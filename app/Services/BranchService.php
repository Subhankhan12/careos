<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\BranchHours;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource;

/**
 * Branch writes for the tenant-admin surface. Lives in the APP layer because branch
 * deactivation safety spans modules (Platform's Branch + Scheduling's appointments/
 * resources), and the arch rules forbid Platform depending on Scheduling. All writes
 * are tenant-scoped (BelongsToTenant) and audited via the AppServiceProvider model hooks
 * (branch.created / branch.updated / branch.activated / branch.deactivated / branch.hours_changed).
 */
class BranchService
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Branch
    {
        return Branch::create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(Branch $branch, array $data): Branch
    {
        $branch->update($data);

        return $branch;
    }

    public function setActive(Branch $branch, bool $active): void
    {
        $branch->update(['active' => $active]);
    }

    /**
     * Future appointments that would be stranded if the branch were removed — the
     * blocking-status set (booked/confirmed/arrived/in-progress), starting from now.
     */
    public function futureAppointmentCount(string $branchId): int
    {
        return Appointment::query()
            ->where('branch_id', $branchId)
            ->where('starts_at', '>=', now())
            ->whereIn('status', Appointment::blockingStatuses())
            ->count();
    }

    public function activeResourceCount(string $branchId): int
    {
        return Resource::query()
            ->where('branch_id', $branchId)
            ->where('active', true)
            ->count();
    }

    /**
     * Replace the branch's weekly opening hours. Upserts one row per weekday (0=Sun…6=Sat);
     * a closed day stores no times. The slot/booking engine reads these to bound bookings.
     *
     * @param  array<int, array{is_closed: bool, open_time: ?string, close_time: ?string}>  $days
     */
    public function setHours(Branch $branch, array $days): void
    {
        DB::transaction(function () use ($branch, $days): void {
            foreach ($days as $weekday => $row) {
                BranchHours::updateOrCreate(
                    ['branch_id' => $branch->id, 'weekday' => $weekday],
                    [
                        'is_closed' => $row['is_closed'],
                        'open_time' => $row['is_closed'] ? null : $row['open_time'],
                        'close_time' => $row['is_closed'] ? null : $row['close_time'],
                    ],
                );
            }
        });
    }
}
