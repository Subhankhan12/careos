<?php

namespace Modules\Surgery\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Surgery\Models\SurgicalCase;

/**
 * The surgical case (SURGERY.G1). Scheduling a case is gated `surgery.manage` (a surgical act). G1 creates the
 * case at `scheduled`; the lifecycle machine (pre_op → in_progress → completed → post_op) is SURGERY.G2. The
 * case is patient + surgeon scoped (fail-closed) and read-logged (the model's `LogsReads`); creation is
 * audited app-layer. `stayId` is a SOFT inpatient link (null for day-surgery).
 *
 * ELECTRIC FENCE: the service records the case a human schedules — it computes no acuity / priority / risk.
 */
class SurgicalCaseService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function schedule(
        User $actor,
        Patient $patient,
        StaffProfile $surgeon,
        string $procedureDescription,
        Carbon $scheduledAt,
        ?string $stayId = null,
    ): SurgicalCase {
        Gate::forUser($actor)->authorize('surgery.manage');
        $this->assertSameTenant($patient->tenant_id, 'patient_id', $patient->id);
        $this->assertSameTenant($surgeon->tenant_id, 'primary_surgeon_id', $surgeon->id);

        return SurgicalCase::query()->create([
            'patient_id' => $patient->id,
            'primary_surgeon_id' => $surgeon->id,
            'stay_id' => $stayId,
            'procedure_description' => $procedureDescription,
            'scheduled_at' => $scheduledAt,
        ]);
    }

    /**
     * @return Collection<int, SurgicalCase>
     */
    public function forPatient(Patient $patient): Collection
    {
        return SurgicalCase::query()
            ->where('patient_id', $patient->id)
            ->orderBy('scheduled_at')
            ->get();
    }

    private function assertSameTenant(?string $tenantId, string $attribute, string $id): void
    {
        if ($tenantId !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
