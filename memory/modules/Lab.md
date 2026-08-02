# Module: Lab (`Modules\Lab`)

## Purpose

The Laboratory / LIS vertical — **Phase 3** of the phased hospital build (Phases 1 inpatient/ADT, 2 pharmacy,
5 surgery, 6 ED — all complete). Planned as ~6 buildable gates + 1 seam-stubbed (`docs/HOSPITAL-PHASE3-LAB-MAP.md`):
the module + test catalog + the formalized `LabConnectivity` seam + lab RBAC (G1) → lab order entry (reuse
Clinical `Order`) (G2) → specimen tracking (net-new) (G3) → manual result entry + reference-range display —
the fence gate (G4) → result routing + worklist (G5) → lab billing (G6) → **[SEAM-STUBBED] the HL7/analyzer
feed (G7 — partner-gated, NOT built)**. **The buildable LAB core (G1–G6) is COMPLETE** (see "LAB core COMPLETE"
below); only the partner-gated G7 HL7 feed remains a seam. Peer module, mirroring ED/Surgery/Pharmacy.

## THE BIG REUSE (the map's core finding — do NOT duplicate)

Lab is **~85% reuse**. Clinical's `Order` + `OrderResult` **already model lab orders + manual results**: the
`Order` lifecycle is *literally* lab-shaped (`ordered → collected → in_progress → resulted → reviewed`);
`OrderResult` is **append-only + raw with NO interpretation column** (`no range/flag/abnormal/score` — the
fence already built) and its `source` field already splits **`manual` / `imported`** (the seam's two paths);
`OrderService::place` already calls `$this->lab->transmit($order)`. **A lab order IS a Clinical `Order`; a lab
result IS a Clinical `OrderResult`.** Lab REUSES them (G2/G4) — it mints NO parallel order/result entity. The
genuine net-new lab domain is **specimen tracking** (G3) + **reference ranges as displayed reference data**.

## The test catalog (G1 — the overlay)

`lab_tests` (BelongsToTenant) is a thin overlay on the EXISTING Clinical `OrderableItem` (the
`DentalProcedure`/`SurgicalItem` precedent — `unique(orderable_item_id)`): a lab test IS a tenant-authored
`OrderableItem` (`category='lab'`; code/name/`specimen_or_modality`/active live there) + the overlay adding
ONLY the lab DISPLAY reference data — `unit` + `reference_range`. `LabCatalogService::authorTest` (gate
`lab.catalog`, one `DB::transaction`: `OrderableItem::updateOrCreate` [category=lab] + `LabTest::updateOrCreate`)
/ `deactivate` (soft, `orderable.active=false`) / `seedStarter` (a SMALL GENERIC editable template — LAB-FBC/
LAB-HB/LAB-K/LAB-CREAT/LAB-GLU-F/LAB-URINALYSIS with illustrative ranges; **NO licensed LOINC/test set
bundled**) / `catalog`. `LabCatalogController` (`/lab/catalog`) + `Lab/Catalog.vue`; audited (app-layer
`LabTest.created` → `lab.test_authored`, tenant-level).

## THE `LabConnectivity` SEAM (G1 — formalized, NOT filled)

The seam **already exists in Clinical** and is **already wired** (`LabConnectivity` interface `transmit`/
`ingestResult` → `ManualLabConnectivity`, bound in `ClinicalServiceProvider`, `transmit` called in
`OrderService::place`). LAB.G1 **formalizes** it (docblocks only — no behavior change): documents the two
paths — **MANUAL** (a human enters an `OrderResult` `source=manual`, built) and **IMPORTED** (a future
CERTIFIED HL7/analyzer partner implements `ingestResult` → appends an `OrderResult` `source=imported`, **never
interpreted** — the P0P.G11 discipline). **Lab CONSUMES the seam (it stays in Clinical) — it does NOT bind a
new one and does NOT build a homemade HL7 client** (that is the partner-gated LAB.G7, SEAM-STUBBED). The
bound impl stays the `Manual*` no-op; a certified partner swaps it later. **THE DEFINING LIS VALUE (the
analyzer/HL7 feed) IS PARTNER-GATED** — without it, the vertical is a manual record-keeping shell (say so
honestly).

## THE FENCE (the reference-range line — the sharpest)

`reference_range` is **RECORDED REFERENCE DATA** the clinician reads beside a result (a free-text/recorded
range) — the system **NEVER computes a high/low/abnormal/critical flag** or grades the value against it (the
vitals-bands / computed-acuity line). `OrderResult` already forbids the interpretation column; `lab_tests`
carries no abnormal/critical/flag/grade/score column; the grep over `Modules\Lab\src` finds no
grade/flag-a-result logic. A computed abnormal/critical verdict = certified-partner / non-goal (enforced in
G4). Neither the manual nor the imported result path ever interprets a value.

## RBAC (additive)

New perms `lab.catalog` (author the catalog + ranges) + `lab.result` (enter results + specimens, used G3/G4).
Ordering a lab test reuses the EXISTING `order.manage` (the clinician orders). New roles: `lab_tech`
(order.manage + lab.result), `pathologist` (the lab lead — + lab.catalog + note.write/sign), `phlebotomist`
(patient.view + lab.result). `org_admin` gains both perms. Reprovision migration `add_lab_permissions` (the
`add_ed_permissions` precedent); `RbacNegativeSweepTest` untouched.

## Boundaries / posture

- **Arch:** `Modules\Lab` may use Platform + care modules (Clinical [heavily — Order/OrderResult/OrderableItem/
  LabConnectivity]/Patients/Billing) + Audit SERVICES, but **not** `Audit\Models`, `AiCore`, `Comms`, or the
  peer verticals (Nursing/Dental/Hospital/Pharmacy/Surgery/ED). `arch('Lab …')` in `ModuleBoundariesTest`.
- **Audit:** the `LabTest.created` hook lives in `app/Providers/AppServiceProvider.php` (app-layer), so Lab
  stays free of Audit — the ED/Surgery/Pharmacy posture. Tenant-level (a catalog item, not patient-scoped).
- **No money math** in Lab (billing is G6, via the existing engine).

## Lab order entry (G2 — reuse the Clinical Order)

A lab order IS a Clinical `Order` (~85% reuse). `LabOrderService::place` REUSES the EXISTING
`OrderService::place` (authorizes `order.manage`, runs the `ordered→collected→in_progress→resulted→reviewed`
lifecycle, calls the seam's `transmit()` — manual no-op) with the `LabTest`'s `OrderableItem`, then appends the
thin **`lab_orders`** overlay (the only net-new): `specimen_type` (defaults from the catalog) + `priority`
(routine/urgent/**STAT**), in one `DB::transaction`; ties to the patient + an optional `Encounter` (the
existing linkage). `lab_orders` (BelongsToTenant, LogsReads, **APPEND-ONLY** — model guards + DB triggers,
`unique(order_id)`). **FENCE:** the priority is a RECORDED flag the clinician sets — no computed priority/rank/
escalation; **STAT is overlay-only, Clinical's `Order` UNTOUCHED** (its priority stays default routine; `orders`
schema unchanged). Placing reuses `order.manage`; `LabOrderController` (`/lab/patients/{patient}/orders`) +
`Lab/Orders.vue`; audit `lab.order_placed` (patient-scoped, app-layer).

## Gate log

- **LAB.G1**: module + tenant-authored test catalog (`LabTest` overlay on `OrderableItem`) + the formalized
  `LabConnectivity` seam (docblocks; consumed from Clinical) + lab RBAC. 5 feature tests
  (`tests/Feature/Lab/LabCatalogTest.php`) + arch boundary + reprovision migration + FIX.5 smoke (`/lab/catalog`).
  See D-136.
- **LAB.G2**: lab order entry — REUSES the Clinical `Order` (`OrderService::place`) + a thin `lab_orders`
  overlay (specimen + priority incl STAT, append-only); priority is a recorded flag (not computed); Clinical
  untouched. `LabOrderService` + `LabOrderController` (`/lab/patients/{patient}/orders`) + `Lab/Orders.vue` +
  `lab.orders.*` i18n; FIX.5 smoke extended. 6 feature tests (`tests/Feature/Lab/LabOrderTest.php`). No charge.
  See D-137.
- **LAB.G3**: specimen tracking — the one genuine net-new lab entity. `specimens` (accession unique-per-tenant
  via the `MrnGenerator` recipe; legal-only `collected → in_lab → resulted` + rejected) + append-only
  `specimen_events`; `SpecimenService` (collect + transition, gate `lab.result`); collection does NOT advance
  the Clinical Order (the phlebotomist has only lab.result — Order untouched). `SpecimenController`
  (`/lab/orders/{labOrder}/specimens`) + `Lab/Specimens.vue` + `lab.specimens.*` i18n; FIX.5 smoke extended.
  FENCE: state + accession are facts, no computed priority/routing. 7 feature tests
  (`tests/Feature/Lab/SpecimenTest.php`). No charge. See D-138.
- **LAB.G4**: manual result entry + reference-range display — **THE FENCE GATE**. A lab result IS a Clinical
  `OrderResult` (REUSED via `OrderService::recordResult` — append-only, raw, `source=manual`; advances the reused
  Order → resulted). Thin `lab_results` overlay (append-only, `unique(order_result_id)`) links the reused result
  to the LAB.G3 specimen that produced it (carries NO value); `LabResultService::record` (gate `lab.result` +
  reuses `order.manage`) also walks the specimen → resulted via the G3 legal machine. `LabResultController`
  (`/lab/orders/{labOrder}/results` view · `/lab/specimens/{specimen}/results` store) + `Lab/Results.vue` +
  `lab.results.*` i18n; app-layer `lab.result_recorded`; FIX.5 smoke extended (GET 200 + store 403). **THE
  FENCE:** the reference range (`unit`+`reference_range` from the LAB.G1 catalog) is DISPLAYED beside the raw
  value — the system computes NO abnormal/high/low/critical flag, no delta, no interpretation (proven: an
  out-of-range value carries no flag; no computed-judgment column on `order_results`/`lab_results`; no
  grade/flag logic in `Modules\Lab\src`; the payload key-sweep is clean). 7 feature tests
  (`tests/Feature/Lab/LabResultTest.php`). No charge. See D-139.
- **LAB.G5**: result routing + the "results to review" worklist — closes the order → result → review loop by
  SURFACING the EXISTING resulted → reviewed step for lab orders (reuse `OrderService`'s review, NOT
  reinvented). `LabResultService::reviewWorklist` (gate `order.manage`) returns the ORDERING clinician's own
  resulted lab orders (`Order.status=resulted` + `ordered_by`=actor), ordered by resulted-time (a FACT). The
  review action REUSES the existing `clinical.orders.review` endpoint (`markReviewed`: resulted → reviewed) —
  NO new review endpoint/model/migration. `LabReviewController` (invokable, `/lab/results/review`, the
  `OrdersReviewController` analogue lab-scoped) + `Lab/Review.vue` + `lab.review.*` i18n; FIX.5 smoke extended
  (GET 200 for doctor, 403 for reception). **FENCE:** facts + the recorded STAT flag (staff MAY sort by flag/
  time — a fact); NO computed priority/urgency ranking, NO critical-result flag, NO review-first judgment
  (proven: a later-resulted routine order outranks an earlier-resulted STAT one — ordered by time not STAT;
  payload key-sweep clean; no rank/priority-score/flag-critical logic in `Modules\Lab\src`); result stays raw
  value + displayed range (G4 carried). 6 feature tests (`tests/Feature/Lab/LabReviewTest.php`). No charge.
  See D-140.
- **LAB.G6**: lab billing — a lab order accrues its test fee through the EXISTING engine, reconciling-to-the-
  unit. **The FINAL buildable Phase-3 gate — the LAB core is COMPLETE.** STRICTLY ORCHESTRATION (the ED.G6 /
  surgery-G5 / bed-day pattern) — NO new money math. A lab test is a tenant-authored `TariffItem` (lab catalog,
  keyed by the LAB.G1 code, no licensed pricing); `LabBillingService` (`priceTest`/`chargeOrder`/`invoiceOrder`/
  `catalogTariffs`, gate `billing.manage`) captures ONE charge per lab order via `ChargeCaptureService::
  captureManual` (engine snapshots the fee + computes the line total), idempotent via `lab_order_charges`
  (link, no money); outpatient issues via `validateForPatientPeriod`→`createDraftFromCharges`→`issue`,
  inpatient/ED lab charges join the stay/episode invoice via the existing `invoiceStay` (no lab code).
  `LabBillingController` (`/lab/orders/{labOrder}/billing` + price-test/charge/invoice) + `Lab/Billing.vue` +
  `lab.billing.*` i18n; FIX.5 smoke extended (GET 200 + charge 403). No audit hook (Charge/Invoice audited by
  Billing). **RECONCILES-TO-THE-UNIT proven both ways** (an outpatient invoice δ=0; a composite inpatient
  episode — lab charges + bed-days on ONE stay invoice — δ=0). **FENCE:** the fee is a tariff, NOT result-driven
  (two opposite result values → same fee); the adversarial grep over `Modules\Lab\src` finds zero money math;
  `lab_order_charges` carries no money/result/severity column. 7 feature tests
  (`tests/Feature/Lab/LabBillingTest.php`). See D-141.

## Specimen tracking (G3 — the net-new entity)

`specimens` (BelongsToTenant, LogsReads) — collected against a LAB.G2 `LabOrder`, `accession_number`
(unique-per-tenant, `MrnGenerator` recipe under a tenant-row lock), `specimen_type` (from the order overlay),
collected_by/at, `status` (out of `$fillable`). Legal-only `collected → in_lab → resulted` (+ rejected, reason
required). `specimen_events` APPEND-ONLY (model guards + DB triggers). `SpecimenService::collect`/`transition`,
gate `lab.result`. **The Clinical Order is REUSED + UNTOUCHED** — collection records the specimen, it does NOT
advance the Order (the phlebotomist has only lab.result; the Order's lifecycle is Clinical's, advanced by the
result step G4). FENCE: state + accession are operational facts — no computed priority/urgency/routing.

## Manual result entry (G4 — the fence gate; reuse OrderResult)

A lab result IS a Clinical `OrderResult` — `LabResultService::record` REUSES `OrderService::recordResult`
(append-only, raw, `source=manual`, advancing the reused `Order` → resulted), appends the thin `lab_results`
overlay (append-only, `unique(order_result_id)`; the ONLY net-new — it links the reused result to the LAB.G3
specimen that produced it and carries NO value), and advances the specimen → resulted through the G3 legal
machine (collected → in_lab → resulted, each hop legal). Gated `lab.result` (the lab-domain permission) + the
reused `recordResult` re-checks `order.manage` (the `lab_tech`/`pathologist`/`org_admin` holds both; a
`phlebotomist` with only `lab.result` is refused at the reused Clinical path — proven). **THE FENCE (the
sharpest):** the reference range (`unit`+`reference_range`) is DISPLAYED reference data read from the LAB.G1
catalog beside the raw value — NEVER a threshold graded against. NO abnormal/high/low/critical flag, delta, or
interpretation is computed anywhere (payload or UI); `order_results` stays raw (no interpretation column) and
`lab_results` adds none. `LabConnectivity` stays the manual no-op (no homemade HL7 — that is LAB.G7).

## Result routing + review worklist (G5 — reuse the OrderService review flow)

Closes the order → result → review loop. `LabResultService::reviewWorklist(actor)` (gate `order.manage` — the
review permission) SURFACES the actor's own resulted lab orders (the reused Clinical `Order` at `resulted`,
`ordered_by`=actor), ordered by resulted-time (the latest `OrderResult.entered_at` — a FACT, newest first).
Reviewing REUSES the EXISTING `clinical.orders.review` endpoint (`OrderController::review` → `markReviewed`:
resulted → reviewed) — the worklist posts `order_id` to it; **NO new review endpoint/model/migration** (the
`OrdersReviewController` idiom, lab-scoped). **FENCE:** the worklist shows facts + the LAB.G2 recorded STAT flag
(client-side sort by flag/time is allowed — a recorded fact, the ED-board precedent) — NO computed priority/
urgency ranking, NO critical-result flag, NO review-first judgment; the result stays raw value + displayed range
(G4). `LabReviewController` (`/lab/results/review`) + `Lab/Review.vue`.

## Lab billing (G6 — the existing engine, reconciles-to-the-unit)

STRICTLY ORCHESTRATION — Lab adds NO pricing/charge/VAT/line-total math (the adversarial grep over
`Modules\Lab\src` is clean). A lab test is a tenant-authored `TariffItem` in the `lab` `TariffCatalog` (keyed by
the LAB.G1 catalog code, integer minor units, no licensed pricing). `LabBillingService::chargeOrder` captures
ONE charge per lab order via the EXISTING `ChargeCaptureService::captureManual` (the engine resolves + SNAPSHOTS
the fee + computes `line_total = qty × price`); idempotent via the `lab_order_charges` link (soft `charge_id`
ref, no money). Outpatient → `invoiceOrder` (the existing validate→draft→issue flow); inpatient/ED → the lab
charges join the stay/episode's discharge invoice via the existing `BedBillingService::invoiceStay`
(gather-by-patient+period — no lab code). Service date = the resulted-time (a fact) else the order date.
**RECONCILES-TO-THE-UNIT** proven both ways (outpatient δ=0; composite inpatient episode — lab + bed-days on one
invoice — δ=0). Gated `billing.manage` (the billing office, NOT the lab bench). **FENCE:** the fee is a tariff,
NOT result-driven (two opposite result values → same fee) — the result is a clinical record, the fee a rate.

## LAB core COMPLETE (Phase 3) + the one deliberate gap

The buildable LAB vertical is COMPLETE: **G1** module + tenant-authored test catalog + the formalized
`LabConnectivity` seam → **G2** order (reuse Clinical `Order`) → **G3** specimen (net-new; accession + legal
state machine) → **G4** manual result + reference-range display (reuse `OrderResult`; the fence) → **G5** review
worklist (reuse the OrderService review flow) → **G6** billing (the existing engine; reconciles-to-the-unit). A
lab now runs end-to-end AS A MANUAL RECORD-KEEPING SHELL: a clinician orders, a phlebotomist collects +
accessions, the bench results (raw value + displayed range, never a computed abnormal flag), the orderer
reviews, the office bills. **THE ONE DELIBERATE GAP — LAB.G7 (NOT built):** the HL7/FHIR/analyzer feed is the
CERTIFIED-PARTNER seam (`LabConnectivity`, manual today; a certified partner appends `OrderResult`
`source=imported`, never interpreted — the P0P.G11 discipline). A homemade HL7 client is out of scope. Radiology
(Phase 4) remains — also partner-gated (PACS/DICOM). See `docs/HOSPITAL-PHASE3-LAB-MAP.md`.
