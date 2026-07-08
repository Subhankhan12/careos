<?php

namespace Modules\Patients\Services;

use Illuminate\Support\Facades\DB;
use Modules\Patients\Models\Patient;

class PatientService
{
    public function __construct(private readonly MrnGenerator $mrn) {}

    /**
     * @param  array<string, mixed>  $patient
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $identifiers
     * @param  list<array<string, mixed>>  $coverages
     */
    public function create(array $patient, array $contacts = [], array $identifiers = [], array $coverages = []): Patient
    {
        return DB::transaction(function () use ($patient, $contacts, $identifiers, $coverages): Patient {
            $patient['mrn'] ??= $this->mrn->generate();

            $record = Patient::create($patient);
            $this->createChildren($record, $contacts, $identifiers, $coverages);

            return $record->load(['contacts', 'identifiers', 'coverages']);
        });
    }

    /**
     * @param  array<string, mixed>  $patient
     * @param  list<array<string, mixed>>|null  $contacts
     * @param  list<array<string, mixed>>|null  $identifiers
     * @param  list<array<string, mixed>>|null  $coverages
     */
    public function update(
        Patient $record,
        array $patient,
        ?array $contacts = null,
        ?array $identifiers = null,
        ?array $coverages = null,
    ): Patient {
        return DB::transaction(function () use ($record, $patient, $contacts, $identifiers, $coverages): Patient {
            $record->update($patient);

            if ($contacts !== null) {
                $record->contacts()->delete();
            }

            if ($identifiers !== null) {
                $record->identifiers()->delete();
            }

            if ($coverages !== null) {
                $record->coverages()->delete();
            }

            $this->createChildren(
                $record,
                $contacts ?? [],
                $identifiers ?? [],
                $coverages ?? [],
            );

            return $record->refresh()->load(['contacts', 'identifiers', 'coverages']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $identifiers
     * @param  list<array<string, mixed>>  $coverages
     */
    private function createChildren(Patient $patient, array $contacts, array $identifiers, array $coverages): void
    {
        foreach ($contacts as $contact) {
            $patient->contacts()->create($contact);
        }

        foreach ($identifiers as $identifier) {
            $patient->identifiers()->create($identifier);
        }

        foreach ($coverages as $coverage) {
            $patient->coverages()->create($coverage);
        }
    }
}
