<?php

namespace Modules\Dental\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\DentalProcedure;
use Modules\Dental\Services\DentalCatalogService;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;

/**
 * The dental fee-schedule editor (DENTAL.G3) — manage the tenant's dental procedure catalog
 * (add / edit / deactivate procedures + their fees) and seed the generic starter template.
 * PRESENTATIONAL (P0D.GU): all catalog + billing wiring lives in {@see DentalCatalogService};
 * this controller validates shape and dispatches. NO pricing/charge/VAT math here (the fee
 * is a value the dentist enters; the billing engine owns all money math). Gated on
 * `billing.manage` (the "manage billing tariffs and billable items" permission), tenant-scoped;
 * string-id {id} (FIX.1).
 */
class FeeScheduleController
{
    public function index(Request $request, DentalCatalogService $catalog, SettingsService $settings): Response
    {
        Gate::authorize('billing.manage');
        abort_unless($request->user() instanceof User, 403);

        return Inertia::render('Dental/FeeSchedule', [
            'procedures' => $catalog->list()->map(fn (DentalProcedure $procedure): array => $this->present($procedure))->all(),
            'currency' => (string) $settings->get('currency', 'EUR'),
            'actions' => [
                'store_url' => route('dental.fee-schedule.store'),
                'seed_url' => route('dental.fee-schedule.seed'),
            ],
        ]);
    }

    public function store(Request $request, DentalCatalogService $catalog): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $this->validated($request, withCode: true);

        try {
            $catalog->create($actor, $data['code'], $data['name'], $data['fee_minor'], $data['vat_rate_bp'], $data['tooth_scoped']);
        } catch (DentalException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()->route('dental.fee-schedule')->with('status', 'created');
    }

    public function update(Request $request, string $id, DentalCatalogService $catalog): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $procedure = DentalProcedure::query()->whereKey($id)->firstOrFail();
        $data = $this->validated($request, withCode: false);

        try {
            $catalog->update($actor, $procedure, $data['name'], $data['fee_minor'], $data['vat_rate_bp'], $data['tooth_scoped'], (bool) ($request->boolean('active')));
        } catch (DentalException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('dental.fee-schedule')->with('status', 'updated');
    }

    public function seed(Request $request, DentalCatalogService $catalog): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $catalog->seedStarter($actor);

        return redirect()->route('dental.fee-schedule')->with('status', 'seeded');
    }

    /**
     * @return array{code: string, name: string, fee_minor: int, vat_rate_bp: int, tooth_scoped: bool}
     */
    private function validated(Request $request, bool $withCode): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'fee_minor' => ['required', 'integer', 'min:0'],
            'vat_rate_bp' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'tooth_scoped' => ['boolean'],
        ];
        if ($withCode) {
            $rules['code'] = ['required', 'string', 'max:20'];
        }

        $data = $request->validate($rules);

        return [
            'code' => (string) ($data['code'] ?? ''),
            'name' => (string) $data['name'],
            'fee_minor' => (int) $data['fee_minor'],
            'vat_rate_bp' => (int) ($data['vat_rate_bp'] ?? 0),
            'tooth_scoped' => (bool) ($data['tooth_scoped'] ?? false),
        ];
    }

    /**
     * A catalog row — administrative/financial facts only (code, name, fee, VAT, flags).
     * No clinical judgment (no severity/recommendation).
     *
     * @return array<string, mixed>
     */
    private function present(DentalProcedure $procedure): array
    {
        $item = $procedure->tariffItem;

        return [
            'id' => $procedure->id,
            'code' => $item?->code,
            'name' => $item?->description,
            'fee_minor' => $item?->unit_price_minor,
            'vat_rate_bp' => $item?->vat_rate_bp,
            'active' => $item?->active,
            'tooth_scoped' => $procedure->tooth_scoped,
            'update_url' => route('dental.fee-schedule.update', $procedure->id),
        ];
    }
}
