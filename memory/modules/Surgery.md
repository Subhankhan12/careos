# Module: Surgery (`Modules\Surgery`)

## Purpose

The operating-theatre / surgery vertical — **Phase 5** of the phased hospital build (Phase 1 = inpatient/ADT,
Phase 2 = pharmacy, both complete). Planned ~6 core gates (`docs/HOSPITAL-PHASE5-SURGERY-MAP.md`): the module
+ theatre + theatre-scheduling + the surgical case + OR RBAC (G1) → the case lifecycle + append-only case
events (G2) → pre-op/op/post-op notes reusing Clinical (G3) → the WHO Surgical Safety Checklist (G4) →
consumables/implant tracking (G5) → surgical billing (G6). **SURGERY.G1 ships the FOUNDATION only:** the
module, the theatre + theatre-scheduling (a NET-NEW `TheatreSlot`), the NET-NEW `SurgicalCase` (scheduled
status), and OR RBAC. No case lifecycle / checklist / consumables / billing yet. Surgery inherits the whole
tested platform (tenancy, patients, people, clinical, billing, audit, RBAC, the electric fence).

## The theatre-scheduling decision (G1 — THE crux)

Per the map §2.1, the Scheduling `Appointment` is a fixed, service-derived clinic slot with **no per-booking
duration** and **no planned-vs-actual occupancy** (`ends_at` is never updated; an overrun reads *free*) — the
same gap that made a bed a net-new `Stay`. So a theatre is a **Surgery-OWNED entity** (NOT forced into
Scheduling's `Resource`), and a surgical block is a **NET-NEW `TheatreSlot`** that REUSES the
`BookingService::lockResource`→`assertNoOverlap` **INVARIANT** (the concurrency mechanism) but **not** the
day-board model — a surgery is a **BOUNDED, pre-planned block** (`starts_at` + `ends_at`), distinct from both
a clinic slot and a bed's open-ended stay. The exact Bed/Stay "don't force the wrong abstraction" precedent.

## Key tables

- `theatres` (BelongsToTenant) — an operating theatre / OR room: `branch_id`, `name`, `type` (a plain
  tenant-meaningful label — general/cardiac/…), `active`. `unique(tenant_id, branch_id, name)`.
- `theatre_slots` (BelongsToTenant, **NET-NEW**) — a booked surgical BLOCK in a theatre: `theatre_id` (FK),
  `surgical_case_id` (**SOFT** nullable ref, no FK), `starts_at`, `ends_at`, `status` ∈ {booked, in_progress,
  completed, cancelled} (`BLOCKING_STATUSES` = booked + in_progress occupy the theatre). Index
  (tenant, theatre, starts_at) for the overlap query. NO money/judgment stored.
- `surgical_cases` (BelongsToTenant, **LogsReads**) — a NET-NEW surgery case: `patient_id` (FK),
  `primary_surgeon_id` (FK staff_profiles), `stay_id` (**SOFT** nullable ref to a Phase-1 inpatient stay — no
  FK; null = day-surgery), `procedure_description` (free text in G1), `scheduled_at`, `status` (default
  `scheduled`). The full lifecycle (pre_op → in_progress → completed → post_op) + append-only case events are
  G2. Index (tenant, patient), (tenant, status).

## Key classes

- `Models\Theatre` — BelongsToTenant + HasUlids; a `saving` guard (non-empty name) + a `creating`/`updating`
  guard (branch within tenant, fail-closed).
- `Models\TheatreSlot` — BelongsToTenant + HasUlids; STATUS + `BLOCKING_STATUSES` (the
  `Appointment::blockingStatuses` analogue); `theatre()` belongsTo.
- `Models\SurgicalCase` — BelongsToTenant + HasUlids + **LogsReads**; STATUS_SCHEDULED (+ the future lifecycle
  constants documented); `patient()`/`primarySurgeon()` belongsTo + `slot()` hasOne (soft link);
  `auditPatientId` → patient_id; `assertReferencesWithinTenant` (patient + surgeon; `stay_id` is a deliberate
  soft ref, not existence-checked).
- `Services\TheatreSchedulingService` — `createTheatre` (gate `theatre.manage`) + `bookSlot` (gate
  `surgery.schedule`, THE overlap-lock invariant below).
- `Services\SurgicalCaseService` — `schedule` (gate `surgery.manage`, patient + surgeon fail-closed) +
  `forPatient` read.
- `Exceptions\TheatreException` (nameRequired / nonPositiveDuration / slotConflict) + `SurgicalCaseException`
  (procedureRequired).
- `Console\AttemptBookSlotCommand` (`surgery:attempt-book-slot`) — the parallel-hammer booking (concurrency
  test only), the `pharmacy:attempt-dispense` / `hospital:attempt-bed-claim` sibling; registered in the
  provider.
- `Providers\SurgeryServiceProvider` — loadMigrations + registers the hammer command. (The intra-op
  device-data / surgical-risk seam arrives with its consumer in a later gate — nothing invokes it in G1.)

## The overlap-lock invariant (concurrency — the crux)

`TheatreSchedulingService::bookSlot` runs, in ONE `DB::transaction`: `lockTheatre` (`select id from theatres
… for update` — serialise concurrent bookings for the same theatre, the `BookingService::lockResource` idiom)
→ `assertNoOverlap` (`select … from theatre_slots where theatre_id = ? and status in (booked, in_progress)
and starts_at < ?end and ends_at > ?start for update` — two intervals overlap iff each starts before the
other ends) → insert. **Two overlapping surgeries in one theatre → exactly one wins** (the
`BedService::claim` / `MedicationStock` decrement family). **Proven by `TheatreBookingParallelHammerTest`** —
8 OS processes race for the same contested block; 1 `BOOKED:` + 7 `CONFLICT:`; one slot exists. A
non-overlapping/adjacent block, or the same time in a different theatre, is allowed.

## Invariants

- **Tenant + branch scoped, fail-closed.** `BelongsToTenant` confines every query; a cross-tenant theatre
  booking or a cross-tenant case reference throws `CrossTenantReferenceException` (the theatre-row lock also
  re-checks tenant).
- **Overlap-safe theatre booking** (the invariant above) — no double-booked theatre, concurrency-proven.
- **Append-only audit.** `theatre.created` / `theatre_slot.booked` (tenant-level) + `surgical_case.scheduled`
  (patient-scoped) via app-layer `::created` hooks, so Surgery stays free of Audit. The case is read-logged
  (`LogsReads`).
- **ELECTRIC FENCE (operational/scheduling).** A theatre/slot/case is a human-recorded fact — no computed
  acuity/priority/risk/severity/triage/score/urgency/grade column (schema fence, tested). A surgical-risk
  score is the fence line (map §3), a certified-partner / non-goal, never here; the ASA class (an
  anesthetist-**assigned** fact) arrives with the anesthesia record in a later gate.
- **Soft inpatient link.** `stay_id` / `theatre_slots.surgical_case_id` are soft nullable ULIDs (no FK),
  composed app-layer — Surgery stays arch-independent of Hospital (the pharmacy `stay_id` precedent).

## Arch boundary

`arch('Surgery may use care modules + Audit services but not Audit models, AiCore, peer verticals, or
Comms')` — mirrors Dental/Hospital/Pharmacy. Surgery may use Platform + care modules (Patients/People/
Clinical/Billing/Scheduling) + Audit SERVICES; it must NOT use `Audit\Models`, AiCore, Comms, or the **peer
verticals** Nursing/Dental/Hospital/Pharmacy (the inpatient stay-link is a soft app-layer id, not a direct
Hospital dependency). Cross-module audit composition (the Theatre/TheatreSlot/SurgicalCase hooks) lives in
`app/AppServiceProvider`, so Surgery stays free of Audit. The theatre reuses the Scheduling booking INVARIANT
by copying the SQL idiom, NOT by importing `BookingService` (Surgery owns its theatre model).

## RBAC (additive — the `dental.chart`/inpatient/pharmacy precedent)

New permissions: `theatre.manage` (author OR rooms), `surgery.schedule` (book theatre blocks), `surgery.manage`
(create/manage surgical cases). New starter roles: `surgeon` (surgery.manage + surgery.schedule + clinical
read/write), `anesthetist` (surgery.manage + clinical read/write — the anesthesia record + ASA come later),
`scrub_nurse` (patient.view + note.write — checklist.complete/stock come later), `surgical_scheduler`
(theatre.manage + surgery.schedule + appointment.manage). `org_admin` gains all three. Added via the
RbacProvisioner consts + a `provisionTenant`-all backfill migration (the `add_medication_prescribe_permission`
pattern); new tenants via the Tenant `created` hook. `RbacTest`'s permission-count is self-referential to the
const; `RbacNegativeSweepTest`'s withheld-map is untouched (no withheld perm granted to a listed role).

## Case lifecycle + op documentation (SURGERY.G2)

The peri-operative lifecycle + op documentation + anesthetist-assigned values, extending the G1 case (map
§2.2/§2.3). Reuses the inpatient bedside-charting pattern; **Clinical's `Encounter` is UNMODIFIED**.

- **The LEGAL-ONLY lifecycle.** `SurgicalCase::TRANSITIONS` (scheduled → {pre_op, cancelled}; pre_op →
  {in_progress, cancelled}; in_progress → completed; completed → post_op; post_op/cancelled terminal) +
  `canTransition`. `SurgicalCaseService::transition` (gate `surgery.manage`, tenant fail-closed): assert legal
  (else `SurgicalCaseException::invalidTransition`) → in ONE `DB::transaction`, `forceFill` status +
  status_reason + the per-phase timestamp (`pre_op_at` / `in_progress_at` [incision] / `completed_at` /
  `post_op_at` / `cancelled_at` — FACTUAL times) + append a `SurgicalCaseEvent`. The `MedicationOrder`/`Stay`
  transition shape (model-hook audit, not a domain event).
- **`surgical_case_events`** (BelongsToTenant, LogsReads, **APPEND-ONLY** — model `updating`/`deleting` guards +
  DB triggers `surgical_case_events_no_update`/`_no_delete`, the `medication_order_events` recipe) — one
  immutable row per transition (`event_type` = the target phase, `reason`, `performed_by`, `occurred_at`).
  Audited app-layer (`surgical_case.<event_type>`, patient-scoped) so Surgery stays free of Audit.
- **The surgical TEAM.** `surgical_case_team_members` (BelongsToTenant) — surgeon / anesthetist / scrub_nurse
  (the G1 roles) + `other`; `unique(tenant, case, staff)` = one per person (explicit short index name
  `surg_case_team_member_unique`); `addTeamMember` (gate `surgery.manage`, `updateOrCreate` = re-role).
- **Op documentation — REUSE Clinical, `Encounter` UNMODIFIED.** `surgical_case_encounters` (the `ward_rounds`
  link — `surgical_case_id`, `encounter_id` FK, `phase` ∈ {pre_op, operative, post_op}; `unique(tenant,
  encounter_id)`). `SurgicalCaseService::startNote(actor, case, phase)`: opens a `TYPE_PROCEDURE` `Encounter`
  via the EXISTING `EncounterService::open`, links it Surgery-side, drafts a `ClinicalNote` via
  `ClinicalNoteService::saveDraft`, then **CLOSES the encounter** — so no lingering open encounter can break
  the one-open-per-practitioner invariant for other verticals (tested). The surgeon writes → signs → amends
  the note through the EXISTING note editor (`clinical.notes.edit`), unchanged. Surgery MAY `use
  Modules\Clinical` (arch — the `BedsideChartService` posture); the note trail is audited by Clinical's
  existing `Encounter`/`ClinicalNote` listeners (no bespoke hook).
- **ASA / Mallampati — ANESTHETIST-ASSIGNED (recorded facts, NEVER computed).**
  `recordAnesthesiaAssessment(actor, case, asaClass, ?mallampati, anesthetist)` (gate `surgery.manage`)
  validates the closed sets (`ASA_CLASSES` I–VI / `MALLAMPATI_CLASSES` I–IV; else `invalidAsaClass` /
  `invalidMallampati`), records `asa_class` / `mallampati` + provenance (`asa_assessed_by` / `asa_assessed_at`)
  on the case. **ELECTRIC FENCE: CareOS records the ASSIGNED value; it computes NO surgical-risk
  score/prediction** (schema fence + a `Modules\Surgery\src` grep for `computeRisk`/`riskScore`/`predictRisk`).
- **The anesthesia DEVICE-DATA feed stays DEFERRED (partner-gated).** Anesthesia DOCUMENTATION (the ASA record)
  is buildable; the intra-op device-data feed (anesthesia machine / patient monitor) is a partner seam — noted,
  NOT built (a grep test asserts no `DeviceFeed`/`AnesthesiaMachine`/`hl7` code in the module).
- **UI:** `SurgicalCaseController` (`index` board + `store` schedule + `show` [read-logged] + `transition` +
  `team` + `anesthesia` + `startNote` → redirect to the note editor) → `Surgery/CaseBoard.vue` +
  `Surgery/Case.vue` + i18n. **Gotcha:** the case Inertia prop is `surgicalCase`, NOT `case` (a JS reserved
  word — `{{ case.x }}` fails the Vue template compiler). FIX.5 smoke extended (case board + detail GET 200 +
  reception transition 403).

## Status

**SURGERY.G1–G2 complete.** G1 = the FOUNDATION (module + theatre + theatre-scheduling [a NET-NEW
`TheatreSlot` reusing the overlap-lock invariant, concurrency-proven] + the NET-NEW `SurgicalCase` + OR RBAC).
**G2 = the case LIFECYCLE (legal-only state machine + append-only `surgical_case_events` + factual per-phase
timestamps) + the surgical TEAM + OP DOCUMENTATION (reuses sign-and-lock `ClinicalNote`/`Encounter`,
Encounter UNMODIFIED, one-open invariant preserved) + the ANESTHETIST-ASSIGNED ASA/Mallampati (recorded
facts).** Record-not-judge throughout — no computed surgical-risk (the device-data feed stays a partner
stub). Verified: composer check FULLY green (Pint `passed` · PHPStan L5 `[OK] No errors` · **Pest 814
passed / 2 skipped / 7332 assertions**, 0 failed); npm build green; smoke green. See [[D-125]],
[[D-126]], `docs/HOSPITAL-PHASE5-SURGERY-MAP.md`.

## Open items / next gates (per docs/HOSPITAL-PHASE5-SURGERY-MAP.md)

- **G1** *(done — D-125)* — module + theatre + theatre-scheduling (NET-NEW `TheatreSlot`, overlap-lock
  invariant) + NET-NEW `SurgicalCase` (scheduled) + OR RBAC. **Next: G2.**
- **G2** *(done — D-126)* — the case LIFECYCLE (legal-only state machine + append-only `surgical_case_events`
  + factual per-phase timestamps) + the surgical team + **op documentation** (reuse sign-and-lock
  `ClinicalNote`/`Encounter` UNMODIFIED, via a `surgical_case_encounters` link; the encounter is opened →
  drafted → CLOSED so the one-open invariant is preserved) + the ASA/Mallampati (anesthetist-ASSIGNED facts).
  **This gate spanned the map's §2.2 [lifecycle] AND §2.3 [op-notes/ASA]** — so the map's "G3 op-notes" is
  folded in here. **Next: the WHO checklist.**
- **G3 (next) — the WHO Surgical Safety Checklist:** a NET-NEW structured record-not-judge artifact (sign-in /
  time-out / sign-out; the `VisitTask`/`Handover` recipe) — a record of what the team confirmed, **never a
  safety gate or compliance score**.
- **G5** — consumables / implant tracking: REUSE the pharmacy inventory recipe (stock under a `FOR UPDATE`
  lock + append-only `StockMovement` ledger) + a NET-NEW lot/serial/expiry/UDI extension (implant
  traceability — the pharmacy stock model has none).
- **G6** — surgical billing: procedure + theatre-time + consumables as `TariffItem`s → `captureManual` →
  invoice → **reconciles-to-the-unit**; the pharmacy/bed-day shape, no new billing math.
- **The device/risk seam stays EMPTY / deferred** — a computed surgical-risk score + the intra-op device-data
  feed (anesthesia machine / monitor) are certified-partner / non-goal surfaces behind a Null seam (the
  `LabConnectivity` / `MedicationSafetyProvider` precedent), never homemade; wired when the anesthesia record
  (their consumer) is built.
