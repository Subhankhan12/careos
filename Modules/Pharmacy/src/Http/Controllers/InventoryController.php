<?php

namespace Modules\Pharmacy\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Pharmacy\Exceptions\DispensingException;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Pharmacy\Models\MedicationStock;
use Modules\Pharmacy\Models\StockMovement;
use Modules\Pharmacy\Services\StockService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * Pharmacy inventory (PHARMACY.G4) — PRESENTATIONAL over `StockService`. The stock list (on-hand, unit,
 * below-threshold shown factually), receive stock, adjust (stock-take), and the append-only movement log.
 * Tenant-level operational data — gated `dispense.manage`. String-id (FIX.1). NO patient data here.
 */
class InventoryController
{
    public function index(Request $request, StockService $stock): Response
    {
        Gate::authorize('dispense.manage');
        abort_unless($request->user() instanceof User, 403);

        return Inertia::render('Pharmacy/Inventory', [
            'stock' => $stock->forTenant()->map(fn (MedicationStock $s): array => [
                'id' => $s->id,
                'name' => $s->formularyItem->name,
                'on_hand' => $s->on_hand,
                'unit' => $s->unit,
                'reorder_threshold' => $s->reorder_threshold,
                // A factual quantity comparison — NOT a graded alert.
                'below_threshold' => $s->isBelowThreshold(),
                'adjust_url' => route('pharmacy.inventory.adjust', $s->id),
            ])->all(),
            'movements' => $stock->recentMovements()->map(fn (StockMovement $m): array => [
                'id' => $m->id,
                'name' => $m->stock->formularyItem->name,
                'type' => $m->type,
                'quantity_change' => $m->quantity_change,
                'resulting_on_hand' => $m->resulting_on_hand,
                'reason' => $m->reason,
                'occurred_at' => $m->occurred_at->toIso8601String(),
            ])->all(),
            'formulary' => FormularyItem::query()->where('active', true)->orderBy('name')->get()
                ->map(fn (FormularyItem $item): array => ['id' => $item->id, 'name' => $item->name, 'strength' => $item->strength])->all(),
            'actions' => [
                'receive_url' => route('pharmacy.inventory.receive'),
            ],
        ]);
    }

    public function receive(Request $request, StockService $stock): RedirectResponse
    {
        Gate::authorize('dispense.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'formulary_item_id' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit' => ['nullable', 'string', 'max:40'],
            'reorder_threshold' => ['nullable', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $item = FormularyItem::query()->whereKey($data['formulary_item_id'])->firstOrFail();

        try {
            $stock->receive($actor, $item, (int) $data['quantity'], $data['unit'] ?? null, isset($data['reorder_threshold']) ? (int) $data['reorder_threshold'] : null, $data['reason'] ?? null);
        } catch (DispensingException|CrossTenantReferenceException $e) {
            return back()->withErrors(['inventory' => $e->getMessage()]);
        }

        return redirect()->route('pharmacy.inventory')->with('status', 'stock-received');
    }

    public function adjust(Request $request, string $stock, StockService $stockService): RedirectResponse
    {
        Gate::authorize('dispense.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'on_hand' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $record = MedicationStock::query()->whereKey($stock)->firstOrFail();

        try {
            $stockService->adjust($actor, $record, (int) $data['on_hand'], $data['reason']);
        } catch (DispensingException|CrossTenantReferenceException $e) {
            return back()->withErrors(['inventory' => $e->getMessage()]);
        }

        return redirect()->route('pharmacy.inventory')->with('status', 'stock-adjusted');
    }
}
