# Module: ED (`Modules\ED`)

## Purpose

The Emergency Department vertical — **Phase 6** of the phased hospital build (Phase 1 = inpatient/ADT,
Phase 2 = pharmacy, Phase 5 = surgery, all complete). Planned as ~6 gates
(`docs/HOSPITAL-PHASE6-ED-MAP.md`): the module + the NET-NEW `EdVisit` patient-flow entity + ED RBAC + the
empty triage-acuity SEAM (G1) → triage record with the nurse-**assigned** acuity (G2) → the tracking board
(G3) → ED clinical documentation reusing Clinical (G4) → disposition + the ED→ADT handoff (G5) → ED billing
(G6). **ED.G1 (the FOUNDATION) is built.** ED inherits the whole tested platform (tenancy, patients, people,
clinical, billing, audit, RBAC, the electric fence). Peer module, mirroring `Modules\Surgery` /
`Modules\Pharmacy`.

## The EdVisit decision (G1 — the "own flow-entity" crux)

Per the map §2.1, an ED presentation is **NEITHER** a Clinical `Encounter` (a single-sitting,
one-open-per-practitioner visit — an ED presentation has an arrival→triage→treatment→disposition FLOW) **NOR**
an inpatient `Stay` (an inpatient episode with a bed — MOST ED visits discharge home and never become one). So
the ED visit is a **NET-NEW `EdVisit`** flow entity — the Bed/`Stay`/`SurgicalCase` "own flow-entity above a
reused primitive" discipline. ED clinical documentation will REUSE `Encounter` later (G4); the visit itself is
its own entity.

- **`EdVisit`** (`BelongsToTenant`, `LogsReads`, tenant + branch scoped) — the mutable CURRENT state: patient,
  branch, `arrived_at`, `arrival_mode` (walk_in/ambulance/referral — an operational route), `chief_complaint`
  (free text recorded at arrival), `status`, nullable `disposition` (admit/discharge/transfer — recorded at
  the end; the G5 handoff detail) + `dispositioned_at`. **`status` is out of `$fillable`** — it moves only
  through the legal-only transition machine (`forceFill` in the service). **Legal-only lifecycle:**
  `arrived → triaged → in_treatment → awaiting_disposition → dispositioned` (+ `left_without_being_seen` from
  the pre-treatment states arrived/triaged). `canTransition()` guards; illegal moves throw.
- **`EdVisitEvent`** (`BelongsToTenant`, `LogsReads`, **APPEND-ONLY** — model `updating`/`deleting` guards +
  `SIGNAL '45000'` DB triggers, the `surgical_case_events` recipe) — one immutable row for **arrival** + one
  per transition (`arrived`/`triaged`/`in_treatment`/`awaiting_disposition`/`dispositioned`/
  `left_without_being_seen`) + optional reason + who + when. A correction is a NEW row.

## The triage-acuity SEAM (G1 — the FENCE crux, empty)

`Contracts\TriageAcuityProvider` (interface: `suggestAcuity(AcuityContext): AcuityResult`) is bound in
`EDServiceProvider::register()` to `Services\NullTriageAcuityProvider` (returns `AcuityResult::none()` — no
suggestion, asserts nothing). This MIRRORS Pharmacy's `MedicationSafetyProvider` → `NullMedicationSafetyProvider`
and Clinical's `LabConnectivity` → `ManualLabConnectivity` (referenced by NAME only — ED imports no peer
vertical). **CareOS builds the SEAM, not the logic.** `Support\AcuityContext` (the input a partner would read)
+ `Support\AcuityResult` (an optional advisory suggested level; `none()` is the fence-clean default).

**THE FENCE LINE (the sharpest in the vertical).** A **COMPUTED** triage acuity (system takes vitals+complaint
→ produces the ESI/Manchester/CTAS level) IS performing triage — a regulated **medical device**, the electric
fence line (`AGENTS.md:36-39`), literally eval-locked (`ClinicalAgentsEvalTest.php:273` refuses `triage`), and
a **permanent homemade non-goal**. The seam ships EMPTY and stays empty until a certified partner fills it
(advisory + human-owned, never auto-assigning/auto-prioritising). The **nurse-ASSIGNED** acuity (a recorded
fact, the `Stay::admission_type` / surgical-ASA precedent) is the buildable version — recorded in a separate
triage record in **G2**, NOT on the `EdVisit` (no acuity/triage/priority/severity/score column exists on it).

## RBAC (additive)

New permissions `ed.manage` (register/advance a visit) + `triage.record` (used in G2). New role templates:
`ed_physician` (ed.manage + admission.manage — for the ED→ADT admit handoff, G5), `triage_nurse` (ed.manage +
triage.record), `ed_charge_nurse` (runs the board — + note.supervise + reporting.view). `org_admin` gains both
perms. Re-provisioned for existing tenants via `2026_08_06_000003_add_ed_permissions.php` (the
`add_surgery_permissions` precedent). No `Gate::before` change; `RbacNegativeSweepTest` untouched (only base
roles/perms are in its withheld-map).

## Boundaries / posture

- **Arch:** `Modules\ED` may use Platform + care modules (Patients/People/Clinical/Billing/Scheduling) + Audit
  SERVICES, but **not** `Audit\Models`, `AiCore`, Comms, or the peer verticals (Nursing/Dental/Hospital/
  Pharmacy/Surgery). The ED→ADT link (G5) is a soft app-layer `stay_id`, NOT a Hospital dependency.
  `arch('ED …')` in `tests/Architecture/ModuleBoundariesTest.php`.
- **Audit:** the `EdVisit.created` (`ed_visit.registered`) + `EdVisitEvent.created` (`ed_visit.<event_type>`)
  hooks live in `app/Providers/AppServiceProvider.php` (app-layer composition), so ED stays free of Audit —
  the Dental/Hospital/Pharmacy/Surgery posture. Patient-scoped.
- **Fence:** an ED visit + its flow state are OPERATIONAL facts. No computed acuity/priority/severity anywhere;
  the seam is empty; the module computes no acuity (grep over `Modules\ED\src` is clean — the fence test
  asserts no compute/score/calculate-acuity method exists).
- **No money math** in ED (billing is G6, via the existing engine).

## Gate log

- **ED.G1** (this gate): module + `EdVisit`/`EdVisitEvent` + the empty triage-acuity seam + ED RBAC. 10 feature
  tests (`tests/Feature/ED/EdVisitTest.php`) + arch boundary + reprovision migration. See D-130.

## Not built yet (later gates)

G2 triage record (nurse-assigned acuity + raw vitals — reuse `Vital`) · G3 tracking board (reuse the
ward-board idiom) · G4 ED documentation (reuse `Encounter`/`ClinicalNote`/`Vital`) · G5 disposition + the
ED→ADT handoff (admit = create a Phase-1 `Stay`, `admission_type=emergency`, app-layer) · G6 ED billing (the
existing engine, reconciles-to-the-unit). **Computed triage acuity is a PERMANENT non-goal** (certified
partner behind the seam). See `docs/HOSPITAL-PHASE6-ED-MAP.md`.
