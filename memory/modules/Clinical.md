# Module: Clinical (`Modules\Clinical`)

## Purpose

Tenant-owned clinical record foundation. D.1 adds encounters: the clinical visit container that
links a patient, practitioner, branch, optional appointment, and future clinical artifacts.
D.2 adds structured SOAP clinical notes with legal-grade sign-and-lock immutability and
visible superseding amendments. D.3 adds structured clinical lists and a deterministic allergy
hard-stop. D.4 adds private clinical documents with portal sharing and per-download audit. D.7
adds the clinical SOAP/chart UI surfaces without moving business rules into Vue components.

## Key tables

- `encounters` - tenant-owned (`BelongsToTenant`). ULID id, `tenant_id`, `patient_id`,
  `practitioner_id`, `branch_id`, nullable `appointment_id`, `type`, `started_at`, nullable
  `ended_at`, `status`, nullable administrative `reason_for_visit`, timestamps.
- `note_templates` - tenant-owned SOAP template prefills. ULID id, name, nullable specialty,
  default SOAP section text, JSON required sections, active flag, timestamps.
- `clinical_notes` - tenant-owned structured SOAP notes. ULID id, `encounter_id`, denormalized
  `patient_id`, staff `author_id`, SOAP text fields, nullable `template_id`, draft/signed status,
  signature fields, version, nullable `supersedes_id`, mandatory amendment reason when superseding.
- `problems` - tenant-owned problem list entries with `patient_id`, nullable `encounter_id`,
  description, nullable free code, onset/status/recorded/resolved fields.
- `allergies` - tenant-owned allergy list entries with documented substance, normalized
  `substance_key`, reaction/severity/status, recorded/verified fields, and an index on
  `(tenant_id, patient_id, substance_key, status)`.
- `vitals` - tenant-owned raw measurements with explicit-unit columns (`temperature_c`,
  `weight_g`, `height_mm`) plus `extra`; no interpretation/flag/score fields.
- `medications` - tenant-owned documented medications with normalized `substance_key`,
  free-text dose/route/frequency, dates, status, recorder, and audit/read logging.
- `documents` - tenant-owned clinical document metadata with `patient_id`, nullable
  `encounter_id`, category/title/original filename, private `storage_path`, MIME/size,
  uploader/upload timestamp, portal share flags, timestamps, and soft deletes.

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
- `Models\Problem`, `Allergy`, `Vital`, `Medication` - tenant-owned/read-logged clinical-list
  rows with same-tenant patient/encounter/recorder guards.
- `Services\AllergyGuard` - exact normalized `substance_key` equality check against active
  documented allergies; throws on conflict.
- `Services\ClinicalListService` - records problems, allergies, and vitals, and read-logs all
  four clinical lists for a patient.
- `Services\MedicationService` - records medications through the allergy hard-stop; override
  requires `allergy.override` plus a non-empty reason.
- `Events\ClinicalRecordChanged` - app-layer audit glue writes clinical-list change events.
- `Models\Document` - tenant-owned/read-logged document metadata; same-tenant patient,
  optional encounter, and uploader guards.
- `Services\DocumentService` - validates uploads, stores bytes under generated per-tenant private
  paths, shares/unshares for portal access, soft-deletes metadata, and resolves portal-visible
  documents.
- `Events\DocumentChanged` - app-layer audit glue writes document upload/share/unshare/delete.
- Document controllers - staff upload/download/share/unshare/delete and portal list/download
  endpoints; all access streams through controllers, never public URLs.
- `Http\Controllers\NoteEditorController` - Inertia note editor surface; server-enforces
  `note.write`/`note.sign`, saves drafts, signs, and starts amendments through
  `ClinicalNoteService`.
- `Http\Controllers\ClinicalChartController` - Inertia patient chart surface; authorizes
  `patient.view`, read-logs the chart view, and returns encounters, notes/version history,
  allergies, raw vitals, medications, documents, and empty later-domain sections.
- `Http\Controllers\OpenEncounterFromAppointmentController` - day-board integration that opens an
  encounter and draft note through Clinical/Scheduling services, then redirects to the note editor.
- Vue pages: `resources/js/pages/Clinical/NoteEditor.vue` and
  `resources/js/pages/Clinical/Chart.vue`.
- Vue components: `SoapEditor`, `VersionHistory`, `AllergyBanner`, and `Timeline` are
  presentational only.

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
- Problems, allergies, vitals, and medications are tenant-owned and fail closed without
  `TenantContext`; references are same-tenant guarded.
- Vitals store raw documented values only; no interpretation/ranges/flags/scores/derived values.
- Medication recording is blocked by `AllergyGuard` only on exact normalized active allergy
  `substance_key` equality. No fuzzy matching, drug-class inference, interaction checking, or
  dosing logic exists.
- Allergy hard-stop overrides require `allergy.override` and a non-empty reason and write
  patient-scoped `allergy.override` audit rows flagged as overrides.
- Clinical-list writes require clinician write permission (`note.write`). Reads through
  `ClinicalListService::readListsForPatient()` write patient-scoped `read` audit rows.
- Document storage paths are generated from tenant ID, patient ID, and ULID under private local
  storage; sanitized original filenames are metadata only and never drive storage paths.
- Staff document downloads require `patient.view`; uploads/share/unshare/delete require
  `note.write`. Portal users only see documents explicitly shared with their own patient account.
- Sharing requires an active `portal.access` consent via `ConsentService::has()`; no consent means
  fail-closed.
- Upload/share/unshare/delete write patient-scoped document audit events. Every staff or portal
  download writes a patient-scoped `read` audit row for resource `document`.
- Clinical UI routes remain server-enforced: `patient.view` for chart/note display, `note.write`
  for drafts/amendments, and `note.sign` for signing. Vue components may hide actions but do not
  own authorization, validation, or state transitions.
- Signed notes are returned read-only in the note editor response, and the update route rejects
  later edits even if a client sends the request directly.
- Amendment history returns the full original-to-latest version chain; the original remains
  visible.
- Chart views write patient-scoped read audit rows. Allergy data is first-class and prominent in
  the response/UI; vitals props carry raw documented values only, with no flags, scores, ranges,
  or interpretation fields.
- Day-board -> Document opens an encounter plus draft note and redirects to the note editor; the
  observed open -> document -> sign path is 3 clicks.

## Status

**Phase D in progress.** D.1 encounters, D.2 SOAP notes, D.3 clinical lists/allergy hard-stop,
D.4 clinical documents, and D.7 clinical UI are registered and covered by feature and architecture
tests. Local `composer check` is green: 205 tests / 1013 assertions. Local `cmd /c npm run build`
is green for the clinical pages.

## Open items

- Execute only the next pasted Phase D gate; D.5/D.6 are not present in this repo unless separately
  pasted and completed.
