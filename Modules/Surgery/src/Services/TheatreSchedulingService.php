<?php

namespace Modules\Surgery\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Surgery\Exceptions\TheatreException;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\Theatre;
use Modules\Surgery\Models\TheatreSlot;

/**
 * Theatre + theatre-scheduling (SURGERY.G1). Creating a theatre is gated `theatre.manage`; booking a surgical
 * block is gated `surgery.schedule`. Per docs/HOSPITAL-PHASE5-SURGERY-MAP.md §2.1 a theatre slot is a NET-NEW
 * bounded block that REUSES the booking/bed overlap-lock INVARIANT — NOT the Scheduling day-board model.
 *
 * THE OVERLAP-LOCK INVARIANT (the crux): two surgeries cannot overlap in the same theatre. `bookSlot` runs,
 * in ONE transaction, the `BookingService::lockResource`→`assertNoOverlap` idiom (also `BedService::claim` /
 * `MedicationStock::lockOnHand`): lock the theatre row `FOR UPDATE` → assert no blocking slot overlaps the
 * requested block → insert. N racing bookings for the same theatre serialise on the theatre-row lock, so
 * exactly one wins the contested block (proven by the parallel hammer). This reuses the concurrency INVARIANT,
 * not the day-grid — a theatre schedule is a bounded-block list.
 */
class TheatreSchedulingService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Author an operating theatre (an OR room) for a branch. Gated `theatre.manage`; the model asserts the
     * branch is within the tenant (fail-closed) and the name is non-empty.
     */
    public function createTheatre(User $actor, string $branchId, string $name, ?string $type = null): Theatre
    {
        Gate::forUser($actor)->authorize('theatre.manage');

        return Theatre::query()->create([
            'branch_id' => $branchId,
            'name' => $name,
            'type' => $type,
        ]);
    }

    /**
     * Book a surgical block in a theatre — concurrency-safe (the overlap-lock invariant). Gated
     * `surgery.schedule`; the theatre must be within the tenant (fail-closed). Overlapping the theatre's
     * existing booked/in-progress block throws {@see TheatreException::slotConflict}.
     */
    public function bookSlot(
        User $actor,
        Theatre $theatre,
        Carbon $startsAt,
        int $durationMinutes,
        ?SurgicalCase $case = null,
    ): TheatreSlot {
        Gate::forUser($actor)->authorize('surgery.schedule');
        $this->assertSameTenant($theatre->tenant_id, 'theatre_id', $theatre->id);

        if ($durationMinutes <= 0) {
            throw TheatreException::nonPositiveDuration();
        }

        $tenantId = (string) $this->tenantContext->id();
        $endsAt = $startsAt->copy()->addMinutes($durationMinutes);

        return DB::transaction(function () use ($tenantId, $theatre, $startsAt, $endsAt, $case): TheatreSlot {
            $this->lockTheatre($tenantId, $theatre->id);
            $this->assertNoOverlap($tenantId, $theatre->id, $startsAt, $endsAt);

            return TheatreSlot::query()->create([
                'theatre_id' => $theatre->id,
                'surgical_case_id' => $case?->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => TheatreSlot::STATUS_BOOKED,
            ]);
        });
    }

    /**
     * Serialise concurrent bookings for the same theatre: a `SELECT … FOR UPDATE` on the theatre row so N
     * racing bookings queue on it (the `BookingService::lockResource` idiom). Fails closed if the theatre is
     * not in the tenant.
     */
    private function lockTheatre(string $tenantId, string $theatreId): void
    {
        $rows = DB::select(
            'select id from theatres where tenant_id = ? and id = ? for update',
            [$tenantId, $theatreId],
        );

        if ($rows === []) {
            throw CrossTenantReferenceException::forAttribute('theatre_id', $theatreId);
        }
    }

    /**
     * Assert no BLOCKING slot in this theatre overlaps [starts_at, ends_at). Two intervals overlap iff
     * existing.starts_at < new.ends_at AND existing.ends_at > new.starts_at. Locked `FOR UPDATE` so a
     * concurrent booker sees this one (the `assertNoOverlap` idiom).
     */
    private function assertNoOverlap(string $tenantId, string $theatreId, Carbon $startsAt, Carbon $endsAt): void
    {
        $blocking = TheatreSlot::BLOCKING_STATUSES;
        $placeholders = implode(',', array_fill(0, count($blocking), '?'));

        $overlaps = DB::select(
            <<<SQL
select id
from theatre_slots
where tenant_id = ?
  and theatre_id = ?
  and status in ({$placeholders})
  and starts_at < ?
  and ends_at > ?
for update
SQL,
            [
                $tenantId,
                $theatreId,
                ...$blocking,
                $endsAt->format('Y-m-d H:i:s'),
                $startsAt->format('Y-m-d H:i:s'),
            ],
        );

        if ($overlaps !== []) {
            throw TheatreException::slotConflict($theatreId);
        }
    }

    private function assertSameTenant(?string $tenantId, string $attribute, string $id): void
    {
        if ($tenantId !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
