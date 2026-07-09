<?php

namespace Modules\Clinical\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Clinical\Events\ClinicalRecordChanged;
use Modules\Clinical\Models\CarePlan;
use Modules\Clinical\Models\CarePlanGoal;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

class CarePlanService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $goals
     */
    public function create(Patient $patient, StaffProfile $creator, User $actor, array $data, array $goals = []): CarePlan
    {
        $this->authorizeWrite($actor);
        $this->assertSameTenant($patient, 'patient_id');
        $this->assertSameTenant($creator, 'created_by');

        $carePlan = CarePlan::query()->create([
            'patient_id' => $patient->id,
            'title' => (string) $data['title'],
            'status' => $data['status'] ?? CarePlan::STATUS_ACTIVE,
            'started_on' => $data['started_on'] ?? now()->toDateString(),
            'ended_on' => $data['ended_on'] ?? null,
            'created_by' => $creator->id,
        ]);

        foreach ($goals as $goal) {
            $this->addGoal($carePlan, $actor, $goal);
        }

        $this->audit($carePlan, $actor, 'care_plan.created');

        return $carePlan;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addGoal(CarePlan $carePlan, User $actor, array $data): CarePlanGoal
    {
        $this->authorizeWrite($actor);
        $this->assertSameTenant($carePlan, 'care_plan_id');

        $goal = CarePlanGoal::query()->create([
            'care_plan_id' => $carePlan->id,
            'description' => (string) $data['description'],
            'target_date' => $data['target_date'] ?? null,
            'status' => $data['status'] ?? CarePlanGoal::STATUS_OPEN,
        ]);

        $this->audit($carePlan, $actor, 'care_plan_goal.created', 'care_plan_goal', $goal->id, [
            'goal_status' => $goal->status,
        ]);

        return $goal;
    }

    public function transition(CarePlan $carePlan, string $status, User $actor): CarePlan
    {
        $this->authorizeWrite($actor);
        $this->assertSameTenant($carePlan, 'care_plan_id');

        if ($carePlan->status !== CarePlan::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Only active care plans can transition.');
        }

        if (! in_array($status, [CarePlan::STATUS_COMPLETED, CarePlan::STATUS_CANCELLED], true)) {
            throw new InvalidArgumentException('Illegal care plan transition.');
        }

        $carePlan->forceFill([
            'status' => $status,
            'ended_on' => now()->toDateString(),
        ])->save();

        $this->audit($carePlan, $actor, 'care_plan.'.$status);

        return $carePlan;
    }

    public function transitionGoal(CarePlanGoal $goal, string $status, User $actor): CarePlanGoal
    {
        $this->authorizeWrite($actor);
        $carePlan = CarePlan::query()->whereKey($goal->care_plan_id)->firstOrFail();
        $this->assertSameTenant($carePlan, 'care_plan_id');

        if ($goal->status !== CarePlanGoal::STATUS_OPEN) {
            throw new InvalidArgumentException('Only open care plan goals can transition.');
        }

        if (! in_array($status, [CarePlanGoal::STATUS_MET, CarePlanGoal::STATUS_NOT_MET], true)) {
            throw new InvalidArgumentException('Illegal care plan goal transition.');
        }

        $goal->forceFill(['status' => $status])->save();

        $this->audit($carePlan, $actor, 'care_plan_goal.'.$status, 'care_plan_goal', $goal->id, [
            'goal_status' => $goal->status,
        ]);

        return $goal;
    }

    private function authorizeWrite(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('note.write')) {
            throw new AuthorizationException('This user cannot manage care plans.');
        }
    }

    private function assertSameTenant(object $model, string $attribute): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function audit(
        CarePlan $carePlan,
        User $actor,
        string $action,
        string $resourceType = 'care_plan',
        ?string $resourceId = null,
        array $context = [],
    ): void {
        Event::dispatch(new ClinicalRecordChanged(
            $action,
            $resourceType,
            $resourceId ?? $carePlan->id,
            $carePlan->patient_id,
            $actor,
            [
                'care_plan_id' => $carePlan->id,
                'status' => $carePlan->status,
                ...$context,
            ],
        ));
    }
}
