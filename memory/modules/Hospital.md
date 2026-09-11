# Module: Hospital (`Modules\Hospital`)

## Purpose

The inpatient / ADT hospital vertical (Phase 1 of a phased hospital build — later phases: pharmacy/eMAR,
lab, radiology, OR, ED, each mapped before building). Planned as ~7 core gates, foundational-first
(`docs/HOSPITAL-PHASE1-ADT-MAP.md`). **HOSPITAL.G1 ships the FOUNDATION only:** the bed/ward/unit domain
model + a concurrency-safe bed-claim primitive + inpatient RBAC. **No ADT workflow yet** (the admit/
transfer/discharge `Stay` state machine is HOSPITAL.G2) and **no UI** (the ward board is HOSPITAL.G3).
Hospital inherits the whole tested foundation — tenancy, patients, clinical charting, the billing engine,
append-only audit, RBAC, the design system — so its new surface is ONLY the inpatient operational domain.

## Ward modeling decision (G1)

The map offered two options for the ward/unit hierarchy: wire Platform's unwired `Department` stub, OR a
fresh Hospital-owned `Ward`. **Chose a Hospital-owned `Ward`** because (1) ward attributes will grow
inpatient-specific (capacity, unit type, later staffing/policies) and those belong in the vertical, not
Platform's generic `Department`; (2) it gives a clean bidirectional `Ward hasMany Bed` relation (a
`Department::beds()` back-relation is impossible — Platform must not depend on Hospital); (3) it mirrors how
every vertical owns its domain models (`Nursing\Visit`, `Dental`) while referencing only the Platform
foundation (`Branch`) — the proven `Visit → Branch` pattern. Platform's `Department` stub is left for its
original generic clinic-functional-department purpose; swapping the FK to `Department` later is localized.

## Key tables

- `wards` (BelongsToTenant) — an inpatient ward/nursing unit under a branch. Columns: id (ULID), tenant_id,
  branch_id, name, code, active. `unique(tenant_id, branch_id, code)`. Cross-tenant branch link rejected
  (`assertBranchWithinTenant`, like `Department`).
- `beds` (BelongsToTenant) — a NET-NEW model, deliberately **NOT a Scheduling `Resource`** (occupancy is a
  continuous multi-day stay, not a timed slot — so there is NO `starts_at`/`ends_at`). Columns: id (ULID),
  tenant_id, branch_id, ward_id, `label`, `bed_type` ∈ {general, icu, isolation}, `status` ∈ {free,
  occupied, cleaning, blocked} (default free), active. `unique(tenant_id, ward_id, label)`. **ELECTRIC
  FENCE: no patient / acuity / severity / score / risk / flag column — `status` is OPERATIONAL housekeeping
  (can be true with no patient/service attached, e.g. `cleaning`), never a clinical judgment.**

## Key classes

- `Models\Ward` — BelongsToTenant + HasUlids; `belongsTo(Branch)`, `hasMany(Bed)`; cross-tenant branch guard.
- `Models\Bed` — BelongsToTenant + HasUlids; `belongsTo(Branch)`, `belongsTo(Ward)`; cross-tenant branch+ward
  guard. Holds the type/status vocabularies + `TRANSITIONS` (the legal housekeeping state machine:
  free→{occupied,blocked}, occupied→{cleaning}, cleaning→{free,blocked}, blocked→{free}) + `canTransition()`.
  **free→occupied is reached ONLY through the concurrency-safe `BedService::claim()`, never `setStatus()`.**
- `Services\WardService` — `create`/`rename`/`deactivate`, each gated `ward.manage` server-side; tenant+branch
  scoped; audited via app-layer hooks.
- `Services\BedService` — `create`/`deactivate`/`setStatus` gated `bed.manage`; `claim` gated `admission.manage`;
  `forWard` reads a ward's beds + status (the data the G3 board will render). `setStatus` is a legal-only,
  row-locked housekeeping transition (rejects →occupied and illegal edges, throws `BedStatusTransitionException`).
  **`claim` applies the `BookingService::lockResource`→assert idiom to a bed:** `DB::transaction` +
  `SELECT status FROM beds WHERE tenant_id=? AND id=? FOR UPDATE`, assert still free under the lock, flip to
  occupied — so N racing claims yield exactly ONE winner (loser gets `BedNotAvailableException`). A private
  `lockBedStatus` centralises the tenant-scoped FOR UPDATE (cross-tenant id → `CrossTenantReferenceException`).
- `Events\BedStatusChanged` (bed, fromStatus, toStatus, actor, ?reason) — fired by BedService on every status
  transition; the app layer listens → `bed.status_changed` audit (so Hospital stays free of Audit).
- `Exceptions\BedNotAvailableException` (claim conflict), `Exceptions\BedStatusTransitionException` (illegal/
  use-claim). `Console\AttemptBedClaimCommand` (`hospital:attempt-bed-claim`, for the parallel hammer only).
- `Providers\HospitalServiceProvider` — loadMigrations + registers the console command.

## Invariants

- **Concurrency-safe occupancy:** a bed can be claimed (free→occupied) by exactly one winner under a row
  lock — proven by `BedClaimParallelHammerTest` (8 OS processes race one free bed → 1 CLAIMED, 7 CONFLICT),
  the sibling of `BookingParallelHammerTest`/`VisitAssignmentParallelHammerTest`.
- **Legal-only status transitions:** every housekeeping change is validated against `Bed::TRANSITIONS`
  (illegal throws) and audited via `BedStatusChanged` — one audit row per transition.
- **Tenant + branch scoped, fail-closed:** `BelongsToTenant` confines every query; a ward/bed pointing at
  another tenant's branch/ward throws `CrossTenantReferenceException`; a cross-tenant id is invisible.
- **ELECTRIC FENCE (operational, not clinical):** a bed/ward is housekeeping infrastructure — no patient
  link, no acuity/severity/score/risk/grade/flag anywhere in schema/service/event. Asserted by a schema
  fence test. (An inpatient deterioration score / NEWS2 is out — see the map §3: certified-partner or
  non-goal, never homemade.)

## Arch boundary

`arch('Hospital may use care modules + Audit services but not Audit models, AiCore, Nursing, or Comms')` —
mirrors Dental. Hospital may use Platform + Patients/Scheduling/Clinical/Billing + Audit SERVICES, never
Audit models directly, AiCore, the peer Nursing vertical, or Comms. Cross-module audit composition (the
bed/ward hooks + the `BedStatusChanged` listener) lives in `app/AppServiceProvider`, so Hospital references
no Audit. In G1 Hospital in fact depends only on the Platform foundation.

## RBAC (additive — the `dental.chart` precedent, `Gate::before` unchanged)

New permissions in the catalog: `ward.manage`, `bed.manage`, `admission.manage`, `document.view`. New
starter role templates (map §4): `ward_nurse` (= nurse clinical set), `charge_nurse` (nurse set +
`note.supervise` + `reporting.view` + `bed.manage`), `hospitalist` (doctor set minus `dental.chart` +
`admission.manage`), `bed_manager` (`ward.manage`+`bed.manage`+`patient.view`+`reporting.view`),
`admissions_clerk` (reception set + `patient.edit` + `admission.manage`), `him_records` (`patient.view`+
`note.supervise`+`document.view`+`audit.view`). `org_admin` gains all four new permissions. Ward/bed
management gates on `bed.manage`/`ward.manage`; claiming a bed on `admission.manage` (placing occupancy is
an admission act). **Ward-level scope is branch-level for Phase 1** (deeper ward scoping = a later
`abac_conditions` gate). The permission-count test (`RbacTest`) is relative, so additions stay green.

## ADT workflow (HOSPITAL.G2)

The core of the vertical — admit / transfer / discharge a multi-day `Stay`.

- `stays` (BelongsToTenant, **LogsReads** — patient-scoped read-logged) — a patient's inpatient episode, a
  NET-NEW entity **ABOVE an UNMODIFIED `Encounter`** (map §2.2; the `VisitPlan→Visit` analogue — G2 does NOT
  touch Clinical, so Encounter's one-open-per-practitioner invariant holds for every vertical; bedside
  charting reuses Encounter per ward-round in G4). The MUTABLE current state: `patient_id`, `branch_id`,
  `admitting_clinician_id` (StaffProfile), `current_bed_id`/`current_ward_id`, `admitted_at`,
  `discharged_at`, `status`, `admission_type`, `admission_reason`, `discharge_disposition`. **State machine:**
  `STATUSES` {admitted, discharged}; `TRANSITIONS` = admitted→discharged. A **transfer is a bed-move WITHIN
  admitted** (not a status change). `admission_type` ∈ {elective, emergency, transfer} (an operational ROUTE)
  is validated in the model's `creating` hook; cross-tenant refs rejected there too.
- `stay_events` (BelongsToTenant, **APPEND-ONLY** at model + DB-trigger level `stay_events_no_update`/
  `_no_delete`) — one immutable row per admit/transfer/discharge preserving the full bed journey (bed_id,
  from_bed_id, ward_id, reason, disposition, occurred_at, performed_by). A correction is a NEW event.
- `Services\AdmissionService` — `admit` / `transfer` / `discharge`, gated `admission.manage`, tenant+branch
  fail-closed (rejects a cross-tenant bed UP FRONT). **Each is ATOMIC** (the dental-perform discipline): the
  stay change + the bed claim/release (via G1's proven `BedService::claim`/`release` — NOT reimplemented) +
  the append-only `StayEvent` all happen in ONE `DB::transaction`, so a forced failure rolls back everything
  — no orphan stay, no stuck bed, and even the bed's audit row rolls back (proven). **admit** creates the
  stay + `claim`s the bed (free→occupied); **transfer** `claim`s the new bed then `release`s the old
  (occupied→cleaning), moves the stay; **discharge** `release`s the bed, sets disposition + discharged_at,
  transitions to discharged. **One-active-stay guard:** a patient row lock + `lockForUpdate()->exists()`
  check refuses a second active admission (the one-open-encounter analogue). Legal-only (illegal transitions
  throw `AdmissionException`). **No charge posted — bed-to-billing is G6.**
- `Events\StayTransitioned` → app-layer listener → one append-only `admission.<eventType>` audit row (keyed
  by event type since a transfer keeps status=admitted); the AppointmentTransitioned pattern. `BedService`
  gained `release()` (occupied→cleaning, `admission.manage`, same lock idiom). NEW `admission.manage` now
  also gates the ADT actions (it existed since G1).
- `Http\Controllers\AdmissionController` (string-id FIX.1) — the MINIMAL action surface (rich board = G3):
  `show` (GET `/hospital/admissions/{stay}`, `patient.view`, read-logged, renders `Hospital/Admission.vue`)
  + `store`/`transfer`/`discharge` (POST, `admission.manage`). Route-smoke extended (org_admin GET 200,
  reception POST admit 403).

## Ward board (HOSPITAL.G3)

The live bed-occupancy cockpit — the first inpatient UI. PRESENTATIONAL over G1/G2 (P0D.GU): it READS and
SURFACES the existing actions; it computes no ADT/occupancy logic.

- `Services\WardService::activeWards()` — read helper (the tenant's active wards). The board data source is
  WardService (wards) + `BedService::forWard` (beds+status) + a Stay query (occupant per bed).
- `Http\Controllers\WardBoardController` — `show` (GET `/hospital/wards`, gate `patient.view`) renders
  `Hospital/WardBoard.vue`: each active ward → its beds (label, bed_type, **housekeeping status**), the
  current patient + `admitted_at` per OCCUPIED bed (the active Stay keyed by `current_bed_id`), and a plain
  **occupancy count** (occupied/total). It reuses the day-board TILE/STATUS idiom for layout, but the data
  is beds/stays (continuous occupancy) — it never touches the scheduling slot engine. `setBedStatus` (POST
  `/hospital/beds/{bed}/status`, gate `bed.manage`, string-id FIX.1) sets a bed's housekeeping status via
  `BedService::setStatus` (legal-only). The ADT actions (admit/transfer/discharge) POST to the EXISTING G2
  routes — **admit-from-the-board uses the proven `AdmissionService::admit` → concurrency-safe claim**, no
  new ADT logic, atomicity/concurrency untouched (tested). The board is NOT per-occupant read-logged (it is
  an operational overview, the day-board posture; deep read-logging is the G2 admission `show`).
- **Read gate = `patient.view`** (all inpatient clinical staff, incl. ward nurses, hold it; billing is
  denied). Surfaced actions keep their own gates: admit/transfer/discharge = `admission.manage`, bed status
  = `bed.manage`; the payload's `can_admit`/`can_manage_beds` reflect the actor (server Gate authoritative).
- **ELECTRIC FENCE:** the payload is OPERATIONAL ONLY — housekeeping status, occupant name + `admitted_at`
  (LOS-so-far is plain elapsed time the client renders), a plain occupancy count. NO acuity/severity/risk/
  priority/deterioration field; the status COLOUR is the housekeeping state, never a clinical judgment
  (asserted by a recursive `wbAssertNoJudgment` over the payload). No charge posted (billing is G6).

## Bedside charting (HOSPITAL.G4)

Clinical documentation for a stay — REUSE-heavy, NOT new clinical domain. It composes the EXISTING tested
Clinical module against the stay WITHOUT modifying Clinical (Hospital MAY use Clinical — not forbidden by
the arch rule — the allowed dep Dental also uses).

- `ward_rounds` (BelongsToTenant) — the Hospital-SIDE link tying a Stay to the Clinical `Encounter`s created
  during it (the map's §2.2 "Stay -> per-round Encounter"). **Clinical is UNTOUCHED** — no `stay_id` on
  Encounter, no schema/invariant change; the association is Hospital-side. `WardRound` belongsTo `Stay` +
  `Encounter` (Clinical); `unique(tenant_id, encounter_id)`.
- `Services\BedsideChartService` — composes Clinical, reimplements nothing:
  - `startRound` opens a reused `Encounter` (via `EncounterService::open`, type `other` — no inpatient type
    added to Clinical; **the one-open-per-practitioner invariant is enforced UNCHANGED**), links it with a
    `WardRound`, and creates the sign-and-lock note DRAFT (`ClinicalNoteService::saveDraft`) — atomically;
    the controller then redirects into the EXISTING note editor (`clinical.notes.edit`). The round's
    practitioner is the stay's admitting clinician.
  - `recordVital` reuses `ClinicalListService::recordVital` (note.write) tied to the stay's latest round
    Encounter (raw `Vital`, no interpretation). **`vitalsForStay` is the ONLY new affordance** (a
    stay-scoped READ): it filters the existing `Vital` store to the stay's round Encounters and builds the
    RAW per-metric series via the existing `VitalsSeries::build` — NO schema change, no bands/flags/scores.
  - `placeOrder` reuses `OrderService::place` (order.manage) tied to the round; `ordersForStay` reads them.
  - `roundsForStay` lists the rounds (+ Encounter). All required-FK models resolved via typed model queries
    (`Patient/Branch/StaffProfile::findOrFail`) for the reused Clinical services.
- `Http\Controllers\BedsideChartController` (string-id FIX.1) — `show` (GET `/hospital/admissions/{stay}/chart`,
  `patient.view`, **read-logged** via `Stay::auditRead`) renders `Hospital/StayChart.vue`: rounds (+ note
  status + a link to the EXISTING editor), raw vitals-over-the-stay, orders. `startRound` (POST, encounter.manage)
  redirects into the existing note editor; `recordVital` (POST, note.write); `placeOrder` (POST, order.manage).
  Route-smoke extended (doctor 200 / billing 403).
- **RBAC = the EXISTING clinical permissions the inpatient roles already hold** — ward_nurse + hospitalist
  have encounter.manage / note.write / note.sign / order.manage; read = patient.view. No new permission.
- **ELECTRIC FENCE carries through:** raw vitals (VitalsSeries, no bands/scores), sign-and-lock notes
  unchanged (append-only versions), append-only order results — NO computed acuity/deterioration/early-warning
  score (a NEWS2-style score is certified-partner/non-goal, NOT built). The stay-chart payload carries no
  judgment field (asserted by a recursive scan). No charge posted (billing is G6).

## Nursing shift handover (HOSPITAL.G5)

**DISCOVERY:** a handover is a NET-NEW structured SBAR artifact, NOT a reuse of `ClinicalNote` — SBAR
(Situation/Background/Assessment/Recommendation) ≠ SOAP; it carries shift metadata a note lacks; it is
STAY-scoped (ClinicalNote is Encounter-scoped, mandatory encounter_id). It REUSES the platform PATTERNS
(append-only + DB triggers, audit, LogsReads, note/chart UI idioms) — "reuse the pattern, own the domain".

- `handovers` (BelongsToTenant, **LogsReads**, **APPEND-ONLY** model guards + DB triggers `handovers_no_update`/
  `_no_delete`) — a shift handover for a stay: `stay_id`, `authored_by` (the outgoing nurse User), `shift`
  ∈ {day, evening, night}, the SBAR text (`situation` [required], `background`, `assessment`, `recommendation`
  — all nurse-authored), `reason` (correction), `handed_over_at`. **ELECTRIC FENCE: no acuity/severity/score/
  risk/priority/flag column;** `assessment` is the nurse's OWN written assessment (a SBAR section like a note's
  SOAP assessment), never a computed score. `Handover` model validates the shift + a non-empty Situation.
- `Services\HandoverService` — `record` (gate **`note.write`** — the nursing roles hold it, no new permission;
  tenant+patient fail-closed; append-only; nothing computes/auto-populates) + `history` (the stay's raw shift
  trail, newest first). No interpretation logic.
- `Http\Controllers\HandoverController` (string-id FIX.1) — `show` (GET `/hospital/admissions/{stay}/handover`,
  `patient.view`, **read-logged**) renders `Hospital/Handover.vue` (the SBAR record form + the shift trail);
  `store` (POST, `note.write`). The write is audited via an app-layer `Handover::created` hook (`handover.recorded`)
  so Hospital stays free of Audit. Route-smoke extended (doctor 200 / billing 403).
- **RBAC:** record = `note.write` (ward_nurse + charge_nurse hold it), read = `patient.view` — reused, no new
  permission. **No charge (billing is G6).**

## Bed-to-billing (HOSPITAL.G6)

An inpatient stay accrues charges (bed-days + services) through the **EXISTING** billing engine, and
discharge produces an invoice that **reconciles-to-the-unit**. **NET-NEW is STRICTLY ORCHESTRATION — no
new billing/pricing/VAT/line-total math** (the engine prices everything; a per-diem is a RATE, not a
clinical acuity). The map's endorsed shape: a bed-day is a `TariffItem`; a stay accrues `Charge`s
(many-per-patient); the discharge invoice is the existing `validateForPatientPeriod` →
`createDraftFromCharges` → `issue` flow; `ReconciliationEngine` I4 handles "N charges → 1 invoice" natively.

- `bed_day_accruals` (BelongsToTenant) — the **idempotency ledger** (the `nursing:materialize-visits`
  discipline mirrored for bed-days): id (ULID), tenant_id, `stay_id` (FK stays cascade), `service_date`
  (date), `charge_id` (FK charges cascade), timestamps. `unique(tenant_id, stay_id, service_date)` — the
  hard guard that a re-run never double-charges; index (tenant_id, stay_id). **No money stored** (the
  Charge is the money).
- `Models\BedDayAccrual` — BelongsToTenant + HasUlids; fillable stay_id, service_date, charge_id.
- `Services\BedBillingService` — the orchestration core (constructor: `ChargeCaptureService`,
  `ChargeValidator`, `IssueService`, `TenantContext`). `CATALOG_KEY='hospital'`; `STARTER` = 3 GENERIC
  bed-day items (BED-DAY-GENERAL 50000, BED-DAY-ICU 200000, BED-DAY-ISOLATION 80000 minor units — **the
  tenant's own codes, NO licensed code set**, placeholder rates it edits). `catalog()` firstOrCreate the
  effective-dated hospital catalog (valid_from 2020, open-ended, ACTIVE); `seedStarter(actor)` (gate
  `billing.manage`, idempotent by code, unit='bed-day', vat 0); `bedDayCode(bedType)` → 'BED-DAY-'.upper;
  `accrueBedDays(actor, stay, ?upTo)` loops each calendar day admitted→(upTo ?? discharged ?? now),
  **fast-checks the ledger then captures via `ChargeCaptureService::captureManual`** (the engine resolves
  + **snapshots** the fee and computes line_total) inside a `DB::transaction` + writes the ledger row (a
  race rolls back on the unique key); `invoiceStay(actor, stay)` (gate `billing.manage`, cross-tenant
  fail-closed) accrues the final bed-days, `validateForPatientPeriod`, gathers the VALIDATED/uninvoiced
  charges in the stay window, `createDraftFromCharges`(SELF_PAY) → `issue` (gapless number). **The bed
  resolves via a typed `Bed::findOrFail` (fail-closed) — no `??` fallback.** No pricing/VAT anywhere.
- `Console\AccrueBedDaysCommand` (`hospital:accrue-bed-days`) — the unattended sweep, shaped exactly like
  `nursing:materialize-visits`: iterate ACTIVE tenants, set context, **resolve the tenant's `org_admin`
  as the billing actor** (holds `billing.manage`; skip+warn if none), accrue every ADMITTED stay,
  restore context. Idempotent (twice ≠ double-charge). Registered in `HospitalServiceProvider`; scheduled
  in `routes/console.php` `->dailyAt('05:30')` (before the 06:00 dunning / 06:30 reconcile sweeps).
- `Http\Controllers\BedBillingController` (string-id FIX.1) — `invoice` (POST
  `/hospital/admissions/{stay}/invoice`, gate `billing.manage`) → `invoiceStay` → redirect to the EXISTING
  `billing.invoices.show`. PRESENTATIONAL over the service. Light ADT wiring: `AdmissionController::show`
  adds `actions.can_invoice` (billing.manage && discharged) + `invoice_url`; `Admission.vue` shows an
  "Invoice stay" button on a discharged stay. Route-smoke extended (reception POST 403).
- **RECONCILES-TO-THE-UNIT (THE key proof):** a discharged stay's bed-day + service charges assemble into
  one gapless invoice and the existing `ReconciliationEngine::check(period)` passes with I4
  `delta_minor === 0` — proven in `BedBillingTest` (6 bed-days × 50000 + 1 consult × 12000 = 312000,
  every invariant green). Fee-snapshot proven (editing the tariff later never changes a past charge).
- **ELECTRIC FENCE / no-money-math (adversarial grep, tested):** `Modules\Hospital` contains **none** of
  `line_total_minor`/`vat_total_minor`/`subtotal_minor`/`vatMinor`/`intdiv(` — the only money it names is
  the authored per-diem RATE (`unit_price_minor`/`vat_rate_bp=0` keys on the tariff item). All charge/VAT/
  line-total math lives in Billing. Tenant+branch scoped, fail-closed.

## Discharge summary + LOS + episode close-out (HOSPITAL.G7 — Phase-1 close-out)

The FINAL Phase-1 gate — tie off the inpatient episode with a discharge summary, length-of-stay, and a
clean, coherent, read-only-where-finalized closed episode. Mostly REUSE.

- **LOS is DERIVED, never a judgment.** `Stay::lengthOfStayMinutes(): ?int` = `discharged_at − admitted_at`
  in whole minutes, computed on read (null while admitted). A read affordance on the Stay — no column, no
  storage. **ELECTRIC FENCE:** the map (§3) flags an LOS-outlier flag as a clinician-/ops-set threshold, NOT
  a system grade — so there is deliberately NO "too long"/outlier/rating/expected-vs-actual anywhere; the UI
  renders raw days/hours (reusing the ward board's `board.losDaysHours/losHours/losMinutes` idiom).
- **Discovery — the discharge summary is a NET-NEW stay-scoped SIGN-AND-LOCK record (the G5 posture).** It is
  NOT a `ClinicalNote` (SOAP + encounter-scoped, mandatory encounter_id) nor an uploaded `Document`
  (file/path-shaped, no authored content) — a discharge summary is a stay-scoped clinician-authored NARRATIVE.
  So it OWNS its table while REUSING the patterns: the `ClinicalNote` sign-and-lock discipline + the
  **`clinical_notes_signed_*` CONDITIONAL immutability trigger** (`IF OLD.status = 'finalized'`),
  `BelongsToTenant`, `LogsReads`, and app-layer audit hooks.
- `discharge_summaries` (BelongsToTenant, **LogsReads**, sign-and-lock) — `stay_id`, `patient_id`
  (denormalized for read-logging), `authored_by` (User), `summary` (the clinician's episode narrative —
  required to finalize), `instructions` (nullable, patient discharge instructions), `status` ∈ {draft,
  finalized}, `finalized_at`, `finalized_by` (User). `unique(tenant_id, stay_id)` — one summary per episode.
  Conditional DB triggers `discharge_summaries_finalized_no_update`/`_no_delete` (immutable once finalized;
  a draft stays editable) + model guards (belt). **FENCE: no acuity/severity/score/risk/rating/outlier/
  readmission column** — `summary`/`instructions` are the clinician's own words.
- `Models\DischargeSummary` — `STATUS_DRAFT`/`STATUS_FINALIZED`; `isFinalized()`; model guards throw
  `AdmissionException::dischargeSummaryFinalized()` on update/delete of a finalized row; `auditPatientId`.
- `Services\DischargeSummaryService` — `saveDraft` (gate **`note.write`**; `updateOrCreate` one draft per
  stay; refuses once finalized) + `finalize` (gate **`note.sign`**; row-locked, idempotent, requires a
  non-empty narrative → sets finalized/finalized_at/by; the `ClinicalNoteService::sign` discipline) +
  `forStay`. Tenant fail-closed off the `Stay`. NOTHING computed/auto-populated. **RBAC reuses the existing
  clinical permissions — NO new permission** (every inpatient clinical role holds note.write + note.sign).
- `Services\BedBillingService::invoicesForStay(Stay): Collection<Invoice>` — an ADDITIVE pure READ (no
  billing math) traversing the bed-day ledger → charges → invoice, for the closed-episode view.
- `Http\Controllers\DischargeSummaryController` (string-id FIX.1) — `show` (GET
  `/hospital/admissions/{stay}/discharge-summary`, `patient.view`, **read-logged**) renders the closed
  episode: LOS + disposition + the summary (draft editor OR finalized read-only) + the stay's EXISTING
  records read-only (ADT journey [G2], ward rounds [G4], handovers [G5], invoices [G6]); `save` (POST,
  `note.write`), `finalize` (POST, `note.sign`). `AdmissionController::show` gained `los_minutes` + a
  `summary_url`; `Admission.vue` shows LOS + a "Discharge summary" link. Audited via app-layer
  `DischargeSummary::created`/`updated` hooks (`discharge_summary.drafted`/`.finalized`).
- **Episode close-out:** the discharge (G2) already stamps discharged_at + disposition + releases the bed; G7
  composes the summary + LOS + the full record (ADT/rounds/handovers/charges/invoice) into a coherent closed
  episode, read-only where finalized. No change to G2's discharge state-change or G6's billing (additive).

## Status

**HOSPITAL PHASE 1 (inpatient / ADT) COMPLETE — G1–G7 shipped.** G1 = Bed/Ward model + concurrency-safe
bed-claim + inpatient RBAC. G2 = the ADT `Stay` + admit/transfer/discharge state machine (atomic, bed-safe,
above an unmodified Encounter). **G3 = the ward board (live bed-occupancy cockpit) — the first inpatient UI,
presentational over G1/G2. **G4 = bedside charting — REUSES Clinical (a ward round is a reused Encounter tied
to the stay by a Hospital-side WardRound; notes/vitals/orders reused; the only new affordance is the
stay-scoped `vitalsForStay` read); Encounter UNMODIFIED, fence holds. **G5 = nursing shift handover — a
NET-NEW structured SBAR artifact (nurse-authored, append-only, record-not-judge, stay-scoped), reusing
platform patterns; NOT a ClinicalNote reuse.** **G6 = bed-to-billing — inpatient per-diem + service accrual
through the EXISTING billing engine + a discharge invoice that reconciles-to-the-unit (I4 δ=0); STRICTLY
ORCHESTRATION, no new money math (adversarial-grep proven).** **G7 = discharge summary + LOS + episode
close-out — LOS is a DERIVED elapsed fact (no outlier/grade); the discharge summary is a NET-NEW stay-scoped
SIGN-AND-LOCK record (reuses the ClinicalNote sign-and-lock discipline + conditional immutability trigger);
the closed episode composes G2/G4/G5/G6 read-only.** **The day-one inpatient spine is now end-to-end:** admit
to a bed on a ward → live ward board → bedside chart (reused Clinical) → nursing SBAR handover → bed-to-billing
invoice → discharge with LOS + a signed discharge summary + a coherent closed episode. Verified: npm build
green; composer check FULLY green; targeted — `WardBedManagementTest` (7), `BedClaimParallelHammerTest` (1),
`HospitalAdmissionTest` (12), `WardBoardTest` (5), `BedsideChartTest` (7), `HandoverTest` (6), `BedBillingTest`
(7), `DischargeSummaryTest` (6), Clinical/Encounter + Billing/Reconciliation + arch + RBAC suites unchanged;
smoke green (discharge-summary route added). Phases 2–7 (pharmacy/eMAR, lab, radiology, OR, ED) remain the
phased roadmap — each mapped before building. See [[D-113]], [[D-114]], [[D-115]], [[D-116]], [[D-117]],
[[D-118]], [[D-119]], `docs/HOSPITAL-PHASE1-ADT-MAP.md`.

## Open items / next gates (per docs/HOSPITAL-PHASE1-ADT-MAP.md)

- **G2** *(done — D-114)* — ADT `Stay` + admit/transfer/discharge state machine ABOVE a reused, unmodified
  `Encounter`; each transition an append-only `admission.<event>` audit row + a `stay_events` history row; the
  admission wraps `BedService::claim()`, discharge/transfer `release()` the bed (occupied→cleaning); atomic +
  one-active-stay guarded. **Next: G3.**
- **G3** *(done — D-115)* — ward board (live bed-occupancy cockpit over Bed+Stay, the tile/status idiom on a
  continuous timeline; the first inpatient UI, presentational over G1/G2). **Next: G4.**
- **G4** *(done — D-116)* — bedside charting: reuses Clinical (ward round = reused Encounter tied to the
  stay via `WardRound`; notes/vitals/orders reused; new = the `vitalsForStay` read); Encounter unmodified,
  fence holds. **Next: G5.**
- **G5** *(done — D-117)* — nursing shift handover: a net-new structured SBAR artifact (nurse-authored,
  append-only, record-not-judge, stay-scoped); reuses platform patterns, not a ClinicalNote reuse. **Next: G6.**
- **G6** *(done — D-118)* — bed-to-billing: per-diem `TariffItem` (tenant-authored, no licensed code set) +
  the idempotent `hospital:accrue-bed-days` sweep + a discharge invoice via the existing validate→draft→issue
  flow that reconciles-to-the-unit (I4 δ=0). STRICTLY orchestration — no new billing/pricing/VAT math. **Next: G7.**
- **G7** *(done — D-119)* — discharge summary + LOS + episode close-out: LOS = `Stay::lengthOfStayMinutes()`
  (derived elapsed fact, no grade/outlier); the discharge summary is a NET-NEW stay-scoped SIGN-AND-LOCK
  record (`discharge_summaries`, draft→finalized-immutable, reusing the ClinicalNote sign-and-lock discipline +
  the `clinical_notes_signed_*` conditional trigger; `note.write`/`note.sign`, no new permission); the
  closed-episode view composes G2/G4/G5/G6 read-only. **PHASE 1 COMPLETE.**
- **PHASE 1 (inpatient / ADT) COMPLETE (G1→G7).** Next phases (each mapped before building): **Phase 2**
  pharmacy / eMAR · **Phase 3** lab · **Phase 4** radiology · **Phase 5** OR/theatre · **Phase 6** ED · (an
  optional G8 scheduled-admissions was noted in the map). Long poles remain partner-gated / non-goal:
  HL7/FHIR ADT feed (`Interop`), bedside device capture, certified early-warning/deterioration engine (NEWS2),
  DRG/case-mix grouper.
- Long poles (partner-gated / non-goal): HL7/FHIR ADT feed (`Interop`), bedside device capture, certified
  early-warning/deterioration engine (NEWS2 — fence + regulated device, never homemade), DRG/case-mix grouper.

## Demo data (DemoHospitalSeeder — D-147)
- `database/seeders/DemoHospitalSeeder.php` (+ `tests/Feature/Demo/DemoHospitalSeederTest.php`) seeds **Klinik
  Bergblick** (CHF, de) — a coherent, reconciling demo tenant for ALL SIX hospital verticals, built through the
  REAL services (no raw rows). 20 users (one per hospital role, `twoFactorEnabled` for Playwright); 2 wards / 7
  beds (varied housekeeping states) / an OP-Saal; tenant-authored catalogs+tariffs. Actor = org_admin (holds
  every hospital gate), role `StaffProfile`s carry provenance.
- **THE COMPOSITE EPISODE:** one patient ED→admit(emergency Stay)→bed-days→meds(eMAR)→surgery(WHO+ASA+implant/
  consumable)→labs→radiology(report)→discharge → the whole episode on ONE `invoiceStay` invoice (13 charges,
  CHF 5187.20), reconciles δ=0. Plus a 2nd elective inpatient, a still-admitted transfer (live occupancy, DRAFT
  bed-days), an ED-discharge, standalone outpatient lab+radiology, and live pending states — 5 gapless invoices.
- Period = the CURRENT month (services stamp `now()`; `billing:reconcile` reconciles the current period);
  admissions back-dated via `forceFill(admitted_at)` (data, not the clock). PROVEN: `billing:reconcile`=PASS,
  `audit:verify-chains`=OK, `ReconciliationEngine::run` all 6 invariants δ=0. Fence proven at schema level.
- Run manually (not in `DatabaseSeeder`): `php artisan db:seed --class=DemoHospitalSeeder`. See [[LOG]].

## QA phase 9 (2026-09-08) — audit only, nothing fixed

**25 findings across Bed management + Medical records** (3C/6H/11M/5L; the audit now stands at 168 across
nine phases). Roles: `bed_manager` (driven in `klinik-bergblick`) and `him_records` (**no seeded account
in any tenant** — provisioned by driving `/admin/roles` as `org_admin`, restored afterwards).

**BED STATE HONESTY PASSES — the strongest single result in the phase.** `bed.status` is written in
exactly **three** places, all inside `BedService`, each under a `SELECT … FOR UPDATE` lock that re-reads
the authoritative status: `setStatus` (a housekeeping act), `claim` (admission/transfer-in), `release`
(discharge/transfer-out). No factory, seeder, controller or command writes the column directly —
`DemoHospitalSeeder` drives the real paths. **`free → occupied` is impossible by hand:** `setStatus`
rejects `occupied` as a target, so occupancy is only reachable through `claim`, which re-asserts `free`
under the lock. **Nothing auto-frees a bed** — no timer, no scheduled side effect; `cleaning → free` is
always a recorded human act. Every transition dispatches `BedStatusChanged` → one append-only
`bed.status_changed` audit row carrying the actor, `from_status`, `to_status`, ward and branch. There is
**no `bed_events` table and none is needed** — the tenant's hash-chained audit chain is the record.
Driven: blocking CH-02 produced exactly one row, `actor=28`, hash-chained.

**BUT the status write is not bound to the stay (`P9-H1`, HIGH).** `Bed::TRANSITIONS` allows
`occupied → cleaning` and `BedService` never consults `Stay`, so a `bed.manage` holder can move an
OCCUPIED bed to `cleaning` — after which `release()` throws for both discharge and transfer and **the
patient is wedged with no product path back to `occupied`** (only `claim` writes it, and it needs `free`).
`WardBoard.vue` maps `occupied: []` so the UI never offers the button; the endpoint validates `status` as
free-text `string|max:40` with no `in:` rule. **Pattern 1 inverted: the UI is the guard and the server is
open.** Not driven live — it would strand the demo tenant's only admitted patient irreversibly.
Consequence `P9-M7`: `stays.current_bed_id` has no unique constraint, so the same chain lets two admitted
stays share a bed, and the board's `keyBy('current_bed_id')` silently hides one patient.

**`P9-C3` — THE MOST SEVERE PATTERN-7 INSTANCE THE AUDIT HAS FOUND.** `BedsideChartService` resolves
`StaffProfile::findOrFail($stay->admitting_clinician_id)` and passes it as the ward round's **Encounter
practitioner** (`:72`), the **note author** (`:77`) and the **vital recorder** (`:96`); `$actor` travels
alongside and is used only for the gate and the audit row. Driven end to end as `ward_nurse`: Lena Studer
started a round and the note editor printed **"Version 1 · draft · Dr. med. Martin Keller"** directly
above the page's own *"You author this note"*; `encounters.practitioner_id`, `clinical_notes.author_id`
and `vitals.recorded_by` all store Keller while the audit rows say `actor=25` (Studer). **Unconditional**,
unlike `P8-C2`'s no-profile fallback — Studer has a profile and `StaffProfile::forUser()` returns her
correctly. `admitting_clinician_id` itself is CLEAN (a chosen domain field, required, no default); the
defect is re-purposing a clinical-responsibility field as an attribution. Operational cost: the
one-open-encounter-per-practitioner invariant collapses a stay to one concurrent round, refusing the
second clinician with a message naming a third person. Server-side twin `P9-H6`:
`AccrueBedDaysCommand::resolveBillingActor` picks an org_admin with **no `ORDER BY`** and no permission
check, bypassing `SystemActorResolver::forPermission()` (which every other scheduled command uses), and
persists that person as `created_by` on every bed-day charge, nightly, across every tenant.

**`P9-H2` — the ward board discloses every inpatient and writes no read row.** `auditRead` appears at
exactly four Hospital call sites (`AdmissionController:37`, `BedsideChartController:42`,
`DischargeSummaryController:46`, `HandoverController:32`); `WardBoardController::show` is not one of them,
while `:76` emits each occupant's full name, ward, bed and admission time. **The four surfaces that show
one patient are logged; the one that shows every patient is not.**

**`P9-H3` — 11 `withErrors` sites, zero renderers.** No Hospital page reads `page.props.errors`, imports
`RefusalNotice` or passes `onError`. Driven: clicking *Start ward round* twice returned
`errors.round = "Patient already has an open encounter with this practitioner."` and the screen showed
nothing. The `P6-C3` / `P8-H1` defect, third module.

**`P9-M5` — no nav entry at any width and no click path.** `grep -rn "/hospital" resources/js` returns
**zero hits**. The whole Hospital folder holds two `<Link>`s; nothing links to the chart or the handover;
the ward board is sent `occupant.show_url`, declares it, and never renders it. `bed_manager` is the first
role whose *entire* remit is URL-only. `P9-M6`: `ward.manage` has **no HTTP surface at all** (WardService
create/rename/deactivate are routeless) and only one of `bed.manage`'s three operations is routed.

**Money and time (`P9-M1`–`M4`, `M8`–`M10`):** `Admission.vue` prints raw ISO-8601 on screen
(`2026-09-01T08:00:00+00:00`) while the discharge summary renders the SAME bed-journey event as
`Sep 8, 2026, 3:57 PM` — two pages, one stay, two clocks and two formats. `DischargeSummary.vue` formats
money with a local `fmtAmount` and **no currency at all** (the controller never emits one), and day-shifts
`issue_date` through `new Date('YYYY-MM-DD')` — the D-091 bug in a page written after the fix. Vitals
render as bare chips with no timestamp though `recorded_at` is in the payload (D-191). `invoiceStay` is
four independently committed transactions (orphan draft + duplicate on retry — the D-199 shape QA-FIX.8c
closed elsewhere); `BedBillingController` catches only `AdmissionException|InvalidArgumentException` so a
missing bed-day tariff 500s, and Hospital is the only module with **no `seedStarter` surface**;
`AccrueBedDaysCommand` has no try/catch and restores tenant context outside a `finally`.

**`Stay::TRANSITIONS` / `canTransition` are dead code** (`P9-M11`) — the only module whose declared state
machine has no consumer; `AdmissionService` asserts inline instead.

**D-211 verified and holding:** the admit form's bed selector is still `bed_id: ''` + `required` + an
explicit `selectBed` placeholder, and no Hospital page pre-selects a person or resource. See [[Patients]],
[[Clinical]], [[Platform]], [[LOG]].

## QA-FIX.9c (2026-09-09) — `P9-C3`: a ward round records its WRITER, not the admitting clinician

**THE STUDY, AND WHY THE ANSWER IS NOT QA-FIX.2a's.** `BedsideChartService` resolved
`StaffProfile::findOrFail($stay->admitting_clinician_id)` once and passed it three ways: Encounter
**practitioner**, note **author**, vital **recorder**. D-195 settled the outpatient case by keeping the
ENCOUNTER on its booked clinician and moving only the note. **That rule does not transfer: a ward round
has no booking, so there is no booked clinician to keep.** All three change. Three pieces of evidence
agree — the chart already presents the practitioner as the doer beside the round's timestamp; the
admitting clinician is already recorded on the stay, so copying it onto each round adds nothing and
asserts something false; and Clinical's one-open-encounter-**per-practitioner** invariant collapsed a
whole stay to ONE concurrent round because the practitioner was always the same person (an operational
cost, now covered by a test that two clinicians can round on the same stay).

**WHAT LEGITIMATELY DOES NOT CHANGE:** `stays.admitting_clinician_id` — a different fact (responsibility
for the ADMISSION), chosen on the admit form, asserted unchanged by its own test. Nothing is lost.

**IT WAS UNCONDITIONAL** — worse than `P8-C2`, whose fallback fired only when the actor had no profile.
Here the actor HAS one and `StaffProfile::forUser()` returns it correctly, one call away, never asked.

**REFUSE, DO NOT GUESS (D-195, D-216):** no staff profile → the round throws `InvalidArgumentException`,
the observation `AdmissionException::unidentifiedRecorder()`; both land in catch blocks the controller
**already had**, so no new exception type and no new controller branch.

**HISTORICAL ROWS: COUNTABLE, COUNT IS ZERO.** Unlike `P8-C2`, the substitution was unconditional, so every
affected row is reachable by joining `ward_rounds.encounter_id` to `encounters`, `clinical_notes` and
`vitals`. Across the four demo tenants: **0 rounds, 0 notes, 0 vitals** — no seeder creates a ward round.
No row rewritten (D-197 posture); the query is in D-220 for a real deployment.

**`P9-H6` IS NOT CLOSED BY THIS** — same pattern, different cause: an unattended command has no session
actor, and its remedy is `SystemActorResolver::forPermission()`. Still open.

**Guarded by** 8 tests whose fixture makes **actor ≠ admitting clinician on purpose** — the property whose
absence let `P2-C1`, `P6-C2`, `P7-C1` and `P9-C3` survive their own suites — including the RENDERED chart
name and both refusals. Mutation-checked two ways (round substitution → 5 red; vital substitution → 2 red),
each grep-confirmed with a comment-stripped count. **`BedsideChartTest`'s fixture was CORRECTED** (its
acting user had no staff profile at all, part of why this could hide there); no behaviour assertion
changed. See D-220, [[Clinical]], [[LOG]].

## Refusals are visible (QA-FIX.11a, D-224)

Every Hospital page now renders **`RefusalNotice`** (`resources/js/Components/RefusalNotice.vue`, D-210) — an
**adoption**, not a design: one import, one tag, no restyle, no reword. Before this the module refused
correctly and **no page showed it**, so a refusal and a success were byte-identical to the user.

**If you add a page with a write control, add the notice** — one import plus `<RefusalNotice />` as the
first child of the page wrapper. **If a page has no reachable refusal, do NOT add it** and pin the absence
with a reason (D-176; the `Checklist.vue` precedent).

**It reads the WHOLE error bag on purpose.** `validate()` keys by FIELD, the controllers key by DOMAIN, and
**which one a live control can actually reach differs per page** — on `Lab/Catalog` only the field key is
reachable; on `Hospital/WardBoard` a single refusal produced three messages at once. Never narrow it to
named keys.
