<?php

namespace Modules\Dental\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\DentalProcedure;
use Modules\Dental\Models\PerformedProcedure;
use Modules\Dental\Models\TreatmentPlan;
use Modules\Dental\Models\TreatmentPlanItem;
use Modules\Dental\Models\TreatmentPlanPhase;
use Modules\Dental\Services\DentalCatalogService;
use Modules\Dental\Services\PerformProcedureService;
use Modules\Dental\Services\TreatmentPlanService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;

/**
 * The dental treatment-plan editor (DENTAL.G5) — PRESENTATIONAL over TreatmentPlanService
 * (P0D.GU). It builds/presents a DENTIST-AUTHORED plan (phases + planned procedures with a
 * fee-schedule estimate), drives the legal lifecycle, and performs a planned item (which
 * charges through G4). All estimate/lifecycle/charge logic lives in the services; this
 * controller validates shape and dispatches — NO pricing/charge math here.
 *
 * ESTIMATE vs CHARGE: the plan ESTIMATES (proposing/accepting posts no charge); a charge is
 * created only when a procedure is PERFORMED (G4). Gated: reading `patient.view`, managing
 * `dental.chart`, performing (dental.chart + billing.manage via the service). String-id (FIX.1).
 */
class TreatmentPlanController
{
    public function index(Request $request, string $patient, TreatmentPlanService $plans, DentalCatalogService $catalog, SettingsService $settings): Response
    {
        Gate::authorize('patient.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $canManage = Gate::allows('dental.chart');
        $canPerform = $canManage && Gate::allows('billing.manage');

        $planModels = $plans->plansFor($actor, $record); // read-logged inside the service

        // A plan item is "done" when a performed procedure references it (derived, no stored flag).
        $itemIds = $planModels->flatMap(fn (TreatmentPlan $plan): array => $plan->items->pluck('id')->all())->all();
        $doneItemIds = PerformedProcedure::query()->whereIn('treatment_plan_item_id', $itemIds)->pluck('treatment_plan_item_id')->all();

        return Inertia::render('Dental/TreatmentPlans', [
            'patient' => [
                'id' => $record->id,
                'mrn' => $record->mrn,
                'name' => trim($record->first_name.' '.$record->last_name),
            ],
            'plans' => $planModels->map(fn (TreatmentPlan $plan): array => $this->presentPlan($plan, $plans, $doneItemIds))->all(),
            'procedures' => $canManage
                ? $catalog->list()->map(fn (DentalProcedure $p): array => [
                    'id' => $p->id,
                    'code' => $p->tariffItem?->code,
                    'name' => $p->tariffItem?->description,
                    'tooth_scoped' => $p->tooth_scoped,
                ])->all()
                : [],
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name'])->map(fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name])->all(),
            'currency' => (string) $settings->get('currency', 'EUR'),
            'actions' => [
                'can_manage' => $canManage,
                'can_perform' => $canPerform,
                'store_url' => route('dental.plans.store', $record->id),
            ],
        ]);
    }

    public function store(Request $request, string $patient, TreatmentPlanService $plans): RedirectResponse
    {
        Gate::authorize('dental.chart');
        $actor = $this->actor($request);
        $data = $request->validate(['title' => ['nullable', 'string', 'max:120']]);
        $record = Patient::query()->whereKey($patient)->firstOrFail();

        $plans->create($actor, $record, $data['title'] ?? null);

        return redirect()->route('dental.plans', $record->id)->with('status', 'created');
    }

    public function addPhase(Request $request, string $plan, TreatmentPlanService $plans): RedirectResponse
    {
        Gate::authorize('dental.chart');
        $actor = $this->actor($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $planModel = TreatmentPlan::query()->whereKey($plan)->firstOrFail();

        try {
            $plans->addPhase($actor, $planModel, $data['name']);
        } catch (DentalException $e) {
            return back()->withErrors(['phase' => $e->getMessage()]);
        }

        return redirect()->route('dental.plans', $planModel->patient_id)->with('status', 'updated');
    }

    public function addItem(Request $request, string $plan, TreatmentPlanService $plans): RedirectResponse
    {
        Gate::authorize('dental.chart');
        $actor = $this->actor($request);
        $data = $request->validate([
            'treatment_plan_phase_id' => ['required', 'string'],
            'dental_procedure_id' => ['required', 'string'],
            'tooth' => ['nullable', 'string', 'max:2'],
            'surface' => ['nullable', 'string', 'max:20'],
        ]);
        $planModel = TreatmentPlan::query()->whereKey($plan)->firstOrFail();
        $phase = TreatmentPlanPhase::query()->whereKey($data['treatment_plan_phase_id'])->firstOrFail();
        $procedure = DentalProcedure::query()->whereKey($data['dental_procedure_id'])->firstOrFail();

        $tooth = ($data['tooth'] ?? '') === '' ? null : $data['tooth'];
        $surface = ($data['surface'] ?? '') === '' ? null : $data['surface'];

        try {
            $plans->addItem($actor, $planModel, $phase, $procedure, $tooth, $surface);
        } catch (DentalException $e) {
            return back()->withErrors(['item' => $e->getMessage()]);
        }

        return redirect()->route('dental.plans', $planModel->patient_id)->with('status', 'updated');
    }

    public function transition(Request $request, string $plan, TreatmentPlanService $plans): RedirectResponse
    {
        Gate::authorize('dental.chart');
        $actor = $this->actor($request);
        $data = $request->validate(['action' => ['required', 'string', 'in:propose,accept,decline,start,complete']]);
        $planModel = TreatmentPlan::query()->whereKey($plan)->firstOrFail();

        try {
            match ($data['action']) {
                'propose' => $plans->propose($actor, $planModel),
                'accept' => $plans->accept($actor, $planModel),
                'decline' => $plans->decline($actor, $planModel),
                'start' => $plans->start($actor, $planModel),
                'complete' => $plans->complete($actor, $planModel),
                default => throw new DentalException('Unknown treatment-plan action.'),
            };
        } catch (DentalException $e) {
            return back()->withErrors(['transition' => $e->getMessage()]);
        }

        return redirect()->route('dental.plans', $planModel->patient_id)->with('status', 'updated');
    }

    /**
     * Perform a planned item — records the clinical fact + captures the charge (G4) and links the
     * performed procedure to the plan item so the plan tracks completion. All logic lives in
     * PerformProcedureService; billing.manage is enforced there (a failure rolls the whole thing back).
     */
    public function performItem(Request $request, string $item, PerformProcedureService $performer): RedirectResponse
    {
        Gate::authorize('dental.chart');
        $actor = $this->actor($request);
        $data = $request->validate([
            'branch_id' => ['required', 'string'],
            'tooth_state' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $planItem = TreatmentPlanItem::query()->with('treatmentPlan')->whereKey($item)->firstOrFail();
        $patient = Patient::query()->whereKey($planItem->treatmentPlan?->patient_id)->firstOrFail();
        $procedure = DentalProcedure::query()->whereKey($planItem->dental_procedure_id)->firstOrFail();
        $branch = Branch::query()->whereKey($data['branch_id'])->firstOrFail();
        $toothState = ($data['tooth_state'] ?? '') === '' ? null : $data['tooth_state'];

        try {
            $performer->perform($actor, $patient, $branch, $procedure, $planItem->tooth, $planItem->surface, $data['note'] ?? null, $toothState, 1, $planItem);
        } catch (DentalException $e) {
            return back()->withErrors(['perform' => $e->getMessage()]);
        }

        return redirect()->route('dental.plans', $patient->id)->with('status', 'performed');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    /**
     * @param  list<string>  $doneItemIds
     * @return array<string, mixed>
     */
    private function presentPlan(TreatmentPlan $plan, TreatmentPlanService $plans, array $doneItemIds): array
    {
        $phases = $plan->phases->sortBy('sequence')->map(function (TreatmentPlanPhase $phase) use ($plan, $plans, $doneItemIds): array {
            $items = $plan->items->where('treatment_plan_phase_id', $phase->id)->sortBy('sequence')->values();

            return [
                'id' => $phase->id,
                'name' => $phase->name,
                // Phase total = SUM of item estimates (the only arithmetic; no VAT/discount math).
                'total_minor' => $items->sum(fn (TreatmentPlanItem $i): int => $plans->itemEstimate($i)),
                'items' => $items->map(fn (TreatmentPlanItem $i): array => [
                    'id' => $i->id,
                    'code' => $i->dentalProcedure?->tariffItem?->code,
                    'name' => $i->dentalProcedure?->tariffItem?->description,
                    'tooth' => $i->tooth,
                    'surface' => $i->surface,
                    'estimate_minor' => $plans->itemEstimate($i),
                    'done' => in_array($i->id, $doneItemIds, true),
                    'perform_url' => route('dental.plan-items.perform', $i->id),
                ])->all(),
            ];
        })->values()->all();

        return [
            'id' => $plan->id,
            'title' => $plan->title,
            'status' => $plan->status,
            'accepted_at' => $plan->accepted_at?->toDateString(),
            'total_minor' => $plan->items->sum(fn (TreatmentPlanItem $i): int => $plans->itemEstimate($i)),
            'phases' => $phases,
            'phase_url' => route('dental.plans.phases', $plan->id),
            'item_url' => route('dental.plans.items', $plan->id),
            'transition_url' => route('dental.plans.transition', $plan->id),
        ];
    }
}
