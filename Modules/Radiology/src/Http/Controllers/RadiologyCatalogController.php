<?php

namespace Modules\Radiology\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Platform\Models\User;
use Modules\Radiology\Exceptions\RadiologyCatalogException;
use Modules\Radiology\Models\RadiologyExam;
use Modules\Radiology\Services\RadiologyCatalogService;

/**
 * The imaging exam catalog (RAD.G1) — PRESENTATIONAL over {@see RadiologyCatalogService}. The tenant authors
 * its OWN imaging exam menu (code/name/modality + body-part/contrast) — NO licensed CPT/RadLex set is bundled.
 * An imaging exam is a Clinical `OrderableItem` (category=imaging) + a thin overlay; the order/report/image
 * REUSE Clinical (later gates). Gated `radiology.catalog`. String-id (FIX.1).
 *
 * ELECTRIC FENCE: the catalog records modality/body-part reference data — this screen records it; it computes
 * NO image finding/CAD/abnormality flag ("AI radiology" is a hard non-goal).
 */
class RadiologyCatalogController
{
    public function show(Request $request, RadiologyCatalogService $catalog): Response
    {
        Gate::authorize('radiology.catalog');
        abort_unless($request->user() instanceof User, 403);

        return Inertia::render('Radiology/Catalog', [
            'exams' => $catalog->catalog()->map(fn (RadiologyExam $e): array => [
                'id' => $e->id,
                'code' => $e->orderableItem?->code,
                'name' => $e->orderableItem?->name,
                'modality' => $e->orderableItem?->specimen_or_modality, // the modality (a plain recorded type)
                'body_part' => $e->body_part,
                'contrast' => $e->contrast,
                'active' => $e->orderableItem !== null && $e->orderableItem->active,
                'deactivate_url' => route('radiology.catalog.deactivate', $e->id),
            ])->all(),
            'actions' => [
                'can_manage' => Gate::allows('radiology.catalog'),
                'store_url' => route('radiology.catalog.store'),
                'seed_url' => route('radiology.catalog.seed'),
            ],
        ]);
    }

    public function store(Request $request, RadiologyCatalogService $catalog): RedirectResponse
    {
        Gate::authorize('radiology.catalog');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:120'],
            'modality' => ['nullable', 'string', 'max:40'],
            'body_part' => ['nullable', 'string', 'max:60'],
            'contrast' => ['nullable', 'boolean'],
        ]);

        try {
            $catalog->authorExam($actor, $data['code'], $data['name'], $data['modality'] ?? null, $data['body_part'] ?? null, (bool) ($data['contrast'] ?? false));
        } catch (RadiologyCatalogException $e) {
            return back()->withErrors(['radiology_catalog' => $e->getMessage()]);
        }

        return redirect()->route('radiology.catalog')->with('status', 'radiology-exam-authored');
    }

    public function deactivate(Request $request, string $exam, RadiologyCatalogService $catalog): RedirectResponse
    {
        Gate::authorize('radiology.catalog');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = RadiologyExam::query()->whereKey($exam)->firstOrFail();
        $catalog->deactivate($actor, $record);

        return redirect()->route('radiology.catalog')->with('status', 'radiology-exam-deactivated');
    }

    public function seed(Request $request, RadiologyCatalogService $catalog): RedirectResponse
    {
        Gate::authorize('radiology.catalog');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $catalog->seedStarter($actor);

        return redirect()->route('radiology.catalog')->with('status', 'radiology-catalog-seeded');
    }
}
