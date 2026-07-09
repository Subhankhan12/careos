<?php

namespace Modules\Clinical\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Clinical\Events\ClinicalRecordChanged;
use Modules\Clinical\Models\CarePlan;
use Modules\Clinical\Models\ClinicalTask;
use Modules\Clinical\Models\Encounter;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

class ClinicalTaskService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, StaffProfile $assignee, array $data): ClinicalTask
    {
        $this->authorizeWrite($actor);
        $this->assertSameTenant($assignee, 'assigned_to');

        $patientId = $this->resolvePatientId($data);

        $task = ClinicalTask::query()->create([
            'patient_id' => $patientId,
            'care_plan_id' => $data['care_plan_id'] ?? null,
            'encounter_id' => $data['encounter_id'] ?? null,
            'title' => (string) $data['title'],
            'description' => $data['description'] ?? null,
            'assigned_to' => $assignee->id,
            'due_at' => $data['due_at'],
            'priority' => $data['priority'] ?? ClinicalTask::PRIORITY_NORMAL,
            'status' => $data['status'] ?? ClinicalTask::STATUS_OPEN,
            'completed_at' => $data['completed_at'] ?? null,
        ]);

        $this->audit($task, $actor, 'clinical_task.created');

        return $task;
    }

    public function transition(ClinicalTask $task, string $status, User $actor): ClinicalTask
    {
        $this->authorizeWrite($actor);
        $this->assertSameTenant($task, 'clinical_task_id');

        $legal = [
            ClinicalTask::STATUS_OPEN => [
                ClinicalTask::STATUS_IN_PROGRESS,
                ClinicalTask::STATUS_DONE,
                ClinicalTask::STATUS_CANCELLED,
            ],
            ClinicalTask::STATUS_IN_PROGRESS => [
                ClinicalTask::STATUS_DONE,
                ClinicalTask::STATUS_CANCELLED,
            ],
        ];

        if (! in_array($status, $legal[$task->status] ?? [], true)) {
            throw new InvalidArgumentException('Illegal clinical task transition.');
        }

        $task->forceFill([
            'status' => $status,
            'completed_at' => $status === ClinicalTask::STATUS_DONE ? now() : null,
        ])->save();

        $this->audit($task, $actor, 'clinical_task.'.$status);

        return $task;
    }

    private function authorizeWrite(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('note.write')) {
            throw new AuthorizationException('This user cannot manage clinical tasks.');
        }
    }

    private function assertSameTenant(object $model, string $attribute): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolvePatientId(array $data): ?string
    {
        $patientId = isset($data['patient_id']) ? (string) $data['patient_id'] : null;

        if ($patientId !== null) {
            if (! Patient::query()->whereKey($patientId)->exists()) {
                throw CrossTenantReferenceException::forAttribute('patient_id', $patientId);
            }
        }

        if (isset($data['care_plan_id'])) {
            $carePlan = CarePlan::query()->whereKey((string) $data['care_plan_id'])->first();
            if ($carePlan === null) {
                throw CrossTenantReferenceException::forAttribute('care_plan_id', (string) $data['care_plan_id']);
            }

            $patientId ??= $carePlan->patient_id;

            if ($carePlan->patient_id !== $patientId) {
                throw CrossTenantReferenceException::forAttribute('care_plan_id', $carePlan->id);
            }
        }

        if (isset($data['encounter_id'])) {
            $encounter = Encounter::query()->whereKey((string) $data['encounter_id'])->first();
            if ($encounter === null) {
                throw CrossTenantReferenceException::forAttribute('encounter_id', (string) $data['encounter_id']);
            }

            $patientId ??= $encounter->patient_id;

            if ($encounter->patient_id !== $patientId) {
                throw CrossTenantReferenceException::forAttribute('encounter_id', $encounter->id);
            }
        }

        return $patientId;
    }

    private function audit(ClinicalTask $task, User $actor, string $action): void
    {
        Event::dispatch(new ClinicalRecordChanged(
            $action,
            'clinical_task',
            $task->id,
            $task->patient_id,
            $actor,
            [
                'care_plan_id' => $task->care_plan_id,
                'encounter_id' => $task->encounter_id,
                'assigned_to' => $task->assigned_to,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_at' => $task->due_at->toDateTimeString(),
                'completed_at' => $task->completed_at?->toDateTimeString(),
            ],
        ));
    }
}
