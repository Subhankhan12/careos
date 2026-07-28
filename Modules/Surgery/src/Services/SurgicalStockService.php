<?php

namespace Modules\Surgery\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Surgery\Exceptions\SurgicalInventoryException;
use Modules\Surgery\Models\SurgicalItem;
use Modules\Surgery\Models\SurgicalItemStock;
use Modules\Surgery\Models\SurgicalStockMovement;

/**
 * Surgical inventory admin (SURGERY.G4) — author the item catalog + receive/adjust stock, each as an
 * APPEND-ONLY {@see SurgicalStockMovement} that keeps `surgical_item_stocks.on_hand` consistent. MIRRORS the
 * pharmacy `StockService` recipe (Surgery cannot import the peer Pharmacy vertical). Gated `surgery.manage`;
 * tenant fail-closed. Every on-hand mutation happens UNDER A FOR UPDATE ROW LOCK inside a transaction (the
 * `BedService` idiom) so it is concurrency-safe and never goes negative. Operational only — a reorder
 * threshold is a plain number, no judgment.
 */
class SurgicalStockService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function createItem(User $actor, string $code, string $name, bool $isImplant = false, string $unit = 'unit'): SurgicalItem
    {
        Gate::forUser($actor)->authorize('surgery.manage');

        return SurgicalItem::query()->create([
            'code' => $code,
            'name' => $name,
            'is_implant' => $isImplant,
            'unit' => $unit,
        ]);
    }

    /** Receive stock for a surgical item (+quantity). Creates the stock row on first receipt. */
    public function receive(User $actor, SurgicalItem $item, int $quantity, ?string $unit = null, ?int $reorderThreshold = null, ?string $reason = null): SurgicalItemStock
    {
        Gate::forUser($actor)->authorize('surgery.manage');
        $this->assertSameTenant($item, 'surgical_item_id', $item->id);

        if ($quantity <= 0) {
            throw SurgicalInventoryException::nonPositiveQuantity();
        }

        $stock = SurgicalItemStock::query()->firstOrCreate(
            ['surgical_item_id' => $item->id],
            ['on_hand' => 0, 'unit' => $unit ?? $item->unit, 'reorder_threshold' => $reorderThreshold],
        );

        DB::transaction(function () use ($stock, $quantity, $reason, $actor): void {
            $onHand = SurgicalItemStock::lockOnHand($stock->tenant_id, $stock->id);
            $this->applyMovement($stock, SurgicalStockMovement::TYPE_RECEIVED, $quantity, $onHand + $quantity, $reason, $actor);
        });

        return $stock->refresh();
    }

    /** Correct on-hand to an absolute count (a stock-take). Requires a reason; cannot go negative. */
    public function adjust(User $actor, SurgicalItemStock $stock, int $newOnHand, string $reason): SurgicalItemStock
    {
        Gate::forUser($actor)->authorize('surgery.manage');
        $this->assertSameTenant($stock, 'surgical_item_stock_id', $stock->id);

        if ($newOnHand < 0) {
            throw SurgicalInventoryException::negativeOnHand();
        }

        DB::transaction(function () use ($stock, $newOnHand, $reason, $actor): void {
            $onHand = SurgicalItemStock::lockOnHand($stock->tenant_id, $stock->id);
            $this->applyMovement($stock, SurgicalStockMovement::TYPE_ADJUSTED, $newOnHand - $onHand, $newOnHand, $reason, $actor);
        });

        return $stock->refresh();
    }

    /**
     * @return Collection<int, SurgicalItemStock>
     */
    public function forTenant(): Collection
    {
        return SurgicalItemStock::query()->with('surgicalItem')->get()
            ->sortBy(fn (SurgicalItemStock $s): string => (string) $s->surgicalItem?->name)->values();
    }

    /**
     * @return Collection<int, SurgicalStockMovement>
     */
    public function recentMovements(int $limit = 50): Collection
    {
        return SurgicalStockMovement::query()->with('stock.surgicalItem')->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Write the on-hand mutation + append the immutable ledger row. Called only from inside a locked
     * transaction (the caller holds the FOR UPDATE lock on the stock row).
     */
    private function applyMovement(SurgicalItemStock $stock, string $type, int $quantityChange, int $resultingOnHand, ?string $reason, User $actor, ?string $caseItemUsageId = null): void
    {
        $stock->forceFill(['on_hand' => $resultingOnHand])->save();

        SurgicalStockMovement::query()->create([
            'surgical_item_stock_id' => $stock->id,
            'type' => $type,
            'quantity_change' => $quantityChange,
            'resulting_on_hand' => $resultingOnHand,
            'reason' => $reason,
            'case_item_usage_id' => $caseItemUsageId,
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
