# CareOS — Hospital Phase 3 (Laboratory / LIS): Reconciliation + Build-Sequence Map

**Status: analysis only — NO code.** This is the map-before-building step for **Phase 3 of the phased hospital
build** (Phases 1 inpatient/ADT, 2 pharmacy, 5 surgery, 6 ED — all complete). It draws — precisely — the line
between the **BUILDABLE lab record-keeping** (order entry, a tenant-authored test catalog, specimen tracking,
manual result entry, reference-range *display*, result routing, lab billing) and the **PARTNER-GATED
HL7/FHIR/analyzer INTEGRATION** that is the *defining* feature of a real LIS (results flowing IN from
analyzers/reference labs; orders flowing OUT). Same discipline as `docs/HOSPITAL-PHASE1-ADT-MAP.md`,
`docs/HOSPITAL-PHASE2-PHARMACY-MAP.md`, `docs/HOSPITAL-PHASE5-SURGERY-MAP.md`, `docs/HOSPITAL-PHASE6-ED-MAP.md`,
`docs/DENTAL-DELIVERY-MAP.md`, `docs/CLINIC-DELIVERY-MAP.md`.

> The referenced `careos-hospital-expansion-scoping.md §2.3` is not committed to this repo (as with the other
> phase maps); this map is derived from the authoritative source — the codebase itself — and reconciles against
> it. **This is Phase 3.** The remaining hospital phase is **Radiology (Phase 4)** — also partner-gated,
> pending PACS/DICOM; mapped separately. Build order follows customer/partner pull, not phase number.

**The one-sentence thesis.** The lab *workflow* is **~85% already built** — CareOS's Clinical module already
ships a generic **`Order` + `OrderResult`** system whose lifecycle is *literally* lab-shaped
(`ordered → collected → in_progress → resulted → reviewed`), whose results are **append-only + raw with NO
interpretation** (the fence already holds), whose `source` field already distinguishes **`manual` vs
`imported`** (the seam's two paths), and which is **already wired to a `LabConnectivity` seam** stubbed to
`ManualLabConnectivity` (`OrderService::place()` already calls `$this->lab->transmit($order)`). So the lab
vertical is **mostly reuse** (a lab test *is* a Clinical `Order`); the genuinely net-new domain is
**specimen tracking** and **reference ranges as displayed reference data** — and the **defining LIS value, the
HL7/FHIR/analyzer feed, is PARTNER-GATED**: the seam exists and is empty, results are entered MANUALLY, and a
certified partner fills it later (the `MedicationSafetyProvider` / `TriageAcuityProvider` precedent). **Build
the manual record-keeping; formalize the seam; never compute an abnormal flag; be honest that without the HL7
partner this is a manual shell.**

---

## Section 0 — What CareOS already provides that Lab REUSES (the head start — unusually large)

| Existing capability | How lab uses it | Reuse quality |
|---|---|---|
| **Clinical `Order`** (`Modules/Clinical/src/Models/Order.php`) — status `ordered → collected → in_progress → resulted → reviewed` (+ `cancelled`); `priority` routine/urgent; `orderable_item_id`; `ordered_by`; `reviewed_by`/`reviewed_at` | **A lab test IS an `Order`.** Its lifecycle is *already* the lab lifecycle (collected = specimen taken, resulted = result entered, reviewed = clinician attested). No new order entity. | **Clean (direct reuse)** — the biggest reuse in any vertical |
| **`OrderResult`** (`OrderResult.php`) — append-only (`order_results_no_update`/`_no_delete` DB triggers), **raw `result_value` only, NO range/flag/abnormal/score column**; `source` = `manual` / `imported` | **A lab result IS an `OrderResult`.** Manual entry today; `imported` is the HL7-partner path. **The electric fence is ALREADY built into the model** (docblock: "no range/flag/abnormal/score — same posture as vitals"). | **Clean (direct reuse) + the fence** |
| **`OrderService`** (`place`/`transition`/`recordResult`/`review`) — places, tracks the lifecycle, records a MANUAL result a human reviews; **`place()` already calls `$this->lab->transmit($order)`** | Lab order entry + manual resulting + review are the EXISTING service methods. `review` is a **human attestation, never a computed judgment**. | **Clean (direct reuse)** |
| **`LabConnectivity` seam** (`Modules/Clinical/src/Contracts/LabConnectivity.php`) → **`ManualLabConnectivity`** (bound in `ClinicalServiceProvider:17`) — `transmit()` no-op; `ingestResult()` **throws** "not available; results are entered manually" | **The seam already exists and is already wired.** Phase 3 FORMALIZES it (documents the manual/imported paths; a certified HL7 partner binds a real impl later). §2.1/§3. | **Pattern precedent (the crux) — already present** |
| **`OrderableItem` catalog** (`OrderableItemService`, tenant-authored) | The **lab test menu** is a tenant-authored `OrderableItem` set (like every catalog — **NO licensed LOINC/test set bundled**); a thin lab overlay adds specimen-type + reference range (§2.3). | **Clean (extend)** |
| **Multi-tenancy** (`BelongsToTenant`, fail-closed) | A lab = a tenant; every test/specimen/result row is tenant-owned. | Clean (free) |
| **Patient master + `Encounter` + the ED `EdVisit` + the Phase-1 `Stay`** | A lab order is placed on a `Patient`, optionally tied to an `Encounter` (`Order.encounter_id` nullable) — so an **outpatient, ED, or inpatient** order all reuse the same `Order`. | Clean |
| **Billing engine** (`TariffItem` · `ChargeCaptureService::captureManual` · `IssueService` · `ReconciliationEngine`) | A **lab test is a `TariffItem`**; a resulted test **accrues a `Charge`**; the invoice reconciles-to-the-unit — the ED-G6 / surgery-G5 / bed-day pattern. | Clean (orchestration only — §2.6) |
| **Append-only audit** (hash-chained) + **read-logging** (`LogsReads` on Order/OrderResult) | Every order/result/transition is audited + patient-scoped read-logged — already wired on the Clinical models. | Clean (drop-in) |
| **RBAC** (`order.manage` already exists) + `RbacProvisioner` additive consts | Lab roles (lab_tech / pathologist / phlebotomist) are **additive templates**; `order.manage` already models "place + track orders" (§5). | Clean (additive) |
| **The partner-SEAM pattern** (`MedicationSafetyProvider`→`Null*`, `TriageAcuityProvider`→`Null*`) | The `LabConnectivity`→`ManualLabConnectivity` stub is the **same shape, already in place** — the HL7 feed is a 1:1 partner seam (§3). | Clean (the crux) |
| **Design system** + route-smoke / MySQL-parity / immutability guards | The lab worklist / specimen / result UIs reuse tokens + primitives; new routes ride the existing guards. | Clean |

**Read-off:** the lab *workflow* is **~85% reuse** — the order, the result, the lifecycle, the review, the
manual/imported split, and the connectivity seam are **already built in Clinical**. The genuinely net-new lab
domain is **specimen tracking** + **reference ranges (as displayed reference data)** — and the **defining LIS
value (the HL7/analyzer feed) is partner-gated.**

---

## Section 1 — The lab spine, mapped (reuse vs net-new vs partner)

| # | Spine element | What it needs | REUSE (with what) | NET-NEW lab domain | ⛔ PARTNER-GATED (stub) |
|---|---|---|---|---|---|
| 1 | **Lab order entry** | Order a test/panel on a patient; specimen type; priority (routine/urgent/STAT — a recorded flag); optional encounter/stay/ED-visit link | **Clinical `Order` DIRECTLY** (`OrderService::place`; lifecycle; `priority` routine/urgent; `encounter_id`) | A thin **lab-order overlay** (specimen_type, a **STAT** priority value [additive], panel→child-tests) (§2.4) | none |
| 2 | **Test catalog** | The tenant's lab test menu (code, name, specimen type, reference range, unit) | **`OrderableItem`** (tenant-authored catalog) | A **lab-test overlay** on `OrderableItem` (specimen_type + reference_range + unit) — tenant-authored, **NO licensed LOINC bundled** (§2.3) | none |
| 3 | **Specimen tracking** | A specimen collected from the patient, tied to the order; accession/collection ids; a legal-only state (collected → in_lab → resulted) | The append-only + state-machine **shape** (`stay_events`/`ed_visit_events`); `Order.status=collected` as the trigger point | **`Specimen`** (net-new): accession number, specimen_type, collected_by/at, a legal-only state machine, order link (§2.2) | Barcode/label **hardware** = a device surface, later |
| 4 | **Result entry (manual)** | Record a result value + unit + the test's reference range shown alongside; append-only | **`OrderResult`** (append-only, raw `result_value`, `source=manual`; `OrderService::recordResult`) — **the fence already built in** | Optional `unit`/`ref_range_snapshot` on the result **as recorded reference data** (display), NOT a computed flag (§2.5) | ⛔ A **COMPUTED abnormal/critical FLAG** = the fence line (§4) → non-goal/partner |
| 5 | **Reference ranges** | Display the test's normal range beside the value; the clinician reads value-vs-range | The `OrderableItem` overlay's `reference_range` (reference data) | A **reference_range** field on the lab-test overlay (tenant-recorded) (§2.5) | ⛔ **Grading** the value against the range (high/low/abnormal/critical) = a clinical judgment → non-goal/partner (§4) |
| 6 | **Result routing** | Route a result to the ordering clinician; review/attest | **The EXISTING order→result→review flow** (`reviewed_by`/`reviewed_at`; `OrderService::review`; Comms notifications) | A **lab worklist** view (orders by status) — a read affordance | none |
| 7 | **Lab billing** | A lab test is billable → one invoice | **Billing engine unchanged**: `TariffItem` → `captureManual` → `Charge` → invoice → `ReconciliationEngine` (I4) | **Orchestration only**: capture a charge per resulted/ordered test (the ED-G6 shape) (§2.6) | none (facts/money) |
| 8 | **HL7/FHIR/analyzer feed** (the DEFINING LIS feature) | Orders OUT to analyzers/reference labs; results IN automatically | *(the seam already exists — `transmit` on place)* | *(nothing — CareOS records manually)* | ⛔ **The HL7/FHIR/analyzer INTEGRATION** — the `LabConnectivity` seam filled by a **certified partner**; `ingestResult` creates an `OrderResult` `source=imported`; homemade HL7 client = **out of scope** (§3) |

---

## Section 2 — The load-bearing decisions (get these right)

### 2.1 The `ManualLabConnectivity` stub — **reconciled: it ALREADY EXISTS and is ALREADY WIRED. Phase 3 formalizes, it does not create.**

The stub is real and in production shape:
- **`Modules\Clinical\Contracts\LabConnectivity`** — `transmit(Order): void` + `ingestResult(array $payload): void`.
- **`Modules\Clinical\Services\ManualLabConnectivity`** — the ONLY implementation: `transmit()` is a **no-op**
  (the order is worked manually), `ingestResult()` **throws** `RuntimeException('Automated result ingestion is
  not available; results are entered manually.')`.
- **Bound** in `ClinicalServiceProvider::register()` (`$this->app->bind(LabConnectivity::class,
  ManualLabConnectivity::class)`), and **already CONSUMED**: `OrderService::place()` calls
  `$this->lab->transmit($order)` on every order placement (a no-op today).
- **Documented as deferred** in `DEFERRED.md` (two lines): "Lab HL7/FHIR feeds" and "Real HL7/FHIR lab
  connectivity (electronic transmission + automated result ingestion). **P0P.G11**" — with the standing rule
  *"Never interpret a result even when auto-ingested — the electric fence holds."*

**Decision:** Phase 3 does **not** re-invent this. It **formalizes** the existing seam: (a) keep the
`LabConnectivity` interface + the `ManualLabConnectivity` binding **in Clinical** (that is where `Order` lives
and where `transmit` is already called); (b) make the **`imported` result path explicit** — when a certified
partner binds a real `LabConnectivity`, its `ingestResult(payload)` resolves the target `Order` and appends an
`OrderResult` with `source = OrderResult::SOURCE_IMPORTED` (the field already exists) through the SAME
append-only, fence-clean path a manual result uses; (c) the lab vertical **consumes** the seam and provides
the **manual** path (already built). The seam stays a `Null*`/`Manual*` no-op until a partner fills it — the
`MedicationSafetyProvider`/`TriageAcuityProvider` posture. **Nothing homemade fills it.**

### 2.2 Specimen tracking — **the genuine net-new lab domain: a `Specimen` entity (Clinical's `Order` has a `collected` status but no specimen).**

`Order.status` includes `collected`, but there is **no specimen entity** — no accession number, no specimen
type, no collection provenance, no specimen state. A real lab needs the **specimen as a tracked object**
(collected from the patient → in the lab → resulted), because one order can involve a specimen with its own
identity (accession/barcode) and lifecycle distinct from the order's clinical status.

**Decision (net-new, buildable):** a **`Specimen`** (`BelongsToTenant`, `LogsReads`): `order_id` (link to the
Clinical `Order`), `accession_number` (a tenant-generated id — the `MRN`/gapless-number precedent),
`specimen_type`, `collected_by`/`collected_at`, and a **legal-only state machine** (`collected → in_lab →
resulted`, + `rejected` from any pre-result state, with a mandatory reason — the `VisitTask`/`Stay` shape).
Append-only **`SpecimenEvent`** for the transitions (the `stay_events`/`ed_visit_events` recipe). The specimen
state and the order status stay **loosely coupled** (collecting a specimen can advance the order to
`collected` via the existing `OrderService::transition`; a lab-side action, app-layer). **FENCE:** a specimen
priority (STAT/urgent) is a **recorded flag the clinician/lab sets**, never a computed priority (§4).

### 2.3 Test catalog — **extend `OrderableItem` (tenant-authored); no licensed test set bundled.**

The lab test menu is a tenant-authored `OrderableItem` set (the existing catalog). A **thin lab-test overlay**
(a `lab_tests` table keyed by `orderable_item_id`, the `dental_procedures`/`surgical_items` precedent) adds the
lab-specific fields: `specimen_type`, `unit`, and a **`reference_range`** (free text or low/high — recorded
**reference data**, §2.5). **NO licensed LOINC/CPT/test-code set is bundled** — the tenant authors its own
codes/ranges (the dental/pharmacy/surgery discipline). A panel is a parent test with child tests (a small
overlay), or simply several orders — recommend the simplest that a customer needs.

### 2.4 Lab order entry — **reuse Clinical `Order` DIRECTLY; a thin overlay only for specimen-type + STAT.**

A lab order **is** a Clinical `Order` (`OrderService::place`): patient + optional encounter + the orderable
(the test) + `priority` + `clinical_note`, tracked through the existing lifecycle. Two small additive needs:
1. **Specimen type + panel** — carried on the lab-test overlay (§2.3) / the specimen (§2.2), not on `Order`.
2. **STAT priority** — `Order.priority` is currently `routine`/`urgent`; a **`stat`** value is a **1-line
   additive const** (a recorded flag, never a computed priority — §4). *(Or keep urgent as the top flag if a
   customer doesn't distinguish STAT.)*

**Do NOT duplicate the order.** No lab-order entity is minted — the lab vertical composes the existing `Order`
app-layer (the specimen + catalog overlay hang off it). This is the sharpest "don't force it" call: unlike
surgery/ED (which needed net-new flow entities because `Encounter`/`Appointment` didn't fit), **the lab order
fits `Order` exactly** — reuse it.

### 2.5 Manual result entry + reference ranges — **reuse `OrderResult` (the fence is already built); the range is DISPLAYED reference data, never a computed flag.**

Manual resulting is the existing `OrderService::recordResult` → an append-only `OrderResult` (raw
`result_value`, `source=manual`, immutable via DB triggers). The lab adds only **display reference data**: the
test's `reference_range` + `unit` (from the §2.3 overlay) are **shown alongside** the value so the clinician
reads value-vs-range. Optionally, the result **snapshots** the range/unit at result time (recorded reference
data, like a fee snapshot) — still a **fact**, not a judgment.

**THE FENCE (the sharpest — §4).** `OrderResult` **already** carries "no range/flag/abnormal/score column —
same posture as vitals." Phase 3 keeps that: the range + value are **recorded facts the clinician reads**; the
system does **NOT** compute a high/low/abnormal/critical flag, does **NOT** grade the value against the range,
does **NOT** delta-check or auto-alert. A computed abnormal/critical verdict is a **certified-partner concern
or a non-goal** — the exact vitals-bands / computed-acuity line.

### 2.6 Lab billing — **the existing engine, unchanged; net-new is strictly orchestration.**

A lab test is a **tenant-authored `TariffItem`** (own code, integer minor units — no licensed pricing).
Capturing a test's charge is `ChargeCaptureService::captureManual` (the engine snapshots the fee + computes
the line total); the invoice is the existing `validateForPatientPeriod → createDraftFromCharges → issue`; it
**reconciles-to-the-unit** (I4 δ=0) — the ED-G6 / surgery-G5 / bed-day shape. An **inpatient/ED** lab test's
charge is a patient charge that joins the stay's `invoiceStay` (the composite-episode reuse). **No new billing
math** (adversarial-grep, like every billing gate).

---

## Section 3 — THE PARTNER-GATED HL7 BOUNDARY (the crux — draw it precisely)

The **HL7/FHIR/analyzer integration is the DEFINING feature of a real LIS** — results flowing IN from
analyzers/reference labs automatically, orders flowing OUT electronically. **It is PARTNER-GATED, not built.**

### 3.1 The boundary, stated plainly

| Layer | Owner | Status |
|---|---|---|
| **Lab order entry** (reuse `Order`), **test catalog** (`OrderableItem` overlay), **specimen tracking** (`Specimen`), **manual result entry** (`OrderResult`), **reference-range DISPLAY**, **result routing/review**, **lab billing** | **CareOS** | ✅ Build now — record-keeping, fence-clean, MANUAL path |
| **The HL7/FHIR/analyzer FEED** — electronic transmit OUT + automated result ingest IN | **A certified interoperability/analyzer partner** behind the `LabConnectivity` seam (`transmit` / `ingestResult`) | ⛔ **Never homemade** — the DEFINING value is partner-gated |
| **A COMPUTED abnormal/high/low/critical FLAG or result interpretation** (delta-checks, auto-critical alerting) | **A certified partner**, or a **non-goal** | ⛔ **Never homemade** — the fence line (§4) |

### 3.2 The formalized seam + the manual path

- **The seam:** `LabConnectivity` (`transmit(Order)` / `ingestResult(payload)`) → `ManualLabConnectivity`
  (transmit no-op; ingest throws "entered manually"), **already bound + already consumed** (§2.1). Phase 3
  keeps it and documents the **`imported` target shape**: a real partner's `ingestResult` appends an
  `OrderResult` `source=imported` through the same append-only, fence-clean path (it records the value; it
  **never interprets** it — `DEFERRED.md` P0P.G11).
- **The manual path (buildable today):** a lab tech enters the result via `OrderService::recordResult`
  (`source=manual`); the clinician reviews. This is fully built.

### 3.3 The honest note (say it plainly so expectations are clear)

**WITHOUT the HL7/analyzer partner, the lab vertical is MANUAL order-and-result entry + specimen tracking +
reference-range display + billing — a REAL but LIMITED record-keeping SHELL.** It is genuinely usable (a
small lab or send-out workflow where humans key results), but the **defining LIS value — analyzer/reference-lab
results flowing in automatically — is gated on the interoperability partnership.** This is a business
conversation (an HL7/FHIR interface engine or an analyzer vendor), **not** a code gate. Build the shell; be
explicit it is a shell; fill the seam when a partner is signed. (Same honesty as the ED map's "computed triage
= partner" and the pharmacy map's "drug-safety = partner.")

---

## Section 4 — THE REFERENCE-RANGE FENCE (facts, not computed flags)

The canonical rule (`AGENTS.md:36-39`): **no diagnosis, no triage, no symptom assessment, no dosing logic —
anywhere.** A **computed abnormal/critical result flag is a clinical judgment** on the wrong side of it — the
exact vitals-bands / computed-acuity line, and it is **already refused** in the codebase.

| Layer | Owner | Status |
|---|---|---|
| The **result value** + **unit** + the **reference range** (recorded/displayed) | **CareOS** | ✅ Build now — recorded facts the clinician reads |
| A **computed abnormal/high/low/critical flag**, **delta-check**, **auto-critical alert**, **result interpretation** | **A certified medical-device/CDS partner**, or a **non-goal** | ⛔ Never homemade |

**Already enforced:** `OrderResult` "stored RAW: result_value only, with NO interpretation fields (no
range/flag/abnormal/score) — same posture as vitals"; DB triggers make it append-only; `DEFERRED.md` P0P.G11
states *"Never interpret a result even when auto-ingested."* Phase 3 must **not** add a computed flag anywhere
(schema/service/UI) — the range + value display is a **factual** juxtaposition (like the vitals series showing
raw numbers), never a graded verdict. **Colour/highlight by a computed abnormal state is forbidden**; showing
the range next to the value (both facts) is fine.

**Other interpretation temptations → build the record-not-judge version (or stub):**

| Temptation | Why it's fenced | Build instead |
|---|---|---|
| **Auto-flag high/low/abnormal** vs the range | Computes a clinical judgment (the vitals-bands line) | Display the range + value (facts); the clinician judges |
| **Critical-value auto-alerting** | Computed triage/severity → escalation | A human marks/communicates a critical value (record it); auto-critical alerting = certified partner |
| **Delta-checks** (this result vs the last) | Computed "getting worse?" judgment (the eval already refuses this) | Show the raw prior results in sequence (facts) — the vitals-series posture |
| **Computed result interpretation / reflex testing** | System-proposed clinical decision | The pathologist interprets (records their own reading); reflex rules = partner/non-goal |
| **STAT/priority computed from the test/result** | Computes a clinical priority | Priority is a **recorded flag** the clinician/lab sets |
| **AI "interpret this result"** | System clinical decision | Draft-only, human-owned AT MOST — and it ships its own `tests/Evals/` fence locks |

---

## Section 5 — New roles Phase 3 introduces (RBAC)

Purely additive — new entries in `RbacProvisioner::PERMISSIONS`/`::ROLE_TEMPLATES` + a re-provision migration
(the `add_surgery_permissions`/`add_ed_permissions` precedent); zero `Gate::before` change. `order.manage`
("place + track structured clinical orders") **already exists** and is the ordering permission; lab adds a
specimen/result-entry permission.

**New permissions (additive):**
- **`lab.result`** — enter/track a lab result + manage specimens (the lab tech). *(A lab-bench act, distinct
  from `order.manage` [the clinician ordering] and `note.write`.)*
- **`lab.catalog`** — author the lab test catalog + reference ranges (`billing.manage` prices the tariff, as
  everywhere). *(Or reuse an existing catalog-admin permission if a customer's separation is coarse.)*

**New roles (additive templates):**

| New role | Closest existing template | Already covered | What the new role adds |
|---|---|---|---|
| **Lab technician** | `nurse` (`patient.view`, `order.manage`) | Sees orders, tracks status | **`lab.result`** (enter results, manage specimens) |
| **Pathologist** | `doctor` | Clinical read/write, `order.manage` | **`lab.result`** (+ review/interpret — the human reading, recorded) |
| **Phlebotomist** | `reception`/`nurse` | `patient.view` | **`lab.result`** limited to specimen collection *(or a narrower `specimen.collect` if a customer separates it)* |

**Existing roles that touch lab (reuse):** `doctor`/`hospitalist`/`ed_physician`/`surgeon` already hold
`order.manage` — they **order** labs today via the existing `Order` flow. **`org_admin`** gains the new perms.
Scope stays `branch_id` (branch-level; a lab-section scope is a later `abac_conditions` gate).

---

## Section 6 — Dependency-ordered build sequence (proposed gates)

Foundational-first, each gate buildable + testable. **Placement recommendation: a new peer module
`Modules\Lab`** that **REUSES Clinical's `Order`/`OrderResult`/`LabConnectivity`** (a lab order *is* a Clinical
`Order`).

**Why a peer module, not folded into Clinical.** (1) It mirrors the established shape — Surgery/Pharmacy/ED
are peer modules that reuse Clinical. (2) The net-new lab domain (specimens, the test-catalog overlay, lab
RBAC, the lab worklist/UI) is self-contained and keeps Clinical lean. (3) `Modules\Lab` MAY use Clinical
(the allowed dependency every vertical uses) — it consumes `OrderService` + the `LabConnectivity` seam
app-layer, and mints only the net-new `Specimen`/lab-catalog/reference-range tables. **The `LabConnectivity`
seam itself STAYS in Clinical** (it is already there, already wired into `OrderService::place`) — `Modules\Lab`
consumes it; the "formalization" is documenting the `imported` path + the manual consumer, not moving the seam.
*(Alternative considered: fold specimens/ranges into Clinical since `Order`/`OrderResult`/the seam already live
there. Rejected — it bloats Clinical with a domain [specimens, lab roles, a lab worklist] that is cleanly a
vertical; the peer-module + app-layer-reuse shape is consistent with Surgery/Pharmacy/ED.)*

| Gate | Deliverable | Depends on | FULL vs SEAM-STUBBED |
|---|---|---|---|
| **LAB.G1** | **Module + lab test catalog + the `LabConnectivity` seam formalized + lab RBAC (foundation).** Register `Modules\Lab`; a **lab-test overlay** on `OrderableItem` (`specimen_type`/`unit`/`reference_range`, tenant-authored, no licensed set); **formalize** the existing Clinical `LabConnectivity` seam (document the manual/imported paths; binding stays `ManualLabConnectivity`); `lab_tech`/`pathologist`/`phlebotomist` roles + `lab.result`/`lab.catalog` perms (additive). Backend + tests, minimal UI. | Clinical (Order/Orderable/seam), Platform, RBAC, Audit | **FULL** (catalog + seam formalized; the HL7 impl is partner) |
| **LAB.G2** | **Lab order entry (REUSE Clinical `Order`).** Order a lab test via the EXISTING `OrderService::place` (a lab test = an `Order`); a thin overlay for specimen-type + panel; add a **`stat`** priority const (additive, a recorded flag). Gate `order.manage`. | LAB.G1, Clinical, Patients | **FULL** (direct reuse) |
| **LAB.G3** | **Specimen tracking (NET-NEW).** A `Specimen` (accession, specimen_type, collected_by/at, legal-only `collected → in_lab → resulted` [+ `rejected` + reason]) + append-only `SpecimenEvent`; tied to the `Order`; collecting advances the order to `collected` (existing `transition`, app-layer). Gate `lab.result`. | LAB.G2 | **FULL** (the genuine net-new domain) |
| **LAB.G4** | **Manual result entry + reference-range DISPLAY (the fence).** Reuse `OrderService::recordResult` (append-only `OrderResult`, raw, `source=manual`); display the test's `reference_range` + `unit` beside the value (recorded reference data). **FENCE: NO computed abnormal/high/low/critical flag** — range + value are facts. Gate `lab.result`. | LAB.G3, Clinical (OrderResult) | **FULL** (manual) — **the fence gate** |
| **LAB.G5** | **Result routing + the lab worklist.** Route to the ordering clinician via the EXISTING `reviewed_by`/`review` + Comms; a lab worklist (orders by status: ordered/collected/in_lab/resulted). Gate `order.manage`/`lab.result`. | LAB.G2–G4, Comms | **FULL** (reuse) |
| **LAB.G6** | **Lab billing.** A lab test is a `TariffItem`; capture via `captureManual`; invoice reconciles-to-the-unit (I4); an inpatient/ED test's charge joins the stay's `invoiceStay`. Gate `billing.manage`. | LAB.G2, Billing | **FULL** (orchestration, no new math) |
| **LAB.G7** *(SEAM-STUBBED / partner)* | **HL7/FHIR/analyzer feed.** A certified partner binds a real `LabConnectivity`; `transmit` sends orders OUT, `ingestResult` appends an `OrderResult` `source=imported` (never interpreted). **NOT built — partner-gated.** The seam + the imported-path shape are ready. | LAB.G1 (the seam) | **⛔ SEAM-STUBBED** (the defining value; pending a partner) |

**Rough gate count:** **~6 buildable gates (LAB.G1–G6)** for a MANUAL lab-record-keeping shell,
foundational-first, each testable alone; **+1 SEAM-STUBBED** (LAB.G7 — the HL7/analyzer feed, partner-gated,
NOT built). **Critical path: LAB.G1 → LAB.G2 → LAB.G3 → LAB.G4** (catalog/seam → order → specimen →
result+range is the load-bearing chain). **LAB.G5 (routing) + LAB.G6 (billing) parallel off G2/G4.** **Most
gates are REUSE-heavy** (G2/G4/G5/G6 lean on Clinical + Billing); the only genuinely net-new domain is **G3
(specimens)** and **G4's reference-range reference data**. **LAB.G7 is off the buildable path** — the seam is
established in G1 and stays a no-op until a partner fills it.

---

## Section 7 — Platform-fit + fence risks (where reuse is CLEAN vs FORCED)

**🟢 CLEAN — reuse with confidence (unusually much):** the lab **order** IS a Clinical `Order` (direct reuse —
do NOT mint a new order entity); the lab **result** IS an `OrderResult` (append-only, raw, fence already
built); the **lifecycle** (`ordered→collected→in_progress→resulted→reviewed`) is already lab-shaped; the
**review/routing** is `reviewed_by`/`OrderService::review`; the **connectivity seam** already exists + is
already wired (`transmit` on place); the **catalog** extends `OrderableItem`; **billing** reuses the engine
(no new math); **RBAC** is additive (`order.manage` already models ordering).

**🔴 FORCED / NET-NEW — the few genuine builds:**
1. **Specimen tracking has no home.** `Order.status=collected` is a status, not a specimen — no accession, no
   specimen state, no collection provenance. → a **net-new `Specimen`** entity (§2.2). This is the one real
   net-new lab domain.
2. **Reference ranges are net-new reference data.** `OrderResult` deliberately has no range field. → a
   **`reference_range`** on the lab-test overlay, **displayed** beside the value (§2.5) — reference data, not a
   result field.

**🚨 THE SHARPEST RISKS:**
1. **Duplicating Clinical's `Order`/`OrderResult`.** The biggest mistake would be minting a parallel
   lab-order/lab-result entity — the existing ones fit *exactly* (unlike surgery/ED, which needed net-new flow
   entities). **Reuse them; extend only with the specimen + the catalog overlay.**
2. **The reference-range fence drifting into a COMPUTED abnormal/critical flag.** This is the vitals-bands
   line and the sharpest fence risk. Keep it record-not-judge: display the range + value (facts); **never**
   grade/flag/colour-by-abnormal/delta-check/auto-alert (§4). `OrderResult` already forbids the column — keep
   it forbidden through the overlay + the UI.
3. **Filling the `LabConnectivity` seam with a homemade HL7 client.** The defining value is partner-gated; a
   homemade interface engine is out of scope (partner-and-market work, `DEFERRED.md` P0P.G11). Keep the seam a
   `Manual*` no-op until a certified partner fills it.
4. **Over-promising the value.** The HONEST framing (§3.3): what's built is a **manual record-keeping shell**;
   the analyzer/HL7 feed is the partner-gated defining value. State it plainly to the customer.

---

## Where this sits

**Phase 3 of the phased hospital build.** Phases 1 (inpatient/ADT), 2 (pharmacy), 5 (surgery), and 6 (ED) are
complete; **Phase 3 = the Laboratory / LIS** (this map). Unlike the buildable-in-isolation ED, **the lab's
defining value (the HL7/FHIR/analyzer feed) is PARTNER-GATED** — so LAB.G1–G6 deliver a **real but limited
MANUAL shell** (order via the reused `Order`, track specimens, enter results manually with the reference range
displayed, route/review, bill to the unit), and **LAB.G7 (the analyzer/HL7 feed) waits for a certified
interoperability partner** behind the already-present `LabConnectivity` seam. Day-one manual LIS shell (G1–G6):
a clinician orders a test (reused `Order`) → a phlebotomist collects a tracked `Specimen` → a lab tech enters
the raw result (append-only `OrderResult`) with the reference range shown beside it (**no computed flag**) →
the result routes to the ordering clinician for review → the test bills to the unit. **The long-pole partner
surface — HL7/FHIR transmission + automated analyzer/reference-lab result ingestion, and any computed
abnormal/critical interpretation — stays behind the seam, never homemade.** **Radiology (Phase 4)** follows —
also partner-gated (PACS/DICOM), mapped separately. **Build the manual record; formalize the seam; display the
range but never grade it; and be honest the defining value is partner-gated.**
