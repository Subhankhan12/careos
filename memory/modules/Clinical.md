# Module: Clinical (`Modules\Clinical`)

## Purpose

Tenant-owned clinical record foundation. D.1 adds encounters: the clinical visit container that
links a patient, practitioner, branch, optional appointment, and future clinical artifacts.

## Key tables

- `encounters` - tenant-owned (`BelongsToTenant`). ULID id, `tenant_id`, `patient_id`,
  `practitioner_id`, `branch_id`, nullable `appointment_id`, `type`, `started_at`, nullable
  `ended_at`, `status`, nullable administrative `reason_for_visit`, timestamps.

## Key services / classes

- `Models\Encounter` - tenant-owned, read-logged, same-tenant guarded references to patient,
  staff profile, branch, and optional appointment.
- `Services\EncounterService` - opens/closes encounters, enforces `encounter.manage`, rejects
  cross-tenant references, guards one open encounter per patient/practitioner, and transitions
  appointments via Scheduling `AppointmentService`.
- `Events\EncounterOpened` / `EncounterClosed` - consumed by app-layer audit glue as
  `encounter.opened` and `encounter.closed`.
- `Http\Controllers\EncounterShowController` - backend JSON read surface; authorizes before
  disclosure and writes a patient-scoped read audit row.

## Invariants enforced

- Encounters are tenant-owned and fail closed without `TenantContext`.
- Patient, practitioner, branch, and appointment references must be visible in the same tenant.
- Optional appointment must match the encounter patient and branch.
- Only one `open` encounter may exist per patient/practitioner at a time.
- Opening from an appointment crosses the Scheduling boundary through `AppointmentService` and
  results in appointment status `in_progress`.
- Encounter reads write audit `read` rows with `patient_id`; open/close write patient-scoped
  audit events and the chain verifies.

## Status

**Phase D in progress.** D.1 encounters are registered and covered by feature and architecture
tests. Local `composer check` is green: 176 tests / 757 assertions.

## Open items

- D.2 adds SOAP notes, signing/locking, and amendments.
