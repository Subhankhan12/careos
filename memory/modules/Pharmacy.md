# Module: Pharmacy (`Modules\Pharmacy`)

## Purpose

The pharmacy / medication-management vertical — **Phase 2** of the phased hospital build (Phase 1 =
inpatient/ADT, complete). Planned ~5 core gates (`docs/HOSPITAL-PHASE2-PHARMACY-MAP.md`): formulary +
RBAC + the safety seam (G1) → medication orders (G2) → eMAR (G3) → dispensing + inventory (G4) → pharmacy
billing (G5). **PHARMACY.G1 ships the FOUNDATION only:** the tenant-authored formulary + the
medication-safety **SEAM (built empty)** + pharmacy RBAC. No orders/eMAR/dispensing/billing yet. Pharmacy
inherits the whole tested platform (tenancy, patients, billing, audit, RBAC, the electric fence).

## The medication-safety seam decision (G1 — THE crux)

Drug–drug interaction · allergy-class contraindication · dose-range/max-dose · duplicate-therapy checking
each **compute a clinical-safety JUDGMENT** — what the electric fence refuses (*"no dosing logic … Ever"*,
`AGENTS.md`) and **medical-device territory** requiring a certified partner over a licensed drug database
(`DEFERRED.md` D-P0D.G3; map §3). So G1 builds the **SEAM, not the logic**, EXACTLY mirroring Clinical's
`LabConnectivity → ManualLabConnectivity` no-op: a `MedicationSafetyProvider` interface bound to a
**`NullMedicationSafetyProvider`** that returns `SafetyResult::none()` for every call. **A homemade checker
is a permanent non-goal** — it would contradict the clinical-safety eval (`ClinicalAgentsEvalTest` refuses
"should we change meds?"). `none()` means "CareOS asserts nothing about safety", NOT "these meds are safe" —
a human (and, when licensed, a certified partner bound in place of the null-object) owns that judgment.
When a partner is wired, its findings are ADVISORY + human-owned (surfaced, never auto-blocking).

## Key tables

- `formulary_items` (BelongsToTenant) — the tenant's OWN medication list (the `TariffItem`/`OrderableItem`/
  `DentalProcedure` catalog discipline). Columns: id (ULID), tenant_id, `code` (the tenant's OWN code — NOT
  a licensed identifier), `name`, `form` (nullable — a plain dosage form ∈ {tablet, capsule, liquid,
  injection, topical, other}), `strength` (nullable free text), `active`. `unique(tenant_id, code)`, index
  (tenant_id, active). **NO licensed drug data bundled** (no First Databank / Medi-Span / RxNorm / ATC /
  NDC); **NO computed-safety column** (no interaction/dose/contraindication/severity/score/risk). A licensed
  drug DB would later ENRICH a row at a partner seam — deliberately NOT attached now.

## Key classes

- `Models\FormularyItem` — BelongsToTenant + HasUlids; `FORMS` vocabulary; a `saving` guard (non-empty
  code+name, valid form) throwing `FormularyException`. No LogsReads (a tenant config catalog, like
  `TariffItem`, not a patient record).
- `Services\FormularyService` — `seedStarter` (gate `formulary.manage`, idempotent by code, a SMALL GENERIC
  starter of 5 common meds with the tenant's own `MED-*` codes — NOT a licensed set) + `create`/`update`/
  `deactivate` (gate `formulary.manage`, tenant fail-closed via `assertSameTenant`) + `forTenant` read.
- `Contracts\MedicationSafetyProvider` — the safety SEAM: `checkOrder(SafetyContext): SafetyResult` +
  `checkAdministration(...)` (the future G2/G3 call sites). Bound to the null-object in
  `PharmacyServiceProvider::register` (swappable for a certified partner WITHOUT touching consumers).
- `Services\NullMedicationSafetyProvider` — the ONLY shipped impl; returns `SafetyResult::none()`.
- `Support\SafetyResult` (`::none()`, `hasAlerts()`, `alerts`), `Support\SafetyAlert` (code/message/source —
  the shape a PARTNER returns; never constructed by CareOS), `Support\SafetyContext` (patientId +
  `MedicationReference[]`), `Support\MedicationReference` (code/name/dose/route) — plain value objects, so
  the seam is stable across later gates.
- `Http\Controllers\FormularyController` (string-id FIX.1) — `index`/`store`/`deactivate`, gated
  `formulary.manage`, renders `Pharmacy/Formulary.vue` (a minimal formulary admin surface). Formulary writes
  audited via app-layer `FormularyItem::created`/`updated` hooks (`formulary.item.created`/`.updated`).
- `Providers\PharmacyServiceProvider` — loadMigrations + `register()` binds the safety seam to the
  null-object.

## Invariants

- **Tenant-authored, NO licensed drug data.** The formulary is the tenant's own list (own codes); the
  starter is a generic editable template. Asserted by a test (own MED-* codes, tenant-isolated, and a schema
  fence: no rxnorm/atc/ndc/gtin/interaction/severity/score column).
- **The safety seam is EMPTY + swappable.** The bound provider is the null-object and returns no alerts (it
  asserts no safety judgment); a test double implementing the interface is resolvable (proving the seam is
  real for a future certified partner). NO homemade checking logic exists.
- **Tenant + fail-closed:** `BelongsToTenant` confines every query; a cross-tenant formulary edit throws
  `CrossTenantReferenceException`. **RBAC:** authoring is `formulary.manage` (server Gate authoritative);
  audited.
- **ELECTRIC FENCE:** a formulary item is a plain record; medication-safety judgment lives ONLY behind the
  certified-partner seam, never on the row or in CareOS code.

## Arch boundary

`arch('Pharmacy may use care modules + Audit services but not Audit models, AiCore, peer verticals, or
Comms')` — mirrors Dental/Hospital. Pharmacy may use Platform + care modules (Patients/Clinical/Billing) +
Audit SERVICES; it must NOT use Audit models, AiCore, Comms, or the **peer verticals** Nursing/Dental/
Hospital (the inpatient stay-link for G2 is composed at the **app layer**, not by a direct Hospital
dependency). Cross-module audit composition (the `FormularyItem` hooks) lives in `app/AppServiceProvider`,
so Pharmacy stays free of Audit. In G1 Pharmacy depends only on the Platform foundation.

## RBAC (additive — the `dental.chart`/inpatient precedent)

New permissions in the catalog: `formulary.manage` (author the formulary), `dispense.manage` (dispensing +
stock — the G4 foundation, no consumer yet). New starter roles: `pharmacist` (patient.view +
formulary.manage + dispense.manage) + `pharmacy_technician` (patient.view + dispense.manage, dispenses under
a pharmacist). `org_admin` gains both new permissions. Added via the RbacProvisioner consts + a backfill
migration (`syncPermissionCatalog` + `provisionTenant` all tenants — the `add_billing_manage_permission`
pattern); new tenants get them via the Tenant `created` hook. The `RbacTest` permission-count assertion is
relative to the const, so additions stay green.

## Medication orders (PHARMACY.G2)

The prescribing entity — a NET-NEW medication order that THREADS the G1 safety seam. The generic clinical
`Order` has no dose/route/frequency/PRN (don't force it; map §2.2), so a med order OWNS its tables while
reusing the proven patterns (the `Stay`/`StayEvent` mutable-state + append-only-history shape).

- `medication_orders` (BelongsToTenant, **LogsReads**) — the MUTABLE current state: `patient_id`,
  `prescribed_by` (User), `formulary_item_id` (the G1 formulary), `stay_id` (**SOFT nullable ref** to a
  Phase-1 inpatient stay — **no FK/relation**, so Pharmacy stays arch-independent of Hospital; null for
  outpatient), `dose_amount` + `dose_unit`, `route` (a plain enum — PO/IV/IM/SC/topical/inhaled/other),
  `frequency` (a schedule descriptor — QID/BID/PRN, tenant-meaningful free text), `starts_at`/`stops_at`,
  `prn` + `prn_reason`, `note`, `status` ∈ {active, held, discontinued, completed}, `status_reason`.
  **State machine:** active→{held, discontinued, completed}, held→{active, discontinued, completed};
  discontinued/completed terminal (legal-only, clinician-driven). **FENCE: no computed dose/suggestion/
  ranking/safety-verdict column** (asserted by a schema fence) — every field is the clinician's entry.
- `medication_order_events` (BelongsToTenant, **APPEND-ONLY** model guards + DB triggers
  `medication_order_events_no_update`/`_no_delete` — the `stay_events` recipe) — one immutable row per
  transition (placed / held / resumed / discontinued / completed) + reason + performed_by + occurred_at.
- `Models\MedicationOrder` (status machine + ROUTES + `canTransition` + soft `stay_id`, no Stay relation) +
  `MedicationOrderEvent` (append-only) + `MedicationOrderException`.
- `Services\MedicationOrderService` — `prescribe` (gate **`medication.prescribe`**; tenant+patient
  fail-closed; **CALLS `MedicationSafetyProvider::checkOrder` at placement** [the seam call-site]; creates
  the order + a `placed` event in one transaction) + `transition` (legal-only; writes an event) +
  `activeForPatient`/`historyForPatient` + `safetyReview` (the display-surface call-site — `checkOrder` over
  the patient's active meds, none() today). **THREADS THE SEAM, adds NO checking:** the result is ADVISORY +
  HUMAN-OWNED — it NEVER blocks the order and NEVER auto-acts; today the null-object returns none(). The
  service computes no dose, suggests no med, ranks nothing.
- `Http\Controllers\MedicationOrderController` (string-id FIX.1) — `index` (GET
  `/pharmacy/patients/{patient}/medications`, `patient.view`, **read-logged**) renders
  `Pharmacy/MedicationOrders.vue` (place form + active + history + an **EMPTY alerts area wired to
  `SafetyResult`**); `store` (POST, `medication.prescribe`), `transition` (POST, `medication.prescribe`).
  Lifecycle events audited via app-layer `MedicationOrderEvent::created` hook (`medication_order.<type>`).
- **RBAC:** `medication.prescribe` (a NEW permission — prescribing is a physician act, held by doctor /
  hospitalist / org_admin, NOT nurse — distinct from lab/imaging `order.manage`). Read = `patient.view`.
- **THE CRUX — no homemade checking:** CareOS never manufactures a `SafetyAlert` (proven by a grep test:
  no `new SafetyAlert(` anywhere in `Modules\Pharmacy\src`); the seam is the ONLY safety path and it goes to
  the null-object. Drug-interaction/dose/contraindication/duplicate judgment stays a certified-partner /
  permanent-non-goal surface.

## eMAR (PHARMACY.G3)

The electronic medication administration record — recording that a nurse administered / held / refused a
dose against a G2 order. A NET-NEW append-only domain, record-not-judge, threading the safety seam at the
ADMINISTRATION point.

- `medication_administrations` (BelongsToTenant, **LogsReads**, **APPEND-ONLY** — model guards + DB triggers
  `medication_administrations_no_update`/`_no_delete`, the `medication_order_events` recipe) — one immutable
  row per administration: `patient_id`, `medication_order_id` (the G2 order), `administered_by` (User),
  `outcome` ∈ {given, held, refused} (the nurse's FACT), `administered_at`, `scheduled_at` (nullable — the
  due/scheduled time; null for PRN), `dose_amount`/`dose_unit` (the dose GIVEN — defaults from the order for
  'given', null for held/refused), `reason` (nullable — for held/refused), `stay_id` (soft nullable ref).
  Explicit short index names (auto-names exceed MySQL's 64-char limit). **FENCE: no computed-safety/verdict/
  severity/score/late/missed/graded-flag column** (schema fence); `scheduled_at` vs `administered_at` is a
  RAW time comparison the UI renders — late/missed is an elapsed FACT, never a system grade.
- `Models\MedicationAdministration` (outcomes + append-only guards, `@property-read MedicationOrder $order`)
  + `MedicationAdministrationException`.
- `Services\MedicationAdministrationService` — `record` (gate **`note.write`**; tenant fail-closed; **CALLS
  `MedicationSafetyProvider::checkAdministration`** at the administration point [the seam call-site]; appends
  the immutable row; defaults the given dose from the order) + `dueForPatient` (the FACTUAL worklist — the
  patient's ACTIVE orders, not a computed priority) + `forPatient` (the MAR) + `safetyReview` (the
  display-surface call-site — `checkAdministration` over active meds, none() today). **THREADS THE SEAM,
  adds NO checking:** advisory + human-owned, NEVER blocks, NEVER auto-acts; the null-object returns none().
- `Http\Controllers\MedicationAdministrationController` (string-id FIX.1) — `index` (GET
  `/pharmacy/patients/{patient}/emar`, `patient.view`, **read-logged**) renders `Pharmacy/Emar.vue` (the due
  worklist + the MAR + an **EMPTY alerts area wired to `SafetyResult`**); `record` (POST
  `/pharmacy/medication-orders/{order}/administer`, `note.write`). Audited via app-layer
  `MedicationAdministration::created` hook (`medication.administered`).
- **RBAC:** administration **reuses `note.write`** (the nursing clinical-write permission the ward nurse
  holds — the G5 handover precedent; NO new permission). Read = `patient.view`.
- **THE SEAM at the administration layer:** `checkAdministration` was already defined in the G1 interface +
  null-object; G3 wires the CALL-SITE. No homemade checking (the module-wide `new SafetyAlert(` grep stays
  clean); the administration is never auto-blocked on safety grounds (the nurse owns the decision).

## Dispensing + inventory (PHARMACY.G4)

Pharmacy stock + the dispensing workflow — a net-new OPERATIONAL domain (no fence subtlety, but safe +
concurrency-safe stock math). Dispensing decrements stock against a G2 order.

- `medication_stocks` (BelongsToTenant) — the MUTABLE current on-hand per formulary item (the Bed-status
  analogue): `formulary_item_id`, `location` (nullable), `on_hand` (int), `unit`, `reorder_threshold`
  (nullable int — a plain configured NUMBER). `unique(tenant, formulary_item_id)`. `on_hand` is mutated
  ONLY under a FOR UPDATE row lock (`MedicationStock::lockOnHand` — the `BedService::lockBedStatus` idiom).
  `isBelowThreshold()` is a FACTUAL `on_hand <= reorder_threshold` comparison, never a graded alert.
- `stock_movements` (BelongsToTenant, **APPEND-ONLY** — model guards + DB triggers) — the immutable ledger:
  `type` ∈ {received, dispensed, adjusted}, signed `quantity_change`, `resulting_on_hand`, `reason`,
  `dispense_id` (soft link), `performed_by`. The current `on_hand` stays consistent with the latest
  movement.
- `dispenses` (BelongsToTenant, **LogsReads**, **APPEND-ONLY**) — one immutable dispensing event against a
  G2 order: `patient_id`, `medication_order_id`, `formulary_item_id`, `quantity`, `dispensed_by`,
  `dispensed_at`, soft `stay_id`.
- `Services\StockService` — `receive` (+qty, creates the stock row on first receipt) + `adjust` (stock-take
  to an absolute count, reason required) + `forTenant`/`recentMovements`. Each mutation is UNDER THE LOCK +
  writes a movement. Gate `dispense.manage`.
- `Services\DispensingService` — `dispense(actor, order, qty)`: gate `dispense.manage`; **factual state
  check** (order must be active); resolve stock; **DB::transaction { lock on_hand FOR UPDATE → assert
  on_hand ≥ qty (else `insufficientStock`) → create the Dispense → decrement on_hand → append the
  'dispensed' movement }** — ATOMIC + concurrency-safe (the `BedService::claim` idiom). NO safety judgment
  (medication safety is the orders/administration seam, not dispensing). `historyForPatient`/`onHandForItem`.
- `Console\AttemptDispenseCommand` (`pharmacy:attempt-dispense`) — the parallel-hammer command (mirrors
  `hospital:attempt-bed-claim`); registered in the provider.
- `Http\Controllers\InventoryController` (GET `/pharmacy/inventory`, `dispense.manage`) → `Pharmacy/Inventory.vue`
  (stock + below-threshold factual + receive + adjust + movement log) + `DispensingController` (GET
  `/pharmacy/patients/{patient}/dispensing`, `patient.view`, **read-logged**) → `Pharmacy/Dispensing.vue`
  (active orders + on-hand + dispense + history). Audited via app-layer `Dispense::created`
  (`medication.dispensed`, patient-scoped) + `StockMovement::created` (`stock.<type>`, tenant-level) hooks.
- **RBAC:** dispensing/inventory **reuse `dispense.manage`** (the G1 pharmacist/pharmacy_technician
  permission — NO new permission); the per-patient dispensing view reads on `patient.view`.
- **THE CONCURRENCY PROOF:** `DispenseParallelHammerTest` — 8 OS processes race to dispense the last unit;
  exactly ONE wins, on_hand never goes negative (the `BedClaimParallelHammer` sibling). Dispense + movement
  are atomic (a forced failure rolls back both). **No safety checking in dispensing** (the seam is
  orders/administration); no judgment/graded column (schema fence).

## Pharmacy billing (PHARMACY.G5)

A dispensed medication accrues a charge through the **EXISTING** billing engine, reconciling-to-the-unit —
the final buildable pharmacy gate (map §2.1/§5). **STRICTLY ORCHESTRATION: zero new billing/pricing/VAT/
line-total math** (the bed-day HOSPITAL.G6 [[D-118]] pattern, mirrored — the engine prices everything).

- **Medication pricing — a formulary item maps to a tenant-authored `TariffItem`.** A soft nullable
  `tariff_item_id` on `formulary_items` (no FK — Pharmacy stays decoupled, the `stay_id` shape;
  `FormularyItem::tariffItem()` belongsTo + `isPriced()`). Pricing lives in the EXISTING billing/tariff
  store, **NOT duplicated in pharmacy**; **NO licensed drug pricing bundled** (the tenant authors placeholder
  rates it edits — the `BedBillingService::seedStarter` discipline).
- `Services\PharmacyBillingService` (orchestration; `CATALOG_KEY='pharmacy'`) —
  - `catalog()` get-or-creates the tenant's `pharmacy` `TariffCatalog` (valid_from 2020, open-ended, active).
  - `priceItem(actor, item, priceMinor, ?unit)` (gate `billing.manage`, tenant fail-closed, price>0 else
    `PharmacyBillingException::nonPositivePrice`) `updateOrCreate`s a `TariffItem` keyed
    (catalog, `item->code`) with the tenant's OWN code, integer minor units, **zero VAT**,
    `requires_service_documentation=false`, and links it onto the formulary item. Re-pricing updates the
    tariff item; PAST charges are unaffected (they snapshotted the fee).
  - `chargeForDispense(actor, Dispense): ?Charge` — resolves the formulary item; **UNPRICED → null** (no
    charge, no gate, so G4 tests stay green); IDEMPOTENT via a `dispense_charges` lookup (return the existing
    Charge); else gate `billing.manage` + tenant fail-closed → `ChargeCaptureService::captureManual($patient,
    $branch, $dispensed_at, $item->code, $quantity, $actor)` (**the engine resolves the tariff, SNAPSHOTS the
    fee, computes `line_total`**) → write the `dispense_charges` link. NO money math here. `$branch` =
    `Branch::firstOrFail()` (charges are branch-attributed).
  - `invoicePatient(actor, patient, from, to): Invoice` (gate `billing.manage`, tenant fail-closed) — the
    EXISTING `validateForPatientPeriod` → gather validated/uninvoiced charges in the window → `nothingToInvoice`
    if empty → `createDraftFromCharges(SELF_PAY, now, now+30d)` → `issue`. NO new invoice logic.
- `dispense_charges` (BelongsToTenant) — the dispense→charge link: `dispense_id` (FK cascade), `charge_id`
  (**soft** ULID, no FK — Pharmacy stays decoupled from Billing), `unique(tenant, dispense_id)` makes
  charge-on-dispense IDEMPOTENT. **NO money stored** — the Charge is the money (the `bed_day_accruals` /
  `DentalProcedureCharge` precedent). `Models\DispenseCharge`.
- **Charge-on-dispense is wired BEST-EFFORT + DECOUPLED** in `DispensingController::dispense` — AFTER the
  dispense commits, `try { $billing->chargeForDispense(...) } catch (Throwable) {}`, so a billing hiccup NEVER
  blocks the concurrency-critical G4 dispense (an uncharged dispense is reconcilable later).
- `Http\Controllers\PricingController` (string-id FIX.1) — `index` (GET `/pharmacy/pricing`, `billing.manage`)
  renders `Pharmacy/Pricing.vue` (each formulary item + its `price_minor`/`unit`, a set-price surface — "price
  a med like any tariff item"); `set` (POST `/pharmacy/pricing/{item}`, `billing.manage`, validates
  `price_minor` integer≥1) → `priceItem`.
- **RBAC:** the `pharmacist` role gains the EXISTING `billing.manage` (the pharmacist bills dispensed meds
  through the engine) — RbacProvisioner const + a `provisionTenant`-all backfill migration; **NO new
  permission** (billing.manage already exists), so `RbacTest`'s permission-count stays green.
- **RECONCILES-TO-THE-UNIT (THE key proof):** a patient invoice INCLUDING a dispensed-med charge assembles
  into one gapless invoice and `ReconciliationEngine::check(period)` passes with **I4 `delta_minor === 0`**
  (for INPATIENT, pharmacy charges join the stay's discharge invoice via the existing G6 `invoiceStay`).
- **ELECTRIC FENCE / no-money-math (adversarial grep, tested):** a med price is a RATE (financial), never a
  safety/appropriateness/substitution verdict — no cost-based substitution suggestion (that is the
  certified-partner seam, not billing); `Modules\Pharmacy` contains none of `line_total_minor`/
  `vat_total_minor`/`subtotal_minor`/`vatMinor`/`intdiv(` (the only money it names is the authored per-unit
  RATE); no cost/clinical-judgment column on `formulary_items`.

## Status

**PHARMACY.G1–G5 complete — PHARMACY CORE COMPLETE.** G1 = the foundation (module + tenant-authored formulary + the medication-safety
SEAM [null-object, built empty] + pharmacy RBAC). G2 = medication orders (a NET-NEW prescribing entity —
dose/route/frequency/PRN, mutable status machine + append-only event log, soft stay ref — threading the seam
at placement). **G3 = the eMAR — a NET-NEW append-only administration record (given/held/refused against a
G2 order, the nurse's FACT; the due worklist is the factual set of active orders) that THREADS the safety
seam at the ADMINISTRATION point (`checkAdministration`, advisory + non-blocking, null-object today; NO
homemade checking); reuses `note.write`. **G4 = dispensing + inventory — a NET-NEW OPERATIONAL domain
(append-only `stock_movements` + `dispenses`; on-hand consistent with the ledger) where dispensing against a
G2 order decrements stock SAFELY + CONCURRENCY-SAFELY (FOR UPDATE lock, no oversell, no negative — the
BedClaim parallel-hammer proof); reuses `dispense.manage`; no safety judgment in dispensing.** **G5 = pharmacy
billing — a dispensed med accrues a charge through the EXISTING engine (a formulary item maps to a
tenant-authored `TariffItem`; `ChargeCaptureService::captureManual` snapshots the fee + computes the line
total; the invoice reconciles-to-the-unit, I4 `delta_minor === 0`) — STRICTLY ORCHESTRATION, zero new money
math; the pharmacist gains the existing `billing.manage`; charge-on-dispense is best-effort + decoupled.**
Verified: npm build green; composer check FULLY green (Pint · PHPStan L5 `[OK]` · **Pest 795 passed /
2 skipped / 7056 assertions**, 0 failed); smoke green (pricing route added). **The ONE deliberate gap
is the medication-safety JUDGMENT** — the `NullMedicationSafetyProvider` seam invoked-but-no-op throughout (G2
placement + G3 administration), the certified-partner binding point; a homemade checker is a PERMANENT
non-goal. Phases 3–7 (lab / radiology / OR / ED) remain. See [[D-120]], [[D-121]], [[D-122]], [[D-123]],
[[D-124]], `docs/HOSPITAL-PHASE2-PHARMACY-MAP.md`.

## Open items / next gates (per docs/HOSPITAL-PHASE2-PHARMACY-MAP.md)

- **G1** *(done — D-120)* — module + tenant-authored formulary + the medication-safety seam (null-object) +
  pharmacy RBAC. **Next: G2.**
- **G2** *(done — D-121)* — medication orders: a NET-NEW `MedicationOrder` (dose/route/frequency/PRN, mutable
  status machine + append-only `medication_order_events`, soft `stay_id`) reusing the Stay/StayEvent shape +
  `medication.prescribe`; THREADS the safety SEAM (`checkOrder` at placement, advisory + non-blocking,
  null-object today) — never homemade. **Next: G3.** *(Note: the exact-match `AllergyGuard` hard-stop wiring
  is deferred to when the med-order write path integrates it — G2 established the safety-seam call-site.)*
- **G3** *(done — D-122)* — the eMAR: a NET-NEW append-only `medication_administrations` (given/held/refused
  against a G2 order; the due worklist = the factual set of active orders, no computed priority) reusing the
  append-only recipe + `note.write`; THREADS the seam at the administration point (`checkAdministration`,
  advisory + non-blocking, null-object today) — never homemade. **Next: G4.** *(Scope note: the due list is
  the active-orders worklist [factual] — a full frequency→times-of-day materialization à la
  `VisitPlan→PlannedVisit` was kept out as over-scope; scheduled_at is a per-administration recorded time.)*
- **G4** *(done — D-123)* — dispensing + inventory: net-new `medication_stocks` (on-hand, mutated under a
  FOR UPDATE lock) + append-only `stock_movements` + `dispenses`; dispensing against a G2 order decrements
  stock SAFELY + CONCURRENCY-SAFELY (`BedService::claim` idiom; the parallel-hammer proof — 1 of 8 wins the
  last unit); reuses `dispense.manage`; no safety judgment in dispensing. **Next: G5.**
- **G5** *(done — D-124)* — pharmacy billing: a soft `FormularyItem.tariff_item_id` → a tenant-authored
  `TariffItem` (`priceItem`); a dispense accrues a Charge via the EXISTING `captureManual` (engine snapshots +
  computes; idempotent `dispense_charges`; best-effort + decoupled in the controller); `invoicePatient` runs
  the existing validate→draft→issue flow and **reconciles-to-the-unit** (I4 `delta_minor === 0`); the
  pharmacist gains the existing `billing.manage`; STRICTLY ORCHESTRATION, no new money math. **PHARMACY CORE
  COMPLETE.**
- **PHARMACY CORE COMPLETE (G1–G5).** Next is **Phase 3+** of the phased hospital build (lab / radiology / OR
  / ED — `docs/HOSPITAL-PHASE2-PHARMACY-MAP.md` and the phase map), NOT a further pharmacy gate.
- **The safety seam stays EMPTY** through every gate — invoked at G2 (order) + G3 (administration), no-op at
  each; the certified-partner engine + a licensed drug DB are the permanent partner surfaces (never homemade).
  A homemade medication-safety checker is a **permanent non-goal** (medical-device territory; contradicts the
  clinical-safety eval).

### QA Phase 5 — the dispensing screen is silent about allergies (audit only, `<pending>`)

**THE SAFETY SEAM IS HONEST, AND IT IS ON THE WRONG SCREEN.** `AllergyRecordPanel`
(`resources/js/pages/Clinical/Chart.vue:232`) says, verbatim: *"No automated medication-safety
checking is configured… drug-allergy interaction, cross-reactivity and contraindication checking is a
certified-partner function and is not performed here."* No cleared state, no green tick, no grading —
**the fence holds**. But **`/pharmacy/patients/{p}/dispensing` shows neither the allergy nor that
statement**: `DispensingController::index()` passes `patient` as **id + name only**, and no
`resources/js/pages/Pharmacy/*` file mentions allergies at all. Driven with a **severe Penicillin
(anaphylaxis)** allergy against an active **Amoxicillin** order: the screen showed nothing (`P5-C1`).

**THE TECHNICIAN'S DISPENSE IS NEVER BILLED (`P5-C2`) — know this before touching
`PharmacyBillingService`.** `chargeForDispense()` opens with `Gate::authorize('billing.manage')`, which
`pharmacy_technician` deliberately lacks, and `DispensingController.php:81-86` calls it inside
`catch (Throwable) {}`. So an authorization failure is swallowed exactly like a transient hiccup:
stock decrements, the dispense commits, **no charge exists**, and the screen is identical to the
pharmacist's. Dispensing is that role's primary job. **Nothing anywhere lists uncharged dispenses**,
so the code comment's "reconcilable later" is unbacked.

**A PHARMACIST CANNOT BILL WHAT THEY DISPENSE (`P5-H1`, resolving Phase 3's `P3-H4`).** Charges are
created **`draft`** (`ChargeCaptureService.php:143`); `/billing/new-invoice` filters `VALIDATED` at
`InvoiceDraftController` lines 43/76/124 and never validates; and
`PharmacyBillingService::invoicePatient()` — which *does* validate-then-issue — has **no route and no
production caller**. The screen reads "No validated, un-invoiced charges to bill." for ever.

**What the dispense path gets RIGHT — do not regress it:** the dispense row + stock decrement +
append-only `StockMovement` are **one transaction** with `lockForUpdate` and an in-lock stock re-check
(`DispensingService.php:52`); `Dispense` and `StockMovement` throw on `updating` **and** `deleting`;
four driven refusals left the ledger completely unchanged. **But there is NO reversal path at all**
(`P5-H3`) — a mis-dispense is permanent; only stock can be corrected, via inventory adjust.

**The model has NO batch and NO expiry** (`medication_stocks`: `location, on_hand, unit,
reorder_threshold`), so "from which batch" cannot be asked.
