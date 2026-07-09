# Module: Nursing (`Modules\Nursing`)

## Purpose

Tenant-owned nursing/home-care wedge. E.1 adds service agreements: the contract behind recurring
home-care visits, including who receives care, what is authorized, and who funds it.

## Key tables

- `service_agreements` - tenant-owned (`BelongsToTenant`). ULID id, `tenant_id`, `patient_id`,
  `branch_id`, EU-generic `funding_type`, nullable `payer_name` / `authorization_ref`,
  nullable decimal `authorized_hours_per_week`, `starts_on`, nullable `ends_on`, lifecycle
  `status`, staff `created_by`, timestamps. Indexed by `(tenant_id, patient_id, status)` and
  `(tenant_id, branch_id)`.
- `agreement_services` - tenant-owned child rows. `service_agreement_id`, Scheduling
  `service_id`, documented `planned_frequency_text`, nullable `required_qualification`,
  `duration_minutes`, timestamps. Indexed by `(tenant_id, service_agreement_id)` and
  `(tenant_id, service_id)`.
- `visit_plans` - tenant-owned recurrence definitions. `service_agreement_id`,
  `agreement_service_id`, RFC 5545 `rrule`, `timezone`, local arrival window times,
  `duration_minutes`, `starts_on`, nullable `ends_on`, `active`, timestamps.
- `planned_visits` - tenant-owned generated occurrences. `visit_plan_id`, `patient_id`,
  local `scheduled_date`, UTC `window_start_at` / `window_end_at`, duration,
  nullable required qualification, status (`planned/assigned/cancelled/skipped`), nullable
  `assigned_resource_id`, nullable `assigned_at` / `assigned_by`, nullable cancellation reason,
  and optional `location_latitude` / `location_longitude` for deterministic straight-line travel.
  Unique
  `(tenant_id, visit_plan_id, scheduled_date)` and indexed by `(tenant_id, scheduled_date,
  status)` plus assignment window lookups.
- `nurse_constraints` - tenant-owned nurse/resource constraints. `resource_id` (Scheduling
  practitioner resource), `qualification`, decimal `max_hours_per_week`, and
  `max_travel_minutes_between_visits`. Unique `(tenant_id, resource_id)`.
- `visits` - tenant-owned executed visit container. Nullable `planned_visit_id`, `patient_id`,
  nurse `resource_id`, `branch_id`, `scheduled_start_at`, nullable check-in/out timestamps,
  lifecycle status (`scheduled/in_progress/completed/missed/cancelled`), and per-tenant unique
  `client_visit_uuid` offline idempotency key.
- `visit_events` - tenant-owned append-only proof events. `visit_id`, type `check_in/check_out`,
  device `occurred_at`, server `received_at`, nullable GPS `location` POINT SRID 4326, non-null
  `location_index` mirror for portable spatial indexing, nullable accuracy, source `gps/manual`,
  required manual reason for manual source, computed `distance_meters`, `recorded_by`, timestamps.
  DB triggers block UPDATE/DELETE.

## Key services / classes

- `Providers\NursingServiceProvider` - loads Nursing migrations and registers Nursing console
  commands.
- `Models\ServiceAgreement` - tenant-owned/read-logged agreement; guards patient, branch, and
  creating staff user references in the current tenant.
- `Models\AgreementService` - tenant-owned child row; guards same-tenant service agreement and
  Scheduling service catalog references.
- `Services\ServiceAgreementService` - creates agreements with services, reads with patient-scoped
  read logging, and enforces lifecycle transitions.
- `Models\VisitPlan` and `PlannedVisit` - tenant-owned recurrence plans and generated visits with
  same-tenant agreement/service/patient/resource/user guards; planned visits are patient-scoped
  read-logged when shown on the dispatch board.
- `Models\NurseConstraint` - tenant-owned practitioner resource constraints for dispatch.
- `Services\VisitPlanGenerator` - expands RRULEs through Recurr, materializes concrete visits
  idempotently with upsert, stores UTC windows, and cancels single occurrences without changing
  the rule.
- `Console\MaterializeVisitsCommand` - `nursing:materialize-visits`, horizon-based idempotent
  materialization command for active tenants/plans.
- `Services\AssignmentValidator` - deterministic qualification/window/travel/hour-cap validation
  returning distinct reason codes.
- `Services\VisitAssignmentService` - `dispatch.manage`-gated locked assign/unassign workflow.
- `Console\AttemptVisitAssignmentCommand` - process-level test harness used by the assignment
  parallel hammer.
- `Http\Controllers\DispatchBoardController` and `DispatchActionController` - Inertia dispatcher
  board and server-side assign/unassign actions.
- `Models\Visit` and `VisitEvent` - tenant-owned visit execution records; `VisitEvent` is
  append-only at model and DB level.
- `Services\VisitService` - creates visits from assigned planned visits and records exactly one
  check-in plus one check-out event with GPS or reason-required manual fallback.
- `Services\DayPackService` - builds the nurse PWA day-pack for one authenticated nurse and one
  requested date, limited to the nurse's linked active practitioner resource assignments.
- `Http\Controllers\NurseAuthController` - `/api/nurse/login` and `/api/nurse/logout` Sanctum
  device-token endpoints; login requires tenant staff plus completed MFA.
- `Http\Controllers\NurseDayPackController` - `/api/nurse/day-pack` bearer-token endpoint requiring
  `nurse:day-pack` ability and returning the server-scoped day-pack.
- `Events\ServiceAgreementChanged` - app-layer audit glue records `service_agreement.*` actions.
- `Events\PlannedVisitChanged` - app-layer audit glue records `planned_visit.materialized` and
  `planned_visit.cancelled`.
- `nurse-pwa/` - separate Vite/Vue 3/TypeScript PWA with Workbox generation, Dexie IndexedDB,
  AES-GCM encrypted day-pack storage, and Vitest tests.

## Invariants enforced

- Agreements and agreement services are tenant-owned and fail closed without `TenantContext`.
- Agreement patient, branch, creating user, and Scheduling service references must be visible in
  the same tenant.
- `agreement.manage` is required to create/read/transition agreements.
- Starter RBAC grants `agreement.manage` to org-admin and coordinator roles; reception is denied.
- Legal transitions: `draft -> active/ended`, `active -> suspended/ended`,
  `suspended -> active/ended`; `ended` is terminal.
- Agreement lifecycle events are patient-scoped audited. Reading an agreement writes a
  patient-scoped `read` audit row.
- Planned frequency is stored as documented text; E.1 does not compute visit schedules.
- Recurrence parsing is delegated to `simshaun/recurr`; Nursing does not hand-roll RRULE parsing.
- Visit generation is timezone-correct: occurrences are computed in the plan timezone and stored
  as UTC arrival windows, so DST changes shift UTC while preserving local wall-clock time.
- Re-materialization is idempotent via unique `(tenant_id, visit_plan_id, scheduled_date)` plus
  upsert. Existing rows keep their status/cancellation reason, so cancelled occurrences are not
  resurrected.
- Scheduling `nursing:materialize-visits` is deferred; the command exists and is tested.
- `dispatch.manage` is required to view the dispatch board and assign/unassign visits; starter
  RBAC grants it to org-admin and coordinator, not reception.
- Nurse constraints may attach only to active practitioner resources.
- Assignment validation is deterministic: required qualification must exactly match the nurse
  constraint qualification; assigned visit windows use half-open overlap; travel uses haversine
  straight-line distance divided by tenant setting `nursing.dispatch.average_speed_kmh` (default
  40); and ISO-week assigned duration cannot exceed `max_hours_per_week`.
- Assignment is concurrency-safe: the service locks the planned visit row, nurse resource row, and
  candidate assigned visit rows with `FOR UPDATE` before persisting assignment. Parallel hammer
  proves one winner for eight overlapping contenders.
- Assignment/unassignment writes patient-scoped `planned_visit.assigned` /
  `planned_visit.unassigned` audit events; dispatch board reads write patient-scoped read rows.
- GPS privacy posture D-E3: proof-of-visit captures location only at check-in and check-out.
  There is no continuous location tracking, no background location collection, and no route capture.
- Manual proof fallback requires a non-empty reason whenever GPS is unavailable/denied.
- Check-out requires a prior check-in; the unique `(tenant_id, visit_id, type)` key prevents more
  than one check-in or one check-out event for a visit.
- Geofence distance is informational only: `VisitService` computes `ST_Distance_Sphere` from the
  event GPS point to the planned visit target coordinate, stores `distance_meters`, and flags
  distant events in audit context without blocking check-in/out.
- `visit_events.location` remains nullable for manual fallback. MariaDB 10.4 requires spatial
  indexes to be NOT NULL, so `location_index` is the non-null indexed mirror while `location`
  preserves the nullable business value; MySQL 8 CI verifies the same DDL.
- Nurse PWA tokens are device-scoped Sanctum bearer tokens with `nurse:day-pack` ability; token
  issuance requires a tenant staff user with completed MFA.
- The day-pack endpoint is fail-closed to the current token's tenant and linked active staff
  profile/practitioner resource. It returns only assigned visits on the requested date for that
  nurse, never another nurse's visits or another tenant's rows.
- Day-pack patient data is intentionally minimal: visit windows/address/tasks plus allergies,
  active medications, active problems, active care-plan goals, and same-day open/in-progress tasks.
- Every patient included in a day-pack sync writes a patient-scoped `read` audit row with surface
  `nurse_day_pack`.
- D-E2 storage posture: the PWA stores only AES-GCM ciphertext in Dexie/IndexedDB. The key is
  derived from the current session token using HKDF, stays in JavaScript memory only, and is never
  persisted along with the token or salt.
- Local PWA data is wiped on logout, on any 401/403 sync response (remote wipe via token
  revocation/expiry), and on the configurable idle timeout (default 15 minutes).
- The PWA is read-only in E.5: it shows today's synced visits and raw documented patient data with
  prominent allergies; visit execution/offline writeback arrives later.

## Status

**Phase E IN PROGRESS.** P0E.G1 service agreements, P0E.G2 planned visits from RRULE recurrence,
P0E.G3 dispatcher assignment, P0E.G4 proof-of-visit, and P0E.G5 nurse PWA encrypted day-pack sync
are registered with tests and app-layer audit/read logging.

## Open items

- Later Nursing gates add offline visit execution/writeback and operational workflows on top of
  the read-only encrypted day-pack.
