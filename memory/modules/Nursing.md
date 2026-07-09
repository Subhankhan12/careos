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
  `assigned_resource_id`, nullable cancellation reason. Unique
  `(tenant_id, visit_plan_id, scheduled_date)` and indexed by `(tenant_id, scheduled_date,
  status)`.

## Key services / classes

- `Providers\NursingServiceProvider` - loads Nursing migrations.
- `Models\ServiceAgreement` - tenant-owned/read-logged agreement; guards patient, branch, and
  creating staff user references in the current tenant.
- `Models\AgreementService` - tenant-owned child row; guards same-tenant service agreement and
  Scheduling service catalog references.
- `Services\ServiceAgreementService` - creates agreements with services, reads with patient-scoped
  read logging, and enforces lifecycle transitions.
- `Models\VisitPlan` and `PlannedVisit` - tenant-owned recurrence plans and generated visits with
  same-tenant agreement/service/patient/resource guards.
- `Services\VisitPlanGenerator` - expands RRULEs through Recurr, materializes concrete visits
  idempotently with upsert, stores UTC windows, and cancels single occurrences without changing
  the rule.
- `Console\MaterializeVisitsCommand` - `nursing:materialize-visits`, horizon-based idempotent
  materialization command for active tenants/plans.
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

## Status

**Phase E IN PROGRESS.** P0E.G1 service agreements and P0E.G2 planned visits from RRULE
recurrence are registered with tests and app-layer audit.

## Open items

- Later Nursing gates add dispatch, EVV/offline PWA surfaces, and operational
  workflows on top of agreements.
