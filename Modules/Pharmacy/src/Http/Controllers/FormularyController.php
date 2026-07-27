<?php

namespace Modules\Pharmacy\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Pharmacy\Exceptions\FormularyException;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Pharmacy\Services\FormularyService;
use Modules\Platform\Models\User;

/**
 * The tenant-authored formulary admin surface (PHARMACY.G1) — PRESENTATIONAL over `FormularyService`.
 * The tenant's OWN medication list (NO licensed drug data). String-id `{item}` (FIX.1); the whole surface
 * is gated `formulary.manage` (the pharmacist authors the formulary). No orders/eMAR/dispensing here.
 */
class FormularyController
{
    public function index(Request $request, FormularyService $formulary): Response
    {
        Gate::authorize('formulary.manage');
        abort_unless($request->user() instanceof User, 403);

        return Inertia::render('Pharmacy/Formulary', [
            'items' => $formulary->forTenant()->map(fn (FormularyItem $item): array => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'form' => $item->form,
                'strength' => $item->strength,
                'active' => $item->active,
                'deactivate_url' => route('pharmacy.formulary.deactivate', $item->id),
            ])->all(),
            'forms' => FormularyItem::FORMS,
            'actions' => [
                'store_url' => route('pharmacy.formulary.store'),
            ],
        ]);
    }

    public function store(Request $request, FormularyService $formulary): RedirectResponse
    {
        Gate::authorize('formulary.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:200'],
            'form' => ['nullable', 'string', 'in:'.implode(',', FormularyItem::FORMS)],
            'strength' => ['nullable', 'string', 'max:100'],
        ]);

        // Tenant-scoped uniqueness (the global BelongsToTenant scope confines the check to this tenant).
        if (FormularyItem::query()->where('code', $data['code'])->exists()) {
            return back()->withErrors(['code' => 'A formulary item with this code already exists.']);
        }

        try {
            $formulary->create($actor, $data);
        } catch (FormularyException $e) {
            return back()->withErrors(['formulary' => $e->getMessage()]);
        }

        return redirect()->route('pharmacy.formulary')->with('status', 'formulary-item-added');
    }

    public function deactivate(Request $request, string $item, FormularyService $formulary): RedirectResponse
    {
        Gate::authorize('formulary.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = FormularyItem::query()->whereKey($item)->firstOrFail();
        $formulary->deactivate($actor, $record);

        return redirect()->route('pharmacy.formulary')->with('status', 'formulary-item-deactivated');
    }
}
