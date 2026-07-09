<?php

namespace Modules\Clinical\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Modules\Clinical\Events\ClinicalRecordChanged;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Problem;
use Modules\Clinical\Models\Recall;
use Modules\Clinical\Models\RecallRule;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

class RecallEngine
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Deterministically evaluates active recall rules for one explicit tenant.
     *
     * Supported criteria keys:
     * - `active_problem_codes`: list of exact problem codes; patient must have at least one active match.
     * - `missing_encounter_type`: exact encounter type absent in the previous `interval_months`.
     *
     * @return Collection<int, Recall>
     */
    public function evaluate(Tenant $tenant, ?User $actor = null): Collection
    {
        $previousTenant = $this->tenantContext->current();
        $this->tenantContext->set($tenant);

        try {
            return $this->evaluateCurrentTenant($actor);
        } finally {
            if ($previousTenant instanceof Tenant) {
                $this->tenantContext->set($previousTenant);
            } else {
                $this->tenantContext->forget();
            }
        }
    }

    /**
     * @return Collection<int, Recall>
     */
    private function evaluateCurrentTenant(?User $actor): Collection
    {
        $generated = collect();
        $dueOn = now()->toDateString();

        $rules = RecallRule::query()
            ->where('active', true)
            ->orderBy('name')
            ->get();

        foreach ($rules as $rule) {
            Patient::query()
                ->where('status', Patient::STATUS_ACTIVE)
                ->orderBy('id')
                ->get()
                ->filter(fn (Patient $patient): bool => $this->patientMatchesRule($patient, $rule))
                ->each(function (Patient $patient) use ($actor, $dueOn, $generated, $rule): void {
                    $recall = Recall::query()->firstOrCreate([
                        'patient_id' => $patient->id,
                        'rule_id' => $rule->id,
                        'due_on' => $dueOn,
                    ], [
                        'status' => Recall::STATUS_DUE,
                    ]);

                    if ($recall->wasRecentlyCreated) {
                        $this->auditGenerated($recall, $rule, $actor);
                    }

                    $generated->push($recall);
                });
        }

        return $generated->values();
    }

    private function patientMatchesRule(Patient $patient, RecallRule $rule): bool
    {
        $criteria = $rule->criteria;
        $hasRecognizedCriterion = false;

        $activeProblemCodes = $criteria['active_problem_codes'] ?? [];
        if (is_array($activeProblemCodes) && $activeProblemCodes !== []) {
            $hasRecognizedCriterion = true;
            $codes = array_values(array_filter($activeProblemCodes, is_string(...)));

            if ($codes === []) {
                return false;
            }

            if (! Problem::query()
                ->where('patient_id', $patient->id)
                ->where('status', Problem::STATUS_ACTIVE)
                ->whereIn('code', $codes)
                ->exists()) {
                return false;
            }
        }

        $missingEncounterType = $criteria['missing_encounter_type'] ?? null;
        if (is_string($missingEncounterType) && trim($missingEncounterType) !== '') {
            $hasRecognizedCriterion = true;
            $cutoff = now()->subMonths($rule->interval_months);

            if (Encounter::query()
                ->where('patient_id', $patient->id)
                ->where('type', $missingEncounterType)
                ->where('started_at', '>=', $cutoff)
                ->exists()) {
                return false;
            }
        }

        return $hasRecognizedCriterion;
    }

    private function auditGenerated(Recall $recall, RecallRule $rule, ?User $actor): void
    {
        if (! $actor instanceof User) {
            return;
        }

        Event::dispatch(new ClinicalRecordChanged(
            'recall.generated',
            'recall',
            $recall->id,
            $recall->patient_id,
            $actor,
            [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'due_on' => $recall->due_on->toDateString(),
                'criteria' => $rule->criteria,
            ],
        ));
    }
}
