<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Clinical\Models\Allergy;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Models\PatientCoverage;
use Modules\Patients\Models\PatientIdentifier;
use Modules\Patients\Services\PatientAccessReport;

/**
 * The Patient 360 page.
 *
 * It lives in the APP LAYER because it composes TWO modules — Patients (the administrative
 * record) and Clinical (the recorded allergies the shared header's chip displays). Modules never
 * depend on each other (D-017), and an arch test enforces that `Modules\Patients` does not use
 * `Modules\Clinical`; the same reasoning already placed `AppointmentDetailController` here.
 * Moving this class changed its namespace and nothing else — the route, its `patient.view` gate,
 * the payload it already returned and its single read-audit row are untouched.
 *
 * THE ALLERGY READ IS DISPLAY-ONLY (ALLERGY.P1 / PC.P1-B1). It surfaces the substance, reaction
 * and the severity a CLINICIAN RECORDED, as facts. CareOS computes no allergy judgment here: no
 * cross-reactivity, no class match, no contraindication, no ranking — that is the certified
 * `MedicationSafetyProvider` seam, whose only implementation is a null object. Rows are ordered
 * by SUBSTANCE (alphabetically), never by severity, so the list itself asserts no priority.
 */
class PatientShowController extends Controller
{
    public function __invoke(string $patient, PatientAccessReport $accessReport): Response
    {
        Gate::authorize('patient.view');

        $record = Patient::query()
            ->whereKey($patient)
            ->firstOrFail();

        $record->auditRead(['surface' => 'patient_360']);

        return Inertia::render('Patients/Show', [
            'patient' => [
                'id' => $record->id,
                'mrn' => $record->mrn,
                'first_name' => $record->first_name,
                'last_name' => $record->last_name,
                'date_of_birth' => $record->date_of_birth->toDateString(),
                'age' => $record->date_of_birth->age,
                'sex' => $record->sex,
                'gender' => $record->gender,
                'preferred_language' => $record->preferred_language,
                'status' => $record->status,
                'contacts' => $this->contacts($record),
                'identifiers' => $this->identifiers($record),
                'coverages' => $this->coverages($record),
                'consents' => $this->consents($record),
            ],
            // B1 — the RECORDED allergies this page has been waiting for. `Patients/Show.vue`
            // already declares this optional top-level prop and keeps its banner dormant until
            // it lands ("rendered when present, absent silently"), which is exactly the gap the
            // wireframe names. Landing it here lights that banner with no page rewrite.
            'allergies' => $this->allergies($record),
            'accessLog' => $this->accessLog($accessReport, $record),
            /*
             * Tab counts computed HERE from the real rows (PC.P3, the PC.P2 lesson).
             *
             * They are accurate as Vue lengths today because none of these lists is filtered —
             * but that is a property of today's payload, not a guarantee. The moment one of them
             * is capped or gated (as the chart's `notes` and `orders` already are), a page-side
             * length starts under-reporting the record silently. Counting the rows is the version
             * that stays true.
             */
            'counts' => [
                'contacts' => PatientContact::query()->where('patient_id', $record->id)->count(),
                'coverages' => PatientCoverage::query()->where('patient_id', $record->id)->count(),
                'consents' => PatientConsent::query()->where('patient_id', $record->id)->count(),
                'identifiers' => PatientIdentifier::query()->where('patient_id', $record->id)->count(),
                'allergies' => Allergy::query()
                    ->where('patient_id', $record->id)
                    ->where('status', Allergy::STATUS_ACTIVE)
                    ->count(),
                'accessLog' => $accessReport->forPatient($record)->count(),
            ],
            'actions' => [
                'can_edit' => Gate::allows('patient.edit'),
                'grant_consent_url' => route('patients.consents.grant', $record->id),
            ],
        ]);
    }

    /**
     * The patient's ACTIVE recorded allergies, as documented facts.
     *
     * Ordered by substance — deliberately NOT by severity, because ordering by badness would be
     * the system asserting a priority it has no business asserting. Only `active` rows are
     * shown, matching the app-layer composition `AppointmentDetailController` already uses, so
     * the two surfaces cannot disagree about what a patient is allergic to.
     *
     * @return list<array<string, mixed>>
     */
    private function allergies(Patient $patient): array
    {
        return Allergy::query()
            ->where('patient_id', $patient->id)
            ->where('status', Allergy::STATUS_ACTIVE)
            ->orderBy('substance')
            ->get()
            ->map(fn (Allergy $allergy): array => [
                'id' => $allergy->id,
                'substance' => $allergy->substance,
                'reaction' => $allergy->reaction,
                // The clinician-RECORDED severity, surfaced as a fact — never a computed grade,
                // and never used to colour or order anything (D-169).
                'severity' => $allergy->severity,
                'status' => $allergy->status,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contacts(Patient $patient): array
    {
        return PatientContact::query()
            ->where('patient_id', $patient->id)
            ->get()
            ->map(fn (PatientContact $contact): array => [
                'id' => $contact->id,
                'type' => $contact->type,
                'value' => $contact->value,
                'line1' => $contact->line1,
                'line2' => $contact->line2,
                'city' => $contact->city,
                'postal' => $contact->postal,
                'country' => $contact->country,
                'is_primary' => $contact->is_primary,
            ])
            ->all();
    }

    /**
     * @return list<array<string, string>>
     */
    private function identifiers(Patient $patient): array
    {
        return PatientIdentifier::query()
            ->where('patient_id', $patient->id)
            ->get()
            ->map(fn (PatientIdentifier $identifier): array => [
                'id' => $identifier->id,
                'system' => $identifier->system,
                'value' => $identifier->value,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function coverages(Patient $patient): array
    {
        return PatientCoverage::query()
            ->where('patient_id', $patient->id)
            ->orderBy('priority')
            ->get()
            ->map(fn (PatientCoverage $coverage): array => [
                'id' => $coverage->id,
                'payer_name' => $coverage->payer_name,
                'member_id' => $coverage->member_id,
                'plan' => $coverage->plan,
                'coverage_type' => $coverage->coverage_type,
                'priority' => $coverage->priority,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function consents(Patient $patient): array
    {
        return PatientConsent::query()
            ->where('patient_id', $patient->id)
            ->get()
            ->map(fn (PatientConsent $consent): array => [
                'id' => $consent->id,
                'template_key' => $consent->template_key,
                'template_title' => $consent->template_title,
                'template_version' => $consent->template_version,
                'scope_keys' => $consent->template_scope_keys,
                'status' => $consent->status,
                'granted_at' => $consent->granted_at?->toDateTimeString(),
                'withdrawn_at' => $consent->withdrawn_at?->toDateTimeString(),
                'expires_at' => $consent->expires_at?->toDateTimeString(),
                'withdraw_url' => route('patients.consents.withdraw', [$patient->id, $consent->id]),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accessLog(PatientAccessReport $accessReport, Patient $patient): array
    {
        $rows = [];

        foreach ($accessReport->forPatient($patient) as $row) {
            $rows[] = [
                'actor_type' => $row->actor_type,
                'actor_id' => $row->actor_id,
                'occurred_at' => $row->occurred_at,
                'resource_type' => $row->resource_type,
            ];
        }

        return $rows;
    }
}
