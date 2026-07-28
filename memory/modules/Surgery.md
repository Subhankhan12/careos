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

## WHO Surgical Safety Checklist (SURGERY.G3) — RECORDED, NOT ENFORCED

The three-phase WHO checklist (sign_in / time_out / sign_out) the team COMPLETES — a RECORD, per the map §2.4.
**THE CRUX FENCE LINE: it never blocks/gates the case.** A blocking checklist would be a safety-enforcement
medical device; CareOS records completion, the human team owns the safety decision.

- **`surgical_checklist_template_items`** (BelongsToTenant, MUTABLE config) — the tenant-authored WHO template:
  `phase`, `label`, `display_order`, `active`; `unique(tenant, phase, label)`. Seeded with the standard
  (freely-published) WHO items as an editable starter — NOT a licensed set (the formulary discipline). The
  tenant edits (add / deactivate). Auto-seeded lazily on first `forCase`/`openChecklist` (idempotent).
- **`surgical_checklists`** (BelongsToTenant, LogsReads) — the per-case container (one per case;
  `unique(tenant, surgical_case_id)`); `patient_id` denormalized for read-logging.
- **`surgical_checklist_items`** (BelongsToTenant, LogsReads, **APPEND-ONLY** — model guards + DB triggers
  `surgical_checklist_items_no_update`/`_no_delete`, the `surgical_case_events` recipe) — the completion log:
  one immutable row per confirmation (`phase`+`label` SNAPSHOT, `checked` [the member's fact], `confirmed_by`,
  `confirmed_at`, `note`, soft `template_item_id`). A correction is a NEW row (with a note); the CURRENT state
  of an item = its latest row.
- `Services\SurgicalChecklistService` — `seedTemplate` (gate `surgery.manage`, idempotent); `openChecklist`
  (gate `note.write`, tenant fail-closed, get-or-create the container + auto-seed); `confirmItem(actor, case,
  templateItemId, checked, ?note)` (gate `note.write`, append a confirmation — snapshots phase+label);
  `forCase` (the FACTUAL read model — active items per phase + latest check state + a plain
  `checked_count`/`total`). **It NEVER touches the case status** — no gating.
- `Http\Controllers\SurgicalChecklistController` (string-id FIX.1) — `show` (GET
  `/surgery/cases/{case}/checklist`, `note.write`, read-logged) → `Surgery/Checklist.vue`; `confirm` (POST,
  `note.write`). A link from `Surgery/Case.vue`. Audited app-layer (`surgical_checklist.opened` +
  `surgical_checklist.item_confirmed`, patient-scoped). FIX.5 smoke extended (checklist GET 200 + reception
  confirm 403).
- **RBAC:** read + confirm reuse **`note.write`** (the whole surgical team — surgeon / anesthetist /
  scrub_nurse hold it; reception does not); template seeding is `surgery.manage`.
- **THE FENCE (proven):** the G2 case state machine is UNCHANGED by checklist state — a case transitions
  through the FULL lifecycle (incision included) REGARDLESS of checklist completeness (tested with an EMPTY
  checklist). No computed safety verdict — the schema has no verdict/passed/safe/pass_fail/compliant/score
  column, the read model exposes only a factual count, and a `Modules\Surgery\src` grep finds no
  `safeToProceed`/`checklistPassed`/`gateOnChecklist` method. The Inertia prop is `surgicalCase`, not the
  reserved `case`.

## Consumables + implant tracking (SURGERY.G4)

Items used/placed in a surgery, with a concurrency-safe stock decrement + implant lot/serial/UDI
traceability. Per the map §2.5. **MIRRORS the pharmacy G4 inventory recipe** (Surgery cannot import the peer
Pharmacy vertical, so the recipe is COPIED with Surgery-owned tables) + a **NET-NEW implant traceability
extension** (a recall/regulatory requirement).

- **The mirrored inventory (pharmacy G4 recipe, copied):** `surgical_items` (tenant-authored catalog — `code`,
  `name`, `is_implant` flag, `unit`, `active`; the `FormularyItem` shape) → `surgical_item_stocks` (on-hand,
  mutated ONLY under `SurgicalItemStock::lockOnHand` [`select … for update`], `isBelowThreshold` a factual
  comparison; the `MedicationStock` shape) → `surgical_stock_movements` (APPEND-ONLY ledger,
  received/used/adjusted, signed `quantity_change` + `resulting_on_hand`; model guards + DB triggers; the
  `StockMovement` shape). `SurgicalStockService` (createItem / receive / adjust, gate `surgery.manage`).
- **Consumable USAGE (the `Dispense` shape):** `case_item_usages` (BelongsToTenant, LogsReads, **APPEND-ONLY**)
  — an item used in a case. `SurgicalUsageService::recordUsage` (gate `note.write`, tenant fail-closed) does
  the ATOMIC, concurrency-safe decrement (mirror `DispensingService::dispense`): in ONE `DB::transaction`,
  `lockOnHand` FOR UPDATE → assert on_hand ≥ qty (else `insufficientStock`) → create the usage → decrement →
  append the 'used' movement. **No oversell, no negative on-hand — proven by `SurgicalItemUsageParallelHammerTest`**
  (8 OS processes race for the last unit; 1 `USED:` + 7 `INSUFFICIENT:`, on_hand=0; the
  `surgery:attempt-use-item` hammer, the dispense/bed-claim sibling).
- **IMPLANT lot/serial/UDI TRACEABILITY (the NET-NEW extension):** `implant_placements` (BelongsToTenant,
  LogsReads, **APPEND-ONLY**) — WHICH implant (`lot_number` / `serial_number` / `udi`) went into WHICH patient
  during a case, so a placed implant is TRACEABLE for device recalls (indexed by lot + UDI).
  `SurgicalUsageService::placeImplant` (gate `note.write`; asserts `is_implant` + a lot) does the decrement
  (1 unit, via the same `consume`) AND records the placement, atomically. **The RECALL LOOKUP:**
  `patientsForLot(lot|udi|serial)` returns the placements/patients — a FACTUAL traceability query, never a
  device-safety verdict; `implantsForPatient` is the patient's implant history.
- **RBAC:** stock admin (catalog / receive / adjust) is **`surgery.manage`**; recording usage / placing an
  implant is **`note.write`** (the surgical team — scrub_nurse/surgeon/anesthetist). Reception has neither.
- **UI:** `SurgicalInventoryController` (`/surgery/inventory` — catalog + stock + receive/adjust + the recall
  lookup, `surgery.manage`) → `Surgery/Inventory.vue`; `CaseSuppliesController` (`/surgery/cases/{case}/supplies`
  — record usage/implant + the case's + the patient's implant history, `note.write`, read-logged) →
  `Surgery/CaseSupplies.vue`; links from `Surgery/Case.vue`. Audited app-layer (`surgical_item.created` /
  `surgical_stock.<type>` tenant-level + `surgical_item.used` / `implant.placed` patient-scoped). FIX.5 smoke
  extended (inventory + supplies GET 200 + reception use 403).
- **ELECTRIC FENCE (operational / traceability):** stock/usage/implant records are FACTS. `isBelowThreshold`
  is a factual `on_hand <= threshold` count (never a graded alert); implant traceability is RECORD-KEEPING
  (which implant → which patient), NOT a device-safety judgment — the system records the identifiers, it does
  NOT verify/grade/compute a device-recall verdict (no verdict/safe/recall_status/grade column on any surgical
  inventory table; a `Modules\Surgery\src` grep finds no `verifyDevice`/`recallStatus`/`deviceSafe` method).
  No charge (surgical billing is G5).

## Status

**SURGERY.G1–G4 complete.** G1 = the FOUNDATION (module + theatre + theatre-scheduling [a NET-NEW
`TheatreSlot` reusing the overlap-lock invariant, concurrency-proven] + the NET-NEW `SurgicalCase` + OR RBAC).
**G2 = the case LIFECYCLE (legal-only state machine + append-only `surgical_case_events` + factual per-phase
timestamps) + the surgical TEAM + OP DOCUMENTATION (reuses sign-and-lock `ClinicalNote`/`Encounter`,
Encounter UNMODIFIED, one-open invariant preserved) + the ANESTHETIST-ASSIGNED ASA/Mallampati (recorded
facts).** **G3 = the WHO Surgical Safety Checklist — RECORDED, NOT ENFORCED (the three-phase tenant-authored
template + an append-only completion log; it NEVER gates the case — the case transitions regardless of
checklist completeness; no computed safety verdict).** **G4 = consumables + implant tracking — MIRRORS the pharmacy G4 inventory recipe
(concurrency-safe stock decrement, hammer-proven — 1 winner) + a NET-NEW implant lot/serial/UDI traceability
extension (a factual recall lookup, NOT a device-safety verdict).** Record-not-judge throughout — no computed
surgical-risk / device verdict (the device-data feed stays a partner stub). Verified: composer check FULLY
green (Pint `passed` · PHPStan L5 `[OK] No errors` · **Pest 830 passed / 2 skipped / 8002
assertions**, 0 failed); npm build green; smoke green. See [[D-125]], [[D-126]], [[D-127]], [[D-128]],
`docs/HOSPITAL-PHASE5-SURGERY-MAP.md`.

## Open items / next gates (per docs/HOSPITAL-PHASE5-SURGERY-MAP.md)

- **G1** *(done — D-125)* — module + theatre + theatre-scheduling (NET-NEW `TheatreSlot`, overlap-lock
  invariant) + NET-NEW `SurgicalCase` (scheduled) + OR RBAC. **Next: G2.**
- **G2** *(done — D-126)* — the case LIFECYCLE (legal-only state machine + append-only `surgical_case_events`
  + factual per-phase timestamps) + the surgical team + **op documentation** (reuse sign-and-lock
  `ClinicalNote`/`Encounter` UNMODIFIED, via a `surgical_case_encounters` link; the encounter is opened →
  drafted → CLOSED so the one-open invariant is preserved) + the ASA/Mallampati (anesthetist-ASSIGNED facts).
  **This gate spanned the map's §2.2 [lifecycle] AND §2.3 [op-notes/ASA]** — so the map's "G3 op-notes" is
  folded in here. **Next: the WHO checklist.**
- **G3** *(done — D-127)* — the WHO Surgical Safety Checklist: a NET-NEW three-phase artifact (tenant-authored
  template + per-case container + an APPEND-ONLY completion log) — RECORDED, NOT ENFORCED. It NEVER gates the
  case (the G2 machine is unchanged; a case transitions regardless of checklist completeness — tested); no
  computed safety verdict (a factual `checked/total` count only); reuses `note.write`. **Next: consumables.**
- **G4** *(done — D-128)* — consumables + implant tracking: MIRRORS the pharmacy G4 inventory recipe
  (`surgical_items` → `surgical_item_stocks` [FOR UPDATE lock] → append-only `surgical_stock_movements`;
  `case_item_usages` decrement, concurrency-safe + hammer-proven) + a NET-NEW `implant_placements` lot/serial/UDI
  traceability extension (a factual recall lookup: lot/UDI → patients, NOT a device verdict). Stock admin
  `surgery.manage`; usage `note.write`. **Next: surgical billing.**
- **G5 (next) — surgical billing:** procedure + theatre-time + consumables as `TariffItem`s → `captureManual`
  → invoice → **reconciles-to-the-unit**; the pharmacy/bed-day shape, no new billing math. **This is the last
  Phase-5 core gate.**
- **The device/risk seam stays EMPTY / deferred** — a computed surgical-risk score + the intra-op device-data
  feed (anesthesia machine / monitor) are certified-partner / non-goal surfaces behind a Null seam (the
  `LabConnectivity` / `MedicationSafetyProvider` precedent), never homemade; wired when the anesthesia record
  (their consumer) is built.
