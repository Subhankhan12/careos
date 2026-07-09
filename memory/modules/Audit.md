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
recalls.

## Open items

- Least-privilege DB user with UPDATE/DELETE revoked on `audit_events` (deferred; triggers guard now).
- Schedule `audit:ensure-partitions` once the scheduler exists (deferred).
- Break-glass flagging on every access is caller-driven; full patient access-report UI is later.
