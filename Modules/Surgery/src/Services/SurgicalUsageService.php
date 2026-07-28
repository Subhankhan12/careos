<?php

namespace Modules\Surgery\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Surgery\Exceptions\SurgicalInventoryException;
use Modules\Surgery\Models\CaseItemUsage;
use Modules\Surgery\Models\ImplantPlacement;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\SurgicalItem;
use Modules\Surgery\Models\SurgicalItemStock;
use Modules\Surgery\Models\SurgicalStockMovement;

/**
 * Surgical consumable/implant USAGE (SURGERY.G4) — the surgical team records an item used/placed in a case,
 * decrementing stock. Gated `note.write`; tenant fail-closed. The usage record + the stock decrement + the
 * append-only {@see SurgicalStockMovement} happen ATOMICALLY in one transaction; the stock row is taken UNDER
 * A FOR UPDATE LOCK ({@see SurgicalItemStock::lockOnHand}) and asserted sufficient, so N racing uses of the
 * last unit yield exactly ONE winner and on-hand never goes negative (the `DispensingService`/`BedService`
 * idiom). Placing an IMPLANT additionally records its lot/serial/UDI ({@see ImplantPlacement}) for recall
 * traceability.
 *
 * ELECTRIC FENCE: factual operations only — no clinical/device-safety judgment. Implant traceability is
 * record-keeping (which implant → which patient); it does not verify or grade the device.
 */
class SurgicalUsageService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /** Record a consumable used in a case — decrements stock ATOMICALLY + concurrency-safely. */
    public function recordUsage(User $actor, SurgicalCase $case, SurgicalItem $item, int $quantity): CaseItemUsage
    {
        Gate::forUser($actor)->authorize('note.write');
        $this->assertSameTenant($case->tenant_id, 'surgical_case_id', $case->id);
        $this->assertSameTenant($item->tenant_id, 'surgical_item_id', $item->id);

        if ($quantity <= 0) {
            throw SurgicalInventoryException::nonPositiveQuantity();
        }

        $stock = $this->resolveStock($item);

        return DB::transaction(fn (): CaseItemUsage => $this->consume($case, $item, $stock, $quantity, $actor));
    }

    /**
     * Place an implant in a case — decrements stock (1 unit) ATOMICALLY AND records the lot/serial/UDI for
     * recall traceability. The item must be flagged an implant; a lot number is required.
     */
    public function placeImplant(
        User $actor,
        SurgicalCase $case,
        SurgicalItem $item,
        string $lotNumber,
        ?string $serialNumber = null,
        ?string $udi = null,
        ?string $note = null,
    ): ImplantPlacement {
        Gate::forUser($actor)->authorize('note.write');
        $this->assertSameTenant($case->tenant_id, 'surgical_case_id', $case->id);
        $this->assertSameTenant($item->tenant_id, 'surgical_item_id', $item->id);

        if (! $item->is_implant) {
            throw SurgicalInventoryException::notAnImplant($item->id);
        }
        if (trim($lotNumber) === '') {
            throw SurgicalInventoryException::lotRequired();
        }

        $stock = $this->resolveStock($item);

        return DB::transaction(function () use ($case, $item, $stock, $actor, $lotNumber, $serialNumber, $udi, $note): ImplantPlacement {
            $usage = $this->consume($case, $item, $stock, 1, $actor);

            return ImplantPlacement::query()->create([
                'surgical_case_id' => $case->id,
                'patient_id' => $case->patient_id,
                'surgical_item_id' => $item->id,
                'case_item_usage_id' => $usage->id,
                'lot_number' => $lotNumber,
                'serial_number' => $serialNumber,
                'udi' => $udi,
                'placed_by' => $actor->id,
                'placed_at' => Carbon::now(),
                'note' => $note,
            ]);
        });
    }

    /**
     * THE RECALL LOOKUP — a FACTUAL traceability query: which patients received an implant matching this lot /
     * UDI / serial. Never a device-safety verdict; it returns the placement records.
     *
     * @return Collection<int, ImplantPlacement>
     */
    public function patientsForLot(string $query): Collection
    {
        return ImplantPlacement::query()->with(['patient', 'surgicalItem'])
            ->where(function ($q) use ($query): void {
                $q->where('lot_number', $query)->orWhere('udi', $query)->orWhere('serial_number', $query);
            })
            ->orderByDesc('placed_at')->orderByDesc('id')->get();
    }

    /**
     * @return Collection<int, ImplantPlacement>
     */
    public function implantsForPatient(Patient $patient): Collection
    {
        return ImplantPlacement::query()->with('surgicalItem')->where('patient_id', $patient->id)
            ->orderByDesc('placed_at')->orderByDesc('id')->get();
    }

    /**
     * @return Collection<int, CaseItemUsage>
     */
    public function usagesForCase(SurgicalCase $case): Collection
    {
        return CaseItemUsage::query()->with('surgicalItem')->where('surgical_case_id', $case->id)
            ->orderByDesc('used_at')->orderByDesc('id')->get();
    }

    /**
     * @return Collection<int, ImplantPlacement>
     */
    public function implantsForCase(SurgicalCase $case): Collection
    {
        return ImplantPlacement::query()->with('surgicalItem')->where('surgical_case_id', $case->id)
            ->orderByDesc('placed_at')->orderByDesc('id')->get();
    }

    private function resolveStock(SurgicalItem $item): SurgicalItemStock
    {
        $stock = SurgicalItemStock::query()->where('surgical_item_id', $item->id)->first();
        if ($stock === null) {
            throw SurgicalInventoryException::noStock($item->id);
        }

        return $stock;
    }

    /**
     * The ATOMIC, concurrency-safe decrement (called inside a transaction): lock the stock FOR UPDATE →
     * assert sufficient → create the usage → decrement → append the 'used' movement. No oversell, no negative.
     */
    private function consume(SurgicalCase $case, SurgicalItem $item, SurgicalItemStock $stock, int $quantity, User $actor): CaseItemUsage
    {
        $onHand = SurgicalItemStock::lockOnHand($stock->tenant_id, $stock->id);
        if ($onHand < $quantity) {
            throw SurgicalInventoryException::insufficientStock($item->id, $quantity, $onHand);
        }

        $usage = CaseItemUsage::query()->create([
            'surgical_case_id' => $case->id,
            'patient_id' => $case->patient_id,
            'surgical_item_id' => $item->id,
            'quantity' => $quantity,
            'used_by' => $actor->id,
            'used_at' => Carbon::now(),
        ]);

        $resulting = $onHand - $quantity;
        $stock->forceFill(['on_hand' => $resulting])->save();

        SurgicalStockMovement::query()->create([
            'surgical_item_stock_id' => $stock->id,
            'type' => SurgicalStockMovement::TYPE_USED,
            'quantity_change' => -$quantity,
            'resulting_on_hand' => $resulting,
            'reason' => null,
            'case_item_usage_id' => $usage->id,
            'performed_by' => $actor->id,
            'occurred_at' => Carbon::now(),
        ]);

        return $usage;
    }

    private function assertSameTenant(?string $tenantId, string $attribute, string $id): void
    {
        if ($tenantId !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
