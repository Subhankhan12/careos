# Module: Dental (`Modules\Dental`)

## Purpose

The dental clinical vertical (a paying general/family dentist bought it) — charting, procedures,
treatment plans, perio, imaging. Planned as ~8 core gates, foundational-first
(`docs/DENTAL-DELIVERY-MAP.md`). **DENTAL.G1 ships the FOUNDATION only:** the tooth/odontogram data
model (record-not-judge) + dental RBAC + a thin service. **No UI yet** (the chart UI is DENTAL.G2).
Dental inherits the whole tested foundation — tenancy, patients, scheduling+chairs (W8c resources),
the billing engine, documents/imaging storage, encounters/notes, audit, RBAC, governed AI, design
system — so its new surface is ONLY the dental clinical domain.

## Key tables

- `tooth_records` (BelongsToTenant, **APPEND-ONLY** at model + DB-trigger level) — one immutable
  charting record for one tooth (or one surface of a tooth) at one moment. Columns: id (ULID),
  tenant_id, patient_id (denormalized for read-logging), `tooth` (FDI two-digit), `surface` (nullable —
  null = whole-tooth record), `charted_condition` (the charted FACT), `note`, `reason` (why this
  supersedes a prior record — a correction), `charted_by`, `charted_at`, timestamps. UPDATE/DELETE
  blocked by `tooth_records_no_update`/`_no_delete` triggers (SIGNAL 45000, portable MariaDB 10.4 +
  MySQL 8). **The current odontogram = latest row per (tooth, surface); history = every row.**

## Key classes

- `Support\ToothNotation` (pure, no model deps) — **FDI / ISO 3950** two-digit notation, the canonical
  tooth id. `permanent()` (11–18/21–28/31–38/41–48, 32 teeth), `primary()` (51–55/61–65/71–75/81–85,
  20 teeth — family dentist charts children), `all()`, `isValid()`, `dentitionOf()` (derives
  permanent|primary from the id — never stored), `isSurface()`; `SURFACES` = mesial/distal/buccal/
  lingual/occlusal. A patient's dentition is NOT hardcoded to 32 teeth — it is whatever teeth have
  records (missing = a charted state; mixed dentition = both primary + permanent records).
- `Models\ToothRecord` — append-only (model `updating`/`deleting` throw `DentalException` + DB
  triggers), `BelongsToTenant`, `LogsReads` (`auditResourceType='tooth_records'`, `auditPatientId`).
  `creating` validates FDI id / surface / condition against the allowed vocabulary per scope
  (`WHOLE_TOOTH_CONDITIONS` for surface=null: present/missing/unerupted/implant/pontic/crown/root_canal/
  bridge_retainer; `SURFACE_CONDITIONS` for surface set: sound/caries/restoration/fracture/sealant/
  veneer/erosion/abrasion) — deterministic, NO interpretation.
- `Services\ToothChartService` — `chart()` appends a record (Gate `dental.chart`, actor + patient
  same-tenant, audited `dental.tooth_charted`); `currentChart()` (latest per tooth/surface) and
  `history()` (full trail, optional per-tooth) both Gate `patient.view` and write a patient-scoped
  `read` audit row. Pure record + retrieve — no interpretation logic.
- `Exceptions\DentalException`, `Providers\DentalServiceProvider` (boot: loadMigrationsFrom only).
- **Billing integration (DENTAL.G3):** `Models\DentalProcedure` (a THIN overlay on a Billing `TariffItem` —
  the tariff item holds code/name/FEE/VAT/active; the overlay adds only `tooth_scoped`, 1:1) +
  `Models\DentalProcedureCharge` (the light tooth link: ties a billing `charge` to an odontogram tooth/
  surface, no money). `Services\DentalCatalogService` (the fee schedule, over a dedicated dental
  `TariffCatalog` key 'dental'): `catalog` (get-or-create), `seedStarter` (generic template, NO CDT),
  `create`/`update` (author the tariff item's name+fee — data entry, no math), `list`. Gated `billing.manage`,
  audited (`dental.procedure.created/updated`). `Services\DentalChargeService::capture` charges a procedure
  through the EXISTING `ChargeCaptureService::captureManual` (resolves + SNAPSHOTS the fee — NO dental money
  math) and records the tooth link. A dental charge reconciles-to-the-unit like any other; a later fee edit
  never changes a past charge (snapshot). `Http\Controllers\FeeScheduleController` + `Dental/FeeSchedule.vue`
  (`/dental/fee-schedule`, `billing.manage`) = the presentational editor. See [[D-101]], [[Billing]].
- `Http\Controllers\OdontogramController` (DENTAL.G2) — the odontogram chart UI, PRESENTATIONAL over
  `ToothChartService` (P0D.GU). `show` (GET `/dental/chart/{patient}`, `patient.view`) renders the current
  chart (`currentChart`) + history (`history`) + the domain-owned tooth universe/surfaces/condition
  vocabulary as props (so no domain logic in the Vue); `store` (POST, `dental.chart`) charts through the
  service (append-only). String-id `{patient}` (FIX.1 → cross-tenant/missing 404). RENDER-NOT-JUDGE: the
  payload carries charted facts only; the Vue (`resources/js/pages/Dental/Odontogram.vue`) uses colour as a
  FACTUAL charted-condition legend (categorical, not severity), no score/grade/flag rendered. See [[D-100]].

## Invariants (ELECTRIC FENCE — record-not-judge)

- **NO interpretation anywhere.** The schema has no severity/score/risk/grade/abnormal/flag/priority/
  recommendation column; `charted_condition` is a value the clinician SELECTED, never computed. The
  system does not detect caries, grade decay, assess risk, or diagnose. Asserted by a schema + recursive
  output fence test (like the vitals/reporting tests). Same posture as vitals (D-D3) + order results (D-076).
- **Append-only history:** a correction is a NEW record + `reason`; prior states are never destroyed
  (model guard + DB triggers — both tested, including a raw `DB::table` update/delete throwing).
- **Tenant + patient scoped, fail-closed:** actor and patient must belong to the current tenant
  (`CrossTenantReferenceException` otherwise); `BelongsToTenant` confines every query; charting is
  audited, reading is patient-scoped read-logged.
- **FDI notation** (permanent + primary) is the canonical tooth id; invalid ids/surfaces/conditions are
  rejected deterministically.

## Arch boundary

`arch('Dental may use care modules + Audit services but not Audit models, AiCore, Nursing, or Comms')`.
Dental may use Patients/Scheduling/Clinical/Billing + Audit SERVICES (`AuditService`, `LogsReads`) — never
Audit models directly, AiCore, Nursing, or Comms. Cross-module guards that need another module live in `app/`.

## RBAC

New permission `dental.chart` ('Chart teeth and dental findings (odontogram)') in the catalog. Granted to
`org_admin` + `doctor` (the treating-clinician role — in a dental tenant this IS the general dentist).
A dedicated dentist/hygienist/assistant role split is a later dental gate. Reception/nurse are refused
(tested). Reads gate on the existing `patient.view`.

## Status

**DENTAL.G1–G5 = the CORE dental spine (complete); G6 (perio) built on top.** G1 = tooth/odontogram data
model (foundation). G2 = interactive odontogram chart UI, render-not-judge. G3 = procedure catalog
(tenant-authored fee schedule) + billing-engine integration (a procedure IS a tariff item; no new billing
logic). G4 = the perform-a-procedure workflow — one atomic action recording the clinical fact + charge +
tooth-state change. G5 = the phased, fee-scheduled treatment plan — dentist-authored, estimate reuses G3
pricing (snapshotted at proposal), estimates without billing (G4 charges). **G6 = perio charting** —
per-tooth, per-site periodontal measurements as RAW recorded facts (record-not-judge). **G7 = the
dentist-authored diagnosis record** — the SHARPEST fence: NO AI, NO suggested/proposed diagnosis, NO
auto-ranked differential; the dentist enters it and sets the status, the system only records. **G8 = dental
imaging** — upload + a basic 2D viewer + a dentist-authored reading, over the EXISTING clinical document
storage; NO AI/CV analysis; live capture/DICOM/3D overlay partner-gated. **THE GENERAL-DENTIST FEATURE SET
(G1–G8) IS COMPLETE: chart the mouth → record + bill procedures → build/track a phased fee-scheduled plan →
chart periodontal status → record a diagnosis → upload/view/read imaging.** **DENTAL.G9 = demo-readiness
(presentation + seed only): the vertical is now REACHABLE (a role-gated "Dental" nav entry + a `/dental`
patient-picker landing + a shared dental sub-nav + a patient→dental cross-link + a portal treatment-plan
link) and DEMO-READY (`DemoDentalSeeder`, reconciles-to-the-unit). See the demo-readiness section below +
[[D-107]].**

## Perform workflow (DENTAL.G4)

- `Models\PerformedProcedure` (APPEND-ONLY at model + DB-trigger level, LogsReads) — the clinical fact,
  tied to the resulting `charge_id` (NOT NULL). `Services\PerformProcedureService::perform` writes THREE
  things in ONE `DB::transaction`: (1) captures the charge via the EXISTING `DentalChargeService::capture`
  (G3, no new billing math); (2) records the `performed_procedures` row; (3) charts the resulting
  tooth-state via the EXISTING `ToothChartService::chart` (G1, append-only). **A failure in any step rolls
  back ALL THREE — no orphan** (tested; nested audit writes become savepoints). The tooth-state result is a
  perform-time input the DENTIST states (extraction → missing, filling → restoration on the surface) —
  charted verbatim against G1's vocabulary, no inference/judgment. **RBAC needs BOTH** `dental.chart`
  (up front, clinical) AND `billing.manage` (inside the charge) — the dentist-owner holds both via
  org_admin; a doctor (dental.chart only) is denied at the charge and everything rolls back. `Http\
  Controllers\OdontogramController::perform` (POST `/dental/chart/{patient}/perform`, string-id) + the
  odontogram Vue gains a "Perform a procedure" side-panel form + performed history (shown only when
  `can_perform`). No G3 code changed. See [[D-102]], [[Billing]], [[Clinical]].

## Treatment plan (DENTAL.G5)

- `treatment_plans` (lifecycle draft→proposed→accepted/declined→in_progress→completed; BelongsToTenant,
  LogsReads, NOT append-only) group `treatment_plan_phases` holding `treatment_plan_items` (a planned
  procedure = a G3 `dental_procedure` + tooth/surface + `estimated_fee_minor`). `Services\
  TreatmentPlanService`: `create/addPhase/addItem` (draft-only, gate `dental.chart`); `propose` SNAPSHOTS
  each item's `estimated_fee_minor` from the live tariff fee (`dentalProcedure->tariffItem->unit_price_minor`,
  READ not recomputed) then transitions draft→proposed — so a later fee edit never changes an accepted
  plan (tested); `accept/decline/start/complete` via a legal-only `transition`/`canTransition` state machine
  (illegal throws `DentalException`; completed/declined terminal); `itemEstimate` = snapshot ?? live fee;
  totals are `->sum(itemEstimate)` (the ONLY arithmetic — NO VAT/discount math, grep clean). **NO
  DOUBLE-CHARGE (tested): accepting/starting posts NO charge; the charge is created only when PERFORMED
  (G4).** **Link to G4:** `performed_procedures.treatment_plan_item_id` (nullable) + `PerformProcedureService
  ::perform` gains an optional `?TreatmentPlanItem $planItem = null` (G4's atomic 3-write workflow otherwise
  unchanged) that validates same-tenant + same-patient and stamps the link; an item is "done" when a
  performed procedure references it (derived). **FENCE: dentist-authored** — no auto-suggest/severity/AI; the
  payload carries no severity/suggested/ai field (tested via `tpAssertNoJudgment`). **RBAC:** manage =
  `dental.chart`; read = `patient.view`; perform a planned item = dental.chart + billing.manage (G4). UI:
  `Http\Controllers\TreatmentPlanController` + `Dental/TreatmentPlans.vue` (`/dental/plans/{patient}`,
  string-id) staff editor; `PortalTreatmentPlanController` + `Portal/TreatmentPlan.vue`
  (`/portal/treatment-plan`) read-only patient view (proposed-onward only, no actions/PSP). No G3/G4 behavior
  changed. See [[D-103]], [[Billing]], [[Patients]].

## Perio charting (DENTAL.G6)

- `perio_exams` (BelongsToTenant, LogsReads, **APPEND-ONLY** model `updating`/`deleting`-throw + DB triggers
  `perio_exams_no_update`/`_no_delete` SIGNAL-45000) — a point-in-time 6-point probing session (patient,
  examined_by, exam_date, note). Groups `perio_measurements` (BelongsToTenant, APPEND-ONLY, triggers) — one
  row per tooth × SITE. **`PerioMeasurement::SITES`** = the standard **6 probing sites** (mesio_buccal,
  buccal, disto_buccal, mesio_lingual, lingual, disto_lingual) — DISTINCT from `ToothNotation::SURFACES` (5
  anatomical surfaces the odontogram uses). Per site the RAW probed values: `pocket_depth_mm`,
  `recession_mm` (smallInteger — signed, negative = overgrowth), `bleeding_on_probing` (bool), + optional
  per-tooth `mobility` (0–3) / `furcation` (0–4) carried on the tooth's site rows. Tooth = FDI (reuses G1
  `ToothNotation::isValid`). **ELECTRIC FENCE (record-not-judge — perio's core risk): RAW numbers ONLY — NO
  stage (I–IV), grade (A–C), severity, risk score, auto-flag of a deepening site, or computed attachment-loss
  finding, anywhere in schema/service/UI.** `PerioMeasurement::assertValid` is pure data-entry validation
  (valid FDI/site + physically-plausible bounds: depth 0–15, recession -15–30, mobility 0–3, furcation 0–4)
  — bounds reject impossible input, they NEVER grade. A per-site trend (`siteHistory`, oldest first) is raw
  numbers in sequence — NO band/arrow/"worsening" label (same rule as unified vitals trends, P.13). Proven by
  a recursive `pcAssertNoJudgment` (forbids stage/staging/grade/severity/risk/flag/classification/worsening/…)
  over BOTH the page props and the siteHistory output. `Services\PerioChartService`: `recordExam` (gate
  `dental.chart`, actor+patient same-tenant fail-closed, DB::transaction of exam + site rows — an invalid
  value throws and the whole exam rolls back, audited `dental.perio_charted`); `examsFor`/`siteHistory` (gate
  `patient.view`, patient-scoped `read` audit). `Http\Controllers\PerioChartController` (GET `/dental/perio/
  {patient}` = show, POST = store, string-id FIX.1) + `Dental/PerioChart.vue` — the classic perio grid
  (teeth × 6 sites entry; prior exams as raw grids), PRESENTATIONAL (P0D.GU): NO severity colouring/flags/
  stage badge (a dot marks BOP = data entry, not severity). NEW `perio.*` i18n. No G1–G5 code changed. See
  [[D-104]], [[Dental]], [[Clinical]].

## Diagnosis record (DENTAL.G7) — the sharpest fence

- `diagnoses` (BelongsToTenant, LogsReads, **APPEND-ONLY** model `updating`/`deleting`-throw + DB triggers
  `diagnoses_no_update`/`_no_delete`) stores what the DENTIST decided: `label` (text they wrote OR picked),
  optional `tooth`/`surface` (FDI, reuses `ToothNotation`), `findings`, `reason` (a change), and `status` ∈
  `Diagnosis::STATUSES` {provisional, confirmed, ruled_out} the DENTIST sets; `diagnosis_term_id` is
  PROVENANCE only (null = free text). `diagnosis_terms` (BelongsToTenant, plain catalog, NOT append-only) =
  the tenant's OWN pick-list `{label, is_active}` — TENANT-AUTHORED like the procedure catalog, **NO licensed
  diagnostic code set (ICD/SNODENT) bundled**. **ELECTRIC FENCE — THE SHARPEST (do not compromise): NO AI in
  the diagnosis path at all this gate — NO suggested/proposed diagnosis, NO auto-ranked differential, NO
  computed likelihood/confidence, and NOTHING auto-populates a diagnosis from the charting/perio/imaging.**
  The system only records what the dentist entered; `status` is the dentist's determination, recorded not
  decided/suggested. `Diagnosis::assertValid` is pure data-entry validation (non-empty label, valid
  FDI/surface, known status) — never infers/ranks. The pick-list is a plain ALPHABETICAL list, never sorted/
  filtered by a computed judgment. `Services\DiagnosisService`: `record` (gate `dental.chart`, tenant+patient
  fail-closed, term-id must be this tenant's, audited `dental.diagnosis_recorded`); `diagnosesFor` (gate
  `patient.view`, patient-scoped `read` audit, history = every row newest-first); `terms`/`addTerm` (the
  tenant's pick-list, addTerm audited `dental.diagnosis_term.created`). `Http\Controllers\DiagnosisController`
  (GET `/dental/diagnoses/{patient}` = show, POST = store, POST `/dental/diagnosis-terms` = storeTerm,
  string-id FIX.1) + `Dental/Diagnoses.vue` — the dentist writes/picks a diagnosis, sets the status, ties an
  optional tooth, references findings, manages their own term list; diagnosis history newest-first.
  PRESENTATIONAL (P0D.GU): NO "suggested diagnosis" UI, NO differential ranking, NO AI panel, NO auto-fill.
  **Proven by the strictest fence test yet**: recursive `dxAssertNoSuggestion` over the payload + terms PLUS
  a no-auto-populate proof (charting caries [G2] + probing 9mm perio pockets [G6] yields ZERO diagnoses).
  NEW `diagnosis.*` i18n. No G1–G6 code changed. See [[D-105]], [[Dental]], [[AiCore]].

## Imaging (DENTAL.G8)

- Storage REUSE (no new file storage): a dental image is stored through the EXISTING Clinical
  `DocumentService::upload` (private `local` disk, tenant-prefixed `tenants/{tenant}/clinical-documents/
  {patient}/{ulid}.{ext}`, MIME/size validated, category `Document::CATEGORY_IMAGE`, NO public URL — Dental
  MAY use Clinical per `ModuleBoundariesTest`). NEW `dental_images` (BelongsToTenant, LogsReads, **IMMUTABLE**
  — model `updating`/`deleting`-throw + DB triggers `dental_images_no_update`/`_no_delete`) adds the metadata
  over it: `document_id` (the stored asset), `image_type` ∈ `DentalImage::TYPES` {bitewing, periapical,
  panoramic, photo, scan}, optional `tooth` (FDI) / `region`, `captured_at`, `uploaded_by`. The dentist's
  reading is NEW `dental_image_readings` (BelongsToTenant, **APPEND-ONLY** model + triggers) — free text the
  DENTIST wrote (`reading` + optional `reason`); a change is a new reading, history preserved. **ELECTRIC
  FENCE: the system DISPLAYS the image + records the dentist's reading — it NEVER analyses the pixels. NO
  AI/CV, NO caries/pathology detection, NO auto-findings, NO overlay, NO auto-annotation.** No method looks
  at the image bytes except to STREAM them; the schema/service/UI carry no ai/finding/detected/overlay/
  confidence field (recursive `diAssertNoAnalysis` + a no-auto-read proof: an upload creates ZERO readings).
  `Services\DentalImagingService`: `upload` (gate `dental.chart`; the doc store additionally enforces
  `note.write` — dentist/org_admin holds both), `recordReading` (gate `dental.chart`, append-only, audited
  `dental.image_read`), `imagesFor`/`fileContents` (gate `patient.view`, patient-scoped `read` audit). Private
  bytes stream ONLY through an authed nosniff route `GET /dental/image-file/{image}` (`private, no-store`, no
  public URL). `Http\Controllers\DentalImageController` (show/store/storeReading/download, string-id FIX.1) +
  `Dental/Imaging.vue` — upload + gallery + 2D viewer (client-side zoom/pan on raw pixels) + readings;
  PRESENTATIONAL (P0D.GU), NO AI panel/overlay. **PARTNER-GATED / NON-GOAL (flagged, NOT built): live capture
  (X-ray sensor / intraoral scanner), DICOM/PACS, 3D scan overlay/comparison, AI radiology/caries detection.**
  NEW `imaging.*` i18n. Reuses Clinical `DocumentService`/`Document` (allowed dep). No G1–G7 code changed. See
  [[D-106]], [[Dental]], [[Clinical]].

## Demo-readiness — navigability + demo seeder (DENTAL.G9)

Presentational/routing + seed ONLY (P0D.GU) — the dental FUNCTIONALITY (G1–G8) was already done and
safety-verified (docs/DEEP-AUDIT-REPORT.md); G9 makes it REACHABLE + DEMONSTRABLE. No fence/billing/
clinical/tenancy/RBAC logic changed, no existing behavior test modified. See [[D-107]].

- **Navigability.** A role-gated top-nav **"Dental"** entry (`dental.chart` added to
  `HandleInertiaRequests::NAV_PERMISSIONS`; `AppLayout.vue` nav item — a non-dental role never sees it) →
  a NEW `/dental` **patient-picker landing** (`Http\Controllers\DentalLandingController` + `Dental/Index.vue`,
  `dental.chart`-gated, PRESENTATIONAL — there is no patient-independent CLINICAL dental route, so the
  landing is a picker into each patient's odontogram; surfaces a fee-schedule link only for `billing.manage`).
  A shared **`resources/js/components/DentalSectionNav.vue`** sub-nav on all 5 patient dental pages
  (chart/perio/diagnoses/plans/images) makes the vertical navigable from any dental page. A **patient →
  dental cross-link** ("Dental chart →") on Patient 360 (`Patients/Show.vue`) + the clinical chart
  (`Clinical/Chart.vue`), gated client-side on the shared `auth.user.permissions['dental.chart']` (no dead
  link for non-dental staff). A portal **"Treatment plan"** nav link (`PortalLayout.vue`) surfaces the
  EXISTING read-only `/portal/treatment-plan` (own-data, no PSP). Route smoke gains `/dental` (dentist 200 /
  reception 403). NEW `DentalLandingTest` (4).
- **Demo seeder.** `database/seeders/DemoDentalSeeder.php` — **Zahnarztpraxis Morgenstern**
  (`zahnarztpraxis-morgenstern`, CHF), a companion to DemoClinicSeeder/DemoSpitexSeeder, built through the
  REAL services (idempotent by slug; D-066 — never rewinds `now()`). Seeds the generic starter fee schedule
  (no CDT), 4 patients with charted odontograms incl. a multi-step correction HISTORY, a live performed
  extraction (atomic clinical record + DRAFT charge + tooth-state), an ACCEPTED plan (portal-visible) + a
  PROPOSED one, two perio exams (raw trend), two diagnoses, an image + a dentist reading. **Dental billing
  reconciles-to-the-unit:** charges CAPTURED through the existing engine (`DentalChargeService::capture` +
  `forceFill(service_date)` into the closed previous month — capture() not perform(), so the mutable Charge
  can be back-dated; perform() also writes an append-only tooth record that cannot move) → validated → 3
  gapless invoices → full + partial payment; the paired `DemoDentalSeederTest` proves all six invariants at
  delta_minor === 0, the audit chain verifies, and a second run adds nothing. Draft charges (the live
  performed procedure) stay unbilled and are invisible to reconciliation (I4 counts only INVOICED charges).
  Seed: `php artisan db:seed --class=DemoDentalSeeder`.

## Open items / next gates (per docs/DENTAL-DELIVERY-MAP.md)

- (done: G2 odontogram UI · G3 catalog+billing · G4 perform workflow · G5 treatment plan [CORE spine
  complete] · G6 perio charting · G7 diagnosis record · G8 imaging — **general-dentist feature set G1–G8
  COMPLETE** · **G9 demo-readiness: navigability + `DemoDentalSeeder` + audit cosmetics [D-107]**) · later
  (renumbered — G9 is now demo-readiness): chair-view (reuse of the resource/day-board), sterilization/
  inventory, ortho/scan-comparison. Long
  poles (partner-gated/non-goal): live imaging capture (X-ray sensor / intraoral scanner) + DICOM/PACS + 3D
  scan overlay/comparison, AI radiology/caries detection (NON-GOAL — fence + regulated device), licensed
  procedure/diagnosis codes (CDT/ICD licensed — tenant-authored catalogs, do NOT bundle).
- All later gates keep the fence: build the prototype's AI-diagnosis / AI-overlay / auto-grade features
  WITHOUT the interpretation; dental agents ship draft-only with `tests/Evals/` locks.
