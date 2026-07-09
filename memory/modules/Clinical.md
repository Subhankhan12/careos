# Module: Clinical (`Modules\Clinical`)

## Purpose

Tenant-owned clinical record foundation. D.1 adds encounters: the clinical visit container that
links a patient, practitioner, branch, optional appointment, and future clinical artifacts.
D.2 adds structured SOAP clinical notes with legal-grade sign-and-lock immutability and
visible superseding amendments.

## Key tables

- `encounters` - tenant-owned (`BelongsToTenant`). ULID id, `tenant_id`, `patient_id`,
  `practitioner_id`, `branch_id`, nullable `appointment_id`, `type`, `started_at`, nullable
  `ended_at`, `status`, nullable administrative `reason_for_visit`, timestamps.
- `note_templates` - tenant-owned SOAP template prefills. ULID id, name, nullable specialty,
  default SOAP section text, JSON required sections, active flag, timestamps.
- `clinical_notes` - tenant-owned structured SOAP notes. ULID id, `encounter_id`, denormalized
  `patient_id`, staff `author_id`, SOAP text fields, nullable `template_id`, draft/signed status,
  signature fields, version, nullable `supersedes_id`, mandatory amendment reason when superseding.

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
- `Models\ClinicalNote` - tenant-owned, read-logged SOAP note; signed rows cannot be updated or
  deleted at model level.
- `Models\NoteTemplate` - tenant-owned template/policy for SOAP prefills and required sections.
- `Services\ClinicalNoteService` - save drafts, sign notes, create amendments, and resolve version
  chains; enforces `note.write` / `note.sign`.
- `Events\ClinicalNoteSigned` / `ClinicalNoteAmended` - consumed by app-layer audit glue as
  `note.signed` and `note.amended`.
- `Http\Controllers\ClinicalNoteShowController` - backend JSON read surface for note read-logging.

## Invariants enforced

- Encounters are tenant-owned and fail closed without `TenantContext`.
- Patient, practitioner, branch, and appointment references must be visible in the same tenant.
- Optional appointment must match the encounter patient and branch.
- Only one `open` encounter may exist per patient/practitioner at a time.
- Opening from an appointment crosses the Scheduling boundary through `AppointmentService` and
  results in appointment status `in_progress`.
- Encounter reads write audit `read` rows with `patient_id`; open/close write patient-scoped
  audit events and the chain verifies.
- Notes store structured SOAP sections only: subjective, objective, assessment, plan.
- Draft notes remain editable. Signed notes are immutable in Eloquent and by DB triggers that
  block raw UPDATE/DELETE only when `OLD.status = 'signed'`.
- Amendments never mutate originals; they create new draft rows with `version = old.version + 1`,
  `supersedes_id`, and a required reason.
- Template required sections are enforced on sign; missing required SOAP text blocks signing.
- Note reads write patient-scoped `read` rows. Signing/amending writes patient-scoped audit events.

## Status

**Phase D in progress.** D.1 encounters and D.2 SOAP notes are registered and covered by feature
and architecture tests. Local `composer check` is green: 186 tests / 800 assertions.

## Open items

- D.3 adds problems, allergies, vitals, medications, and deterministic allergy hard-stop.
