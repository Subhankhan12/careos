<?php

namespace Modules\ED\Contracts;

use Modules\ED\Services\NullTriageAcuityProvider;
use Modules\ED\Support\AcuityContext;
use Modules\ED\Support\AcuityResult;

/**
 * THE TRIAGE-ACUITY SEAM (ED.G1) — the clean integration point where a CERTIFIED, LICENSED triage/acuity
 * engine (an ESI / Manchester / CTAS decision-support device) would attach.
 *
 * This is DELIBERATELY an interface with only a null-object implementation
 * ({@see NullTriageAcuityProvider}) — EXACTLY like Pharmacy's `MedicationSafetyProvider` →
 * `NullMedicationSafetyProvider` and Clinical's `LabConnectivity` → `ManualLabConnectivity` (referenced by
 * name — ED does not depend on a peer vertical). CareOS builds the SEAM, NOT the logic:
 *
 *  - COMPUTING a triage acuity (taking vitals + complaint and PRODUCING the ESI/Manchester/CTAS level) is
 *    performing TRIAGE — precisely what the electric fence refuses ("no diagnosis, no triage, no symptom
 *    assessment, no dosing logic … Ever" — AGENTS.md), and it is regulated MEDICAL-DEVICE territory requiring
 *    a certified partner + a funded regulatory track (docs/HOSPITAL-PHASE6-ED-MAP.md §3). A homemade version
 *    is a PERMANENT non-goal — it would contradict the clinical-safety eval (tests/Evals) that already refuses
 *    triage.
 *  - When a certified partner is licensed, its implementation is bound here WITHOUT touching any consumer, and
 *    its result is ADVISORY + human-owned — surfaced to the triage nurse, NEVER auto-assigning the acuity and
 *    NEVER auto-prioritising the queue.
 *
 * Until then, the bound implementation returns {@see AcuityResult::none()} for every call: CareOS asserts
 * nothing about acuity; a human (the triage nurse, ED.G2) owns that judgment.
 */
interface TriageAcuityProvider
{
    /**
     * Evaluate an arrival context for an ADVISORY acuity suggestion (a future certified-partner call site).
     * The shipped null-object ignores the context and returns {@see AcuityResult::none()} — CareOS computes
     * no acuity.
     */
    public function suggestAcuity(AcuityContext $context): AcuityResult;
}
