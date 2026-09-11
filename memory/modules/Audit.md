# Module: Audit (`Modules\Audit`)

## Purpose

The append-only, per-tenant, hash-chained audit trail plus the read-logging mechanism. Designed
once to serve compliance across all markets ("who accessed my record"). Does NOT depend on
Platform — the Platform-aware glue lives in `app/`.

## Key tables

- `audit_events` — append-only, monthly **range-partitioned** on `occurred_at`, PK `(id, occurred_at)`.
  Columns: id CHAR(26), tenant_id (NULL = platform), actor_type (user/service/ai), actor_id,
  action, resource_type/id, patient_id, before_hash/after_hash, reason, ip, ua, context JSON,
  occurred_at DATETIME(6), prev_hash, hash. BEFORE UPDATE/DELETE triggers `SIGNAL SQLSTATE '45000'`.

## Key services / classes

- `Services\AuditService` — `record(data)`, `recordRead(type, id, patientId?, context?)`,
  `verifyChain(?tenantId)`. Per-tenant chain: locks latest row `FOR UPDATE`, strictly monotonic
  microsecond `occurred_at`, `hash = sha256(canonical ordered payload incl. prev_hash)`.
- `Contracts\AuditContext` (interface owned by Audit) — tenant/actor/ip/ua resolution.
  Default `Support\NullAuditContext`; app binds `App\Audit\PlatformAuditContext`.
- `Concerns\LogsReads` (+ `Facades\Audit`) — `auditRead()` for sensitive-resource reads (action `read`).
- `Models\AuditEvent` — read-only (update/delete throw `AuditEventImmutableException`).
- `Console\EnsureAuditPartitions` — `audit:ensure-partitions` (idempotent monthly partition maintenance).

## Canonical hash payload (order matters)

`id, tenant_id, actor_type, actor_id, action, resource_type, resource_id, patient_id,
before_hash, after_hash, reason, ip, ua, context, occurred_at, prev_hash`.

## App-layer glue (not in this module — respects the boundary)

- `App\Audit\AuthAuditSubscriber` — auth events → audit (login/logout/failed/password reset).
- `App\Providers\AppServiceProvider` — model-event emitters (role/setting/feature/tenant changes),
  skipped in system mode; binds `AuditContext`.
- `App\Services\BreakGlassService` — composes Platform `BreakGlassGrant` + AuditService.

## Invariants enforced

- `audit_events` is append-only: UPDATE/DELETE blocked at the DB (triggers) AND in Eloquent.
- Per-tenant hash chain; `verifyChain` detects any tampering or gap.
- Partition + trigger DDL is portable across MariaDB 10.4 and MySQL 8.

## Status

**Phase A COMPLETE** (through P0A.C) + patient read-logging wired in P0B.G2. Append-only
hash-chained partitioned `audit_events` + AuditService (verifyChain, DB UPDATE/DELETE triggers),
audit integration, read-logging, and break-glass are in place; portable + green on MariaDB (dev)
and MySQL 8 (CI as of Phase A). Read-logging is exercised via a probe and by real Patient reads.
P0B.G3 added `patient.merged` and `patient.unmerged` audit actions with reversible merge snapshots.
P0B.G4 added patient-scoped `consent.granted` and `consent.withdrawn` audit actions; chain
verification is covered in the consent lifecycle tests.
P0B.G5 added patient-scoped `portal.invited`, `portal.first_login`, and `portal.login` actions.
P0B.G6 surfaces `PatientAccessReport` in the patient 360 UI and verifies the 360 view writes the
existing patient-scoped `read` event.
P0D.G1 added patient-scoped `encounter.opened` and `encounter.closed` actions plus encounter read
logging. P0D.G2 added patient-scoped `note.signed` and `note.amended` actions plus clinical-note
read logging. P0D.G3 added patient-scoped `problem.added`, `allergy.added`, `vital.recorded`,
`medication.added`, and `allergy.override` actions plus clinical-list read logging. P0D.G4 added
patient-scoped `document.uploaded`, `document.shared`, `document.unshared`, and
`document.deleted` actions plus staff/portal document download read logging. P0D.G7 added
patient-scoped `read` rows for the clinical chart and note-editor surfaces. P0D.G6 added
patient-scoped care-plan/task lifecycle actions (`care_plan.*`, `care_plan_goal.*`,
`clinical_task.*`) and care-plan chart read logging. P0D.G5 added patient-scoped referral and
recall lifecycle actions (`referral.*`, `recall.*`) plus chart read logging for referrals and
recalls. P0D.G8 added AiCore ledger/action audit rows for clinical Summary/Follow-up agent paths
and patient-scoped read logging for Summary source rows.

## Open items

- Least-privilege DB user with UPDATE/DELETE revoked on `audit_events` (deferred; triggers guard now).
- Schedule `audit:ensure-partitions` once the scheduler exists (deferred).
- Break-glass flagging on every access is caller-driven; full patient access-report UI is later.

## FINAL STATE after the ten-phase QA programme (2026-09-10, `805930e`)

**This module is the programme's spine — three CRITICALs and two of the last three fixes landed on it.**

- **There is exactly ONE physical writer of audit rows:** `AuditService::record()`. Every other reference to
  `audit_events` in `app/` and `Modules/` is a SELECT, a comment, the model's `$table` or the migration.
  **An absent row therefore means a caller never called — never that a second path swallowed it.**
- `record()` wraps each row in `DB::transaction` with a **per-tenant `FOR UPDATE` lock**, so N rows are N
  serialised appends. Keep per-request row counts bounded (QA-FIX.10a caps the AR export at 11).
- **`patient_id` DEFAULTS TO NULL** and is only set if the caller passes it. Nothing at the write path enforces
  the link, so a disclosure written without it is silently patient-less — invisible in the patient's own log
  while looking perfectly well-formed in the ledger. This is how `P9-C1` happened.
- **`P1-C1` (CRITICAL, fixed `78a05db`, D-192/193)** — web requests wrote **tenant-local wall-clock into the
  append-only ledger's UTC columns**. Stated precisely at the time and still true: **the chain was never
  broken**; the values were wrong, the hashes were consistent. Storage is UTC from every path now; display is
  tenant-local.
- **The disclosure set is `PatientAccessReport::DISCLOSURE_ACTIONS`** (D-222) and an export is a **`read` row
  with an export surface** (D-221). **Do not invent an action string for a disclosure** — it produces a
  well-formed, hash-chained row that no patient can ever see.
- **The append-only fence held in all ten phases:** model `appendOnly()` guards plus `SIGNAL '45000'` database
  triggers on `lab_results`, `order_results`, `imaging_study_events`, `stay_events`, `audit_events`; the chain
  is verified by replay. Nothing in the programme mutated or deleted a recorded fact.

## A live staff session steals attribution for a patient's own portal reads (QA-FIX.12a, `QF12a-M1`, OPEN)

`app/Audit/PlatformAuditContext::actor()` checks the **default (staff) guard first and lets it win
unconditionally**; only if `Auth::user()` is null does it look at `Auth::guard('patient')`. The staff app
and the portal are the same origin and share one session cookie, so both guards can hold a user at once.

**Measured A/B, same browser, same pages, same patient, minutes apart:** with a staff session live,
`portal_home` / `portal_messages` / `portal_telehealth` all recorded `user` · *"Dr. Anke Berg"*; with no
staff session, all three recorded `patient` · *"Patient (self)"*. Both halves are in one exported CSV.

**Pre-existing** (`portal_home` has behaved this way since PC.P5) and **graded MEDIUM on reachability, not
consequence** — nothing goes unrecorded, but the patient's legal access log names an actor who did not read
the record. **Not fixed:** reversing the guard order is wrong in the other direction; the honest remedy
resolves the actor from the guard that authorised THIS request, which is a change across every audited
surface and needs its own gate.
