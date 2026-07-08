<?php

namespace Modules\Patients\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Services\TenantContext;

class PatientAccessReport
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return Collection<int, object>
     */
    public function forPatient(Patient|string $patient): Collection
    {
        $patientId = $patient instanceof Patient ? $patient->id : $patient;
        $tenantId = $this->tenants->id();

        if ($tenantId === null) {
            throw TenantContextMissingException::forQuery(new Patient);
        }

        return collect(DB::select(
            'SELECT actor_type, actor_id, resource_type, resource_id, patient_id, occurred_at, context '.
            'FROM audit_events WHERE tenant_id <=> ? AND action = ? AND patient_id = ? '.
            'ORDER BY occurred_at ASC, id ASC',
            [$tenantId, 'read', $patientId],
        ));
    }
}
