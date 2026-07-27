<?php

namespace Modules\Pharmacy\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Patients\Models\Patient;
use Modules\Pharmacy\Exceptions\DispensingException;
use Modules\Pharmacy\Models\Dispense;
use Modules\Pharmacy\Models\MedicationOrder;
use Modules\Pharmacy\Models\MedicationStock;
use Modules\Pharmacy\Models\StockMovement;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Dispensing (PHARMACY.G4) — a pharmacist dispenses a quantity against a G2 medication order, decrementing
 * stock. Gated `dispense.manage`; tenant + patient fail-closed. The dispense record + the stock decrement +
 * the append-only {@see StockMovement} happen ATOMICALLY in one transaction; the stock row is taken UNDER A
 * FOR UPDATE LOCK ({@see MedicationStock::lockOnHand}) and asserted sufficient, so N racing dispenses of the
 * last unit yield exactly ONE winner and on-hand never goes negative (the BedService::claim idiom).
 *
 * Factual state checks only: an order must be active (not discontinued), and there must be enough stock —
 * NO clinical/safety judgment is computed here (medication safety is the orders/administration seam).
 */
class DispensingService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function dispense(User $actor, MedicationOrder $order, int $quantity): Dispense
    {
        Gate::forUser($actor)->authorize('dispense.manage');
        $this->assertSameTenant($order);

        if ($quantity <= 0) {
            throw DispensingException::nonPositiveQuantity();
        }

        // Factual state check — you cannot dispense against a discontinued/completed/held order.
        if (! $order->isActive()) {
            throw DispensingException::orderNotActive($order->id, $order->status);
        }

        $stock = MedicationStock::query()->where('formulary_item_id', $order->formulary_item_id)->first();
        if ($stock === null) {
            throw DispensingException::noStock($order->formulary_item_id);
        }

        return DB::transaction(function () use ($actor, $order, $quantity, $stock): Dispense {
            // Lock the stock row FOR UPDATE and read the authoritative on-hand; assert sufficient under the lock.
            $onHand = MedicationStock::lockOnHand($stock->tenant_id, $stock->id);
            if ($onHand < $quantity) {
                throw DispensingException::insufficientStock($order->formulary_item_id, $quantity, $onHand);
            }

            $dispense = Dispense::query()->create([
                'patient_id' => $order->patient_id,
                'medication_order_id' => $order->id,
                'formulary_item_id' => $order->formulary_item_id,
                'quantity' => $quantity,
                'dispensed_by' => $actor->id,
                'dispensed_at' => Carbon::now(),
                'stay_id' => $order->stay_id,
            ]);

            $resulting = $onHand - $quantity;
            $stock->forceFill(['on_hand' => $resulting])->save();

            StockMovement::query()->create([
                'medication_stock_id' => $stock->id,
                'type' => StockMovement::TYPE_DISPENSED,
                'quantity_change' => -$quantity,
                'resulting_on_hand' => $resulting,
                'reason' => null,
                'dispense_id' => $dispense->id,
                'performed_by' => $actor->id,
                'occurred_at' => Carbon::now(),
            ]);

            return $dispense;
        });
    }

    /**
     * @return Collection<int, Dispense>
     */
    public function historyForPatient(Patient $patient): Collection
    {
        return Dispense::query()
            ->where('patient_id', $patient->id)
            ->with('formularyItem')
            ->orderByDesc('dispensed_at')
            ->orderByDesc('id')
            ->get();
    }

    public function onHandForItem(string $formularyItemId): ?int
    {
        $stock = MedicationStock::query()->where('formulary_item_id', $formularyItemId)->first();

        return $stock?->on_hand;
    }

    private function assertSameTenant(MedicationOrder $order): void
    {
        if ($order->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('medication_order_id', (string) $order->id);
        }
    }
}
