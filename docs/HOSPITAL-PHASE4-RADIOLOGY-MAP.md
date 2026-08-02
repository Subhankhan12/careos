# CareOS — Hospital Phase 4 (Radiology / RIS): Reconciliation + Build-Sequence Map

**Status: analysis only — NO code.** This is the map-before-building step for **Phase 4 of the phased hospital
build** — the **LAST hospital phase** (Phases 1 inpatient/ADT, 2 pharmacy, 3 lab/LIS, 5 surgery, 6 ED — all
built). It draws — precisely — the line between the **BUILDABLE radiology record-keeping** (imaging order entry,
a tenant-authored study catalog, the study record + modality worklist, the radiologist's authored report,
report routing, radiology billing, and — via the existing dental precedent — an optional manually-uploaded image
attachment) and the **PARTNER-GATED DICOM/PACS INTEGRATION** that is the *defining* feature of a real RIS
(native DICOM study storage, modality-worklist push, a diagnostic-grade multi-series viewer, PACS retrieval).
Same discipline as `docs/HOSPITAL-PHASE1-ADT-MAP.md`, `docs/HOSPITAL-PHASE2-PHARMACY-MAP.md`,
`docs/HOSPITAL-PHASE3-LAB-MAP.md`, `docs/HOSPITAL-PHASE5-SURGERY-MAP.md`, `docs/HOSPITAL-PHASE6-ED-MAP.md`,
`docs/DENTAL-DELIVERY-MAP.md`, `docs/CLINIC-DELIVERY-MAP.md`.

> The referenced `careos-hospital-expansion-scoping.md §2.4` is not committed to this repo (as with the other
> phase maps); this map is derived from the authoritative source — the codebase itself — and reconciles against
> it. **This is Phase 4 — the last hospital phase.** After it, every hospital vertical is mapped/built
> (inpatient · pharmacy · lab · radiology · surgery · ED), with the **certified-partner seams** (drug-safety,
> HL7/analyzer, PACS/DICOM, anaesthesia device-data) the standing gaps. Build order follows customer/partner
> pull, not phase number.

**The one-sentence thesis.** The radiology *workflow* is **~95% already built** — even more than lab: CareOS's
Clinical module already ships a generic **`Order` + `OrderResult`** whose lifecycle is modality-agnostic, whose
**`OrderableItem` already carries `category='imaging'` and a field literally named `specimen_or_modality`**, whose
result can already **attach a document** (a report/image); the **sign-and-lock `ClinicalNote`** is exactly a
radiology report (write → sign → read-only → amend → version); the **board/worklist idiom** (ED board, lab
review) is the modality worklist; the **billing engine** bills a study to the unit; and **dental imaging
(DENTAL.G8) already stores + views uploaded images** through the existing `DocumentService` (private disk,
authenticated stream, human-authored readings, **no pixel analysis**). So radiology is **overwhelmingly reuse**
(an imaging order *is* a Clinical `Order`; a report *is* a sign-and-lock note; an uploaded still *is* a
`Document`); the only genuinely net-new domain is the **study record** (identifiers + a legal-only
ordered → acquired → reported state) — the exact analog of the lab `Specimen`. And the **defining RIS value — the
DICOM/PACS/modality integration — is PARTNER-GATED**: no `ImagingConnectivity` seam exists yet, so Phase 4
**creates** it (the `LabConnectivity`/`MedicationSafetyProvider`/`TriageAcuityProvider` precedent), ships it as a
null no-op, and a certified PACS/DICOM partner fills it later. **Build the record-keeping + the report + the
optional uploaded still; create the seam; never compute an image finding; be honest that without PACS this is an
order-form + a typed report, not a diagnostic imaging workstation.**

---

## Section 0 — What CareOS already provides that Radiology REUSES (the head start — the largest of any vertical)

| Existing capability | How radiology uses it | Reuse quality |
|---|---|---|
| **Clinical `Order`** (`Modules/Clinical/src/Models/Order.php`) — status `ordered → collected → in_progress → resulted → reviewed` (+ `cancelled`); `priority` routine/urgent; `orderable_item_id`; `ordered_by`; `reviewed_by`/`reviewed_at`; **modality-agnostic** | **An imaging order IS an `Order`.** Its lifecycle already fits (in_progress = acquiring, resulted = report filed, reviewed = ordering clinician attested). No new order entity. | **Clean (direct reuse)** |
| **`OrderableItem`** (`OrderableItem.php`) — **`CATEGORY_IMAGING = 'imaging'` ALREADY EXISTS**; field **`specimen_or_modality`** (already named for modality); tenant-authored, "NOT a licensed catalog" | **The imaging study menu IS a set of `OrderableItem`s (`category='imaging'`)** — the category and the modality field are *already there*. A thin overlay adds body-part/contrast (§2.3). | **Clean (direct reuse) — the category already exists** |
| **`OrderResult`** (`OrderResult.php`) — append-only, raw, `source` = `manual`/`imported`; **`recordResult` accepts a `document_id`** (a linked report/image) | A study's result can **attach a report document/image**; `imported` is the future PACS/partner path. The fence (no interpretation column) is already built. | **Clean (direct reuse)** |
| **`OrderService`** (`place`/`transition`/`recordResult`/`markReviewed`/`toReview`) | Imaging order entry, status tracking, report-as-result, review, and the "to review" worklist are the EXISTING methods. `markReviewed` is a **human attestation, never a computed judgment**. | **Clean (direct reuse)** |
| **`ClinicalNote` + `ClinicalNoteService`** — sign-and-lock (`draft → signed`; immutable when signed; `amend` → new `version` via `supersedes_id` + reason) | **A radiology report IS a sign-and-lock note.** The radiologist authors → signs → it locks → amend creates a versioned successor (the lab-result / op-note / discharge-summary precedent). | **Clean (direct reuse)** |
| **`DocumentService` + the DENTAL.G8 imaging recipe** (`DentalImage`/`DentalImageController` — private disk, tenant-prefixed, MIME/size-validated, **authenticated byte stream, no public URL**, human-authored readings, **no pixel analysis**) | An **uploaded exported still** (JPEG/PDF from a modality) can be stored + viewed exactly like a dental image — a **limited manual image path** that already exists. (This is NOT DICOM/PACS — §3.) | **Clean (reuse the dental recipe)** |
| **The board/worklist idiom** (`EdBoardController`; `LabReviewController` + `LabResultService::reviewWorklist`; `OrdersReviewController`/`UnsignedNotesWorklist`) | **The modality worklist** (ordered studies to acquire) + the **report-review worklist** (reported studies to attest) are the EXISTING worklist shape — facts only, no computed priority. | **Clean (reuse)** |
| **Billing engine** (`TariffItem` · `ChargeCaptureService::captureManual` · `IssueService` · `ReconciliationEngine`) | An **imaging study is a `TariffItem`**; an acquired/reported study **accrues a `Charge`**; the invoice reconciles-to-the-unit — the LAB-G6 / ED-G6 / surgery-G5 pattern. | **Clean (orchestration only — §2.8)** |
| **The catalog-overlay precedent** (`LabTest`→`OrderableItem`; `DentalProcedure`/`SurgicalItem`) | A **radiology exam overlay** on `OrderableItem` (`category='imaging'`) adds only imaging fields — 1:1 with `LabTest` (§2.3). | **Clean (extend)** |
| **The partner-SEAM pattern** — `LabConnectivity`→`ManualLabConnectivity`, `MedicationSafetyProvider`→`NullMedicationSafetyProvider`, `TriageAcuityProvider`→`NullTriageAcuityProvider` (interface in `Contracts/`, one null no-op, bound in the provider) | The **`ImagingConnectivity` seam is minted in the same shape** (interface + `NullImagingConnectivity` no-op, bound in `RadiologyServiceProvider`) — the PACS/DICOM feed is a 1:1 partner seam (§3). | **Pattern precedent (the crux) — to be created** |
| **Multi-tenancy** (`BelongsToTenant`, fail-closed) · **Patient master + `Encounter` + `Stay` + `EdVisit`** (`Order.encounter_id` nullable) | A study = a tenant; an imaging order is placed on a `Patient`, optionally tied to an `Encounter`/stay/ED-visit — outpatient, ED, inpatient all reuse the same `Order`. | Clean (free) |
| **Append-only audit** (hash-chained) + **read-logging** (`LogsReads`) · **RBAC** (`order.manage` exists) + `RbacProvisioner` additive consts + reprovision-migration recipe | Every order/study/report is audited + patient-scoped read-logged; radiology roles are **additive templates** (§5). | Clean (drop-in / additive) |
| **Design system** + FIX.5 route-smoke / MySQL-parity / immutability (`SIGNAL '45000'`) guards | The worklist / study / report / billing UIs reuse tokens + primitives; new routes ride the existing guards; the study state history is append-only via the established trigger recipe. | Clean |

**Read-off:** the radiology *workflow* is **~95% reuse** — the order, the result, the report (sign-and-lock),
the review/routing, the worklist, the billing, the (uploaded-still) image path, and even the `imaging` catalog
category are **already built**. The only genuinely net-new domain is the **study record** (identifiers + a
legal-only state) — and the **defining RIS value (the DICOM/PACS/modality feed) is partner-gated**, behind a
seam that must be **created** (none exists yet).

---

## Section 1 — The radiology spine, mapped (reuse vs net-new vs partner)

| # | Spine element | What it needs | REUSE (with what) | NET-NEW radiology domain | ⛔ PARTNER-GATED (stub) |
|---|---|---|---|---|---|
| 1 | **Imaging order entry** | Order a study on a patient; modality (X-ray/CT/MRI/US — a plain type); body part; priority (routine/urgent/STAT — a recorded flag); optional encounter/stay/ED-visit link | **Clinical `Order` DIRECTLY** (`OrderService::place`; lifecycle; `priority`; `encounter_id`) | A thin **imaging-order overlay** (modality, body_part, a **STAT** priority value [additive]) (§2.4) | none |
| 2 | **Study/exam catalog** | The tenant's imaging exam menu (code, name, modality, body part, contrast) | **`OrderableItem`** (`category='imaging'` — **already exists**; `specimen_or_modality` holds the modality) | A **radiology-exam overlay** on `OrderableItem` (body_part, contrast, default modality) — tenant-authored, **NO licensed code set** (§2.3) | none |
| 3 | **The study record** | A study tied to the order; accession/study id; a legal-only state (ordered → acquired → reported) | The append-only + state-machine **shape** (`specimen`/`stay_events`/`ed_visit_events`); `Order.status` as the coupled clinical status | **`ImagingStudy`** (net-new): accession/study number, modality snapshot, acquired_by/at, a legal-only state machine, order link (§2.2) | The **DICOM study object** (SOP instances/series) = PACS (§3) |
| 4 | **The image itself** | Store + view the diagnostic image | An **uploaded exported still** (JPEG/PDF) via **`DocumentService`** (the DENTAL.G8 recipe) — a limited manual path | (nothing net-new — reuse the dental image recipe) | ⛔ **Native DICOM storage, multi-series/slice diagnostic viewing, PACS retrieval** — the `ImagingConnectivity` seam (§3) |
| 5 | **The radiologist report** | The radiologist reads the image + authors a report; sign + amend | **`ClinicalNote` sign-and-lock** (`ClinicalNoteService`: draft → sign → immutable → amend/version) — or `OrderResult` for a terse result | (choose: reuse `ClinicalNote`, or a thin `RadiologyReport` mirroring the recipe) (§2.5) | ⛔ A **COMPUTED image finding / CAD / auto-read / abnormality flag** = the fence line (§4) → non-goal/partner |
| 6 | **Report routing** | Route the report to the ordering clinician; review/attest | **The EXISTING order→result→review flow** (`markReviewed`; `toReview`; the LAB.G5 worklist) | A **radiology worklist** view (studies by status) — a read affordance (§2.6) | none |
| 7 | **Modality worklist** | A worklist of ordered studies for the radiographer to acquire | **The board/worklist idiom** (ED board / lab review) — operational facts, no computed priority | A **modality-worklist** query over imaging orders (§2.6) | ⛔ **DICOM Modality Worklist (MWL) push** to the machine = PACS/modality integration (§3) |
| 8 | **Radiology billing** | A study is billable → one invoice | **Billing engine unchanged**: `TariffItem` → `captureManual` → `Charge` → invoice → `ReconciliationEngine` (I4) | **Orchestration only**: capture a charge per study (the LAB-G6 / ED-G6 shape) (§2.8) | none (facts/money) |
| 9 | **DICOM/PACS/modality feed** (the DEFINING RIS feature) | Studies stored as DICOM in a PACS; MWL to modalities; a diagnostic viewer; results/reports IN | *(the seam to be created — `transmitOrder` on place)* | *(nothing — CareOS records manually + optional upload)* | ⛔ **The DICOM/PACS INTEGRATION** — the `ImagingConnectivity` seam filled by a **certified PACS partner**; homemade DICOM/PACS = **out of scope** (§3) |

---

## Section 2 — The load-bearing decisions (get these right)

### 2.1 The `ImagingConnectivity` seam — **reconciled: NO imaging-connectivity seam exists yet. Phase 4 CREATES it (mirroring `LabConnectivity`), it does not formalize an existing one.**

Unlike lab (where `LabConnectivity` + `ManualLabConnectivity` already existed and only needed formalizing), **there
is no imaging/PACS/DICOM seam in the codebase.** What exists is the *pattern* — three battle-tested seams to copy:
- **`Modules\Clinical\Contracts\LabConnectivity`** → `ManualLabConnectivity` (`transmit` no-op; `ingestResult` throws), bound in `ClinicalServiceProvider`, consumed in `OrderService::place`.
- **`Modules\Pharmacy\Contracts\MedicationSafetyProvider`** → `NullMedicationSafetyProvider` (returns `SafetyResult::none()`), bound in `PharmacyServiceProvider`.
- **`Modules\ED\Contracts\TriageAcuityProvider`** → `NullTriageAcuityProvider` (returns `AcuityResult::none()`), bound in `EDServiceProvider`.

**Decision (net-new, but a 1:1 pattern copy):** Phase 4 **creates** `Modules\Radiology\Contracts\ImagingConnectivity`
with two methods — **`transmitOrder(Order $order): void`** (the outbound DICOM Modality Worklist push — a no-op
today) and **`ingestStudy(array $payload): void`** (the inbound imported study/report — throws "not available;
studies are recorded manually / images uploaded" today) — plus a single shipped implementation
**`NullImagingConnectivity`** (both no-op/throw), bound in `RadiologyServiceProvider::register()` and consumed in
the imaging order path (`transmitOrder` on place, mirroring `OrderService::place` → `$this->lab->transmit`). The
seam references its peers **by name only** (no import — the arch boundary). It stays a `Null*` no-op until a
certified PACS/DICOM partner binds a real implementation. **Nothing homemade fills it.**

### 2.2 The study record — **the one genuine net-new radiology domain: an `ImagingStudy` (the lab-`Specimen` analog).**

`Order.status` tracks the clinical order, but there is **no study entity** — no accession/study number, no
acquisition provenance, no study state distinct from the order. A real RIS needs the **study as a tracked
object** (ordered → acquired at the modality → reported by the radiologist), because a study has its own
identity (accession/study id) and lifecycle.

**Decision (net-new, buildable):** an **`ImagingStudy`** (`BelongsToTenant`, `LogsReads`): `order_id` (link to
the Clinical `Order`), `accession_number` (a tenant-generated id — the `MRN`/`Specimen` accession precedent, a
tenant-row-locked gapless sequence), `modality` + `body_part` (from the order overlay), `acquired_by`/`acquired_at`,
and a **legal-only state machine** (`ordered → acquired → reported`, + `cancelled`/`abandoned` from a pre-report
state with a mandatory reason — the `Specimen`/`Stay`/`EdVisit` shape). Append-only **`ImagingStudyEvent`** for the
transitions (the `specimen_events`/`ed_visit_events` recipe — model guards + `SIGNAL '45000'` DB triggers). The
study state and the order status stay **loosely coupled** app-layer (acquiring a study can advance the order to
`in_progress`; filing the report advances it to `resulted` via the existing `OrderService`). **FENCE:** a study
priority (STAT/urgent) is a **recorded flag the clinician sets**, never a computed priority (§4). This is the
*exact* net-new shape LAB.G3 built for specimens.

### 2.3 Study/exam catalog — **extend `OrderableItem` (`category='imaging'` already exists); no licensed code set bundled.**

The imaging exam menu is a tenant-authored `OrderableItem` set with **`category = OrderableItem::CATEGORY_IMAGING`
(already defined)**; the modality can live in the existing **`specimen_or_modality`** field (already named for it).
A **thin radiology-exam overlay** (a `radiology_exams` table keyed by `orderable_item_id` — the `LabTest` /
`dental_procedures` / `surgical_items` precedent) adds the imaging-specific fields: **`body_part`**, **`contrast`**
(bool/enum), and a **default `modality`** if not carried on the orderable. **NO licensed CPT/RadLex/LOINC-imaging
set is bundled** — the tenant authors its own codes (the lab/dental/pharmacy/surgery discipline).

### 2.4 Imaging order entry — **reuse Clinical `Order` DIRECTLY; a thin overlay only for modality + body-part + STAT.**

An imaging order **is** a Clinical `Order` (`OrderService::place`): patient + optional encounter + the orderable
(the exam) + `priority` + `clinical_note`, through the existing lifecycle. Small additive needs:
1. **Modality + body part** — carried on the imaging-order overlay (§2.4) / the study (§2.2), not on `Order`.
2. **STAT priority** — `Order.priority` is `routine`/`urgent`; a **`stat`** value is a 1-line additive const
   **(a recorded flag, never a computed priority — §4)**. *(This is the identical additive LAB.G2 flagged; if lab
   already added `stat`, radiology reuses it.)*

**Do NOT duplicate the order.** No imaging-order entity is minted — the vertical composes the existing `Order`
app-layer (the exam catalog + study overlay hang off it). Like the lab order, **the imaging order fits `Order`
exactly** — reuse it (the sharpest "don't force it" call).

### 2.5 The radiologist report — **reuse the sign-and-lock `ClinicalNote` (or a thin mirror); it is authored documentation, never a computed read.**

The radiologist reads the image (via PACS when integrated; via the uploaded still or off-system today) and
**authors** a report. That is exactly a **sign-and-lock note**: `ClinicalNoteService` runs draft → **sign**
(immutable, `note.sign`) → **amend** (a versioned successor via `supersedes_id` + a mandatory reason). Two clean
options: **(a) reuse `ClinicalNote`** directly (a radiology note type/section set), or **(b) a thin
`RadiologyReport`** mirroring the recipe (like Lab reused `OrderResult` for a terse result, but a report is prose
→ the note recipe fits better). **Recommend reusing `ClinicalNote`** (the op-note / discharge-summary precedent)
and linking it to the study/order; a terse "result" can additionally ride `OrderResult` (with the report
`document_id`). **FENCE (§4):** the report is the **radiologist's recorded judgment**; the system computes **no**
finding/flag/CAD read.

### 2.6 Report routing + the modality worklist — **reuse the order→review flow + the worklist idiom.**

- **Report routing:** filing the report advances the `Order` to `resulted`; it routes to the ordering clinician
  via the EXISTING `OrderService::markReviewed` / `toReview` (the LAB.G5 worklist copied almost verbatim —
  `reviewWorklist` filtered to imaging orders). No new review flow.
- **The modality worklist:** a worklist of `ordered`/`in_progress` imaging studies for the radiographer to
  acquire — the **board/worklist idiom** (ED board / lab review), **facts only** (patient, exam, modality, the
  recorded STAT flag, ordered-time), advanced through the study state machine. **No computed priority ranking**
  (staff MAY sort by the recorded flag/time — the ED-board precedent).

### 2.7 The image path — **the dental upload recipe is a limited manual image; DICOM/PACS is the seam (§3).**

Radiology is **not** literally "no image ever": the DENTAL.G8 recipe (`DocumentService` → private disk →
authenticated byte stream, human readings, no pixel analysis) lets a human **upload an exported still**
(JPEG/PDF from a modality/CD) and view it against the study — a genuine but limited manual path, reusable
wholesale. What that path is **not** is a diagnostic imaging pipeline: **native DICOM storage, multi-series/slice
viewing, windowing/measurement tools, MWL push, and PACS retrieval** are the partner seam (§3).

### 2.8 Radiology billing — **the existing engine, unchanged; net-new is strictly orchestration.**

An imaging study is a **tenant-authored `TariffItem`** (own code, integer minor units — no licensed pricing).
Capturing a study's charge is `ChargeCaptureService::captureManual` (the engine snapshots the fee + computes the
line total); the invoice is the existing `validateForPatientPeriod → createDraftFromCharges → issue`; it
**reconciles-to-the-unit** (I4 δ=0) — the LAB-G6 / ED-G6 / surgery-G5 shape. An **inpatient/ED** study's charge is
a patient charge that joins the stay's `invoiceStay` (the composite-episode reuse). **No new billing math**
(adversarial-grep, like every billing gate).

---

## Section 3 — THE PACS/DICOM PARTNER BOUNDARY (the crux — draw it precisely)

The **DICOM/PACS/modality integration is the DEFINING feature of a real RIS** — studies stored as DICOM,
modality worklists pushed to the machines, a diagnostic-grade multi-series viewer, PACS retrieval. **It is
PARTNER-GATED, not built.**

### 3.1 The boundary, stated plainly

| Layer | Owner | Status |
|---|---|---|
| **Imaging order entry** (reuse `Order`), **exam catalog** (`OrderableItem` overlay), **study record + state** (`ImagingStudy`), **modality worklist**, **radiologist report** (`ClinicalNote` sign-and-lock), **report routing/review**, **radiology billing**, and an **optional uploaded exported still** (`DocumentService`, the dental recipe) | **CareOS** | ✅ Build now — record-keeping + authored report + a limited manual image, fence-clean |
| **The DICOM/PACS FEED** — native DICOM study storage, **DICOM Modality Worklist (MWL)** push to modalities, a **diagnostic multi-series/slice viewer**, PACS query/retrieve, imported study/report ingest | **A certified PACS/DICOM/modality partner** behind the `ImagingConnectivity` seam (`transmitOrder` / `ingestStudy`) | ⛔ **Never homemade** — the DEFINING value is partner-gated |
| **A COMPUTED image finding / CAD / auto-read / abnormality flag / triage-of-images** | **A certified medical-device partner**, or a **non-goal** | ⛔ **Never homemade** — the fence line (§4) |

### 3.2 The seam + the manual path

- **The seam (to create):** `ImagingConnectivity` (`transmitOrder(Order)` = the MWL push / `ingestStudy(payload)`
  = imported study/report) → `NullImagingConnectivity` (transmit no-op; ingest throws "recorded manually / images
  uploaded"), bound in `RadiologyServiceProvider` and consumed on order placement — the `LabConnectivity` shape.
  A real partner's `ingestStudy` would append the study metadata / an `OrderResult` `source=imported` through the
  same append-only, fence-clean path (it records; it **never interprets**).
- **The manual path (buildable today):** the radiographer records the study (acquired), a human **uploads an
  exported still** (`DocumentService`), and the radiologist **authors** the report (`ClinicalNote`). Fully built.

### 3.3 The honest note (say it plainly so expectations are clear)

**WITHOUT the PACS/DICOM partner, radiology is imaging order entry + a study record + a modality worklist + a
typed radiologist report + billing, plus an OPTIONAL manually-uploaded exported still — a REAL but LIMITED
record-keeping SHELL, an "order-form + a typed report" rather than a diagnostic imaging workstation.** It is
genuinely usable (a small site that orders studies, records them, uploads an export, and files a report), but the
**defining RIS value — native DICOM storage, modality-worklist integration, and a diagnostic viewer — is gated on
the PACS partnership.** This is a business conversation (a PACS/VNA vendor, a DICOM toolkit partner), **not** a
code gate. Build the shell; be explicit it is a shell; fill the seam when a partner is signed. (Same honesty as
the lab map's "HL7 = partner", the ED map's "computed triage = partner", and the pharmacy map's "drug-safety =
partner.")

---

## Section 4 — THE AI-IMAGING FENCE (an authored report is buildable; a computed image read is a hard non-goal)

The canonical rule (`AGENTS.md`): **no diagnosis, no triage, no symptom assessment, no dosing logic — anywhere.**
**Computer-aided detection (CAD), abnormality-flagging, auto-interpretation of images, or AI radiology reads are
MEDICAL-DEVICE decisions on the wrong side of it — a HARD non-goal** (already stated in the dental imaging
docblock: "AI radiology / caries detection is a NON-GOAL"). The radiologist **authors** the report (their
judgment, recorded); the system computes **no** image finding.

| Layer | Owner | Status |
|---|---|---|
| The **study record + metadata**, the **uploaded still** (stored/displayed), the **radiologist's authored report** (sign-and-lock) | **CareOS** | ✅ Build now — recorded facts + human-authored judgment |
| A **computed image finding / CAD / abnormality flag / auto-read / lesion detection / confidence score / image triage** | **A certified medical-device partner**, or a **non-goal** | ⛔ Never homemade |

**Already enforced (the precedent):** dental imaging stores images + **human-authored readings** and has **no**
`ai`/`finding`/`detected`/`overlay`/`confidence` field anywhere; `OrderResult` is raw with no interpretation
column; the vitals/lab/triage evals refuse computed clinical verdicts. Phase 4 must **not** add any computed
image read (schema/service/UI) — the report is **authored prose**, the image is a **displayed fact**.

**Interpretation temptations → build the record-not-judge version (or stub):**

| Temptation | Why it's fenced | Build instead |
|---|---|---|
| **CAD / auto-detect a lesion/fracture/nodule** | Computes a medical-device diagnosis | The radiologist reads + authors the report (records their judgment) |
| **Abnormality/critical-finding auto-flag or auto-alert** | Computed clinical verdict + escalation | A human marks/communicates a critical finding (record it); auto = certified device partner |
| **"Priority read" computed from the exam/history** | Computes a clinical priority | Priority is a **recorded flag** the clinician sets; the worklist shows facts |
| **AI "pre-read"/report drafting from pixels** | System clinical decision from images | Out of scope; a report is human-authored (any text AI is draft-only, human-owned, and ships its own `tests/Evals/` locks — and never reads pixels) |
| **Prior-study comparison / auto "change vs prior"** | Computed "getting worse?" judgment | Show the prior studies/reports in sequence (facts); the radiologist compares |

---

## Section 5 — New roles Phase 4 introduces (RBAC)

Purely additive — new entries in `RbacProvisioner::PERMISSIONS`/`::ROLE_TEMPLATES` + one re-provision migration
(the `add_lab_permissions`/`add_ed_permissions` precedent); zero `Gate::before` change. `order.manage`
("place + track structured clinical orders") **already exists** and is the ordering permission.

**New permissions (additive):**
- **`radiology.study`** — record/track a study + manage the modality worklist (the radiographer/technologist).
  *(A radiology-bench act, distinct from `order.manage` [the clinician ordering] and `note.write`.)*
- **`radiology.catalog`** — author the imaging exam catalog (`billing.manage` prices the tariff, as everywhere).
  *(Or reuse an existing catalog-admin permission if a customer's separation is coarse.)*
- **The report reuses `note.write` + `note.sign`** (the sign-and-lock note is authored/signed with the existing
  clinical-note permissions — no new report permission needed).

**New roles (additive templates):**

| New role | Closest existing template | Already covered | What the new role adds |
|---|---|---|---|
| **Radiographer / technologist** | `nurse` / `lab_tech` (`patient.view`, `order.manage`) | Sees orders, tracks status | **`radiology.study`** (record/acquire studies, manage the worklist; upload the still) |
| **Radiologist** | `doctor` / `pathologist` | Clinical read/write, `order.manage`, `note.write`/`note.sign` | **`radiology.study`** *(+ authors/signs the report via the existing `note.write`/`note.sign` — the human read, recorded)* |

**Existing roles that touch radiology (reuse):** `doctor`/`hospitalist`/`ed_physician`/`surgeon` already hold
`order.manage` — they **order** imaging today via the existing `Order` flow. **`org_admin`** gains the new perms.
Scope stays `branch_id` (a per-modality/room scope is a later `abac_conditions` gate).

---

## Section 6 — Dependency-ordered build sequence (proposed gates)

Foundational-first, each gate buildable + testable. **Placement recommendation: a new peer module
`Modules\Radiology`** that **REUSES Clinical's `Order`/`OrderResult`/`ClinicalNote`** and **owns the new
`ImagingConnectivity` seam** (mirroring `Modules\Lab` + `Modules\ED`).

**Why a peer module, not folded into Clinical or Hospital.** (1) It mirrors the established shape —
Lab/Surgery/Pharmacy/ED are peer modules that reuse Clinical. (2) The net-new radiology domain (the study record,
the exam-catalog overlay, radiology RBAC, the modality worklist/UI, and — unlike lab — its own connectivity seam)
is self-contained and keeps Clinical lean. (3) `Modules\Radiology` MAY use Clinical/Patients/Billing (the allowed
dependencies) + Audit *services* — it consumes `OrderService`/`ClinicalNoteService`/`DocumentService` app-layer,
mints the net-new `ImagingStudy`/exam-overlay tables, and binds its own `ImagingConnectivity`. *(Alternative
considered: fold into Clinical since `Order`/`OrderResult`/`ClinicalNote` live there — rejected: it bloats
Clinical with a vertical [studies, radiology roles, a worklist, a PACS seam]; the peer-module shape is
consistent. Alternative considered: fold into `Modules\Hospital` — rejected: radiology serves outpatient + ED +
inpatient equally, so it is a peer vertical, not an inpatient sub-feature.)* Cross-vertical composition (e.g. an
inpatient/ED study's charge joining `invoiceStay`) lives **app-layer**, the `EdDispositionService` precedent.

| Gate | Deliverable | Depends on | FULL vs SEAM-STUBBED |
|---|---|---|---|
| **RAD.G1** | **Module + imaging exam catalog + the `ImagingConnectivity` seam (CREATED) + radiology RBAC (foundation).** Register `Modules\Radiology`; a **radiology-exam overlay** on `OrderableItem` (`category='imaging'`; body_part/contrast/default-modality; tenant-authored, no licensed set); **create** the `ImagingConnectivity` seam (interface + `NullImagingConnectivity` no-op, bound; document the MWL/imported paths) — the `LabConnectivity`/`TriageAcuityProvider` shape; `radiographer`/`radiologist` roles + `radiology.study`/`radiology.catalog` perms (additive) + reprovision migration; arch boundary rule. Backend + tests, minimal UI. | Clinical (Order/Orderable), Platform, RBAC, Audit | **FULL** (catalog + seam created; the PACS impl is partner) |
| **RAD.G2** | **Imaging order entry (REUSE Clinical `Order`).** Order an imaging exam via the EXISTING `OrderService::place` (an exam = an `Order`); a thin overlay for modality + body_part; add/reuse a **`stat`** priority const (additive, a recorded flag). Gate `order.manage`. | RAD.G1, Clinical, Patients | **FULL** (direct reuse) |
| **RAD.G3** | **The study record (NET-NEW) + the modality worklist.** An `ImagingStudy` (accession, modality/body_part, acquired_by/at, legal-only `ordered → acquired → reported` [+ `cancelled`/`abandoned` + reason]) + append-only `ImagingStudyEvent`; tied to the `Order`; acquiring advances the order (existing `transition`, app-layer). The **modality worklist** (studies to acquire) reuses the board/lab-review idiom — facts, no computed priority. Gate `radiology.study`. | RAD.G2 | **FULL** (record/metadata + worklist) — **the DICOM image path is SEAM-STUBBED (RAD.G6)** |
| **RAD.G4** | **The radiologist report (REUSE sign-and-lock) + report routing.** Author the report as a `ClinicalNote` (draft → sign → immutable → amend/version), link it to the study/order; filing advances the `Order` to `resulted`; route to the ordering clinician via the EXISTING `markReviewed`/`toReview` (the LAB.G5 worklist). Optionally attach an uploaded exported still (`DocumentService`, the dental recipe). **FENCE: NO computed image finding/flag** — the report is authored. Gate `note.write`/`note.sign` + `radiology.study`. | RAD.G3, Clinical (ClinicalNote/OrderResult), (DocumentService) | **FULL** (reuse) — **the fence gate** |
| **RAD.G5** | **Radiology billing.** An imaging study is a `TariffItem`; capture via `captureManual`; the invoice reconciles-to-the-unit (I4); an inpatient/ED study's charge joins the stay's `invoiceStay`. Gate `billing.manage`. | RAD.G2, Billing | **FULL** (orchestration, no new math) |
| **RAD.G6** *(SEAM-STUBBED / partner)* | **DICOM/PACS/modality feed.** A certified PACS/DICOM partner binds a real `ImagingConnectivity`; `transmitOrder` pushes the DICOM Modality Worklist, `ingestStudy` ingests the DICOM study/report (never interpreted); a diagnostic multi-series viewer + PACS retrieval. **NOT built — partner-gated.** The seam + the imported-path shape are ready. | RAD.G1 (the seam) | **⛔ SEAM-STUBBED** (the defining value; pending a partner) |

**Rough gate count:** **~5 buildable gates (RAD.G1–G5)** for a radiology record-keeping shell (order + study +
report + billing + optional uploaded still), foundational-first, each testable alone; **+1 SEAM-STUBBED**
(RAD.G6 — the DICOM/PACS feed, partner-gated, NOT built). **Critical path: RAD.G1 → RAD.G2 → RAD.G3 → RAD.G4**
(catalog/seam → order → study+worklist → report+routing is the load-bearing chain). **RAD.G5 (billing) parallels
off G2.** **Most gates are REUSE-heavy** (G2/G4/G5 lean on Clinical + Billing); the only genuinely net-new domain
is **G3 (the study record)** — one fewer buildable gate than lab because radiology has no specimen-*and*-result
split (the report reuses the note, the "result" reuses the review flow). **RAD.G6 is off the buildable path** —
the seam is established in G1 and stays a no-op until a partner fills it.

---

## Section 7 — Platform-fit + fence risks (where reuse is CLEAN vs FORCED)

**🟢 CLEAN — reuse with confidence (the most of any vertical):** the imaging **order** IS a Clinical `Order`
(direct reuse — the `imaging` category and the modality field already exist); the **report** IS a sign-and-lock
`ClinicalNote` (draft→sign→immutable→amend/version); the **result/routing** is `markReviewed`/`toReview` (the
LAB.G5 worklist copied); the **modality worklist** is the board idiom; the **uploaded still** is the DENTAL.G8
`DocumentService` recipe (no analysis); **billing** reuses the engine (no new math); the **catalog** extends
`OrderableItem`; **RBAC** is additive; the **seam pattern** is copied 1:1 from `LabConnectivity`.

**🔴 FORCED / NET-NEW — the few genuine builds:**
1. **The study record has no home.** `Order.status` is a status, not a study — no accession, no acquisition
   provenance, no study state. → a **net-new `ImagingStudy`** (§2.2). This is the one real net-new domain (the
   lab-`Specimen` analog).
2. **The `ImagingConnectivity` seam does not exist.** Unlike lab, there is no imaging seam to formalize — Phase 4
   **creates** it (a 1:1 copy of `LabConnectivity`, §2.1). *(A pattern copy, not a design problem.)*

**🚨 THE SHARPEST RISKS:**
1. **Duplicating Clinical's `Order`/`ClinicalNote`.** The biggest mistake would be minting a parallel
   imaging-order / radiology-report entity — the existing ones fit *exactly* (the `imaging` category is already
   there; the sign-and-lock note is exactly a report). **Reuse them; extend only with the study + the exam
   overlay + the seam.**
2. **The AI-imaging fence — a COMPUTED image finding / CAD read.** This is the sharpest fence risk and a **hard
   medical-device non-goal** (already stated for dental). Keep it record-not-judge: store/display the image
   (fact) + the **authored** report (human judgment); **never** compute a finding/flag/CAD/overlay/confidence
   (§4). No such column, service, or UI — ever.
3. **Filling the `ImagingConnectivity` seam with a homemade DICOM/PACS stack.** The defining value is
   partner-gated; a homemade DICOM toolkit / PACS / diagnostic viewer is out of scope (partner-and-market work).
   Keep the seam a `Null*` no-op until a certified partner fills it. The **uploaded-still** path (dental recipe)
   is the honest interim — it is a manual export viewer, **not** a DICOM diagnostic pipeline; do not present it as
   one.
4. **Over-promising the value.** The HONEST framing (§3.3): what's built is an **order-form + a study record + a
   typed report + billing (+ an optional uploaded still)** — a real but limited shell; the DICOM/PACS/modality
   integration and the diagnostic viewer are the partner-gated defining value. State it plainly to the customer.

---

## Where this sits

**Phase 4 of the phased hospital build — the LAST hospital phase.** Phases 1 (inpatient/ADT), 2 (pharmacy),
3 (lab/LIS), 5 (surgery), and 6 (ED) are built; **Phase 4 = Radiology / RIS** (this map). Like the lab, **the
radiology defining value (the DICOM/PACS/modality feed) is PARTNER-GATED** — so RAD.G1–G5 deliver a **real but
limited record-keeping shell** (order via the reused `Order`, a tracked `ImagingStudy` on a modality worklist, an
optional uploaded exported still, a sign-and-lock radiologist report routed to the ordering clinician, billed to
the unit), and **RAD.G6 (the DICOM/PACS feed + diagnostic viewer) waits for a certified PACS partner** behind the
newly-created `ImagingConnectivity` seam. Day-one manual RIS shell (G1–G5): a clinician orders a study (reused
`Order`) → a radiographer records a tracked `ImagingStudy` from the modality worklist (and may upload an exported
still) → a radiologist authors + signs the report (**no computed image finding**) → the report routes to the
ordering clinician for review → the study bills to the unit. **The long-pole partner surface — native DICOM
storage, modality-worklist push, a diagnostic multi-series viewer, PACS retrieval, and any computed image
read/CAD — stays behind the seam, never homemade.**

**After Phase 4, every hospital vertical is mapped/built** — inpatient · pharmacy · lab · radiology · surgery ·
ED — with the standing **certified-partner seams** the honest gaps: **drug-safety** (`MedicationSafetyProvider`),
**HL7/analyzer** (`LabConnectivity`), **PACS/DICOM** (`ImagingConnectivity`, created here), and **anaesthesia
device-data** (surgery). **Build the record; create the seam; display the image but never read it; and be honest
the defining value is partner-gated.**
