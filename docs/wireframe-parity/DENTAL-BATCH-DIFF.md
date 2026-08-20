# Dental Batch — Wireframe-Parity Diff (13 screens, audit only)

**AUDIT + REPORT ONLY. Nothing was fixed, no app code changed, no gate opened.**
`resources/prototype/` stays gitignored.

- **Date:** 2026-08-20 · **HEAD:** `cf70f69` · **CI:** `check -> completed / success` · tree clean.
- **Env:** `migrate:fresh` + `db:seed --force` + `DemoClinicSeeder` + `DemoDentalSeeder` (Praxis Lindenhof ·
  Zahnarztpraxis Morgenstern), Redis up.
- **Scope:** the 13 Dental screens from `WIREFRAME-INVENTORY.md`, decoded and audited as ONE batch.
- **Standing:** the nine-page pass is CLOSED (D-160). This is a NEW batch under the same discipline — *match the
  visual, never weaken a real gate, keep correctly-more-real, surface reconciliations, never fabricate a backend
  to match a mock.*

> ## ⚠️ THE HEADLINE, UP FRONT
>
> **This is not a re-skin batch. It is the most fence-loaded domain in the design pack.**
>
> The dental wireframes were drawn around an **agent that diagnoses**: it reads pulp tests and radiographs and
> proposes a two-axis endo diagnosis with a confidence level; it narrows a differential; it substitutes an
> antibiotic around a penicillin allergy and doses it; it totals perio indices and flags "one site to watch";
> it measures a crown prep against a material minimum and prescribes the material; it reads a bitewing and
> confirms bone loss; it computes a prognosis.
>
> **Essentially none of that may be built as drawn.** Every item is either a computed clinical judgment
> (record-not-judge), an AI imaging finding (a regulated CADe/CADx device), or drug-safety/dosing logic — all
> permanent non-goals or certified-partner seams.
>
> **The live dental build already made these calls correctly**, screen by screen, and its tests lock them. The
> honest output of this audit is therefore **less "here is the parity backlog"** and **more "here is the list of
> things the mock asks for that we must continue to refuse, and the much smaller list of genuine visual work
> underneath."**

---

## 1 — The 13 screens

All 13 decoded to `resources/prototype/dental-*.wireframe.html` (standard pipeline: bundler loader reconstructed
in Node → base64/gunzip manifest+template+ext_resources → UUID-substitute → headless render → post-render DOM →
loader stripped → hosted Inter). **13/13 rendered.** Three (Scan Library, Scan Upload, Scan Comparison) logged
console errors from **unrendered `{{ }}` placeholders in the mock's own SVG mesh imagery** — mock artefacts, not
decode failures; their DOM rendered fully.

| # | Screen | Purpose (one line) | Live route · Vue |
|---|---|---|---|
| 1 | **Odontogram** | The base tooth chart every specialty view hangs off; FDI notation, per-surface findings | `dental/chart/{patient}` · `Dental/Odontogram.vue` |
| 2 | **Perio Charting** | Full-mouth 6-point probing, with live indices and a "site to watch" | `dental/perio/{patient}` · `Dental/PerioChart.vue` |
| 3 | **Endo Diagnosis** | Agent synthesises pulp tests + radiograph into a proposed two-axis diagnosis | `dental/diagnoses/{patient}` · `Dental/Diagnoses.vue` |
| 4 | **RCT Procedure** | Single-visit molar endo; working lengths, irrigation, obturation | *(procedure flow)* `dental/plans/{patient}` · `Dental/TreatmentPlans.vue` |
| 5 | **Crown Prep** | Post-endo cuspal coverage; reduction/ferrule gauged live, material Rx proposed | *(procedure flow)* `dental/plans/{patient}` · `Dental/TreatmentPlans.vue` |
| 6 | **Treatment Plan** | A phased, priced, consented plan the clinician owns | `dental/plans/{patient}` · `Dental/TreatmentPlans.vue` |
| 7 | **Fee Schedule Editor** | The priced tariff positions the billing agent grounds on | `dental/fee-schedule` · `Dental/FeeSchedule.vue` |
| 8 | **Scan Library** | Every scan a patient has, browsable; pick two to superimpose | `dental/images/{patient}` · `Dental/Imaging.vue` |
| 9 | **Scan Upload** | Live intraoral capture; the scanner builds a mesh and flags thin coverage | `dental/images/{patient}` · `Dental/Imaging.vue` (manual upload) |
| 10 | **Scan Comparison Viewer** | Planned setup superimposed on today's scan; deviation measured | ⚪ **NO LIVE PAGE** |
| 11 | **Ortho Progress** | Mid-treatment aligner tracking check; planned vs actual, off-track flagged | ⚪ **NO LIVE PAGE** |
| 12 | **Chair Scheduling** | The operatory-resource lens — columns are chairs, not people | ⚪ **NO LIVE PAGE** |
| 13 | **Inventory & Sterilization** | Autoclave cycles, instrument traceability, stock par levels | ⚪ **NO LIVE PAGE** |

**9 of 13 have a live page** (built functional-plain, never diffed). **4 have none.**

### The real dental backend the audit maps onto

Verified in `Modules/Dental/src` — **13 models**: `ToothRecord` · `PerioExam` · `PerioMeasurement` ·
`Diagnosis` · `DiagnosisTerm` · `DentalImage` · `DentalImageReading` · `DentalProcedure` ·
`PerformedProcedure` · `DentalProcedureCharge` · `TreatmentPlan` / `…Phase` / `…Item`. **8 services**, incl.
`ToothChartService`, `PerioChartService`, `DiagnosisService`, `DentalImagingService`,
`PerformProcedureService`, `TreatmentPlanService`, `DentalChargeService`, `DentalCatalogService`.

**The fee schedule is not a new pricing engine:** `DentalCatalogService` authors the tenant's own `TariffItem`s
over the **existing Billing tariff engine** — "it computes no prices". **No licensed code set (ADA CDT / Swiss
SSO point values) is bundled**, by decision.

---

## 2 — Per-screen diff

Classification: **(a)** visual · **(b)** backend gap · **(c)** fence/integrity MUST-NOT-WEAKEN ·
**(d)** correctly-more-real.

| Screen | Key deltas | Class | Severity |
|---|---|---|---|
| **Odontogram** | Mock shows a live-recomputing **DMFT/dmft caries index**, a **"1 finding"** count and **"Flagged · one site to watch"**. Live omits all three deliberately. · Mock adds **mixed-dentition** mode (primary + permanent on one arch, unerupted successors in parentheses, sealant key) — live is permanent-only. · Mock has Read/Chart mode toggle, a per-tooth detail rail with history, and a US-notation cross-reference. | **(c)** for DMFT/finding-count/flag · **(b)** mixed dentition · **(a)** rail/toggle/notation | c: **Must-not-build** · b: **Med** · a: **Low** |
| **Perio Charting** | Mock's whole right rail is agent-computed: **BOP %, sites ≥4 mm, mean pocket depth, plaque score**, each with a **trend arrow** ("▼ from 3.1", "plateau"). · **"One site to watch"** — "the agent flagged it for re-evaluation". · **"Corroborated on today's bitewing — bone loss confirmed"** = an **AI imaging finding**. · Mock has a **colour band scale** (1–3 / 4–5 / 6 mm+ / bleeds). | **(c)** indices, trend labels, site-to-watch, bitewing finding, colour banding · **(a)** grid layout/entry ergonomics | c: **Must-not-build** · a: **Low** |
| **Endo Diagnosis** | The most fence-loaded screen in the pack. **Agent-proposed two-axis diagnosis with "High confidence"**; **auto-narrowed differential** (Ruled out / Confirmed / Less likely); **AI radiograph findings** ("periapical radiolucency ~4 mm, loss of lamina dura"); **automatic antibiotic substitution + dosing** ("penicillin allergy blocked amoxicillin → clindamycin 300 mg qds"); **computed prognosis** ("Favourable"); **recommended pathway**. · Genuinely recordable underneath: pulp-sensibility test results vs adjacent controls, percussion/palpation/mobility/probing, the two-axis AAE **terms** as an authored pick-list. | **(c)** diagnosis, differential, confidence, imaging findings, drug substitution/dosing, prognosis, recommendation · **(b)** structured pulp/periapical test records | c: **Must-not-build** · b: **High** |
| **RCT Procedure** | **"computing working lengths from the apex locator"**, **"Agent flag: a 4th canal (DL) is likely"**, **"Agent's obturation plan"**, agent-standardised irrigation protocol. · Recordable underneath: canals found, working length per canal, irrigant/obturation materials as charted facts, chair time. | **(c)** likely-canal flag, computed working length, agent obturation plan · **(b)** per-canal endo record | c: **Must-not-build** · b: **Med** |
| **Crown Prep** | **Reduction/ferrule gauged live from a scan and compared to a material minimum** ("0.9 vs 1.0 mm minimum — a light additional pass…"), **"1 surface to refine"**, **"slightly under-reduced"**. · **AI scan analysis** ("margin fully captured, no voids", "interproximal clearance verified"). · **Agent's restoration Rx** (auto-selected material). · **Drug cross-reactivity reasoning** ("Articaine — no cross-reactivity with her penicillin allergy"). · Recordable underneath: measured reduction values, shade, margin type, cement, lab + turnaround. | **(c)** gauged-vs-minimum verdicts, scan analysis, material Rx, cross-reactivity · **(b)** prep-record fields, lab dispatch | c: **Must-not-build** · b: **Med** |
| **Treatment Plan** | **The best-behaved screen in the batch** — it states the fence itself ("the clinician owns the plan; the agent only proposes and prices"). Live already has phases/items/estimate/consent. · Deltas are mostly visual: phase timeline styling, the goal narrative, the provenance rail. · **Money:** total estimate, per-phase CHF, "CHF 104 of 1,240 billed" — must stay **engine-computed**. · **"Point value CHF 4.00 · v2025.1"** has no backend. · Agent-drafted sequence must ride the real ApprovalQueue. | **(a)** most of it · **(b)** point value, phase scheduling dates · **(c)** money-must-be-engine, agent-must-be-capped | a: **Low** · b: **Med** · c: **Guard** |
| **Fee Schedule Editor** | Mock is built on **tax points × a point value (Taxpunktwert)** with **versioned, effective-dated schedules** and a **version diff**. Live is a tenant-authored flat-fee `TariffItem` catalog — **no point-value concept, no schedule versioning, no diff**. · Mock groups positions by category and shows active/retired status. · Governance copy ("the agent may read active positions, never edit them") matches the real posture. | **(b)** point value + versioning + diff (substantial) · **(a)** grouping/status/layout | b: **High** · a: **Low** |
| **Scan Library** | Mock is a **3D scan/mesh** library (upper/lower/bite/ortho-tray filters, "planned setup" items, select-two-to-compare). Live `Dental/Imaging.vue` is a **2D image** library (manual upload + viewer + dentist reading). · Filters, compare-selection and the tray axis are net-new. | **(b)** scan/mesh model + tray/setup concepts · **(a)** filters/selection UI | b: **High** · a: **Low** |
| **Scan Upload** | Mock is a **live intraoral scanner stream** building a mesh, with **per-tooth coverage flagged** ("Well covered / Thin / Gap · recapture"). Live is a file upload. · Live capture needs a **vendor scanner SDK** — an explicitly deferred, partner-gated long pole. · Coverage flagging is a computed quality verdict on clinical imagery. | **(c)** coverage flagging · **(b)/(c)** live capture = partner seam | **Must-not-build as drawn** · capture: **partner** |
| **Scan Comparison Viewer** | Planned-vs-actual **superimposition with measured deviation** and per-tooth "beyond 0.5 mm" flags. Mock's own caption says *"geometry, not diagnosis"* — an honest framing, but it still needs a **3D registration/comparison pipeline** that does not exist. · **NO LIVE PAGE.** | **(b)** 3D pipeline (substantial) · **(c)** the flagging threshold is a clinical call | b: **High** · c: **Guard** |
| **Ortho Progress** | **"the agent compares planned vs actual movement and flags what's off-track"** — a computed treatment-tracking judgment. · Tray sequence, refinement window, debond projection. · **NO LIVE PAGE**; ortho/aligner tracking is an explicitly DEFERRED dental long pole. | **(c)** off-track flagging · **(b)** aligner/tray model | c: **Must-not-build** · b: **High** |
| **Chair Scheduling** | Chairs-as-columns day/week lens with **utilisation %**, **turnover buffers**, and booking guarded by **`chair.capabilities ⊇ service.requirements`**. · `Resource::TYPE_CHAIR` **already exists**, so chairs are modelled — but there is **NO capability field** on `Resource` (the same gap APPT.P4 recorded). · **NO LIVE PAGE**; chair-view is a deferred dental gate. · Any booking here **must** go through the real slot-finder + `lockResource`/`assertNoOverlap`. | **(b)** capability field + turnover buffer + utilisation query · **(c)** booking-must-use-the-real-guard | b: **Med** · c: **Guard** |
| **Inventory & Sterilization** | Autoclave cycles, **indicator-confirmed release that fails closed**, **instrument→patient traceability**, quarantine on failed indicator, par levels, expiring lots, **agent-drafted reorders (human-approved)**. · **NOTHING exists**: no sterilisation/reprocessing model anywhere in `Modules/`. Surgery/Pharmacy inventory are different things. · **NO LIVE PAGE.** | **(b)** an entire reprocessing subsystem · **(c)** reorder agent must ride ApprovalQueue | b: **Substantial** · c: **Guard** |

---

## 3 — SHARED COMPONENTS (build once, reuse) — *the sequencing key*

The single most useful output of auditing these as a batch: **six components carry most of the visual work**, and
each appears on 3–9 screens. Building them per-screen would be the expensive mistake.

| # | Component | Appears on | Notes |
|---|---|---|---|
| **S1** | **Patient clinical header** — avatar, name, MRN, age, allergy chip, context line ("SPT visit · today · probed by …") | **9 of 13** (Odontogram, Perio, Endo, RCT, Crown, Plan, Scan Library, Scan Upload, Ortho) | The most repeated element in the batch. The allergy chip must render the **recorded** allergy only (ALLERGY.P1) — never a computed cross-reactivity. |
| **S2** | **Tooth-arch widget (FDI)** — quadrant layout, selection ring, per-surface fill language, chart key | **6** (Odontogram, Perio, Endo, RCT, Crown, Ortho) | Already exists in `Odontogram.vue`; needs extracting to a shared component. Mixed dentition (S2b) is an extension, not a second widget. |
| **S3** | **Specialty tab strip** — Odontogram / Perio / Endo / Imaging | **5** | Pure navigation; trivial once the routes exist. |
| **S4** | **Clinical stat-tile row** — the 3–4 tiles under the header | **8** | ⚠️ **The tiles are where the fence gets breached.** The *shell* is shared and safe; what goes in it is per-screen and must be a **recorded count**, never a computed index (see §5). |
| **S5** | **Procedure/phase card** — code, description, tooth, fee, status pill | **4** (Plan, Crown, RCT, Fee Schedule) | Money inside it must be engine-supplied. |
| **S6** | **Scan/image tile + viewer shell** — thumbnail grid, filters, selection, zoom | **4** (Scan Library, Upload, Compare, Ortho) | The 2D viewer exists in `Imaging.vue`; the 3D/mesh variants do not. |

**Consequence for sequencing:** S1 + S3 + S4-shell + S5 are cheap, reusable, and unlock visual parity across 9
screens. **Do them first, in one gate.** S2 is an extraction of existing code. S6 splits into "reuse the 2D
viewer" (cheap) and "3D mesh" (a partner-gated long pole — do not attempt).

---

## 4 — SHARED BACKEND GAPS (fix once, unlocks many)

| # | Gap | Unlocks | Size |
|---|---|---|---|
| **B1** | **Mixed dentition** — primary teeth (5x–8x), eruption state, sealants on `ToothRecord` | Odontogram (paediatric), and any paediatric view later | **Med** |
| **B2** | **Fee-schedule versioning + effective dating** (+ optional tax-point × point-value pricing) | Fee Schedule Editor, Treatment Plan estimate provenance, invoice-keeps-its-schedule | **High** — and the point-value half is the **Swiss SSO licensed tariff**, a deliberate non-inclusion. Version/effective-date the tenant's own catalog; do **not** bundle a licensed code set. |
| **B3** | **Structured procedure records** — per-canal endo (working length, irrigant, obturation), prep measurements (reduction per surface, ferrule, taper, shade, margin, cement, lab) | RCT + Crown Prep, and any future procedure screen | **Med** |
| **B4** | **`Resource` capability field** + service requirements | Chair Scheduling **and** the already-recorded **APPT.P4** room-capability gap | **Med** — *one field closes two backlog items.* |
| **B5** | **3D scan / mesh model** (capture, tray/planned-setup, registration) | Scan Library (3D), Scan Upload (live capture), Scan Comparison, Ortho Progress | **Substantial + partner-gated** (vendor scanner SDK). **Recommend: do not build.** |
| **B6** | **Sterilisation / reprocessing subsystem** (cycles, indicators, load→instrument→patient traceability, quarantine, par levels, lot expiry) | Inventory & Sterilization | **Substantial** — an entire subsystem, nothing exists |

**B4 is the standout: a single field closes a gap recorded twice** (here and in APPT.P4).

---

## 5 — THE FENCE VERIFICATION (the crux)

### 5.1 MUST-NOT-BUILD-AS-DRAWN — computed clinical judgment

Every item below would require CareOS to **compute a clinical judgment** rather than record a fact. All are
permanent non-goals or certified-partner seams. **None may be built as drawn.**

| Screen | Item as drawn | Why it is refused |
|---|---|---|
| Odontogram | **DMFT / dmft index**, live-recomputing | A computed caries index. **Already ruled on** — the live app deliberately omits it and the QA audit recorded it as *"correctly MORE REAL than the mockup — do NOT fix."* This audit **re-confirms**, it does not re-open. |
| Odontogram | "1 finding" count · "Flagged · one site to watch" | A computed salience judgment over clinical findings. Same prior ruling. |
| Perio | **BOP % · sites ≥4 mm · mean depth · plaque score**, "totalled by the agent" | Computed perio indices. DENTAL.G6 stores **raw per-site measurements only**; a recursive test forbids stage/grade/severity/risk/flag/worsening keys. |
| Perio | **Trend arrows** ("▼ from 3.1", "▼ 19 pts", "plateau") | A computed trend verdict. A raw value-over-time list is permitted; a labelled direction is not. |
| Perio | **"One site to watch"** — "the agent flagged it for re-evaluation"; "deepened against an improving mouth" | The clearest computed clinical judgment in the batch. The dental map already ruled: build as a **raw delta (3→5)**, never a flag. |
| Perio | **Colour bands** (1–3 / 4–5 / 6 mm+) | A severity ramp on clinical values — the ranges-DISPLAYED-not-FLAGGED rule. A numeric grid is fine; a red/amber ramp is not. |
| Perio · Endo · Crown | **"Corroborated on today's bitewing — bone loss confirmed"**; "periapical radiolucency ~4 mm, loss of lamina dura"; "margin fully captured, no voids" | **AI imaging findings = CADe/CADx = a regulated medical device.** A HARD permanent non-goal. The dentist reads the image; the system records what they wrote (`DentalImageReading`). |
| Endo | **Agent-proposed two-axis diagnosis, "High confidence"** | Auto-diagnosis + a confidence score. DENTAL.G7 is explicitly *dentist-authored, no AI, no auto-differential*. |
| Endo | **Differential** — "Ruled out / Confirmed / Less likely" | An auto-ranked differential. |
| Endo | **"Penicillin allergy blocked amoxicillin → clindamycin 300 mg qds"** | **Drug substitution + dosing.** Dosing logic is forbidden by the fence's first line; allergy cross-reactivity is a certified-partner seam (`MedicationSafetyProvider`), advisory and never auto-blocking. |
| Endo · Crown | **Prognosis "Favourable"** · **"Recommended pathway: RCT + crown"** · **"Agent's restoration Rx: monolithic zirconia"** | Computed prognosis, treatment recommendation and material selection. |
| Crown | **Reduction gauged vs a material minimum** — "0.9 vs 1.0 mm minimum", "1 surface to refine", "slightly under-reduced" | A pass/fail clinical verdict against a threshold. Recording the measured values is fine; grading them is not. |
| Crown | "Articaine — **no cross-reactivity** with her penicillin allergy" | Cross-reactivity reasoning — partner seam, permanent non-goal in-house. |
| RCT | **"Agent flag: a 4th canal (DL) is likely"** · computed working lengths · "Agent's obturation plan" | A predicted anatomical finding + a computed clinical protocol. |
| Scan Upload | **Per-tooth coverage flagging** ("Thin", "Gap · recapture") | A computed quality verdict on clinical imagery. |
| Scan Compare | **"Beyond 0.5 mm"** deviation flagging | The mock calls it *"geometry, not diagnosis"* — the measurement may be geometric, but **the threshold is a clinical call**. Show the measured deviation; do not flag it. |
| Ortho | **"the agent … flags what's off-track"** | A computed treatment-tracking judgment. |

**What IS safely buildable from these screens:** the recorded facts underneath — pulp-test results per tooth
against named controls, percussion/palpation/mobility/probing values, canals found and working lengths **as
charted by the clinician**, prep measurements as recorded numbers, an authored two-axis diagnosis from the
tenant's own term pick-list, and a dentist-authored reading on any image.

### 5.2 MONEY — engine-computed, never page-computed

| Where | Item | Required path |
|---|---|---|
| Treatment Plan | Total estimate (CHF 1,240) · per-phase CHF · "CHF 104 of 1,240 billed" | Every figure from the **billing engine**; no page-side sum. Phase billing at completion goes through `DentalChargeService` → `ChargeCaptureService::captureManual` → the existing invoice flow, reconciling **δ=0**. |
| Treatment Plan | "On a payment plan · 4 × CHF 310 · 1 paid" | The **ARDETAIL.P5** payment-plan model — installments tie to the real outstanding, δ=0. Display only. |
| Fee Schedule | Position price = **tax points × point value** | If B2 is ever built, the multiplication belongs in the **engine**, not the page — and a point-value change must re-price by **effective date**, with issued invoices keeping their schedule. |
| Crown Prep | Lab cost / turnaround | Not money in the ledger sense; if it becomes a charge it goes through the engine. |

### 5.3 SCHEDULING — the real guard

**Chair Scheduling** books a chair. Any booking **must** go through the real `AvailableSlotFinder` +
`BookingService::book` → `lockResource` → `assertNoOverlap` in one transaction. The mock's
`chair.capabilities ⊇ service.requirements` rule must be **server-enforced** (it needs B4), never a page-side
filter. Turnover buffers must come from real availability, not a drawn gap.

### 5.4 AGENT — the capped ApprovalQueue path

The mock's agent is pervasive. Where an agent element survives the fence at all (Treatment Plan's *drafted
sequence*, Inventory's *drafted reorder*), it must ride the **real capped path**: the agent **DRAFTS**, a
**HUMAN commits**, autonomy = MIN(configured, tool ceiling, role ceiling), and it never auto-sends or auto-books.
Every "agent proposes → clinician confirms" flow in these screens is only acceptable **after** its content has
passed §5.1 — and for the clinical screens, it does not.

---

## 6 — CORRECTLY-MORE-REAL — keep, do not trim

| Item | Why it stays |
|---|---|
| **Odontogram omits DMFT, the finding count and the "site to watch" flag**, and says so on the chart key | The prior QA audit recorded this explicitly as a **correct divergence, not drift**. |
| **`Odontogram.vue`'s categorical chart key** — "a FACTUAL charted-condition legend, NOT a severity ramp… no score/grade/gradient anywhere" (in-code comment) | The fence made visible in the UI. |
| **PerioChart stores raw per-site values with no indices** and a test forbidding judgment keys | DENTAL.G6/D-104. |
| **`Diagnoses.vue` is a dentist-authored record over a tenant-authored term pick-list**, with no AI and no differential | DENTAL.G7. |
| **`Imaging.vue` has a manual upload + a dentist-authored reading**, and a test forbidding `ai/finding/detected/confidence/analysis/…` | DENTAL.G8. |
| **Fee schedule is the tenant's own catalog over the existing tariff engine**, computing no prices, bundling no licensed code set | Avoids both a second pricing engine and a licensing problem. |
| **Real German/Swiss demo data, role-gated nav, honest empty states** | vs the mock's fixed nav and fabricated content. |

---

## 7 — PROPOSED FIX CHAIN

**Recommended scope: the 9 screens with a live page. Leave the 4 without one.**

The four no-live-page screens (Scan Comparison, Ortho Progress, Chair Scheduling, Inventory & Sterilization) are
**net-new subsystems**, three of them already deferred by decision (ortho/3D-scan long poles; chair-view a later
dental gate) and one entirely unbuilt (reprocessing). They should stay deferred and be pulled forward only by a
customer need — **not** as parity work.

| Gate | Builds | Proves |
|---|---|---|
| ~~**DENTAL-B.P1**~~ ✅ **DONE** | **Shared components** — S1 patient clinical header, S3 specialty tab strip, S4 stat-tile **shell**, S5 procedure/phase card. Extract S2 tooth-arch from `Odontogram.vue` into a reusable component. | Purely presentational (P0D.GU); every existing dental test stays green; the tile shell carries **no** computed value. |
| ~~**DENTAL-B.P2**~~ ✅ **DONE** | **Odontogram visual parity** — Read/Chart toggle, per-tooth detail rail with history, US-notation cross-reference, chart-key polish. **DMFT/finding-count/flag stay omitted.** | The fence assertions still pass; the omissions are re-asserted, not quietly reintroduced. |
| **DENTAL-B.P3** | **Perio visual parity** — grid ergonomics, entry flow, raw value-over-time. **No indices, no trend labels, no colour bands, no site-to-watch.** | A recursive no-judgment assertion over the page payload, as DENTAL.G6 does today. |
| **DENTAL-B.P4** | **Treatment Plan parity** — phase timeline, goal narrative, consent + provenance rail, payment-plan link. All money engine-supplied. | Estimate/billed figures tie to the engine **δ=0**; no page-side arithmetic (adversarial grep). |
| **DENTAL-B.P5** | **Fee Schedule parity (visual half)** — category grouping, active/retired status, layout. **B2 versioning is a separate decision.** | The catalog stays tenant-authored; the agent still cannot edit it. |
| **DENTAL-B.P6** | **Scan Library / Upload parity over the 2D backend** — filters, selection, viewer polish. **No coverage flagging, no live capture.** | The imaging fence test still passes; no `ai/finding/confidence` key appears. |
| **DENTAL-B.P7** *(optional)* | **B3 structured procedure records** — per-canal endo + prep measurements as recorded facts, surfacing the RCT/Crown screens' *recordable* half only. | Values recorded, never graded; no gauged-vs-minimum verdict anywhere. |
| **DENTAL-B.P8** *(optional, separate)* | **B4 `Resource` capability field** — closes this batch's chair gap **and** APPT.P4. | Capability match enforced **server-side** in the booking path. |

**Realistic gate count: 6 core + 2 optional = 6–8 gates.** Roughly **one-third of the visual surface is
S1/S3/S4/S5**, which is why P1 comes first.

### P1 outcome (2026-08-20)

Shipped as `resources/js/Components/Dental/` — `PatientClinicalHeader.vue` (S1), `ToothArch.vue` +
`toothConditionColour.ts` (S2), `ClinicalStatTile.vue` (S4), `ProcedureCard.vue` (S5). Only Odontogram was
rewired (to S2); S1/S4/S5 are registered for P2–P6 to adopt.

**Correction to §3 — S3 was already built.** The specialty tab strip exists as
`resources/js/Components/DentalSectionNav.vue`, shipped at DENTAL.G9 with all five tabs on the real routes and
already i18n'd. P1 therefore did **not** rebuild it. Its per-tab role-gating was also examined and deliberately
left alone: all five targets (`dental.chart`, `dental.perio`, `dental.diagnoses`, `dental.plans`,
`dental.imaging`) are gated identically at `patient.view`, so gating tabs individually would invent a
distinction the routes do not make. `/dental/fee-schedule` is `billing.manage`-gated and correctly absent from
the strip.

**§3 also overstated the shared-component count.** Six were listed, but S6 (the scan tile) was never in P1's
scope — §3 itself splits it into "reuse the 2D viewer" and a partner-gated 3D long pole. P1 delivered **four new
components covering S1/S2/S4/S5**, with S3 pre-existing.

**S2 is proven behaviour-identical**, not merely believed to be: the Odontogram chart card was captured from a
real browser before and after the extraction — **18109 normalised characters, byte-for-byte identical**, all 32
teeth matching on number, classes, computed opacity, all five per-surface background colours and the whole-tooth
mark, with full-page text identical. The existing Odontogram tests were not modified.

**S4 is deliberately closed** — no slot, no tone/trend/severity prop, no arithmetic — because §3 flagged the
tiles as where the fence gets breached. `tests/Feature/Dental/SharedComponentsTest.php` asserts that absence
structurally, and the assertions were mutation-checked (adding a `trend` prop and a `Number(value) / 100`
both fail the suite).

### P2 outcome (2026-08-20)

Read/Chart toggle, per-tooth detail rail, US-notation cross-reference and chart-key polish, all over the
existing backend. **What the rail can show is bounded by what `tooth_records` holds**: tooth, surface,
charted condition, note, correction reason, charted-by and charted-at (append-only; current = latest per
tooth+surface). There is no per-tooth findings count, severity, imaging link, probing depth or eruption
state, so none is shown.

- **Read mode is a UI mode, not a permission.** The server still authorises every write on `dental.chart`,
  the client sends no mode the server reads, and a test proves a forged `mode` parameter changes nothing in
  either direction.
- **`ToothNotation::universal()`** is a deterministic, total FDI→Universal lookup with the ADA definition
  documented in-code, verified a bijection over all 52 teeth and computed in the domain.
- **No stat tile was added, deliberately.** The gate permitted S4 "if any factual count is shown" — but the
  only counts available on this page are counts over clinical findings, i.e. the "1 finding" chip §5.1 rules
  out. An empty tile beats a fence breach.
- **§5.1 re-asserted:** browser-verified that the only occurrence of "flagged" on the rendered page is inside
  the §6 note itself.
- **B1 (mixed dentition) untouched**, as scoped. The S1 allergy chip is left unused — sourcing Clinical
  allergies into the dental payload is a new cross-module read and belongs to a later gate.

**A P2 mutation exposed a real hole in the P1 fence scan**: a camelCase `siteToWatch` identifier passed both
suites, because `\bwatch\b` does not match inside `sitetowatch`. Both suites now also scan a
non-alphanumeric-stripped copy of the source for compound §5.1 phrases. Bare `watch` stays permitted — it is
Vue's own reactive primitive.

**Explicitly NOT in the chain:** every §5.1 item; B5 (3D/mesh, partner-gated); B6 (reprocessing subsystem); the
Swiss SSO point-value tariff (licensed).

---

## 8 — Bottom line

- **13 decoded, 13 audited. 9 have a live page; 4 do not.**
- **The batch's dominant finding is not visual drift — it is that the mock's clinical intelligence must be
  refused.** ~20 distinct computed-judgment items across 8 screens are MUST-NOT-BUILD-AS-DRAWN, including three
  separate AI-imaging-finding surfaces and one antibiotic substitution with dosing.
- **The live dental build already made every one of those calls correctly**, and its tests lock them. Nothing in
  this audit asks for a fence change.
- **The genuine parity work is modest and front-loaded:** six shared components carry most of it, over four
  screens whose backends already exist.
- **Four screens should stay deferred**, three of them by decisions already recorded.

**Nothing was fixed. No gate was opened.** If this chain is issued, DENTAL-B.P1 (shared components) is the
cheapest, highest-leverage first commit.
