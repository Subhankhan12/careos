<?php

namespace Modules\Platform\Services;

use Illuminate\Support\Collection;
use Modules\Platform\Models\BranchHours;

/**
 * Resolves a branch's opening window for the scheduling engine. A branch with NO
 * configured rows is "unconfigured" and imposes NO constraint (the engine keeps its
 * default scan window and booking is unrestricted) — this preserves existing behavior
 * for every branch that hasn't set hours. A configured branch bounds bookable times to
 * its per-weekday [open, close] window, and a closed day offers nothing.
 */
class BranchHoursService
{
    /**
     * All configured weekday rows for a branch, keyed by weekday (0=Sun … 6=Sat).
     * Empty when the branch is unconfigured.
     *
     * @return Collection<int, BranchHours>
     */
    public function forBranch(string $branchId): Collection
    {
        return BranchHours::query()->where('branch_id', $branchId)->get()->keyBy('weekday');
    }

    public function isConfigured(string $branchId): bool
    {
        return $this->forBranch($branchId)->isNotEmpty();
    }

    /**
     * The minute-of-day window a slot scan should use for this branch/weekday:
     *   - the configured [open, close] when the branch is hours-managed and open,
     *   - null when the branch is hours-managed and CLOSED that day (offer nothing),
     *   - the given default window when the branch has no configured hours.
     *
     * @return array{open: int, close: int}|null
     */
    public function scanWindow(string $branchId, int $weekday, int $defaultOpenMinutes, int $defaultCloseMinutes): ?array
    {
        $hours = $this->forBranch($branchId);

        if ($hours->isEmpty()) {
            return ['open' => $defaultOpenMinutes, 'close' => $defaultCloseMinutes];
        }

        $day = $hours->get($weekday);

        if ($day === null || $day->is_closed) {
            return null;
        }

        return ['open' => (int) $day->openMinutes(), 'close' => (int) $day->closeMinutes()];
    }

    /**
     * Whether a booking that STARTS at $startMinutes (minute-of-day) on $weekday is
     * within the branch's opening hours. Unconfigured branches are unrestricted; a
     * closed day rejects; otherwise the start must sit inside [open, close].
     */
    public function allowsStart(string $branchId, int $weekday, int $startMinutes): bool
    {
        $hours = $this->forBranch($branchId);

        if ($hours->isEmpty()) {
            return true;
        }

        $day = $hours->get($weekday);

        if ($day === null || $day->is_closed) {
            return false;
        }

        return $startMinutes >= (int) $day->openMinutes() && $startMinutes <= (int) $day->closeMinutes();
    }
}
