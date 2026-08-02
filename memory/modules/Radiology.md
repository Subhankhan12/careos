# Module: Radiology (`Modules\Radiology`)

## Purpose

The Radiology / RIS vertical — **Phase 4** of the phased hospital build (the LAST hospital phase; Phases 1
inpatient/ADT, 2 pharmacy, 3 lab/LIS, 5 surgery, 6 ED — all built). Planned as ~5 buildable gates + 1
seam-stubbed (`docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md`): the module + exam catalog + the CREATED
`ImagingConnectivity` (PACS/DICOM) seam + radiology RBAC (G1) → imaging order entry (reuse Clinical `Order`)
(G2) → the study record (net-new `ImagingStudy`) + modality worklist (G3) → the radiologist report (reuse the
sign-and-lock `ClinicalNote`) + report routing (G4) → radiology billing (G5) → **[SEAM-STUBBED] the DICOM/PACS/
modality feed + diagnostic viewer (G6 — partner-gated, NOT built)**. **RAD.G1 (the FOUNDATION) is built.** Peer
module, mirroring Lab/ED/Surgery/Pharmacy.

## THE BIG REUSE (the map's core finding — do NOT duplicate)

Radiology is **~95% reuse** — the most of any vertical. Clinical already provides everything: an imaging order
IS a Clinical `Order` (**`OrderableItem::CATEGORY_IMAGING='imaging'` + the `specimen_or_modality` field already
exist**; the lifecycle is modality-agnostic; `recordResult` already accepts a `document_id`); a report IS a
sign-and-lock `ClinicalNote` (draft→sign→immutable→amend/version); routing IS `markReviewed`/`toReview` (the
LAB.G5 worklist); the modality worklist IS the board idiom; billing IS the engine; **an uploaded exported still
IS a `Document`** via the DENTAL.G8 `DocumentService` recipe (private disk, authenticated stream, no pixel
analysis). Radiology REUSES them (G2/G4/G5) — it mints NO parallel order/report/image entity. The one genuinely
net-new domain is the **study record** (`ImagingStudy`, G3 — the lab-`Specimen` analog: accession + a legal-only
`ordered→acquired→reported` state).

## The exam catalog (G1 — the overlay)

`radiology_exams` (BelongsToTenant) is a thin overlay on the EXISTING Clinical `OrderableItem` (the `LabTest`/
`DentalProcedure`/`SurgicalItem` precedent — `unique(orderable_item_id)`): an imaging exam IS a tenant-authored
`OrderableItem` (`category='imaging'`; code/name + the **modality** in `specimen_or_modality` live there) + the
overlay adding ONLY `body_part` + `contrast`. `RadiologyCatalogService::authorExam` (gate `radiology.catalog`,
one `DB::transaction`: `OrderableItem::updateOrCreate`[category=imaging] + `RadiologyExam::updateOrCreate`) /
`deactivate` (soft, `orderable.active=false`) / `seedStarter` (a SMALL GENERIC editable template — RAD-CXR/
RAD-AXR/RAD-CT-HEAD/RAD-CT-ABDO/RAD-MRI-BRAIN/RAD-US-ABDO with plain names; **NO licensed CPT/RadLex set
bundled**) / `catalog`. `RadiologyCatalogController` (`/radiology/catalog`) + `Radiology/Catalog.vue`; audited
(app-layer `RadiologyExam.created` → `radiology.exam_authored`, tenant-level).

## THE `ImagingConnectivity` SEAM (G1 — CREATED, not formalized)

**Unlike Lab (whose `LabConnectivity` already existed), NO imaging seam existed — RAD.G1 CREATES it.**
`Modules\Radiology\Contracts\ImagingConnectivity` (`transmitOrder(Order)` = the future DICOM Modality-Worklist
push / `ingestStudy(array)` = a future imported study/report from PACS) + the ONLY shipped impl
`NullImagingConnectivity` (transmit no-op; ingest **throws** "not available; recorded manually / images
uploaded"), **bound in `RadiologyServiceProvider::register()`**. It MIRRORS `LabConnectivity`→
`ManualLabConnectivity` / `MedicationSafetyProvider`→`Null*` / `TriageAcuityProvider`→`Null*` (referenced by
name — no peer import). **Radiology OWNS this seam** (Lab consumed Clinical's; Radiology's is its own). The
DICOM/PACS integration (native DICOM storage, MWL push, a diagnostic viewer, PACS retrieval) is the
partner-gated **RAD.G6 — SEAM-STUBBED, NOT built**; a homemade DICOM/PACS stack is a PERMANENT non-goal. The
seam is swappable for a certified partner WITHOUT touching consumers (proven: a partner test double resolves via
`app()->instance`). The imported path is **append-never-interpret** — a partner records a study/report; the
image is the partner's, NEVER interpreted.

## THE FENCE (the AI-imaging line — a HARD medical-device non-goal)

The radiologist AUTHORS the report (their recorded judgment — G4, via the sign-and-lock note). The system
computes **NO** image finding / CAD / abnormality flag / auto-read / confidence score — "AI radiology" is a HARD
medical-device non-goal (the DENTAL.G8 "AI radiology = NON-GOAL" line). The seam never interprets an image.
`radiology_exams` carries no finding/cad/abnormal/ai/confidence/flag column; the grep over `Modules\Radiology\
src` finds no computeFinding/detectAbnormality/cadRead/interpretImage/aiRead logic (and no homemade DICOM/PACS
client — DicomClient/PacsClient/parseDicom/DicomViewer etc.). Enforced from G1; carried through every gate.

## RBAC (additive)

New perms `radiology.catalog` (author the exam menu) + `radiology.study` (record studies + the modality
worklist, used G3). Ordering an imaging exam reuses the EXISTING `order.manage` (the clinician orders); the
report reuses `note.write`/`note.sign` (the sign-and-lock note). New roles: `radiographer` (patient.view +
order.manage + radiology.study — the imaging bench) + `radiologist` (the lead — + radiology.catalog +
note.write/sign + encounter.manage). `org_admin` gains both perms. Reprovision migration
`add_radiology_permissions` (the `add_lab_permissions` precedent); `RbacTest` count is self-referential to the
const, stays green.

## Boundaries / posture

- **Arch:** `Modules\Radiology` may use Platform + care modules (Clinical [heavily — Order/ClinicalNote/Document/
  OrderableItem]/Patients/Billing) + Audit SERVICES, but **not** `Audit\Models`, `AiCore`, `Comms`, or the peer
  verticals (Nursing/Dental/Hospital/Pharmacy/Surgery/ED/Lab). `arch('Radiology …')` in `ModuleBoundariesTest`.
- **Audit:** the `RadiologyExam.created` hook lives in `app/Providers/AppServiceProvider.php` (app-layer), so
  Radiology stays free of Audit — the ED/Surgery/Pharmacy/Lab posture. Tenant-level (a catalog item, not
  patient-scoped).
- **No money math** in Radiology (billing is G5, via the existing engine).

## Gate log

- **RAD.G1**: module + tenant-authored imaging exam catalog (`RadiologyExam` overlay on `OrderableItem`
  `category=imaging`, generic starter, NO licensed set) + the CREATED `ImagingConnectivity` (PACS/DICOM) seam
  (null no-op, bound; no imaging seam existed before) + radiology RBAC (`radiology.catalog`/`radiology.study` +
  radiographer/radiologist). 5 feature tests (`tests/Feature/Radiology/RadiologyCatalogTest.php`) + arch
  boundary + reprovision migration + FIX.5 smoke (`/radiology/catalog`). REUSES Clinical's Order/ClinicalNote/
  Document — does NOT duplicate. FENCE: the seam never interprets; no computed image read anywhere. See D-142.

- **RAD.G2**: imaging order entry — REUSES the Clinical `Order` (`OrderService::place`) + a thin
  `radiology_orders` overlay (modality/body-part + priority incl STAT, append-only); priority is a recorded flag
  (not computed); Clinical untouched (STAT overlay-only). `RadiologyOrderService::place` also calls the
  `ImagingConnectivity` seam's `transmitOrder()` (the future DICOM MWL push — null no-op today).
  `RadiologyOrderController` (`/radiology/patients/{patient}/orders`) + `Radiology/Orders.vue` +
  `radiology.orders.*` i18n; app-layer `radiology.order_placed`; FIX.5 smoke extended (GET 200 + place 403). 6
  feature tests (`tests/Feature/Radiology/RadiologyOrderTest.php`). No charge. See D-143.
- **RAD.G3**: the net-new `ImagingStudy` record + the modality worklist. `imaging_studies` (accession
  unique-per-tenant via the `Specimen` recipe [`IMG-%06d`]; legal-only `ordered → acquired → reported` +
  cancelled) + append-only `imaging_study_events`; `ImagingStudyService` (register/acquire/transition/worklist,
  gate `radiology.study`); acquiring does NOT advance the Clinical Order (the report step G4 does — Order
  untouched). The modality worklist reuses the board/LAB.G5-review idiom (facts, ordered by ordered-time, NO
  computed priority). `RadiologyWorklistController` (`/radiology/worklist`) + `ImagingStudyController`
  (`/radiology/orders/{radiologyOrder}/study` show/acquire; `/radiology/studies/{study}/transition`) +
  `Radiology/Worklist.vue` + `Radiology/Study.vue` + `radiology.worklist.*`/`radiology.study.*` i18n; app-layer
  `radiology.study_accessioned` + `radiology.study.<event_type>`; FIX.5 smoke extended (worklist + study GET
  200; acquire 403). **THE DICOM IMAGE PATH IS SEAM-STUBBED** (the study is metadata; no DICOM storage/viewer/
  PACS built — RAD.G6). **The optional uploaded still (dental `DocumentService`) is DEFERRED** to a later gate
  (explicitly permitted — G3's core is the study record + worklist). FENCE: state + accession + worklist are
  facts; no computed image finding/CAD/priority; ordered-by-time not STAT (proven). 8 feature tests
  (`tests/Feature/Radiology/RadiologyStudyTest.php`). No charge. See D-144.

## The study record + modality worklist (G3 — the net-new domain)

`imaging_studies` (BelongsToTenant, LogsReads) — registered against a RAD.G2 `RadiologyOrder`, `accession_number`
(unique-per-tenant, the `Specimen` recipe under a tenant-row lock, `IMG-%06d`), `modality` (from the order),
`acquired_by`/`acquired_at`, `status` (out of `$fillable`). Legal-only `ordered → acquired → reported` (+
cancelled, reason required). `imaging_study_events` APPEND-ONLY (model guards + DB triggers). `ImagingStudyService`:
`register` (create at ordered + accession + `ordered` event) / `acquire` (register-if-missing → ordered→acquired,
records acquired_by/at) / `transition` (legal-only; `reported` reached by G4) / `worklist` (imaging orders
awaiting acquisition — study null or `ordered` — ordered by ordered-time). Gate `radiology.study`. **The Clinical
Order is REUSED + UNTOUCHED** — acquiring records the study; it does NOT advance the Order (the report step G4
does). **THE IMAGE IS THE PARTNER's (RAD.G6):** the study is metadata; NO DICOM storage/diagnostic viewer/PACS is
built (the optional dental-style uploaded still is DEFERRED). FENCE: state + accession are operational facts — no
computed image finding/CAD/abnormality, no computed priority (the worklist shows the recorded STAT flag as a
fact, ordered by ordered-time not by STAT — proven).

## Imaging order entry (G2 — reuse the Clinical Order)

An imaging order IS a Clinical `Order` (~95% reuse). `RadiologyOrderService::place` REUSES the EXISTING
`OrderService::place` (authorizes `order.manage`, runs the `ordered→…→reviewed` lifecycle) with the RAD.G1
exam's `OrderableItem`, then appends the thin **`radiology_orders`** overlay (the only net-new): `modality` +
`body_part` (default from the exam, overridable) + `priority` (routine/urgent/**STAT**), in one `DB::transaction`;
then calls the `ImagingConnectivity` seam's **`transmitOrder()`** (the future DICOM Modality-Worklist push — the
null no-op today). Ties to the patient + an optional `Encounter`. `radiology_orders` (BelongsToTenant, LogsReads,
**APPEND-ONLY** — model guards + DB triggers, `unique(order_id)`). **FENCE:** the priority is a RECORDED flag —
no computed priority/rank/escalation; **STAT is overlay-only, Clinical's `Order` UNTOUCHED** (priority stays
routine; `orders` schema unchanged); no computed image finding (no image yet). Placing reuses `order.manage`;
audit `radiology.order_placed` (patient-scoped, app-layer).

## Not built yet (later gates)

G4 the radiologist report (reuse the sign-and-lock
`ClinicalNote`) + routing [the fence gate] · G5 radiology billing (the engine; reconcile-to-the-unit) ·
**G6 [SEAM-STUBBED] the DICOM/PACS/modality feed + diagnostic viewer — partner-gated, NOT built** (a certified
PACS partner fills the `ImagingConnectivity` seam). After Phase 4, every hospital vertical is mapped/built;
standing certified-partner seams: drug-safety, HL7/analyzer, PACS/DICOM, anaesthesia device-data. See
`docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md`.
