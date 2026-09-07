<?php

namespace Modules\Pharmacy\Support;

use Modules\Clinical\Models\Allergy;
use Modules\Patients\Models\Patient;
use Modules\Pharmacy\Contracts\MedicationSafetyProvider;
use Modules\Pharmacy\Services\NullMedicationSafetyProvider;

/**
 * The RECORDED safety context a pharmacy screen shows beside a medication action (QA-FIX.5a, P5-C1, D-206).
 *
 * WHY THIS EXISTS. Phase 5 drove the dispensing screen for a patient with a RECORDED SEVERE PENICILLIN
 * ALLERGY ("Anaphylaxis requiring adrenaline and hospital admission") holding an ACTIVE AMOXICILLIN
 * order, and the screen showed the order, the stock and the history — and nothing else. The honesty
 * fence held (nothing claimed a check had happened), but the recorded fact was absent from the one
 * screen where the drug is physically released, while the clinical chart displayed it two clicks away.
 *
 * WHAT THIS RETURNS, AND WHAT IT REFUSES TO RETURN. It assembles RECORDED FACTS ONLY — the patient's
 * own allergy rows, carried verbatim — plus the state of the {@see MedicationSafetyProvider} seam. It
 * computes NO interaction, NO cross-reactivity, NO contraindication, NO severity grade and NO
 * substitution: those are the certified-partner medical-device functions CareOS never performs
 * (ALLERGY.P1). It does not compare the allergy list against the medication being dispensed, because
 * doing so IS the judgment the fence forbids.
 *
 * The shape mirrors what `ClinicalChartController` already passes to `AllergyRecordPanel`, so every
 * surface renders the same component with the same wording rather than growing a second dialect.
 *
 * BOUNDARY NOTE: Pharmacy reads `Clinical\Models\Allergy` directly, following the existing precedent in
 * `Comms\Services\InboxPatientContextReader`, which reads the same model for the same reason. No
 * app-layer controller move is needed — `AllergyRecordPanel` already lives in the shared
 * `resources/js/Components/`.
 *
 * CALLERS MUST HAVE ALREADY AUTHORIZED AND AUDITED THE PATIENT READ. This adds neither, deliberately:
 * the three pharmacy screens each already `Gate::authorize('patient.view')` and call `auditRead()`
 * exactly once, and a second audit path here would double-count every render.
 */
final class PatientSafetyRecord
{
    public function __construct(private readonly MedicationSafetyProvider $medicationSafety) {}

    /**
     * @return array{allergies: list<array<string, mixed>>, medicationSafety: array{providerConfigured: bool, advisories: list<array<string, string>>}}
     */
    public function forPatient(Patient $patient): array
    {
        return [
            'allergies' => Allergy::query()
                ->where('patient_id', $patient->id)
                ->orderBy('substance')
                ->get()
                ->map(fn (Allergy $allergy): array => [
                    'id' => $allergy->id,
                    'substance' => $allergy->substance,
                    'reaction' => $allergy->reaction,
                    // A RECORDED provenance fact (where/how documented) — displayed, never computed.
                    'source' => $allergy->source,
                    // The clinician-RECORDED severity — surfaced as a fact, NOT a computed grade, and
                    // never used to style, rank or order anything (D-169).
                    'severity' => $allergy->severity,
                    'status' => $allergy->status,
                    'recorded_at' => $allergy->recorded_at->toDateTimeString(),
                    'verified_at' => $allergy->verified_at?->toDateTimeString(),
                ])
                ->values()
                ->all(),
            // The display-only seam. With the null object bound (today) the panel states plainly that no
            // automated checking is performed — which is the whole point of showing it here.
            'medicationSafety' => [
                'providerConfigured' => ! $this->medicationSafety instanceof NullMedicationSafetyProvider,
                'advisories' => [],
            ],
        ];
    }
}
