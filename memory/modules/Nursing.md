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
- `Events\ServiceAgreementChanged` - app-layer audit glue records `service_agreement.*` actions.
- `Events\PlannedVisitChanged` - app-layer audit glue records `planned_visit.materialized` and
  `planned_visit.cancelled`.

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

## Status

**Phase E IN PROGRESS.** P0E.G1 service agreements, P0E.G2 planned visits from RRULE recurrence,
and P0E.G3 dispatcher assignment are registered with tests and app-layer audit.

## Open items

- Later Nursing gates add EVV/offline PWA surfaces and operational workflows on top of agreements.
