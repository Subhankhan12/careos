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

**DENTAL.G1 + G2 + G3 COMPLETE.** G1 = tooth/odontogram data model (foundation). G2 = the interactive
odontogram chart UI, render-not-judge. G3 = the dental procedure catalog (tenant-authored fee schedule) +
billing-engine integration (a procedure IS a tariff item; charging reuses the tested engine, no new billing
logic).

## Open items / next gates (per docs/DENTAL-DELIVERY-MAP.md)

- (done: G2 odontogram UI · G3 procedure catalog + billing) · G4 procedures (perform workflow) · G5 treatment
  plan · G6 perio charting · G7 diagnosis record · G8 imaging/scans; later: G9 chair-view (reuse), G10
  sterilization/inventory, G11 ortho/scan-comparison. Long poles: imaging-device/scanner integration
  (partner-gated), licensed procedure codes (CDT licensed — tenant-authored catalog, do NOT bundle).
- All later gates keep the fence: build the prototype's AI-diagnosis / AI-overlay / auto-grade features
  WITHOUT the interpretation; dental agents ship draft-only with `tests/Evals/` locks.
