# CareOS — Hospital Phase 2 (Pharmacy / Medication Management): Reconciliation + Build-Sequence Map

**Status: analysis only — NO code.** This is the map-before-building step for **Phase 2 of the phased
hospital build** (Phase 1 = inpatient / ADT, complete at `ecff84a`; Phases 3–7 = lab, radiology, OR, ED
still to come, each mapped first). It draws — precisely — the line between the **BUILDABLE pharmacy core**
(formulary, orders, eMAR, dispensing, inventory, billing — all *record-keeping*) and the **LICENSED-PARTNER /
MEDICAL-DEVICE** surfaces (drug-interaction / dose-range / contraindication / duplicate-therapy *judgment*)
that must be **stubbed at a seam, never built homemade**. Same discipline as `docs/HOSPITAL-PHASE1-ADT-MAP.md`,
`docs/DENTAL-DELIVERY-MAP.md`, `docs/CLINIC-DELIVERY-MAP.md`.

> The referenced `careos-hospital-expansion-scoping.md §2.2` is not committed to this repo; this map is
> derived from the authoritative source — the codebase itself — and reconciles against it.

**The one-sentence thesis.** Everything the pharmacy *record* needs already has a fence-clean precedent to
reuse (tenant-authored priced catalog, charge→invoice→reconcile, additive RBAC, the exact-match allergy
hard-stop, the plan→occurrence scheduler, the append-only clinical-event recipe); everything that would
*compute a medication-safety judgment* (interaction / dose / contraindication / duplicate checking) is
**medical-device territory** and is already precedented as a **partner seam** (`LabConnectivity` →
`ManualLabConnectivity` no-op) and already **locked out** by the electric fence (`AGENTS.md:38-39`) + the
eval suite (`tests/Evals`). Build the record; stub the judgment.

---

## Section 0 — What CareOS already provides that Pharmacy REUSES (the head start)

| Existing capability | How pharmacy uses it | Reuse quality |
|---|---|---|
| **Multi-tenancy** (`BelongsToTenant`, fail-closed) | A hospital pharmacy = a tenant; every formulary / order / administration / stock row is tenant-owned, invisible without tenant context. | Clean (free) |
| **Patient master** (Patients) | A medication is ordered/administered/dispensed for a `Patient`. No new patient model. | Clean |
| **Inpatient `Stay` + ward-round `Encounter`** (Hospital G2/G4) | An **inpatient** medication order + eMAR administration is scoped to a `Stay` (and the round `Encounter`) — reuse the Phase-1 spine; the stay↔med link is Hospital-side (the `WardRound` precedent) or app-layer. | Clean (compose) |
| **Tenant-authored catalog pattern** (`TariffCatalog`/`TariffItem`; `DentalProcedure` overlay; `OrderableItem`) | The **formulary is tenant-authored** — the tenant's own med codes + prices, exactly like the bed-day starter and the dental procedure catalog. **No licensed drug DB bundled.** | Clean (pattern precedent — §2.1) |
| **Billing engine** (`TariffItem` · `ChargeCaptureService::captureManual` · `IssueService` · `ReconciliationEngine`) | A **dispensed med is a `TariffItem`**; dispensing **accrues a `Charge`**; the invoice is the existing gather→issue flow; **reconciles-to-the-unit** — the bed-day (G6) precedent, zero new math. | Clean (orchestration only — §2.3) |
| **Clinical `Order` state-machine + `order.manage`-gated `OrderService`** | A medication order **reuses the status-lifecycle + append-only-result + `*.manage`-gated-service *shape*** — but needs its own med-shaped entity (§2.2). | Pattern precedent |
| **Exact-match allergy hard-stop** (`AllergyGuard` + `allergy.override`) | The prescribe/dispense flow reuses the **deterministic substance-key equality** hard-stop + the human override gate — **unchanged, and NOT an interaction engine** (the fence precedent — §3). | Clean (drop-in) |
| **Documented medication list** (`Medication` + `MedicationService`) | The patient's current med list (name/`dose_text`/route/`frequency_text`) already exists behind the allergy fence — the outpatient med-history record the inpatient order/eMAR builds beside. | Clean (adjacent) |
| **Plan → occurrence scheduler** (`VisitPlan(rrule)` → `PlannedVisit` via `VisitPlanGenerator::materialize` + `nursing:materialize-visits`) | **eMAR scheduled doses** reuse the RFC-5545 recurrence→occurrence engine + the idempotent horizon-materialize command (a med schedule ⇒ a due dose at time *T*). | Pattern precedent (§2.4) |
| **Append-only clinical-event recipe** (model guard + `SIGNAL '45000'` DB triggers: `order_results`, `visit_events`, `stay_events`, `handovers`) | An **administration event / dispensing event** is append-only (a correction is a new row) — copy the `order_results` recipe verbatim. | Clean (drop-in) |
| **Record-not-judge model contracts** (`Vital`/`VisitVital` "no interpretation/ranges/flags/scores"; `Handover` SBAR; `OrderResult` raw) | eMAR **"given / held / refused"** is a human-entered FACT — the exact posture already stated on those models. | Clean (+ the fence) |
| **Append-only audit** (hash-chained, immutable) | Every prescribe / administer / dispense is one append-only audit row via an app-layer model hook — the `handover.recorded` / `admission.<state>` pattern. | Clean (drop-in) |
| **RBAC** (`RbacProvisioner::PERMISSIONS`/`ROLE_TEMPLATES`, `Gate::before` unchanged) | `pharmacist` / `pharmacy_technician` roles + `medication.*` permissions are **additive const entries** — the `dental.chart` / inpatient precedent (§4). | Clean (additive) |
| **The partner SEAM pattern** (`LabConnectivity` interface → `ManualLabConnectivity` no-op, bound in a provider) | The **drug-interaction / formulary-DB seam** is a 1:1 copy — an interface bound to a Null/Manual no-op, swappable for a licensed partner later (§3). | Clean (the crux) |
| **Governed AI + electric fence + `composer eval`** (37 evals / 398 assertions; refuses "is this getting worse?" / "should we change meds?") | Any pharmacy agent help (an order-entry draft) is **draft-only, human-owned**; a computed interaction/dose judgment is **fence-inconsistent and eval-rejected** (§3). | Clean (+ the fence) |
| **Design system** (Eucalyptus Glow) + **route-smoke / MySQL-parity / immutability guards** | The formulary / order / eMAR / dispensing UIs reuse tokens + primitives; new routes ride the existing smoke + parity guards. | Clean |

**Read-off:** the pharmacy *record* is ~80% reuse. The only genuinely net-new domain tables are the
**formulary overlay**, the **medication order**, the **eMAR schedule + administration event**, and
**inventory + dispensing** — and the only thing that must **never** be built is the **safety judgment**.

---

## Section 1 — The pharmacy spine, mapped (reuse vs net-new vs licensed-partner)

| # | Spine element | What it needs | REUSE | NET-NEW pharmacy domain | ⛔ LICENSED-PARTNER / DEVICE (stub) |
|---|---|---|---|---|---|
| 1 | **Formulary** (tenant med catalog) | The tenant's own list of dispensable/orderable meds + prices | Tenant-authored catalog **pattern**: `TariffItem` + a thin overlay (the `DentalProcedure` model); `seedStarter`/`STARTER` (own codes, no licensed set); `TariffCatalog` effective-dating | **`FormularyItem`** overlay on a `TariffItem` — med-descriptive fields (form, strength, route-default, generic name) the tenant authors | A **licensed drug DB** (First Databank / Medi-Span / regional) that would *enrich/validate* the formulary → a partner surface at a clean seam (§2.1) |
| 2 | **Medication order** (prescribing, inpatient) | A dose of a formulary item ordered for a patient during a stay: dose/route/frequency/duration/PRN, start/stop, status | The `Order` **status-machine + `order.manage`-gated service *shape***; `Stay`/round-`Encounter` link (Hospital-side); the exact-match `AllergyGuard` hard-stop + `allergy.override` | **`MedicationOrder`** entity — the med-shaped fields the generic `Order` lacks (§2.2); append-only order-events | **Interaction / dose-range / contraindication check** at order time → invoked at a **stub seam**, never computed (§3) |
| 3 | **eMAR** (administration record) | Scheduled + PRN doses; a nurse records each dose *given / held / refused* (who, when, why) | The `VisitPlan→PlannedVisit` **recurrence→occurrence** engine + idempotent materialize command; the **append-only** `order_results`/`visit_events` recipe; the **record-not-judge** `VisitVital`/`Handover` contract | **`MedicationSchedule`** (→ materialized **`DueDose`**) + **`AdministrationEvent`** (given/held/refused + `administered_by` + time + reason) tied to the order + `Stay` | The **"is this dose safe/late/missed-critical" judgment** — never computed; the record is a fact (§3) |
| 4 | **Dispensing + inventory** | Pharmacy stock levels; a dispense decrements stock and links to the order | `BelongsToTenant`; the append-only event recipe; the `Charge` link (#5) | **`StockItem`** (product, on-hand qty, optional lot/expiry) + append-only **`DispenseEvent`** (decrement-on-dispense, order-linked) | **Auto-substitution / therapeutic-interchange** *decision* → partner/non-goal (§3.4); barcode/BCMA scanning hardware → device surface (later) |
| 5 | **Pharmacy billing** | Dispensed meds as billable charges → one invoice | **Billing engine unchanged**: a formulary item's `TariffItem` → `captureManual` → `Charge` → `validateForPatientPeriod` → `createDraftFromCharges` → `issue`; `ReconciliationEngine` I4 | **Orchestration only**: capture a charge per dispense/administration (the `DentalChargeService`/bed-day shape) | none (facts/money) |
| 6 | **Medication-safety checking** (the crux) | Drug–drug interaction · allergy-*class* contraindication · dose-range/max-dose · duplicate-therapy | *(nothing — CareOS records the inputs)* | *(nothing — CareOS records the inputs)* | **ALL of it** — a certified partner engine behind a **stub seam**; homemade = permanent non-goal (§3) |

---

## Section 2 — The load-bearing reuse decisions (get these right)

### 2.1 The formulary is **TENANT-AUTHORED — no licensed drug database bundled.** (This is the whole game.)

The formulary is the tenant's own medication list, authored/imported by the tenant — **exactly** the posture
already shipped twice:

- **Dental** (`DentalCatalogService.php:30-32`): *"NO licensed code set (ADA CDT / Swiss SSO point values) is
  bundled — the catalog is tenant-authored; `seedStarter()` lays down a small GENERIC editable template with
  placeholder fees the dentist changes."* A `DentalProcedure` (`DentalProcedure.php:11-13`) is *"a THIN overlay
  on a Billing `tariff_items` row … the overlay adds only the dental-specific `tooth_scoped` flag."*
- **Bed-day** (`BedBillingService.php:42-47`): *"A GENERIC starter per-diem template — the tenant's own codes
  (NO licensed code set), placeholder rates … the tenant edits."*
- **Orderables** (`OrderableItem.php:9-10`): *"A tenant-authored orderable test/study. NOT a licensed catalog."*

**Recommendation — the priced-overlay shape (like `DentalProcedure`):** a `FormularyItem` overlay on a
`TariffItem` (the tariff row holds the code + price + VAT + unit; the overlay adds med-descriptive fields:
generic name, form [tablet/capsule/solution], strength, default route). `seedStarter(User $actor)` (gate
`billing.manage`, idempotent by code — the exact `BedBillingService::seedStarter` shape) lays down a **small
generic starter** (e.g. `MED-PARACETAMOL-500-TAB`, `MED-AMOXICILLIN-500-CAP`) with placeholder prices the
pharmacist edits. This makes dispensing bill through the engine for free (§2.3).

> **The clean integration point for a licensed drug DB.** A First Databank / Medi-Span / regional database
> would later *enrich* a formulary item (canonical name, ingredients, ATC/RxNorm codes) and *feed* the
> interaction engine (§3) — attaching at the formulary and the check-seam, **without** CareOS ever
> re-hosting or shipping licensed drug data. The formulary stays the tenant's own list; the partner is an
> optional overlay. **Precedent: no licensed CDT/ICD/test catalog is bundled anywhere in CareOS today.**

### 2.2 Medication order — **a new `MedicationOrder` entity, reusing the `Order` *pattern* (not the table).**

The generic `Order` is a lab/imaging/referral envelope with **zero** medication shape — its fillable
(`Order.php:56-68`) is `patient_id, encounter_id, orderable_item_id, ordered_by, ordered_at, priority,
clinical_note, status, …`; the "what" is entirely delegated to `orderable_item_id` + free-text
`clinical_note`. There is **no** dose, route, frequency, duration, or PRN field.

| | Extend/overload `Order` | **New `MedicationOrder` (recommended)** |
|---|---|---|
| Pros | No new table | Owns the med-specific shape cleanly (dose, route, frequency, duration, PRN, start/stop, taper); every existing `Order` consumer (labs/imaging worklists, `toReview`) keeps working unchanged; a med order's lifecycle (active→held→discontinued) differs from an orderable's (ordered→collected→resulted→reviewed) |
| Cons | Forces dose/route/frequency onto **every** orderable; conflates "a test to run + result" with "a drug to give repeatedly over days"; pollutes the lab worklists | One new model/service/gate; a decision on the inpatient link (below) |

This is the **same call as Stay-above-Encounter** (Phase 1 §2.2): reuse the proven *shape* (the status-machine,
the `*.manage`-gated service, the append-only event, the same-tenant asserts), own the domain. Concretely:
`MedicationOrder(patient_id, encounter_id?, formulary_item_id, prescribed_by, dose, dose_unit, route,
frequency, duration_or_prn, starts_at, stops_at?, status, indication_note)` with a status machine
`active → held → discontinued/completed`, gated by a new `medication.prescribe` permission (§4), placed
through a `MedicationOrderService::prescribe(...)` shaped on `OrderService::place`. **The `AllergyGuard`
hard-stop fires at prescribe** (reuse `MedicationService::record`'s exact flow: `AllergyGuard::check` →
`allergy.override` if a reason + the permission). **The interaction/dose seam is *invoked* here and returns a
no-op** (§3).

**Inpatient link (arch-clean).** Keep `MedicationOrder` referencing `Patient` + optional `Encounter`
(Clinical) so the module stays independent of Hospital; the **stay↔med-order association lives Hospital-side**
(a thin link, the `WardRound` precedent — `WardRound(stay_id, encounter_id)`) or is composed at the app layer.
This preserves the arch posture that verticals don't hard-depend on each other (§5).

### 2.3 Pharmacy billing — **the existing engine, unchanged; net-new is strictly orchestration.**

A dispensed (or administered) med is a `TariffItem`; capturing its charge is `captureManual` — which
**snapshots the price and computes the line total in the engine** (`ChargeCaptureService.php:135-142`:
`line_total_minor = quantity * unit_price_minor`). This is *already reused* by `DentalChargeService::capture`
(*"adds NO pricing/charge/VAT/line-total math of its own … the engine owns all of it"*,
`DentalChargeService.php:18-24`) and `BedBillingService::accrueBedDays`. Pharmacy billing is the **bed-day
(G6) pattern**: capture a charge per dispense (formulary item's code, qty), then the existing
`validateForPatientPeriod → createDraftFromCharges → issue` collapses N charges onto one invoice, and
`ReconciliationEngine` I4 proves it **reconciles-to-the-unit** (δ=0). **No new billing/pricing/VAT/line-total
math** — the same adversarial-grep discipline as G6.

### 2.4 eMAR scheduling — reuse the **plan→occurrence** engine; the administration event is **append-only + record-not-judge.**

A medication *schedule* (e.g. "500 mg PO q8h for 5 days") is a recurrence; the *due doses* are its
occurrences — the exact `VisitPlan(rrule)` → `PlannedVisit` shape, materialized idempotently by
`VisitPlanGenerator::materialize` (`Recurr\Rule` + `ArrayTransformer` + `BetweenConstraint`; upsert keyed on
`tenant/plan/scheduled_date`) and driven by a rolling-horizon command (`nursing:materialize-visits`). Phase 2
mirrors this: a `MedicationSchedule` → materialized `DueDose` rows via `pharmacy:materialize-doses`
(idempotent, twice ≠ double-schedule). PRN ("as needed") doses are unscheduled — an administration event with
no due-slot.

The **administration event** is append-only (the `order_results`/`visit_events` recipe: model
`updating`/`deleting` guards + two `SIGNAL '45000'` triggers + a `unique` guard on the due-slot so a dose is
charted once) and **records a fact, not a judgment**: `given / held / refused` + `administered_by` + time +
reason — the posture already stated on `VisitVital` (*"No interpretation, ranges, flags, scores"*),
`Handover` (*"the system records the handover, it never computes a judgment"*), and `OrderResult` (raw value).
**Gap to fill vs the `VisitTask` template:** `VisitTask` has `open/done/not_done(+reason)` but **no
`completed_by` actor and no "held"** — eMAR extends the shape with an explicit `administered_by` and the
richer `given/held/refused` outcome. It computes nothing (no "late", no "missed-critical" flag — those are
judgments).

---

## Section 3 — THE MEDICAL-DEVICE BOUNDARY (the crux of this map)

**The single most important line in Phase 2.** Everything CareOS builds is *record-keeping*. The moment
software **computes a medication-safety judgment** — does this drug interact with that one? does this dose
exceed the max? does this contraindicate the patient's condition/allergy *class*? is this a duplicate
therapy? — it is **clinical decision support**, regulated as a **medical device** (e.g. EU MDR Class IIa/IIb),
and it is **exactly what the electric fence refuses.**

**The canonical rule (`AGENTS.md:38-39`, HARD RULES, "never violate"):**

> **ELECTRIC FENCE:** no diagnosis, no triage, no symptom assessment, **no dosing logic** — anywhere in code,
> prompts, or AI features. Ever.

**It is already written down as a non-goal (`DEFERRED.md:54-57`, D-P0D.G3):**

> **Drug interaction / allergy class / dose / CDS engines are medical-device territory.** Drug-interaction
> checking, allergy class inference, dose calculation, and clinical decision support require a **partner-first
> licensed drug database and a funded regulatory track**; **do not build these in-house** as CareOS
> deterministic clinical-list logic.

### 3.1 The four checks — each is a STUB seam, never homemade

| Check | Why it is a computed clinical JUDGMENT (fenced) | The build |
|---|---|---|
| **Drug–drug interaction** | Computes "these two drugs are dangerous together" — a clinical safety verdict from a licensed interaction knowledge base | **Stub seam** — a certified partner engine; the record (both orders) is CareOS's |
| **Allergy-*class* / cross-reactivity contraindication** | Infers "patient is allergic to X ⇒ also react to class-mate Y" — ontology/ingredient reasoning | **Stub seam.** CareOS keeps only the **exact-match** `AllergyGuard` (below) |
| **Dose-range / max-dose** | Computes "this dose is too high/low for this patient" — dosing logic, literally the fenced example | **Stub seam.** CareOS records the ordered dose (a fact); it does not judge it |
| **Duplicate therapy** | Infers "these two orders are the same therapeutic class" — class reasoning | **Stub seam.** CareOS shows the med list (a fact); it does not compute the clash |

### 3.2 The precedent is already in the codebase — copy it exactly

**(a) The exact-match allergy hard-stop is *deliberately* not an interaction engine.** `AllergyGuard::check`
(`AllergyGuard.php:16-31`) blocks only when the normalized `substance_key` **exactly equals** an active
documented allergy — `normalize()` is `Str::lower(trim())` and *nothing more*. There is **no** RxNorm/ATC/
SNOMED code, no drug-class, no ingredient expansion, no interaction table (a repo-wide grep for
`interaction|drug_class|cross.react|ingredient|contraindicat` returns **zero** drug-safety hits). The design
record states it outright (`memory/modules/Clinical.md:150`; `DECISIONS.md:114-115`): *"`substance_key`
equality. No fuzzy matching, drug-class inference, interaction checking."* **This is the fence-safe pattern to
reuse verbatim** — a deterministic list match with a human `allergy.override` gate, *not* the beginning of a
homemade checker.

**(b) The partner SEAM already exists for labs — copy it 1:1.** `LabConnectivity`
(`Modules/Clinical/src/Contracts/LabConnectivity.php`) is *"DELIBERATELY an interface with only a Manual
(no-op) implementation — real lab connectivity is partner-and-market work and is NOT built here."*
`ManualLabConnectivity` (`ManualLabConnectivity.php:14-29`) is a no-op `transmit()` and an
`ingestResult()` that **throws** "not available; entered manually". It is bound in
`ClinicalServiceProvider::register` (`$this->app->bind(LabConnectivity::class, ManualLabConnectivity::class)`).

> **The pharmacy build provides the SEAM, not the logic.** Define
> `interface DrugSafetyChecker { check(MedicationOrder|context): SafetyFinding[]; }` bound to a
> `NullDrugSafetyChecker` (returns "no automated check available — pharmacist/prescriber reviews") in
> `PharmacyServiceProvider::register`. The order/administration flow *invokes* it and records the result;
> when a certified partner is licensed, the binding swaps — CareOS's records don't change. **Identical to
> `LabConnectivity`.**

**(c) A homemade checker fails the eval discipline.** `tests/Evals/ClinicalAgentsEvalTest.php:200-226` feeds
the literal asks **"is this getting worse?"** and **"should we change meds?"** and asserts
`status === 'refused'` + `human_handoff === true` + zero writes. A drug-interaction/dose verdict *is* "should
we change/give this med?", computed. Unlike billing (where the agent's number is discarded because the
deterministic `TariffResolver`/`ChargeValidator` is the ground truth), **there is no deterministic in-house
ground-truth engine a safety checker could mirror** — the ground truth is the *licensed knowledge base*. So a
homemade version can neither pass the fence nor be validated; it must be **deferred to a partner behind the
interface.** (The eval suite — 37 evals / 398 assertions, `composer eval`, part of CI — *"Locks, never
changes … never edit the eval to pass"*, `AGENT-EVALS.md:14-15`.)

### 3.3 The boundary, stated plainly

| Layer | Owner | Status |
|---|---|---|
| Formulary (tenant list), medication **orders**, **eMAR** administration record, **dispensing**, **inventory**, **billing**, the **exact-match** allergy hard-stop | **CareOS** | ✅ Build now — all record-keeping, fence-clean |
| Drug–drug **interaction**, allergy-**class** contraindication, **dose-range/max-dose**, **duplicate-therapy** *judgment* | **A certified licensed-drug-DB partner**, behind a `DrugSafetyChecker` seam (advisory, human-owned, logged) — or a **non-goal** | ⛔ **Never homemade.** Stubbed from gate 1; the binding swaps when licensed |

### 3.4 Other interpretation temptations → build the record-not-judge version (or stub)

| Temptation | Why it's fenced | Build instead |
|---|---|---|
| **Auto-dose calculation** (mg/kg, renal/hepatic adjust, BSA) | Dosing logic — the literal fenced example | Record the **dose the prescriber enters**; a partner calculator is behind the seam |
| **Auto-substitution / therapeutic interchange** *decision* | Computes a clinical equivalence judgment | Record the substitution **a pharmacist makes** (with reason); never auto-swap |
| **IV compatibility / admixture-stability computation** | Computes a chemistry safety verdict | Partner/reference surface; CareOS records what was given |
| **"Missed / late / critical-dose" flags on eMAR** | Computes a clinical-priority judgment on a fact | Show the raw **due time vs administered time** (facts); the nurse/pharmacist judges |
| **Interaction/dose "smart alerts" at order entry** | The §3.1 judgment, surfaced as an alert | Invoke the `DrugSafetyChecker` seam → renders the **partner's** advisory (or "no automated check"), never a homemade one |
| **AI "suggest a regimen / adjust meds"** | System-proposed clinical decision | Draft-only, human-approved AT MOST — and it must ship its own `tests/Evals/` fence locks; a regimen *judgment* is refused-and-handed-off |

**The discipline, stated as a standing rule:** the `DrugSafetyChecker` seam must **never** be filled with
homemade logic under pressure to "make it smart." That is simultaneously a **fence** violation (computed
clinical judgment) and a **legal/regulatory** one (unlicensed medical device on unlicensed drug data). The
homemade version is a **permanent non-goal.**

---

## Section 4 — New roles Phase 2 introduces (RBAC)

Adding roles/permissions is **purely additive** — new entries in `RbacProvisioner::PERMISSIONS` and
`::ROLE_TEMPLATES` (plain const arrays synced by `provisionTenant()`), plus a permission migration, with
**zero** change to `Gate::before`/`PermissionService` — the exact way `dental.chart` and the six inpatient
roles were added. Naming stays `<domain>.<verb>`.

**New permissions (additive):**
- **`formulary.manage`** — author/edit the tenant formulary (pharmacist). *(Alternatively fold into
  `billing.manage`, since the formulary is a priced catalog; a distinct perm is cleaner.)*
- **`medication.prescribe`** — place/hold/discontinue a medication order (prescriber). *(Distinct from
  `order.manage`, which is lab/imaging-scoped.)*
- **`medication.administer`** — record an eMAR administration (given/held/refused) (nurse).
- **`medication.dispense`** — dispense + adjust stock (pharmacist / pharmacy technician).

**New roles (additive templates):**

| New role | Closest existing template | Already covered | What the new role adds |
|---|---|---|---|
| **Pharmacist** | `billing`-adjacent + clinical read | `patient.view` exists broadly | **`formulary.manage`** + **`medication.dispense`** + `billing.manage` (to price/bill dispenses) + `allergy.override` (deterministic hard-stop, with reason) + `medication.prescribe` if the site lets pharmacists prescribe by protocol |
| **Pharmacy technician** | *(none directly)* | — | **`medication.dispense`** + inventory management, **under** a pharmacist; **no** prescribe, **no** override |

**Existing roles that touch meds (reuse, no new role needed):**
- **Prescriber** — `doctor` / `hospitalist` (both hold `order.manage`, `note.write`, and — hospitalist —
  `allergy.override`) gain **`medication.prescribe`**. The hospitalist is the natural inpatient prescriber.
- **Administering nurse** — `nurse` / `ward_nurse` / `charge_nurse` (hold `note.write`/`note.sign`) gain
  **`medication.administer`** — the eMAR write, reusing the note-write-style clinical posture (the G5 handover
  precedent: reuse an existing clinical permission where possible).
- **`org_admin`** gains all four new permissions (it holds every permission).

**Scope note (unchanged from Phase 1):** the only RBAC scope axis is `branch_id`; there is no
ward-/pharmacy-level scope. Branch-level is fine for Phase 2; finer scope is a later `abac_conditions` gate.

---

## Section 5 — Dependency-ordered build sequence (proposed gates)

Foundational-first, each gate buildable + testable on its own. **Placement recommendation: a new peer module
`Modules\Pharmacy`** (not folded into `Modules\Hospital`). Rationale: (1) medication management is its own
vertical that overlays Billing + Clinical + Patients — the self-contained shape of `Modules\Dental` and
`Modules\Hospital`; (2) the arch tests deliberately keep verticals from depending on each other
(`ModuleBoundariesTest.php:163-175`), and folding pharmacy into the inpatient/ADT module would mis-scope it
(a formulary + dispensing serve outpatient too) and couple two verticals; (3) pharmacy needs to *use* Billing
+ Clinical (`Medication`/`AllergyGuard`) + Patients, and should **exclude** `Audit\Models`, `AiCore`, `Comms`,
and peer verticals — a new `arch('Pharmacy …')` rule in the Dental/Hospital style. Register a
`PharmacyServiceProvider` in `bootstrap/providers.php`; **bind the `DrugSafetyChecker` seam to its null
implementation in `register()`** (the `LabConnectivity` precedent). Cross-module composition (an inpatient
administration that touches the `Stay` + fires audit) lives in the **app layer** — the standing boundary rule.

| Gate | Deliverable | Depends on | Notes |
|---|---|---|---|
| **PH.G1** | **Module + tenant-authored Formulary + pharmacy RBAC + the safety SEAM (foundation).** Register `Modules\Pharmacy`; a `FormularyItem` overlay on `TariffItem` (form/strength/route/generic name) with `seedStarter` (tenant's own codes, **no licensed drug DB**); `pharmacist` + `pharmacy_technician` roles + `formulary.manage`/`medication.*` permissions (additive); **bind `DrugSafetyChecker` → `NullDrugSafetyChecker` no-op**. Backend + tests, minimal UI. | Platform, Billing, Patients, RBAC, Audit (services) | **Everything below depends on this.** Fence: formulary is a record; the seam is a no-op. |
| **PH.G2** | **Medication orders (prescribing).** Net-new `MedicationOrder` (dose/route/frequency/duration/PRN/start-stop, status active→held→discontinued) reusing the `Order` status-machine + a `MedicationOrderService::prescribe` (gate `medication.prescribe`); the **exact-match `AllergyGuard` hard-stop** + `allergy.override` fire at prescribe; the **`DrugSafetyChecker` seam is invoked** (returns "no automated check"); append-only order-events; inpatient stay-link Hospital-side/app-layer. | PH.G1, Clinical (`AllergyGuard`), Hospital (`Stay`, via app-layer) | The clinical spine. Fence: **no dose calc, no interaction judgment** — the seam only. |
| **PH.G3** | **eMAR (administration record).** `MedicationSchedule` → materialized `DueDose` via `pharmacy:materialize-doses` (the `VisitPlan→PlannedVisit` engine, idempotent) + PRN; append-only `AdministrationEvent` (**given/held/refused** + `administered_by` + time + reason, the `order_results` recipe) tied to the order + `Stay`; a stay/ward eMAR view. Gate `medication.administer`. | PH.G2, Nursing scheduler pattern | Fence: given/held/refused is a **FACT** (`VisitVital`/`Handover` precedent); **no late/missed/critical flag** (a judgment). |
| **PH.G4** | **Dispensing + inventory.** Net-new `StockItem` (product, on-hand qty, optional lot/expiry) + append-only `DispenseEvent` (decrement-on-dispense, order-linked); a dispensing worklist + stock view. Gate `medication.dispense`. | PH.G1, PH.G2 | Fence: stock counts are facts; **no auto-substitution** (partner/non-goal). Inventory is 100% net-new (no analogue exists). |
| **PH.G5** | **Pharmacy billing.** Each dispense (or administration) captures a `Charge` through the **existing engine** (`FormularyItem.tariffItem.code` → `captureManual`); the invoice is the existing gather→issue flow; **reconciles-to-the-unit** (I4). The bed-day (G6) shape. | PH.G2/PH.G4, Billing | **No new billing math** (adversarial-grep, like G6). |
| **PH.G6** *(optional/later)* | **Outpatient prescribing + discharge meds + refills.** Reuse PH.G2/G3 for ambulatory/discharge scripts; refill workflow. | PH.G2 | Small; composes the existing spine. Controlled-substance registers + BCMA barcode scanning (a device surface) are separate later gates. |

**Rough gate count:** **~5 core gates (PH.G1–G5)** for a credible inpatient-pharmacy MVP, foundational-first,
each testable alone; **+1 optional** (G6 outpatient/discharge/refills). **Critical path: PH.G1 → PH.G2 →
PH.G3** (formulary → orders → eMAR is the medication spine — the clinically load-bearing chain). **PH.G4
(dispensing/inventory) parallels off PH.G1/PH.G2; PH.G5 (billing) pulls PH.G2 + PH.G4.** **The
`DrugSafetyChecker` seam is established in PH.G1 and invoked at PH.G2 (order) + PH.G3 (administration)
throughout — and is a no-op at every one of those call sites.**

---

## Section 6 — Platform-fit + fence risks (where reuse is CLEAN vs FORCED)

Called out honestly so pharmacy is not built on a wrong abstraction — or, worse, over the fence.

**🔴 FORCED — do not stretch these:**
1. **Medication order ≠ generic `Order`.** The `Order` table has no dose/route/frequency/duration/PRN; cramming
   them on breaks the lab/imaging worklists for every consumer (the Stay-vs-Encounter trap again). → **net-new
   `MedicationOrder`**, reusing only the status-machine + service *shape* (§2.2).
2. **eMAR scheduled administration has NO existing analogue.** The closest pattern is the Nursing
   `VisitPlan→PlannedVisit` scheduler — a *pattern precedent*, not a drop-in (it schedules nurse visits, not
   doses; `VisitTask` lacks a `completed_by` actor and a "held" outcome). → **net-new `MedicationSchedule`/
   `DueDose`/`AdministrationEvent`**, borrowing the recurrence→occurrence engine + the append-only recipe.
3. **Inventory is 100% net-new.** No stock/lot/batch/dispense model or table exists anywhere. → build it fresh
   (PH.G4); don't force it onto any existing model.

**🟢 CLEAN — reuse with confidence:** the tenant-authored priced catalog (`TariffItem` + a `DentalProcedure`-style
overlay); `captureManual` + `ReconciliationEngine` I4 (dispense billing, no new math); the exact-match
`AllergyGuard` + `allergy.override` (the safety hard-stop); the append-only `order_results`/`visit_events`
trigger recipe (administration/dispense events); additive `RbacProvisioner` consts (new roles/permissions);
the `LabConnectivity` seam pattern (the `DrugSafetyChecker` stub).

**🚨 THE SHARPEST RISK — the safety seam under pressure.** The one way this vertical goes wrong is **filling
the `DrugSafetyChecker` seam with homemade logic** to "make it smart" (an interaction table, a dose-range
formula, an allergy-class map). That is the fence line (`AGENTS.md:38-39` — *no dosing logic, ever*), the
documented non-goal (`DEFERRED.md:54-57` — *medical-device territory … do not build in-house*), and the eval
lock (`ClinicalAgentsEvalTest` — a med-safety verdict is refused-and-handed-off). It is also a **legal**
line: an unlicensed medical device computing on unlicensed drug data. **The record is CareOS's; the judgment
is a certified partner's.** Build the seam empty and keep it empty until a licensed partner fills it.

---

## Where this sits

**Phase 2 of the phased hospital build.** Phase 1 (inpatient / ADT) is complete (G1 beds → G2 ADT → G3 board →
G4 charting → G5 handover → G6 billing → G7 discharge). **Phase 2 = pharmacy / medication management**
(this map). **Phases 3–7 remain, each mapped before building:** lab, radiology, OR/theatre, ED — plus the
long-pole partner/device surfaces (HL7/FHIR feeds, BCMA barcode, the certified drug-safety and
early-warning engines) that stay behind seams, never homemade.
