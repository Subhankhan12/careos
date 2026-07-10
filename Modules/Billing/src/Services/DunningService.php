<?php

namespace Modules\Billing\Services;

use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Channels\EmailDunningChannel;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\DunningEvent;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Throwable;

/**
 * Staged, deterministic, settings-driven dunning for overdue invoices.
 *
 * evaluate() is a pure function of invoice state at an as-of date: it creates
 * the set of dunning_events that SHOULD exist and nothing more, so re-running
 * for the same date is a no-op. A dunning fee is a NEW charge captured through
 * ChargeCaptureService; the original invoice is never mutated. Delivery reuses
 * the notification-channel abstraction and — unlike appointment/recall
 * outreach — is NOT gated on comms consent (dunning is a legal communication).
 */
class DunningService
{
    public const SETTINGS_KEY = 'billing.dunning';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
        private readonly SettingsService $settings,
        private readonly DunningLetterRenderer $renderer,
        private readonly DunningChannelManager $channels,
        private readonly ChargeCaptureService $charges,
    ) {}

    /**
     * @return list<DunningEvent> newly created events (empty on a no-op re-run)
     */
    public function evaluate(Tenant $tenant, CarbonInterface|string $asOf, User $actor, bool $deliver = true): array
    {
        $this->authorize($actor);
        $this->assertActorTenant($actor);

        if ($tenant->id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('tenant_id', (string) $tenant->id);
        }

        $policy = $this->policy();
        if ($policy['levels'] === []) {
            return [];
        }

        $asOfDate = $this->date($asOf);
        $created = [];

        $balances = InvoiceBalance::query()
            ->where('open_balance_minor', '>', 0)
            ->where('dunning_paused', false)
            ->orderBy('invoice_id')
            ->get();

        foreach ($balances as $balance) {
            $invoice = Invoice::query()->whereKey($balance->invoice_id)->first();

            if (! $invoice instanceof Invoice
                || $invoice->series !== Invoice::SERIES_INVOICE
                || $invoice->due_date === null) {
                continue;
            }

            $daysPastDue = $this->wholeDaysBetween($invoice->due_date->toDateString(), $asOfDate);

            $reached = DunningEvent::query()
                ->where('invoice_id', $invoice->id)
                ->pluck('level')
                ->map(fn (int $level): int => $level)
                ->all();

            foreach ($policy['levels'] as $index => $level) {
                if (in_array($level['level'], $reached, true)) {
                    continue;
                }

                // Ascending thresholds: once one is unmet, higher ones are too.
                if ($daysPastDue < $level['days_past_due']) {
                    break;
                }

                // Never skip a level: the previous configured level must already
                // have fired (or exist) before this one may.
                if ($index > 0 && ! in_array($policy['levels'][$index - 1]['level'], $reached, true)) {
                    break;
                }

                $event = $this->fireLevel($invoice, $balance, $level, $asOfDate, $actor, $policy['channel'], $deliver);
                $reached[] = $level['level'];
                $created[] = $event;
            }
        }

        return $created;
    }

    public function setPaused(Invoice $invoice, bool $paused, User $actor, ?string $reason = null): InvoiceBalance
    {
        $this->authorize($actor);
        $this->assertActorTenant($actor);
        $this->assertSameTenant($invoice, 'invoice_id');

        $balance = InvoiceBalance::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $balance->forceFill(['dunning_paused' => $paused])->save();

        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => $paused ? 'dunning.paused' : 'dunning.resumed',
            'patient_id' => $invoice->patient_id,
            'resource_type' => 'invoice',
            'resource_id' => $invoice->id,
            'context' => array_filter([
                'invoice_id' => $invoice->id,
                'reason' => $reason,
            ], fn (mixed $value): bool => $value !== null),
        ]);

        return $balance->refresh();
    }

    /**
     * @param  array{level: int, days_past_due: int, template: string, fee_code: ?string}  $level
     */
    private function fireLevel(
        Invoice $invoice,
        InvoiceBalance $balance,
        array $level,
        string $asOfDate,
        User $actor,
        string $channelKey,
        bool $deliver,
    ): DunningEvent {
        return DB::transaction(function () use ($invoice, $balance, $level, $asOfDate, $actor, $channelKey, $deliver): DunningEvent {
            $documentPath = $this->renderer->render($invoice, $level['level'], $level['template'], $balance->open_balance_minor);

            $feeChargeId = $level['fee_code'] !== null
                ? $this->captureFee($invoice, $level['fee_code'], $asOfDate, $actor)
                : null;

            $event = new DunningEvent([
                'invoice_id' => $invoice->id,
                'level' => $level['level'],
                'triggered_on' => $asOfDate,
                'document_path' => $documentPath,
            ]);

            $delivered = $deliver ? $this->deliver($invoice, $event, $level['template'], $channelKey) : false;

            $event->status = $delivered ? DunningEvent::STATUS_SENT : DunningEvent::STATUS_CREATED;
            $event->save();

            $this->audit->record([
                'actor_type' => 'user',
                'actor_id' => (string) $actor->id,
                'action' => 'dunning.triggered',
                'patient_id' => $invoice->patient_id,
                'resource_type' => 'dunning_event',
                'resource_id' => $event->id,
                'context' => array_filter([
                    'invoice_id' => $invoice->id,
                    'level' => $level['level'],
                    'status' => $event->status,
                    'open_balance_minor' => $balance->open_balance_minor,
                    'fee_charge_id' => $feeChargeId,
                ], fn (mixed $value): bool => $value !== null),
            ]);

            if ($delivered) {
                $this->audit->record([
                    'actor_type' => 'user',
                    'actor_id' => (string) $actor->id,
                    'action' => 'dunning.sent',
                    'patient_id' => $invoice->patient_id,
                    'resource_type' => 'dunning_event',
                    'resource_id' => $event->id,
                    'context' => [
                        'invoice_id' => $invoice->id,
                        'level' => $level['level'],
                        'channel' => $channelKey,
                    ],
                ]);
            }

            return $event->refresh();
        }, 5);
    }

    private function deliver(Invoice $invoice, DunningEvent $event, string $body, string $channelKey): bool
    {
        if (! $this->channels->has($channelKey)) {
            return false;
        }

        $channel = $this->channels->get($channelKey);

        if (! $channel->canSend($invoice)) {
            return false;
        }

        try {
            $channel->send($invoice, $event, $body);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function captureFee(Invoice $invoice, string $feeCode, string $asOfDate, User $actor): ?string
    {
        $patient = Patient::query()->find($invoice->patient_id);

        $branchId = Charge::query()
            ->where('invoice_id', $invoice->id)
            ->orderBy('id')
            ->value('branch_id');

        if ($patient === null || $branchId === null) {
            return null;
        }

        $branch = Branch::query()->find($branchId);
        if ($branch === null) {
            return null;
        }

        // A dunning fee is a NEW draft charge that appears on a future document.
        // The original invoice is never touched.
        return $this->charges->captureManual($patient, $branch, $asOfDate, $feeCode, 1, $actor)->id;
    }

    /**
     * @return array{channel: string, levels: list<array{level: int, days_past_due: int, template: string, fee_code: ?string}>}
     */
    private function policy(): array
    {
        $raw = $this->settings->get(self::SETTINGS_KEY, []);

        if (! is_array($raw)) {
            return ['channel' => EmailDunningChannel::KEY, 'levels' => []];
        }

        $channel = is_string($raw['channel'] ?? null) ? $raw['channel'] : EmailDunningChannel::KEY;
        $rawLevels = is_array($raw['levels'] ?? null) ? $raw['levels'] : [];

        $levels = [];
        foreach ($rawLevels as $rawLevel) {
            if (! is_array($rawLevel) || ! isset($rawLevel['level'], $rawLevel['days_past_due'])) {
                throw new InvalidArgumentException('Each dunning level requires a level and days_past_due.');
            }

            $levels[] = [
                'level' => (int) $rawLevel['level'],
                'days_past_due' => (int) $rawLevel['days_past_due'],
                'template' => (string) ($rawLevel['template'] ?? 'Your invoice is overdue. Please arrange payment.'),
                'fee_code' => isset($rawLevel['fee_code'])
                    ? (string) $rawLevel['fee_code']
                    : null,
            ];
        }

        usort($levels, fn (array $a, array $b): int => $a['days_past_due'] <=> $b['days_past_due']);

        return ['channel' => $channel, 'levels' => $levels];
    }

    private function authorize(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('billing.manage')) {
            throw new AuthorizationException('This user cannot manage billing.');
        }
    }

    private function assertActorTenant(User $actor): void
    {
        if ($actor->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('actor_id', (string) $actor->id);
        }
    }

    private function assertSameTenant(object $model, string $attribute): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }

    private function date(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface
            ? Carbon::instance($date)->toDateString()
            : Carbon::parse($date)->toDateString();
    }

    /**
     * Whole calendar days from $from to $to, positive when $to is later.
     * Computed on UTC day boundaries so every day is exactly 86400s (DST-safe).
     */
    private function wholeDaysBetween(string $from, string $to): int
    {
        $fromTs = Carbon::parse($from, 'UTC')->startOfDay()->getTimestamp();
        $toTs = Carbon::parse($to, 'UTC')->startOfDay()->getTimestamp();

        return intdiv($toTs - $fromTs, 86400);
    }
}
