<?php

namespace App\AiCore\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Models\KbArticle;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;
use Modules\Comms\Models\Message;
use Modules\Comms\Models\Thread;
use Modules\Patients\Models\Patient;
use Modules\Scheduling\Models\Appointment;

/**
 * Draft engine for the Inbox agent (G.6). The LLM only ever DRAFTS; every
 * draft line — generated or supplied — must be GROUNDED in exactly one of the
 * three permitted sources before it may reach the approval queue:
 *   (a) the thread's OWN message history,
 *   (b) the tenant's ACTIVE KB articles (Phase C),
 *   (c) the patient's OWN administrative facts (next appointment time,
 *       invoice open balance) — recomputed live and compared exactly.
 * An unsourced or unresolvable claim throws IN CODE (the D.8 pattern): no
 * approval-queue item is created for it. Anything the engine cannot ground is
 * a HANDOFF, never a guess. Drafting NEVER writes to any record.
 */
class InboxDraftEngine
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function draft(array $input): array
    {
        $thread = Thread::query()->whereKey((string) ($input['thread_id'] ?? ''))->firstOrFail();

        if (! $thread->isPatientThread() || $thread->patient_id === null) {
            throw new AiCoreException('Inbox drafts exist only for patient threads.');
        }

        $patient = Patient::query()->whereKey($thread->patient_id)->firstOrFail();
        $patient->auditRead(['surface' => 'inbox_agent']);
        $thread->auditRead(['surface' => 'inbox_agent']);

        $history = Message::query()
            ->where('thread_id', $thread->id)
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();

        $lines = array_key_exists('draft', $input)
            ? array_values((array) $input['draft'])
            : $this->generate($thread, $patient, $history);

        if ($lines === []) {
            return [
                'thread_id' => $thread->id,
                'patient_id' => $patient->id,
                'handoff' => true,
                'lines' => [],
                'explanation' => 'Nothing in the permitted sources answers this message; a human must reply.',
            ];
        }

        $validated = [];
        foreach ($lines as $line) {
            $validated[] = $this->validateLine($thread, $patient, is_array($line) ? $line : []);
        }

        return [
            'thread_id' => $thread->id,
            'patient_id' => $patient->id,
            'handoff' => false,
            'lines' => $validated,
            'body' => implode("\n", array_map(fn (array $line): string => (string) $line['text'], $validated)),
            'ai_assisted' => true,
            'sends_on_send_only' => 'A staff member must explicitly send this draft; the agent never posts.',
        ];
    }

    /**
     * Deterministic generator standing in for the LLM: it only ever proposes
     * lines it can ground, so validation succeeds by construction.
     *
     * @param  Collection<int, Message>  $history
     * @return list<array<string, mixed>>
     */
    private function generate(Thread $thread, Patient $patient, $history): array
    {
        $lastPatientMessage = $history->last(
            fn (Message $message): bool => $message->author_type === Message::AUTHOR_PATIENT,
        );

        if (! $lastPatientMessage instanceof Message) {
            return [];
        }

        $ask = mb_strtolower($lastPatientMessage->body);
        $lines = [];

        if (preg_match('/appointment|when|time|termin/', $ask) === 1) {
            $next = $this->nextAppointmentFact($patient);

            if ($next !== null) {
                $lines[] = [
                    'text' => 'Your next appointment is on '.$next.'.',
                    'source' => ['type' => 'admin_fact', 'key' => 'next_appointment', 'value' => $next],
                ];
            }
        }

        if (preg_match('/invoice|bill|balance|pay|owe/', $ask) === 1) {
            $balance = $this->openBalanceFact($patient);
            $lines[] = [
                'text' => 'Your current open balance is '.$balance.' (minor units).',
                'source' => ['type' => 'admin_fact', 'key' => 'invoice_open_balance', 'value' => $balance],
            ];
        }

        foreach (KbArticle::query()->where('is_active', true)->get() as $article) {
            if (mb_stripos($ask, mb_strtolower($article->title)) !== false) {
                $lines[] = [
                    'text' => trim(mb_substr($article->body, 0, 300)),
                    'source' => ['type' => 'kb_article', 'id' => $article->id],
                ];
                break;
            }
        }

        return $lines;
    }

    /**
     * Every line must resolve against one of the THREE permitted sources.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function validateLine(Thread $thread, Patient $patient, array $line): array
    {
        $text = trim((string) ($line['text'] ?? ''));
        $source = is_array($line['source'] ?? null) ? $line['source'] : null;

        if ($text === '' || $source === null) {
            throw new AiCoreException('Inbox draft lines require text and a source reference.');
        }

        match ((string) ($source['type'] ?? '')) {
            'thread_message' => $this->assertThreadMessageSource($thread, (string) ($source['id'] ?? '')),
            'kb_article' => $this->assertKbSource((string) ($source['id'] ?? '')),
            'admin_fact' => $this->assertAdminFact($patient, (string) ($source['key'] ?? ''), (string) ($source['value'] ?? '')),
            default => throw new AiCoreException('Inbox draft source type is not allowed.'),
        };

        return ['text' => $text, 'source' => $source];
    }

    private function assertThreadMessageSource(Thread $thread, string $messageId): void
    {
        $resolves = Message::query()
            ->whereKey($messageId)
            ->where('thread_id', $thread->id)
            ->exists();

        if (! $resolves) {
            throw new AiCoreException('Inbox draft thread-message source does not resolve to this thread.');
        }
    }

    private function assertKbSource(string $articleId): void
    {
        $resolves = KbArticle::query()
            ->whereKey($articleId)
            ->where('is_active', true)
            ->exists();

        if (! $resolves) {
            throw new AiCoreException('Inbox draft KB source does not resolve to an active article.');
        }
    }

    /**
     * Administrative facts are recomputed LIVE for this patient and compared
     * exactly: a claim that does not match reality is rejected in code.
     */
    private function assertAdminFact(Patient $patient, string $key, string $claimedValue): void
    {
        $actual = match ($key) {
            'next_appointment' => $this->nextAppointmentFact($patient),
            'invoice_open_balance' => $this->openBalanceFact($patient),
            default => throw new AiCoreException('Inbox draft admin fact is not allowed.'),
        };

        if ($actual === null || $claimedValue !== $actual) {
            throw new AiCoreException(
                "Inbox draft admin fact {$key} is unsourced: the claimed value does not match this patient's records.",
            );
        }
    }

    private function nextAppointmentFact(Patient $patient): ?string
    {
        $next = Appointment::query()
            ->where('patient_id', $patient->id)
            ->whereIn('status', [Appointment::STATUS_BOOKED, Appointment::STATUS_CONFIRMED])
            ->where('starts_at', '>=', Carbon::now())
            ->orderBy('starts_at')
            ->first();

        return $next?->starts_at->toDateTimeString();
    }

    private function openBalanceFact(Patient $patient): string
    {
        $invoiceIds = Invoice::query()
            ->where('patient_id', $patient->id)
            ->whereNotNull('number')
            ->pluck('id');

        return (string) (int) InvoiceBalance::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->sum('open_balance_minor');
    }
}
