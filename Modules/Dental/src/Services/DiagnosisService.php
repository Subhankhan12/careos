<?php

namespace Modules\Dental\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Services\AuditService;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\Diagnosis;
use Modules\Dental\Models\DiagnosisTerm;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * The diagnosis record/retrieve service (DENTAL.G7) — the sharpest fence in the vertical. It ONLY
 * records what the DENTIST entered and reads it back. There is NO suggestion, NO ranking, NO
 * differential, NO likelihood, NO inference, and NO AI of any kind in this service — not a single
 * method proposes or auto-populates a diagnosis.
 *
 *  - {@see record()} appends one immutable {@see Diagnosis} the dentist authored (label + the
 *    status THEY set + optional tooth/findings), gated on `dental.chart`, tenant + patient scoped,
 *    audited (`dental.diagnosis_recorded`).
 *  - {@see diagnosesFor()} reads the patient's diagnoses (history = every record), gated on
 *    `patient.view`, and writes a patient-scoped `read` audit row.
 *  - {@see terms()} / {@see addTerm()} manage the tenant's OWN diagnosis pick-list — a plain,
 *    alphabetical list of the tenant's terms (never ranked / filtered by a computed judgment).
 */
class DiagnosisService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
    ) {}

    /**
     * Record a dentist-authored diagnosis. `$label` is the diagnosis text (free text, or copied
     * from a tenant term the dentist picked); `$status` is the value the DENTIST set. `$termId`, if
     * given, is provenance only (which pick-list term was chosen) and must belong to this tenant.
     * The Diagnosis `creating` hook validates label / FDI / status deterministically.
     */
    public function record(
        User $actor,
        Patient $patient,
        string $label,
        string $status,
        ?string $tooth = null,
        ?string $surface = null,
        ?string $findings = null,
        ?string $termId = null,
        ?string $reason = null,
    ): Diagnosis {
        Gate::forUser($actor)->authorize('dental.chart');
        $this->assertActorTenant($actor);
        $this->assertPatientTenant($patient);

        // If the dentist picked a term, confirm it is one of THIS tenant's own terms (provenance).
        if ($termId !== null && ! DiagnosisTerm::query()->whereKey($termId)->exists()) {
            throw new DentalException('The selected diagnosis term does not exist for this tenant.');
        }

        $diagnosis = Diagnosis::query()->create([
            'patient_id' => $patient->id,
            'diagnosed_by' => $actor->id,
            'diagnosis_term_id' => $termId,
            'label' => $label,
            'tooth' => $tooth,
            'surface' => $surface,
            'status' => $status,
            'findings' => $findings,
            'reason' => $reason,
            'diagnosed_at' => Carbon::now(),
        ]);

        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => 'dental.diagnosis_recorded',
            'resource_type' => 'diagnoses',
            'resource_id' => $diagnosis->id,
            'patient_id' => (string) $patient->id,
            'context' => [
                'status' => $status,
                'tooth' => $tooth,
                'is_free_text' => $termId === null,
                'is_change' => $reason !== null,
            ],
        ]);

        return $diagnosis;
    }

    /**
     * The patient's diagnoses, most recent first — history is every record (a change is a new row).
     *
     * @return Collection<int, Diagnosis>
     */
    public function diagnosesFor(User $actor, Patient $patient): Collection
    {
        Gate::forUser($actor)->authorize('patient.view');
        $this->assertActorTenant($actor);
        $this->assertPatientTenant($patient);

        // Reading a diagnosis discloses clinical data → patient-scoped read log.
        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => 'read',
            'resource_type' => 'diagnoses',
            'resource_id' => (string) $patient->id,
            'patient_id' => (string) $patient->id,
            'context' => ['scope' => 'diagnoses'],
        ]);

        return Diagnosis::query()
            ->where('patient_id', $patient->id)
            ->orderByDesc('diagnosed_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The tenant's OWN active diagnosis terms — a plain, alphabetical pick-list. NOT ranked, NOT
     * filtered by any computed likelihood; just the tenant's terms in name order.
     *
     * @return Collection<int, DiagnosisTerm>
     */
    public function terms(User $actor): Collection
    {
        Gate::forUser($actor)->authorize('patient.view');
        $this->assertActorTenant($actor);

        return DiagnosisTerm::query()
            ->where('is_active', true)
            ->orderBy('label')
            ->get();
    }

    /**
     * Add a term to the tenant's own diagnosis pick-list (a data-entry convenience). The dentist
     * authors their list; nothing is imported from a licensed code set.
     */
    public function addTerm(User $actor, string $label): DiagnosisTerm
    {
        Gate::forUser($actor)->authorize('dental.chart');
        $this->assertActorTenant($actor);

        if (trim($label) === '') {
            throw new DentalException('A diagnosis term needs a label.');
        }

        // BelongsToTenant scopes the lookup and stamps tenant_id on create.
        $term = DiagnosisTerm::query()->firstOrCreate(
            ['label' => trim($label)],
            ['created_by' => $actor->id, 'is_active' => true],
        );

        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => 'dental.diagnosis_term.created',
            'resource_type' => 'diagnosis_terms',
            'resource_id' => $term->id,
            'context' => ['label' => $term->label],
        ]);

        return $term;
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
