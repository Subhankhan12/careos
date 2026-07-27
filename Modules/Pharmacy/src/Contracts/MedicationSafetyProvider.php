<?php

namespace Modules\Pharmacy\Contracts;

use Modules\Clinical\Contracts\LabConnectivity;
use Modules\Pharmacy\Services\NullMedicationSafetyProvider;
use Modules\Pharmacy\Support\SafetyContext;
use Modules\Pharmacy\Support\SafetyResult;

/**
 * THE MEDICATION-SAFETY SEAM (PHARMACY.G1) — the clean integration point where a CERTIFIED, LICENSED
 * drug-safety engine (drug–drug interaction · allergy-class contraindication · dose-range/max-dose ·
 * duplicate-therapy checking) would attach.
 *
 * This is DELIBERATELY an interface with only a null-object implementation
 * ({@see NullMedicationSafetyProvider}) — EXACTLY like Clinical's
 * {@see LabConnectivity} → `ManualLabConnectivity`. CareOS builds the SEAM,
 * NOT the logic:
 *
 *  - Each of those checks COMPUTES a clinical-safety JUDGMENT — precisely what the electric fence refuses
 *    ("no diagnosis, no triage, no symptom assessment, no dosing logic … Ever" — AGENTS.md). It is also
 *    MEDICAL-DEVICE territory requiring a partner-first licensed drug database + a regulatory track
 *    (DEFERRED.md D-P0D.G3; docs/HOSPITAL-PHASE2-PHARMACY-MAP.md §3). A homemade version is a PERMANENT
 *    non-goal — it would contradict the clinical-safety eval (tests/Evals) that already refuses such
 *    judgments.
 *  - When a certified partner is licensed, its implementation is bound here WITHOUT touching any consumer,
 *    and its findings are ADVISORY + human-owned — surfaced to the pharmacist/prescriber, NEVER
 *    auto-blocking or auto-acting.
 *
 * Until then, the bound implementation returns {@see SafetyResult::none()} for every call: CareOS asserts
 * nothing about medication safety; a human owns that judgment.
 */
interface MedicationSafetyProvider
{
    /**
     * Evaluate a proposed/current medication context at PRESCRIBING time (future PHARMACY.G2 call site).
     */
    public function checkOrder(SafetyContext $context): SafetyResult;

    /**
     * Evaluate a medication context at ADMINISTRATION time (future PHARMACY.G3 / eMAR call site).
     */
    public function checkAdministration(SafetyContext $context): SafetyResult;
}
