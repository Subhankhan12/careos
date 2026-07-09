<?php

namespace Modules\Nursing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Nursing\Events\PlannedVisitChanged;
use Modules\Nursing\Models\AgreementService;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\VisitPlan;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\Constraint\BetweenConstraint;

class VisitPlanGenerator
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function materialize(VisitPlan $visitPlan, string|CarbonImmutable $fromDate, string|CarbonImmutable $toDate): int
    {
        $this->assertSameTenant($visitPlan, 'visit_plan_id');

        if (! $visitPlan->active) {
            return 0;
        }

        $from = $this->localDate($fromDate, $visitPlan->timezone)->startOfDay();
        $to = $this->localDate($toDate, $visitPlan->timezone)->endOfDay();

        if ($to->lessThan($from)) {
            throw new InvalidArgumentException('Materialization end date must be on or after the start date.');
        }

        $planEnd = $visitPlan->ends_on?->toImmutable()->setTimezone($visitPlan->timezone)->endOfDay();
        $effectiveTo = $planEnd !== null && $planEnd->lessThan($to) ? $planEnd : $to;

        $rule = new Rule(
            $visitPlan->rrule,
            $this->localWindowStart($visitPlan, $visitPlan->starts_on->toDateString()),
            null,
            $visitPlan->timezone,
        );

        $constraint = new BetweenConstraint($from, $effectiveTo, true);
        $occurrences = (new ArrayTransformer)->transform($rule, $constraint);
        $createdDates = [];

        DB::transaction(function () use ($visitPlan, $occurrences, &$createdDates): void {
            $agreement = ServiceAgreement::query()->whereKey($visitPlan->service_agreement_id)->firstOrFail();
            $agreementService = AgreementService::query()->whereKey($visitPlan->agreement_service_id)->firstOrFail();
            $candidateDates = [];
            $rows = [];

            foreach ($occurrences as $occurrence) {
                $localOccurrence = CarbonImmutable::instance($occurrence->getStart())
                    ->setTimezone($visitPlan->timezone);
                $scheduledDate = $localOccurrence->toDateString();

                if ($visitPlan->ends_on !== null && $scheduledDate > $visitPlan->ends_on->toDateString()) {
                    continue;
                }

                $windowStart = $this->localWindowStart($visitPlan, $scheduledDate)->utc();
                $windowEnd = $this->localWindowEnd($visitPlan, $scheduledDate)->utc();
                $now = Date::now()->toDateTimeString();

                $candidateDates[] = $scheduledDate;
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $visitPlan->tenant_id,
                    'visit_plan_id' => $visitPlan->id,
                    'patient_id' => $agreement->patient_id,
                    'scheduled_date' => $scheduledDate,
                    'window_start_at' => $windowStart->toDateTimeString(),
                    'window_end_at' => $windowEnd->toDateTimeString(),
                    'duration_minutes' => $visitPlan->duration_minutes,
                    'required_qualification' => $agreementService->required_qualification,
                    'status' => PlannedVisit::STATUS_PLANNED,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows === []) {
                return;
            }

            $existingDates = PlannedVisit::query()
                ->where('visit_plan_id', $visitPlan->id)
                ->whereIn('scheduled_date', $candidateDates)
                ->get(['scheduled_date'])
                ->map(fn (PlannedVisit $visit): string => $visit->scheduled_date->toDateString())
                ->all();

            $createdDates = array_values(array_diff($candidateDates, $existingDates));

            DB::table('planned_visits')->upsert(
                $rows,
                ['tenant_id', 'visit_plan_id', 'scheduled_date'],
                ['patient_id', 'window_start_at', 'window_end_at', 'duration_minutes', 'required_qualification', 'updated_at'],
            );

            $createdVisits = PlannedVisit::query()
                ->where('visit_plan_id', $visitPlan->id)
                ->whereIn('scheduled_date', $createdDates)
                ->orderBy('scheduled_date')
                ->get();

            foreach ($createdVisits as $visit) {
                Event::dispatch(new PlannedVisitChanged($visit, 'planned_visit.materialized', [
                    'visit_plan_id' => $visitPlan->id,
                    'scheduled_date' => $visit->scheduled_date->toDateString(),
                    'timezone' => $visitPlan->timezone,
                ]));
            }
        });

        return count($createdDates);
    }

    public function cancelOccurrence(PlannedVisit $visit, string $reason, ?User $actor = null): PlannedVisit
    {
        $this->assertSameTenant($visit, 'planned_visit_id');

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Cancellation reason is required.');
        }

        $visit->forceFill([
            'status' => PlannedVisit::STATUS_CANCELLED,
            'cancellation_reason' => $reason,
        ])->save();

        Event::dispatch(new PlannedVisitChanged(
            $visit->refresh(),
            'planned_visit.cancelled',
            ['visit_plan_id' => $visit->visit_plan_id],
            $actor,
            $reason,
        ));

        return $visit;
    }

    private function assertSameTenant(object $model, string $attribute): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }

    private function localDate(string|CarbonImmutable $date, string $timezone): CarbonImmutable
    {
        if ($date instanceof CarbonImmutable) {
            return $date->setTimezone($timezone);
        }

        return CarbonImmutable::parse($date, $timezone);
    }

    private function localWindowStart(VisitPlan $visitPlan, string $scheduledDate): CarbonImmutable
    {
        return CarbonImmutable::parse($scheduledDate.' '.$visitPlan->window_start_time, $visitPlan->timezone);
    }

    private function localWindowEnd(VisitPlan $visitPlan, string $scheduledDate): CarbonImmutable
    {
        return CarbonImmutable::parse($scheduledDate.' '.$visitPlan->window_end_time, $visitPlan->timezone);
    }
}
