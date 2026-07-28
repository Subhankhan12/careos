<?php

namespace Modules\ED\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\ED\Contracts\TriageAcuityProvider;
use Modules\ED\Services\NullTriageAcuityProvider;

/**
 * The Emergency Department vertical — **Phase 6** of the phased hospital build. ED.G1 ships the FOUNDATION
 * only: the module, the NET-NEW `EdVisit` patient-flow entity + its legal-only lifecycle + append-only flow
 * events, ED RBAC, and — established here, EMPTY — the triage-acuity SEAM (per
 * docs/HOSPITAL-PHASE6-ED-MAP.md). No triage record (G2), tracking board (G3), documentation (G4),
 * disposition/ADT handoff (G5), or billing (G6).
 *
 * The triage-acuity seam is bound here to its null-object — the `MedicationSafetyProvider` /
 * `LabConnectivity` precedent — so a certified partner engine can later be bound in its place WITHOUT
 * touching any consumer. Cross-module audit composition (the `EdVisit` / `EdVisitEvent` model hooks) lives in
 * the app layer, so this module stays free of Audit — the Dental / Hospital / Pharmacy / Surgery posture. The
 * inpatient stay-link (the ED→ADT admit handoff, G5) is a soft `stay_id` composed app-layer, not a direct
 * Hospital dependency.
 */
class EDServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // THE TRIAGE-ACUITY SEAM. CareOS performs no acuity computation itself — computing a triage acuity is
        // performing triage, which is certified-partner / medical-device territory and a permanent homemade
        // non-goal (the electric fence; the triage eval already refuses it). The null-object returns
        // "no suggestion" (asserts nothing); swap the binding for a licensed partner later, no consumer changes.
        $this->app->bind(TriageAcuityProvider::class, NullTriageAcuityProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
