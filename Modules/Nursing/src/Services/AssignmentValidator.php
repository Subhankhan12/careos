<?php

namespace Modules\Nursing\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\Nursing\Models\Competency;
use Modules\Nursing\Models\NurseCompetency;
use Modules\Nursing\Models\NurseConstraint;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Platform\Services\SettingsService;
use Modules\Scheduling\Models\Resource;

class AssignmentValidator
{
    public const REASON_QUALIFICATION = 'qualification_mismatch';

    public const REASON_WINDOW_OVERLAP = 'window_overlap';

    public const REASON_TRAVEL_LOCATION_MISSING = 'travel_location_missing';

    public const REASON_TRAVEL_INFEASIBLE = 'travel_infeasible';

    public const REASON_HOUR_CAP_EXCEEDED = 'hour_cap_exceeded';

    public const REASON_NURSE_CONSTRAINT_MISSING = 'nurse_constraint_missing';

    /** Distinct BLOCKING reason prefix for a HARD competency the nurse lacks. */
    public const REASON_COMPETENCY_MISSING_HARD = 'competency_missing_hard';

    /** Distinct NON-BLOCKING advisory prefix for a SOFT competency the nurse lacks. */
    public const REASON_COMPETENCY_MISSING_SOFT = 'competency_missing_soft';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Backwards-compatible entry point: returns only the BLOCKING reason codes
     * (missing/qualification/window/travel/hour-cap plus hard-competency misses).
     * Existing callers (the assignment path, the dispatch agent) treat a non-empty
     * result as a refusal; soft-competency warnings are intentionally excluded here.
     *
     * @param  iterable<int, PlannedVisit>  $assignedVisits
     * @return list<string>
     */
    public function validate(PlannedVisit $visit, Resource $resource, iterable $assignedVisits): array
    {
        return $this->evaluate($visit, $resource, $assignedVisits)->blocking;
    }

    /**
     * Full validation separating BLOCKING violations from NON-BLOCKING warnings.
     *
     * @param  iterable<int, PlannedVisit>  $assignedVisits
     */
    public function evaluate(PlannedVisit $visit, Resource $resource, iterable $assignedVisits): AssignmentValidation
    {
        // Competency composes with every other rule and is independent of the
        // nurse-constraint row, so it is evaluated first, whether or not the nurse
        // has a constraint record.
        [$competencyBlocking, $competencyWarnings] = $this->competencyReasons($visit, $resource);

        $blocking = $competencyBlocking;

        $constraint = NurseConstraint::query()->where('resource_id', $resource->id)->first();

        if ($constraint === null) {
            $blocking[] = self::REASON_NURSE_CONSTRAINT_MISSING;

            return new AssignmentValidation(
                array_values(array_unique($blocking)),
                array_values(array_unique($competencyWarnings)),
            );
        }

        $assigned = collect($assignedVisits)
            ->filter(fn (PlannedVisit $assignedVisit): bool => $assignedVisit->id !== $visit->id)
            ->values();

        if (! $this->qualificationSatisfied($visit, $constraint)) {
            $blocking[] = self::REASON_QUALIFICATION;
        }

        if ($this->hasWindowOverlap($visit, $assigned)) {
            $blocking[] = self::REASON_WINDOW_OVERLAP;
        }

        $travelReason = $this->travelReason($visit, $assigned, $constraint);

        if ($travelReason !== null) {
            $blocking[] = $travelReason;
        }

        if ($this->exceedsHourCap($visit, $assigned, $constraint)) {
            $blocking[] = self::REASON_HOUR_CAP_EXCEEDED;
        }

        return new AssignmentValidation(
            array_values(array_unique($blocking)),
            array_values(array_unique($competencyWarnings)),
        );
    }

    /**
     * For each competency the visit requires, if the nurse does not currently HOLD
     * it (missing, inactive, or expired), produce either a blocking reason (the
     * agency configured the competency HARD) or a warning (configured SOFT). Each
     * reason/advisory names the competency code. A required code with no active
     * tenant-configured competency is advisory-only — the system never blocks on a
     * rule the agency has not configured as hard.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function competencyReasons(PlannedVisit $visit, Resource $resource): array
    {
        $required = array_values(array_unique(array_filter(array_map(
            fn ($code): string => mb_strtolower(trim((string) $code)),
            $visit->required_competencies ?? [],
        ), fn (string $code): bool => $code !== '')));

        if ($required === []) {
            return [[], []];
        }

        $heldCompetencyIds = NurseCompetency::query()
            ->where('resource_id', $resource->id)
            ->held()
            ->pluck('competency_id')
            ->all();

        $definitions = Competency::query()
            ->whereIn('code', $required)
            ->get()
            ->keyBy(fn (Competency $competency): string => mb_strtolower(trim($competency->code)));

        $blocking = [];
        $warnings = [];

        foreach ($required as $code) {
            $competency = $definitions->get($code);

            // No active tenant-configured competency for this code → advisory only.
            if ($competency === null || ! $competency->active) {
                $warnings[] = self::REASON_COMPETENCY_MISSING_SOFT.':'.$code;

                continue;
            }

            if (in_array($competency->id, $heldCompetencyIds, true)) {
                continue;
            }

            if ($competency->enforcement === Competency::ENFORCEMENT_HARD) {
                $blocking[] = self::REASON_COMPETENCY_MISSING_HARD.':'.$code;
            } else {
                $warnings[] = self::REASON_COMPETENCY_MISSING_SOFT.':'.$code;
            }
        }

        return [$blocking, $warnings];
    }

    private function qualificationSatisfied(PlannedVisit $visit, NurseConstraint $constraint): bool
    {
        if ($visit->required_qualification === null || trim($visit->required_qualification) === '') {
            return true;
        }

        return $this->normalize($visit->required_qualification) === $this->normalize($constraint->qualification);
    }

    /**
     * @param  Collection<int, PlannedVisit>  $assigned
     */
    private function hasWindowOverlap(PlannedVisit $visit, Collection $assigned): bool
    {
        return $assigned->contains(
            fn (PlannedVisit $assignedVisit): bool => $assignedVisit->window_start_at->lt($visit->window_end_at)
                && $assignedVisit->window_end_at->gt($visit->window_start_at),
        );
    }

    /**
     * @param  Collection<int, PlannedVisit>  $assigned
     */
    private function travelReason(PlannedVisit $visit, Collection $assigned, NurseConstraint $constraint): ?string
    {
        $sameDay = $assigned
            ->filter(
                fn (PlannedVisit $assignedVisit): bool => $assignedVisit->window_start_at->toDateString()
                    === $visit->window_start_at->toDateString(),
            )
            ->sortBy(fn (PlannedVisit $assignedVisit): string => $assignedVisit->window_start_at->toDateTimeString())
            ->values();

        $previous = $sameDay
            ->filter(fn (PlannedVisit $assignedVisit): bool => $assignedVisit->window_end_at->lte($visit->window_start_at))
            ->last();
        $next = $sameDay
            ->filter(fn (PlannedVisit $assignedVisit): bool => $assignedVisit->window_start_at->gte($visit->window_end_at))
            ->first();

        foreach ([[$previous, $visit], [$visit, $next]] as [$from, $to]) {
            if (! $from instanceof PlannedVisit || ! $to instanceof PlannedVisit) {
                continue;
            }

            if (! $this->hasLocation($from) || ! $this->hasLocation($to)) {
                return self::REASON_TRAVEL_LOCATION_MISSING;
            }

            $availableMinutes = $from->window_end_at->diffInMinutes($to->window_start_at, false);
            $requiredMinutes = $this->travelMinutes($from, $to);

            if ($requiredMinutes > $availableMinutes || $requiredMinutes > $constraint->max_travel_minutes_between_visits) {
                return self::REASON_TRAVEL_INFEASIBLE;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, PlannedVisit>  $assigned
     */
    private function exceedsHourCap(
        PlannedVisit $visit,
        Collection $assigned,
        NurseConstraint $constraint,
    ): bool {
        $weekStart = $visit->window_start_at->copy()->startOfWeek(CarbonInterface::MONDAY);
        $weekEnd = $visit->window_start_at->copy()->endOfWeek(CarbonInterface::SUNDAY);

        $assignedMinutes = $assigned
            ->filter(
                fn (PlannedVisit $assignedVisit): bool => $assignedVisit->window_start_at->betweenIncluded($weekStart, $weekEnd),
            )
            ->sum(fn (PlannedVisit $assignedVisit): int => (int) $assignedVisit->duration_minutes);

        return ($assignedMinutes + (int) $visit->duration_minutes) / 60 > (float) $constraint->max_hours_per_week;
    }

    private function hasLocation(PlannedVisit $visit): bool
    {
        return $visit->location_latitude !== null && $visit->location_longitude !== null;
    }

    private function travelMinutes(PlannedVisit $from, PlannedVisit $to): float
    {
        $distanceKm = $this->distanceKm(
            (float) $from->location_latitude,
            (float) $from->location_longitude,
            (float) $to->location_latitude,
            (float) $to->location_longitude,
        );
        $speedKmh = max(1.0, (float) $this->settings->get('nursing.dispatch.average_speed_kmh', 40));

        return ($distanceKm / $speedKmh) * 60;
    }

    private function distanceKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthKm = 6371.0;
        $latDelta = deg2rad($toLat - $fromLat);
        $lngDelta = deg2rad($toLng - $fromLng);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($lngDelta / 2) ** 2;

        return $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
