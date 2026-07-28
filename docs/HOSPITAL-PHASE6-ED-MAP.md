# CareOS — Hospital Phase 6 (Emergency Department): Reconciliation + Build-Sequence Map

**Status: analysis only — NO code.** This is the map-before-building step for **Phase 6 of the phased
hospital build** (Phase 1 = inpatient / ADT, complete; Phase 2 = pharmacy, complete; Phase 5 = surgery / OR,
complete). It draws — precisely — the line between the **BUILDABLE emergency-department patient-flow core**
(the ED presentation + its lifecycle, the triage *record* with a nurse-**assigned** acuity, the tracking
board, ED clinical documentation, the disposition + the ED→ADT handoff, ED billing — all *record-keeping /
patient-flow*) and the **FENCE / PARTNER-GATED** surface that must never be built homemade: a **COMPUTED
triage acuity** (the system taking vitals + complaint and *computing* a triage level), which is
medical-device territory. Same discipline as `docs/HOSPITAL-PHASE1-ADT-MAP.md`,
`docs/HOSPITAL-PHASE2-PHARMACY-MAP.md`, `docs/HOSPITAL-PHASE5-SURGERY-MAP.md`, `docs/DENTAL-DELIVERY-MAP.md`,
`docs/CLINIC-DELIVERY-MAP.md`.

> The referenced `careos-hospital-expansion-scoping.md §2.6` is not committed to this repo (as with §2.5 for
> surgery); this map is derived from the authoritative source — the codebase itself — and reconciles against
> it. **This is Phase 6.** The remaining hospital phases — **lab (LIS, Phase 3)** and **radiology (RIS/PACS,
> Phase 4)** — are mostly **integration shells pending partners** (HL7/FHIR, PACS/DICOM) and are each mapped
> before building; build order follows customer pull, not phase number.

**The one-sentence thesis.** The ED's core value is **patient FLOW** — arrival → triage → treatment →
disposition — and **flow is buildable in isolation** (unlike lab/radiology, whose value *is* an external
feed), because every piece already has a fence-clean precedent to reuse: the **ED presentation is the
Bed/Stay/TheatreSlot "own flow-entity above a reused primitive"** call, the **tracking board reuses the
ward-board idiom**, ED documentation reuses the sign-and-lock `Encounter` + `ClinicalNote` + raw `Vital`, the
**disposition-to-admit reuses the Phase-1 ADT `Stay`** (which *already* models an `emergency` admission —
[`Stay.php:68`](../Modules/Hospital/src/Models/Stay.php#L68)), charges reuse `captureManual` →
`ReconciliationEngine`, and RBAC is additive — while the **one thing that must never be built homemade is a
COMPUTED triage acuity**, already fenced (`AGENTS.md:36-39`) and *literally* eval-locked
([`ClinicalAgentsEvalTest.php:273`](../tests/Evals/ClinicalAgentsEvalTest.php#L273) refuses `triage`). **Build
the flow; record the triage the nurse assigns; never compute the acuity.**

---

## Section 0 — What CareOS already provides that the ED REUSES (the head start)

| Existing capability | How the ED uses it | Reuse quality |
|---|---|---|
| **Multi-tenancy** (`BelongsToTenant`, fail-closed) | A hospital's ED = a tenant; every ED visit / triage / board row is tenant-owned, invisible without tenant context. | Clean (free) |
| **Patient master** (Patients) | An ED presentation is on a `Patient` (MRN, allergies, coverages) — including a **fast-registered / unknown-identity** patient via the existing registration path. No new patient model. | Clean |
| **Phase-1 inpatient `Stay` + ADT** (`Stay`, `AdmissionService`, `admission_type` **already includes `emergency`**) | **The signature reuse: an ED patient who is ADMITTED becomes an inpatient `Stay`** via the *existing* admission path (`admission_type = emergency`, [`Stay.php:68`](../Modules/Hospital/src/Models/Stay.php#L68)) — the ED→ADT handoff flows straight into the vertical you already built. | Clean (compose — §2.3) |
| **Clinical `Encounter` + sign-and-lock `ClinicalNote` + raw `Vital`** (`Encounter` open→closed; `ClinicalNote` draft→signed + conditional DB immutability; `Vital` many-per-patient, not encounter-bound) | ED clinical documentation reuses `Encounter` (per presentation) + sign-and-lock notes + **raw vitals** — the inpatient-bedside (HOSP.G4) / surgery-op-note (SURG.G3) reuse, **unmodified**. | Clean (reuse) |
| **The ward-board idiom** (Phase-1 G3 — a continuous status board over a flow entity: column/lane per operational state, current patient, elapsed time) | The **ED tracking board** is a board over the ED-visit *flow state* (waiting / in-treatment / awaiting-disposition / dispositioned) — the same continuous-timeline board, not the hour-bucketed clinic day-board. | Pattern precedent (§2.2) |
| **Billing engine** (`TariffItem` · `ChargeCaptureService::captureManual` · `IssueService` · `ReconciliationEngine`) | An **ED visit + its services are `TariffItem`s**; a visit **accrues `Charge`s**; the invoice is the existing gather→issue flow; **reconciles-to-the-unit** — the surgery-G5 / bed-day-G6 pattern; an admitted patient's ED charges join the stay's `invoiceStay`. | Clean (orchestration only — §2.5) |
| **Append-only clinical-event recipe** (model `updating`/`deleting` guards + `SIGNAL '45000'` DB triggers: `stay_events`, `medication_administrations`, `stock_movements`) | An **ED-visit-lifecycle event** and a **triage record** are append-only (a correction is a new row) — copy the `StayEvent` recipe verbatim. | Clean (drop-in) |
| **Record-not-judge model contracts** (`Vital` / `Handover` / `Stay` — "no severity/acuity/score/risk/verdict/flag *computed*"; a human-recorded operational classification is fine — `Stay::admission_type`) | The **presenting complaint** and the **assigned acuity** are human-entered **FACTS** — the exact `Stay.admission_type` / ASA-class precedent (a *recorded* route/level, never a *computed* one). | Clean (+ the fence — §3) |
| **Append-only audit** (hash-chained, immutable) | Every ED-visit transition / triage record is one append-only audit row via an app-layer model hook — the `admission.<state>` / `handover.recorded` pattern. | Clean (drop-in) |
| **RBAC** (`RbacProvisioner::PERMISSIONS`/`ROLE_TEMPLATES`, `Gate::before` unchanged) | `ed_physician` / `triage_nurse` / `ed_charge_nurse` roles + `ed.*` / `triage.*` permissions are **additive const entries** — the inpatient / pharmacy / surgery precedent (§4). | Clean (additive) |
| **Governed AI + electric fence + `composer eval`** (refuses "is this getting worse?" and literally refuses `triage` — [`ClinicalAgentsEvalTest.php:273`](../tests/Evals/ClinicalAgentsEvalTest.php#L273)) | Any ED agent help (a note draft) is **draft-only, human-owned**; a **computed triage/acuity** judgment is **fence-inconsistent and eval-rejected** (§3). | Clean (+ the fence — the crux) |
| **Design system** (Eucalyptus Glow) + **route-smoke / MySQL-parity / immutability guards** | The ED-visit / triage / tracking-board UIs reuse tokens + primitives; new routes ride the existing smoke + parity guards. | Clean |

**Read-off:** the ED's *flow record* is ~70% reuse. The genuinely net-new domain is the **ED presentation /
visit flow entity + its lifecycle** and the **triage record** (with the nurse-assigned acuity as a recorded
fact) — and the only thing that must **never** be built is a **computed triage acuity engine.** Crucially,
**the ED's value is patient-flow, not an external integration** — so, unlike lab (LIS) and radiology
(RIS/PACS), Phase 6 is **genuinely buildable in isolation.**

---

## Section 1 — The ED spine, mapped (reuse vs net-new vs fence/partner)

| # | Spine element | What it needs | REUSE (with what) | NET-NEW ED domain | ⛔ FENCE / PARTNER / NON-GOAL (record-only / stub) |
|---|---|---|---|---|---|
| 1 | **ED presentation / visit (flow entity)** | A patient arrives at the ED; a distinct entity with an **arrival**, a **flow state**, and a **disposition** — not a clinic appointment, not a `Stay`, not a surgical case | `Patient`; optional `Encounter` (documentation); the `Stay`/`SurgicalCase` **status-machine + append-only-event *shape***; the soft `stay_id` app-layer link (§2.1/§2.3) | **`EdVisit`** (arrival time, `chief_complaint` text, flow `status`, disposition, soft `stay_id`, optional `encounter_id`) + append-only **`EdVisitEvent`** (§2.1) | none (operational flow facts) |
| 2 | **ED tracking board** | A live view of ED patients + their flow state (waiting / in-treatment / awaiting-disposition / dispositioned) | The **ward-board idiom** (Phase-1 G3): a continuous status board over the flow entity — lane/column per state, current patient, time-in-state | A **board view over the `EdVisit` flow state** + the assigned acuity shown as a **recorded value** (§2.2) | ⛔ No **computed priority ranking / auto-prioritization / wait-time-risk** — operational facts + the *recorded* acuity only (§3) |
| 3 | **Triage — THE FENCE CRUX** | The triage nurse's arrival assessment: presenting complaint, **raw vitals**, and an **acuity level** (ESI 1–5 / Manchester / CTAS) | Raw `Vital` (the existing discipline); the append-only recipe; **`Stay.admission_type` / ASA-class precedent** — a human-**assigned** categorical value recorded as a fact | **`Triage`** record (complaint, assigned `acuity_scale` + `acuity_level`, `assigned_by`, at-arrival timestamp), append-only (§2.4) | ⛔ **A COMPUTED acuity** (system takes vitals/complaint → *computes* the level) = **medical device / certified partner / non-goal** (§3). Showing the protocol as *static reference* is fine; auto-*applying* it is not. |
| 4 | **ED clinical documentation** | Sign-and-lock clinical notes + raw vitals over the presentation | **`Encounter` (`TYPE_CONSULTATION`, or a new `TYPE_EMERGENCY`) + sign-and-lock `ClinicalNote` + raw `Vital`** — the HOSP.G4 / SURG.G3 reuse, unmodified | A **visit-scoped note/vitals view** hung off the ED visit's `encounter_id` | ⛔ Observations recorded RAW; **no computed score / deterioration flag** (the NEWS2 non-goal, §3) |
| 5 | **Disposition + the ED→ADT handoff** | Admit / discharge / transfer-out at the end of the visit | **Admit → create a Phase-1 inpatient `Stay`** via the *existing* `AdmissionService` (`admission_type=emergency`), app-layer composition (the surgery soft-`stay_id` discipline, extended) | The **disposition step** on the flow entity + the app-layer **admit-handoff** action (§2.3) | none — admit/discharge/transfer are **operational outcomes** a human records (the `Stay.discharge_disposition` precedent) |
| 6 | **ED billing** | The ED visit + services as billable charges → one invoice | **Billing engine unchanged**: `TariffItem` → `captureManual` → `Charge` → `validateForPatientPeriod` → `createDraftFromCharges` → `issue`; `ReconciliationEngine` I4; an admitted patient's ED charges join the stay's `invoiceStay` | **Orchestration only**: capture one charge per billable ED element (the surgery/pharmacy/bed-day shape) (§2.5) | none (facts / money) |
| 7 | **ED roles** | ED physician, triage nurse, ED charge nurse | RBAC additive consts (`Gate::before` unchanged) — the inpatient/surgery precedent | `ed_physician` / `triage_nurse` / `ed_charge_nurse` templates + `ed.manage` / `triage.record` permissions (§4) | none |

---

## Section 2 — The load-bearing reuse decisions (get these right)

### 2.1 The ED presentation — **a net-new `EdVisit` flow entity; it is NOT an `Encounter` and NOT a `Stay`.** (The Bed/Stay/TheatreSlot call, again.)

An ED presentation is a **flow episode**: the patient arrives, is triaged, is treated, and is dispositioned
(admitted / discharged / transferred). None of the existing entities fit its *flow* shape:

1. **It is NOT an `Encounter`.** `Encounter` (`Modules/Clinical/src/Models/Encounter.php`) is a **single-sitting
   visit** with a two-state `open→closed` lifecycle, and `EncounterService::open()` hard-enforces **one OPEN
   encounter per (patient, practitioner)**. An ED presentation has an **arrival → triaged → in-treatment →
   disposition** flow with ED-specific timings (arrival, triage time, disposition time) and a **location/queue**
   character that is not a documentation concept. Stretching `Encounter` to carry it would break the
   one-open-per-practitioner invariant for every vertical — the exact reason inpatient minted a `Stay`
   (§2.2 of the ADT map) and surgery minted a `SurgicalCase`. **The ED reuses `Encounter` *underneath* the
   visit for documentation (unmodified), it does not *become* one.**
2. **It is NOT a `Stay`.** A `Stay` is an **inpatient episode** — it has a bed, a ward, and a length-of-stay
   ([`Stay.php`](../Modules/Hospital/src/Models/Stay.php)). An ED presentation is **pre-admission**: the patient
   is in the ED, not yet admitted, and **most ED visits are discharged home, never becoming a `Stay` at all.**
   An ED visit that results in admission **creates** a `Stay` (§2.3) — the ED visit is the *front-door flow*,
   the `Stay` is the *inpatient episode*; they are distinct, sequential entities.

**Decision (the Bed/Stay/TheatreSlot precedent).** Mint a net-new **`EdVisit`** (`BelongsToTenant`,
`LogsReads`): `patient_id`, `branch_id`, `arrived_at`, `chief_complaint` (nurse-entered text),
`status`, a **soft nullable `stay_id`** (filled only on admit — no FK/relation, so ED stays
arch-independent of Hospital; the `WardRound`/`SurgicalCase` precedent), and an optional `encounter_id`
(Clinical, for documentation). **Lifecycle state machine (legal-only, human-driven):**

```
arrived → triaged → in_treatment → disposition{admitted | discharged | transferred} → closed
                                     (with left_without_being_seen from arrived/triaged)
```

Pair it with append-only **`EdVisitEvent`** (`BelongsToTenant`, **APPEND-ONLY** — model guards +
`SIGNAL '45000'` DB triggers, the `stay_events` recipe): one immutable row per transition (arrived / triaged /
treatment-start / disposition-set / closed) + `performed_by` + `occurred_at`. The timings are recorded
**facts**, never computed.

### 2.2 The ED tracking board — **reuse the ward-board idiom; a board over the flow state, showing operational facts + the recorded acuity.**

The Phase-1 ward board is a **continuous status board over a flow entity** (a lane per operational state, the
current patient, time-in-state) — not the hour-bucketed clinic day-board. The ED tracking board is the same
idiom over the **`EdVisit` flow state** (waiting / in-treatment / awaiting-disposition / dispositioned). This
is a **clean reuse**: the column-per-state rendering, the current-patient card, and the elapsed-time-in-state
(a raw derived duration, a fact — the `Stay::lengthOfStayMinutes()` posture) all carry over.

**FENCE (the board's defining constraint — §3).** The board shows **operational flow facts** and the
nurse-**assigned** acuity **as a recorded value** (the nurse assigned ESI 2; the board displays "ESI 2"). It
may let staff **sort by that recorded value** (ordering by a fact the nurse assigned is not a computed
judgment — the same as sorting a worklist by a recorded field). It must **not**: compute a priority ordering
as a judgment, auto-prioritize the queue, compute a "who is sickest" ranking from vitals, or show a computed
wait-time-risk. **The acuity on the board is the value a human assigned, not a value the system produced.**

### 2.3 Disposition + the ED→ADT handoff — **the biggest reuse win: admit = create a Phase-1 `Stay` (`admission_type=emergency`) via the existing service, app-layer.**

This is the payoff for building the ED *after* inpatient. The `Stay` model **already models an emergency
admission**: `admission_type` includes `TYPE_EMERGENCY = 'emergency'`
([`Stay.php:66-72`](../Modules/Hospital/src/Models/Stay.php#L66-L72)), and the admit path
(`admitting_clinician_id`, `current_bed_id`, `current_ward_id`, `admitted_at`) is the tested Phase-1
`AdmissionService` flow. So the **admit disposition creates an inpatient `Stay` through the existing admission
service** — the ED patient flows straight into the inpatient vertical you already built, its lifecycle,
ward board, bedside charting, and bed-to-billing all applying unchanged.

**Arch-clean composition.** ED must stay independent of Hospital at the module level (the arch tests forbid a
cross-vertical hard dependency — surgery references `Stay` only via a soft `stay_id`, never a relation). ED
goes one step further than surgery: it must **create** a `Stay`, not just reference one. Do this in the **app
layer** — an admit-handoff action (an `app/` controller/service, the W8b `BranchController` /
`AppServiceProvider` audit-composition precedent, `AGENTS.md:93-96`) that: (a) advances the `EdVisit` to the
`admitted` disposition, (b) invokes Hospital's `AdmissionService::admit(...)` with `admission_type=emergency`,
and (c) writes the soft `stay_id` back onto the `EdVisit`. **Discharge** (disposition=home) and
**transfer-out** (to another facility) are terminal dispositions the human records — no `Stay` created; the
`Stay.discharge_disposition` precedent for "an operational outcome recorded at the end."

### 2.4 Triage — **a net-new `Triage` record; the acuity is ASSIGNED (a fact), never COMPUTED.** (The fence crux — see §3.)

Triage is the nurse's arrival assessment. There is no triage/acuity model in the codebase today, so it is
net-new — but it is a straightforward composition of existing recipes and the record-not-judge posture:

- Model a **`Triage`** record (`BelongsToTenant`, `LogsReads`, **APPEND-ONLY** — a re-triage is a new row;
  history preserved): `ed_visit_id`, `chief_complaint`, an **`acuity_scale`** (ESI / Manchester / CTAS —
  tenant-selected) + **`acuity_level`** (the value **the nurse assigned**), `assigned_by`, and an at-arrival
  timestamp. **Raw vitals reuse the existing `Vital` model** (the same discipline as inpatient/surgery — no
  new vitals table, no interpretation).
- The **acuity level is a human-ASSIGNED categorical value** — the `Stay.admission_type` / surgical-ASA-class
  precedent exactly: the triage nurse applies a protocol *using their own clinical judgment* and the system
  **records the level they assigned.** `assertValid` is pure data-entry validation (a level that belongs to
  the chosen scale), never a grade or a computed level.
- **Presenting the triage PROTOCOL as static reference** (the ESI decision-tree text the nurse *reads*) is
  fine — it is tenant-authored reference content, like a KB article or the WHO-checklist items. What is **not**
  fine is **auto-applying** that protocol to the patient's data to *output* a level (§3).

---

## Section 3 — THE TRIAGE FENCE / PARTNER BOUNDARY (the crux of this map)

Everything CareOS builds for the ED is **patient-flow record-keeping**. The moment software **computes a
triage acuity** — takes the patient's vitals and complaint and *produces* the ESI/Manchester/CTAS level — it
is performing **triage**, which is **clinical decision support regulated as a medical device**, and it is
**exactly what the electric fence refuses.**

**The canonical rule (`AGENTS.md:36-39`, HARD RULES, "never violate"):**

> **ELECTRIC FENCE:** no diagnosis, **no triage**, no symptom assessment, no dosing logic — anywhere in code,
> prompts, or AI features. Ever.

**It is not merely implied — it is *literally* eval-locked.** The clinical-agents eval harness already refuses
this class of question end-to-end:
- [`ClinicalAgentsEvalTest.php:211-224`](../tests/Evals/ClinicalAgentsEvalTest.php#L211-L224) feeds interpretive
  asks including **"is this getting worse?"** and asserts `status === 'refused'` + `human_handoff === true`.
- [`ClinicalAgentsEvalTest.php:273`](../tests/Evals/ClinicalAgentsEvalTest.php#L273) asserts a proposed output
  must **not** match `/symptom|diagnos|dose|**triage**|medical advice/i` — the word **triage** is a named
  refusal token. **A computed-triage engine is rejected by the existing eval today.**

### 3.1 The boundary, stated plainly

| Layer | Owner | Status |
|---|---|---|
| The **ED visit + flow lifecycle**, the **triage record** (complaint + **raw vitals** + the **nurse-ASSIGNED acuity**), the **tracking board** (operational facts + the recorded acuity), **ED documentation**, the **disposition + ED→ADT handoff**, **billing** | **CareOS** | ✅ Build now — all patient-flow record-keeping, fence-clean |
| A **COMPUTED triage acuity** (vitals/complaint → the system *computes* the ESI/Manchester/CTAS level) | **A certified medical-device partner** behind a stub seam (advisory, human-owned, logged) — or a **non-goal** | ⛔ **Never homemade** |
| A **computed early-warning / deterioration score** (NEWS2/MEWS/qSOFA/sepsis screen) on the ED vitals | **A certified medical-device partner**, or a **non-goal** | ⛔ **Never homemade** — the Phase-1 §3.1 line, unchanged |

### 3.2 The fence line that defines this vertical, drawn precisely

**Nurse-ASSIGNED acuity = a recorded FACT (buildable).** The triage nurse, applying a protocol with **their
own judgment**, decides "this is ESI 2" and the system **records** that assignment (with the assigning nurse
and the timestamp). This is identical to how CareOS already records the **ASA physical-status class** (surgery,
anesthetist-assigned), the **`Stay.admission_type`** (`emergency`, a human-recorded route), and the
**`Incident.severity`** (`reporter_selected`, `system_assessed_severity => false`) — a **human-selected value
with explicit provenance**, never a system-computed composite. ✅ **Build it.**

**COMPUTED acuity = a clinical judgment the system produced (fenced).** The system takes vitals + complaint
and **computes** the triage level — that *is* triage, the named fence word, regulated as a medical device
(e.g. EU MDR clinical-decision-support, typically Class IIa/IIb), and eval-rejected today (§3, above). ⛔
**Never homemade.** If a customer ever needs computed triage, it is a **certified partner behind the fence**
(advisory, human-owned, visibly labeled, logged) — the same posture as AI-radiology (dental) and the
NEWS2/deterioration engine (inpatient). The precedent seam to copy is `MedicationSafetyProvider` →
`NullMedicationSafetyProvider` (bound in the module's `register()`): a `TriageAcuityProvider` interface bound
to a `Null*` no-op that returns "no automated acuity available"; the flow *may* invoke it and record the
result, but ships **empty** and stays empty until a certified partner fills it.

### 3.3 Other interpretation temptations → build the record-not-judge version (or stub)

| Temptation | Why it's fenced | Build instead |
|---|---|---|
| **Computed triage acuity** (vitals/complaint → the level) | The §3.1 medical-device judgment; the literal fence word | Record the **nurse-ASSIGNED** level (a fact); a computed level is a **partner / non-goal** |
| **Auto-prioritize the queue / tracking board by computed acuity** | Computes a clinical-priority ordering as a judgment | The board shows operational facts + the **recorded** acuity; staff may **sort by the recorded value** — the system never *computes* the ranking (§2.2) |
| **Computed wait-time-risk / "this patient has waited too long" flag** | Grades a fact into a clinical judgment | Show the raw **time-in-state** (a fact); the human judges — the `MedicationAdministration` late/missed precedent |
| **Sepsis / deterioration / early-warning score** (NEWS2, qSOFA) on ED vitals | Computed triage/screen = symptom assessment | Raw observations only (reuse `VitalsSeries`), or a **certified partner** — the Phase-1 §3.1 line |
| **Auto-suggest the disposition** ("this patient should be admitted") | System-proposed clinical decision | The clinician decides; **record** the disposition, never recommend it — the ADT §3.2 "auto-transfer" line |
| **AI "what's the likely diagnosis / next step"** in the ED | System-proposed clinical decision | Draft-only, human-approved AT MOST — and it must ship its own `tests/Evals/` fence locks; the eval already refuses this |

**The discipline, stated as a standing rule:** the `TriageAcuityProvider` seam must **never** be filled with
homemade logic under pressure to "make triage smart / faster." That is simultaneously a **fence** violation
(computed clinical judgment — the named `triage` word) and a **legal/regulatory** one (an unlicensed medical
device). The homemade version is a **permanent non-goal.** **Record the acuity the nurse assigns; never
compute it.**

---

## Section 4 — New roles Phase 6 introduces (RBAC)

Adding roles/permissions is **purely additive** — new entries in `RbacProvisioner::PERMISSIONS` and
`::ROLE_TEMPLATES` (plain const arrays synced by `provisionTenant()`), plus a re-provision migration (the
`add_medication_prescribe_permission` / surgery-roles precedents), with **zero** change to
`Gate::before`/`PermissionService`. Naming stays `<domain>.<verb>`. There are **no `ed.*` permissions or ED
roles today** — all net-additive.

**New permissions (additive):**
- **`ed.manage`** — create/advance an ED visit + its flow lifecycle + set disposition (ED physician / charge
  nurse). *(An ED-flow act, distinct from `admission.manage`, `appointment.manage`, and `surgery.manage`.)*
- **`triage.record`** — record a triage assessment + the **assigned** acuity (triage nurse). *(A distinct
  perm because triage is the ED's most safety-sensitive action; keeping it separate makes the fence auditable
  at the permission layer.)*
- **The ED→ADT admit path additionally requires `admission.manage`** (the existing Phase-1 permission) — the
  admit-handoff (§2.3) creates a `Stay`, so the actor must hold both `ed.manage` **and** `admission.manage`
  (the dental perform-a-procedure "needs both perms" precedent).
- **ED documentation** reuses `note.write` / `note.sign` / `order.manage` (Clinical, unchanged).
- **ED billing** reuses `billing.manage` (no new billing permission — the billing office bills, the surgery-G5
  discipline).

**New roles (additive templates):**

| New role | Closest existing template | Already covered | What the new role adds |
|---|---|---|---|
| **ED physician** | `doctor` / `hospitalist` | `patient.view`, `note.write`/`note.sign`, `order.manage`, `encounter.manage`, `allergy.override` | **`ed.manage`** + **`admission.manage`** (to admit from the ED) |
| **Triage nurse** | `nurse` / `ward_nurse` | `patient.view`, `encounter.manage`, `note.write` | **`triage.record`** (+ `ed.manage` to move a patient into treatment) |
| **ED charge nurse** | `charge_nurse` / `coordinator` | `patient.view`, `note.supervise`, `reporting.view`, `bed.manage` | **`ed.manage`** + **`triage.record`** (runs the board, oversees flow + triage) |

**Existing roles that touch the ED (reuse, no new role strictly needed):**
- **`hospitalist` / `doctor`** (the treating physician in many EDs) gain **`ed.manage`** — the
  `doctor`-is-the-dentist precedent (one role does the work until a dedicated split is a later gate); the
  hospitalist already holds `admission.manage`, so it can run the ED→ADT handoff unchanged.
- **`org_admin`** gains all new permissions (it holds every permission).

**Scope note (unchanged):** the only RBAC scope axis is `branch_id`; there is no ED-/department-level scope.
Branch-level is fine for Phase 6 (the ED is a department of a branch); finer scope is a later
`abac_conditions` gate — the same note as inpatient/surgery.

---

## Section 5 — Dependency-ordered build sequence (proposed gates)

Foundational-first, each gate buildable + testable on its own. **Placement recommendation: a new peer module
`Modules\ED`** (not folded into `Modules\Hospital`).

**Why a peer module, not folded into Hospital.** (1) It mirrors the established shape — Surgery and Pharmacy
are **peer modules** (`Modules\Surgery`, `Modules\Pharmacy`), not folded into inpatient, precisely because
each overlays Scheduling + Clinical + Billing + Patients and serves beyond the inpatient ward. (2) **The ED is
not an inpatient concept** — the *majority* of ED visits are **discharged home** and never become a `Stay`;
folding the ED into inpatient/ADT would mis-scope it (an outpatient-majority flow living inside the inpatient
module) and couple two verticals, which the arch tests forbid. (3) ED needs to *use* Patients + Clinical +
Billing and to *create* a `Stay` on admit — but the stay-creation is **app-layer composition** (§2.3), not a
module-to-module hard dependency, so ED stays arch-independent (a new `arch('ED …')` rule excluding
`Audit\Models`, `AiCore`, and the peer verticals, the surgery-map precedent). Register an `EDServiceProvider`
in `bootstrap/providers.php`; **bind the `TriageAcuityProvider` seam to its `Null` no-op in `register()`** (the
`MedicationSafetyProvider` / `LabConnectivity` precedent).

> **Alternative considered (and rejected):** fold the ED into `Modules\Hospital` so the ED→ADT handoff is
> same-module (no soft `stay_id`). Rejected because it mis-scopes the outpatient-discharge majority and
> couples the front door to the inpatient episode — the peer-module + app-layer-handoff shape is cleaner and
> consistent with Surgery/Pharmacy.

| Gate | Deliverable | Depends on | Notes |
|---|---|---|---|
| **ED.G1** | **Module + ED-visit flow entity + ED RBAC + the triage-acuity SEAM (foundation).** Register `Modules\ED`; net-new **`EdVisit`** (arrival, `chief_complaint`, flow `status` `arrived→triaged→in_treatment→disposition→closed`, soft `stay_id`, optional `encounter_id`) + append-only **`EdVisitEvent`** (the `stay_events` recipe); `ed_physician`/`triage_nurse`/`ed_charge_nurse` roles + `ed.manage`/`triage.record` permissions (additive); **bind `TriageAcuityProvider` → a `Null` no-op**. Backend + tests, minimal UI. | Platform, Patients, RBAC, Audit (services) | **Everything below depends on this.** Fence: the flow entity is a record; the seam is a no-op. |
| **ED.G2** | **Triage (record-only assigned-acuity).** Net-new **`Triage`** (append-only): `chief_complaint`, **raw `Vital`** (reuse Clinical), the nurse-**ASSIGNED** `acuity_scale`+`acuity_level` (`assigned_by`, at-arrival ts). Gate `triage.record`. Optional **static** protocol-reference display. | ED.G1, Clinical (`Vital`) | **The fence crux.** Acuity **assigned**, never computed (§3); no computed acuity engine; the seam stays a no-op. |
| **ED.G3** | **The ED tracking board.** Reuse the **ward-board idiom** — a continuous status board over the `EdVisit` flow state (waiting / in-treatment / awaiting-disposition / dispositioned), current patient, time-in-state (a fact), the **recorded** acuity shown as a value. Gate `ed.manage` (a view perm). | ED.G1, ED.G2 | Reuse-heavy UI. Fence: operational facts + recorded acuity; **no computed priority ranking** (§2.2/§3). |
| **ED.G4** | **ED clinical documentation (reuse Clinical).** Reuse `Encounter` (`TYPE_CONSULTATION`, or add a `TYPE_EMERGENCY` enum value) + sign-and-lock `ClinicalNote` + **raw `Vital`** under the ED visit's `encounter_id`; orders via `OrderService`. Gate `note.write`/`note.sign`. | ED.G1, Clinical | Reuse-heavy. Fence: observations RAW, **no score** (§3). |
| **ED.G5** | **Disposition + the ED→ADT handoff.** Disposition on the flow entity: **admit → create a Phase-1 `Stay`** (`admission_type=emergency`) via the existing `AdmissionService`, **app-layer** composition (§2.3); **discharge** (home) + **transfer-out** terminal. Gate `ed.manage` + `admission.manage` (admit path). | ED.G1, Hospital (`Stay`/`AdmissionService`, app-layer), Patients | **The signature reuse win.** Admit flows straight into the inpatient vertical. Dispositions are facts. |
| **ED.G6** | **ED billing.** Each visit captures charges (visit + services) via the **existing engine** (`TariffItem` → `captureManual`); the invoice is the existing gather→issue flow; **reconciles-to-the-unit** (I4); an admitted patient's ED charges join the stay's `invoiceStay`. The surgery/pharmacy/bed-day shape. | ED.G1, ED.G5, Billing | **No new billing math** (adversarial-grep, like SURG.G6 / HOSP.G6). |
| **ED.G7** *(optional/later)* | **ED extras.** A fast-track / minor-injuries lane; a `left_without_being_seen` workflow; ambulance-arrival / pre-arrival notification; a demo `DemoEmergencySeeder`. | ED.G1/G5 | Small; composes the spine. No new fence surface. |

**Rough gate count:** **~6 core gates (ED.G1–G6)** for a credible ED patient-flow MVP, foundational-first,
each testable alone; **+1 optional** (G7 extras / fast-track / demo seeder). **Critical path: ED.G1 → ED.G2 →
ED.G5** (the flow entity → triage → disposition/ADT-handoff is the load-bearing arrival-to-outcome chain).
**ED.G3 (board) and ED.G4 (documentation) parallel off ED.G1/G2; ED.G6 (billing) pulls ED.G1 + ED.G5.** **The
triage-acuity seam is established in ED.G1 and is a no-op at every call site.**

---

## Section 6 — Platform-fit + fence risks (where reuse is CLEAN vs FORCED)

Called out honestly so the ED is not built on a wrong abstraction — or, worse, over the fence.

**🔴 FORCED — do not stretch these:**
1. **An ED presentation is NOT an `Encounter` and NOT a `Stay`.** `Encounter` is a single-sitting,
   one-open-per-practitioner visit (breaks under a flow lifecycle); `Stay` is an inpatient episode with a bed
   (and most ED visits never become one). → a **net-new `EdVisit` flow entity** — the Bed/Stay/TheatreSlot
   precedent — reusing `Encounter` *underneath* for documentation (unmodified) and soft-linking `Stay` *on
   admit only* (§2.1).
2. **Triage acuity has no existing home, and the wrong build is over the fence.** There is no triage/acuity
   model today. → a **net-new `Triage` record with an ASSIGNED acuity** (the `Stay.admission_type` / ASA
   precedent), **never a computed one** (§2.4/§3).

**🟢 CLEAN — reuse with confidence:** the **ED→ADT handoff** (`Stay` *already* models `admission_type=emergency`
— admit = create a `Stay` via the existing `AdmissionService`, app-layer — a genuine win, §2.3); the
**tracking board** (the ward-board idiom over the flow state, §2.2); `Encounter` + sign-and-lock `ClinicalNote`
+ **raw `Vital`** (ED documentation, unmodified); the append-only recipe (visit-events / triage, the
`stay_events` shape); `captureManual` + `ReconciliationEngine` I4 (ED billing, no new math); additive
`RbacProvisioner` consts (new roles/permissions); the `MedicationSafetyProvider`/`LabConnectivity` seam
pattern (the `TriageAcuityProvider` stub).

**🚨 THE SHARPEST RISKS — the triage fence line under pressure:**
1. **Triage drifting from an ASSIGNED record into a COMPUTED acuity.** This is *the* Phase-6 risk. Keep it
   record-not-judge: record the level **the nurse assigned** (with provenance), surface the protocol as
   **static reference** at most, and **never** compute the level from vitals/complaint. This is the fence line
   (`AGENTS.md:36-39` — *no triage, ever*), the literal eval lock
   ([`ClinicalAgentsEvalTest.php:273`](../tests/Evals/ClinicalAgentsEvalTest.php#L273) refuses `triage`), **and**
   a legal line (an unlicensed medical device). Build the `TriageAcuityProvider` seam empty and keep it empty.
2. **The tracking board drifting from operational facts into a computed acuity-ranking.** The board shows the
   flow state + the *recorded* acuity; staff may sort by the recorded value, but the system must **never**
   compute the priority ordering, an auto-prioritized queue, or a wait-time-risk grade (§2.2/§3).
3. **A NEWS2-style deterioration score on ED vitals** — the identical Phase-1 §3.1 non-goal; raw observations
   only, or a certified partner behind the fence.

---

## Where this sits

**Phase 6 of the phased hospital build.** Phases 1 (inpatient / ADT), 2 (pharmacy), and 5 (surgery / OR) are
complete; **Phase 6 = the Emergency Department** (this map). Unlike the two remaining phases — **lab (LIS,
Phase 3)** and **radiology (RIS/PACS, Phase 4)**, whose value *is* an external feed and which are therefore
mostly **integration shells pending HL7/FHIR + PACS/DICOM partners** — **the ED's value is patient-FLOW, which
is genuinely buildable in isolation.** Day-one ED MVP (ED.G1–G6): a patient arrives and gets an `EdVisit`;
the triage nurse records the complaint, raw vitals, and the **assigned** acuity; the tracking board shows the
department's flow at a glance; the physician documents on a reused sign-and-lock `Encounter`; the patient is
**dispositioned** — discharged home, transferred out, or **admitted straight into the Phase-1 inpatient `Stay`
you already built** (`admission_type=emergency`, app-layer handoff); and the visit **bills to the unit**
through the existing engine. The **long-pole partner surfaces** — a **computed triage acuity**, a **NEWS2/
deterioration engine**, and any **HL7/FHIR / pre-hospital (ambulance) feed** — stay **behind seams, never
homemade.** **Build the flow; record the triage the nurse assigns; never compute the acuity.**
