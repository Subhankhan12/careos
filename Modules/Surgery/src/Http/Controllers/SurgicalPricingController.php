<?php

namespace Modules\Surgery\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Models\TariffItem;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Surgery\Exceptions\SurgicalBillingException;
use Modules\Surgery\Models\SurgicalItem;
use Modules\Surgery\Services\SurgicalBillingService;

/**
 * Surgical pricing (SURGERY.G5) — PRESENTATIONAL over `SurgicalBillingService`. Set tenant-authored prices
 * (integer minor units) in the existing tariff store for surgical items (consumables/implants), theatre time,
 * and procedures — the "set surgical prices like a tariff item" admin. Gated `billing.manage`. String-id
 * (FIX.1). NO licensed pricing; NO money math (the engine owns pricing). A price is a RATE, not a verdict.
 */
class SurgicalPricingController
{
    public function index(Request $request, SurgicalBillingService $billing): Response
    {
        Gate::authorize('billing.manage');
        abort_unless($request->user() instanceof User, 403);

        $tariffs = $billing->catalogTariffs();
        $theatreTime = $tariffs->firstWhere('code', SurgicalBillingService::THEATRE_TIME_CODE);

        return Inertia::render('Surgery/SurgicalPricing', [
            'items' => SurgicalItem::query()->where('active', true)->with('tariffItem')->orderBy('name')->get()
                ->map(fn (SurgicalItem $item): array => [
                    'id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'is_implant' => $item->is_implant,
                    'price_minor' => $item->tariffItem?->unit_price_minor,
                    'set_url' => route('surgery.pricing.item', $item->id),
                ])->all(),
            // Procedures = the surgery catalog's tariffs authored as procedures (unit 'procedure').
            'procedures' => $tariffs->where('unit', 'procedure')->map(fn (TariffItem $t): array => [
                'code' => $t->code,
                'name' => $t->description,
                'price_minor' => $t->unit_price_minor,
            ])->values()->all(),
            'theatre_time' => ['price_minor' => $theatreTime?->unit_price_minor, 'unit' => $theatreTime?->unit],
            'actions' => [
                'procedure_url' => route('surgery.pricing.procedure'),
                'theatre_time_url' => route('surgery.pricing.theatre-time'),
            ],
        ]);
    }

    public function setItem(Request $request, string $item, SurgicalBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate(['price_minor' => ['required', 'integer', 'min:1']]);
        $record = SurgicalItem::query()->whereKey($item)->firstOrFail();

        try {
            $billing->priceItem($actor, $record, (int) $data['price_minor']);
        } catch (SurgicalBillingException|CrossTenantReferenceException $e) {
            return back()->withErrors(['pricing' => $e->getMessage()]);
        }

        return redirect()->route('surgery.pricing')->with('status', 'surgical-item-priced');
    }

    public function setProcedure(Request $request, SurgicalBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:200'],
            'price_minor' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $billing->priceProcedure($actor, $data['code'], $data['name'], (int) $data['price_minor']);
        } catch (SurgicalBillingException $e) {
            return back()->withErrors(['pricing' => $e->getMessage()]);
        }

        return redirect()->route('surgery.pricing')->with('status', 'procedure-priced');
    }

    public function setTheatreTime(Request $request, SurgicalBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'price_minor' => ['required', 'integer', 'min:1'],
            'unit' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $billing->priceTheatreTime($actor, (int) $data['price_minor'], $data['unit'] ?? null);
        } catch (SurgicalBillingException $e) {
            return back()->withErrors(['pricing' => $e->getMessage()]);
        }

        return redirect()->route('surgery.pricing')->with('status', 'theatre-time-priced');
    }
}
