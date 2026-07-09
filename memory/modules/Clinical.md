# Module: Clinical (`Modules\Clinical`)

## Purpose

Tenant-owned clinical record foundation. D.1 adds encounters: the clinical visit container that
links a patient, practitioner, branch, optional appointment, and future clinical artifacts.
D.2 adds structured SOAP clinical notes with legal-grade sign-and-lock immutability and
visible superseding amendments. D.3 adds structured clinical lists and a deterministic allergy
hard-stop. D.4 adds private clinical documents with portal sharing and per-download audit. D.5
adds referrals and deterministic recalls. D.6 adds care plans, clinical tasks, and unsigned-note
worklists. D.7 adds the clinical SOAP/chart UI surfaces without moving business rules into Vue
components. D.8 adds governed clinical agents through app-layer AiCore integration.

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
- `referrals` - tenant-owned patient referrals with nullable encounter, inbound/outbound
  direction, external provider names, nullable same-tenant `to_branch_id`, specialty, documented
  reason, lifecycle status, sent/responded timestamps, and notes.
- `recall_rules` - tenant-owned deterministic recall rules with JSON criteria, interval months,
  and active flag.
- `recalls` - tenant-owned patient recalls with rule FK, due date, and due/contacted/booked/
  completed/dismissed status.
- `care_plans` - tenant-owned patient care plans with title, active/completed/cancelled status,
  started/ended dates, creator, and timestamps.
- `care_plan_goals` - tenant-owned goals attached to a care plan with clinician-authored
  description, nullable target date, and open/met/not_met status.
- `clinical_tasks` - tenant-owned tasks with nullable patient/care-plan/encounter links, title,
  nullable description, same-tenant staff assignee, due date, priority, status, completion time,
  and index `(tenant_id, assigned_to, status, due_at)`.

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
- `Models\Referral`, `RecallRule`, `Recall` - tenant-owned referral/recall rows; referrals and
  recalls are read-logged patient data, recall rules are tenant policy/configuration.
- `Services\ReferralService` - creates referrals and enforces draft -> sent -> accepted/declined
  -> completed lifecycle; writes referral audit events.
- `Services\RecallEngine` - deterministic tenant evaluator for active recall rules over patient,
  problem, and encounter data; creates idempotent due recall rows.
- `Services\RecallService` - enforces recall due/contacted/booked/completed/dismissed lifecycle
  and writes recall audit events.
- `Models\CarePlan`, `CarePlanGoal`, `ClinicalTask` - tenant-owned care plan/task records with
  same-tenant reference guards; care plans/tasks are read-logged patient data when applicable.
- `Services\CarePlanService` - creates plans/goals and enforces active -> completed/cancelled plan
  transitions plus open -> met/not_met goal transitions; writes clinical audit events.
- `Services\ClinicalTaskService` - creates tasks, guards assignment and compatible patient links,
  enforces open/in_progress/done/cancelled lifecycle, and writes clinical audit events.
- `Services\UnsignedNotesWorklist` - returns draft notes older than a threshold, ordered by age;
  clinicians see their own drafts, `note.supervise` users see tenant-team drafts.
- `Http\Controllers\NoteEditorController` - Inertia note editor surface; server-enforces
  `note.write`/`note.sign`, saves drafts, signs, and starts amendments through
  `ClinicalNoteService`.
- `Http\Controllers\ClinicalChartController` - Inertia patient chart surface; authorizes
  `patient.view`, read-logs the chart view, and returns encounters, notes/version history,
  allergies, raw vitals, medications, documents, care plans, referrals, recalls, and an optional
  AI summary draft prop.
- `Http\Controllers\OpenEncounterFromAppointmentController` - day-board integration that opens an
  encounter and draft note through Clinical/Scheduling services, then redirects to the note editor.
- App-layer `ClinicalSummaryDraftController` / `ClinicalSummaryInsertController` compose Clinical
  with AiCore: draft generation runs through AiCore and insertion is an explicit clinician action
  into an editable note after source validation.
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
- Referrals require `note.write`, stay tenant-owned, and never widen scope for cross-tenant
  exchange. Internal referrals use same-tenant `to_branch_id`; external referrals are documented
  provider-name records until explicit share objects exist.
- Referral lifecycle is draft -> sent -> accepted/declined; accepted referrals may become
  completed. Created/sent/responded/completed actions are audited.
- Recall rules are deterministic: supported criteria are exact active problem-code membership and
  exact absence of an encounter type within `interval_months`. No AI, inference, triage, or
  clinical judgement selects recall recipients.
- Recall generation is idempotent per tenant/patient/rule/due date. Recall lifecycle is
  due -> contacted/booked/completed/dismissed; contacted -> booked/completed/dismissed; booked ->
  completed/dismissed; completed/dismissed are terminal.
- Chart views now return and read-log referrals and recalls as real patient data.
- Care plans/goals/tasks are clinician-authored storage only; no generated clinical content.
- Care plan and task writes require `note.write`; task assignees must be same-tenant staff.
- Care-plan status transitions are active -> completed/cancelled only. Goal transitions are
  open -> met/not_met only. Clinical-task transitions are open -> in_progress/done/cancelled and
  in_progress -> done/cancelled; done/cancelled are terminal.
- `note.supervise` is the supervisor boundary for unsigned-note worklists; without it, users only
  see aged draft notes authored by their own staff profile.
- Clinical UI routes remain server-enforced: `patient.view` for chart/note display, `note.write`
  for drafts/amendments, and `note.sign` for signing. Vue components may hide actions but do not
  own authorization, validation, or state transitions.
- Signed notes are returned read-only in the note editor response, and the update route rejects
  later edits even if a client sends the request directly.
- Amendment history returns the full original-to-latest version chain; the original remains
  visible.
- Chart views write patient-scoped read audit rows and now return real care plans with goals,
  referrals, and recalls. Allergy data is first-class and prominent in the response/UI; vitals
  props carry raw documented values only, with no flags, scores, ranges, or interpretation fields.
- Day-board -> Document opens an encounter plus draft note and redirects to the note editor; the
  observed open -> document -> sign path is 3 clicks.
- D.8 Summary agent reads only the requested patient's signed notes, problems, medications, and
  vitals in range; every returned line carries a source resolving to that patient's real row/field.
- Summary agent refuses interpretive/diagnostic/triage requests and never writes to the clinical
  record. Clinician insertion is a separate server-side action and revalidates all sources.
- D.8 Follow-up agent drafts wording only for D.5 recall recipients selected by deterministic
  rules; no recipient selection, advice, symptom guidance, or urgency inference lives in the agent.
- D.C full consult loop test proves day-board -> open encounter -> SOAP draft -> sign -> chart
  signed note -> amend with reason -> chart shows both versions -> audit chain verifies.

## Status

**Phase D COMPLETE.** D.1 encounters, D.2 SOAP notes, D.3 clinical lists/allergy hard-stop,
D.4 clinical documents, D.5 referrals/recalls, D.6 care plans/tasks/worklist, D.7 clinical UI,
D.8 clinical agents, and D.C full-loop consolidation are registered and covered by feature and
architecture tests. Local `composer check` is green: 222 tests / 1202 assertions. Local
`cmd /c npm run build` is green for the clinical pages.

## Open items

- Next phase: Phase E - Nursing wedge (home care, dispatch, offline-first nurse PWA).
