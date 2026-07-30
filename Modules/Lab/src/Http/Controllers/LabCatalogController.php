<?php

namespace Modules\Lab\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Lab\Exceptions\LabCatalogException;
use Modules\Lab\Models\LabTest;
use Modules\Lab\Services\LabCatalogService;
use Modules\Platform\Models\User;

/**
 * The lab test catalog (LAB.G1) — PRESENTATIONAL over {@see LabCatalogService}. The tenant authors its OWN
 * lab test menu (code/name/specimen + the DISPLAY unit + reference range) — NO licensed LOINC/test set is
 * bundled. A lab test is a Clinical `OrderableItem` (category=lab) + a thin overlay; the order/result REUSE
 * Clinical (later gates). Gated `lab.catalog`. String-id (FIX.1).
 *
 * ELECTRIC FENCE: the reference range is RECORDED reference data (displayed beside a result later) — this
 * screen records it; it computes NO abnormal/high/low/critical flag and grades nothing (the vitals-bands line).
 */
class LabCatalogController
{
    public function show(Request $request, LabCatalogService $catalog): Response
    {
        Gate::authorize('lab.catalog');
        abort_unless($request->user() instanceof User, 403);

        return Inertia::render('Lab/Catalog', [
            'tests' => $catalog->catalog()->map(fn (LabTest $t): array => [
                'id' => $t->id,
                'code' => $t->orderableItem?->code,
                'name' => $t->orderableItem?->name,
                'specimen' => $t->orderableItem?->specimen_or_modality,
                'unit' => $t->unit,                       // recorded reference data
                'reference_range' => $t->reference_range, // RECORDED reference data (displayed), NOT a computed grade
                'active' => $t->orderableItem !== null && $t->orderableItem->active,
                'deactivate_url' => route('lab.catalog.deactivate', $t->id),
            ])->all(),
            'actions' => [
                'can_manage' => Gate::allows('lab.catalog'),
                'store_url' => route('lab.catalog.store'),
                'seed_url' => route('lab.catalog.seed'),
            ],
        ]);
    }

    public function store(Request $request, LabCatalogService $catalog): RedirectResponse
    {
        Gate::authorize('lab.catalog');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:120'],
            'specimen_type' => ['nullable', 'string', 'max:60'],
            'unit' => ['nullable', 'string', 'max:40'],
            'reference_range' => ['nullable', 'string', 'max:120'], // free-text recorded reference data
        ]);

        try {
            $catalog->authorTest($actor, $data['code'], $data['name'], $data['specimen_type'] ?? null, $data['unit'] ?? null, $data['reference_range'] ?? null);
        } catch (LabCatalogException $e) {
            return back()->withErrors(['lab_catalog' => $e->getMessage()]);
        }

        return redirect()->route('lab.catalog')->with('status', 'lab-test-authored');
    }

    public function deactivate(Request $request, string $test, LabCatalogService $catalog): RedirectResponse
    {
        Gate::authorize('lab.catalog');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = LabTest::query()->whereKey($test)->firstOrFail();
        $catalog->deactivate($actor, $record);

        return redirect()->route('lab.catalog')->with('status', 'lab-test-deactivated');
    }

    public function seed(Request $request, LabCatalogService $catalog): RedirectResponse
    {
        Gate::authorize('lab.catalog');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $catalog->seedStarter($actor);

        return redirect()->route('lab.catalog')->with('status', 'lab-catalog-seeded');
    }
}
