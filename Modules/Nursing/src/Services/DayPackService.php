<?php

namespace Modules\Nursing\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\Clinical\Models\Allergy;
use Modules\Clinical\Models\CarePlan;
use Modules\Clinical\Models\CarePlanGoal;
use Modules\Clinical\Models\ClinicalTask;
use Modules\Clinical\Models\Medication;
use Modules\Clinical\Models\Problem;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitTask;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DayPackService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * D-E2: the offline day-pack is deliberately scoped to one nurse, one day,
     * and only patients attached to that nurse's assigned visits. This is not a
     * chart export endpoint.
     *
     * @return array<string, mixed>
     */
    public function forNurse(User $nurse, Carbon $date): array
    {
        Gate::forUser($nurse)->authorize('patient.view');

        if ($nurse->tenant_id !== $this->tenantContext->id()) {
            throw new HttpException(403, 'Nurse token is not scoped to the current tenant.');
        }

        $staffIds = StaffProfile::query()
            ->where('user_id', $nurse->id)
            ->where('status', StaffProfile::STATUS_ACTIVE)
            ->pluck('id');

        if ($staffIds->isEmpty()) {
            throw new HttpException(403, 'No active staff profile is linked to this nurse account.');
        }

        $resourceIds = Resource::query()
            ->where('type', Resource::TYPE_PRACTITIONER)
            ->where('active', true)
            ->whereIn('staff_profile_id', $staffIds)
            ->pluck('id');

        if ($resourceIds->isEmpty()) {
            throw new HttpException(403, 'No active practitioner resource is linked to this nurse account.');
        }

        $visits = PlannedVisit::query()
            ->whereDate('scheduled_date', $date->toDateString())
            ->where('status', PlannedVisit::STATUS_ASSIGNED)
            ->whereIn('assigned_resource_id', $resourceIds)
            ->orderBy('window_start_at')
            ->get();

        $patients = Patient::query()
            ->whereIn('id', $visits->pluck('patient_id')->unique()->values())
            ->get()
            ->keyBy('id');

        foreach ($patients as $patient) {
            $patient->auditRead([
                'surface' => 'nurse_day_pack',
                'date' => $date->toDateString(),
            ]);
        }

        return [
            'date' => $date->toDateString(),
            'tenant_id' => $this->tenantContext->id(),
            'nurse' => [
                'id' => $nurse->id,
                'name' => $nurse->name,
            ],
            'visits' => $visits
                ->map(fn (PlannedVisit $visit): array => $this->visitPayload($visit, $patients->get($visit->patient_id)))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function visitPayload(PlannedVisit $visit, ?Patient $patient): array
    {
        if (! $patient instanceof Patient) {
            return [];
        }

        return [
            'id' => $visit->id,
            'execution_visit_id' => $this->executionVisitId($visit),
            'scheduled_date' => $visit->scheduled_date->toDateString(),
            'window_start_at' => $visit->window_start_at->toIso8601String(),
            'window_end_at' => $visit->window_end_at->toIso8601String(),
            'duration_minutes' => $visit->duration_minutes,
            'required_qualification' => $visit->required_qualification,
            'status' => $visit->status,
            'nurse_resource_id' => $visit->assigned_resource_id,
            'address' => $this->addressFor($patient),
            'patient' => [
                'id' => $patient->id,
                'mrn' => $patient->mrn,
                'name' => trim($patient->first_name.' '.$patient->last_name),
                'date_of_birth' => $patient->date_of_birth->toDateString(),
                'sex' => $patient->sex,
                'allergies' => $this->allergiesFor($patient),
                'medications' => $this->medicationsFor($patient),
                'problems' => $this->problemsFor($patient),
                'care_plan_goals' => $this->carePlanGoalsFor($patient),
            ],
            'tasks' => $this->tasksFor($patient, $visit),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function addressFor(Patient $patient): array
    {
        $address = PatientContact::query()
            ->where('patient_id', $patient->id)
            ->where('type', PatientContact::TYPE_ADDRESS)
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->first();

        return [
            'line1' => $address?->line1,
            'line2' => $address?->line2,
            'city' => $address?->city,
            'postal' => $address?->postal,
            'country' => $address?->country,
        ];
    }

    /**
     * @return list<array{id: string, substance: string, reaction: string|null, severity: string}>
     */
    private function allergiesFor(Patient $patient): array
    {
        return Allergy::query()
            ->where('patient_id', $patient->id)
            ->where('status', Allergy::STATUS_ACTIVE)
            ->orderBy('substance')
            ->get()
            ->map(fn (Allergy $allergy): array => [
                'id' => $allergy->id,
                'substance' => $allergy->substance,
                'reaction' => $allergy->reaction,
                'severity' => $allergy->severity,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, name: string, dose_text: string|null, route: string|null, frequency_text: string|null}>
     */
    private function medicationsFor(Patient $patient): array
    {
        return Medication::query()
            ->where('patient_id', $patient->id)
            ->where('status', Medication::STATUS_ACTIVE)
            ->orderBy('name')
            ->get()
            ->map(fn (Medication $medication): array => [
                'id' => $medication->id,
                'name' => $medication->name,
                'dose_text' => $medication->dose_text,
                'route' => $medication->route,
                'frequency_text' => $medication->frequency_text,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, description: string, code: string|null}>
     */
    private function problemsFor(Patient $patient): array
    {
        return Problem::query()
            ->where('patient_id', $patient->id)
            ->where('status', Problem::STATUS_ACTIVE)
            ->orderBy('description')
            ->get()
            ->map(fn (Problem $problem): array => [
                'id' => $problem->id,
                'description' => $problem->description,
                'code' => $problem->code,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, care_plan_id: string, care_plan_title: string, description: string, target_date: string|null}>
     */
    private function carePlanGoalsFor(Patient $patient): array
    {
        $goals = [];

        CarePlan::query()
            ->where('patient_id', $patient->id)
            ->where('status', CarePlan::STATUS_ACTIVE)
            ->get()
            ->each(function (CarePlan $plan) use (&$goals): void {
                CarePlanGoal::query()
                    ->where('care_plan_id', $plan->id)
                    ->where('status', CarePlanGoal::STATUS_OPEN)
                    ->orderBy('target_date')
                    ->get()
                    ->each(function (CarePlanGoal $goal) use (&$goals, $plan): void {
                        $goals[] = [
                            'id' => $goal->id,
                            'care_plan_id' => $plan->id,
                            'care_plan_title' => $plan->title,
                            'description' => $goal->description,
                            'target_date' => $goal->target_date?->toDateString(),
                        ];
                    });
            });

        return $goals;
    }

    /**
     * @return list<array{id: string, title: string, description: string|null, due_at: string, priority: string, status: string, source: string, visit_id?: string}>
     */
    private function tasksFor(Patient $patient, PlannedVisit $visit): array
    {
        $executionVisit = Visit::query()
            ->where('planned_visit_id', $visit->id)
            ->latest('created_at')
            ->first();

        if ($executionVisit instanceof Visit) {
            return VisitTask::query()
                ->where('visit_id', $executionVisit->id)
                ->where('status', VisitTask::STATUS_OPEN)
                ->orderBy('created_at')
                ->get()
                ->map(fn (VisitTask $task): array => [
                    'id' => $task->id,
                    'title' => $task->description,
                    'description' => null,
                    'due_at' => $executionVisit->scheduled_start_at->toIso8601String(),
                    'priority' => 'normal',
                    'status' => $task->status,
                    'source' => 'visit_task',
                    'visit_id' => $executionVisit->id,
                ])
                ->values()
                ->all();
        }

        return ClinicalTask::query()
            ->where('patient_id', $patient->id)
            ->whereDate('due_at', $visit->scheduled_date->toDateString())
            ->whereIn('status', [ClinicalTask::STATUS_OPEN, ClinicalTask::STATUS_IN_PROGRESS])
            ->orderBy('due_at')
            ->get()
            ->map(fn (ClinicalTask $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'due_at' => $task->due_at->toIso8601String(),
                'priority' => $task->priority,
                'status' => $task->status,
                'source' => 'clinical_task',
            ])
            ->values()
            ->all();
    }

    private function executionVisitId(PlannedVisit $visit): ?string
    {
        return Visit::query()
            ->where('planned_visit_id', $visit->id)
            ->latest('created_at')
            ->value('id');
    }
}
