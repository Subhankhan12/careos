<?php

namespace Modules\Dental\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Services\AuditService;
use Modules\Dental\Models\ToothRecord;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * The odontogram record/retrieve service (DENTAL.G1). PURE record + read — NO
 * interpretation logic (no caries detection, grading, risk, or diagnosis).
 *
 *  - {@see chart()} appends one immutable {@see ToothRecord} (a correction is a new
 *    record + reason, never an edit), gated on `dental.chart`, tenant + patient
 *    scoped, and audited (`dental.tooth_charted`).
 *  - {@see currentChart()} / {@see history()} read the patient's odontogram, gated on
 *    `patient.view`, and write a patient-scoped `read` audit row (clinical data).
 *
 * Everything is tenant-scoped and fail-closed: the actor and the patient must belong
 * to the current tenant, and `ToothRecord` (BelongsToTenant) confines every query.
 */
class ToothChartService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
    ) {}

    /**
     * Append a charting record. `$surface` null = a whole-tooth status; non-null = a
     * per-surface condition. `$reason` is required only conceptually for a correction
     * (a later record that supersedes an earlier one) — it is stored verbatim.
     */
    public function chart(
        User $actor,
        Patient $patient,
        string $tooth,
        ?string $surface,
        string $condition,
        ?string $note = null,
        ?string $reason = null,
    ): ToothRecord {
        Gate::forUser($actor)->authorize('dental.chart');
        $this->assertActorTenant($actor);
        $this->assertPatientTenant($patient);

        // The ToothRecord `creating` hook validates FDI id / surface / condition
        // deterministically (no interpretation); an invalid value throws before insert.
        $record = ToothRecord::query()->create([
            'patient_id' => $patient->id,
            'tooth' => $tooth,
            'surface' => $surface,
            'charted_condition' => $condition,
            'note' => $note,
            'reason' => $reason,
            'charted_by' => $actor->id,
            'charted_at' => Carbon::now(),
        ]);

        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => 'dental.tooth_charted',
            'resource_type' => 'tooth_records',
            'resource_id' => $record->id,
            'patient_id' => (string) $patient->id,
            'context' => [
                'tooth' => $tooth,
                'surface' => $surface,
                'charted_condition' => $condition,
                'is_correction' => $reason !== null,
            ],
        ]);

        return $record;
    }

    /**
     * The patient's CURRENT odontogram: the latest record per (tooth, surface).
     * Historical records are never destroyed — this just picks the most recent.
     *
     * @return Collection<int, ToothRecord>
     */
    public function currentChart(User $actor, Patient $patient): Collection
    {
        $this->authorizeRead($actor, $patient);

        return ToothRecord::query()
            ->where('patient_id', $patient->id)
            ->orderByDesc('charted_at')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (ToothRecord $record): string => $record->tooth.'|'.($record->surface ?? ''))
            ->values();
    }

    /**
     * The full charting history (optionally for one tooth), oldest first — the
     * append-only trail proving state over time.
     *
     * @return Collection<int, ToothRecord>
     */
    public function history(User $actor, Patient $patient, ?string $tooth = null): Collection
    {
        $this->authorizeRead($actor, $patient);

        return ToothRecord::query()
            ->where('patient_id', $patient->id)
            ->when($tooth !== null, fn ($query) => $query->where('tooth', $tooth))
            ->orderBy('charted_at')
            ->orderBy('id')
            ->get();
    }

    private function authorizeRead(User $actor, Patient $patient): void
    {
        Gate::forUser($actor)->authorize('patient.view');
        $this->assertActorTenant($actor);
        $this->assertPatientTenant($patient);

        // Reading the odontogram discloses clinical data → patient-scoped read log.
        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => 'read',
            'resource_type' => 'tooth_records',
            'resource_id' => (string) $patient->id,
            'patient_id' => (string) $patient->id,
            'context' => ['scope' => 'odontogram'],
        ]);
    }

    private function assertActorTenant(User $actor): void
    {
        if ($actor->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('actor_id', (string) $actor->id);
        }
    }

    private function assertPatientTenant(Patient $patient): void
    {
        if ($patient->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $patient->id);
        }
    }
}
