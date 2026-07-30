# Module: Lab (`Modules\Lab`)

## Purpose

The Laboratory / LIS vertical — **Phase 3** of the phased hospital build (Phases 1 inpatient/ADT, 2 pharmacy,
5 surgery, 6 ED — all complete). Planned as ~6 buildable gates + 1 seam-stubbed (`docs/HOSPITAL-PHASE3-LAB-MAP.md`):
the module + test catalog + the formalized `LabConnectivity` seam + lab RBAC (G1) → lab order entry (reuse
Clinical `Order`) (G2) → specimen tracking (net-new) (G3) → manual result entry + reference-range display —
the fence gate (G4) → result routing + worklist (G5) → lab billing (G6) → **[SEAM-STUBBED] the HL7/analyzer
feed (G7 — partner-gated, NOT built)**. **LAB.G1 (the FOUNDATION) is built.** Peer module, mirroring
ED/Surgery/Pharmacy.

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

## Gate log

- **LAB.G1**: module + tenant-authored test catalog (`LabTest` overlay on `OrderableItem`) + the formalized
  `LabConnectivity` seam (docblocks; consumed from Clinical) + lab RBAC. 5 feature tests
  (`tests/Feature/Lab/LabCatalogTest.php`) + arch boundary + reprovision migration + FIX.5 smoke (`/lab/catalog`).
  See D-136.

## Not built yet (later gates)

G2 order entry (reuse `Order`, +STAT priority) · G3 specimen tracking (net-new `Specimen`) · G4 manual result
+ reference-range display (reuse `OrderResult`; the fence) · G5 routing + worklist · G6 billing (the engine) ·
**G7 [SEAM-STUBBED] the HL7/FHIR/analyzer feed — partner-gated, NOT built** (a certified partner fills the
`LabConnectivity` seam). Radiology (Phase 4) follows — also partner-gated (PACS/DICOM). See
`docs/HOSPITAL-PHASE3-LAB-MAP.md`.
