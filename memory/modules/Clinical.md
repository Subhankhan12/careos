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
- `Support\VitalsSeries` (P0P.G13) - PURE, no model deps: merges a flat reading list into a
  per-metric, time-ordered (most-recent-first), source-tagged (`clinic`|`visit`) series; a
  null/absent metric is absent from that metric series, never zero-filled. No interpretation.
- `Contracts\VisitVitalsReader` + `Services\VitalsHistoryService` (P0P.G13) - `VitalsHistoryService::
  forPatient(patientId, ?perMetricLimit)` returns the UNIFIED vitals series merging the Clinical
  `Vital` store with Nursing `visit_vitals` read through the `VisitVitalsReader` seam (impl
  `App\Clinical\NursingVisitVitalsReader` in the app layer, since Clinical may not import Nursing).
  Output is raw values only (`{recorded_at,value,source}`) — no bands/flags/scores/deltas. Consumed by
  the chart (`vitalsHistory` companion prop) and the nurse PWA day-pack (recent 5/metric).
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

## Structured clinical orders (P0P.G11, D-076)

- Labs/imaging orders: a clinician places a structured order, tracks a status lifecycle, records a
  MANUAL result, and marks it reviewed. The electric fence holds absolutely — the system records what
  is ordered and resulted and NEVER interprets (no range/flag/abnormal/colour/score anywhere; same
  posture as vitals D-D3). "Reviewed by {clinician}" is a HUMAN attestation, not a system judgment.
- Three tenant-owned tables: `orderable_items` (tenant-AUTHORED — no licensed catalog; `OrderableItemService`
  create/deactivate + `seedStarter()` seeds a small generic editable template FBC/UE/URINALYSIS/CXR/USS-ABDO),
  `orders` (patient/encounter/orderable_item/ordered_by/priority/clinical_note/status; statuses
  ordered→collected→in_progress / resulted / reviewed / cancelled), `order_results` (APPEND-ONLY: DB
  triggers block UPDATE/DELETE; raw `result_value` + optional `result_document_id` link to a D.4 document;
  source manual/imported, only manual used).
- `OrderService`: `place` (status ordered, transmits via LabConnectivity no-op, audited), `transition`
  (collected/in_progress/cancelled legal changes), `recordResult` (append-only result → status resulted;
  requires a value and/or a document), `markReviewed` (resulted→reviewed + reviewed_by/at — the human
  attests), `chartOrders` (patient-scoped read-logs order + each result), `toReview` (resulted-not-reviewed
  worklist, the unsigned-notes analogue). Audit via `ClinicalRecordChanged` (app-layer listener).
- **Transmission/ingestion is a STUB interface:** `Clinical\Contracts\LabConnectivity` with the ONLY impl
  `ManualLabConnectivity` (transmit = no-op, `ingestResult` throws — no live ingestion). Bound in
  `ClinicalServiceProvider::register`. No HL7/FHIR client, no network. Real lab connectivity is DEFERRED
  partner work (see DEFERRED.md; trigger = a customer's specific lab + a funded integration build).
- RBAC `order.manage` (org_admin/doctor/nurse; reception refused). Net-new UI (additive, presentational):
  chart Orders tab (place/result/review; results shown RAW), `Clinical/OrdersReview.vue` worklist,
  `Clinical/OrderableItems.vue` catalog admin. No existing chart prop removed.

## Clinical dot-phrases / quick-text macros (P0P.G10, D-077)

- Reusable text snippets a clinician expands while writing SOAP notes: PERSONAL (private to the author)
  or SHARED (tenant-wide, admin-managed). Pure internal text expansion — NO clinical interpretation, NO AI.
- `text_snippets` (BelongsToTenant): scope personal/shared, `owner_staff_id` (set for personal, null for
  shared), `trigger` (bare token — the '.' is UI sugar; normalized lowercase alnum), title, body, specialty,
  active. Unique `(tenant, scope, owner_staff_id, trigger)` binds PERSONAL (per-owner); SHARED uniqueness is
  service-enforced (MySQL NULL owner is distinct).
- `SnippetService`: `resolveFor(staff, trigger)` — PERSONAL wins over SHARED (documented precedence);
  `list(staff)` — the clinician's personal + all active shared (never another clinician's personal);
  `expand(snippet, context)` — substitutes ONLY the FIXED whitelist `PLACEHOLDERS` (date, patient_first_name,
  patient_dob, clinician_name, branch_name), leaving any other token LITERAL. It iterates the whitelist keys,
  never the caller's context keys, so a diagnosis/medication/allergy/vital/any clinical field is
  STRUCTURALLY impossible to substitute (tested with poisoned clinical context — nothing leaks). CRUD:
  personal editable only by its owner; shared requires `snippet.manage.shared` (org_admin + doctor as the
  clinical-lead role). Shared changes audited (`snippet.shared.*`, patient_id null — snippets are NOT
  patient data); personal lightly logged.
- Editor integration is ADDITIVE: `NoteEditor.edit()` passes a NEW optional `snippets` prop (the current
  clinician's list, pre-expanded server-side with the whitelist context) + a snippet insert control; the
  component only renders/inserts. NO existing NoteEditor prop or behavior changed. Net-new
  `Clinical/Snippets.vue` management page (RBAC `note.write`; shared editing gated). i18n keys.

- Vitals DISPLAY: weight/height are stored in base units (grams / millimetres) but rendered in clinical
  units (kg / cm) via the display-only helper `resources/js/lib/units.ts` (`vitalDisplayValue`) — weight ÷1000
  1dp, height ÷10 0dp; every other metric (mmHg/bpm/°C/%) is already conventional and passes through raw. The
  chart Vitals tab + P.13 trend both use it. Storage NEVER changes; convert only at render, still raw with no
  interpretation (electric fence holds). M-3 / FIX.4, see [[D-092]].

## Allergy record-display + the medication-safety seam shell (ALLERGY.P1)

The allergy wireframe's SAFE parts. A recorded `source` field (additive migration `2026_08_27_000001`, nullable)
joins the existing recorded facts (`substance`/`reaction`/`severity` [recorded enum]/`verified_at`) on
`Models\Allergy`; `ClinicalChartController` surfaces `source`/`recorded_at`/`verified_at`; the new
`resources/js/Components/AllergyRecordPanel.vue` (on the chart, beside `AllergyBanner`) renders a per-allergy
RECORD card — recorded severity shown as a FACT, not a computed grade (record-not-judge). It also renders a
DISPLAY-ONLY region wired to the `MedicationSafetyProvider` seam: the controller reports
`medicationSafety.providerConfigured` (`false` today — the bound provider is `NullMedicationSafetyProvider`) +
`advisories: []`, so the region shows the honest "no automated checking configured" state (zero controls — cannot
block/compute/suggest). **THE FENCE (permanent non-goal):** computed drug-allergy cross-reactivity / drug-class
match / contraindication / auto-block / therapeutic substitution is a certified-partner MEDICAL-DEVICE function,
NEVER built homemade. The only allergy block is the pre-existing deterministic exact-match `AllergyGuard`
(unchanged; a Penicillin allergy does NOT trip for Amoxicillin — no cross-reactivity). Locked by
`tests/Feature/Clinical/AllergyAlertDisplayTest.php` (6). See `docs/wireframe-parity/ALLERGY-ALERT-DIFF.md`.

## Open items

- Next phase: Phase E - Nursing wedge (home care, dispatch, offline-first nurse PWA).
- Real HL7/FHIR lab connectivity is deferred (partner-driven; see DEFERRED.md).
- Computed drug-allergy safety (cross-reactivity / class-match / contraindication / substitution) is a
  certified-partner medical-device NON-GOAL — the `MedicationSafetyProvider` seam awaits a licensed partner.

## PC.P2 — Patient Chart visual parity (2026-08-21, `de55f43`)

Second gate of the **PC chain**. Visual parity over the EXISTING chart backend. **P0D.GU.**

**WHAT THE PAYLOAD ALREADY CARRIED** (so most of this gate was completion, not construction):
allergies, the medication-safety seam's honest "not configured" state, vitals **and** a unified
`vitalsHistory` ("raw values only (no bands/flags/scores/deltas)"), medications, documents,
carePlans, referrals, recalls, orders, and — already correct — the AI summary panel over
`ClinicalSummaryTool` (`autonomyCeiling: SUGGEST`, "ABSOLUTE CONSTRAINT: EXTRACTIVE"), plus **full
note version chains** with `amendment_reason` and a per-version `edit_url`, so v1 was always
reachable. Tabs, month grouping and encounter type filters existed too.

**THE REAL DEFECT THIS GATE FIXED: the counts were `array.length` in Vue.** That is not a
cosmetic difference. Several of the lists are DELIBERATELY PARTIAL — `notes` carries head
versions only (superseded ones live in the version chain) and `orders` is empty for an actor who
may not see them — so counting the loaded array **under-reports the record**. Counts are now
computed SERVER-side from real rows (`counts.encounters/notes/problems/vitals/medications/
documents/orders/referrals/openRecalls`), and `notes`/`orders` deliberately mirror their lists so
a chip can never disagree with what is under it. The now-superseded client-side `openRecalls`
computed was DELETED rather than left beside it — a second, divergent source of the same number
is the bug I had just removed.

**BUILT:** find-in-chart (a plain case-insensitive substring match over content ALREADY LOADED —
it fetches nothing, ranks nothing, scores no relevance, reorders nothing, and says so on screen);
recall proximity as **a plain calendar interval** (`due_in_days`, computed server-side on whole
days, negative when past due) rendered as "due in N days" / "N days past due" / "due today".

**THE FENCE, unchanged and re-asserted:** vitals stay RAW — no band, flag, score, delta, trend,
arrow or sparkline, and nothing on the page is styled from a vital's value or a numeric threshold
(D-169); the summary stays EXTRACTIVE at SUGGEST with per-line source chips and ONE explicit human
insert (no new tool, no raised ceiling, no auto-insert); no risk score, acuity, EWS, prognosis,
auto-problem-list or cross-reactivity anywhere; nothing draws (D-172).

**Tests added** — `tests/Feature/Clinical/ChartParityTest.php` (5 tests, 181 assertions).
**Mutation-checked four ways:** a recall row tinted by proximity, a `'band' => 'high'` key in the
vitals payload, the summary tool's ceiling raised to APPROVE, and an auto-insert call site.

**I SHIPPED A FALSE GREEN AND CAUGHT IT.** The vitals re-assertion passed the `band` mutation
because my fixture recorded **no vitals** — an absence assertion over an EMPTY collection is
vacuously true. The test now records two real vitals (including a frankly abnormal 176/104, SpO2
91) and asserts `toHaveCount(2)` before scanning, then asserts the abnormal row's keys are exactly
the recorded fields — so the fence is proven against data that would *tempt* an annotation. Re-run
against the mutation, it now fails as it should.

**Four fixture bugs from reading signatures rather than guessing:** `RecallRule` needs `criteria`
and uses `interval_months` (not `interval_days`); `Encounter` fails closed without a
`practitioner_id`; `Medication` requires `substance_key`; and the note service method is
`saveDraft(encounter, author, sections, actor)` with `sign(note, user)` and
`amend(note, changes, reason, author, actor)` — not the `create(...)` I assumed.

## PC.P3 — the shared clinical header now serves two surfaces (2026-08-21)

`Components/Clinical/PatientClinicalHeader.vue` (S1) is consumed by **five** pages: the four dental
surfaces (compact) and Patient 360 (hero). It was **extended, not forked** — `status`, `links`,
`variant`, `initials` are all optional and `compact` is the default, and the hero adds only
absolutely-positioned decoration, so the compact DOM is byte-identical. **Change it with that in
mind:** a structural edit inside the shared body changes all five pages at once.

It stays **purely presentational** — it parses no date, derives no age, and its allergy chips carry
**one constant class string** whatever the recorded severity (D-169). It deliberately has **no `flag`
prop**: nothing in CareOS records a patient flag, and a prop would invite the next author to compute
one (D-176). The fence test scans this file by name and was mutation-checked.

## PC.P4 — the Note Editor (2026-08-21)

**THE EDITOR HAS NO AGENT, AND THAT IS DELIBERATE (D-177).** There is no rephrase, autocomplete or
note-authoring tool anywhere in CareOS — ten AiCore tools, none touching note prose — and the
wireframe draws none either. `ClinicalSummaryTool` is EXTRACTIVE at a SUGGEST ceiling and belongs on
the CHART; it was deliberately **not** duplicated onto the authoring page, because a
content-producing affordance beside the note body is exactly what this screen must not have. If a
future gate proposes an assist panel here, that is a NEW capability decision, not parity work.

**What protects the note is the WRITE SURFACE, not the call sites.** A mutation that added a new
watcher assigning straight into a SOAP section passed the suite green, because the guard counted
`insertSnippet` call sites. The test now pins **one watcher on the page and exactly one assignment
into a section**. Keep it that way: if you add a watcher, the fence test will (correctly) go red, and
the fix is to justify the write, not to raise the count.

**The lifecycle is unchanged and must stay so:** `saveDraft` (drafts only) → `sign` (`note.sign`,
records who + when, re-validates required sections) → `amend` (signed only, reason required, creates
a NEW version at version+1 that is itself a DRAFT). Signed notes are immutable at model AND trigger
level. The PC.P4 additions — the superseded banner and the chain payload — are **display only**; they
re-implement nothing and bypass nothing.

**Flagged, not changed:** the mock wants template prefill to render placeholder-style and never
autosave as content; live applies template defaults as real content at creation. Changing that is a
behaviour change to an existing tested path — it needs its own gate.

## PC.P6 — Referral Out (2026-08-21)

**`ReferralService::send()` DOES NOT TRANSMIT ANYTHING.** It sets `status = 'sent'` and `sent_at`.
There is no channel, no message, no document and no integration anywhere in the repo — the service
is the only file that touches referrals. The UI therefore says **"Mark as sent"**, never "Send",
and states on screen that the clinician must send it through their own secure channel (D-179). If
you wire a real channel, that is a NEW capability with its own gate — and the wording must change
with it, or the screen starts lying in the other direction.

**The referral surface writes no state.** `ReferralController` calls the service for every
transition and contains no `save`/`update`/`forceFill`; a test pins that. The service owns
draft → sent → accepted|declined → completed and re-checks `note.write` on every call — which
surfaces as **403** (AuthorizationException), not 500, while an out-of-order transition is an
InvalidArgumentException and surfaces as 500.

**Four things the wireframe draws that do NOT exist — do not add them without a backend:**
urgency (no column; inventing it also hands the UI a value to rank and tint by), a shared/withheld
document packet (no attachment relation), a provider directory (`to_provider_name` is FREE TEXT),
and a referral agent (no tool touches referrals). The mock's Received / Appt-booking / Report-back
tracking states are equally unbacked; the five real states are what render.

**Opening the screen writes exactly ONE read row** (`surface: referrals`), not one per referral, so
it lands in the patient's access log (PC.P5). Keep it to one: the count is asserted.

## PC.P7 — the recall due list (2026-08-22) — PC batch core complete

**`ORDER BY due_on ASC` IS THE WHOLE FENCE ON THIS SCREEN.** The longest-overdue row leads because
its recorded date is earliest — not because anything scored it. Keep it a date sort: no priority or
urgency score, no overdue band, no likelihood-of-non-attendance, and above all **no `:class` keyed
to `due_in_days`** (D-180/D-169). Every row across a -200…+120 spread must render with one class
string; a test and a browser check both pin it. `recalls` has **no priority column** — that schema
fact is asserted, so adding one would break the suite loudly.

**Why wiring the recall agent here was safe: the CEILING, not the UI.** `clinical.draft_recall_message`
is capped at **SUGGEST**, so `AgentRuntime::runTool()` can only reach `propose()` — auto-send is
structurally unreachable. If anyone ever raises that ceiling, this screen silently becomes an
auto-contact surface; the ceiling is the guarantee, so treat a change to it as a security change.
The tool refuses medical advice, blocks without `comms.email` consent, and never sets `sent`.

**`FollowUpAgent::draftRecallMessage()` had no caller before this gate** — the tool, the wrapper and
the registry entry existed but nothing invoked them. Worth remembering when auditing agent surface:
a registered tool is not necessarily a reachable one.

**Audit granularity on a MULTI-patient screen:** the one-row-per-render rule (PC.P1/P5/P6) assumes a
single patient. This worklist writes one `auditRead()` row **per patient shown** — same mechanism,
correct granularity — otherwise most of those patients' access logs would never record the
disclosure. Do not "optimise" it back to one row.

**Completing a recall books nothing.** `RecallService::transition()` only moves the status; no
scheduling path is touched from this screen, and a test asserts the controller cannot reach one.


### QA-FIX.2a — a note is authored by whoever wrote it (2026-09-05, closes `P2-C1`)

**The defect, measured in a browser:** logged in as Dr. Brunner, clicking **Document** on an
appointment booked with Dr. Keller and signing produced `author_id` = **Keller**, `signed_by` =
**Brunner**, audit `actor_id` = **Brunner** — and the screen said **"Signed · Dr. med. Sofia
Keller"**. Brunner wrote every word.

**THE CAUSE WAS ONE ARGUMENT.** `OpenEncounterFromAppointmentController` resolved the appointment's
practitioner once and passed the same value to both calls:

```php
$practitioner = $this->practitionerForAppointment($appointment);   // Dr. Keller
$encounter = $encounters->open($patient, $practitioner, ...);      // RIGHT — whose visit
$note = $notes->saveDraft($encounter, $practitioner, [], $actor);  // WRONG — becomes author_id
```

`ClinicalNoteService:69` writes `'author_id' => $author->id` from that second argument. `$actor` was
already being passed alongside it — for `note.write` authorisation and the audit row — so the
correct value was in scope the whole time.

**TWO QUESTIONS, TWO ANSWERS.** *Whose visit is this?* → the ENCOUNTER, legitimately the booked
clinician, **left untouched**; a test asserts `encounter->practitioner_id` still equals the
appointment's practitioner, so the fix is provably surgical. *Who wrote this down?* → the NOTE, now
the authenticated user.

**`StaffProfile::forUser()` is the one place that answers "who is acting?"** and it **returns null
rather than guessing**. Callers refuse. This matters because the repo already contained the opposite
pattern — `ImagingReportController:160` falls back to `StaffProfile::query()->orderBy('display_name')
->firstOrFail()`, i.e. attributes the report to an arbitrary clinician — and copying it would have
re-created the very defect.

**THE AMENDMENT PATH HAD THE SAME BUG.** `NoteEditorController::amend()` passed
`authorFor($record)` — the *superseded* version's author — so a correction written by Dr. B was
recorded as Dr. A's. An amendment is a new version; its author is whoever wrote it. The original
keeps its own author, which is what the chain is for.

**THE SIGNATURE NAMES THE SIGNATORY.** `NoteEditor.vue` rendered `author_name` under a "Signed ·"
label. It now uses `signed_by_name`, resolved from `signed_by`. **Author ≠ signatory is a real
state, not a hypothetical** — the seeded radiology reports are authored by Dr. Lang and signed by
Dr. Berg — so when they differ the view names **both** ("Written by X · Signed by Y · date").
Comparing them crosses the namespace split: the author's `staff_profiles.user_id` vs `signed_by`.

**TWO FEATURES SILENTLY REPAIRED, found during the study and not touched:**
`UnsignedNotesWorklist:24-29` builds "my unsigned notes" from the actor's staff profiles, so a note
Brunner wrote sat in **Keller's** worklist; `ClinicalSummaryInsertController:55-65` looks for "the
draft authored by the current clinician" and therefore **could never match** a Document-created note,
silently breaking summary-insert on that path.

**WHY HISTORY IS NOT REWRITTEN (D-197):** `ClinicalNote::updating` throws *"Signed clinical notes are
immutable"* and `deleting` blocks signed notes, so a correction cannot go through the model; and the
true author is **not in the note row** — it is recoverable only by reconstruction from the audit
chain. Measured scope: 24 notes, four tenants, earliest 2026-08-25.

**Guarded by** `tests/Feature/Clinical/NoteAuthorshipTest.php` (8). **Every fixture makes the actor
and the appointment's practitioner different people and asserts that they are** (D-174) — the
pre-existing `ClinicalUiTest` fixtures use `d7Practitioner($branch, $doctor)`, so A == B and they
passed either way. That vacuity is why the defect survived; it is the lesson worth keeping.
Mutation-checked: old authorship → 4 red; old amendment inheritance → the amendment test red alone.

**Deliberately not changed:** `BedsideChartService:66,77` (author = the stay's admitting clinician)
and `SurgicalCaseService:166,178` (author = the case's primary surgeon) share the shape but sit
outside the audited surface and could not be browser-verified here — see `DEFERRED.md`, and note the
operative note may be *meant* to carry the responsible surgeon. `EdDocumentationService` takes a
user-chosen `practitioner_id` (an explicit assignment, not an inference).
