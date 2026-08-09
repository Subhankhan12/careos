<?php

namespace Modules\AiCore\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\AiCore\Events\AgentActionLifecycleChanged;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Exceptions\FenceRefusalException;
use Modules\AiCore\Models\AgentAction;
use Modules\Platform\Models\User;

class ApprovalQueue
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly PromptRegistry $prompts,
        private readonly AiInteractionRecorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $diff
     */
    public function propose(string $toolKey, array $input, User $actor, string $feature, string $agent, string $why, string $autonomyLevel, ?array $diff = null): AgentAction
    {
        $tool = $this->tools->get($toolKey);
        $this->authorize($actor, $tool->definition()->permission);
        $prompt = $this->prompts->get($feature);
        $preview = $tool->preview($input);

        $interaction = $this->recorder->record(
            $feature,
            $agent,
            'internal',
            'tool-runtime',
            '1',
            $prompt->hash(),
            'proposed',
            toolCalls: [['tool' => $toolKey, 'autonomy' => $autonomyLevel]],
            metadata: ['why' => $why],
        );

        $action = AgentAction::query()->create([
            'interaction_id' => $interaction->id,
            'feature' => $feature,
            'agent' => $agent,
            'tool_key' => $toolKey,
            'autonomy_level' => $autonomyLevel,
            'status' => AgentAction::STATUS_PENDING,
            'proposed_by' => (string) $actor->getKey(),
            'why' => $why,
            'input_payload' => $input,
            'proposed_output' => $preview,
            'diff' => $diff,
        ]);

        event(new AgentActionLifecycleChanged($action, 'proposed'));

        return $action;
    }

    /**
     * Approve and execute a pending action — optionally with a human-edited payload.
     *
     * An edit is NOT a bypass. Whether or not the reviewer supplied `$editedPayload`, the SAME
     * gate runs: the reviewer is re-authorised against the tool's OWN permission, the action must
     * still be pending, and the tool re-executes (re-grounding/re-deriving from live state) on the
     * payload. The edit changes only the CONTENT the human is posting through the gate; when one is
     * present the action is RECORDED as human-edited — the `edited_payload` column holds the edit,
     * the executed result carries a `human_edited` marker (beside the tool's own provenance), and
     * the executed `ai_interactions` row records it — so an edited post is always distinguishable
     * from an unedited approve.
     *
     * @param  array<string, mixed>|null  $editedPayload
     */
    public function approve(AgentAction $action, User $reviewer, ?array $editedPayload = null): AgentAction
    {
        $tool = $this->tools->get($action->tool_key);
        $this->authorize($reviewer, $tool->definition()->permission);
        $this->assertPending($action);

        $humanEdited = $editedPayload !== null;
        $payload = $editedPayload ?? $action->input_payload;
        $prompt = $this->prompts->get($action->feature);

        $this->recorder->record(
            $action->feature,
            $action->agent,
            'internal',
            'tool-runtime',
            '1',
            $prompt->hash(),
            'approved',
            toolCalls: [['tool' => $action->tool_key]],
            outputRef: $action->id,
            approverId: (string) $reviewer->getKey(),
            metadata: $humanEdited ? ['human_edited' => true] : null,
        );

        try {
            $result = $tool->execute($payload, $reviewer);
        } catch (FenceRefusalException $e) {
            // The ELECTRIC FENCE refused the action (e.g. a handed-off draft with nothing to send).
            // The fence fired exactly as it does today — we only RECORD the refusal as a terminal,
            // countable outcome (append-only ledger row + audited status), with the fence's OWN
            // reason, then re-throw so the caller surfaces it. Nothing was executed; no result.
            $this->recordFenceRefusal($action, $reviewer, $prompt->hash(), $e->getMessage());

            throw $e;
        }

        if ($humanEdited) {
            // Provenance stamp: the human authored the final content THROUGH the gate. This sits
            // beside the tool's own markers (e.g. ai_assisted) so the posted result is auditable
            // as human-edited — never a claim the tool makes about itself.
            $result['human_edited'] = true;
        }

        $action->forceFill([
            'status' => AgentAction::STATUS_EXECUTED,
            'reviewed_by' => (string) $reviewer->getKey(),
            'approved_at' => Carbon::now(),
            'executed_at' => Carbon::now(),
            'edited_payload' => $editedPayload,
            'result' => $result,
        ])->save();

        $this->recorder->record(
            $action->feature,
            $action->agent,
            'internal',
            'tool-runtime',
            '1',
            $prompt->hash(),
            'executed',
            toolCalls: [['tool' => $action->tool_key]],
            outputRef: $action->id,
            approverId: (string) $reviewer->getKey(),
            metadata: $humanEdited ? ['human_edited' => true] : null,
        );

        event(new AgentActionLifecycleChanged($action, 'approved'));
        event(new AgentActionLifecycleChanged($action, 'executed'));

        return $action->fresh() ?? $action;
    }

    public function reject(AgentAction $action, User $reviewer, string $reason): AgentAction
    {
        $tool = $this->tools->get($action->tool_key);
        $this->authorize($reviewer, $tool->definition()->permission);
        $this->assertPending($action);

        if (trim($reason) === '') {
            throw new AiCoreException('Rejection reason is required.');
        }

        $action->forceFill([
            'status' => AgentAction::STATUS_REJECTED,
            'reviewed_by' => (string) $reviewer->getKey(),
            'rejected_at' => Carbon::now(),
            'rejection_reason' => $reason,
        ])->save();

        $prompt = $this->prompts->get($action->feature);
        $this->recorder->record(
            $action->feature,
            $action->agent,
            'internal',
            'tool-runtime',
            '1',
            $prompt->hash(),
            'rejected',
            toolCalls: [['tool' => $action->tool_key]],
            outputRef: $action->id,
            approverId: (string) $reviewer->getKey(),
            metadata: ['reason' => $reason],
        );

        event(new AgentActionLifecycleChanged($action, 'rejected', ['reason' => $reason]));

        return $action->fresh() ?? $action;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function autoExecute(string $toolKey, array $input, User $actor, string $feature, string $agent, string $why, string $autonomyLevel): AgentAction
    {
        $tool = $this->tools->get($toolKey);
        $this->authorize($actor, $tool->definition()->permission);

        if (! $tool->definition()->reversible) {
            throw new AiCoreException('Only reversible tools may run automatically.');
        }

        $result = $tool->execute($input, $actor);
        $prompt = $this->prompts->get($feature);

        $interaction = $this->recorder->record(
            $feature,
            $agent,
            'internal',
            'tool-runtime',
            '1',
            $prompt->hash(),
            'executed',
            toolCalls: [['tool' => $toolKey, 'autonomy' => $autonomyLevel]],
            metadata: ['why' => $why],
        );

        $action = AgentAction::query()->create([
            'interaction_id' => $interaction->id,
            'feature' => $feature,
            'agent' => $agent,
            'tool_key' => $toolKey,
            'autonomy_level' => $autonomyLevel,
            'status' => AgentAction::STATUS_EXECUTED,
            'proposed_by' => (string) $actor->getKey(),
            'reviewed_by' => (string) $actor->getKey(),
            'approved_at' => Carbon::now(),
            'executed_at' => Carbon::now(),
            'why' => $why,
            'input_payload' => $input,
            'proposed_output' => $result,
            'result' => $result,
        ]);

        event(new AgentActionLifecycleChanged($action, 'executed'));

        return $action;
    }

    /**
     * Record an electric-fence refusal as a terminal, countable outcome. The fence already fired
     * (the tool threw at execute); this only RECORDS it — a `fence_refused` status transition (the
     * reason kept in `rejection_reason`, distinguished from a human reject by the status), an
     * append-only `fence_refused` ledger row, and the audited lifecycle event. Nothing executed.
     */
    private function recordFenceRefusal(AgentAction $action, User $reviewer, string $promptHash, string $reason): void
    {
        $action->forceFill([
            'status' => AgentAction::STATUS_FENCE_REFUSED,
            'reviewed_by' => (string) $reviewer->getKey(),
            'fence_refused_at' => Carbon::now(),
            'rejection_reason' => $reason,
        ])->save();

        $this->recorder->record(
            $action->feature,
            $action->agent,
            'internal',
            'tool-runtime',
            '1',
            $promptHash,
            'fence_refused',
            toolCalls: [['tool' => $action->tool_key]],
            outputRef: $action->id,
            approverId: (string) $reviewer->getKey(),
            metadata: ['reason' => $reason],
        );

        event(new AgentActionLifecycleChanged($action, 'fence_refused', ['reason' => $reason]));
    }

    private function authorize(User $actor, string $permission): void
    {
        if (! Gate::forUser($actor)->allows($permission)) {
            throw new AuthorizationException('This user cannot run this AI tool.');
        }
    }

    private function assertPending(AgentAction $action): void
    {
        if ($action->status !== AgentAction::STATUS_PENDING) {
            throw new AiCoreException('Only pending agent actions can be reviewed.');
        }
    }
}
