<?php

namespace Modules\Hospital\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Hospital\Events\BedStatusChanged;
use Modules\Hospital\Exceptions\BedNotAvailableException;
use Modules\Hospital\Exceptions\BedStatusTransitionException;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Ward;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * Bed CRUD + housekeeping-status management (HOSPITAL.G1). Tenant + branch scoped;
 * management is gated on `bed.manage` and the admission claim on `admission.manage`
 * (server Gate authoritative). Status transitions are legal-only (see
 * {@see Bed::TRANSITIONS}) and audited via the BedStatusChanged event (app-layer
 * listener → append-only audit; Hospital stays free of Audit).
 *
 * The concurrency-safe {@see self::claim()} applies the BookingService
 * lock-row-then-assert idiom to a Bed: two parallel claims on one free bed produce
 * exactly one winner. The actual admission-to-bed (attaching a patient/stay) lands
 * in HOSPITAL.G2 — this gate builds and proves the safe-claim primitive only.
 */
class BedService
{
    public function create(User $actor, Ward $ward, string $label, string $bedType): Bed
    {
        Gate::forUser($actor)->authorize('bed.manage');

        if (! in_array($bedType, Bed::TYPES, true)) {
            throw new InvalidArgumentException("Unknown bed type: {$bedType}.");
        }

        return Bed::query()->create([
            'branch_id' => $ward->branch_id,
            'ward_id' => $ward->id,
            'label' => $label,
            'bed_type' => $bedType,
        ]);
    }

    public function deactivate(User $actor, Bed $bed): Bed
    {
        Gate::forUser($actor)->authorize('bed.manage');

        $bed->forceFill(['active' => false])->save();

        return $bed;
    }

    /**
     * Read a ward's beds and their housekeeping status (the data the HOSPITAL.G3
     * ward board will render). Tenant-scoped by BelongsToTenant.
     *
     * @return Collection<int, Bed>
     */
    public function forWard(Ward $ward): Collection
    {
        return Bed::query()
            ->where('ward_id', $ward->id)
            ->orderBy('label')
            ->get();
    }

    /**
     * A housekeeping status change (cleaning / blocked / back to free). free ->
     * occupied is NOT allowed here — occupancy goes through the concurrency-safe
     * claim() so it is always serialized under a row lock. Legal-only + audited.
     */
    public function setStatus(User $actor, Bed $bed, string $to, ?string $reason = null): Bed
    {
        Gate::forUser($actor)->authorize('bed.manage');

        if ($to === Bed::STATUS_OCCUPIED) {
            throw BedStatusTransitionException::useClaim($bed->id);
        }

        if (! in_array($to, Bed::STATUSES, true)) {
            throw BedStatusTransitionException::illegal($bed->id, $bed->status, $to);
        }

        $from = DB::transaction(function () use ($bed, $to): string {
            $current = $this->lockBedStatus($bed->tenant_id, $bed->id);

            if (! Bed::canTransition($current, $to)) {
                throw BedStatusTransitionException::illegal($bed->id, $current, $to);
            }

            $bed->status = $to;
            $bed->save();

            return $current;
        });

        Event::dispatch(new BedStatusChanged($bed, $from, $to, $actor, $reason));

        return $bed;
    }

    /**
     * Concurrency-safe bed claim (free -> occupied). Locks the bed row FOR UPDATE,
     * asserts it is still free under the lock, then flips it to occupied — so N
     * racing claims yield exactly ONE winner (the BookingService lock idiom applied
     * to a Bed). The patient/stay is attached in HOSPITAL.G2; this is the primitive.
     */
    public function claim(User $actor, Bed $bed): Bed
    {
        Gate::forUser($actor)->authorize('admission.manage');

        $bed = DB::transaction(function () use ($bed): Bed {
            $current = $this->lockBedStatus($bed->tenant_id, $bed->id);

            if ($current !== Bed::STATUS_FREE) {
                throw BedNotAvailableException::notFree($bed->id, $current);
            }

            $bed->status = Bed::STATUS_OCCUPIED;
            $bed->save();

            return $bed;
        });

        Event::dispatch(new BedStatusChanged($bed, Bed::STATUS_FREE, Bed::STATUS_OCCUPIED, $actor));

        return $bed;
    }

    /**
     * Lock a single bed row FOR UPDATE and return its authoritative current status.
     * The tenant-scoped WHERE means a cross-tenant id is invisible and rejected —
     * the same shape as BookingService::lockResource().
     */
    private function lockBedStatus(string $tenantId, string $bedId): string
    {
        $rows = DB::select('select status from beds where tenant_id = ? and id = ? for update', [$tenantId, $bedId]);

        if ($rows === []) {
            throw CrossTenantReferenceException::forAttribute('bed_id', $bedId);
        }

        return $rows[0]->status;
    }
}
