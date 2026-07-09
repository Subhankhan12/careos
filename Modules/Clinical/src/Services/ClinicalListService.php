<?php

namespace Modules\Clinical\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Modules\Clinical\Events\ClinicalRecordChanged;
use Modules\Clinical\Models\Allergy;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Medication;
use Modules\Clinical\Models\Problem;
use Modules\Clinical\Models\Vital;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

class ClinicalListService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordProblem(
        Patient $patient,
        StaffProfile $recorder,
        User $actor,
        array $data,
        ?Encounter $encounter = null,
    ): Problem {
        $this->authorizeWrite($actor);
        $this->assertSameTenant($patient, 'patient_id');
        $this->assertSameTenant($recorder, 'recorded_by');
        $this->assertEncounter($patient, $encounter);

        $problem = Problem::query()->create([
            'patient_id' => $patient->id,
            'encounter_id' => $encounter?->id,
            'description' => (string) $data['description'],
            'code' => $data['code'] ?? null,
            'onset_date' => $data['onset_date'] ?? null,
            'status' => $data['status'] ?? Problem::STATUS_ACTIVE,
            'recorded_by' => $recorder->id,
            'recorded_at' => $data['recorded_at'] ?? now(),
            'resolved_at' => $data['resolved_at'] ?? null,
        ]);

        Event::dispatch(new ClinicalRecordChanged(
            'problem.added',
            'problem',
            $problem->id,
            $patient->id,
            $actor,
            ['status' => $problem->status, 'encounter_id' => $problem->encounter_id],
        ));

        return $problem;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordAllergy(Patient $patient, StaffProfile $recorder, User $actor, array $data): Allergy
    {
        $this->authorizeWrite($actor);
        $this->assertSameTenant($patient, 'patient_id');
        $this->assertSameTenant($recorder, 'recorded_by');

        $substance = (string) $data['substance'];
        $allergy = Allergy::query()->create([
            'patient_id' => $patient->id,
            'substance' => $substance,
            'substance_key' => AllergyGuard::normalize($data['substance_key'] ?? $substance),
            'reaction' => $data['reaction'] ?? null,
            'severity' => $data['severity'] ?? Allergy::SEVERITY_UNKNOWN,
            'status' => $data['status'] ?? Allergy::STATUS_ACTIVE,
            'recorded_by' => $recorder->id,
            'recorded_at' => $data['recorded_at'] ?? now(),
            'verified_at' => $data['verified_at'] ?? null,
        ]);

        Event::dispatch(new ClinicalRecordChanged(
            'allergy.added',
            'allergy',
            $allergy->id,
            $patient->id,
            $actor,
            [
                'substance_key' => $allergy->substance_key,
                'severity' => $allergy->severity,
                'status' => $allergy->status,
            ],
        ));

        return $allergy;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordVital(
        Patient $patient,
        StaffProfile $recorder,
        User $actor,
        array $data,
        ?Encounter $encounter = null,
    ): Vital {
        $this->authorizeWrite($actor);
        $this->assertSameTenant($patient, 'patient_id');
        $this->assertSameTenant($recorder, 'recorded_by');
        $this->assertEncounter($patient, $encounter);

        $vital = Vital::query()->create([
            'patient_id' => $patient->id,
            'encounter_id' => $encounter?->id,
            'recorded_at' => $data['recorded_at'] ?? now(),
            'systolic' => $data['systolic'] ?? null,
            'diastolic' => $data['diastolic'] ?? null,
            'heart_rate' => $data['heart_rate'] ?? null,
            'temperature_c' => $data['temperature_c'] ?? null,
            'spo2' => $data['spo2'] ?? null,
            'weight_g' => $data['weight_g'] ?? null,
            'height_mm' => $data['height_mm'] ?? null,
            'extra' => $data['extra'] ?? null,
            'recorded_by' => $recorder->id,
        ]);

        Event::dispatch(new ClinicalRecordChanged(
            'vital.recorded',
            'vital',
            $vital->id,
            $patient->id,
            $actor,
            ['encounter_id' => $vital->encounter_id],
        ));

        return $vital;
    }

    /**
     * @return array{problems: Collection<int, Problem>, allergies: Collection<int, Allergy>, vitals: Collection<int, Vital>, medications: Collection<int, Medication>}
     */
    public function readListsForPatient(Patient $patient): array
    {
        $this->assertSameTenant($patient, 'patient_id');

        $problems = Problem::query()->where('patient_id', $patient->id)->get();
        $allergies = Allergy::query()->where('patient_id', $patient->id)->get();
        $vitals = Vital::query()->where('patient_id', $patient->id)->get();
        $medications = Medication::query()->where('patient_id', $patient->id)->get();

        foreach ($problems as $problem) {
            $problem->auditRead(['surface' => 'clinical_list']);
        }

        foreach ($allergies as $allergy) {
            $allergy->auditRead(['surface' => 'clinical_list']);
        }

        foreach ($vitals as $vital) {
            $vital->auditRead(['surface' => 'clinical_list']);
        }

        foreach ($medications as $medication) {
            $medication->auditRead(['surface' => 'clinical_list']);
        }

        return [
            'problems' => $problems,
            'allergies' => $allergies,
            'vitals' => $vitals,
            'medications' => $medications,
        ];
    }

    private function authorizeWrite(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('note.write')) {
            throw new AuthorizationException('This user cannot write clinical records.');
        }
    }

    private function assertSameTenant(object $model, string $attribute): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }

    private function assertEncounter(Patient $patient, ?Encounter $encounter): void
    {
        if ($encounter === null) {
            return;
        }

        $this->assertSameTenant($encounter, 'encounter_id');

        if ($encounter->patient_id !== $patient->id) {
            throw CrossTenantReferenceException::forAttribute('encounter_id', $encounter->id);
        }
    }
}
