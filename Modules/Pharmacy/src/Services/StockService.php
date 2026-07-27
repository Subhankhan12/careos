<?php

namespace Modules\Pharmacy\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Pharmacy\Exceptions\DispensingException;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Pharmacy\Models\MedicationStock;
use Modules\Pharmacy\Models\StockMovement;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Pharmacy inventory (PHARMACY.G4) — receive stock + correct it (a stock-take adjust), each as an
 * APPEND-ONLY {@see StockMovement} that keeps `medication_stocks.on_hand` consistent. Gated
 * `dispense.manage`; tenant fail-closed. Every on-hand mutation happens UNDER A FOR UPDATE ROW LOCK inside a
 * transaction (the BedService idiom) so it is concurrency-safe and never goes negative. Operational only —
 * no clinical judgment; a reorder threshold is a plain number.
 */
class StockService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Receive stock for a formulary item (+quantity). Creates the stock row on first receipt.
     */
    public function receive(User $actor, FormularyItem $item, int $quantity, ?string $unit = null, ?int $reorderThreshold = null, ?string $reason = null): MedicationStock
    {
        Gate::forUser($actor)->authorize('dispense.manage');
        $this->assertSameTenant($item, 'formulary_item_id', $item->id);

        if ($quantity <= 0) {
            throw DispensingException::nonPositiveQuantity();
        }

        $stock = MedicationStock::query()->firstOrCreate(
            ['formulary_item_id' => $item->id],
            ['on_hand' => 0, 'unit' => $unit ?? 'unit', 'reorder_threshold' => $reorderThreshold],
        );

        DB::transaction(function () use ($stock, $quantity, $reason, $actor): void {
            $onHand = MedicationStock::lockOnHand($stock->tenant_id, $stock->id);
            $this->applyMovement($stock, StockMovement::TYPE_RECEIVED, $quantity, $onHand + $quantity, $reason, $actor);
        });

        return $stock->refresh();
    }

    /**
     * Correct on-hand to an absolute count (a stock-take). Requires a reason; cannot go negative.
     */
    public function adjust(User $actor, MedicationStock $stock, int $newOnHand, string $reason): MedicationStock
    {
        Gate::forUser($actor)->authorize('dispense.manage');
        $this->assertSameTenant($stock, 'medication_stock_id', $stock->id);

        if ($newOnHand < 0) {
            throw DispensingException::negativeOnHand();
        }

        DB::transaction(function () use ($stock, $newOnHand, $reason, $actor): void {
            $onHand = MedicationStock::lockOnHand($stock->tenant_id, $stock->id);
            $this->applyMovement($stock, StockMovement::TYPE_ADJUSTED, $newOnHand - $onHand, $newOnHand, $reason, $actor);
        });

        return $stock->refresh();
    }

    /**
     * @return Collection<int, MedicationStock>
     */
    public function forTenant(): Collection
    {
        return MedicationStock::query()->with('formularyItem')->get()
            ->sortBy(fn (MedicationStock $s): string => (string) $s->formularyItem?->name)->values();
    }

    /**
     * @return Collection<int, StockMovement>
     */
    public function recentMovements(int $limit = 50): Collection
    {
        return StockMovement::query()->with('stock.formularyItem')->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Write the on-hand mutation + append the immutable ledger row. Called only from inside a locked
     * transaction (the caller holds the FOR UPDATE lock on the stock row).
     */
    private function applyMovement(MedicationStock $stock, string $type, int $quantityChange, int $resultingOnHand, ?string $reason, User $actor, ?string $dispenseId = null): void
    {
        $stock->forceFill(['on_hand' => $resultingOnHand])->save();

        StockMovement::query()->create([
            'medication_stock_id' => $stock->id,
            'type' => $type,
            'quantity_change' => $quantityChange,
            'resulting_on_hand' => $resultingOnHand,
            'reason' => $reason,
            'dispense_id' => $dispenseId,
            'performed_by' => $actor->id,
            'occurred_at' => Carbon::now(),
        ]);
    }

    private function assertSameTenant(object $model, string $attribute, string $id): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
