# Module: Nursing (`Modules\Nursing`)

## Purpose

Tenant-owned nursing/home-care wedge: service agreements, recurrence-generated planned visits,
validated dispatch, proof-of-visit, offline-first nurse PWA sync, incidents, actual-timesheets,
and validator-bound dispatch agent proposals.

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
- `nurse_sync_actions` - tenant-owned idempotency ledger for offline PWA replay. Unique
  `(tenant_id, client_action_uuid)`, nullable `visit_id`, `nurse_resource_id`, action type,
  device sequence/timestamp, status/result code, client payload, and prior result payload.
- `visit_observations` - tenant-owned nurse-authored note/observation content from offline sync.
  Unique `(tenant_id, client_action_uuid)`, linked visit/patient/resource, note text, flagged
  review state/reason, and device timestamp.
- `sync_conflicts` - tenant-owned human-review queue for ambiguous offline conflicts. Nullable
  `visit_id`, `nurse_resource_id`, action type, client payload, server state, reason, status
  (`open/resolved`), resolver, and timestamps.
- `visit_tasks` - tenant-owned visit execution checklist rows. `visit_id`, nullable
  `agreement_service_id`, description, status (`open/done/not_done`), reason-required
  `not_done_reason`, and nullable `completed_at`.
- `visit_notes` - tenant-owned nurse observational notes linked to visit and patient with body,
  author nurse resource, and `recorded_at`. These are not D.2 signed clinical notes.
- `visit_attachments` - tenant-owned private photo/signature metadata rows. File bytes live under
  generated tenant-prefixed private disk paths and are streamed through an authorized controller.
- `visit_vitals` - tenant-owned raw visit vitals linked to visit and patient using the D.3 vitals
  column shape plus `extra`; no interpretation fields.
- `incidents` - tenant-owned factual incident reports. Nullable visit/patient links,
  `reported_by_resource_id`, `occurred_at`, reporter-selected category/severity, description,
  lifecycle status, and timestamps.
- `timesheet_lines` - tenant-owned draft/approved pay lines derived from actual visit proof events.
  `resource_id`, `visit_id`, work date, actual `started_at` / nullable `ended_at`, nullable
  computed `minutes`, optional `travel_minutes`, JSON discrepancy flags, approval metadata, and
  timestamps. Unique `(tenant_id, visit_id)`. DB triggers block UPDATE/DELETE once approved.

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
- `Http\Controllers\NurseSyncController` - `/api/nurse/sync` bearer-token endpoint accepting a
  batch of encrypted-PWA outbox actions after client decryption and returning per-action results.
- `Services\NurseSyncService` - deterministic replay service for offline actions with idempotency
  and D-E1 conflict policy.
- `Models\NurseSyncAction`, `VisitObservation`, and `SyncConflict` - tenant-owned sync ledger,
  note persistence, and human-review conflict rows.
- `Models\VisitTask`, `VisitNote`, `VisitAttachment`, and `VisitVital` - tenant-owned visit
  execution rows for offline task outcomes, nurse notes, private files, and raw vitals.
- `Models\Incident` and `TimesheetLine` - tenant-owned E.8 incident and actual-timesheet records
  with same-tenant guards; approved timesheet lines are immutable at model and DB-trigger level.
- `Services\TimesheetService` - derives draft timesheet lines from completed visits' actual
  check-in/check-out events, flags discrepancies, and gates approval through `timesheet.approve`.
- App-layer AiCore `DispatchAgent` tools propose Nursing assignments/replans through
  `NursingDispatchProposalEngine`; approved proposals execute through `VisitAssignmentService`.
- `Events\NurseSyncActionProcessed` - app-layer audit glue records `nurse_sync.*` actions.
- `Events\IncidentReported` - app-layer audit glue records `incident.reported` with patient_id and
  explicit reporter-selected severity context.
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
- Offline PWA action replay is idempotent by `(tenant_id, client_action_uuid)`; replaying the same
  batch returns the stored result and does not double-create visits, visit events, vitals, notes, or
  conflicts.
- D-E1 conflict policy: server wins schedule (cancelled/reassigned visits reject check-in,
  check-out, task-complete, and other schedule-affecting actions with
  `schedule_changed_server_wins`); client wins note content (notes persist and are flagged when
  schedule changed); ambiguous actions create `sync_conflicts` rows for human review.
- Vitals synced from the PWA are raw documented values only and include `client_action_uuid` in
  `extra` for traceability; no interpretation/scoring is introduced.
- `nurse_sync.accepted`, `nurse_sync.rejected`, and `nurse_sync.conflict` are audited through
  app-layer event handling.
- The PWA outbox is append-only until server acknowledgement, encrypted with the same AES-GCM
  session-derived key as the day-pack, and retried with exponential backoff (`1000ms`, `2000ms`,
  doubling to `60000ms` max).
- E.7 visit execution actions use `visit_task_done`, `visit_task_not_done`, `visit_vitals`,
  `visit_note`, `visit_photo`, and `visit_signature`. They replay idempotently through
  `nurse_sync_actions`, write patient-scoped audit rows, and do not duplicate domain rows on replay.
- A `visit_task_not_done` action is rejected unless `not_done_reason` is non-empty; accepted
  done/not-done task actions update only the addressed same-tenant visit task.
- Visit vitals are raw documented values only. The `visit_vitals` table intentionally has no
  flag/range/score/interpretation/derived columns, and the PWA displays raw unit-labeled inputs
  without interpretive colors or advice.
- Photos and signatures are stored locally in the encrypted outbox until sync. The server validates
  MIME/size, redacts base64 file bytes from the sync ledger payload, writes bytes to the private
  local disk under a tenant-prefixed generated path, and exposes only controller-streamed downloads.
- Nurse visit notes are observational visit documentation, not signed/locked SOAP clinical notes.
  Clinician countersigning is deferred.
- Incident reports are factual reporter-authored records. CareOS stores the selected severity but
  never assesses severity for the reporter, advises action, or escalates based on clinical
  judgment. Offline `incident_report` sync actions are idempotent by `client_action_uuid`.
- Timesheet minutes come only from actual `visit_events` check-in/check-out `occurred_at` values,
  never from the plan or schedule. Missing checkout creates a draft line with null end/minutes and
  `missing_check_out` instead of guessing.
- Timesheet discrepancy flags are explainable for approvers: `missing_check_out`,
  `manual_location`, and `duration_deviation` beyond tenant setting
  `nursing.timesheet.duration_deviation_minutes` (default 15).
- Draft timesheet lines remain editable. Approval requires `timesheet.approve` and then locks the
  row at both model and database-trigger level; raw UPDATE/DELETE of approved rows is blocked.
- Starter RBAC grants `timesheet.approve` to org-admin and coordinator roles.
- Dispatch agent proposals are accepted only if `AssignmentValidator` returns no reasons. The agent
  never assigns while pending, never bypasses the E.3 locked assignment path, and refuses clinically
  framed prioritization requests.
- Phase E exit is proven by `airplane mode: full offline visit syncs and produces a timesheet line`.
  The test logs a nurse in, syncs a day-pack with patient read audit, replays an offline visit outbox
  through the real sync API, verifies one completed visit with one vitals row, note, photo,
  signature, and two visit events, generates a timesheet line from actual check-in/out times, and
  replays the same batch with no duplicates. Browser transport-level offline is deferred because
  Playwright is not installed; PWA Vitest covers encrypted Dexie/outbox offline persistence.

## Status

**Phase E COMPLETE.** P0E.G1 service agreements, P0E.G2 planned visits from RRULE recurrence,
P0E.G3 dispatcher assignment, P0E.G4 proof-of-visit, P0E.G5 nurse PWA encrypted day-pack sync,
P0E.G6 offline action queue/conflict policy, P0E.G7 offline visit execution, P0E.G8 incidents
and actual-timesheets, P0E.G9 dispatch agent proposals, and P0E.C airplane-mode consolidation are
registered with tests and app-layer audit/read logging.

## Open items

- Clinician countersigning for nurse observational visit notes is deferred.
