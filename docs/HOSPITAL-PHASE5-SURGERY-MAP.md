# CareOS — Hospital Phase 5 (Operating Theatre / Surgery): Reconciliation + Build-Sequence Map

**Status: analysis only — NO code.** This is the map-before-building step for **Phase 5 of the phased
hospital build** (Phase 1 = inpatient / ADT, complete; Phase 2 = pharmacy, complete). It draws — precisely —
the line between the **BUILDABLE perioperative core** (theatre scheduling, the surgical case + its lifecycle,
pre-op / op / post-op notes, the WHO Surgical Safety Checklist, consumables/implant tracking, surgical
billing — all *record-keeping*) and the **PARTNER-GATED / REGULATED** surfaces (a **computed** surgical-risk
score, intra-op **device-data** feeds) that must be **stubbed at a seam, never built homemade**. Same
discipline as `docs/HOSPITAL-PHASE1-ADT-MAP.md`, `docs/HOSPITAL-PHASE2-PHARMACY-MAP.md`,
`docs/DENTAL-DELIVERY-MAP.md`, `docs/CLINIC-DELIVERY-MAP.md`.

> The referenced `careos-hospital-expansion-scoping.md §2.5` is not committed to this repo; this map is
> derived from the authoritative source — the codebase itself — and reconciles against it. **This is Phase 5;
> the remaining hospital phases — lab (LIS), radiology (RIS/PACS), and emergency (ED/triage) — are each mapped
> before building, in whatever order a customer need pulls forward (build order ≠ phase number).**

**The one-sentence thesis.** Everything the surgical *record* needs already has a fence-clean precedent to
reuse (the theatre is a bookable `Resource` + the `lockResource → assertNoOverlap` mechanism; the surgical
case is the **Bed/Stay** "own-model-above-a-reused-primitive" precedent; op notes reuse the sign-and-lock
`ClinicalNote`; consumables reuse the pharmacy inventory recipe; charges reuse `captureManual` →
`ReconciliationEngine`; RBAC is additive) — the **WHO Surgical Safety Checklist is a *buildable*
record-not-judge artifact** (the team checks items; the system records completion, it computes no safety) —
and the only things that must **never** be built homemade are a **computed surgical-risk score** and the
**intra-op device-data feed**, both already precedented as **partner seams** (`LabConnectivity` →
`ManualLabConnectivity`; `MedicationSafetyProvider` → `NullMedicationSafetyProvider`) and locked out by the
electric fence (`AGENTS.md:38-39`). **Build the record; stub the judgment; the ASA class is *assigned*, never
computed.**

---

## Section 0 — What CareOS already provides that Surgery REUSES (the head start)

| Existing capability | How surgery uses it | Reuse quality |
|---|---|---|
| **Multi-tenancy** (`BelongsToTenant`, fail-closed) | A hospital's OR suite = a tenant; every theatre / case / checklist / consumable row is tenant-owned, invisible without tenant context. | Clean (free) |
| **Patient master** (Patients) | A surgery is performed on a `Patient` (MRN, allergies, consent, coverages). No new patient model. | Clean |
| **Scheduling `Resource` + the booking mechanism** (`Resource` type=`room`/`practitioner`; `BookingService::lockResource`→`assertNoOverlap`; `ResourceAvailability`; branch-hours) | A **theatre is a `Resource` (`type='room'`)**; the surgeon/anesthetist/nurses are `practitioner` resources; the surgical block reuses the **row-lock-then-assert-no-overlap** mechanism + availability/branch-hours gates for no-double-book. **But the `Appointment` *table* does not fit a variable block** (§2.1). | Pattern precedent (§2.1) |
| **Inpatient `Stay`** (Hospital Phase 1) | An **inpatient** surgery ties to a `Stay`; the surgical **case sits *above* the reserved theatre block exactly as a `Stay` sits above an `Encounter`** — the `WardRound` soft-link precedent (app-layer). | Clean (compose) |
| **Clinical `Encounter` + sign-and-lock `ClinicalNote`** (`Encounter::TYPE_PROCEDURE`; `ClinicalNote` draft→signed + conditional DB immutability) | **Pre-op assessment, the operation note, and the post-op note reuse the sign-and-lock `ClinicalNote`** hung off a surgical `Encounter` — the bedside-charting (HOSP.G4) reuse. `Encounter` unmodified (or one new `TYPE_SURGERY` enum value). | Clean (reuse) |
| **Pharmacy inventory recipe** (`MedicationStock` under a `FOR UPDATE` lock + append-only `StockMovement` ledger + `StockService`) | **Consumables/implant stock** reuses the stock + append-only-signed-delta-ledger + lock-then-transaction *recipe* (Phase 2 G4). | Pattern precedent (§2.5) — with a net-new lot/serial/expiry/UDI extension |
| **Billing engine** (`TariffItem` · `ChargeCaptureService::captureManual` · `IssueService` · `ReconciliationEngine`) | A **procedure / theatre-time / consumable is a `TariffItem`**; a case **accrues `Charge`s**; the invoice is the existing gather→issue flow; **reconciles-to-the-unit** — the bed-day (G6) / pharmacy (G5) precedent, zero new math. | Clean (orchestration only — §2.6) |
| **Append-only clinical-event recipe** (model `updating`/`deleting` guards + `SIGNAL '45000'` DB triggers: `stay_events`, `medication_administrations`, `stock_movements`) | A **case-lifecycle event**, a **checklist-phase completion**, and an **anesthesia record** are append-only (a correction is a new row) — copy the recipe verbatim. | Clean (drop-in) |
| **Record-not-judge model contracts** (`Vital` / `Handover` / `OrderResult` / `MedicationAdministration` — "no severity/acuity/score/risk/verdict/flag column") | Checklist completion, the ASA class, and case timings are human-entered **FACTS** — the exact posture already stated on those models. | Clean (+ the fence) |
| **Append-only audit** (hash-chained, immutable) | Every case transition / checklist completion is one append-only audit row via an app-layer model hook — the `admission.<state>` / `handover.recorded` pattern. | Clean (drop-in) |
| **RBAC** (`RbacProvisioner::PERMISSIONS`/`ROLE_TEMPLATES`, `Gate::before` unchanged) | `surgeon` / `anesthetist` / `scrub_nurse` / `surgical_scheduler` roles + `surgery.*` permissions are **additive const entries** — the `dental.chart` / inpatient / pharmacy precedent (§4). | Clean (additive) |
| **The partner SEAM pattern** (`LabConnectivity`→`ManualLabConnectivity`, bound in a provider; `MedicationSafetyProvider`→`NullMedicationSafetyProvider`) | The **intra-op device-data / computed-risk seam** is a 1:1 copy — an interface bound to a Null/Manual no-op, swappable for a certified partner later (§3). | Clean (the crux) |
| **Governed AI + electric fence + `composer eval`** (refuses "is this getting worse?" / "should we change meds?") | Any surgery agent help (a scheduling or op-note draft) is **draft-only, human-owned**; a computed risk/priority judgment is **fence-inconsistent and eval-rejected** (§3). | Clean (+ the fence) |
| **Design system** (Eucalyptus Glow) + **route-smoke / MySQL-parity / immutability guards** | The theatre board / case / checklist UIs reuse tokens + primitives; new routes ride the existing smoke + parity guards. | Clean |

**Read-off:** the surgical *record* is ~75% reuse. The genuinely net-new domain is the **theatre + surgical
case + lifecycle**, the **WHO checklist artifact**, and a **lot/serial-tracked consumable/implant store** — and
the only things that must **never** be built are the **computed risk score** and the **device-data feed**.

---

## Section 1 — The surgical spine, mapped (reuse vs net-new vs partner)

| # | Spine element | What it needs | REUSE | NET-NEW surgical domain | ⛔ PARTNER / DEVICE / NON-GOAL (stub) |
|---|---|---|---|---|---|
| 1 | **Theatre + surgical scheduling** | A theatre reserved as a variable continuous block, with the surgical team held simultaneously; no-double-book | Theatre = `Resource` (`type='room'`); team = `practitioner` resources; the **`lockResource`→`assertNoOverlap` mechanism** + `ResourceAvailability` + branch-hours gates | **`OperatingTheatre`** (or a room `Resource` + a thin overlay) + a **surgical block reservation** on the *case's own* variable window (§2.1) | none |
| 2 | **Surgical case / procedure record** | The planned + performed procedure: patient, theatre, surgeon, team, date, planned-vs-actual times, phases | The `Stay`/`Order` **status-machine + append-only-event *shape***; the `Stay` soft-link (inpatient); `Encounter` (op note) | **`SurgicalCase`** (planned+actual times, theatre, team roster, status machine) + append-only **`CaseEvent`** (§2.2) | none |
| 3 | **Pre-op assessment + op / post-op notes** | Sign-and-lock clinical documentation across the peri-operative phases; the ASA class | **`Encounter` (`TYPE_PROCEDURE`) + sign-and-lock `ClinicalNote`** (draft→signed + conditional DB lock) — the HOSP.G4 reuse | A **case-scoped note view** + (optional) a `TYPE_SURGERY` enum value | The **ASA class is *assigned* by the anesthetist (a fact)**; a **computed** risk score is fenced (§3) |
| 4 | **WHO Surgical Safety Checklist** | Sign-in / time-out / sign-out, each with N human-confirmed items | The **append-only + sign-and-lock recipe** + the **record-not-judge** contract; the `VisitTask` (status + mandatory reason) / `ConsentTemplate→PatientConsent` (template+snapshot) shapes | **`SurgicalChecklist`** (phase + N confirmed items + `completed_by` + at-phase timestamp) (§2.4) | none — it is a **checklist (a record), NOT a safety gate/verdict** (§3) |
| 5 | **Consumables / implant tracking** | Stock of consumables + implants used in a case; **implants need lot/serial/UDI/expiry** | The **pharmacy inventory recipe**: stock under a `FOR UPDATE` lock + append-only `StockMovement` ledger + `StockService` | **`SurgicalItem` catalog + lot-keyed stock + case-usage link** + a **net-new lot/serial/expiry/UDI extension** (§2.5) | Barcode/**UDI-scanning hardware** = a device surface, later |
| 6 | **Surgical billing** | The procedure + theatre-time + consumables as one invoice | **Billing engine unchanged**: `TariffItem` → `captureManual` → `Charge` → `validateForPatientPeriod` → `createDraftFromCharges` → `issue`; `ReconciliationEngine` I4 | **Orchestration only**: capture charges per case (the pharmacy/bed-day shape) (§2.6) | none (facts / money) |
| 7 | **Anesthesia record + intra-op data** | The anesthetist's documentation; the intra-op monitor/machine data | The append-only + record-not-judge recipe (the anesthetist's **entries**) | An **anesthesia record** (the anesthetist's fields; ASA; agents given as recorded facts) | **Intra-op DEVICE-DATA feed** (anesthesia machine / patient monitor) = a **partner seam**; auto-ingested values are **never interpreted** (§3) |
| 8 | **Surgical-risk / decision judgment** (the crux) | *(nothing — CareOS records ASA + the team's findings)* | *(nothing)* | *(nothing)* | **A computed risk/mortality/complication score or CDS** — a certified partner behind a **stub seam**, or a **non-goal**; homemade = permanent non-goal (§3) |

---

## Section 2 — The load-bearing reuse decisions (get these right)

### 2.1 Theatre scheduling — **reuse the Resource + overlap MECHANISM; a surgery is a net-new block, NOT an `Appointment`.** (The Bed/Stay call, again.)

A theatre reserves cleanly onto the Scheduling **`Resource`** layer: `Resource` is typed
`practitioner|room|chair|vehicle` (`Resource.php:30-43`), so a **theatre is a `room`**, an exclusive singleton
whose 1-at-a-time occupancy is exactly what an OR needs; the surgeon/anesthetist/nurses are `practitioner`
resources. And the booking engine **already holds several resources simultaneously** — `BookingService::book`
takes a `resourceIds[]` array, locks each with `lockResource()` (`SELECT … FOR UPDATE`,
`BookingService.php:293-303`), overlap-checks them all against one shared window (`assertNoOverlap`,
`:305-338`), and links them via the `appointment_resources` pivot. Availability windows
(`ResourceAvailability`) and the branch-hours gate come for free.

**But the `Appointment` *table* is structurally a fixed-length clinic slot — it does not fit a surgery:**

1. **No per-booking duration.** `Appointment` stores only `starts_at` + `ends_at`, and `ends_at` is **derived
   at booking** from `Service.default_duration_minutes` (`BookingService.php:131-134`) — there is **no
   duration/end argument**. A surgery's planned length varies per case; every length would need its own
   `Service` row, and even then it is a fixed slot.
2. **No planned-vs-actual occupancy.** There are **no `actual_start`/`actual_end` columns**; `ends_at` is
   never recomputed, and `complete()` records no run time (`AppointmentService`). A case **planned for 3 h
   that runs 4 h 10 m cannot be represented**, and worse, the theatre falsely reads *free* after its planned
   `ends_at` because the overlap check uses the never-updated `ends_at`. **This is the exact gap that made a
   bed a net-new `Stay` (continuous, planned-vs-actual occupancy) rather than a clinic slot** (Phase 1 §2.1).
3. **No team roles or counts.** Resources are typed only `practitioner`/`room`/…; a surgeon, anesthetist, and
   nurses all collapse to `practitioner`, and `Service.requires_resource_types` is a **count-less set** — so
   "exactly 1 anesthetist + 2 scrub nurses" is inexpressible.

**Decision (the Bed/Stay precedent, adapted).** Reuse the **`Resource` layer** (theatre = `room`; team =
`practitioner`), the **`lockResource`→`assertNoOverlap` mechanism**, and the **availability/branch-hours**
gates — but **do not reuse the `Appointment` table.** Mint a net-new **`SurgicalCase`/theatre block** that
carries (a) an **arbitrary planned duration / explicit planned end**, (b) **actual start/end** distinct from
planned (extending occupancy on overrun), and (c) a **role-and-count-aware team roster** over the reused
`Resource` rows, running the reused lock-then-assert mechanism against the theatre + team resources on the
case's own window. This is precisely what inpatient did: `Bed`/`Stay` reused `BookingService`'s row-lock
*mechanism*, not its `Appointment` table. **Surgery sits *between* the clinic slot (fits `Appointment`) and
the bed's open-ended stay (needs `Stay`): a finite but variable-actual block — closer to `Stay`.**

### 2.2 The surgical case — **a net-new `SurgicalCase`, reusing the `Stay`/`Order` *pattern* (not a table); linked to `Stay` app-layer.**

A surgery is a **case**: the planned + performed procedure, the patient, the theatre, the surgeon + team, the
peri-operative phases, and the planned-vs-actual timeline. None of the existing entities fit — an `Encounter`
is a single-sitting visit (one-open-per-practitioner), an `Appointment` is a fixed slot (§2.1), and a generic
`Order` has no procedure/team/phase shape. So a case **owns its tables** while reusing the proven *shape*:

- **`SurgicalCase`** (`BelongsToTenant`, `LogsReads`) — the mutable current state: `patient_id`,
  `operating_theatre_id` (or the room `resource_id`), `primary_surgeon_id`, `anesthetist_id`, a **team roster**
  (role-tagged resource/staff links), `procedure` (a tenant-authored surgical-procedure `TariffItem`, §2.6),
  `scheduled_start`/`planned_duration`, `actual_start`/`actual_end` (nullable — filled as the case runs),
  `status`, `stay_id` (**SOFT nullable ref** to a Phase-1 inpatient stay — **no FK/relation**, so Surgery
  stays arch-independent of Hospital; null for day-surgery/outpatient — the `WardRound`/pharmacy `stay_id`
  precedent). **Lifecycle state machine (legal-only, clinician-driven):**
  `scheduled → pre_op → in_progress → completed → post_op` (with `cancelled` from the pre-op states) — the
  `Stay` ADT / `MedicationOrder` state-machine shape.
- **`CaseEvent`** (`BelongsToTenant`, **APPEND-ONLY** — model `updating`/`deleting` guards + `SIGNAL '45000'`
  DB triggers, the `stay_events`/`medication_administrations` recipe) — one immutable row per transition
  (scheduled / wheels-in / anesthesia-start / incision / closure / wheels-out / completed) + `performed_by` +
  `occurred_at`. The actual timings are recorded **facts**, never computed.

**Inpatient link (arch-clean).** Keep `SurgicalCase` referencing `Patient` + optional `Encounter` (Clinical)
so the module stays independent of Hospital; the **stay↔case association is a soft `stay_id`** (app-layer
composition, the pharmacy/`WardRound` precedent). This preserves the standing rule that verticals don't
hard-depend on each other.

### 2.3 Pre-op / op / post-op notes — **reuse the sign-and-lock `ClinicalNote` + `Encounter`, unmodified.**

Peri-operative documentation is exactly the inpatient bedside-charting reuse (HOSP.G4). `ClinicalNote` is a
draft→signed record with a **conditional immutability**: the model throws on updating/deleting a `signed` row,
and DB triggers `clinical_notes_signed_no_update`/`_no_delete` are guarded by `IF OLD.status = 'signed'`
(draft editable, signed immutable). An **operation note**, a **pre-op assessment**, and a **post-op note** are
each a `ClinicalNote` (SOAP or a surgical layout via `NoteTemplate.required_sections`) hung off a surgical
`Encounter`. `Encounter` already has `TYPE_PROCEDURE` (`Encounter.php:43`) — usable as-is, or add one
`TYPE_SURGERY` enum value (a small additive edit) for cleaner worklists. The `WardRound` precedent applies
verbatim: **`Encounter` is reused UNMODIFIED**, the surgical association kept module-side via the case's
`encounter_id`. **The ASA physical-status class is recorded here as an anesthetist-*assigned* value** (a fact,
like a documented allergy) — never computed (§3).

### 2.4 The WHO Surgical Safety Checklist — **a net-new structured record-not-judge artifact; a checklist, not a safety gate.**

The WHO checklist (sign-in before anesthesia / time-out before incision / sign-out before the patient leaves)
is a **structured record the team completes** — there is **no `checklist`/`screening` model in the codebase
today**, so it is net-new, but it is a straightforward composition of existing recipes:

- Model it as a **parent phase-record + N child-item rows**, each item carrying a human-confirmed status
  (confirmed / not-applicable) and a **mandatory reason when an item is not confirmed** — the exact
  `VisitTask` shape (`status` open/done/not_done with a **required `not_done_reason`**) and the
  `CarePlan → CarePlanGoal` parent-plus-independently-statused-items shape.
- Build it on the **append-only + sign-and-lock recipe** (§2.2 / 4a): a completed phase is signed-and-locked
  (`ClinicalNote` conditional trigger) or append-only (`stock_movements`/`stay_events` recipe), with a
  `completed_by` actor + an at-phase timestamp — the `Handover` SBAR precedent (a net-new structured,
  human-authored, append-only artifact with "no severity/score/risk/priority/flag field").
- Optionally, a **tenant-authored checklist template** snapshotted onto each instance — the
  `ConsentTemplate → PatientConsent` shape (the instance captures `template_version`/`template_body`
  verbatim + a `signature`), so a tenant can author its own checklist variant without a licensed set bundled.

**FENCE (the checklist's defining constraint).** The checklist **records what the team confirmed**; it
**computes no safety and gates nothing.** The system does **not** block the case on an unchecked item (that
would be a computed clinical judgment — and an operational safety gate that CareOS must not own); at most it
surfaces a **factual** "N of M items confirmed" count (never a graded compliance score). Whether to proceed is
a **human** decision. This is the `MedicationAdministration` late/missed precedent: a factual `scheduled_at`
vs `administered_at` comparison the UI renders, never a graded flag.

### 2.5 Consumables / implants — **reuse the pharmacy inventory recipe; net-new lot/serial/expiry/UDI for implant traceability.**

Consumable + implant stock reuses the Phase-2 G4 recipe verbatim as a *pattern*: a mutable on-hand row
mutated **only under a `FOR UPDATE` lock** (`MedicationStock::lockOnHand`), an **append-only signed-delta
`StockMovement` ledger** (model guards + `stock_movements_no_update`/`_no_delete` triggers), and a
`StockService` (lock → transaction → apply-movement, gated + tenant-fail-closed). "Below stock" stays a
**factual** `on_hand <= reorder_threshold` comparison (`isBelowThreshold`), never a graded alert.

**Two net-new extensions (do not force them onto the pharmacy model):**
1. **Lot / serial / UDI / expiry.** `MedicationStock` has **no** lot/batch/serial/UDI/expiry column and is
   keyed `unique(tenant_id, formulary_item_id)` — a **single fungible integer per item**, which cannot
   represent two lots of the same item. **Surgical implants legally require lot/serial/UDI + expiry
   traceability**, so implant stock must be **lot-keyed** (a lot-per-row or a `lot` sub-table), each with an
   expiry date. Expiry/recall stays a **factual date comparison**, never a graded alert (the `isBelowThreshold`
   posture).
2. **A surgical-item catalog** (a `FormularyItem` analogue) + a **case-usage link** (which items/lots were used
   in which case) — the bridge to billing (§2.6).

Barcode/UDI **scanning hardware** is a device surface (a later partner lane), like the dental scanner.

### 2.6 Surgical billing — **the existing engine, unchanged; net-new is strictly orchestration.**

A surgical procedure, theatre-time, and each consumable/implant is a **tenant-authored `TariffItem`** (own
codes, integer minor units — **no licensed procedure set bundled**, the dental/pharmacy precedent). Capturing
a case's charges is `ChargeCaptureService::captureManual` — which **snapshots the price and computes the line
total in the engine** — already reused by `DentalChargeService`, `BedBillingService`, and
`PharmacyBillingService`. Surgical billing is the **pharmacy-billing (G5) / bed-day (G6) pattern**: capture
one charge per billable element of the case (procedure code + theatre-time units + each consumable), then the
existing `validateForPatientPeriod → createDraftFromCharges → issue` collapses them onto one invoice, and
`ReconciliationEngine` I4 proves it **reconciles-to-the-unit** (δ=0); an inpatient case's charges join the
stay's discharge invoice via the existing `BedBillingService::invoiceStay`. **No new billing/pricing/VAT/
line-total math** — the same adversarial-grep discipline as G5/G6.

---

## Section 3 — THE FENCE / PARTNER BOUNDARY (the crux of this map)

Everything CareOS builds for the OR is **record-keeping**. The moment software **computes a peri-operative
clinical judgment** — predicting this patient's surgical risk, grading the team's safety, interpreting the
anesthesia monitor — it is **clinical decision support**, regulated as a **medical device**, and **exactly
what the electric fence refuses.**

**The canonical rule (`AGENTS.md:38-39`, HARD RULES, "never violate"):**

> **ELECTRIC FENCE:** no diagnosis, no triage, no symptom assessment, **no dosing logic** — anywhere in code,
> prompts, or AI features. Ever.

**It is already precedented as a non-goal (`DEFERRED.md:54-57`, tag `P0D.G3`):** clinical-decision-support
engines "require a **partner-first licensed … database and a funded regulatory track**; do not build these
in-house." *(There is no dedicated DEFERRED line for a surgical/early-warning risk score by name — it is
covered transitively by the fence + the record-not-judge posture on every event model; a build gate should
add one explicitly.)*

### 3.1 The boundary, stated plainly

| Layer | Owner | Status |
|---|---|---|
| Theatre scheduling, the **surgical case** + lifecycle, **pre-op / op / post-op notes**, the **WHO checklist** (as a record), **consumables/implant** tracking, **billing**, the **anesthesia record** (the anesthetist's entries), the **ASA class** (assigned) | **CareOS** | ✅ Build now — all record-keeping, fence-clean |
| A **computed surgical-risk / mortality / complication score** or peri-operative **CDS** | **A certified partner** behind a stub seam (advisory, human-owned, logged) — or a **non-goal** | ⛔ **Never homemade** |
| **Intra-op device data** (anesthesia machine, patient monitor vitals stream) | **A device partner** behind a `PerioperativeDeviceFeed` seam; auto-ingested values are **never interpreted** | ⛔ **Never homemade** — the `LabConnectivity`/HL7 precedent |

### 3.2 The two fence lines that define this vertical

**(a) A checklist is a RECORD, not a verdict.** The WHO checklist records what the team confirmed and computes
no safety; it **does not gate the case** on an unchecked item (§2.4). Keep it record-not-judge — the
`Handover`/`MedicationAdministration` posture ("no severity/score/risk/flag column").

**(b) The ASA class is ASSIGNED, not COMPUTED.** The **ASA physical-status class is a value the anesthetist
assigns** — record it exactly like a documented allergy or a vital (a fact). A **computed** risk score
(POSSUM/NSQIP-style mortality or complication prediction) is dosing/triage-class judgment on the wrong side of
the fence → **certified partner or non-goal.** Likewise the **anesthesia record** is the anesthetist's
**documentation** (buildable, record-not-judge); the **device-data feed** is partner-gated — and even when a
partner auto-ingests monitor values, CareOS **never interprets** them (`DEFERRED.md:112-119`, `P0P.G11`:
*"Never interpret a result even when auto-ingested — the electric fence holds"*).

### 3.3 The precedent is already in the codebase — copy it exactly

Two partner seams already ship the exact shape for a `PerioperativeDeviceFeed` / `SurgicalRiskProvider`:
- **`LabConnectivity`** (interface) → **`ManualLabConnectivity`** (a no-op `transmit()`; `ingestResult()`
  **throws** "not available; entered manually"), bound in `ClinicalServiceProvider::register()` (line 17).
- **`MedicationSafetyProvider`** (interface) → **`NullMedicationSafetyProvider`** (`checkOrder`/
  `checkAdministration` return `SafetyResult::none()`), bound in `PharmacyServiceProvider::register()`
  (line 26) — *"CareOS builds the SEAM, NOT the logic."*

> **The surgery build provides the SEAM, not the logic.** Define a
> `PerioperativeDeviceFeed`/`SurgicalRiskProvider` interface bound to a `Null*` implementation (returns "no
> automated feed / no automated risk assessment available") in the Surgery module's `register()`; the case
> flow *invokes* it and records the result; when a certified partner is licensed, the binding swaps — CareOS's
> records don't change. **Identical to `LabConnectivity` / `MedicationSafetyProvider`.**

### 3.4 Other interpretation temptations → build the record-not-judge version (or stub)

| Temptation | Why it's fenced | Build instead |
|---|---|---|
| **Auto-schedule by acuity / "optimize the OR list"** | Computes a clinical-priority ordering | The scheduler is human-driven; AI **draft-only** at most (a suggested order the surgeon accepts), never auto-booked by computed acuity |
| **Computed theatre-utilization *grade*** ("this theatre is under-utilized") | Grades a fact into a judgment | Show the **raw utilization count / minutes** (a fact); the manager judges |
| **"Case running late / over-run" flag** | Grades planned-vs-actual into a judgment | Show the raw **planned vs actual** times (facts) — the `MedicationAdministration` late/missed precedent |
| **Computed checklist-compliance score** | Grades the safety record | A factual **N-of-M confirmed** count; never a graded compliance verdict |
| **Surgical-risk / mortality / complication prediction** | The §3.1 medical-device judgment | Record the **assigned ASA** + the team's findings; a computed score is a **partner/non-goal** |
| **Interpreting the anesthesia monitor / auto-charting intra-op vitals as judgments** | Device interpretation = CDS | A **partner device feed** behind the seam; values recorded as facts, **never interpreted** |
| **AI "suggest the surgical plan / next step"** | System-proposed clinical decision | Draft-only, human-approved AT MOST — and it must ship its own `tests/Evals/` fence locks |

**The discipline, stated as a standing rule:** the `SurgicalRiskProvider` / `PerioperativeDeviceFeed` seam
must **never** be filled with homemade logic under pressure to "make it smart." That is simultaneously a
**fence** violation (computed clinical judgment) and a **legal/regulatory** one (unlicensed medical device).
The homemade version is a **permanent non-goal.**

---

## Section 4 — New roles Phase 5 introduces (RBAC)

Adding roles/permissions is **purely additive** — new entries in `RbacProvisioner::PERMISSIONS` and
`::ROLE_TEMPLATES` (plain const arrays synced by `provisionTenant()`), plus a re-provision migration (the
`add_medication_prescribe_permission` / `grant_billing_manage_to_pharmacist` precedents), with **zero** change
to `Gate::before`/`PermissionService`. `RbacTest`'s permission count is self-referential to the const (no test
edit); the only behavioral guard to respect is `RbacNegativeSweepTest`'s withheld-map (adding **new** roles /
**new** permissions never touches it). Naming stays `<domain>.<verb>`.

**New permissions (additive):**
- **`surgery.schedule`** — book/reschedule a theatre case (surgical scheduler).
- **`surgery.manage`** — create/advance a surgical case + its lifecycle (surgeon / OR team). *(A case
  transition is a surgical act, distinct from `admission.manage` and `appointment.manage`.)*
- **`checklist.complete`** — record WHO-checklist item completion (the team). *(Or reuse `note.write` — the
  eMAR-reuses-`note.write` precedent — if a distinct perm is over-fine.)*
- **`anesthesia.document`** — author the anesthesia record + assign the ASA class (anesthetist).
- **Consumable/implant stock** reuses **`dispense.manage`** (the pharmacy stock permission) or a new
  **`surgical.stock`** — recommend reuse where the same person manages it, a distinct perm if OR stock is a
  separate custodian.

**New roles (additive templates):**

| New role | Closest existing template | Already covered | What the new role adds |
|---|---|---|---|
| **Surgeon** | `doctor` / `hospitalist` | `patient.view`, `note.write`/`note.sign`, `order.manage`, `allergy.override`, `encounter.manage` | **`surgery.manage`** (+ `surgery.schedule` if surgeons self-schedule) |
| **Anesthetist** | `doctor` | clinical read/write, `allergy.override` | **`anesthesia.document`** + **`surgery.manage`** (co-manages the case) |
| **Scrub / OR nurse** | `nurse` / `ward_nurse` | `patient.view`, `note.write` | **`checklist.complete`** + consumable/implant stock (`dispense.manage`/`surgical.stock`) |
| **Surgical scheduler** | `reception` / `coordinator` | `patient.view`, `appointment.manage` | **`surgery.schedule`** (book theatre cases) |

**Existing roles that touch surgery (reuse, no new role strictly needed):**
- **`doctor` / `hospitalist`** (the operating surgeon in many tenants) gain **`surgery.manage`** — the
  `doctor`-is-the-dentist precedent (one role does the work until a dedicated split is a later gate).
- **`org_admin`** gains all four new permissions (it holds every permission).

**Scope note (unchanged):** the only RBAC scope axis is `branch_id`; there is no theatre-/suite-level scope.
Branch-level is fine for Phase 5; finer scope is a later `abac_conditions` gate.

---

## Section 5 — Dependency-ordered build sequence (proposed gates)

Foundational-first, each gate buildable + testable on its own. **Placement recommendation: a new peer module
`Modules\Surgery`** (not folded into `Modules\Hospital`). Rationale: (1) the peri-operative domain overlays
Scheduling + Clinical + Billing + Patients + inventory — the self-contained shape of `Modules\Dental` /
`Modules\Hospital` / `Modules\Pharmacy`; (2) folding it into inpatient/ADT would mis-scope it (**day-surgery /
outpatient** cases serve non-inpatient too) and couple two verticals, which the arch tests forbid; (3) Surgery
needs to *use* Scheduling + Clinical + Billing + Patients but must **exclude** `Audit\Models`, `AiCore`,
`Comms`, and the **peer verticals** (Nursing/Dental/Hospital/Pharmacy) — a new `arch('Surgery …')` rule in the
existing style (the inpatient stay-link is **app-layer**, a soft `stay_id`; the consumable inventory **copies
the pharmacy recipe**, not a cross-vertical dependency). Register a `SurgeryServiceProvider` in
`bootstrap/providers.php`; **bind the `PerioperativeDeviceFeed` / `SurgicalRiskProvider` seam to its null
implementation in `register()`** (the `LabConnectivity`/`MedicationSafetyProvider` precedent).

| Gate | Deliverable | Depends on | Notes |
|---|---|---|---|
| **SURG.G1** | **Module + theatre + surgical scheduling + surgery RBAC + the device/risk SEAM (foundation).** Register `Modules\Surgery`; a **theatre** (`Resource` `type='room'` or a thin `OperatingTheatre`); a **surgical block reservation** reusing the `lockResource`→`assertNoOverlap` mechanism + availability/branch-hours on a **variable window** (not `Appointment`); `surgeon`/`anesthetist`/`scrub_nurse`/`surgical_scheduler` roles + `surgery.*` permissions (additive); **bind `PerioperativeDeviceFeed`/`SurgicalRiskProvider` → a Null no-op**. Backend + tests, minimal UI. | Platform, Scheduling, Billing, Patients, RBAC, Audit (services) | **Everything below depends on this.** Fence: theatre is a record; the seam is a no-op. |
| **SURG.G2** | **Surgical case + lifecycle (the core).** Net-new **`SurgicalCase`** (patient, theatre, surgeon, team roster, planned+**actual** times, status `scheduled→pre_op→in_progress→completed→post_op`) + append-only **`CaseEvent`** (wheels-in/incision/closure/wheels-out, the `stay_events` recipe); soft `stay_id` (inpatient link app-layer); gate `surgery.manage`. | SURG.G1, Patients, Clinical (`Encounter`), Hospital (`Stay`, app-layer) | The spine. Fence: timings are **facts**; no computed risk. |
| **SURG.G3** | **Pre-op / op / post-op notes (reuse Clinical).** Reuse `Encounter` (`TYPE_PROCEDURE` or a new `TYPE_SURGERY`) + sign-and-lock `ClinicalNote` for the pre-op assessment, operation note, and post-op note, tied to the case; **ASA class recorded** (anesthetist-assigned). Gate `note.write`/`note.sign`. | SURG.G2, Clinical | Reuse-heavy. Fence: ASA **assigned**, never computed (§3). |
| **SURG.G4** | **WHO Surgical Safety Checklist.** Net-new **`SurgicalChecklist`** (sign-in/time-out/sign-out, N human-confirmed items + `completed_by` + at-phase timestamp) on the append-only + sign-and-lock recipe (the `VisitTask`/`Handover` shape); optional tenant-authored template snapshot. Gate `checklist.complete`. | SURG.G2 | Fence: a **record, not a safety gate**; a factual N-of-M count, never a compliance score (§2.4/§3). |
| **SURG.G5** | **Consumables + implant tracking.** Reuse the pharmacy inventory recipe (stock under a `FOR UPDATE` lock + append-only `StockMovement` ledger) + a **net-new lot/serial/expiry/UDI** extension (lot-keyed rows for implants) + a surgical-item catalog + a case-usage link. Gate `dispense.manage`/`surgical.stock`. | SURG.G1, SURG.G2 | Fence: stock/expiry are **facts**; no graded alert. Lot/serial/UDI is net-new (§2.5). |
| **SURG.G6** | **Surgical billing.** Each case captures charges (procedure + theatre-time + consumables) via the **existing engine** (`TariffItem` → `captureManual`); the invoice is the existing gather→issue flow; **reconciles-to-the-unit** (I4); inpatient charges join the stay's `invoiceStay`. The pharmacy/bed-day shape. | SURG.G2, SURG.G5, Billing | **No new billing math** (adversarial-grep, like G5/G6). |
| **SURG.G7** *(optional/later)* | **Anesthesia record + device-feed seam invoked + day-surgery extras.** The anesthetist's structured record (agents/ASA as facts) reusing G3; the `PerioperativeDeviceFeed` seam *invoked* (no-op); outpatient/day-surgery scheduling niceties. | SURG.G2/G3 | Small; composes the spine. Device data + risk stay behind the seam. |

**Rough gate count:** **~6 core gates (SURG.G1–G6)** for a credible perioperative MVP, foundational-first,
each testable alone; **+1 optional** (G7 anesthesia record / device seam / day-surgery). **Critical path:
SURG.G1 → SURG.G2 → SURG.G3** (theatre/scheduling → the case → the operative record is the load-bearing chain).
**SURG.G4 (checklist) and SURG.G5 (consumables) parallel off SURG.G2; SURG.G6 (billing) pulls SURG.G2 +
SURG.G5.** **The device/risk seam is established in SURG.G1 and is a no-op at every call site.**

---

## Section 6 — Platform-fit + fence risks (where reuse is CLEAN vs FORCED)

Called out honestly so surgery is not built on a wrong abstraction — or, worse, over the fence.

**🔴 FORCED — do not stretch these:**
1. **A surgical case is NOT an `Appointment`.** `Appointment` is a fixed, service-derived slot with no
   per-booking duration and **no planned-vs-actual occupancy** (`ends_at` never updated; an overrun reads
   free) — the exact gap that made a bed a net-new `Stay`. → **net-new `SurgicalCase`/block**, reusing only the
   `Resource` layer + the `lockResource`→`assertNoOverlap` **mechanism** + availability gates (§2.1).
2. **Team roles/counts have no home.** Resources are typed `practitioner`/`room`/… with a **count-less**
   `requires_resource_types` set — "1 anesthetist + 2 nurses" is inexpressible. → a **role-and-count-aware
   team roster** on the case (§2.1/§2.2).
3. **Implant lot/serial/UDI/expiry is net-new.** `MedicationStock` has **no** lot/expiry/serial column and is
   a single fungible integer per item — it cannot hold two lots. → a **lot-keyed** surgical-item store (§2.5).
4. **The WHO checklist has no existing structured-checklist analogue.** → net-new, composed from the
   `VisitTask` (item + mandatory reason) / `ConsentTemplate→PatientConsent` (template+snapshot) / `Handover`
   (append-only SBAR) recipes (§2.4) — **and kept a record, never a safety gate.**

**🟢 CLEAN — reuse with confidence:** theatre-as-`room`-`Resource` + the `lockResource`→`assertNoOverlap`
mechanism + availability/branch-hours (no-double-book); sign-and-lock `ClinicalNote` + `Encounter` (op notes);
the append-only recipe (case-events / checklist / anesthesia record); `captureManual` + `ReconciliationEngine`
I4 (surgical billing, no new math); the pharmacy inventory recipe (stock + movement + lock); additive
`RbacProvisioner` consts (new roles/permissions); the `LabConnectivity`/`MedicationSafetyProvider` seam
pattern (the device/risk stub); the soft `stay_id` app-layer inpatient link.

**🚨 THE SHARPEST RISKS — two fence lines under pressure:**
1. **The WHO checklist drifting from a RECORD into a SAFETY GATE / computed compliance verdict.** Keep it
   record-not-judge: record what was confirmed, surface a factual count, **never** block the case or grade the
   team (§2.4).
2. **Filling the `SurgicalRiskProvider` / `PerioperativeDeviceFeed` seam with homemade logic** — a computed
   risk score, an intra-op alert, an interpreted monitor value. That is the fence line (`AGENTS.md:38-39` —
   *no triage/diagnosis, ever*), the documented non-goal (`DEFERRED.md:54-57`), the eval lock
   (`ClinicalAgentsEvalTest`), **and** a legal line (unlicensed medical device). Build the seam empty and keep
   it empty until a certified partner fills it. **The ASA class is assigned; the record is CareOS's; the
   judgment is a partner's.**

---

## Where this sits

**Phase 5 of the phased hospital build.** Phase 1 (inpatient / ADT) and Phase 2 (pharmacy) are complete;
**Phase 5 = operating theatre / surgery** (this map). **The remaining hospital phases — lab (LIS), radiology
(RIS/PACS), and emergency (ED/triage) — are each mapped before building** (build order follows customer pull,
not phase number). Day-one perioperative MVP (SURG.G1–G6): schedule a theatre case with its team, run the
case through pre-op → in-progress → post-op with every transition audited, chart the op note (sign-and-lock),
complete the WHO checklist (a record), track consumables/implants with lot/serial traceability, and bill the
case to the unit. The **long-pole partner/device surfaces** — intra-op device-data feeds (anesthesia machine /
patient monitor), a certified surgical-risk/CDS engine, UDI barcode scanning, and HL7/FHIR exchange — stay
**behind seams, never homemade.** **Build the record; stub the judgment; assign the ASA, never compute it.**
