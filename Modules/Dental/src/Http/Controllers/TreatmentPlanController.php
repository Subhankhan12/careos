<?php

namespace Modules\Dental\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Models\Charge;
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
        $performed = PerformedProcedure::query()->whereIn('treatment_plan_item_id', $itemIds)->get();
        $doneItemIds = $performed->pluck('treatment_plan_item_id')->all();

        /*
         * BILLED-SO-FAR comes from REAL CHARGES, not from the plan (DENTAL-B.P4).
         *
         * Performing a planned item captures a charge through the billing engine (G4) and
         * stores its `charge_id`. So "billed" is the sum of those charges' engine-computed
         * line totals — actual money in the ledger, never the estimate re-labelled. A
         * cancelled charge is excluded because it was un-billed. Nothing is derived in the
         * page: the page receives the figure.
         */
        $chargeTotals = Charge::query()
            ->whereIn('id', $performed->pluck('charge_id')->filter()->all())
            ->where('status', '!=', Charge::STATUS_CANCELLED)
            ->pluck('line_total_minor', 'id');

        $billedByItem = [];
        foreach ($performed as $row) {
            $itemId = (string) $row->treatment_plan_item_id;
            $billedByItem[$itemId] = ($billedByItem[$itemId] ?? 0) + (int) ($chargeTotals[$row->charge_id] ?? 0);
        }

        $currency = (string) $settings->get('currency', 'EUR');

        return Inertia::render('Dental/TreatmentPlans', [
            'patient' => [
                'id' => $record->id,
                'mrn' => $record->mrn,
                'name' => trim($record->first_name.' '.$record->last_name),
            ],
            'plans' => $planModels->map(fn (TreatmentPlan $plan): array => $this->presentPlan($plan, $plans, $doneItemIds, $billedByItem, $currency))->all(),
            'procedures' => $canManage
                ? $catalog->list()->map(fn (DentalProcedure $p): array => [
                    'id' => $p->id,
                    'code' => $p->tariffItem?->code,
                    'name' => $p->tariffItem?->description,
                    'tooth_scoped' => $p->tooth_scoped,
                ])->all()
                : [],
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name'])->map(fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name])->all(),
            'currency' => $currency,
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
    /**
     * @param  array<int, string>  $doneItemIds
     * @param  array<string, int>  $billedByItem
     * @return array<string, mixed>
     */
    private function presentPlan(TreatmentPlan $plan, TreatmentPlanService $plans, array $doneItemIds, array $billedByItem, string $currency): array
    {
        $phases = $plan->phases->sortBy('sequence')->map(function (TreatmentPlanPhase $phase) use ($plan, $plans, $doneItemIds, $billedByItem, $currency): array {
            $items = $plan->items->where('treatment_plan_phase_id', $phase->id)->sortBy('sequence')->values();
            $phaseTotal = $items->sum(fn (TreatmentPlanItem $i): int => $plans->itemEstimate($i));

            return [
                'id' => $phase->id,
                'name' => $phase->name,
                // Phase total = SUM of item estimates (the only arithmetic; no VAT/discount math).
                'total_minor' => $phaseTotal,
                'total' => $this->money($phaseTotal, $currency),
                'items' => $items->map(function (TreatmentPlanItem $i) use ($plans, $doneItemIds, $billedByItem, $currency): array {
                    $estimate = $plans->itemEstimate($i);
                    $billed = $billedByItem[$i->id] ?? null;

                    return [
                        'id' => $i->id,
                        'code' => $i->dentalProcedure?->tariffItem?->code,
                        'name' => $i->dentalProcedure?->tariffItem?->description,
                        'tooth' => $i->tooth,
                        'surface' => $i->surface,
                        'estimate_minor' => $estimate,
                        'estimate' => $this->money($estimate, $currency),
                        // The REAL charge raised when this item was performed, if it was.
                        'billed_minor' => $billed,
                        'billed' => $billed === null ? null : $this->money($billed, $currency),
                        'done' => in_array($i->id, $doneItemIds, true),
                        'perform_url' => route('dental.plan-items.perform', $i->id),
                    ];
                })->all(),
            ];
        })->values()->all();

        $planTotal = $plan->items->sum(fn (TreatmentPlanItem $i): int => $plans->itemEstimate($i));
        $planBilled = $plan->items->sum(fn (TreatmentPlanItem $i): int => $billedByItem[$i->id] ?? 0);

        return [
            'id' => $plan->id,
            'title' => $plan->title,
            'status' => $plan->status,
            'accepted_at' => $plan->accepted_at?->toDateString(),
            'total_minor' => $planTotal,
            'total' => $this->money($planTotal, $currency),
            // "N of M billed": both sides are engine figures — real charges over the agreed
            // estimate. The page prints them; it never divides one by the other, and there is
            // deliberately no percentage.
            'billed_minor' => $planBilled,
            'billed' => $this->money($planBilled, $currency),
            'phases' => $phases,
            'phase_url' => route('dental.plans.phases', $plan->id),
            'item_url' => route('dental.plans.items', $plan->id),
            'transition_url' => route('dental.plans.transition', $plan->id),
        ];
    }

    /**
     * Format an integer minor-unit amount for DISPLAY.
     *
     * The conversion happens HERE, once, so the treatment-plan page receives money as strings
     * and performs no arithmetic of its own — not even a divide-by-100 (DENTAL-B.P4; the S5
     * ProcedureCard contract). The value is never derived here: it arrives already computed
     * from the engine.
     */
    private function money(int $minor, string $currency): string
    {
        return $currency.' '.number_format($minor / 100, 2, '.', "'");
    }
}
