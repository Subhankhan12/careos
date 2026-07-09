<?php

namespace Modules\Nursing\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Modules\Clinical\Models\ClinicalTask;
use Modules\Clinical\Models\Vital;
use Modules\Clinical\Services\ClinicalTaskService;
use Modules\Nursing\Events\NurseSyncActionProcessed;
use Modules\Nursing\Models\NurseSyncAction;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\SyncConflict;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitObservation;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NurseSyncService
{
    public const CODE_ACCEPTED = 'accepted';

    public const CODE_ACCEPTED_WITH_FLAG = 'accepted_with_flag';

    public const CODE_SCHEDULE_CHANGED = 'schedule_changed_server_wins';

    public const CODE_AMBIGUOUS_CONFLICT = 'ambiguous_conflict_review_required';

    public const CODE_VISIT_NOT_FOUND = 'visit_not_found';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly VisitService $visits,
        private readonly ClinicalTaskService $tasks,
    ) {}

    /**
     * D-E1 conflict policy:
     * - Server wins schedule: cancellation/reassignment rejects schedule actions.
     * - Client wins note content: nurse-authored notes are persisted and flagged
     *   when the server schedule changed.
     * - Ambiguous conflicts are routed to sync_conflicts for human review.
     *
     * @param  list<array<string, mixed>>  $actions
     * @return list<array<string, mixed>>
     */
    public function sync(User $nurse, array $actions): array
    {
        $resources = $this->nurseResources($nurse);

        return collect($actions)
            ->sortBy(fn (array $action): int => (int) $action['sequence'])
            ->map(fn (array $action): array => $this->process($nurse, $resources, $action))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, resource>  $resources
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function process(User $nurse, Collection $resources, array $action): array
    {
        $existing = NurseSyncAction::query()
            ->where('client_action_uuid', (string) $action['client_uuid'])
            ->first();

        if ($existing instanceof NurseSyncAction) {
            return $this->resultFromLedger($existing);
        }

        return DB::transaction(function () use ($nurse, $resources, $action): array {
            $payload = $this->payload($action);
            $resource = $this->resourceFor($resources, $payload);
            $type = (string) $action['type'];

            return match ($type) {
                'check_in' => $this->checkIn($nurse, $resource, $action, $payload),
                'check_out' => $this->checkOut($nurse, $resource, $action, $payload),
                'task_complete' => $this->taskComplete($nurse, $resource, $action, $payload),
                'vitals' => $this->vitals($nurse, $resource, $action, $payload),
                'note' => $this->note($nurse, $resource, $action, $payload),
                default => $this->ambiguous($nurse, $resource, $action, $payload, self::CODE_AMBIGUOUS_CONFLICT),
            };
        });
    }

    /**
     * @return Collection<int, resource>
     */
    private function nurseResources(User $nurse): Collection
    {
        if ($nurse->tenant_id !== $this->tenantContext->id()) {
            throw new HttpException(403, 'Nurse token is not scoped to the current tenant.');
        }

        $staffIds = StaffProfile::query()
            ->where('user_id', $nurse->id)
            ->where('status', StaffProfile::STATUS_ACTIVE)
            ->pluck('id');

        $resources = Resource::query()
            ->where('type', Resource::TYPE_PRACTITIONER)
            ->where('active', true)
            ->whereIn('staff_profile_id', $staffIds)
            ->get();

        if ($resources->isEmpty()) {
            throw new HttpException(403, 'No active practitioner resource is linked to this nurse account.');
        }

        return $resources;
    }

    /**
     * @param  Collection<int, resource>  $resources
     * @param  array<string, mixed>  $payload
     */
    private function resourceFor(Collection $resources, array $payload): Resource
    {
        if (isset($payload['nurse_resource_id'])) {
            $matched = $resources->firstWhere('id', (string) $payload['nurse_resource_id']);

            if ($matched instanceof Resource) {
                return $matched;
            }
        }

        if (isset($payload['visit_id'])) {
            $visit = Visit::query()->whereKey((string) $payload['visit_id'])->first();
            if ($visit instanceof Visit) {
                $matched = $resources->firstWhere('id', $visit->resource_id);
                if ($matched instanceof Resource) {
                    return $matched;
                }
            }
        }

        $first = $resources->first();
        if (! $first instanceof Resource) {
            throw new HttpException(403, 'No nurse resource is available for sync.');
        }

        return $first;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function payload(array $action): array
    {
        $payload = $action['payload'] ?? [];

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function checkIn(User $nurse, Resource $resource, array $action, array $payload): array
    {
        $plannedVisit = $this->plannedVisit($payload);

        if (! $plannedVisit instanceof PlannedVisit || $this->scheduleChanged($plannedVisit, null, $resource)) {
            return $this->rejectedSchedule($nurse, $resource, $action, $payload, null, $plannedVisit);
        }

        $visit = Visit::query()
            ->where('client_visit_uuid', (string) ($payload['client_visit_uuid'] ?? ''))
            ->first();

        if (! $visit instanceof Visit) {
            $visit = $this->visits->createFromPlannedVisit($plannedVisit, (string) $payload['client_visit_uuid']);
        }

        $event = $this->visits->checkIn(
            $visit,
            $nurse,
            $this->locationOrManualReason($payload),
            (string) $action['device_timestamp'],
        );

        return $this->recordLedger($nurse, $resource, $action, $payload, $visit, NurseSyncAction::STATUS_ACCEPTED, self::CODE_ACCEPTED, [
            'visit_id' => $visit->id,
            'visit_event_id' => $event->id,
        ], $visit->patient_id);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function checkOut(User $nurse, Resource $resource, array $action, array $payload): array
    {
        $visit = $this->visit($payload);
        $plannedVisit = $visit instanceof Visit ? $this->plannedVisitFor($visit) : null;

        if (! $visit instanceof Visit || $this->scheduleChanged($plannedVisit, $visit, $resource)) {
            return $this->rejectedSchedule($nurse, $resource, $action, $payload, $visit, $plannedVisit);
        }

        $event = $this->visits->checkOut(
            $visit,
            $nurse,
            $this->locationOrManualReason($payload),
            (string) $action['device_timestamp'],
        );

        return $this->recordLedger($nurse, $resource, $action, $payload, $visit, NurseSyncAction::STATUS_ACCEPTED, self::CODE_ACCEPTED, [
            'visit_id' => $visit->id,
            'visit_event_id' => $event->id,
        ], $visit->patient_id);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function taskComplete(User $nurse, Resource $resource, array $action, array $payload): array
    {
        $visit = $this->visit($payload);
        $plannedVisit = $visit instanceof Visit ? $this->plannedVisitFor($visit) : null;

        if (! $visit instanceof Visit || $this->scheduleChanged($plannedVisit, $visit, $resource)) {
            return $this->rejectedSchedule($nurse, $resource, $action, $payload, $visit, $plannedVisit);
        }

        $task = ClinicalTask::query()->whereKey((string) ($payload['task_id'] ?? ''))->firstOrFail();
        $this->tasks->transition($task, ClinicalTask::STATUS_DONE, $nurse);

        return $this->recordLedger($nurse, $resource, $action, $payload, $visit, NurseSyncAction::STATUS_ACCEPTED, self::CODE_ACCEPTED, [
            'task_id' => $task->id,
        ], $visit->patient_id);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function vitals(User $nurse, Resource $resource, array $action, array $payload): array
    {
        $visit = $this->visit($payload);
        $plannedVisit = $visit instanceof Visit ? $this->plannedVisitFor($visit) : null;

        if (! $visit instanceof Visit) {
            return $this->recordLedger($nurse, $resource, $action, $payload, null, NurseSyncAction::STATUS_REJECTED, self::CODE_VISIT_NOT_FOUND, [], null);
        }

        if ($this->scheduleChanged($plannedVisit, $visit, $resource)) {
            return $this->ambiguous($nurse, $resource, $action, $payload, 'vitals_schedule_changed');
        }

        $staffId = $this->staffIdFor($resource);
        $vital = Vital::query()->create([
            'patient_id' => $visit->patient_id,
            'recorded_at' => (string) $action['device_timestamp'],
            'systolic' => $payload['systolic'] ?? null,
            'diastolic' => $payload['diastolic'] ?? null,
            'heart_rate' => $payload['heart_rate'] ?? null,
            'temperature_c' => $payload['temperature_c'] ?? null,
            'spo2' => $payload['spo2'] ?? null,
            'weight_g' => $payload['weight_g'] ?? null,
            'height_mm' => $payload['height_mm'] ?? null,
            'extra' => [
                'source' => 'nurse_pwa',
                'client_action_uuid' => (string) $action['client_uuid'],
                ...((array) ($payload['extra'] ?? [])),
            ],
            'recorded_by' => $staffId,
        ]);

        return $this->recordLedger($nurse, $resource, $action, $payload, $visit, NurseSyncAction::STATUS_ACCEPTED, self::CODE_ACCEPTED, [
            'vital_id' => $vital->id,
        ], $visit->patient_id);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function note(User $nurse, Resource $resource, array $action, array $payload): array
    {
        $visit = $this->visit($payload);

        if (! $visit instanceof Visit) {
            return $this->recordLedger($nurse, $resource, $action, $payload, null, NurseSyncAction::STATUS_REJECTED, self::CODE_VISIT_NOT_FOUND, [], null);
        }

        $plannedVisit = $this->plannedVisitFor($visit);
        $flagged = $this->scheduleChanged($plannedVisit, $visit, $resource);
        $flagReason = $flagged ? 'server_schedule_changed_client_note_preserved' : null;

        $observation = VisitObservation::query()->create([
            'visit_id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'nurse_resource_id' => $resource->id,
            'client_action_uuid' => (string) $action['client_uuid'],
            'note_text' => (string) ($payload['note_text'] ?? ''),
            'flagged' => $flagged,
            'flag_reason' => $flagReason,
            'device_timestamp' => (string) $action['device_timestamp'],
        ]);

        return $this->recordLedger(
            $nurse,
            $resource,
            $action,
            $payload,
            $visit,
            NurseSyncAction::STATUS_ACCEPTED,
            $flagged ? self::CODE_ACCEPTED_WITH_FLAG : self::CODE_ACCEPTED,
            [
                'visit_observation_id' => $observation->id,
                'flagged' => $flagged,
                'flag_reason' => $flagReason,
            ],
            $visit->patient_id,
        );
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function ambiguous(User $nurse, Resource $resource, array $action, array $payload, string $reason): array
    {
        $visit = $this->visit($payload);
        $conflict = SyncConflict::query()->create([
            'visit_id' => $visit?->id,
            'nurse_resource_id' => $resource->id,
            'action_type' => (string) $action['type'],
            'client_payload' => $payload,
            'server_state' => $this->serverState($visit, $this->plannedVisitFor($visit)),
            'reason' => $reason,
        ]);

        return $this->recordLedger($nurse, $resource, $action, $payload, $visit, NurseSyncAction::STATUS_CONFLICT, self::CODE_AMBIGUOUS_CONFLICT, [
            'sync_conflict_id' => $conflict->id,
            'reason' => $reason,
        ], $visit?->patient_id);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function rejectedSchedule(
        User $nurse,
        Resource $resource,
        array $action,
        array $payload,
        ?Visit $visit,
        ?PlannedVisit $plannedVisit,
    ): array {
        return $this->recordLedger($nurse, $resource, $action, $payload, $visit, NurseSyncAction::STATUS_REJECTED, self::CODE_SCHEDULE_CHANGED, [
            'server_state' => $this->serverState($visit, $plannedVisit),
        ], $visit instanceof Visit ? $visit->patient_id : $plannedVisit?->patient_id);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $resultPayload
     * @return array<string, mixed>
     */
    private function recordLedger(
        User $nurse,
        Resource $resource,
        array $action,
        array $payload,
        ?Visit $visit,
        string $status,
        string $code,
        array $resultPayload,
        ?string $patientId,
    ): array {
        $ledger = NurseSyncAction::query()->create([
            'client_action_uuid' => (string) $action['client_uuid'],
            'visit_id' => $visit?->id,
            'nurse_resource_id' => $resource->id,
            'action_type' => (string) $action['type'],
            'device_sequence' => (int) $action['sequence'],
            'device_timestamp' => (string) $action['device_timestamp'],
            'status' => $status,
            'result_code' => $code,
            'client_payload' => $payload,
            'result_payload' => $resultPayload,
        ]);

        Event::dispatch(new NurseSyncActionProcessed($ledger, $nurse, $patientId, [
            'status' => $status,
            'result_code' => $code,
            ...$resultPayload,
        ]));

        return $this->resultFromLedger($ledger);
    }

    /**
     * @return array<string, mixed>
     */
    private function resultFromLedger(NurseSyncAction $ledger): array
    {
        return [
            'client_uuid' => $ledger->client_action_uuid,
            'status' => $ledger->status,
            'code' => $ledger->result_code,
            'payload' => $ledger->result_payload ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function visit(array $payload): ?Visit
    {
        if (isset($payload['visit_id'])) {
            return Visit::query()->whereKey((string) $payload['visit_id'])->first();
        }

        if (isset($payload['client_visit_uuid'])) {
            return Visit::query()->where('client_visit_uuid', (string) $payload['client_visit_uuid'])->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function plannedVisit(array $payload): ?PlannedVisit
    {
        if (! isset($payload['planned_visit_id'])) {
            return null;
        }

        return PlannedVisit::query()->whereKey((string) $payload['planned_visit_id'])->lockForUpdate()->first();
    }

    private function plannedVisitFor(?Visit $visit): ?PlannedVisit
    {
        if (! $visit instanceof Visit || $visit->planned_visit_id === null) {
            return null;
        }

        return PlannedVisit::query()->whereKey($visit->planned_visit_id)->lockForUpdate()->first();
    }

    private function scheduleChanged(?PlannedVisit $plannedVisit, ?Visit $visit, Resource $resource): bool
    {
        if ($visit instanceof Visit && $visit->resource_id !== $resource->id) {
            return true;
        }

        if (! $plannedVisit instanceof PlannedVisit) {
            return false;
        }

        return $plannedVisit->status !== PlannedVisit::STATUS_ASSIGNED
            || $plannedVisit->assigned_resource_id !== $resource->id;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{latitude: mixed, longitude: mixed, accuracy_meters?: mixed}|string|null
     */
    private function locationOrManualReason(array $payload): array|string|null
    {
        if (isset($payload['location']) && is_array($payload['location'])) {
            return $payload['location'];
        }

        return $payload['manual_reason'] ?? null;
    }

    private function staffIdFor(Resource $resource): string
    {
        if ($resource->staff_profile_id === null) {
            throw new InvalidArgumentException('Nurse resource is not linked to a staff profile.');
        }

        return $resource->staff_profile_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function serverState(?Visit $visit, ?PlannedVisit $plannedVisit): array
    {
        return [
            'visit_id' => $visit?->id,
            'visit_status' => $visit?->status,
            'visit_resource_id' => $visit?->resource_id,
            'planned_visit_id' => $plannedVisit?->id,
            'planned_visit_status' => $plannedVisit?->status,
            'planned_assigned_resource_id' => $plannedVisit?->assigned_resource_id,
            'server_time' => Carbon::now()->toIso8601String(),
        ];
    }
}
