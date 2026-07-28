<?php

namespace Modules\Surgery\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Surgery\Exceptions\SurgicalInventoryException;
use Modules\Surgery\Models\ImplantPlacement;
use Modules\Surgery\Models\SurgicalItem;
use Modules\Surgery\Models\SurgicalItemStock;
use Modules\Surgery\Models\SurgicalStockMovement;
use Modules\Surgery\Services\SurgicalStockService;
use Modules\Surgery\Services\SurgicalUsageService;

/**
 * Surgical inventory (SURGERY.G4) — PRESENTATIONAL over `SurgicalStockService` + `SurgicalUsageService`. The
 * surgical-item catalog + stock (on-hand, below-threshold factual) + receive/adjust + the recall lookup
 * (lot/UDI → the patients it was placed in — a FACTUAL query). Gated `surgery.manage`. String-id (FIX.1).
 * Operational only: below-stock is a factual count; the recall lookup returns records, never a device verdict.
 */
class SurgicalInventoryController
{
    public function index(Request $request, SurgicalStockService $stock, SurgicalUsageService $usage): Response
    {
        Gate::authorize('surgery.manage');
        abort_unless($request->user() instanceof User, 403);

        $lot = trim((string) $request->query('lot', ''));

        return Inertia::render('Surgery/Inventory', [
            'stock' => $stock->forTenant()->map(fn (SurgicalItemStock $s): array => [
                'id' => $s->id,
                'item' => $s->surgicalItem?->name,
                'code' => $s->surgicalItem?->code,
                'is_implant' => (bool) $s->surgicalItem?->is_implant,
                'on_hand' => $s->on_hand,
                'unit' => $s->unit,
                'below_threshold' => $s->isBelowThreshold(), // a FACTUAL comparison, never a graded alert
                'adjust_url' => route('surgery.inventory.adjust', $s->id),
            ])->all(),
            'items' => SurgicalItem::query()->where('active', true)->orderBy('name')->get()
                ->map(fn (SurgicalItem $i): array => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'is_implant' => $i->is_implant])->all(),
            'movements' => $stock->recentMovements(30)->map(fn (SurgicalStockMovement $m): array => [
                'id' => $m->id,
                'item' => $m->stock?->surgicalItem?->name,
                'type' => $m->type,
                'quantity_change' => $m->quantity_change,
                'resulting_on_hand' => $m->resulting_on_hand,
                'occurred_at' => $m->occurred_at->toIso8601String(),
            ])->all(),
            // The recall lookup — a FACTUAL traceability query (lot/UDI/serial -> the patients it was placed in).
            'recall' => [
                'query' => $lot,
                'results' => $lot === '' ? [] : $usage->patientsForLot($lot)->map(fn (ImplantPlacement $p): array => [
                    'patient' => trim($p->patient->first_name.' '.$p->patient->last_name),
                    'item' => $p->surgicalItem?->name,
                    'lot_number' => $p->lot_number,
                    'serial_number' => $p->serial_number,
                    'udi' => $p->udi,
                    'placed_at' => $p->placed_at->toIso8601String(),
                ])->all(),
            ],
            'actions' => [
                'can_manage' => Gate::allows('surgery.manage'),
                'item_url' => route('surgery.inventory.items'),
                'receive_url' => route('surgery.inventory.receive'),
                'recall_url' => route('surgery.inventory'),
            ],
        ]);
    }

    public function createItem(Request $request, SurgicalStockService $stock): RedirectResponse
    {
        Gate::authorize('surgery.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:200'],
            'is_implant' => ['boolean'],
            'unit' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $stock->createItem($actor, $data['code'], $data['name'], (bool) ($data['is_implant'] ?? false), $data['unit'] ?? 'unit');
        } catch (SurgicalInventoryException|CrossTenantReferenceException $e) {
            return back()->withErrors(['surgical_item' => $e->getMessage()]);
        }

        return redirect()->route('surgery.inventory')->with('status', 'surgical-item-created');
    }

    public function receive(Request $request, SurgicalStockService $stock): RedirectResponse
    {
        Gate::authorize('surgery.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'surgical_item_id' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reorder_threshold' => ['nullable', 'integer', 'min:0'],
        ]);

        $item = SurgicalItem::query()->whereKey($data['surgical_item_id'])->firstOrFail();

        try {
            $stock->receive($actor, $item, (int) $data['quantity'], null, $data['reorder_threshold'] ?? null);
        } catch (SurgicalInventoryException|CrossTenantReferenceException $e) {
            return back()->withErrors(['surgical_stock' => $e->getMessage()]);
        }

        return redirect()->route('surgery.inventory')->with('status', 'surgical-stock-received');
    }

    public function adjust(Request $request, string $stock, SurgicalStockService $stockService): RedirectResponse
    {
        Gate::authorize('surgery.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'on_hand' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $record = SurgicalItemStock::query()->whereKey($stock)->firstOrFail();

        try {
            $stockService->adjust($actor, $record, (int) $data['on_hand'], $data['reason']);
        } catch (SurgicalInventoryException|CrossTenantReferenceException $e) {
            return back()->withErrors(['surgical_stock' => $e->getMessage()]);
        }

        return redirect()->route('surgery.inventory')->with('status', 'surgical-stock-adjusted');
    }
}
