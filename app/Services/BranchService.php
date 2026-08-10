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
    /**
     * @param  array<string, mixed>  $data
     *
     * The FIRST branch a tenant creates becomes the primary (default) branch — the exactly-one-primary
     * invariant (BRANCH.P2) is seeded by the {@see Branch} `creating` hook, which covers every creation
     * path. Later branches are non-primary; move the flag with {@see setPrimary()}.
     */
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
        // Deactivating the PRIMARY must never leave the tenant without one. If another ACTIVE branch
        // exists, atomically move primary to the earliest of them, then deactivate. If this is the
        // only active branch, it stays primary even while inactive — still exactly one primary
        // (never zero). The P1 hard guard [blocked while future appointments exist] runs BEFORE this
        // in the controller and is untouched.
        if (! $active && $branch->is_primary) {
            DB::transaction(function () use ($branch): void {
                $successor = Branch::query()
                    ->where('active', true)
                    ->whereKeyNot($branch->id)
                    ->orderBy('created_at')->orderBy('id')
                    ->first();

                if ($successor !== null) {
                    $successor->update(['is_primary' => true]);
                    $branch->update(['is_primary' => false, 'active' => false]);
                } else {
                    $branch->update(['active' => false]); // sole branch keeps primary
                }
            });

            return;
        }

        $branch->update(['active' => $active]);
    }

    /**
     * Make $branch the tenant's primary (default) branch — atomically, so EXACTLY ONE primary
     * remains (the current primary is cleared and $branch set in one transaction; never zero, never
     * two). The target must be ACTIVE (an inactive branch cannot be the default). There is no
     * "un-set primary" — the flag is only ever MOVED, so a tenant can never end up with zero.
     */
    public function setPrimary(Branch $branch): void
    {
        DB::transaction(function () use ($branch): void {
            Branch::query()->where('is_primary', true)->whereKeyNot($branch->id)->update(['is_primary' => false]);
            $branch->update(['is_primary' => true]);
        });
    }

    /**
     * Idempotent safety net + the runtime mirror of the migration backfill: guarantee the current
     * tenant has EXACTLY ONE primary branch. If none is primary, promote the earliest active branch
     * (else the earliest overall); if several are, keep the earliest and clear the rest. A no-op when
     * the invariant already holds.
     */
    public function ensurePrimary(): void
    {
        $primaries = Branch::query()->where('is_primary', true)->orderBy('created_at')->orderBy('id')->get();

        if ($primaries->count() === 1) {
            return;
        }

        DB::transaction(function () use ($primaries): void {
            if ($primaries->isEmpty()) {
                $target = Branch::query()->where('active', true)->orderBy('created_at')->orderBy('id')->first()
                    ?? Branch::query()->orderBy('created_at')->orderBy('id')->first();
                $target?->update(['is_primary' => true]);

                return;
            }

            // More than one — keep the earliest, clear the others.
            $keep = $primaries->first();
            Branch::query()->where('is_primary', true)->whereKeyNot($keep->id)->update(['is_primary' => false]);
        });
    }

    /**
     * SOFT-SUSPEND toggle (BRANCH.P1): turn online bookings on/off for a branch. `false` stops NEW
     * online bookings (the branch is hidden from public/portal booking and the online write path
     * refuses it) while the branch stays `active` — existing appointments and the day-board are
     * untouched. Always allowed (unlike hard deactivation, it never strands scheduled care), and
     * audited distinctly (branch.online_bookings_enabled / _suspended) via the model hook.
     */
    public function setOnlineBookings(Branch $branch, bool $accepts): void
    {
        $branch->update(['accepts_online_bookings' => $accepts]);
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
