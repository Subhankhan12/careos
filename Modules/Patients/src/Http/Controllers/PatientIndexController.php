<?php

namespace Modules\Patients\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Models\Patient;

class PatientIndexController
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('patient.view');

        $query = trim((string) $request->query('q', ''));
        $dob = trim((string) $request->query('date_of_birth', ''));

        $patients = Patient::query()
            ->when($query !== '', function ($builder) use ($query): void {
                $builder->where(function ($names) use ($query): void {
                    $names->whereRaw('MATCH(first_name, last_name) AGAINST (? IN BOOLEAN MODE)', [$this->booleanNameQuery($query)])
                        ->orWhere('first_name', 'like', '%'.$query.'%')
                        ->orWhere('last_name', 'like', '%'.$query.'%');
                });
            })
            ->when($dob !== '', fn ($builder) => $builder->whereDate('date_of_birth', Carbon::parse($dob)->toDateString()))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(25)
            ->get()
            ->map(fn (Patient $patient): array => $this->summary($patient))
            ->all();

        return Inertia::render('Patients/Index', [
            'filters' => [
                'q' => $query,
                'date_of_birth' => $dob,
            ],
            'patients' => $patients,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Patient $patient): array
    {
        return [
            'id' => $patient->id,
            'mrn' => $patient->mrn,
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'date_of_birth' => $patient->date_of_birth->toDateString(),
            'sex' => $patient->sex,
            'status' => $patient->status,
            'show_url' => route('patients.show', $patient->id),
        ];
    }

    private function booleanNameQuery(string $query): string
    {
        $tokens = array_values(array_filter(preg_split('/\s+/', strtolower(trim($query))) ?: []));

        return collect($tokens)
            ->map(fn (string $token): string => '+'.preg_replace('/[^a-z0-9]/', '', $token).'*')
            ->filter(fn (string $token): bool => $token !== '+*')
            ->implode(' ');
    }
}
