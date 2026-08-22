<?php

namespace Modules\Patients\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Services\TenantContext;

/**
 * "Who accessed my record" — the patient's read-audit report.
 *
 * THERE IS EXACTLY ONE QUERY IN THIS CLASS. The Patient 360 tab, the dedicated access-log screen
 * and the nDSG/GDPR subject-access export all funnel through {@see query()}, because a
 * transparency surface whose export can disagree with its screen is worse than no export at all.
 * The public methods differ only in ORDER and FILTERS, never in what they are allowed to see.
 *
 * COMPLETENESS IS THE PROPERTY (PC.P5). Every disclosure of a patient record is written by
 * `LogsReads::auditRead()` → `AuditService::recordRead()` as an `action = 'read'` row carrying the
 * patient id — from ~65 call sites across clinical, billing, portal, dental, nursing, comms, lab,
 * radiology, surgery, pharmacy, hospital, ED and the AI tools. This report filters on the patient
 * and the action ONLY: it deliberately does NOT filter by actor type, surface, role or recency, so
 * a read cannot be missing from a patient's log because of a whitelist someone forgot to update.
 *
 * ONE KNOWN GAP, RECORDED RATHER THAN PAPERED OVER (PC.P5): **operator-mode activity cannot appear
 * here.** The platform-support ledger path writes its rows with
 * `actor_type = 'operator'` but action `operator.access` and **no `patient_id`** — they are
 * tenant-scoped, recording that the platform touched the clinic, not that anyone read a given
 * patient. Nothing in this class can attribute them to a patient without inventing a link, so it
 * does not try. The screen states this limitation on the page rather than implying the log is
 * exhaustive. (Operator Mode is deliberately inert today — no HTTP route and no UI, D-164 — so no
 * such access can currently occur.)
 */
class PatientAccessReport
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * The Patient 360 tab's view: every read, oldest first. Unchanged behaviour.
     *
     * @return Collection<int, object>
     */
    public function forPatient(Patient|string $patient): Collection
    {
        return $this->query($patient, 'ASC');
    }

    /**
     * The dedicated screen's and the export's view: newest first, optionally narrowed by a date
     * range and by actor types the caller picked from the values actually present.
     *
     * @param  list<string>  $actorTypes  empty = every actor type (the default; no whitelist)
     * @return Collection<int, object>
     */
    public function forPatientNewestFirst(
        Patient|string $patient,
        ?string $from = null,
        ?string $to = null,
        array $actorTypes = [],
    ): Collection {
        return $this->query($patient, 'DESC', $from, $to, $actorTypes);
    }

    /**
     * The actor types that actually appear in this patient's log — the filter chips are built from
     * REAL recorded values, never from a hardcoded taxonomy that could quietly omit one.
     *
     * @return Collection<int, object>
     */
    public function actorTypeCountsFor(Patient|string $patient): Collection
    {
        $patientId = $patient instanceof Patient ? $patient->id : $patient;

        return collect(DB::select(
            'SELECT actor_type, COUNT(*) AS total FROM audit_events '.
            'WHERE tenant_id <=> ? AND action = ? AND patient_id = ? '.
            'GROUP BY actor_type ORDER BY actor_type ASC',
            [$this->tenantId(), 'read', $patientId],
        ));
    }

    /**
     * The number of DISTINCT actors who have read this record — a factual count over audit rows,
     * not a judgment about any of them.
     */
    public function distinctActorCountFor(Patient|string $patient): int
    {
        $patientId = $patient instanceof Patient ? $patient->id : $patient;

        /*
         * COALESCE, not a bare multi-column DISTINCT: MySQL drops a row from
         * COUNT(DISTINCT a, b) when EITHER value is NULL, and a system read legitimately has
         * no actor id — so the plain form silently under-reports the very readers a patient is
         * least likely to know about. A caught-in-the-fixture bug, not a theoretical one.
         */
        $row = DB::selectOne(
            'SELECT COUNT(DISTINCT CONCAT(actor_type, ":", COALESCE(actor_id, "-"))) AS total FROM audit_events '.
            'WHERE tenant_id <=> ? AND action = ? AND patient_id = ?',
            [$this->tenantId(), 'read', $patientId],
        );

        return (int) ($row->total ?? 0);
    }

    /**
     * THE one query. Everything else in this class is ordering and narrowing on top of it.
     *
     * @param  list<string>  $actorTypes
     * @return Collection<int, object>
     */
    private function query(
        Patient|string $patient,
        string $direction,
        ?string $from = null,
        ?string $to = null,
        array $actorTypes = [],
    ): Collection {
        $patientId = $patient instanceof Patient ? $patient->id : $patient;
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        $sql = 'SELECT actor_type, actor_id, resource_type, resource_id, patient_id, occurred_at, context '.
            'FROM audit_events WHERE tenant_id <=> ? AND action = ? AND patient_id = ?';
        $bindings = [$this->tenantId(), 'read', $patientId];

        if ($from !== null) {
            $sql .= ' AND occurred_at >= ?';
            $bindings[] = $from;
        }

        if ($to !== null) {
            $sql .= ' AND occurred_at <= ?';
            $bindings[] = $to;
        }

        if ($actorTypes !== []) {
            $sql .= ' AND actor_type IN ('.implode(',', array_fill(0, count($actorTypes), '?')).')';
            $bindings = [...$bindings, ...$actorTypes];
        }

        $sql .= ' ORDER BY occurred_at '.$direction.', id '.$direction;

        return collect(DB::select($sql, $bindings));
    }

    private function tenantId(): string
    {
        $tenantId = $this->tenants->id();

        if ($tenantId === null) {
            throw TenantContextMissingException::forQuery(new Patient);
        }

        return $tenantId;
    }
}
