<?php

namespace Modules\Patients\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Services\DuplicateCandidate;
use Modules\Patients\Services\DuplicateDetector;
use Modules\Patients\Services\PatientService;

class PatientRegistrationController
{
    public function create(): Response
    {
        Gate::authorize('patient.edit');

        return Inertia::render('Patients/Register', [
            'duplicateCheckUrl' => route('patients.duplicates.check'),
            'storeUrl' => route('patients.store'),
        ]);
    }

    public function store(Request $request, PatientService $patients): RedirectResponse
    {
        Gate::authorize('patient.edit');

        $data = $this->validatedRegistration($request);
        $patient = $patients->create(
            $data['patient'],
            $data['contacts'],
            $data['identifiers'],
            $data['coverages'],
        );

        return redirect()->route('patients.show', $patient->id);
    }

    public function duplicates(Request $request, DuplicateDetector $duplicates): JsonResponse
    {
        Gate::authorize('patient.edit');

        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'postal' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:255'],
            'line1' => ['nullable', 'string', 'max:255'],
            'identifiers' => ['nullable', 'array'],
            'identifiers.*.system' => ['nullable', 'string', 'max:255'],
            'identifiers.*.value' => ['nullable', 'string', 'max:255'],
        ]);

        $candidates = $duplicates->findForDemographics($data)
            ->filter(fn (DuplicateCandidate $candidate): bool => $candidate->score > 0)
            ->take(5)
            ->map(fn (DuplicateCandidate $candidate): array => [
                'id' => $candidate->patient->id,
                'name' => trim($candidate->patient->first_name.' '.$candidate->patient->last_name),
                'mrn' => $candidate->patient->mrn,
                'date_of_birth' => $candidate->patient->date_of_birth->toDateString(),
                'score' => $candidate->score,
                'confidence' => $candidate->confidence,
                'reasons' => $candidate->reasons,
                'show_url' => route('patients.show', $candidate->patient->id),
            ])
            ->values()
            ->all();

        return response()->json(['duplicates' => $candidates]);
    }

    /**
     * @return array{patient: array<string, mixed>, contacts: list<array<string, mixed>>, identifiers: list<array<string, mixed>>, coverages: list<array<string, mixed>>}
     */
    private function validatedRegistration(Request $request): array
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date'],
            'sex' => ['required', 'string', 'max:50'],
            'gender' => ['nullable', 'string', 'max:100'],
            'preferred_language' => ['nullable', 'string', 'max:20'],
            'contacts' => ['array'],
            'contacts.*.type' => ['required_with:contacts', 'string', 'max:50'],
            'contacts.*.value' => ['nullable', 'string', 'max:255'],
            'contacts.*.line1' => ['nullable', 'string', 'max:255'],
            'contacts.*.line2' => ['nullable', 'string', 'max:255'],
            'contacts.*.city' => ['nullable', 'string', 'max:255'],
            'contacts.*.postal' => ['nullable', 'string', 'max:32'],
            'contacts.*.country' => ['nullable', 'string', 'size:2'],
            'contacts.*.is_primary' => ['boolean'],
            'identifiers' => ['array'],
            'identifiers.*.system' => ['required_with:identifiers', 'string', 'max:255'],
            'identifiers.*.value' => ['required_with:identifiers', 'string', 'max:255'],
            'coverages' => ['array'],
            'coverages.*.payer_name' => ['required_with:coverages', 'string', 'max:255'],
            'coverages.*.member_id' => ['required_with:coverages', 'string', 'max:255'],
            'coverages.*.plan' => ['nullable', 'string', 'max:255'],
            'coverages.*.coverage_type' => ['required_with:coverages', 'string', 'max:100'],
            'coverages.*.priority' => ['required_with:coverages', 'integer', 'min:1'],
        ]);

        return [
            'patient' => [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'date_of_birth' => $data['date_of_birth'],
                'sex' => $data['sex'],
                'gender' => $data['gender'] ?? null,
                'preferred_language' => $data['preferred_language'] ?? null,
            ],
            'contacts' => array_values($data['contacts'] ?? []),
            'identifiers' => array_values($data['identifiers'] ?? []),
            'coverages' => array_values($data['coverages'] ?? []),
        ];
    }
}
