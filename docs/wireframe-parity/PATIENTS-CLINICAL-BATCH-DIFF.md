# Patients & Clinical Batch — Wireframe-Parity Diff (12 screens, audit only)

**AUDIT + REPORT ONLY. Nothing was fixed, no app code changed, no gate opened.**
`resources/prototype/` stays gitignored.

- **Date:** 2026-08-21 · **HEAD:** `7d7f354` (DENTAL-B.P6) · **CI:** `check -> completed / success` · tree clean.
- **Env:** `migrate:fresh --seed` + `DemoClinicSeeder` + `DemoHospitalSeeder`, Redis up. Verified BY QUERY:
  Praxis Lindenhof — 15 patients · 3 allergies · 15 notes · 14 encounters; Klinik Bergblick — 8 · 0 · 5 · 5.
- **Scope:** the 12 "Patients & clinical" screens from `WIREFRAME-INVENTORY.md`, decoded and audited as ONE batch.
- **Carried forward from the Dental batch:** D-166 (a stat tile is CLOSED), D-169 (a severity ramp needs no
  judgment word — the rule lives in the STYLING), D-170 (money the engine can't source is OMITTED; an agent
  that doesn't exist is NOT invented), D-171 (no licensed code set bundled), D-172 (on an image, the breach
  is DRAWING).

> ## ⚠️ THE HEADLINE
>
> **This domain is the opposite of the Dental batch, and that is the finding.**
>
> Dental's mocks were built around an agent that diagnoses, and almost nothing could be built as drawn. The
> Patients & Clinical mocks are, screen for screen, **the most fence-aware in the pack** — they state the
> boundary themselves ("the agent monitors, it doesn't decide"; "it rephrases — it doesn't author"; "vitals
> deliberately stay trend-free — a sparkline would already be interpretation"; "extractive only — interpretive
> requests are refused"). Several describe, almost exactly, mechanisms the live build **already has**.
>
> **So the work here is mostly real, and mostly buildable.** Six of the twelve screens have a live page and need
> visual parity over a backend that already carries the data. Four more have a real backend and no page.
>
> **Two exceptions carry the whole fence risk**, and they are severe:
>
> - **Allergy Alert** asks CareOS to compute a **drug-class cross-reactivity determination** and **hard-block a
>   prescription**. That is the `MedicationSafetyProvider` seam — an interface whose only implementation is a
>   null object. Even a certified partner's findings are **advisory and human-owned, never auto-blocking**.
> - **Care Plan Review** re-imports the entire perio computed rail that `DENTAL-B.P3` refused eight days ago —
>   BOP %, "sites ≥ 4 mm", trend arrows, "one site to watch", **and an AI bitewing finding**.

---

## 1 — The 12 screens

All 12 decoded to `resources/prototype/patients-*.wireframe.html` (standard pipeline: bundler loader
reconstructed → base64/gunzip manifest+template → UUID-substitute → headless render → post-render DOM).
**12/12 rendered clean**, no placeholder artefacts.

| # | Screen | Purpose (one line) | Live route · Vue |
|---|---|---|---|
| 1 | **Patients Index + Register** | Find a record by name/DOB; a 4-step registration wizard whose signature moment is duplicate detection | `patients`, `patients/register` · `Patients/Index.vue` |
| 2 | **Patient 360** | The administrative record: header band + five fixed tabs (demographics · contacts · coverages · consents · access) | `patients/{patient}` · `Patients/Show.vue` |
| 3 | **Patient Chart** | The clinical cockpit: encounter timeline + clinical rails + a source-linked AI summary | `clinical/chart/{patient}` · `Clinical/Chart.vue` |
| 4 | **Note Editor** | SOAP note focus mode; signing is irreversible and amendment creates a superseding version | `clinical/notes/{note}/edit` · `Clinical/NoteEditor.vue` |
| 5 | **Patient Access Log** | The read-audit rows this record's own views write — who opened it, when, on what basis | ◐ **partial** — an "access" tab inside `Patients/Show.vue`; no dedicated route/gate |
| 6 | **Allergy Alert** | A hard safety stop: a documented severe allergy blocks a prescription, with no override | ◐ **safe part only** — the recorded-allergy display in `Clinical/Chart.vue` |
| 7 | **Care Plan Review** | The periodic "is it working?" review of an ongoing plan; the agent trends outcomes, the clinician signs | ⚪ **NO LIVE PAGE** (CarePlan backend exists) |
| 8 | **Consult Summary** | A plain-language after-visit recap drafted from the signed note; the clinician signs before release | ⚪ **NO LIVE PAGE** (summary agent exists) |
| 9 | **Medical History Intake** | Patient-facing confirm-or-update history; a new anticoagulant is the moment that matters | ⚪ **NO LIVE PAGE** |
| 10 | **Recall Due List** | The recall agent's worklist — who's due, what it prepared, what it handed to a person | ⚪ **NO LIVE PAGE** (recall engine + cron exist) |
| 11 | **Referral Out** | A clinician-authored referral; the agent assembles the packet and tracks the report back | ⚪ **NO LIVE PAGE** (Referral model exists) |
| 12 | **Patient Flow** | Admin reporting: base movement, acquisition, appointment funnel, visit mix, volume | ⚪ **NO LIVE PAGE** |

**4 have a full live page · 2 are partially live · 6 have none.** Note the inventory recorded Access Log and
Allergy Alert as live; verified against code, both are **partial** — corrected above and in §2.

### The real backend these screens sit on

`Modules/Clinical` carries **18 models**: `Allergy` · `CarePlan` / `CarePlanGoal` · `ClinicalNote` ·
`ClinicalTask` · `Document` · `Encounter` · `Medication` · `NoteTemplate` · `Order` / `OrderResult` /
`OrderableItem` · `Problem` · `Recall` / `RecallRule` · `Referral` · `TextSnippet` · `Vital`.

`ClinicalChartController` already assembles: allergies, **the medication-safety seam**, vitals **and** a unified
`vitalsHistory` ("raw values only (no bands/flags/scores/deltas)"), medications, documents, **carePlans**,
**referrals**. The recall engine runs on a schedule (`clinical:evaluate-recalls`). `ClinicalSummaryTool` is
registered at `autonomyCeiling: AutonomyPolicy::SUGGEST` and is documented as an **ABSOLUTE CONSTRAINT:
EXTRACTIVE** — "it does not interpret, diagnose, infer, prioritize clinically, or add unsourced text."

**Real model shapes** (what a screen can actually show):
`Referral` = patient · encounter · direction · to/from_provider_name · to_branch_id · specialty · reason ·
status · sent_at · responded_at · notes. `CarePlan` = patient · title · status · started_on · ended_on ·
created_by; `CarePlanGoal` = description · target_date · status. `Recall` = patient · rule_id · due_on · status.

---

## 2 — Per-screen diff

Classification: **(a)** visual · **(b)** backend gap · **(c)** fence MUST-NOT-WEAKEN · **(d)** correctly-more-real.

| Screen | Key deltas | Class | Severity |
|---|---|---|---|
| **Patients Index + Register** | Mock: 3-control search, 4 data columns + status, silent 25-cap with a "showing the first 25" line, match highlight; a 4-step wizard (identity · contacts · coverage · review) with **debounced duplicate detection at 300 ms** rendering candidates with High/Medium match labels inside step 1, and a single "this is a new patient — continue". Live has index + register and a `patients/duplicates` endpoint. Deltas are the wizard's step chrome, the review step, and the candidate presentation. · ⚠️ "High match / Medium" is a **similarity grade on identity, not a clinical judgment** — permissible, but it must come from the existing server-side duplicate check, never a page-side score. | **(a)** mostly · **(b)** wizard/review step | a: **Low** · b: **Low–Med** |
| **Patient 360** | Mock: dark hero band, status pill, **Flag chip**, portal-invite + edit actions, five fixed tabs with count chips, consents tab with grant/withdraw + signature capture and immutable snapshots, access-log tab as a human-readable day-grouped timeline. Live already has the page, **all five tabs with count chips** (verified in `Patients/Show.vue`) and the consent grant/withdraw routes, and writes `auditRead(surface: patient_360)` on every render. · **The mock itself notes the AllergyBanner "binds to a chart-sourced allergies prop (not yet in Patients/Show's payload)"** — the same gap DENTAL-B.P1's S1 chip has. | **(a)** hero/tabs/chips · **(b)** allergies prop (see B1) | a: **Low** · b: **Med** |
| **Patient Chart** | Mock: dark patient band with factual counts ("3 encounters · 2 problems · 2 medications · 1 open recall"), pinned AllergyBanner, seven tabs with counts, find-in-chart, encounter timeline with type filters + month groups + note-state badges + **full version chains (v1 signed always reachable)**, referrals & recalls rail with recall proximity ("in 66 days"), and the **AI chart summary** — "extractive only · every line carries its source · interpretive requests are refused", Refresh at suggest ceiling, one explicit "Insert into my draft note". · **The mock explicitly keeps vitals trend-free**: "a sparkline would already be interpretation". | **(a)** most of it · **(d)** the summary + trend-free vitals already match | a: **Low–Med** · d: **keep** |
| **Note Editor** | Mock: focus mode, encounter rail, **version list with v1 signed / v2 draft + amendment reason**, required-section markers driven by the template with a footer count ("2 of 3 required sections filled"), autosave chip, a **type-SIGN-to-confirm** modal, signed read-only rendering with "no edit cursors, no delete affordances — anywhere", and an amend flow requiring a reason. Live has the editor, sign, amend routes. Deltas are chrome, the confirm modal and the required-section counter. **CORRECTED AT PC.P4:** the type-to-confirm modal, the required-section counter and the autosave chip were ALREADY LIVE when this audit was written — `CLINIC.W5` (`1e4b7c0`) predates the audit commit. The real deltas were narrower: per-section required/filled markers, the superseded-version banner, and the dormant allergy banner. | **(a)** all of it | **Low** |
| **Patient Access Log** | Mock is a **dedicated screen** at `patients/{patient}/access-log` gated `patient.audit.view`, with range facets (7/30/90/all), **access-basis facets** (care team · reception & billing · patient self · agent · operator mode), an "only notable" filter, per-agent-read **min-necessary field disclosure**, an expanded **operator-mode read** carrying owner-approval + scope + a 0-changes assertion + a link to the session receipt, and **"Export access report"** — a signed report under **nDSG Art. 25 / GDPR Art. 15**. Live has an access tab inside Patient 360 only — but the **rows already come from a real service**, `PatientAccessReport::forPatient()`. The gap is the dedicated surface, not the data. · Counts (23 people · 142 accesses · 3 agent reads) are **factual counts over audit rows**, not judgments. | **(b)** dedicated route + gate + facets + export · **(b)** operator-mode detail depends on **deferred** Operator Mode G4–G11 | b: **High** |
| **Allergy Alert** | **The batch's sharpest fence.** Mock: a modal that names a **drug-class cross-reaction** ("Amoxicillin — aminopenicillin" vs "Penicillin — amoxicillin cross-reacts"; "Class: All penicillins — incl. amoxicillin, co-amoxiclav"), **hard-blocks the prescription with no override**, and offers an agent-surfaced **"Safe alternative — Clindamycin 300 mg · no conflict"** from "practice antibiotic guidance". · The live build has the **recorded-allergy display** and the seam's honest "not configured" state. | **(c)** the determination, the block, the alternative | **Must-not-build** |
| **Care Plan Review** | Re-imports the perio computed rail refused at DENTAL-B.P3: **BOP % with "▼ 19 pts"**, **"sites ≥ 4 mm" count with "▼ from 18"**, **plaque score "plateau"**, **"One site to watch — tooth 26 mesial · Deepened 4 → 5 mm ... Flagged"**, plus **"Bitewing · bone loss confirmed"** (an AI imaging finding), a **guideline-derived interval recommendation**, and an agent **"SUGGESTED DISPOSITION"**. · Genuinely buildable underneath: the plan itself, its goals, the review as a signed record, adherence as a **count of attended vs scheduled recalls**, and raw per-visit measures. | **(c)** indices, trends, site-to-watch, imaging finding, guideline recommendation · **(b)** review record, interval, outcome-measure series | c: **Must-not-build** · b: **High** |
| **Consult Summary** | The best-behaved screen in the batch. Agent drafts a **plain-language translation of the signed note** — "it rephrases, never authors: no finding, instruction, or date appears here that isn't in the note" — clinician **signs before release**, delivery honours the **consented** clinical-comms channel, PDF filed to the record. · Needs: a summary record with a lifecycle (drafted → signed → released), portal delivery, PDF filing. | **(b)** the record + release path · **(c)** must stay extractive + human-signed | b: **Med–High** · c: **Guard** |
| **Medical History Intake** | Patient-facing confirm-or-update (pre-filled, never blank), structured fields only, **"no free-text symptom triage"** — the mock says so itself. Then the clinician side: the agent surfaces **"New medication · Rivaroxaban — Anticoagulant. Relevant to today's cleaning ... Review bleeding-risk before invasive procedures; consider INR/timing per protocol"** tagged `interaction:anticoagulant`, `ceiling: flag-only`, with "Acknowledge before treatment". · The **flag-only, never-blocks, human-acknowledges** shape is right; the **content** of that flag is a drug-class clinical inference. | **(c)** the interaction inference + its dosing/timing advice · **(b)** patient-facing intake + the acknowledgement record | c: **Partner-seam** · b: **High** |
| **Recall Due List** | Mock: a worklist of due/overdue recalls with type filters, per-row consented channels, and **agent status** — drafted · auto-sent at L1 · handed to a person · **"consent — can't send"**. "Nothing here is a clinical decision; the agent schedules and writes, people handle the judgement calls." · Live has the recall engine + cron but **no page**, and `Recall` carries no channel/consent/draft linkage. | **(b)** the page + draft/channel/consent linkage · **(c)** auto-send must respect the real ceiling + consent | b: **High** · c: **Guard** |
| **Referral Out** | Mock: clinician-authored reason + **urgency**, a **tracker** (sent → received → appointment → report back), an agent that **assembles the packet and chases**, and a **shared/withheld panel** ("minimum necessary") with an explicit patient consent to the share. · `Referral` has reason/status/sent_at/responded_at but **no urgency, no packet/attachment model, no consent-to-share link, no chase**. | **(b)** urgency, packet, consent-to-share, tracking states · **(c)** the share is consent-gated; the agent may not choose who to refer | b: **High** · c: **Guard** |
| **Patient Flow** | Pure admin analytics: active base, new/reactivated/lapsed, **base-movement ledger** (opening → +new → +reactivated → −lapsed → closing → net growth) with a % column, acquisition sources, an appointment funnel with no-show/cancellation/show rates, visit mix, volume. · **Money appears**: "New-patient value CHF 1'240 avg first-year value", "First-visit production CHF 320". · No such report exists live. | **(b)** the whole report · **(c)** money must be engine-computed | b: **Substantial** · c: **Guard** |

---

## 3 — SHARED COMPONENTS: reuse vs genuinely new

**Reuse — already built, do not rebuild:**

| Component | Where it exists | Notes |
|---|---|---|
| **S1 patient clinical header** | `Components/Dental/PatientClinicalHeader.vue` (DENTAL-B.P1) | Appears on **8 of 12** screens here. Already takes identity + MRN + DOB/age + a **recorded** allergy chip + a context line. **Its allergy chip is still unwired** — see B1. Should be promoted out of `Components/Dental/` to a shared clinical namespace. |
| **`AllergyBanner.vue`** | `resources/js/Components/` | The full-width recorded-allergy banner the Chart already pins. Distinct from S1's compact chip; both are display-only (ALLERGY.P1). |
| **`Timeline.vue`** | `resources/js/Components/` | The Chart encounter timeline and the Access Log day-groups are the same shape. |
| **`DataList.vue`**, **`Card.vue`**, **`StatCard.vue`**, **`Tabs.vue`**, **`StepNav.vue`**, **`EmptyState.vue`**, **`VersionHistory.vue`**, **`SoapEditor.vue`** | `resources/js/Components/` | `StepNav` is the register wizard; `VersionHistory` + `SoapEditor` are the Note Editor; `Tabs` drives both 360 and Chart. |
| **`ClinicalStatTile.vue`** | `Components/Dental/` (DENTAL-B.P1) | The **closed** tile (D-166) — use it for the factual counts on Chart/Access Log, never for a clinical index. |

**Genuinely new (build once, reused across the batch):**

| # | Component | Used by | Notes |
|---|---|---|---|
| **N1** | **Clinical rail card** — a titled list card with a workflow-status pill (problems · medications · care plans · referrals · recalls) | Chart, 360, Care Plan Review, Referral Out | Status pills are **lifecycle** facts, never clinical grades. |
| **N2** | **Encounter timeline entry** — type badge, note-state badge, version chain link, month grouping | Chart, Care Plan Review | Extends `Timeline.vue`. |
| **N3** | **Audit/access row** — actor avatar, basis chip, surface, time, expandable detail | Access Log, 360 access tab | Basis is **server-derived**, never self-declared. |
| **N4** | **Agent draft panel** — the ✦ block: label, per-line **source chips**, Refresh, and one explicit human commit | Chart summary, Consult Summary, Care Plan Review, Recall list | The single most repeated agent surface. Must render the real ceiling and never auto-apply. |
| **N5** | **Consent state card** — granted/withdrawn, version, captured-by, withdrawal reason, immutable snapshot note | 360, Consult Summary, Referral Out, Recall list | Consent already exists in the backend; this is its display. |
| **N6** | **Sign-off bar** — the "dark moment": type-to-confirm, required-section count, server re-check | Note Editor, Care Plan Review, Consult Summary | One pattern, three screens. |

---

## 4 — SHARED BACKEND GAPS (fix once, unlocks many)

| # | Gap | Unlocks | Size |
|---|---|---|---|
| **B1** | **Allergies in the `Patients/Show` payload** — the cross-module Clinical read that lights up S1's chip. The mock names this gap itself. | Patient 360, and **every** screen using S1 (8 here + the dental pages). Assessed: Clinical is an allowed dependency for the app layer, allergies are already read-logged on the Chart, and the chip is display-only (ALLERGY.P1) — so this is a **small, well-understood read**, not a new mechanism. Its only real cost is a patient-scoped read-audit row on a screen that already writes one. **Recommend doing it first.** | **Low** |
| **B2** | **Dedicated access-log surface** — route + `patient.audit.view` gate + **server-derived basis** + facets + range filter + **signed export** (nDSG Art. 25 / GDPR Art. 15). `PatientAccessReport::forPatient()` already returns the rows, so this is a surface + basis + export gap, not a data gap. | Patient Access Log; a real regulatory deliverable | **Med** |
| **B3** | **Care-plan review record** — a signed review with outcome, interval and next-recall scheduling; goals already exist | Care Plan Review | **Med** |
| **B4** | **Consult-summary record** — draft → signed → released lifecycle, portal delivery on the consented channel, PDF filed | Consult Summary | **Med–High** |
| **B5** | **Referral enrichment** — urgency, an attachment/packet model, a consent-to-share link, tracking states beyond `sent_at`/`responded_at` | Referral Out | **Med** |
| **B6** | **Recall dispatch linkage** — channel + consent on the recall, the drafted invite, and its agent status | Recall Due List | **Med** |
| **B7** | **Patient-facing intake** — a portal history form + a clinician-side **acknowledgement record** | Medical History Intake | **High** |
| **B8** | **Practice-analytics report** — base movement, acquisition source, appointment funnel, visit mix | Patient Flow | **Substantial** |

---

## 5 — THE FENCE VERIFICATION

### 5.1 MUST-NOT-BUILD-AS-DRAWN / partner-seam

| Screen | Item as drawn | Does the live build already refuse it? | Classification |
|---|---|---|---|
| **Allergy Alert** | **Drug-class cross-reactivity determination** ("amoxicillin cross-reacts"; "Class: all penicillins") | **YES, explicitly.** `MedicationSafetyProvider` (PHARMACY.G1) is an interface whose ONLY implementation is `NullMedicationSafetyProvider`, returning `SafetyResult::none()` for every call. Its docblock: allergy-class contraindication checking "COMPUTES a clinical-safety JUDGMENT — precisely what the electric fence refuses… A homemade version is a PERMANENT non-goal." | **Certified-partner seam** |
| **Allergy Alert** | **The hard block** — "This can't be prescribed… no override" | **YES.** The seam's contract states a partner's findings are "ADVISORY + human-owned, NEVER auto-blocking or auto-acting." So even *with* a licensed partner, the drawn behaviour is refused. | **MUST-NOT-BUILD** |
| **Allergy Alert** | **Agent-proposed "Safe alternative — Clindamycin 300 mg · no conflict"** | **YES.** A substitution + dose is dosing logic; the same refusal the dental Endo screen drew. | **MUST-NOT-BUILD** |
| **Medical History Intake** | **"Anticoagulant… Review bleeding-risk before invasive procedures; consider INR/timing per protocol"**, tagged `interaction:anticoagulant` | **YES** — same seam. The *shape* (flag-only, never blocks, human acknowledges) is correct and worth keeping; the *content* is a drug-class inference CareOS may not compute. | **Certified-partner seam** |
| **Patient 360** | **The "⚑ Flag" chip beside the name** | **Now, yes — it was the one thing on this screen that was faked.** `patients` has no flag column and nothing in CareOS records one, so the chip that shipped was a hardcoded span rendered for **every** patient. PC.P3 removed it. A flag must be a **clinician-recorded** fact before it can be displayed; deriving one from the record would make it a computed risk marker. | **Needs a recorded fact first** |
| **Care Plan Review** | **BOP %, "sites ≥ 4 mm" count, plaque score** | **YES.** DENTAL.G6/D-104 stores raw per-site values only; `PerioChartTest`'s `pcAssertNoJudgment` forbids the judgment keys, and DENTAL-B.P3 re-asserted it with a payload + component scan. | **MUST-NOT-BUILD** |
| **Care Plan Review** | **Trend labels** — "▼ 19 pts", "▼ from 18", "plateau" | **YES** — DENTAL-B.P3: a prior value may be shown as a raw number; labelling the direction is the judgment. | **MUST-NOT-BUILD** |
| **Care Plan Review** | **"One site to watch — tooth 26 mesial… Flagged"** | **YES** — refused at DENTAL-B.P3; the compound-phrase scan (`sitetowatch`) exists precisely for this. | **MUST-NOT-BUILD** |
| **Care Plan Review** | **"Bitewing · bone loss confirmed"** | **YES.** An AI imaging finding = CADe/CADx = regulated device. `DentalImagingTest` forbids `ai/finding/detected/confidence/analysis`; DENTAL-B.P6 added the **drawing** ban (D-172). | **MUST-NOT-BUILD** |
| **Care Plan Review** | **"stage III, grade B"** as a *system* attribute; **guideline → "3-month interval remains indicated"** | **PARTLY.** A stage/grade the **clinician authored** is a record (DENTAL.G7 diagnoses are dentist-authored). **Computing** it, or deriving an interval recommendation from it, is not. | **MUST-NOT-BUILD as computed** / OK as authored |
| **Care Plan Review** | **Agent "SUGGESTED DISPOSITION"** | Partially — the ApprovalQueue path exists, but no care-plan agent tool does. Per **D-170**, do not invent one. | **Omit + flag** |
| **Patient Chart** | AI chart summary | **Already built and already correct** — `ClinicalSummaryTool` is `autonomyCeiling: SUGGEST` and documented "ABSOLUTE CONSTRAINT: EXTRACTIVE… does not interpret, diagnose, infer, prioritize clinically, or add unsourced text." | **correctly-more-real** |
| **Patient Chart** | Vitals | **Already refused** — `vitalsHistory` is "raw values only (no bands/flags/scores/deltas)". The mock agrees: "a sparkline would already be interpretation." | **correctly-more-real** |
| **Consult Summary** | Plain-language recap | Extractive translation of a signed note, human-signed before release. **Buildable as drawn**, provided it stays grounded and never adds an unsourced instruction. | **Guard, buildable** |
| **Recall Due List** | Agent auto-sends at L1 | The autonomy ladder + ApprovalQueue exist. Auto-send must respect the **real** tool ceiling and consent — and "consent — can't send" must be a genuine refusal, not a styled row. | **Guard** |
| **All screens** | *No* readmission/fall/deterioration risk score, *no* computed acuity or triage level, *no* EWS/NEWS, *no* auto-generated problem list, *no* computed prognosis appears anywhere in these 12. | — | **Nothing to refuse** |

### 5.2 MONEY

Only **Patient Flow** carries money: "New-patient value **CHF 1'240** avg first-year value" and "First-visit
production **CHF 320**". Both are **derived financial metrics** (an average over a cohort). Per **D-170**: if the
billing engine cannot source them, they are **omitted and flagged**, never page-computed — and if built, the
arithmetic belongs in the engine with integer minor units, displayed as strings (the DENTAL-B.P4 pattern).
The funnel percentages (95% / 88% / 86.4%, no-show 4%) are **counts over real appointment states** — factual,
provided they are computed server-side from real rows.

### 5.3 SCHEDULING

**Care Plan Review** ("signing schedules the next recall") and **Recall Due List** both create scheduling
consequences. Any booking must go through the real `AvailableSlotFinder` + `BookingService::book` →
`lockResource` → `assertNoOverlap`. Recall *due dates* are the existing engine's (`clinical:evaluate-recalls`);
a review must feed that engine, not a parallel one.

### 5.4 AGENT

Four screens carry an agent surface (Chart summary, Consult Summary, Care Plan Review, Recall list) and one
more shows agent **reads** (Access Log). The rule is unchanged: **the agent drafts, a human commits**, autonomy
= MIN(configured, tool ceiling, role ceiling), never auto-apply. `ClinicalSummaryTool` already sits at SUGGEST.
**No care-plan-review or consult-summary tool exists** — per D-170 neither is to be invented; a gate that wants
one must add it explicitly with its ceiling.

### 5.5 CONSENT-GATED ACCESS

Three screens gate real data on consent: **Consult Summary** (portal release on the consented clinical-comms
channel), **Referral Out** (the patient consented to the share; "minimum necessary" shared/withheld), and
**Recall Due List** (a declined patient is *surfaced, not sent*). Consent already exists in the backend with
immutable snapshots and audited withdrawal — these screens must **read** that, never re-implement it.

---

## 6 — CORRECTLY-MORE-REAL — keep, do not trim

| Item | Why it stays |
|---|---|
| **The medication-safety seam renders an honest "not configured" state** and asserts nothing | ALLERGY.P1. The mock draws a confident determination; the truth is that CareOS makes no safety claim. |
| **Vitals are raw, with no bands/flags/scores/deltas** | The mock agrees a sparkline would be interpretation — the build got there first. |
| **The chart summary is extractive with per-line source chips and a SUGGEST ceiling** | Already exactly what the mock describes. |
| **Notes are append-only with full version chains; v1 stays reachable; amendment requires a reason** | Stronger than a "read-only" state. |
| **Every chart/patient render writes a patient-scoped read-audit row** | The Access Log screen only exists because the build already does this. |
| **Consent snapshots are immutable and withdrawal is audited with its reason** | The referral/summary/recall screens all depend on it. |
| **Duplicate detection is a server-side check on a real endpoint** | Not a page-side similarity score. |
| **Real German/Swiss demo data, role-gated nav, honest empty states** | vs the mock's fixed nav. |

---

## 7 — PROPOSED FIX CHAIN

**Recommended scope: the 6 live/partial screens + the 3 cheapest no-page screens. Defer the other 3.**

| Gate | Builds | Proves |
|---|---|---|
| ~~**PC.P1**~~ ✅ **DONE** | **Shared components + B1.** Promote S1 out of `Components/Dental/` to a shared clinical namespace and **wire its allergy chip** by adding allergies to the `Patients/Show` payload. Build N1 rail card, N3 audit row, N6 sign-off bar. | Presentational (P0D.GU); the chip renders **recorded** allergies only, never a computed cross-reaction; existing tests stay green. |
| ~~**PC.P2**~~ ✅ **DONE** | **Patient Chart parity** — band counts, tab chips, find-in-chart, timeline filters + month groups, version chains, recall proximity, and the N4 agent panel around the **existing** summary tool. | Vitals stay trend-free; the summary keeps its SUGGEST ceiling and source chips; D-169 styling scan. |
| ~~**PC.P3**~~ ✅ **DONE** | **Patient 360 parity** — the hero band carried by the **extended S1** (status pill · dental link), five tabs with **server-computed** counts, consents tab, access tab. | Consent snapshots stay immutable; the allergy chip is display-only; **the flag chip is omitted — nothing records a flag** (§7c). |
| ~~**PC.P4**~~ ✅ **DONE** | **Note Editor parity** — per-section required/filled markers, the superseded-version banner, the shared N6 sign bar, the recorded-allergy banner lit. **The assist panel is OMITTED (§7d)** — no such tool exists and the mock draws none. | Signing/amend re-checked server-side; a signed note provably not editable in place; no delete affordance anywhere. |
| **PC.P5** | **Patient Access Log (B2)** — dedicated route + `patient.audit.view` gate, basis/range facets, agent min-necessary detail, and the **signed nDSG/GDPR export**. | Basis derived server-side; rows immutable; viewing writes its own row. **Operator-mode detail omitted + flagged** (G4–G11 deferred). |
| **PC.P6** | **Referral Out (B5)** — urgency, packet, consent-to-share, tracking states. | The share is consent-gated and minimum-necessary; the agent packages, never decides whom to refer. |
| **PC.P7** | **Recall Due List (B6)** — the worklist over the existing engine, with channel/consent and agent status. | Auto-send respects the real ceiling; "consent — can't send" is a genuine refusal. |
| **PC.P8** *(optional)* | **Consult Summary (B4)** — the draft→sign→release record over the existing extractive summary. | Grounded in the signed note; nothing reaches the patient unsigned; delivery is consent-gated. |

**Realistic gate count: 7 core + 1 optional = 7–8 gates.**

**Recommended to DEFER — and why:**

- **Allergy Alert** — the safe part is already built. The rest is the certified-partner seam; there is nothing
  to build until a licensed engine is contracted, and even then the drawn hard-block is refused.
- **Care Plan Review** — its two useful halves (a signed review record; adherence as a real count) are worth a
  gate eventually, but the screen as drawn is ~70% refused material. Build B3 only when a customer asks.
- **Medical History Intake (B7)** and **Patient Flow (B8)** — a patient-facing portal form and a full analytics
  report are net-new subsystems, not parity work.

---

## 7a — P1 outcome (2026-08-21)

**S1 promoted** to `Components/Clinical/` (a clinical component dental merely needed first), all four dental
callers updated, **behaviour-identical**: the rendered dental header is byte-for-byte identical before and
after (381 normalised chars).

**B1 landed where the boundary requires.** `Modules\Patients` may not use `Modules\Clinical` (arch-enforced),
so `PatientShowController` moved to the **app layer** — the same reason `AppointmentDetailController` lives
there (D-017). Namespace changed; route, gate, payload and the **single read-audit row** unchanged.

**The page was already waiting.** `Patients/Show.vue` has carried a dormant `allergies` prop and hidden banner
since it was built — the exact gap the wireframe names. Landing the prop lit it with no page rewrite. Chips show
recorded substance · reaction · severity as facts, styled identically, **ordered by substance not severity**,
with an honest "No allergies recorded" empty state.

**Patient 360's hero was deliberately not replaced** with S1 — it carries a status pill, flag chip and dental
link S1 has no props for. That is **PC.P3**.

**Three new shells:** N1 rail card, N3 audit row (basis is server-derived and merely printed), N6 sign-off bar
(**performs no signing logic**). All compute nothing.

**The fence scan now follows the header** across both namespaces — moving a file out of a glob is a fence
weakened invisibly, and the severity-tint mutation was caught by that very test.

---
## 7b — P2 outcome (2026-08-21)

Most of this screen was already right: the extractive SUGGEST-ceiling summary with source chips, trend-free
vitals, note version chains with v1 always reachable, tabs, month grouping and type filters all pre-existed.

**The real defect was the counting.** The band and tab chips were `array.length` in Vue — and the chart's lists
are deliberately partial (`notes` carries head versions only; `orders` is empty for an actor who may not see
them), so a Vue length **under-reports the record**. Counts are now server-computed from real rows, with
`notes`/`orders` mirroring their lists so a chip cannot disagree with what sits under it. The superseded
client-side `openRecalls` computed was deleted rather than left as a second, divergent source.

**Added:** find-in-chart (a plain substring filter over already-loaded content — fetches nothing, ranks nothing,
and says so on screen) and recall proximity as a plain calendar interval ("due in 66 days"), tinting nothing.

**A false green of my own, caught and fixed:** the vitals fence assertion passed a `'band' => 'high'` mutation
because the fixture recorded no vitals — an absence assertion over an empty collection is vacuously true. The
test now records real vitals including a frankly abnormal reading, and asserts the collection is non-empty
before scanning it (D-174).

---
## 7c — P3 outcome (2026-08-21)

**S1 was extended, not forked.** Patient 360's hero and the dental band are now the same component. The
four new props (`status`, `links`, `variant`, `initials`) are all **optional** and `compact` is the default,
so the dental callers are untouched — the hero's avatar and watermark are **absolutely-positioned
decoration**, added without changing the compact DOM. A first attempt wrapped the band in an avatar row;
that would have altered the dental markup and was rewritten before it was built. Verified in a browser on
both surfaces: the dental band's root class string, its `text-2xl` name and the absence of avatar/watermark
are unchanged; the 360 hero shows the recorded status pill, the real severe-allergy chip and the dental link.

**The one faked thing on this screen was found and removed.** The hero carried a hardcoded `⚑ Flag` span —
unbound, rendered for **every** patient, asserting a documented fact that does not exist. There is no flag
column, no model attribute and no migration anywhere in CareOS. It is **omitted, not backfilled**: a flag is
meaningful only as a clinician-recorded fact, and deriving one from the record would be exactly the computed
risk marker the fence forbids. `patients.show.headerFlag` was deleted too — a live string is an invitation to
render it again — and the header deliberately grew **no** `flag` prop. **This is the gap; it is recorded, not
closed.** Closing it needs its own gate: a recorded flag with an author, a reason and a timestamp.

**One field the hero no longer shows, stated rather than glossed:** the old bespoke band carried a
`preferred_language` chip that the shared header has no prop for. It is **not lost** — the Demographics tab
on the same page still renders it (`patients.fields.language`, untouched) — but the hero shows one fewer
fact than before, and the wireframe hero does not draw language either. Adding a prop for it would have
widened S1 for one caller; if a clinician wants it back in the band, that is a prop worth adding
deliberately, not a detail to discover later.

**Counting moved server-side here too**, ahead of the defect rather than behind it. `PatientAccessReport::
forPatient()` is uncapped today, so the Vue lengths were accurate — but that is a property of today's payload,
not a guarantee, and it is precisely the assumption that broke on the chart at P2.

**Moving the h1 broke an a11y guard, and the guard was right to break.** `a11y-markup.test.ts` asserted the
360 page source contains `<h1`; the heading now lives in the shared header, so the page has none — the
rendered outline is identical, but the assertion had lost its subject. It was made to FOLLOW the heading
(the page must render the shared header and add no competing `<h1>`; the header must carry exactly one),
which is stronger than what it replaced. The first attempt at that fix was too loose and only the mutation
run revealed it.

**Every new guard was mutation-checked** (five mutations: a reintroduced flag chip, severity-keyed chip
styling, a page-side count, a dental caller made hero, a fence token in the header). Each failed exactly one
test. One mutation fired unprompted: the glyph scan caught the `⚑` in my own explanatory comment, so the
prose was reworded and the raw-source check kept at full strictness.

---
## 7d — P4 outcome (2026-08-21)

**This screen was the most nearly-complete in the batch, and the audit row overstated its gap.** The
type-to-confirm sign modal, the required-section footer count and the autosave chip were all already live
(`CLINIC.W5`, which predates the audit commit). Checking the repo rather than the audit is what turned a
"build all of it" row into four narrow, real deltas — the row is corrected in §2 above.

**THE ASSIST PANEL IS OMITTED, FOR TWO INDEPENDENT REASONS.** First, **no rephrase or note-authoring
capability exists**: there are ten AiCore tools, none of which touches note prose, and the only clinical one
(`ClinicalSummaryTool`) is EXTRACTIVE at a SUGGEST ceiling and lives on the Chart, where PC.P2 wired it.
Second — and decisively — **the wireframe itself draws no assist panel at all**: zero occurrences of assist,
rephrase, AI, agent, suggest or summarize anywhere in the decoded mock. So building one would not have been
parity; it would have been **inventing an agent surface beside a legal clinical record that neither the mock
nor the backend asks for** (D-170). The extractive summary was deliberately NOT duplicated onto the
authoring page either: a content-producing affordance next to the note body is precisely what this screen
must not have. The page now says so in words: *"You author this note. CareOS stores and versions your text —
it does not write, complete, rephrase or suggest clinical content, and nothing is inserted or signed without
you."*

**Built (the four real deltas):** per-section `required · filled` / `required · empty` / `optional` markers
and S/O/A/P letters, with **every marker carrying identical classes** so the word states the state and
nothing is tinted (D-169); the **superseded-version banner** — a clinician reading v1 is now told a newer
version exists and given a link to it, computed server-side from the existing append-only chain and changing
no path; the **shared N6 sign-off bar** adopted, which performs no signing logic and receives the readiness
line as a pre-composed string; and the **dormant allergy banner lit** — the note editor's `allergies` prop
has been declared and hidden since the editor was built, the same gap PC.P1 closed on Patient 360. No
boundary question arose: `Allergy` is Clinical's own model.

**Flagged, not changed:** the mock says template prefill "renders placeholder-style until edited; it never
autosaves as content", while live applies template defaults as real content at creation. That is a
behaviour change to an existing path with existing tests, so it is recorded rather than made here.

**Seven mutations, and one of them exposed a real hole in my own guard.** Counting `insertSnippet` call
sites does NOT catch a **new watcher writing straight into a SOAP section** — which is exactly how
auto-authored text would arrive, and that mutation passed the suite on the first run. The guard now pins the
**write surface itself**: one watcher, and exactly one assignment into a section anywhere on the page. All
seven now bite: auto-sign on a timer, auto-insert via a new watcher, a signed note made editable in place, a
`generatedAssessment` payload key, a severity-tinted allergy banner, a suppressed superseded banner, and an
invented `RephraseNoteTool`.

---
## 8 — Bottom line

- **12 decoded, 12 audited. 4 fully live, 2 partial, 6 with no page.**
- **Unlike Dental, most of this batch is buildable** — the mocks are fence-aware and several describe mechanisms
  the live build already has (extractive summary, trend-free vitals, immutable consent, read-audit on render).
- **Two screens carry the fence risk**: Allergy Alert (a cross-reactivity determination *and* a hard block — the
  partner seam refuses both) and Care Plan Review (the perio computed rail plus an AI imaging finding, all of
  which DENTAL-B.P3/P6 refused within the last week).
- **The cheapest high-value fix is B1** — wiring the allergy chip the shared S1 header has been carrying unused
  since DENTAL-B.P1.
- **Nothing was fixed. No gate was opened.**
