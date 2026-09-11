# Module: Radiology (`Modules\Radiology`)

## Purpose

The Radiology / RIS vertical — **Phase 4** of the phased hospital build (the LAST hospital phase; Phases 1
inpatient/ADT, 2 pharmacy, 3 lab/LIS, 5 surgery, 6 ED — all built). Planned as ~5 buildable gates + 1
seam-stubbed (`docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md`): the module + exam catalog + the CREATED
`ImagingConnectivity` (PACS/DICOM) seam + radiology RBAC (G1) → imaging order entry (reuse Clinical `Order`)
(G2) → the study record (net-new `ImagingStudy`) + modality worklist (G3) → the radiologist report (reuse the
sign-and-lock `ClinicalNote`) + report routing (G4) → radiology billing (G5) → **[SEAM-STUBBED] the DICOM/PACS/
modality feed + diagnostic viewer (G6 — partner-gated, NOT built)**. **The buildable RADIOLOGY core (G1–G5) is
COMPLETE** (see "RADIOLOGY core COMPLETE" below); only the partner-gated G6 DICOM/PACS feed remains a seam. Peer
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
- **RAD.G4**: the radiologist report + report routing — **THE FENCE GATE**. A report IS a REUSED sign-and-lock
  Clinical `ClinicalNote` (write → sign → read-only → amend → version), authored by the radiologist on a reused
  `Encounter`, tied to the RAD.G3 study by a radiology-side `imaging_study_reports` link (Clinical UNMODIFIED —
  the `ed_visit_encounters` precedent). `RadiologyReportService` (`saveDraft`/`sign`/`amend`/`reportFor`/
  `versionsFor`) composes `EncounterService`+`ClinicalNoteService`+`OrderService`+`ImagingStudyService`: signing
  advances the study → reported + the reused Order → resulted (`recordResult` — the report IS the result), whence
  the EXISTING order → review flow (`toReview`/`markReviewed`) routes it to the ordering clinician (reused, NOT
  reinvented). `ImagingReportController` (`/radiology/orders/{radiologyOrder}/report` show/save/sign/amend) +
  `Radiology/Report.vue` + `radiology.report.*` i18n; app-layer `radiology.report_started`; FIX.5 smoke extended
  (report GET 200 + save 403). **THE FENCE:** the radiologist AUTHORS the report (findings=objective,
  impression=assessment — prose); the system computes NO image finding/CAD/abnormality/confidence/auto-read/
  suggested-diagnosis (a HARD non-goal); nothing auto-populates (proven); no such column/logic. Sign-and-lock
  immutability + versioned amend reused (proven). 7 feature tests
  (`tests/Feature/Radiology/RadiologyReportTest.php`). No charge. See D-145.
- **RAD.G5**: radiology billing — an imaging order accrues its exam fee through the EXISTING engine,
  reconciling-to-the-unit. **The LAST buildable Phase-4 gate — the RADIOLOGY core is COMPLETE.** STRICTLY
  ORCHESTRATION (the LAB.G6 / ED.G6 pattern) — NO money math. An imaging exam is a tenant-authored `TariffItem`
  (radiology catalog, keyed by the RAD.G1 code, no licensed pricing); `RadiologyBillingService`
  (`priceExam`/`chargeOrder`/`invoiceOrder`/`catalogTariffs`, gate `billing.manage`) captures ONE charge per
  imaging order via `ChargeCaptureService::captureManual` (engine snapshots the fee + computes the line total),
  idempotent via `radiology_order_charges` (link, no money); outpatient issues via `validateForPatientPeriod`→
  `createDraftFromCharges`→`issue`, inpatient/ED imaging charges join the stay/episode invoice via the existing
  `invoiceStay`. `RadiologyBillingController` (`/radiology/orders/{radiologyOrder}/billing` + price-exam/charge/
  invoice) + `Radiology/Billing.vue` + `radiology.billing.*` i18n; FIX.5 smoke extended (GET 200 + charge 403).
  No audit hook (Charge/Invoice audited by Billing). **RECONCILES-TO-THE-UNIT proven both ways** (outpatient
  invoice δ=0; composite inpatient episode — imaging charges + bed-days on ONE stay invoice — δ=0). **FENCE:**
  the fee is a tariff, NOT report-driven (two orders for the same exam → same fee; STAT priority doesn't change
  it); the adversarial grep over `Modules\Radiology\src` finds zero money math; `radiology_order_charges`
  carries no money/report/finding/severity column. 7 feature tests
  (`tests/Feature/Radiology/RadiologyBillingTest.php`). See D-146.

## Radiology billing (G5 — the existing engine, reconciles-to-the-unit)

STRICTLY ORCHESTRATION — Radiology adds NO pricing/charge/VAT/line-total math (the adversarial grep over
`Modules\Radiology\src` is clean). An imaging exam is a tenant-authored `TariffItem` in the `radiology`
`TariffCatalog` (keyed by the RAD.G1 exam code, integer minor units, no licensed pricing).
`RadiologyBillingService::chargeOrder` captures ONE charge per imaging order via the EXISTING
`ChargeCaptureService::captureManual` (the engine resolves + SNAPSHOTS the fee + computes `line_total = qty ×
price`); idempotent via the `radiology_order_charges` link (soft `charge_id` ref, no money). Outpatient →
`invoiceOrder` (the existing validate→draft→issue flow, service date = the order date); inpatient/ED → the
imaging charges join the stay/episode's discharge invoice via `BedBillingService::invoiceStay`. **RECONCILES-TO-
THE-UNIT** proven both ways (outpatient δ=0; composite inpatient episode — imaging + bed-days on one invoice —
δ=0). Gated `billing.manage` (the billing office, NOT the radiology bench). **FENCE:** the fee is a tariff, NOT
report-driven (the report is a clinical record, the fee a rate).

## RADIOLOGY core COMPLETE (Phase 4) + the one deliberate gap

The buildable RADIOLOGY vertical is COMPLETE: **G1** module + tenant-authored exam catalog + the CREATED
`ImagingConnectivity` seam → **G2** order (reuse Clinical `Order`) → **G3** the net-new `ImagingStudy` (accession
+ legal state machine) + modality worklist → **G4** the radiologist report (reuse sign-and-lock `ClinicalNote`;
the fence) + routing → **G5** billing (the existing engine; reconciles-to-the-unit). A radiology dept now runs
end-to-end AS AN ORDER-FORM-WITH-NO-IMAGE SHELL: a clinician orders an exam, a radiographer records + accessions
the study on the modality worklist, a radiologist authors + signs the report (never a computed image read), it
routes to the ordering clinician, the office bills it. **THE ONE DELIBERATE GAP — RAD.G6 (NOT built):** the
DICOM/PACS/modality feed + diagnostic viewer is the CERTIFIED-PARTNER seam (`ImagingConnectivity`, null today; a
certified PACS partner fills it). AI radiology/CAD is a HARD medical-device non-goal. Also deferred: the optional
uploaded still (dental `DocumentService`). **After Phase 4, EVERY hospital vertical is built** (inpatient ·
pharmacy · lab · radiology · surgery · ED); standing certified-partner seams: drug-safety, HL7/analyzer,
PACS/DICOM, anaesthesia device-data. See `docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md`.

## The radiologist report + routing (G4 — the fence gate; reuse sign-and-lock)

A report IS a reused sign-and-lock `ClinicalNote` — the radiologist authors findings (→ objective) + impression
(→ assessment) as PROSE on a reused `TYPE_CONSULTATION` `Encounter`, tied to the RAD.G3 study by the
radiology-side `imaging_study_reports` link (one report-encounter per study; Clinical's Encounter/ClinicalNote
schema + sign-and-lock + one-open-per-practitioner invariants UNTOUCHED — the `EdVisitEncounter` precedent).
`RadiologyReportService::saveDraft` (note.write) / `sign` (note.sign + radiology.study + order.manage) / `amend`
(note.write, a versioned successor). **Signing files the report:** sign the note (immutable) → study → reported
(the G3 legal transition; requires acquired) → the reused Order → resulted (`OrderService::recordResult`, the
impression as the result value, source=manual) — atomic. **Routing REUSES the order → review flow** (the resulted
Order appears in `OrderService::toReview`; `markReviewed` → reviewed) — NOT reinvented (via the EXISTING
`clinical.orders.worklist`). **THE FENCE (the sharpest in radiology):** the report is AUTHORED prose — NO
computed image finding/CAD/abnormality/confidence/auto-read/diagnosis; nothing auto-populates; AI radiology is a
HARD medical-device non-goal.

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

**G6 [SEAM-STUBBED] the DICOM/PACS/modality feed + diagnostic viewer — partner-gated, NOT built** (a certified
PACS partner fills the `ImagingConnectivity` seam). Also DEFERRED: the optional uploaded still (dental
`DocumentService` — a limited manual export, not a diagnostic viewer). After Phase 4, every hospital vertical is
built; standing certified-partner seams: drug-safety, HL7/analyzer, PACS/DICOM, anaesthesia device-data.
See `docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md`.

## QA phase 8 (2026-09-08) — audit only, nothing fixed

**`P8-C2` — THE MODULE'S WORST DEFECT, and it rewrites cross-phase pattern 7.**
`ImagingReportController::resolve()` (`:153-170`):

```php
$radiologist = StaffProfile::query()->where('user_id', $actor?->getKey())->first()
    ?? StaffProfile::query()->orderBy('display_name')->firstOrFail();
```

The docblock states the intent correctly ("the radiologist authors their OWN report"); the `??`
**silently substitutes the alphabetically first staff profile in the tenant**. Driven: a report written
by `miriam.lang` stored `author_id → Beat Suter (coordinator)` — the same person the Phase-7 ED
criticals landed on. It fires whenever a `note.write` + `radiology.study` holder has **no linked
StaffProfile**, which is the default for a newly provisioned user; with zero profiles it 500s instead.
**Pattern 7 is not about dropdowns** — this module has none, and fails the same way server-side.

**THE REPORT LIFECYCLE IS OTHERWISE STRONG.** Two-step human act (Save draft → **Sign & file**), and
signing routes it to the ordering clinician's worklist. **Amendable with history** — driven: amending a
signed report created v2 (Draft) with its reason while **v1 (Signed) stayed byte-identical**
(`created_at == updated_at`, `supersedes_id` chained). `clinical_notes.signed_by` is a `users` FK
holding the ACTOR; `author_id` is the `staff_profiles` clinician — a real two-person split.
**But no surface names either of them** (`P8-H3`): the version block shows only "Version 1 · Signed · <time>".

**THE IMAGING FENCE IS HONEST.** No image, no canvas, no viewer; `NullImagingConnectivity` is the seam,
and the page says: *"Image storage and viewing (DICOM/PACS) are provided by a certified imaging partner
… This is the study record (metadata) — not a diagnostic viewer."* No CAD, no generated finding (D-172).

**`P8-C1`** — `Radiology/Billing.vue:18` prop `invoice` vs `:41` `function invoice()`; identical to Lab.
**`P8-H2`** — `RadiologyBillingService` has zero `DB::transaction`. **`P8-H5`** — billing is
`billing.manage`; radiographer and radiologist both 403. **`P8-H4`** — no nav entry; `/radiology/worklist`
is URL-only. `RadiologyOrderService::place` **is** transactional, and the modality/body-part placeholder
honestly previews the catalog fallback (`$modality ??= $orderable->specimen_or_modality`).

## QA-FIX.8a — billing display (P8-C1, D-215)

`Radiology/Billing.vue` carried the identical `invoice` prop / `function invoice()` collision as Lab and
is fixed identically: `issueInvoice()`, every money figure from `Modules\Billing\Services\ChargeSetReader`
(summed, formatted, with currency), `rate_formatted` instead of `unit_price_minor`, dead `tariffs` prop
removed. The issue-invoice button now renders — an outpatient imaging invoice was previously impossible
to issue through the UI.

`RadiologyBillingTest`'s byte fence over `Modules/Radiology/src` is why the reader lives in Billing;
naming an engine total column here reddens it. See D-215 and [[Lab]] — one defect, two files, one remedy.

## QA-FIX.8b — the report author (P8-C2, D-216)

`ImagingReportController::resolve()` no longer falls back to
`?? StaffProfile::query()->orderBy('display_name')->firstOrFail()`. It returns a **nullable** profile from
`StaffProfile::forUser()` (D-195), and **authoring refuses** when the actor cannot be identified —
`unidentifiedAuthor()`, wording copied from the two Clinical controllers that already refuse this way.

**The guess corrupted TWO records:** the resolved profile also went to `reportEncounter()`, so a
substituted author became the report **encounter's practitioner** as well.

**SIGNING IS UNAFFECTED BY DESIGN** — a signature is the acting USER (`clinical_notes.signed_by`), needs
no profile, and still works for an account without one. Only authoring refuses. A test pins both, plus
the legitimate author/signatory split (author = staff_profiles, signed_by = users).

**This is the finding that rewrote pattern 7:** the module has NO dropdown, so QA-FIX.7a's "remove the
default" remedy would not have touched it. The real shape is *resolving a person by convenience*.
A codebase sweep found this was the **only** such site; the other five `orderBy('display_name')` uses
build option LISTS, which is a different thing.

`Radiology/Report.vue` now renders `RefusalNotice` — this part introduces a refusal there. The other
eleven Lab/Radiology pages still render none (`P8-H1`, open).

## QA-FIX.8c — atomic charge capture (P8-H2, D-217)

`RadiologyBillingService::chargeOrder` now wraps the capture AND its link row in ONE `DB::transaction`, with
the owning order row locked `FOR UPDATE` (tenant-scoped) and the idempotency read INSIDE the lock.
Previously there was **no transaction at all**: `captureManual()` commits on its own and the LINK table is
the idempotency key, so an orphaned charge was invisible to the guard and a retry double-billed.

**Only ED of the three can be demonstrated live** (it captures >1 charge, so a mid-capture failure is
reachable). Radiology captures exactly one charge per order, so its atomicity is held by the structural guard
in `tests/Feature/Lab/ChargeCaptureAtomicityTest.php` plus the identical remedy. Stated, not papered over.

## Refusals are visible (QA-FIX.11a, D-224)

Every Radiology page now renders **`RefusalNotice`** (`resources/js/Components/RefusalNotice.vue`, D-210) — an
**adoption**, not a design: one import, one tag, no restyle, no reword. Before this the module refused
correctly and **no page showed it**, so a refusal and a success were byte-identical to the user.

**If you add a page with a write control, add the notice** — one import plus `<RefusalNotice />` as the
first child of the page wrapper. **If a page has no reachable refusal, do NOT add it** and pin the absence
with a reason (D-176; the `Checklist.vue` precedent).

**It reads the WHOLE error bag on purpose.** `validate()` keys by FIELD, the controllers key by DOMAIN, and
**which one a live control can actually reach differs per page** — on `Lab/Catalog` only the field key is
reachable; on `Hospital/WardBoard` a single refusal produced three messages at once. Never narrow it to
named keys.

## The recorded actor is finally NAMED on screen (QA-FIX.11b, `P8-H3`, D-225)

The data model was always right — the actor was recorded correctly everywhere — and **no surface displayed
any of them**. That was a DISPLAY defect, so the fix renders a name and **changes no write**.

Each controller has a private `actorNames(array $ids): array<int|string, string>` resolving the whole list
in **ONE query** (the `PatientAccessLogController::actorNames()` shape): staff `display_name` first, the
user's `name` otherwise. **An id that resolves to nobody is left UNNAMED** rather than labelled (D-176), and
the template prints behind a `v-if` so a null renders no dangling separator.

**Type note that PHPStan caught:** the map is `array<int|string, string>`, not `array<string, string>` —
**PHP normalises a numeric string key to an int**, so the stricter-looking type was simply wrong.
