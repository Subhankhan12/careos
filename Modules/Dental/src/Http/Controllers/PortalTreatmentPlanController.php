<?php

namespace Modules\Dental\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Dental\Models\TreatmentPlan;
use Modules\Dental\Models\TreatmentPlanItem;
use Modules\Dental\Models\TreatmentPlanPhase;
use Modules\Dental\Services\TreatmentPlanService;
use Modules\Patients\Models\PortalAccount;

/**
 * The patient portal's view of their OWN dental treatment plans (DENTAL.G5) — READ-ONLY. Only
 * plans that have been shared with the patient (proposed onward: accepted / in-progress /
 * completed / proposed / declined — never a raw draft) are shown, with per-phase + total
 * estimates. No lifecycle actions, no charges — display only (the portal-payment PSP stays
 * deferred). Reuses the portal patient guard; each disclosure is patient-scoped read-logged.
 */
class PortalTreatmentPlanController
{
    public function index(Request $request, TreatmentPlanService $plans): Response
    {
        $account = $request->user('patient');
        abort_unless($account instanceof PortalAccount, 401);

        $planModels = TreatmentPlan::query()
            ->where('patient_id', $account->patient_id)
            ->whereIn('status', [
                TreatmentPlan::STATUS_PROPOSED,
                TreatmentPlan::STATUS_ACCEPTED,
                TreatmentPlan::STATUS_IN_PROGRESS,
                TreatmentPlan::STATUS_COMPLETED,
                TreatmentPlan::STATUS_DECLINED,
            ])
            ->with(['phases', 'items.dentalProcedure.tariffItem'])
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Portal/TreatmentPlan', [
            'plans' => $planModels->map(function (TreatmentPlan $plan) use ($plans): array {
                $plan->auditRead(['surface' => 'portal_treatment_plan']); // patient-scoped disclosure

                return [
                    'id' => $plan->id,
                    'title' => $plan->title,
                    'status' => $plan->status,
                    'total_minor' => $plan->items->sum(fn (TreatmentPlanItem $i): int => $plans->itemEstimate($i)),
                    'phases' => $plan->phases->sortBy('sequence')->map(fn (TreatmentPlanPhase $phase): array => [
                        'id' => $phase->id,
                        'name' => $phase->name,
                        'total_minor' => $plan->items->where('treatment_plan_phase_id', $phase->id)->sum(fn (TreatmentPlanItem $i): int => $plans->itemEstimate($i)),
                        'items' => $plan->items->where('treatment_plan_phase_id', $phase->id)->sortBy('sequence')->values()->map(fn (TreatmentPlanItem $i): array => [
                            'id' => $i->id,
                            'name' => $i->dentalProcedure?->tariffItem?->description,
                            'tooth' => $i->tooth,
                            'estimate_minor' => $plans->itemEstimate($i),
                        ])->all(),
                    ])->values()->all(),
                ];
            })->all(),
        ]);
    }
}
