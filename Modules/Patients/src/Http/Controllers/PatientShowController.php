<?php

namespace Modules\Patients\Http\Controllers;

use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Models\PatientCoverage;
use Modules\Patients\Models\PatientIdentifier;
use Modules\Patients\Services\PatientAccessReport;

class PatientShowController
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
            'accessLog' => $this->accessLog($accessReport, $record),
            'actions' => [
                'can_edit' => Gate::allows('patient.edit'),
                'grant_consent_url' => route('patients.consents.grant', $record->id),
            ],
        ]);
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
