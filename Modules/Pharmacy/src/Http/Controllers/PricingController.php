<?php

namespace Modules\Pharmacy\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Pharmacy\Exceptions\PharmacyBillingException;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Pharmacy\Services\PharmacyBillingService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * Pharmacy pricing (PHARMACY.G5) — PRESENTATIONAL over `PharmacyBillingService::priceItem`. Set a formulary
 * item's price as a tenant-authored `TariffItem` (integer minor units) in the existing tariff store — the
 * "set med prices like a tariff item" admin. Gated `billing.manage`. String-id (FIX.1). NO licensed pricing;
 * NO money math (the engine owns pricing).
 */
class PricingController
{
    public function index(Request $request): Response
    {
        Gate::authorize('billing.manage');
        abort_unless($request->user() instanceof User, 403);

        return Inertia::render('Pharmacy/Pricing', [
            'items' => FormularyItem::query()->where('active', true)->with('tariffItem')->orderBy('name')->get()
                ->map(fn (FormularyItem $item): array => [
                    'id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'strength' => $item->strength,
                    // The tenant's price (integer minor units) from the linked tariff item; null until priced.
                    'price_minor' => $item->tariffItem?->unit_price_minor,
                    'unit' => $item->tariffItem?->unit,
                    'set_url' => route('pharmacy.pricing.set', $item->id),
                ])->all(),
        ]);
    }

    public function set(Request $request, string $item, PharmacyBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'price_minor' => ['required', 'integer', 'min:1'],
            'unit' => ['nullable', 'string', 'max:40'],
        ]);

        $record = FormularyItem::query()->whereKey($item)->firstOrFail();

        try {
            $billing->priceItem($actor, $record, (int) $data['price_minor'], $data['unit'] ?? null);
        } catch (PharmacyBillingException|CrossTenantReferenceException $e) {
            return back()->withErrors(['pricing' => $e->getMessage()]);
        }

        return redirect()->route('pharmacy.pricing')->with('status', 'medication-priced');
    }
}
