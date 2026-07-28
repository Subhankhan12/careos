# Module: Surgery (`Modules\Surgery`)

## Purpose

The operating-theatre / surgery vertical — **Phase 5** of the phased hospital build (Phase 1 = inpatient/ADT,
Phase 2 = pharmacy, both complete). **Phase 5 shipped in 5 gates** (`docs/HOSPITAL-PHASE5-SURGERY-MAP.md`): the
module + theatre + theatre-scheduling + the surgical case + OR RBAC (G1) → the case lifecycle + append-only
case events **+ op documentation reusing Clinical + the ASA record** (G2 — the map's op-notes §2.3 folded in) →
the WHO Surgical Safety Checklist (G3) → consumables/implant tracking (G4) → surgical billing (G5). **PHASE 5
IS NOW COMPLETE.** Surgery inherits the whole tested platform (tenancy, patients, people, clinical, billing,
audit, RBAC, the electric fence).

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

## Surgical billing (SURGERY.G5) — the FINAL Phase-5 gate

A surgical case accrues charges (procedure + theatre-time + consumables/implants) through the **EXISTING**
billing engine, and they invoice + **RECONCILE-TO-THE-UNIT**. Per the map §2.6. **STRICTLY ORCHESTRATION —
NO new billing/pricing/VAT/line-total math** (the pharmacy G5 / bed-day HOSPITAL.G6 shape, copied because
Surgery cannot import the peer verticals but MAY use Billing). The adversarial-grep discipline over
`Modules\Surgery\src` finds ZERO money math.

- **Each billable thing is a tenant-authored `TariffItem`** in a `surgery` `TariffCatalog` (`SurgicalBillingService::catalog()` firstOrCreate; integer minor units, `vat_rate_bp` 0 — NO licensed pricing):
  a **procedure** (`priceProcedure` — its own code, unit `procedure`), **theatre-time** (`priceTheatreTime` — the
  fixed `THEATRE-TIME` code, unit `theatre-minute`), and each **consumable/implant** (`priceItem` — authored
  against the G4 `surgical_items.code`, linking the new soft `surgical_items.tariff_item_id`; `SurgicalItem::isPriced()`).
  All via a private `authorTariff` = `TariffItem::updateOrCreate` keyed `(tariff_catalog_id, code)`.
- **Charge capture via the EXISTING engine — the module computes NO money.** `chargeCase(actor, case,
  ?procedureCode, ?theatreMinutes)` (gate `billing.manage`, tenant fail-closed): idempotent (any
  `surgical_case_charges` row → return the existing charges); else resolve `Patient` + `Branch::firstOrFail`,
  serviceDate = `completed_at ?? scheduled_at`, and push a `ChargeCaptureService::captureManual` per billable —
  the procedure (×1), theatre-time (×minutes), and one per **priced** consumable/implant used (×total, from the
  G4 `case_item_usages`, via `pricedUsageTotals` which SKIPS unpriced items). **The ENGINE resolves the tariff
  by code, SNAPSHOTS the fee, and computes the line total** — Surgery never does. Each capture is linked once
  via **`surgical_case_charges`** (`unique(tenant, charge_id)` = the `dispense_charges` idempotency bridge;
  stores NO money).
- **Invoice via the EXISTING flow — reconciles-to-the-unit.** `invoiceCase(actor, case)` (gate `billing.manage`):
  `validateForPatientPeriod` (draft→validated) → gather the patient's VALIDATED, uninvoiced charges on the
  case's service day → `IssueService::createDraftFromCharges(SELF_PAY)` → `issue` (gapless number + PDF). The
  existing `ReconciliationEngine::check(period)` ties out: **I4 `delta_minor === 0`** with surgical charges
  present (THE key proof). **INPATIENT path:** a surgical case's charges are just patient charges with a
  service_date in the stay window — Hospital's **`BedBillingService::invoiceStay`** sweeps them onto the stay
  discharge invoice (same gather-by-patient+period) **without Surgery importing Hospital** — reconciles δ=0 too.
- **RBAC — the billing office bills, NOT the OR team.** Pricing + charge + invoice all reuse **`billing.manage`**
  (NO new permission, NO RBAC migration; `org_admin` holds it). The surgeon (`surgery.manage` but not
  `billing.manage`) is REFUSED; reception is refused. Cross-tenant fail-closed (`CrossTenantReferenceException`).
- **NO audit hook for the link table** — the `Charge`/`Invoice` are audited by Billing itself (the
  `DispenseCharge` precedent: `PharmacyBillingService` added none either).
- **UI:** `SurgicalPricingController` (`/surgery/pricing` — set procedure/theatre-time/item prices,
  `billing.manage`) → `Surgery/SurgicalPricing.vue`; `SurgicalBillingController` (`/surgery/cases/{case}/billing`
  — capture charges + issue invoice + link to the Billing invoice, `billing.manage`, read-logged) →
  `Surgery/CaseBilling.vue`; a billing link from `Surgery/Case.vue`. **FENCE-in-the-UI:** the controller reads
  the issued invoice's `total_minor` (a Billing figure) but NEVER a charge's `line_total_minor` (that literal is
  fence-forbidden in the module) — the per-line + pre-invoice estimate math is done presentationally in the Vue
  (`quantity × the snapshotted rate`), the same class as its minor→major formatting. FIX.5 smoke extended
  (pricing + case-billing GET 200 + reception charge 403).
- **ELECTRIC FENCE (financial).** A surgical price is a **RATE** (financial/administrative), never a
  clinical/appropriateness verdict — `surgical_case_charges` stores no money; `surgical_items` carries no
  verdict/appropriateness/medical_necessity/severity/score column; the `line_total_minor`/`vat_total_minor`/
  `subtotal_minor`/`vatMinor`/`intdiv(` grep over `Modules\Surgery\src` is clean. The Inertia prop is
  `surgicalCase`, not the reserved `case`.

## Status

**PHASE 5 (OR / SURGERY) COMPLETE — SURGERY.G1–G5 all shipped.** G1 = the FOUNDATION (module + theatre +
theatre-scheduling [a NET-NEW `TheatreSlot` reusing the overlap-lock invariant, concurrency-proven] + the
NET-NEW `SurgicalCase` + OR RBAC). **G2 = the case LIFECYCLE (legal-only state machine + append-only
`surgical_case_events` + factual per-phase timestamps) + the surgical TEAM + OP DOCUMENTATION (reuses
sign-and-lock `ClinicalNote`/`Encounter`, Encounter UNMODIFIED, one-open invariant preserved) + the
ANESTHETIST-ASSIGNED ASA/Mallampati (recorded facts).** **G3 = the WHO Surgical Safety Checklist — RECORDED,
NOT ENFORCED (the three-phase tenant-authored template + an append-only completion log; it NEVER gates the
case — the case transitions regardless of checklist completeness; no computed safety verdict).** **G4 =
consumables + implant tracking — MIRRORS the pharmacy G4 inventory recipe (concurrency-safe stock decrement,
hammer-proven — 1 winner) + a NET-NEW implant lot/serial/UDI traceability extension (a factual recall lookup,
NOT a device-safety verdict).** **G5 = surgical billing — procedure + theatre-time + consumables/implants as
tenant-authored `TariffItem`s → charge capture via the EXISTING `ChargeCaptureService` → invoice via the
EXISTING flow → RECONCILES-TO-THE-UNIT (I4 δ=0, both a standalone case invoice AND an inpatient stay's
`invoiceStay`); STRICTLY ORCHESTRATION, zero new money math (adversarial grep clean); `billing.manage`-gated
(the billing office, not the OR team).** **An OR can now, end-to-end: author theatres + overlap-safely
schedule a surgical block → drive the case lifecycle + document it (reusing Clinical) → record the WHO
checklist → track consumables/implants (recall-traceable) → BILL it, reconciling to the unit.** Record-not-judge
throughout — no computed surgical-risk / device verdict (the anesthesia device-data feed stays a partner stub;
a computed surgical-risk score is a non-goal). Verified: composer check FULLY green (Pint `passed` · PHPStan
L5 `[OK] No errors` · **Pest `837` passed / `2` skipped / `8346` assertions**, 0 failed);
npm build green; smoke green. See [[D-125]], [[D-126]], [[D-127]], [[D-128]], [[D-129]],
`docs/HOSPITAL-PHASE5-SURGERY-MAP.md`.

**Deliberate Phase-5 gaps (fence / partner seams, NOT omissions):** the intra-op anesthesia **device-data
feed** (anesthesia machine / patient monitor — HL7/device ingestion) is a **certified-partner seam**, noted +
stubbed, never homemade; a **computed surgical-risk score** is a **non-goal** (the ASA class is an
anesthetist-ASSIGNED fact, never computed). **Next verticals: Phases 3 (lab), 4 (radiology), 6 (ED) remain.**

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
- **G5** *(done — D-129)* — surgical billing: procedure + theatre-time + consumables/implants as tenant-authored
  `TariffItem`s (`surgery` catalog; `surgical_items.tariff_item_id` soft link) → charge capture via the EXISTING
  `ChargeCaptureService::captureManual` (engine snapshots the fee + computes the line total) → invoice via the
  EXISTING `validate → createDraftFromCharges → issue` flow → **reconciles-to-the-unit** (I4 δ=0, standalone
  case AND inpatient `invoiceStay`). Idempotent via `surgical_case_charges`. STRICTLY ORCHESTRATION — the
  adversarial money-math grep over `Modules\Surgery\src` is clean. `billing.manage`-gated (NO new permission —
  the billing office bills, the OR team does not). **This was the LAST Phase-5 core gate — Phase 5 is COMPLETE.**
- **The device/risk seam stays EMPTY / deferred** *(unchanged by G5)* — a computed surgical-risk score + the
  intra-op device-data feed (anesthesia machine / monitor) are certified-partner / non-goal surfaces behind a
  Null seam (the `LabConnectivity` / `MedicationSafetyProvider` precedent), never homemade; wired when the
  anesthesia record (their consumer) is built.
- **Next verticals (outside Surgery): Phases 3 (lab), 4 (radiology), 6 (ED) remain** per the master hospital
  build sequence — Surgery/Phase-5 itself needs no further core gate.
