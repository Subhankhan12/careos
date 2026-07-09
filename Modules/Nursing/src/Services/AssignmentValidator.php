<?php

namespace Modules\Nursing\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
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

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  iterable<int, PlannedVisit>  $assignedVisits
     * @return list<string>
     */
    public function validate(PlannedVisit $visit, Resource $resource, iterable $assignedVisits): array
    {
        $constraint = NurseConstraint::query()->where('resource_id', $resource->id)->first();

        if ($constraint === null) {
            return [self::REASON_NURSE_CONSTRAINT_MISSING];
        }

        $assigned = collect($assignedVisits)
            ->filter(fn (PlannedVisit $assignedVisit): bool => $assignedVisit->id !== $visit->id)
            ->values();

        $reasons = [];

        if (! $this->qualificationSatisfied($visit, $constraint)) {
            $reasons[] = self::REASON_QUALIFICATION;
        }

        if ($this->hasWindowOverlap($visit, $assigned)) {
            $reasons[] = self::REASON_WINDOW_OVERLAP;
        }

        $travelReason = $this->travelReason($visit, $assigned, $constraint);

        if ($travelReason !== null) {
            $reasons[] = $travelReason;
        }

        if ($this->exceedsHourCap($visit, $assigned, $constraint)) {
            $reasons[] = self::REASON_HOUR_CAP_EXCEEDED;
        }

        return array_values(array_unique($reasons));
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
