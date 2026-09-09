<?php

namespace Modules\Patients\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Services\TenantContext;

/**
 * "Who accessed my record" — the patient's disclosure report.
 *
 * THERE IS EXACTLY ONE QUERY IN THIS CLASS. The Patient 360 tab, the dedicated access-log screen
 * and the nDSG/GDPR subject-access export all funnel through {@see query()}, because a
 * transparency surface whose export can disagree with its screen is worse than no export at all.
 * The public methods differ only in ORDER and FILTERS, never in what they are allowed to see.
 *
 * COMPLETENESS IS THE PROPERTY (PC.P5), AND IT IS COMPLETENESS OVER DISCLOSURES — NOT OVER READS.
 * The report answers *"who was my record shown or given to"*, so it returns the actions in
 * {@see self::DISCLOSURE_ACTIONS} and no others. It deliberately does NOT filter by actor type,
 * surface, role or recency, so a disclosure cannot be missing because of a whitelist someone forgot
 * to update — but the ACTION set is itself a whitelist, and QA-FIX.10b exists because it used to
 * hold the single value `'read'` while the page claimed to show everything (`P9-C2`).
 *
 * WHAT IS DELIBERATELY NOT HERE, AND WHY — the `P9-C2` boundary, measured rather than asserted.
 * 982 rows in the live ledger carry a patient id and only 26 are disclosures; the rest are ACTIVITY
 * ON the record rather than DISCLOSURE OF it — `charge.captured` (163), `charge.validated` (151),
 * `planned_visit.materialized` (89), `visit.check_in`/`check_out` (94), the ADT events,
 * `consent.granted`, `document.uploaded`. Returning those would turn a subject-access artifact into
 * an activity feed and bury the handful of rows a patient is actually asking about. Two borderline
 * cases were examined and are excluded WITH REASONS rather than by omission: `referral.sent` (CareOS
 * transmits nothing, so listing it would assert a disclosure the product did not make) and
 * `notification.sent` (a message about care, not a release of the record). The screen now states
 * this boundary instead of claiming to show everything.
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
    /**
     * THE DISCLOSURE SET — the one definition of "this patient's record was shown or given to
     * someone", used by every query in this class. Three classes, each here for a stated reason:
     *
     *  - `read` — someone looked at, or took a copy of, this record. Every audited download in the
     *    product is one of these with an export surface (`document_download`,
     *    `portal_invoice_download`, `billing_ar_report_export`, …), so an export needs no action of
     *    its own and must not invent one (D-221).
     *  - `document.shared` — a document was RELEASED to the patient's portal. This is `P9-C2`: the
     *    row was written correctly, patient-scoped and hash-chained, and the `action = 'read'`
     *    filter excluded it, so a record release was invisible on the screen built to show it.
     *  - `document.unshared` — the release was withdrawn. Its pair, because a log that shows a
     *    release and never its withdrawal asserts an availability that may no longer stand.
     *
     * ADDING TO THIS LIST IS A DELIBERATE ACT, AND IS MEANT TO BE. There is deliberately no
     * automatic guard that classifies every future action: roughly fifteen action strings are built
     * by interpolation (`'admission.'.$status`) and cannot be enumerated statically, so a scan over
     * literals would look exhaustive without being so — which is the shape of this very finding.
     * What guards it instead is `PatientAccessLogDisclosureTest`, which pins BOTH directions: the
     * three classes appear, and named activity actions are asserted absent on purpose.
     *
     * @var list<string>
     */
    public const DISCLOSURE_ACTIONS = ['read', 'document.shared', 'document.unshared'];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * The Patient 360 tab's view: every disclosure, oldest first.
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
            'WHERE tenant_id <=> ? AND action IN ('.self::actionPlaceholders().') AND patient_id = ? '.
            'GROUP BY actor_type ORDER BY actor_type ASC',
            [$this->tenantId(), ...self::DISCLOSURE_ACTIONS, $patientId],
        ));
    }

    /**
     * The number of DISTINCT actors who have been shown this record — a factual count over audit
     * rows, not a judgment about any of them.
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
            'WHERE tenant_id <=> ? AND action IN ('.self::actionPlaceholders().') AND patient_id = ?',
            [$this->tenantId(), ...self::DISCLOSURE_ACTIONS, $patientId],
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

        /*
         * `action` is SELECTED, not merely filtered on. The screen and the export both have to say
         * WHAT KIND of disclosure each row was, which was impossible — and unnecessary — while every
         * row returned was a read. The surface hardcoded the word "read" for exactly that reason.
         */
        $sql = 'SELECT action, actor_type, actor_id, resource_type, resource_id, patient_id, occurred_at, context '.
            'FROM audit_events WHERE tenant_id <=> ? AND action IN ('.self::actionPlaceholders().') AND patient_id = ?';
        $bindings = [$this->tenantId(), ...self::DISCLOSURE_ACTIONS, $patientId];

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

    /** The `?, ?, …` placeholder list for the disclosure set, so the set is named in one place only. */
    private static function actionPlaceholders(): string
    {
        return implode(', ', array_fill(0, count(self::DISCLOSURE_ACTIONS), '?'));
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
