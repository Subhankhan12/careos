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

Built through gate P0A.G7. Read-logging exercised via a probe; wired to patients in Phase B.

## Open items

- Least-privilege DB user with UPDATE/DELETE revoked on `audit_events` (deferred; triggers guard now).
- Schedule `audit:ensure-partitions` once the scheduler exists (deferred).
- Break-glass flagging on every access is caller-driven; patient-report integration in Phase B.
