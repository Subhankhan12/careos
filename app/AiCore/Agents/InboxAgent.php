<?php

namespace App\AiCore\Agents;

use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Services\AgentRuntime;
use Modules\AiCore\Services\AiInteractionRecorder;
use Modules\AiCore\Services\PromptRegistry;
use Modules\Audit\Services\AuditService;
use Modules\Comms\Models\Message;
use Modules\Comms\Models\Thread;
use Modules\Platform\Models\User;

/**
 * The Inbox agent (D-G5): DRAFTS replies and CLASSIFIES documents, ceiling
 * `suggest`. It NEVER sends — a human reads, edits, and sends.
 *
 * ELECTRIC FENCE: a patient message containing a clinical question (symptoms,
 * medication, "should I come in?", "is this normal?") is REFUSED before any
 * tool runs: NO draft is produced at all — not even "for the clinician to
 * review" — a handoff note is returned, the thread is flagged for clinician
 * attention, and the refusal is ledgered. Medical questions are a human's to
 * answer, always.
 */
class InboxAgent
{
    public const DRAFT_FEATURE = 'comms.draft_reply';

    public const CLASSIFY_FEATURE = 'comms.classify_document';

    public const AGENT = 'inbox-agent';

    public function __construct(
        private readonly AgentRuntime $runtime,
        private readonly PromptRegistry $prompts,
        private readonly AiInteractionRecorder $recorder,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function draftReply(array $input, User $actor): array
    {
        $thread = Thread::query()->whereKey((string) ($input['thread_id'] ?? ''))->firstOrFail();

        $clinicalMessage = $this->latestClinicalPatientMessage($thread);
        if ($clinicalMessage !== null) {
            return $this->refuseClinical($thread, $clinicalMessage, $actor);
        }

        return $this->runGovernedTool('comms.draft_reply', $input, $actor, self::DRAFT_FEATURE,
            'Draft a grounded, source-referenced reply for a staff member to review and send');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function classifyDocument(array $input, User $actor): array
    {
        return $this->runGovernedTool('comms.classify_document', $input, $actor, self::CLASSIFY_FEATURE,
            'Suggest a document category and patient match for human confirmation');
    }

    private function latestClinicalPatientMessage(Thread $thread): ?Message
    {
        $latest = Message::query()
            ->where('thread_id', $thread->id)
            ->where('author_type', Message::AUTHOR_PATIENT)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        if ($latest instanceof Message && $this->isClinicalQuestion($latest->body)) {
            return $latest;
        }

        return null;
    }

    private function isClinicalQuestion(string $body): bool
    {
        return preg_match(
            '/\b(symptom\w*|medicat\w*|dos(?:e|ing|age)|pill|prescri\w*|pain|rash|fever|bleed\w*|swell\w*|dizz\w*|nausea|infect\w*|side\s+effects?|getting\s+worse|is\s+this\s+normal|should\s+i\s+(?:come\s+in|worry|stop|take)|diagnos\w*|treat\w*)\b/iu',
            $body,
        ) === 1;
    }

    /**
     * ELECTRIC FENCE refusal: no draft exists anywhere — only a handoff note
     * for staff and a clinician-attention flag on the thread.
     *
     * @return array<string, mixed>
     */
    private function refuseClinical(Thread $thread, Message $message, User $actor): array
    {
        $thread->forceFill([
            'clinician_attention_at' => now(),
            'clinician_attention_reason' => 'Patient message contains a clinical question; the Inbox agent refused to draft.',
        ])->save();

        $this->audit->record([
            'actor_type' => 'agent',
            'actor_id' => self::AGENT,
            'action' => 'thread.flagged_for_clinician',
            'patient_id' => $thread->patient_id,
            'resource_type' => 'thread',
            'resource_id' => $thread->id,
            'context' => [
                'message_id' => $message->id,
                'reason' => 'clinical_question',
            ],
        ]);

        $prompt = $this->prompts->get(self::DRAFT_FEATURE);
        $this->recorder->record(
            self::DRAFT_FEATURE,
            self::AGENT,
            'internal',
            'inbox-draft-only',
            '1',
            $prompt->hash(),
            'refused',
            toolCalls: [['tool' => 'comms.draft_reply']],
            metadata: [
                'reason' => 'clinical_question',
                'thread_id' => $thread->id,
            ],
        );

        return [
            'status' => 'refused',
            'label' => AiInteractionRecorder::LABEL,
            'human_handoff' => true,
            'thread_flagged' => true,
            'answer' => 'This message contains a clinical question, so no draft was produced. The thread is flagged for a clinician to answer personally.',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function runGovernedTool(string $toolKey, array $input, User $actor, string $feature, string $why): array
    {
        try {
            return $this->runtime->runTool($toolKey, $input, $actor, $feature, self::AGENT, $why);
        } catch (AiCoreException $e) {
            $prompt = $this->prompts->get($feature);
            $this->recorder->record(
                $feature,
                self::AGENT,
                'internal',
                'inbox-draft-only',
                '1',
                $prompt->hash(),
                'invalid_proposal',
                toolCalls: [['tool' => $toolKey]],
                errorMessage: $e->getMessage(),
                metadata: [
                    'thread_id' => $input['thread_id'] ?? null,
                    'document_id' => $input['document_id'] ?? null,
                ],
            );

            throw $e;
        }
    }
}
