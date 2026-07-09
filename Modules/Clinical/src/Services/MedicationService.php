<?php

namespace Modules\Clinical\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Modules\Clinical\Events\ClinicalRecordChanged;
use Modules\Clinical\Exceptions\AllergyConflictException;
use Modules\Clinical\Models\Medication;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

class MedicationService
{
    public function __construct(
        private readonly AllergyGuard $allergyGuard,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(
        Patient $patient,
        StaffProfile $recorder,
        User $actor,
        array $data,
        ?string $overrideReason = null,
    ): Medication {
        $this->authorizeWrite($actor);
        $this->assertSameTenant($patient, 'patient_id');
        $this->assertSameTenant($recorder, 'recorded_by');

        $substanceKey = AllergyGuard::normalize((string) ($data['substance_key'] ?? $data['name']));
        $override = false;

        try {
            $this->allergyGuard->check($patient, $substanceKey);
        } catch (AllergyConflictException $exception) {
            $reason = trim((string) $overrideReason);

            if ($reason === '') {
                throw $exception;
            }

            if (! Gate::forUser($actor)->allows('allergy.override')) {
                throw new AuthorizationException('This user cannot override allergy hard-stops.');
            }

            $override = true;
        }

        $medication = Medication::query()->create([
            'patient_id' => $patient->id,
            'name' => (string) $data['name'],
            'substance_key' => $substanceKey,
            'dose_text' => $data['dose_text'] ?? null,
            'route' => $data['route'] ?? null,
            'frequency_text' => $data['frequency_text'] ?? null,
            'started_on' => $data['started_on'],
            'ended_on' => $data['ended_on'] ?? null,
            'status' => $data['status'] ?? Medication::STATUS_ACTIVE,
            'recorded_by' => $recorder->id,
            'recorded_at' => $data['recorded_at'] ?? now(),
        ]);

        if ($override) {
            Event::dispatch(new ClinicalRecordChanged(
                'allergy.override',
                'medication',
                $medication->id,
                $patient->id,
                $actor,
                [
                    'override' => true,
                    'substance_key' => $substanceKey,
                    'medication_id' => $medication->id,
                ],
                trim((string) $overrideReason),
            ));
        }

        Event::dispatch(new ClinicalRecordChanged(
            'medication.added',
            'medication',
            $medication->id,
            $patient->id,
            $actor,
            [
                'substance_key' => $substanceKey,
                'status' => $medication->status,
                'override' => $override,
            ],
        ));

        return $medication;
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
}
