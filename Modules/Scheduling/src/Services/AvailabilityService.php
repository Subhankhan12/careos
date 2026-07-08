<?php

namespace Modules\Scheduling\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\ResourceAvailability;

class AvailabilityService
{
    /**
     * @return list<array{date: string, start_at: CarbonImmutable, end_at: CarbonImmutable}>
     */
    public function windowsFor(
        Resource $resource,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
    ): array {
        $cursor = CarbonImmutable::parse($startDate)->startOfDay();
        $last = CarbonImmutable::parse($endDate)->startOfDay();
        $rows = $this->availabilityRows($resource);
        $windows = [];

        while ($cursor->lessThanOrEqualTo($last)) {
            foreach ($this->windowsForDate($rows, $cursor) as $window) {
                $windows[] = [
                    'date' => $cursor->toDateString(),
                    'start_at' => $cursor->addMinutes($window['start']),
                    'end_at' => $cursor->addMinutes($window['end']),
                ];
            }

            $cursor = $cursor->addDay();
        }

        return $windows;
    }

    /**
     * @return list<ResourceAvailability>
     */
    private function availabilityRows(Resource $resource): array
    {
        $rows = [];

        foreach ($resource->availability()->orderBy('start_time')->get() as $availability) {
            if ($availability instanceof ResourceAvailability) {
                $rows[] = $availability;
            }
        }

        return $rows;
    }

    /**
     * @param  list<ResourceAvailability>  $rows
     * @return list<array{start: int, end: int}>
     */
    private function windowsForDate(array $rows, CarbonImmutable $date): array
    {
        $dateRows = array_values(array_filter(
            $rows,
            fn (ResourceAvailability $row): bool => $this->availabilityDate($row) === $date->toDateString(),
        ));
        $availableOverrides = array_values(array_filter(
            $dateRows,
            fn (ResourceAvailability $row): bool => $row->is_available,
        ));

        $baseRows = $availableOverrides !== []
            ? $availableOverrides
            : array_values(array_filter($rows, fn (ResourceAvailability $row): bool => $row->date === null
                && $row->weekday === $date->dayOfWeek
                && $row->is_available));

        $windows = array_map(
            fn (ResourceAvailability $row): array => [
                'start' => $this->timeToMinutes((string) $row->start_time),
                'end' => $this->timeToMinutes((string) $row->end_time),
            ],
            $baseRows,
        );

        usort($windows, fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        $blocks = array_values(array_filter(
            $dateRows,
            fn (ResourceAvailability $row): bool => ! $row->is_available,
        ));

        foreach ($blocks as $block) {
            if ($block->isFullDayBlock()) {
                return [];
            }
        }

        foreach ($blocks as $block) {
            $windows = $this->subtractBlock(
                $windows,
                $this->timeToMinutes((string) $block->start_time),
                $this->timeToMinutes((string) $block->end_time),
            );
        }

        return $windows;
    }

    /**
     * @param  list<array{start: int, end: int}>  $windows
     * @return list<array{start: int, end: int}>
     */
    private function subtractBlock(array $windows, int $blockStart, int $blockEnd): array
    {
        $remaining = [];

        foreach ($windows as $window) {
            if ($blockEnd <= $window['start'] || $blockStart >= $window['end']) {
                $remaining[] = $window;

                continue;
            }

            if ($blockStart > $window['start']) {
                $remaining[] = [
                    'start' => $window['start'],
                    'end' => min($blockStart, $window['end']),
                ];
            }

            if ($blockEnd < $window['end']) {
                $remaining[] = [
                    'start' => max($blockEnd, $window['start']),
                    'end' => $window['end'],
                ];
            }
        }

        return array_values(array_filter(
            $remaining,
            fn (array $window): bool => $window['end'] > $window['start'],
        ));
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($hours * 60) + $minutes;
    }

    private function availabilityDate(ResourceAvailability $availability): ?string
    {
        if ($availability->date === null) {
            return null;
        }

        return CarbonImmutable::parse($availability->date)->toDateString();
    }
}
