# Module: Hospital (`Modules\Hospital`)

## Purpose

The inpatient / ADT hospital vertical (Phase 1 of a phased hospital build — later phases: pharmacy/eMAR,
lab, radiology, OR, ED, each mapped before building). Planned as ~7 core gates, foundational-first
(`docs/HOSPITAL-PHASE1-ADT-MAP.md`). **HOSPITAL.G1 ships the FOUNDATION only:** the bed/ward/unit domain
model + a concurrency-safe bed-claim primitive + inpatient RBAC. **No ADT workflow yet** (the admit/
transfer/discharge `Stay` state machine is HOSPITAL.G2) and **no UI** (the ward board is HOSPITAL.G3).
Hospital inherits the whole tested foundation — tenancy, patients, clinical charting, the billing engine,
append-only audit, RBAC, the design system — so its new surface is ONLY the inpatient operational domain.

## Ward modeling decision (G1)

The map offered two options for the ward/unit hierarchy: wire Platform's unwired `Department` stub, OR a
fresh Hospital-owned `Ward`. **Chose a Hospital-owned `Ward`** because (1) ward attributes will grow
inpatient-specific (capacity, unit type, later staffing/policies) and those belong in the vertical, not
Platform's generic `Department`; (2) it gives a clean bidirectional `Ward hasMany Bed` relation (a
`Department::beds()` back-relation is impossible — Platform must not depend on Hospital); (3) it mirrors how
every vertical owns its domain models (`Nursing\Visit`, `Dental`) while referencing only the Platform
foundation (`Branch`) — the proven `Visit → Branch` pattern. Platform's `Department` stub is left for its
original generic clinic-functional-department purpose; swapping the FK to `Department` later is localized.

## Key tables

- `wards` (BelongsToTenant) — an inpatient ward/nursing unit under a branch. Columns: id (ULID), tenant_id,
  branch_id, name, code, active. `unique(tenant_id, branch_id, code)`. Cross-tenant branch link rejected
  (`assertBranchWithinTenant`, like `Department`).
- `beds` (BelongsToTenant) — a NET-NEW model, deliberately **NOT a Scheduling `Resource`** (occupancy is a
  continuous multi-day stay, not a timed slot — so there is NO `starts_at`/`ends_at`). Columns: id (ULID),
  tenant_id, branch_id, ward_id, `label`, `bed_type` ∈ {general, icu, isolation}, `status` ∈ {free,
  occupied, cleaning, blocked} (default free), active. `unique(tenant_id, ward_id, label)`. **ELECTRIC
  FENCE: no patient / acuity / severity / score / risk / flag column — `status` is OPERATIONAL housekeeping
  (can be true with no patient/service attached, e.g. `cleaning`), never a clinical judgment.**

## Key classes

- `Models\Ward` — BelongsToTenant + HasUlids; `belongsTo(Branch)`, `hasMany(Bed)`; cross-tenant branch guard.
- `Models\Bed` — BelongsToTenant + HasUlids; `belongsTo(Branch)`, `belongsTo(Ward)`; cross-tenant branch+ward
  guard. Holds the type/status vocabularies + `TRANSITIONS` (the legal housekeeping state machine:
  free→{occupied,blocked}, occupied→{cleaning}, cleaning→{free,blocked}, blocked→{free}) + `canTransition()`.
  **free→occupied is reached ONLY through the concurrency-safe `BedService::claim()`, never `setStatus()`.**
- `Services\WardService` — `create`/`rename`/`deactivate`, each gated `ward.manage` server-side; tenant+branch
  scoped; audited via app-layer hooks.
- `Services\BedService` — `create`/`deactivate`/`setStatus` gated `bed.manage`; `claim` gated `admission.manage`;
  `forWard` reads a ward's beds + status (the data the G3 board will render). `setStatus` is a legal-only,
  row-locked housekeeping transition (rejects →occupied and illegal edges, throws `BedStatusTransitionException`).
  **`claim` applies the `BookingService::lockResource`→assert idiom to a bed:** `DB::transaction` +
  `SELECT status FROM beds WHERE tenant_id=? AND id=? FOR UPDATE`, assert still free under the lock, flip to
  occupied — so N racing claims yield exactly ONE winner (loser gets `BedNotAvailableException`). A private
  `lockBedStatus` centralises the tenant-scoped FOR UPDATE (cross-tenant id → `CrossTenantReferenceException`).
- `Events\BedStatusChanged` (bed, fromStatus, toStatus, actor, ?reason) — fired by BedService on every status
  transition; the app layer listens → `bed.status_changed` audit (so Hospital stays free of Audit).
- `Exceptions\BedNotAvailableException` (claim conflict), `Exceptions\BedStatusTransitionException` (illegal/
  use-claim). `Console\AttemptBedClaimCommand` (`hospital:attempt-bed-claim`, for the parallel hammer only).
- `Providers\HospitalServiceProvider` — loadMigrations + registers the console command.

## Invariants

- **Concurrency-safe occupancy:** a bed can be claimed (free→occupied) by exactly one winner under a row
  lock — proven by `BedClaimParallelHammerTest` (8 OS processes race one free bed → 1 CLAIMED, 7 CONFLICT),
  the sibling of `BookingParallelHammerTest`/`VisitAssignmentParallelHammerTest`.
- **Legal-only status transitions:** every housekeeping change is validated against `Bed::TRANSITIONS`
  (illegal throws) and audited via `BedStatusChanged` — one audit row per transition.
- **Tenant + branch scoped, fail-closed:** `BelongsToTenant` confines every query; a ward/bed pointing at
  another tenant's branch/ward throws `CrossTenantReferenceException`; a cross-tenant id is invisible.
- **ELECTRIC FENCE (operational, not clinical):** a bed/ward is housekeeping infrastructure — no patient
  link, no acuity/severity/score/risk/grade/flag anywhere in schema/service/event. Asserted by a schema
  fence test. (An inpatient deterioration score / NEWS2 is out — see the map §3: certified-partner or
  non-goal, never homemade.)

## Arch boundary

`arch('Hospital may use care modules + Audit services but not Audit models, AiCore, Nursing, or Comms')` —
mirrors Dental. Hospital may use Platform + Patients/Scheduling/Clinical/Billing + Audit SERVICES, never
Audit models directly, AiCore, the peer Nursing vertical, or Comms. Cross-module audit composition (the
bed/ward hooks + the `BedStatusChanged` listener) lives in `app/AppServiceProvider`, so Hospital references
no Audit. In G1 Hospital in fact depends only on the Platform foundation.

## RBAC (additive — the `dental.chart` precedent, `Gate::before` unchanged)

New permissions in the catalog: `ward.manage`, `bed.manage`, `admission.manage`, `document.view`. New
starter role templates (map §4): `ward_nurse` (= nurse clinical set), `charge_nurse` (nurse set +
`note.supervise` + `reporting.view` + `bed.manage`), `hospitalist` (doctor set minus `dental.chart` +
`admission.manage`), `bed_manager` (`ward.manage`+`bed.manage`+`patient.view`+`reporting.view`),
`admissions_clerk` (reception set + `patient.edit` + `admission.manage`), `him_records` (`patient.view`+
`note.supervise`+`document.view`+`audit.view`). `org_admin` gains all four new permissions. Ward/bed
management gates on `bed.manage`/`ward.manage`; claiming a bed on `admission.manage` (placing occupancy is
an admission act). **Ward-level scope is branch-level for Phase 1** (deeper ward scoping = a later
`abac_conditions` gate). The permission-count test (`RbacTest`) is relative, so additions stay green.

## ADT workflow (HOSPITAL.G2)

The core of the vertical — admit / transfer / discharge a multi-day `Stay`.

- `stays` (BelongsToTenant, **LogsReads** — patient-scoped read-logged) — a patient's inpatient episode, a
  NET-NEW entity **ABOVE an UNMODIFIED `Encounter`** (map §2.2; the `VisitPlan→Visit` analogue — G2 does NOT
  touch Clinical, so Encounter's one-open-per-practitioner invariant holds for every vertical; bedside
  charting reuses Encounter per ward-round in G4). The MUTABLE current state: `patient_id`, `branch_id`,
  `admitting_clinician_id` (StaffProfile), `current_bed_id`/`current_ward_id`, `admitted_at`,
  `discharged_at`, `status`, `admission_type`, `admission_reason`, `discharge_disposition`. **State machine:**
  `STATUSES` {admitted, discharged}; `TRANSITIONS` = admitted→discharged. A **transfer is a bed-move WITHIN
  admitted** (not a status change). `admission_type` ∈ {elective, emergency, transfer} (an operational ROUTE)
  is validated in the model's `creating` hook; cross-tenant refs rejected there too.
- `stay_events` (BelongsToTenant, **APPEND-ONLY** at model + DB-trigger level `stay_events_no_update`/
  `_no_delete`) — one immutable row per admit/transfer/discharge preserving the full bed journey (bed_id,
  from_bed_id, ward_id, reason, disposition, occurred_at, performed_by). A correction is a NEW event.
- `Services\AdmissionService` — `admit` / `transfer` / `discharge`, gated `admission.manage`, tenant+branch
  fail-closed (rejects a cross-tenant bed UP FRONT). **Each is ATOMIC** (the dental-perform discipline): the
  stay change + the bed claim/release (via G1's proven `BedService::claim`/`release` — NOT reimplemented) +
  the append-only `StayEvent` all happen in ONE `DB::transaction`, so a forced failure rolls back everything
  — no orphan stay, no stuck bed, and even the bed's audit row rolls back (proven). **admit** creates the
  stay + `claim`s the bed (free→occupied); **transfer** `claim`s the new bed then `release`s the old
  (occupied→cleaning), moves the stay; **discharge** `release`s the bed, sets disposition + discharged_at,
  transitions to discharged. **One-active-stay guard:** a patient row lock + `lockForUpdate()->exists()`
  check refuses a second active admission (the one-open-encounter analogue). Legal-only (illegal transitions
  throw `AdmissionException`). **No charge posted — bed-to-billing is G6.**
- `Events\StayTransitioned` → app-layer listener → one append-only `admission.<eventType>` audit row (keyed
  by event type since a transfer keeps status=admitted); the AppointmentTransitioned pattern. `BedService`
  gained `release()` (occupied→cleaning, `admission.manage`, same lock idiom). NEW `admission.manage` now
  also gates the ADT actions (it existed since G1).
- `Http\Controllers\AdmissionController` (string-id FIX.1) — the MINIMAL action surface (rich board = G3):
  `show` (GET `/hospital/admissions/{stay}`, `patient.view`, read-logged, renders `Hospital/Admission.vue`)
  + `store`/`transfer`/`discharge` (POST, `admission.manage`). Route-smoke extended (org_admin GET 200,
  reception POST admit 403).

## Status

**HOSPITAL.G1–G2 complete.** G1 = Bed/Ward model + concurrency-safe bed-claim + inpatient RBAC. **G2 = the
ADT `Stay` + admit/transfer/discharge state machine (atomic, bed-safe, above an unmodified Encounter).**
Verified: npm build green; composer check FULLY green; targeted — `WardBedManagementTest` (7),
`BedClaimParallelHammerTest` (1), `HospitalAdmissionTest` (12), arch + RBAC suites unchanged; smoke green.
See [[D-113]], [[D-114]], `docs/HOSPITAL-PHASE1-ADT-MAP.md`.

## Open items / next gates (per docs/HOSPITAL-PHASE1-ADT-MAP.md)

- **G2** *(done — D-114)* — ADT `Stay` + admit/transfer/discharge state machine ABOVE a reused, unmodified
  `Encounter`; each transition an append-only `admission.<event>` audit row + a `stay_events` history row; the
  admission wraps `BedService::claim()`, discharge/transfer `release()` the bed (occupied→cleaning); atomic +
  one-active-stay guarded. **Next: G3.**
- **G3** ward board (over Bed+Stay, the column-per-entity idiom on a continuous timeline) · **G4** bedside
  charting (reuse Clinical `Encounter`/notes/`Vital` + a `forStay()` read affordance) · **G5** shift handover
  (net-new SBAR artifact) · **G6** bed-to-billing (per-diem `TariffItem` + `billing:accrue-bed-days` idempotent
  command, no new math) · **G7** discharge + LOS + discharge summary.
- Long poles (partner-gated / non-goal): HL7/FHIR ADT feed (`Interop`), bedside device capture, certified
  early-warning/deterioration engine (NEWS2 — fence + regulated device, never homemade), DRG/case-mix grouper.
