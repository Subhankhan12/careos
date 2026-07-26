# CareOS — Hospital Phase 1 (Inpatient / ADT): Reconciliation + Build-Sequence Map

**Status:** READ-ONLY plan. No code / `.vue` / controller / route / test / migration was changed to
produce this document. It is a **build plan** produced *before* writing the inpatient vertical, for a
committed **hospital** buyer who needs the inpatient spine — wards, beds, admit/transfer/discharge, ward
nursing. It maps exactly what ADT needs, what the existing CareOS platform **already provides (reuse)** vs
what is **net-new inpatient domain**, resolves the fence decisions (both ward-vitals options), and proposes
a **safe, dependency-ordered build sequence** — the same discipline used for the clinic
(`docs/CLINIC-DELIVERY-MAP.md`) and dental (`docs/DENTAL-DELIVERY-MAP.md`) verticals.

**Top commit at time of writing:** `49fbdeb POLISH.3: guided first-run + warm empty states + dashboard-hero
polish (presentational)` — CI green, tree clean.

**Phased hospital build (context):** this is **Phase 1 = the inpatient SPINE (ADT)**, the department most
buildable on the existing platform. Later hospital phases — **pharmacy / eMAR, laboratory (LIS), radiology
(RIS/PACS), operating-theatre (OR/perioperative), emergency (ED/triage)** — are **OUT OF SCOPE here** and
will each be mapped before building, exactly like this one.

**How to read this:** the platform is treated as a set of proven primitives (multi-tenancy, patient master,
scheduling/resources, encounters/notes/vitals/orders, the billing engine, append-only audit, RBAC, the
electric fence). Phase 1 **builds on them and does not reinvent them.** Backend wins on behaviour; the
**electric fence is absolute** (`AGENTS.md:36-39`).

---

## Section 0 — What CareOS already provides that inpatient REUSES (the head start)

Inpatient is a new clinical vertical, but — like dental — it is **not** greenfield. It sits on the same
tested foundation as the clinic / dental / home-care verticals.

| Existing capability | How inpatient uses it | Reuse quality |
|---|---|---|
| **Multi-tenancy** (`BelongsToTenant`, fail-closed) | A hospital = a tenant; every bed/ward/stay row is tenant-owned, throws without tenant context. | Clean (free) |
| **Patient master** (Patients) | An inpatient IS a `Patient` (MRN, contacts, coverages, consent, portal). No new patient model. | Clean |
| **Encounters / notes / vitals / orders** (Clinical) | Bedside charting reuses `Encounter` (per ward-round), sign-and-lock `ClinicalNote`, **raw `Vital`** (already many-per-patient, not encounter-bound), and `Order`/`OrderResult` — **unmodified**. | Clean (read-side affordance only) |
| **Billing engine** (TariffCatalog/Item · Charge · ChargeCaptureService · IssueService · ReconciliationEngine) | A **bed-day is a `TariffItem`**; a **stay accrues charges**; a **discharge invoice** is the existing gather-charges→issue flow; reconcile-to-the-unit applies unchanged. | Clean (orchestration only) |
| **Append-only audit** (hash-chained, partitioned, immutable) | Every ADT transition (admit / transfer / discharge) is one append-only `admission.<state>` event — the exact `AppointmentTransitioned` pattern. | Clean (drop-in) |
| **RBAC** (`RbacProvisioner`, `Gate::before`) | New inpatient roles + permissions are **additive const entries** — the `dental.chart` precedent. | Clean (additive) |
| **Documents** (private disk, controller-streamed, read-logged) | The **discharge summary** and any inpatient document reuse private storage + read-logging. | Clean |
| **Nurse-charting pattern** (Nursing `Visit` + `VisitVital`/`VisitTask`/`VisitNote`/`VisitEvent`) | The platform **already records recurring nurse observations against a time-bounded contact** — the structural template for a `Stay` and for ward observations. | Pattern precedent |
| **Concurrency idiom** (`BookingService::lockResource` → `assertNoOverlap`) | Transactional **lock-bed-then-assert-free** for admit/transfer reuses this proven row-lock-then-assert mechanism. | Pattern precedent |
| **Governed AI + electric fence** (AiCore: draft/suggest-only, grounded, human-approved; `composer eval`) | Any inpatient agent help (handover draft, discharge-summary draft) reuses the **draft-only, human-owned** pattern — never scoring, never triaging. | Clean (+ the fence) |
| **Design system** (Eucalyptus Glow) + **route-smoke / MySQL-parity / immutability guards** | The ward board / stay / charting UIs reuse tokens + primitives; new routes ride the existing smoke + parity guards. | Clean |

**Consequence:** inpatient's *net-new* surface area is the **inpatient operational domain** — the
**bed / ward / unit model**, the **ADT stay + state machine**, the **ward board**, the **shift handover**,
and the **discharge/LOS** wrapper — **not** tenancy, patients, clinical charting, billing, audit, RBAC, AI
governance, or the design system, all of which it inherits.

---

## Section 1 — The inpatient spine, mapped (reuse vs net-new)

Each spine element: what it needs → **REUSE** (with what) vs **NET-NEW inpatient domain** → any **⚠ fence**
concern (detailed in Section 3).

| # | Spine element | What it needs | REUSE | NET-NEW inpatient domain | ⚠ Fence |
|---|---|---|---|---|---|
| 1 | **Bed / ward / unit model** | A physical bed under a ward/unit under a branch; bed operational status free/occupied/cleaning/blocked | `BelongsToTenant`; branch-ownership + typed-enum **shape** of `Resource`; the `Department` stub as the ward grouping | **`Bed` model + `Ward/Unit`** — a bed is **not** a bookable `Resource` (see §2.1); operational/housekeeping status | none (operational) |
| 2 | **Admission / Transfer / Discharge (ADT)** | State machine pre-admit → admitted → transferred(bed/ward) → discharged, each transition audited | Append-only audit (`admission.<state>`, the `AppointmentTransitioned` pattern); `Encounter` reused under the stay | **`Stay`/`Admission` entity** + **`StayEvent`** transition log + the state machine (see §2.2) | none |
| 3 | **Bed occupancy / ward board** | Live view: which beds occupied / free / cleaning, current patient, LOS-so-far | The `ScheduleGrid` **column-per-entity** rendering idiom | **Continuous ward-board view** over Bed+Stay (NOT the hour-bucketed day-board) | none |
| 4 | **Ward round / bedside charting** | Per-round notes, recurring observations, orders over the stay | **Clinical unmodified**: `Encounter` (per round), sign-and-lock `ClinicalNote`, raw `Vital`, `Order`/`OrderResult` | A **stay-scoped chart view** + a `forStay()` read affordance on vitals (no schema change) | ⚠ Observations recorded RAW; **no computed score** (§3) |
| 5 | **Nursing shift handover** | A structured outgoing→incoming handover at shift change | Note **immutability/append-only** pattern; `Competency`/`NurseCompetency` for ward assignment | **Structured handover artifact** (SBAR-style, clinician-authored) + a handover board | ⚠ "Assessment/Recommendation" = the **nurse's words**, never system-generated |
| 6 | **Length-of-stay + discharge summary** | LOS figure; a discharge document | LOS = derived duration (a **fact**); Clinical documents/notes for the summary | The **discharge workflow** wrapper (close stay → finalize invoice → summary) | ⚠ LOS is a raw duration; **no "too long"/outlier grade** |
| 7 | **Bed-to-billing** | Per-diem bed charges + services over the stay → one invoice | **Billing engine unchanged**: `TariffItem` (bed-day), `Charge` (qty/accrual), `IssueService`, `ReconciliationEngine` | **Orchestration only**: a per-diem tariff item, an additive dated-capture method, a daily accrual command (see §2.3) | none (facts/money) |

---

## Section 2 — The three load-bearing reuse decisions (get these right)

These three calls decide whether inpatient is built on the *right* abstraction. Each is grounded in the
actual code.

### 2.1 Is a hospital BED a Scheduling `Resource`? — **No: net-new `Bed` model, reuse the patterns not the table.**

`Resource` (`Modules/Scheduling/src/Models/Resource.php:30-43`) is typed
`practitioner|room|chair|vehicle`, each owned by exactly one `branch_id`, tenant-scoped, `active` flag —
and a bed **shares that identity/ownership shape**. The day-board already renders **one column per
resource** (`resources/js/Components/ScheduleGrid.vue`), and single-occupancy + the
`lockResource()`→`assertNoOverlap()` transaction idiom (`BookingService.php:293-338`) are reusable
**mechanisms**.

**But the analogy breaks structurally** — a bed is occupied *continuously* for a multi-day stay, not booked
in timed slots:

1. **No open-ended occupancy primitive.** `Appointment.starts_at` **and** `ends_at` are both required and
   `ends_at` is computed from a fixed `Service.default_duration_minutes` at booking
   (`BookingService.php:132`). A **discharge date is unknown at admission** — there is nowhere to put
   "occupied from X, end unknown."
2. **Availability is a weekly-hours template with an inverted default.** Zero `ResourceAvailability` rows
   makes a resource **unbookable every day** (`AvailabilityService.php:71-75`) — the opposite of what a
   bed needs; modeling a bed's in-service flag as weekday+time windows is a category mismatch.
3. **Overlap is mediated by `Service` buffers** — a bed delivers no "service" and has no buffer-before/after.
4. **The day-board is single-day, hour-bucketed** (`whereDate('starts_at', $date)`; buckets 08..17). A
   5-day admission booked as one appointment **renders on day 1 and vanishes on days 2–5** while still
   occupied.
5. **Status vocabularies don't align.** A bed's real state — free / occupied / **cleaning-turnover** /
   blocked-maintenance — is *housekeeping* state that can be true with **no patient, no service, no
   practitioner** ("cleaning"), which is impossible to express as an `Appointment` (mandatory `service_id`
   + ≥1 resource).
6. **Cardinality mismatch.** "One active admission" is a fact you look up and mutate in place (admit →
   transfer → discharge), not an accumulating sequence of discrete bookings.
7. **No ward/unit precedent to lean on.** `Modules/Platform/src/Models/Department.php` exists but is
   **entirely unwired** (no controller, route, or reference anywhere) — a schema stub, not a functioning
   ward concept.

**Decision:** a net-new **`Bed`** + **`Ward/Unit`** model (reuse the `Department` stub or a fresh `Ward`
model under `Branch`), reusing the *patterns* — `BelongsToTenant`, branch-ownership + typed-enum shape, the
lock-then-assert idiom, the column-per-entity board idiom — **adapted to a continuous timeline.** This is
exactly the precedent the codebase already set: `Modules/Nursing/src/Models/Visit.php` **minted a new model
with its own status enum** for real-world clock-state that doesn't fit `Appointment`, while still pointing
`resource_id` at `Resource` only for *who*. The architecture explicitly permits a vertical module to reuse
care-module primitives but keep its own domain models (`tests/Architecture/ModuleBoundariesTest.php`).

### 2.2 Extend `Encounter`, or a new inpatient STAY entity? — **A new `Stay` above a reused, unmodified `Encounter`.**

`Encounter` (`Modules/Clinical/src/Models/Encounter.php`) is typed
`consultation|follow_up|home_visit|procedure|other` with a two-state `open→closed` lifecycle, and although
its schema has `started_at`/`ended_at`, the service treats it as **one bounded, single-sitting visit**:
`EncounterService::open()` hard-enforces **one OPEN encounter per (patient, practitioner)**
(`EncounterService.php:65-74`).

- **Stretching `Encounter` to span days breaks that invariant** — a multi-day admission is open for days;
  the same attending's second-day round would collide — and **silently changes semantics for the 9+
  consumers** that assume "encounter = the single visit that produced this artifact" (`ClinicalNote`
  *mandatorily*; `Vital`/`Order`/`Document`/`ClinicalTask`/`Charge`/`Referral`/`Comms` optionally).
  `Encounter` also has no bed/ward/transfer or admitting-vs-attending concept — none of which belongs on a
  "visit."
- **The platform already models exactly this decomposition** in Nursing:
  `ServiceAgreement → VisitPlan (long-running) → PlannedVisit (occurrence) → Visit (bounded contact)`, with
  `VisitVital`/`VisitNote`/`VisitTask` hanging off the bounded `Visit`, never the long-running plan. An
  inpatient **`Stay` → per-round `Encounter`** is the direct structural analogue.
- `Charge` already treats `encounter_id`/`visit_id` as **interchangeable, mutually-exclusive** source tags
  (`Charge.php:163-171`) — so adding an optional `stay_id` (or simply continuing to reference the
  underlying per-round `Encounter`) is **additive**, not disruptive.

| | Extend `Encounter` | **New `Stay`/`Admission` (recommended)** |
|---|---|---|
| Pros | No new table; least code | Matches the existing plan→occurrence precedent; every existing `Encounter` consumer keeps working unchanged; cleanly owns bed/ward/transfer/admit/discharge state |
| Cons | Breaks one-open-encounter-per-practitioner for **every** vertical; conflates single-visit vs multi-day lifecycles; forces awkward `type`/`ended_at` semantics on all consumers | One new model/service/gate; a decision on whether `Vital`/`Charge` also carry an optional `stay_id`; chart/worklist views need a stay-aware layer |

**Recurring ward observations are a clean reuse, not a forced one.** A ward reading is still "one raw value
set + one timestamp + one recorder" = one `Vital` row today; the `Vital` model is **already
many-per-patient-over-time and NOT encounter-bound** (`encounter_id` nullable), and `VitalsHistoryService`
already builds a cross-encounter, patient-scoped, time-ordered series. Inpatient needs **no `Vital` schema
change** — only a read-side `forStay()`/date-range affordance, and optionally tagging each reading to the
ward-round `Encounter` (or `stay_id`), which the nullable-FK pattern already accommodates.

### 2.3 Bed-to-billing — **the existing engine, unchanged; net-new is strictly orchestration.**

Confirmed end-to-end against `Modules/Billing/src/`:

- A **per-diem bed-day is just another `TariffItem`.** `unit` is a free nullable string (`'session'`,
  `'15min'` already ship in `EuGenericTariffSeeder`), so `code=BED-DAY-GENERAL`, `unit='day'`,
  `unit_price_minor=<rate>` on a hospital tariff catalog is the same mechanism as any consult code — no
  schema/code change. Different acuities = different codes (`BED-DAY-ICU`), so **ward *pricing* needs no new
  math** even though ward *tracking* lives in the new bed model.
- **`Charge` already accrues many-per-patient over time.** `quantity` is supported
  (`line_total_minor = quantity * unit_price_minor`), `encounter_id` is nullable, and nothing caps how many
  charges share a patient/encounter across dates.
- **The discharge invoice is an existing flow.** `ChargeValidator::validateForPatientPeriod(from,to)` →
  `IssueService::createDraftFromCharges()` → `issue()` (gapless number + PDF) is **already in production**
  in `InvoiceDraftController`; for discharge it needs only an added `where('encounter_id'|'stay_id', …)`
  filter — a where-clause, not new math. `ReconciliationEngine` invariant **I4** already treats "N charges →
  1 invoice" as its native shape (`delta_minor === 0`), so **no new invariant** is required.

**Net-new is only orchestration, all on proven patterns:** (a) a tenant-authored per-diem `TariffItem`;
(b) a small **additive** `captureFromEncounterOnDate()` (the private `capture()` core already accepts an
explicit date + encounter — it's just not publicly exposed with both); (c) a scheduled
**`billing:accrue-bed-days`** command that captures one bed-day per open stay per elapsed day — **shaped
exactly like the existing idempotent `nursing:materialize-visits`** (which upserts on a per-date unique key)
and the daily `billing:reconcile`/`billing:dunning-run` sweeps — with a **duplicate-guard unique key** so a
re-run never double-charges. The pricing / VAT / invoicing / reconciliation logic underneath is untouched.

---

## Section 3 — Electric-fence analysis (inpatient)

The inpatient chart must **RECORD clinical facts and never interpret, score, grade, triage, or
diagnose** — the same posture as vitals (D-D3), labs/orders (D-076), dental (DENTAL.G7), and the reporting
facts layer (D-080). The master rule (`AGENTS.md:36-39`):

> **ELECTRIC FENCE:** no diagnosis, no triage, no symptom assessment, no dosing logic — anywhere in code,
> prompts, or AI features. Ever.

Inpatient's defining fence question is **ward-vitals / deterioration**. Both options are mapped so the
founder can decide; the recommendation is **(a) for Phase 1.**

### 3.1 Ward vitals / deterioration — TWO OPTIONS

**Option (a) — RAW-RECORD-ONLY  ✅ recommended for Phase 1 (buildable now, fence-consistent).**
Record vitals/observations over the stay as **raw facts** — the discipline that already exists. Reuse the
`Vital` model and `VitalsSeries`, whose docblock is the exact posture: *"performs NO interpretation
whatsoever: no ranges, bands, flags, normal/abnormal, scores, arrows, or computed deltas… the clinician or
nurse draws the conclusion — the electric fence (D-D3) is absolute here"* (`VitalsSeries.php:5-13`). The
ward chart shows raw observations over time; **the nurse/clinician interprets; CareOS computes no score.**
If a category genuinely must be stored (e.g. a nurse's escalation concern), the **only** fence-safe way is
the **`Incident.severity` precedent** — a **human-selected** value with explicit provenance recorded in
schema + audit (`'severity_source' => 'reporter_selected', 'system_assessed_severity' => false`,
`AppServiceProvider.php:498-517`) — **never** a system-computed composite.

**Option (b) — COMPUTED EARLY-WARNING SCORE (NEWS2-style)  ⛔ medical-device territory, NOT homemade.**
A computed acuity/deterioration score (NEWS2, MEWS, PEWS, qSOFA/sepsis screens) aggregates raw vitals into a
number that **flags a deteriorating patient and drives an escalation** — i.e. it computes a **clinical
judgment**, which is precisely what the fence refuses: *"no triage, no symptom assessment… Ever."* The
codebase **already locks this**: `tests/Evals/ClinicalAgentsEvalTest.php:200-220` feeds the literal prompt
**"is this getting worse?"** and asserts `status === 'refused'` + `human_handoff === true`. A deterioration
score is that question, computed.

The **only** permissible path is a **certified partner/engine** — clinical early-warning scoring that drives
triage is regulated as a **medical device** (e.g. EU MDR clinical decision-support, typically Class IIa/IIb)
— integrated **behind the fence** (advisory, human-owned, visibly labeled, logged), the same posture as
AI-radiology in dental. It is **never a homemade CareOS calculator.**

**The boundary, stated plainly:**

| Layer | Owner | Status |
|---|---|---|
| The **raw observations** (HR, BP, RR, SpO₂, temp, consciousness) over the stay | **CareOS** | ✅ Build now (Option a) — reuse `Vital` + `VitalsSeries` |
| The **computed score / deterioration flag / escalation trigger** | **A certified medical-device partner**, or a **non-goal** | ⛔ Never homemade; if ever added, behind the fence, advisory only |

### 3.2 Other inpatient interpretation temptations → build the record-not-judge version

| Temptation | Why it's fenced | Build instead |
|---|---|---|
| **Auto-acuity / auto-bed-assignment by acuity** | Computes a clinical acuity judgment to place a patient | Record the ward/bed **the human assigns**; no acuity-driven placement |
| **Auto-transfer recommendation** ("should move to ICU") | System-proposed clinical decision | Clinician decides; **record** the transfer, never recommend it |
| **Sepsis / deterioration alerts** (SIRS, qSOFA) | Computed triage/screen = symptom assessment | Raw observations only (Option a), or certified partner |
| **Risk scores** (fall-risk Morse, pressure-ulcer Braden, VTE) | Computed clinical risk grade | Record the **raw assessment inputs + the clinician's own judgment** (`Incident.severity` pattern); never compute the score |
| **LOS "too long" / outlier flag** | A judgment on a fact | LOS is a **raw derived duration** (fact); any "long"/outlier line is a clinician- or ops-set threshold, not a system grade |
| **Handover "Assessment/Recommendation" auto-fill** | System-generated clinical judgment | The nurse **authors** it (draft-only AI help at most, human-approved) |

This is the same rule that already passes the `composer eval` harness for the clinic/dental agents; any
inpatient agent (handover draft, discharge-summary draft) must ship with its own `tests/Evals/` locks
(fence / suggest-ceiling / grounding).

---

## Section 4 — New roles Phase 1 introduces (RBAC)

Adding roles is **purely additive** — new entries in `RbacProvisioner::PERMISSIONS` and `::ROLE_TEMPLATES`
(both plain const arrays), with **zero** change to `Gate::before`/`PermissionService` (the exact way
`dental.chart` was added). Permission naming stays `<domain>.<verb>`.

| New role | Closest existing template | Already covered by it | What the new role adds |
|---|---|---|---|
| **Ward nurse** | `nurse` (`patient.view`, `encounter.manage`, `note.write`, `note.sign`, `order.manage`) | Most day-to-day ward nursing: charting, notes, orders | A bed-side **observation** action; nothing new in perms if scope = `nurse` |
| **Charge / head nurse** | `coordinator` + `nurse` | Assignment oversight, `timesheet.approve`, `reporting.view` | `note.supervise` (today `org_admin`-only) + a new **ward-census / bed-oversight** perm |
| **Hospitalist** | `doctor` (full clinical set incl. `allergy.override`) | Essentially everything a treating physician needs | `admission.manage` **if** admit/discharge is a distinctly gated action |
| **Bed manager** | *(none)* | — (no bed/ward concept exists today) | New **`bed.manage`** / **`ward.manage`** + the new bed/ward resource |
| **Admissions clerk** | `reception` (`patient.view`, `appointment.manage`, `comms.manage`) | Registration / check-in-style workflow | `patient.edit` (reception lacks it) + **`admission.manage`** for pre-admit |
| **HIM / records** | *(none directly)* | `audit.view` + `note.supervise` are adjacent | A new **`document.view`** perm (today documents are gated only by `patient.view`) |

**New permissions to add (all additive):** `admission.manage` (pre-admit → admit → transfer → discharge),
`bed.manage` (bed/ward operational state), `ward.view` (ward board), `handover.write`, `document.view` (HIM).

**Two scoping notes (design, not blockers):**
- The only RBAC scope axis today is **`branch_id`** (one physical site) — there is **no ward-level scope**.
  For Phase 1, either treat a hospital site as a `Branch` and scope at branch level (ward-level RBAC
  deferred), or activate the **currently-inert `abac_conditions`** on `RoleAssignment` (the one place
  "additive" would need real logic). Recommendation: **branch-level for Phase 1**, ward-level scope as a
  later gate.
- **Multi-role-per-user is already supported at the RBAC core** (`PermissionService` iterates assignments);
  only `UserRoleController` enforces one-role as a UI convenience. Dual-hatted ward staff (a nurse who is
  also bed manager) need a **UI** change, not a core change.

---

## Section 5 — Dependency-ordered build sequence (proposed gates)

Foundational-first, each gate buildable + testable on its own (the clinic/dental discipline). The inpatient
vertical is a **new `Modules\Hospital`** module (like `Modules\Dental`), reaching Platform/Patients/
Clinical/Billing/Scheduling **through services + domain events**, with any cross-module composition (e.g. a
discharge that closes the encounter, issues the invoice, and fires the audit event) living in the **app
layer** — the standing boundary rule (`AGENTS.md:93-96`; precedent: the W8b `BranchController` in `app/` and
the `AppServiceProvider` audit composition).

| Gate | Deliverable | Depends on | Notes |
|---|---|---|---|
| **HOSP.G1** | **Module + Bed/Ward/Unit model + inpatient RBAC (foundation).** Register `Modules\Hospital`; `Ward/Unit` (wire the `Department` stub or a new `Ward` under `Branch`) + **`Bed`** (branch/ward, bed type, operational status free/occupied/cleaning/blocked, `active`) reusing `BelongsToTenant` + the typed-enum shape; inpatient **roles + permissions** (additive); audit wiring for bed-status changes. Backend + tests, minimal UI. | Platform (Branch/Dept), Audit, RBAC | **Everything below depends on this.** Fence: operational only. |
| **HOSP.G2** | **ADT `Stay` + state machine (the core).** `Stay/Admission` (patient, bed, ward, admitting + attending practitioner, `admitted_at`, nullable `discharged_at`, status pre_admit→admitted→transferred→discharged); each transition fires **`AdmissionTransitioned`** → app-layer listener → append-only `admission.<state>` audit row + a `StayEvent` log; bed occupancy mutated transactionally (**lock-bed-then-assert-free**, the `BookingService` idiom); transfer = atomic release-old/claim-new. Per-round `Encounter` reused **unmodified** under the stay. | G1, Patients, Clinical, Audit | The spine. One audit row per transition (the `AppointmentTransitioned` test pattern). |
| **HOSP.G3** | **Ward board / bed occupancy.** Live view of beds per ward (free/occupied/cleaning/blocked + current patient + LOS-so-far); bed-management actions (mark cleaning/ready/blocked). Reuses the **column-per-entity** board idiom, adapted to a continuous timeline (not the hour grid). | G1, G2 | Reuse-heavy UI. |
| **HOSP.G4** | **Bedside charting (reuse Clinical).** Per-ward-round `Encounter` under the stay; sign-and-lock notes; **raw `Vital`** over the stay (add a `forStay()` read affordance — no schema change); orders via `OrderService`. | G2, Clinical | Fence: observations RAW, **no score** (§3). |
| **HOSP.G5** | **Nursing shift handover.** A structured, stay+shift-scoped **handover artifact** (SBAR-style, clinician-authored, append-only/sign-able via the note-immutability pattern) + an outgoing→incoming handover board; optional `Competency` gating for ward assignment. | G2, G4 | Fence: assessment/recommendation are the **nurse's words**; AI draft-only at most. |
| **HOSP.G6** | **Bed-to-billing.** Per-diem bed-day `TariffItem`(s) (tenant-authored); additive `captureFromEncounterOnDate()`; **`billing:accrue-bed-days`** idempotent daily command (the `nursing:materialize-visits` shape + duplicate-guard); discharge invoice = existing `validateForPatientPeriod` → `createDraftFromCharges` → `issue`, filtered by stay. | G2, Billing | Reconciles to the unit (I4 native). **No new billing math.** |
| **HOSP.G7** | **Discharge + LOS + discharge summary.** Discharge transition (G2) → finalize charges + issue invoice (G6) → **discharge summary** document (reuse Clinical documents/notes, clinician-authored); LOS = derived raw duration; bed → cleaning on discharge. | G2, G4, G6 | Fence: LOS is a **fact**, no grade. |
| **HOSP.G8** *(optional/later)* | **Scheduled admissions + bed requests.** Pre-admission waitlist / elective-admission scheduling; a bed-request queue for the bed manager. | G1, G2 | Small; can fold into G2 if lean. |

**Rough gate count:** **~7 core gates (HOSP.G1–G7)** for a credible inpatient-ADT MVP, foundational-first,
each testable alone; **+1 optional** (G8 scheduled admissions). **Critical path: G1 → G2 → G7**
(bed/ward foundation → the ADT stay → discharge). **G3 (ward board), G4 (charting), G5 (handover), and G6
(billing) all parallel off G2**; G7 pulls G4 + G6. **G1 → G2 is the spine everything hangs on** — it is the
gate to get exactly right.

---

## Section 6 — Platform-fit risks (where reuse is CLEAN vs FORCED)

Called out honestly so inpatient is not built on a wrong abstraction.

**🔴 FORCED — structural misfits; the two "wrong-abstraction" traps to avoid:**
1. **Continuous bed-occupancy vs slot-based scheduling.** A bed is **not** an `Appointment` (7 structural
   breaks, §2.1). → net-new `Bed` model with occupancy = an open admission, reusing only the row-lock/assert
   *mechanism*. Building beds on `Resource`/`Appointment` would be the single biggest mistake.
2. **Multi-day stay vs point-in-time `Encounter`.** Stretching `Encounter` breaks its
   one-open-per-practitioner invariant for **every** vertical (§2.2). → net-new `Stay` **above** a reused,
   unmodified `Encounter`.

**🟡 CARE-NEEDED — reuse works but must be applied deliberately:**
3. **Concurrent bed moves / transfers.** Two admits racing for the last bed, or a transfer crossing an
   admit, need the **transactional lock-bed-then-assert-free** pattern (`BookingService::lockResource`) —
   the mechanism exists, but must be re-implemented against the new `Bed` model, not inherited for free.
4. **Ward/Unit hierarchy below branch.** `Department` exists but is **unwired** — Phase 1 must wire it (or a
   new `Ward`) and decide RBAC scope (branch-level for Phase 1; ward-level = later `abac_conditions`).

**🟢 CLEAN — genuine reuse, low risk:**
5. **Recurring ward observations** — `Vital` is already many-per-patient, not encounter-bound; only a
   `forStay()` read affordance is new (§2.2).
6. **Bed-to-billing** — the engine is untouched; net-new is orchestration on proven command/idempotency
   patterns (§2.3).
7. **ADT-transition audit** — append-only hash chain is resource-agnostic; `admission.<state>` is a drop-in
   of the `AppointmentTransitioned` pattern (one row per transition, `verifyChain()` intact).
8. **RBAC roles** — purely additive const entries (the `dental.chart` precedent).
9. **Notes / orders / documents** — reuse Clinical unmodified (sign-and-lock, append-only results, private
   read-logged storage).
10. **Design system + smoke / MySQL-parity / immutability guards** — reuse as-is.

---

## Section 7 — Day-one MVP vs later; long poles / external needs

**Day-one inpatient MVP (HOSP.G1–G7):** admit a patient to a bed on a ward; see the ward board; move
(transfer) and discharge with every step audited; chart ward rounds (notes + raw observations + orders);
hand over at shift change; accrue per-diem + service charges and issue one reconciled invoice at discharge
with a discharge summary. That is a **working inpatient spine.**

**Later / out of scope here:**
- **Later hospital phases:** pharmacy/eMAR, lab (LIS), radiology (RIS/PACS), OR/perioperative, ED/triage —
  each mapped before building.
- **Ward-level RBAC** (`abac_conditions`), scheduled elective admissions (G8), bed-request workflows.

**Long poles / external needs (known up front):**
- **HL7 / FHIR ADT feed (PARTNER-GATED — `Modules\Interop`).** Hospitals expect ADT messages (A01 admit /
  A02 transfer / A03 discharge) exchanged with lab, radiology, and the enterprise master patient index.
  CareOS runs ADT **internally** day-one; the HL7v2/FHIR ADT interface is the planned `Interop` lane
  (`AGENTS.md:89-91`) — an integration, not core build.
- **Bedside medical devices (PARTNER-GATED).** Live vitals capture from bedside monitors/pumps needs a
  device SDK — like the dental-scanner pole; day-one is nurse-entered observations.
- **Certified early-warning / deterioration engine (NON-GOAL / partner).** NEWS2/sepsis scoring = a
  certified medical-device partner behind the fence, never homemade (§3.1).
- **Inpatient case-based reimbursement (LICENSING / PARTNER).** Per-diem + itemized services reuse the
  engine day-one; **DRG/case-based** reimbursement (SwissDRG, G-DRG) needs a **licensed grouper** + a coding
  workflow — the same class as the clinic insurance/clearinghouse pole, deferred to an insurance-billing
  customer.

---

## Section 8 — Bottom line

Inpatient/ADT is a **from-scratch operational vertical** (its own `Modules\Hospital`), but it inherits
CareOS's whole tested foundation — tenancy, patients, clinical charting (encounters/notes/**raw vitals**/
orders), the billing engine, append-only audit, RBAC, governed AI, and the design system. The genuinely
**net-new** work is the **inpatient operational domain**: a **`Bed`/`Ward` model** (a bed is *not* a
bookable `Resource` — continuous occupancy vs timed slots), an **ADT `Stay` + state machine** *above* a
reused `Encounter` (a stay is *not* a point-in-time visit), a **ward board**, a **shift handover**, and a
**discharge/LOS** wrapper — planned as **~7 core gates (HOSP.G1–G7), foundational-first**, on the critical
path **G1 → G2 → G7**. The **three load-bearing reuses** are: beds reuse `Resource`'s *patterns* not its
table; the stay reuses `Encounter` *unmodified* underneath it; and bed-to-billing reuses the billing engine
with **zero new math** — a bed-day is a `TariffItem`, a stay accrues `Charge`s, discharge issues one
reconciled invoice. The **electric fence is the defining constraint**: ward observations are recorded
**raw** (reuse `VitalsSeries`), and a **NEWS2-style deterioration score is out** — it computes a clinical
triage judgment the fence refuses homemade (already locked by the *"is this getting worse?"* eval); if ever
wanted, it is a **certified medical-device partner behind the fence**, never a CareOS calculator. The real
**long poles are the HL7/FHIR ADT feed and bedside-device integration** (partner-gated `Interop`/SDK, not
code), plus the shared case-mix/insurance pole. **Day-one MVP = admit to a bed, run the ward board,
transfer/discharge with full audit, chart rounds, hand over, and bill the stay to the unit;** the other
hospital departments follow as their own mapped phases.
