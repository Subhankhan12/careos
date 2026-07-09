<?php

namespace Modules\Clinical\Services;

use Illuminate\Support\Str;
use Modules\Clinical\Exceptions\AllergyConflictException;
use Modules\Clinical\Models\Allergy;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Services\TenantContext;

class AllergyGuard
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function check(Patient $patient, string $substanceKey): void
    {
        $this->assertPatientInTenant($patient);

        $normalized = self::normalize($substanceKey);

        $conflict = Allergy::query()
            ->where('patient_id', $patient->id)
            ->where('substance_key', $normalized)
            ->where('status', Allergy::STATUS_ACTIVE)
            ->exists();

        if ($conflict) {
            throw AllergyConflictException::forSubstance($normalized);
        }
    }

    public static function normalize(string $substance): string
    {
        return Str::lower(trim($substance));
    }

    private function assertPatientInTenant(Patient $patient): void
    {
        if ($patient->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', $patient->id);
        }
    }
}
