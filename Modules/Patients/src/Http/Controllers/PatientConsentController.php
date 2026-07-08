<?php

namespace Modules\Patients\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Services\ConsentService;

class PatientConsentController
{
    public function grant(string $patient, Request $request, ConsentService $consents): RedirectResponse
    {
        Gate::authorize('patient.edit');

        $data = $request->validate([
            'template_key' => ['required', 'string', 'max:255'],
            'signature' => ['required', 'string', 'max:255'],
        ]);

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $capturedBy = $request->user();

        if ($capturedBy === null) {
            abort(403);
        }

        $consents->grant($record, $data['template_key'], $data['signature'], $capturedBy);

        return redirect()->route('patients.show', $record->id);
    }

    public function withdraw(string $patient, string $consent, Request $request, ConsentService $consents): RedirectResponse
    {
        Gate::authorize('patient.edit');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $patientConsent = PatientConsent::query()
            ->where('patient_id', $record->id)
            ->whereKey($consent)
            ->firstOrFail();

        $consents->withdraw($patientConsent, $data['reason']);

        return redirect()->route('patients.show', $record->id);
    }
}
