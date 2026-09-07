# Module: ED (`Modules\ED`)

## Purpose

The Emergency Department vertical — **Phase 6** of the phased hospital build (Phase 1 = inpatient/ADT,
Phase 2 = pharmacy, Phase 5 = surgery, all complete). Planned as ~6 gates
(`docs/HOSPITAL-PHASE6-ED-MAP.md`): the module + the NET-NEW `EdVisit` patient-flow entity + ED RBAC + the
empty triage-acuity SEAM (G1) → triage record with the nurse-**assigned** acuity (G2) → the tracking board
(G3) → ED clinical documentation reusing Clinical (G4) → disposition + the ED→ADT handoff (G5) → ED billing
(G6). **PHASE 6 IS NOW COMPLETE (G1→G6).** An ED runs end-to-end: arrival → triage → board → documentation →
disposition (incl. admit → an inpatient `Stay` via the reused `AdmissionService`) → billing
(reconciles-to-the-unit, incl. the composite emergency→inpatient episode on one invoice). ED inherits the whole
tested platform (tenancy, patients, people, clinical, billing, audit, RBAC, the electric fence). Peer module,
mirroring `Modules\Surgery` / `Modules\Pharmacy`.

## The EdVisit decision (G1 — the "own flow-entity" crux)

Per the map §2.1, an ED presentation is **NEITHER** a Clinical `Encounter` (a single-sitting,
one-open-per-practitioner visit — an ED presentation has an arrival→triage→treatment→disposition FLOW) **NOR**
an inpatient `Stay` (an inpatient episode with a bed — MOST ED visits discharge home and never become one). So
the ED visit is a **NET-NEW `EdVisit`** flow entity — the Bed/`Stay`/`SurgicalCase` "own flow-entity above a
reused primitive" discipline. ED clinical documentation will REUSE `Encounter` later (G4); the visit itself is
its own entity.

- **`EdVisit`** (`BelongsToTenant`, `LogsReads`, tenant + branch scoped) — the mutable CURRENT state: patient,
  branch, `arrived_at`, `arrival_mode` (walk_in/ambulance/referral — an operational route), `chief_complaint`
  (free text recorded at arrival), `status`, nullable `disposition` (admit/discharge/transfer — recorded at
  the end; the G5 handoff detail) + `dispositioned_at`. **`status` is out of `$fillable`** — it moves only
  through the legal-only transition machine (`forceFill` in the service). **Legal-only lifecycle:**
  `arrived → triaged → in_treatment → awaiting_disposition → dispositioned` (+ `left_without_being_seen` from
  the pre-treatment states arrived/triaged). `canTransition()` guards; illegal moves throw.
- **`EdVisitEvent`** (`BelongsToTenant`, `LogsReads`, **APPEND-ONLY** — model `updating`/`deleting` guards +
  `SIGNAL '45000'` DB triggers, the `surgical_case_events` recipe) — one immutable row for **arrival** + one
  per transition (`arrived`/`triaged`/`in_treatment`/`awaiting_disposition`/`dispositioned`/
  `left_without_being_seen`) + optional reason + who + when. A correction is a NEW row.

## The triage-acuity SEAM (G1 — the FENCE crux, empty)

`Contracts\TriageAcuityProvider` (interface: `suggestAcuity(AcuityContext): AcuityResult`) is bound in
`EDServiceProvider::register()` to `Services\NullTriageAcuityProvider` (returns `AcuityResult::none()` — no
suggestion, asserts nothing). This MIRRORS Pharmacy's `MedicationSafetyProvider` → `NullMedicationSafetyProvider`
and Clinical's `LabConnectivity` → `ManualLabConnectivity` (referenced by NAME only — ED imports no peer
vertical). **CareOS builds the SEAM, not the logic.** `Support\AcuityContext` (the input a partner would read)
+ `Support\AcuityResult` (an optional advisory suggested level; `none()` is the fence-clean default).

**THE FENCE LINE (the sharpest in the vertical).** A **COMPUTED** triage acuity (system takes vitals+complaint
→ produces the ESI/Manchester/CTAS level) IS performing triage — a regulated **medical device**, the electric
fence line (`AGENTS.md:36-39`), literally eval-locked (`ClinicalAgentsEvalTest.php:273` refuses `triage`), and
a **permanent homemade non-goal**. The seam ships EMPTY and stays empty until a certified partner fills it
(advisory + human-owned, never auto-assigning/auto-prioritising). The **nurse-ASSIGNED** acuity (a recorded
fact, the `Stay::admission_type` / surgical-ASA precedent) is the buildable version — recorded in a separate
triage record in **G2**, NOT on the `EdVisit` (no acuity/triage/priority/severity/score column exists on it).

## RBAC (additive)

New permissions `ed.manage` (register/advance a visit) + `triage.record` (used in G2). New role templates:
`ed_physician` (ed.manage + admission.manage — for the ED→ADT admit handoff, G5), `triage_nurse` (ed.manage +
triage.record), `ed_charge_nurse` (runs the board — + note.supervise + reporting.view). `org_admin` gains both
perms. Re-provisioned for existing tenants via `2026_08_06_000003_add_ed_permissions.php` (the
`add_surgery_permissions` precedent). No `Gate::before` change; `RbacNegativeSweepTest` untouched (only base
roles/perms are in its withheld-map).

## Boundaries / posture

- **Arch:** `Modules\ED` may use Platform + care modules (Patients/People/Clinical/Billing/Scheduling) + Audit
  SERVICES, but **not** `Audit\Models`, `AiCore`, Comms, or the peer verticals (Nursing/Dental/Hospital/
  Pharmacy/Surgery). The ED→ADT link (G5) is a soft app-layer `stay_id`, NOT a Hospital dependency.
  `arch('ED …')` in `tests/Architecture/ModuleBoundariesTest.php`.
- **Audit:** the `EdVisit.created` (`ed_visit.registered`) + `EdVisitEvent.created` (`ed_visit.<event_type>`)
  hooks live in `app/Providers/AppServiceProvider.php` (app-layer composition), so ED stays free of Audit —
  the Dental/Hospital/Pharmacy/Surgery posture. Patient-scoped.
- **Fence:** an ED visit + its flow state are OPERATIONAL facts. No computed acuity/priority/severity anywhere;
  the seam is empty; the module computes no acuity (grep over `Modules\ED\src` is clean — the fence test
  asserts no compute/score/calculate-acuity method exists).
- **No money math** in ED (billing is G6, via the existing engine).

## Triage (G2 — the FENCE crux, assigned-not-computed)

`ed_triages` (BelongsToTenant, LogsReads, **APPEND-ONLY** — model guards + DB triggers, the `ed_visit_events`
recipe): the triage assessment for an `EdVisit` — `triaged_by` (the nurse's StaffProfile, provenance),
`triaged_at`, `presenting_complaint`, `acuity_scale` (ESI/MANCHESTER/CTAS, provenance) + `acuity_level` (**the
value the NURSE ASSIGNED**). A re-triage is a new row. `EdTriage::SCALES` + `::LEVELS` are closed sets for
**data-entry validation** (`isValidAssignment` — a valid level for the scale), NOT a computed grade. **THE
FENCE:** `acuity_level` is a value the nurse SELECTS (a recorded fact, the `SurgicalCase::asa_class` /
`Stay::admission_type` / `Incident.severity` precedent) — never computed/suggested/ranked; NO
suggested/computed/score/severity/priority column. `TriageService::record` (gate `triage.record`, tenant
fail-closed, one `DB::transaction`): append the triage → optional RAW vitals via the EXISTING
`ClinicalListService::recordVital` (encounter-less, no bands/flags; needs `note.write`) → move the visit
`arrived → triaged` (only from `arrived`; a re-triage keeps the status). **The seam threaded + empty:**
`TriageService::acuitySuggestion(visit)` calls `TriageAcuityProvider->suggestAcuity()` → `none()` today
(read-side advisory only, the UI's empty "no automated suggestion" area; recording never touches it).
`EdTriageController` (`/ed/visits/{visit}/triage`, show=`patient.view` read-logged, store=`triage.record`) +
`ED/Triage.vue`; audit `ed_triage.recorded` (app-layer).

## Gate log

- **ED.G1**: module + `EdVisit`/`EdVisitEvent` + the empty triage-acuity seam + ED RBAC. 10 feature tests
  (`tests/Feature/ED/EdVisitTest.php`) + arch boundary + reprovision migration. See D-130.
- **ED.G2**: triage — the nurse-ASSIGNED acuity + presenting complaint + raw vitals (assigned-not-computed; the
  seam stays empty). `EdTriage` + `TriageService` + `EdTriageController`/`ED/Triage.vue` + `ed.*` i18n; FIX.5
  smoke extended. 7 feature tests (`tests/Feature/ED/EdTriageTest.php`). No charge. See D-131.
- **ED.G3**: the ED tracking board — operational flow facts + the RECORDED acuity; NO computed priority ranking.
  Reuses the ward-board idiom over the `EdVisit` flow. `EdVisitService::activeVisits()` + `EdVisit::latestTriage`
  (HasOne `latestOfMany`); `EdBoardController` (index gate `ed.manage` + a `transition` action → the G1
  `EdVisitService::transition`, dispositioned excluded); `ED/Board.vue` (sort by arrival OR the recorded
  acuity — a fact — never a computed rank); `ed.board.*` i18n; FIX.5 smoke extended. 5 feature tests
  (`tests/Feature/ED/EdBoardTest.php`). No charge. See D-132.
- **ED.G4**: ED clinical documentation — REUSES Clinical (Encounter UNMODIFIED); the fence carries through. An
  ED treatment encounter is a reused `Encounter` (`TYPE_CONSULTATION`) tied to the visit by the ED-side
  `ed_visit_encounters` link (`EdVisitEncounter`, the `ward_rounds` precedent); `EdDocumentationService`
  (mirrors `BedsideChartService`): `startEncounter`/`recordVital`/`placeOrder` + `vitalsForVisit` (the only new
  affordance — raw `VitalsSeries`); the one-open invariant HOLDS (a second concurrent encounter for the same
  patient+practitioner is refused). `EdDocumentationController` (`/ed/visits/{visit}/record`) + `ED/Documentation.vue`
  (reuses the bedside-chart idiom) + `ed.record.*` i18n; FIX.5 smoke extended. 6 feature tests
  (`tests/Feature/ED/EdDocumentationTest.php`). No charge. See D-133.
- **ED.G5**: disposition + the ED→ADT handoff — admit reuses AdmissionService → an inpatient Stay; atomic. The
  SIGNATURE reuse. A soft nullable `stay_id` on `ed_visits` (+ `EdVisitService::transition` gained `?stayId`);
  an APP-LAYER `app/Services/EdDispositionService` (composes ED flow + Hospital admission — in app/, NOT
  Modules\ED) does `admit` (one `DB::transaction`: `AdmissionService::admit(…, Stay::TYPE_EMERGENCY)` →
  `transition(…, dispositioned, admit, $stay->id)`) / `discharge` / `transferOut`. Atomic (forced failure rolls
  back both); reused bed-safety (`BedNotAvailableException` on an occupied bed); ADMIT needs `admission.manage`.
  App-layer `EdDispositionController` (`/ed/visits/{visit}/disposition`) + `ED/Disposition.vue`; board links to
  it; `ed.disposition.*` i18n; FIX.5 smoke extended. 7 feature tests (`tests/Feature/ED/EdDispositionTest.php`).
  No charge. See D-134.
- **ED.G6**: ED billing — visit/service charges via the existing engine (reconciles-to-the-unit; composite
  emergency→inpatient episode). **PHASE 6 COMPLETE.** STRICTLY ORCHESTRATION (surgery-G5 / bed-day shape): an
  `ed` `TariffCatalog` (`EdBillingService`: `priceAttendance`/`priceService` — `ED-ATTENDANCE` + services,
  tenant-authored, no licensed pricing); `chargeVisit` via `ChargeCaptureService::captureManual` (engine
  snapshots the fee + computes the line total; idempotent via `ed_visit_charges`); `invoiceVisit` (discharged)
  reconciles δ=0; an ADMITTED patient's ED charges join the stay's `invoiceStay` alongside bed-days (composite
  episode, δ=0). Gate `billing.manage` (the billing office, NOT the ED team). `EdBillingController`
  (`/ed/visits/{visit}/billing`) + `ED/Billing.vue`; `ed.billing.*` i18n; FIX.5 smoke extended. FENCE: a fee is
  a tariff, NOT acuity-driven; money-math grep clean. 7 feature tests (`tests/Feature/ED/EdBillingTest.php`).
  See D-135.

## The ED→ADT handoff (G5 — the signature reuse)

Closing an ED visit is the clinician's recorded DECISION (admit/discharge/transfer) via `awaiting_disposition
→ dispositioned` (the G1 legal transition). **ADMIT reuses the EXISTING, concurrency-safe, atomic
`AdmissionService::admit`** to create a Phase-1 inpatient `Stay` (`admission_type=emergency`) — admission is
REUSED, never reimplemented/modified. The cross-vertical composition lives in the APP LAYER
(`app/Services/EdDispositionService`) so `Modules\ED` stays arch-independent of `Modules\Hospital`; the ED↔Stay
link is a soft `stay_id` (no FK/relation). The handoff is ATOMIC (admit + disposition in one transaction — a
failure rolls back both); the reused bed-claim concurrency-safety + one-active-stay guard apply unchanged.
**FENCE:** the disposition is the clinician's recorded decision — nothing is computed/suggested/auto-decided.

## Documentation reuse (G4)

ED documentation is REUSE-heavy — it composes the EXISTING Clinical module against the `EdVisit` WITHOUT
modifying Clinical (the inpatient/surgery pattern). The linkage is ED-side (`ed_visit_encounters`) so
Clinical's `Encounter` schema + one-open-per-practitioner invariant are untouched (proven: `encounters` has no
`ed_visit_id` column; a second concurrent encounter is refused). Notes reuse the sign-and-lock `ClinicalNote`
(write→sign→lock→amend→version), vitals reuse the raw `Vital` (tied to the visit's treatment encounter; the
only new read is `vitalsForVisit` via `VitalsSeries` — no bands/flags/scores), orders reuse the structured
`Order`. FENCE carries through: raw vitals, no computed acuity/severity/deterioration score — the record
payload carries raw vitals + note status but no computed-judgment field.

## The board FENCE (G3)

The board shows OPERATIONAL FACTS + the RECORDED acuity ONLY. Staff MAY sort by the recorded acuity (the
nurse's assigned value — ordering by a recorded field is a fact) or by arrival, but the board NEVER computes a
priority ranking / an acuity-driven "who to see next" judgment / a wait-time-risk / a deterioration flag.
`available_transitions` is the FIXED legal-state map (record-not-judge), not a suggestion. Proven: the payload
`->missing` priority/rank/score/severity/deterioration/wait_risk; the server orders by `arrived_at`; the grep
over `EdBoardController` finds no priority/ranking computation.

## Not built yet / seams

**Phase 6 core (G1→G6) is COMPLETE.** Deliberate seam: **COMPUTED triage acuity is a PERMANENT non-goal**
(certified partner behind the empty `TriageAcuityProvider`). Optional later ED extras (fast-track / minor-
injuries lane, LWBS workflow, ambulance pre-arrival, a `DemoEmergencySeeder`) are the map's ED.G7. The
remaining hospital phases are Lab (Phase 3) + Radiology (Phase 4) — mostly integration shells pending
HL7/FHIR + PACS/DICOM partners (their own maps). See `docs/HOSPITAL-PHASE6-ED-MAP.md`.

## QA PHASE 7 — the audit findings (2026-09-07, `docs/qa/ROLE-AUDIT.md`)

**AUDIT ONLY — nothing fixed.** All three ED roles driven separately in a real browser
(`ed_physician` clara.meier, `triage_nurse` yusuf.demir, `ed_charge_nurse` marco.bianchi).
**17 findings: 3 CRITICAL, 5 HIGH, 7 MEDIUM, 2 LOW.**

**THE ACUITY BOUNDARY — the cleanest seam in the product, and it PASSES.** `NullTriageAcuityProvider`
returns `none()` and its docblock draws the distinction that matters: *"'CareOS makes no acuity claim',
not 'this patient is low acuity'"*, calling a homemade acuity computer **a permanent non-goal**. The
form's empty state reads *"No automated suggestion. The triage nurse assigns the acuity."*; the level
select has **no default**. **D-169 passes byte-for-byte**: an ESI 1 and an ESI 3 share card class,
background, border, badge class, badge bg/colour/weight/size — the only difference is the text. The
colour that varies is `statusClass()` = the **flow state**, commented "NOT a clinical severity".

**BUT `P7-C3` — the board's "Recorded acuity" sort INVERTS priority on Manchester.** `Board.vue:52`
uses `localeCompare` on the level STRING. ESI/CTAS are `'1'…'5'` so alphabetical = clinical; Manchester
is `['red','orange','yellow','green','blue']`, which sorts to `blue, green, orange, red, yellow`.
**Driven:** MANCHESTER blue (least urgent) rendered ABOVE MANCHESTER red. Mixed scales compare `'2'`
against `'red'`. The judgment is never computed — the ORDERING of the recorded judgment is, and is wrong.

**`P7-C1` + `P7-C2` — ATTRIBUTION BY DROPDOWN DEFAULT, three times in one module.** `Triage.vue:40`
(`triaged_by`) and `Disposition.vue:29` (`bed_id`, `clinician_id`) **pre-select the first entry of an
unfiltered, alphabetically-ordered staff list**. Driven: yusuf.demir recorded a triage that says
**"Triaged by Beat Suter"** (a `surgical_scheduler`); clara.meier admitted a patient and the STAY names
**Beat Suter as admitting clinician**. `triaged_by` is client-submitted (`EdTriageController.php:92`,
resolved at `:105`) and `ed_triages` has **no actor column**. **Worse in kind than P6-C2**, which
required an operator to actively pick the wrong person. The audit ledger DOES hold the true actor, so
the fact is recoverable from the audit trail — never from the clinical record. **QA-FIX.6b's remedy
applies unchanged.**

**PHASE-6 INHERITANCE IS PARTIAL (2 of 3):** re-triage **appends** ✅ (driven; `SIGNAL '45000'`
UPDATE/DELETE triggers on `ed_triages`), the write is **audited with the real actor** ✅
(`ed_triage.recorded`), but the record's own attribution ❌.

**`P7-H1` — NO HTTP PATH REGISTERS AN ED PRESENTATION.** `EdVisitService::register()` is called ONLY
from `DemoHospitalSeeder` (4 sites). No route, no controller action, no form creates an `EdVisit` —
**a patient cannot be brought into the ED at all**. `EdBoardController` uses the service only for
`activeVisits()` and `transition()`. The pattern-4 shape at the FIRST step of a workflow.

**`P7-H2` — ED renders NO refusals** (the P6-C3 defect, unfixed outside Surgery): 10 `withErrors`
sites, **0** of 5 ED pages read `errors`/`usePage`/`flash`. Driven: a triage with no level → no
message, no record, page unchanged. `RefusalNotice.vue` exists and is not used here.

**`P7-H3` — the ED physician cannot prescribe.** No `medication.prescribe` on any ED role, and the ED
clinical record has **no medication section at all** (searched the rendered page). `order.manage`
covers labs/imaging, not drugs.

**`P7-H4`/`P7-H5` — pattern 1 and the billing wall.** All four landing links 403 for `ed_physician`
(= `P2-H2` unchanged five phases later); `NAV_PERMISSIONS` (14 fixed keys) omits `ed.manage` and
`triage.record`, so there is no ED nav entry. All five ED billing routes are `billing.manage`, which
**no ED role holds**.

**GUARDS HELD:** legal-transitions-only (LWBS correctly disappears once `in_treatment`); board counts
live + consistent; disposition state-gated honestly ("not yet awaiting disposition"); Admit **withheld**
from the nurse rather than offered-and-refused; the ED→inpatient handoff completes and audits both
sides with the real actor (`EdVisitService::transition` is transactional, `:108`); **no empty catch**
anywhere in the module; stored times correct UTC.

**RBAC notes:** `triage_nurse` can record a **DISCHARGE** (`ed.manage`) while lacking `note.sign` and
`order.manage` (`P7-M1`). `ed_physician` has NO `triage.record` — correct, triage is a nurse act.

**CODE-ESTABLISHED, NOT DRIVEN (P7-H5 makes them unreachable):** `EdBillingService` has the P6-M10
shape — idempotency read `:101`, captures `:113-121`, link loop `:123-125`, **no transaction**
(`P7-M5`); `ED/Billing.vue:39` derives money client-side (`P7-M6`).
