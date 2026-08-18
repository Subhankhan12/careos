# CareOS — Onboarding (read this FIRST)

The single file a new session (Claude Code **or** Codex) reads first. After this, the read order below gives you
the COMPLETE, accurate context of everything built: **EIGHT verticals** (clinic · dental · home-care · inpatient ·
pharmacy · surgery · ED · lab · radiology) on one shared multi-tenant platform. **THE BUILD IS COMPLETE — all
hospital phases are built.** The next progress is **deployment + certified-partner integrations, not more code** —
AND, running alongside deploy prep, a page-by-page **WIREFRAME-PARITY PASS** (bring each app page up to its designed
wireframe without weakening a single enforced gate). See §5.

> **WIREFRAME-PARITY PASS — COMPLETE, all NINE pages (D-149 → D-160).** The loop that ran it, per page: **decode** the self-unpacking "bundler-shell"
> wireframe HTML → readable HTML in the **gitignored** `resources/prototype/` (no app change, no commit) → **AUDIT**
> the live page against it into `docs/wireframe-parity/<PAGE>-DIFF.md` (report-only commit) → **FIX** per-part as
> `P0D.GU` gates, **one part = one commit + STOP**, GATE REPORT + CI-green each. **The discipline (non-negotiable):
> match the LOCKED/CAPPED visual, but NEVER weaken a real enforced server gate (suggest-cap, mandatory 2FA,
> required reject-reason, the AutonomyPolicy ceiling, approve = re-authorise + re-ground + still-pending); never
> regress a "correctly-more-real" item; RBAC is reflect-only (server Gate stays authoritative); surface-don't-
> fabricate (every permission/ceiling/source shown is REAL or an honest absence — no faked control/count/copy).**
> **Where each page stands — ALL NINE COMPLETE; the pass is CLOSED (AUTH-VIS, `8a9a867`).** Beyond the seven
> listed here, **Appointment Detail** core (APPT.P1–P3, `ca90273`; the real APPT.P2 tip is the CI-fix `27fa22c`)
> and **Auth Screens** (AUTH-SEC.1 `4334017` + AUTH-SEC.2 `39c0413` + AUTH-VIS `8a9a867`) closed it.
> **No parity page remains — do not invent one.** The seven:
> Admin Settings (SETTINGS.P1–P6, `e7cabf0`) ·
> Approval Queue (APPROVAL.P1–P7, `ea0e9b3`) · Branches (BRANCH.P1–P5, `a865a31`) · Agent & Tool Config (AGENT.P1–P6,
> `d0199e3`) · Allergy Alert **safe-part** (ALLERGY.P1, `46e45d1` — record-display only; computed drug-allergy checking
> is a certified-partner MEDICAL-DEVICE non-goal, NOT built) · **Billing & AR (BILLAR.P1–P7, `aa82ea0`)** · **AR Account
> Detail — COMPLETE (ARDETAIL.P1–P6)**: P1 per-account running-balance ledger → P2 dunning timeline
> (real state machine, read-only) → P3 hero + Swiss `CHF x'xxx.xx` + status/dunning pills + chart/PDF links → **P4
> record-payment through the guarded `PaymentService`** (the first consequential write — over-allocation guard held
> through the page path, reconciles δ=0, operator-gated, audited, agent-EXCLUDED; D-152) → **P5 payment-plan**
> (installments TIE to the real outstanding δ=0 — an exact engine partition, never more than is owed — settled via
> the P4 guarded path so the plan moves no money; operator-created, agent-EXCLUDED; D-153) → **P6 Betreibung /
> debt-enforcement escalation** (a human legal act: the NEW, deliberately narrower `billing.escalate` permission
> [org_admin + billing only — charge-capturing clinical roles hold `billing.manage` and are refused];
> eligibility = the real dunning machine exhausted at its terminal level, fail-closed; an EXPLICIT operator
> confirmation + recorded reason; append-only + audited; **AGENT-EXCLUDED BY CONSTRUCTION** — no AiTool, no AiCore
> reference, and the only files reaching the service are the service, the model and the operator-gated controller,
> asserted as an exact list, so "0 auto-escalated" is structural; D-154). **NO decoded pages remain:** Appointment
> Detail CORE COMPLETE (APPT.P1–P3) and Auth Screens COMPLETE (AUTH-SEC.1 + AUTH-SEC.2 security fixes, then AUTH-VIS
> — the enrolment manual-secret fallback rendering the user OWN real secret; D-160) closed the pass. Along the way the
> audits found and fixed TWO High **live security defects**, which is the pass earning its keep. The one item left
> open on purpose is the password-policy **product** decision. Do NOT invent a new page or gate.

> **⏸️ OPERATOR MODE — PAUSED AFTER ITS SECURITY CORE. READ THIS BEFORE ASSUMING ANYTHING IS UNFINISHED.**
> A second track opened and stopped **on purpose** (D-164; detail in `memory/modules/OperatorMode.md`, plan in
> `docs/features/OPERATOR-MODE-MAP.md`).
> **DONE + LIVE-SAFE — G1–G3**, which shipped a **real security fix**: `Gate::before` and
> `PermissionService::has()` both returned an unconditional `true` for any super-admin, and the only thing
> keeping one out of a clinic's PHI was never being handed a tenant context — **containment by accident, not by
> decision**. A super-admin now reaches tenant data ONLY through an ACTIVE, UNEXPIRED, IN-SCOPE, IN-TIER,
> owner-approved `OperatorGrant`, fail-closed at both former bypass points and **regression-guarded** (D-161);
> **requesting is not granting** (D-162); **the owner — the tenant's `org_admin` — is the gate**, and approval
> is the only activation path (D-163). 40 tests.
> **DELIBERATELY DEFERRED — G4–G11** (elevated-session mechanics · mid-session revoke + expiry + receipt · the
> ~7 operator/owner screens). They are **operator-facing convenience UI**, to be built **after the first
> customer is live**, and they add **no safety property G1–G3 do not already enforce**.
> **There is NO HTTP route and NO UI — Operator Mode is backend-only and inert**, unreachable over the wire.
> **Do NOT treat it as unfinished work blocking deploy, and do NOT "finish" it unprompted.** To resume: read
> the MAP and start at **G4**. One question to answer first if it might be "yes": *is an "all patient records"
> scope ever permitted?* — currently **fail-closed, no wildcard exists**.

> **📋 WAITLIST MANAGEMENT — AUDITED, NOT BUILT.** A tenth wireframe, decoded and audited after the nine-page
> pass closed (it does **not** reopen that pass): `docs/wireframe-parity/WAITLIST-MANAGEMENT-DIFF.md`. ~70% of
> it renders an already-rich backend; the **blocker** is that nothing can add anyone to the waitlist today
> (`WaitlistService::create()` has one caller in the repo — `DemoClinicSeeder`). The **auto-send tier must be
> omitted** (the agent's ceiling is APPROVE) and SMS/phone are a seam (email only). **No fix chain has been
> issued.** Priority: below DEPLOY. *(The other decoded wireframe, "Waiting On Approval", turned out **not** to
> be a parity page — it is one screen of the Operator Mode family and was folded into that MAP.)*

> **One-liner to paste at the start of a new session:**
> *"CareOS is a multi-tenant, agentic healthcare-operations SaaS (Laravel 12 · Inertia/Vue 3 · Eucalyptus Glow ·
> offline Nurse PWA). THE BUILD IS COMPLETE — EIGHT built + green verticals on one platform: CLINIC (delivered +
> admin), DENTAL (G1–G9), HOME-CARE/SPITEX, INPATIENT/ADT (Hospital P1), PHARMACY (P2), LAB/LIS (P3), RADIOLOGY/RIS
> (P4), SURGERY/OR (P5), ED (P6) — ALL hospital phases built. Current focus is DEPLOY + PARTNERSHIPS, NOT building
> (there are no more verticals to build); the page-by-page WIREFRAME-PARITY pass is now COMPLETE (all NINE pages —
> Admin Settings · Approval Queue · Branches · Agent&Tool Config · Allergy safe-part · Billing & AR · AR Account
> Detail [P1 ledger → P2 dunning → P3 visual → P4 record-payment → P5 payment-plan → P6 Betreibung, operator-only +
> agent-excluded by construction] · Appointment Detail P1-P3 · Auth Screens [AUTH-SEC.1/.2 + AUTH-VIS] — NOTHING REMAINS; the rule was: match the visual, NEVER weaken a gate, surface-don't-fabricate, one
> part = one commit; D-149/D-150/D-151 + docs/wireframe-parity/). Read docs/ONBOARDING.md → AGENTS.md → PROJECT-STATE.md → DECISIONS.md →
> DEFERRED.md → memory/LOG.md first. HARD RULES: electric fence (record-not-judge; no AI in the clinical-decision
> path; checklists RECORD not ENFORCE; ranges/results DISPLAYED not FLAGGED; reports/acuity/ASA AUTHORED/ASSIGNED
> not computed; every safety judgment — drug interactions, triage, HL7, DICOM/CAD — is a certified-partner seam,
> null-object, never homemade), fail-closed tenancy, integer-minor money, append-only ledgers + DB triggers,
> reconcile-to-the-unit, P0D.GU. Execute ONLY the pasted gate; open with `git log --oneline -1`, end with composer
> check + smoke green + ONE commit, verify CI, then STOP."*

---

## 1. Read order

1. **`AGENTS.md`** — single source of truth: project, stack, hard rules, workflow, module map, MEMORY PROTOCOL.
2. **`PROJECT-STATE.md`** — authoritative "where we are" snapshot (BUILD COMPLETE · eight verticals · all hospital
   phases · focus = deploy + partnerships · latest commit + suite counts).
3. **`DECISIONS.md`** — architecture decision log, **D-001 → D-164**, verified with **no gaps and no duplicates**
   (append-only; never edit past entries). **D-149** = the wireframe-parity discipline, **D-150** = the Billing & AR
   reconcile extension + engine-computed reporting, **D-151** = AR Account Detail read-only-over-the-engine + the
   agent-never-commits-money / never-escalates-Betreibung line, **D-152** = the AR record-payment gate WIRES the
   guarded `PaymentService` rather than becoming a second payment path, **D-153** = payment plans SCHEDULE money
   against a real balance and never create or move it, **D-154** = Betreibung is a human legal act — operator-only
   on a dedicated narrower permission, eligibility-gated, append-only, and agent-excluded BY CONSTRUCTION,
   **D-155/156/157** = the Appointment Detail chain (real display · the real `LEGAL_TRANSITIONS` action row · the
   real slot-finder + overlap guard), **D-158** = remember-me must not bypass 2FA, **D-159** = public pages are
   smoked because an unauthenticated 500 is the worst kind, **D-160** = the enrolment fallback shows the user's
   OWN real secret or nothing (and closes the pass), **D-161/162/163** = the Operator Mode chain (the
   fail-closed grant-gated super-admin containment replacing the emergent bypass · requesting-is-not-granting ·
   owner=org_admin and approval-is-the-only-activation-path), **D-164** = Operator Mode is **PAUSED after its
   security core, deliberately**, with no HTTP surface.
4. **`DEFERRED.md`** — the parked backlog, each item with its pull-forward TRIGGER (the certified-partner seams +
   medical-device non-goals + earlier parked items).
5. **`memory/LOG.md`** — one line per completed gate; the full build history (Phases 0/A–G · P0P · CLINIC.W1–W10 ·
   FIX.1–5 · POLISH.1–3 · UI.F1–2 · DENTAL.G1–9 · HOSPITAL.G1–7 · PHARMACY.G1–5 · SURGERY.G1–5 · ED.G1–6 ·
   LAB.G1–6 · RAD.G1–5 · A11Y.1 · the WIREFRAME-PARITY gates [SETTINGS.P1–6 · APPROVAL.P1–7 · BRANCH.P1–5 ·
   AGENT.P1–6 · ALLERGY.P1 · BILLAR.P1–7 · ARDETAIL.P1–6 · APPT.P1–3 · **AUTH-SEC.1 · AUTH-SEC.2 · AUTH-VIS**] ·
   the reconciliation entries). **212 entries, and as of this reconciliation EVERY ONE carries a real, resolvable
   commit hash** — the 185 stale `(pending)` markers (written before their commit existed and never backfilled)
   were resolved against `git log`, so a LOG line now maps to the commit that shipped it.
6. **`memory/modules/*.md`** — per-module deep notes (21, incl. the cross-module **`OperatorMode.md`** — read it
   before touching anything operator-related): Platform, Audit, AiCore, People, Patients, Scheduling,
   Clinical, Billing, Comms, FrontDesk, Nursing, Reporting, Import, **Dental, Hospital, Pharmacy, Surgery, ED,
   Lab, Radiology**.
7. **The vertical MAPS** (each vertical is MAP-FIRST — a reconciliation/scope map before code):
   `docs/CLINIC-DELIVERY-MAP.md` · `docs/DENTAL-DELIVERY-MAP.md` · `docs/HOSPITAL-PHASE1-ADT-MAP.md` ·
   `docs/HOSPITAL-PHASE2-PHARMACY-MAP.md` · `docs/HOSPITAL-PHASE3-LAB-MAP.md` ·
   `docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md` · `docs/HOSPITAL-PHASE5-SURGERY-MAP.md` · `docs/HOSPITAL-PHASE6-ED-MAP.md`.
8. **`docs/FEATURE-INVENTORY.md`** — classified gap map (why each remaining thing is unbuilt).
9. **`docs/MASTER-STATUS-REPORT.md`** — cross-vertical status + gap audit.
10. **`docs/DB-PARITY.md`** — MariaDB-10.4 (dev) ↔ MySQL-8 (prod/CI) parity notes (the P0P.G15 `dateTime()` rule).
11. **The audit reports** — `docs/QA-AUDIT-REPORT.md` (live-browser QA + FIX.1–5), `docs/DEEP-AUDIT-REPORT.md`,
    `docs/FULL-EXERCISE-AUDIT-REPORT.md` (all-roles exercise). Plus `docs/ONBOARDING-REHEARSAL-REPORT.md`,
    `docs/DISCOVERY.md`, `docs/SCREENS.md`, `docs/AGENT-EVALS.md`, `docs/DEPLOY-RUNBOOK.md`.
14. **`docs/wireframe-parity/*.md`** — the wireframe-parity pass (D-149 → D-160): per-page audit/diff reports +
    per-part progress. **NINE docs, ALL COMPLETE/RESOLVED — the pass is CLOSED:** `ADMIN-SETTINGS-DIFF.md`,
    `APPROVAL-QUEUE-DIFF.md`, `BRANCHES-DIFF.md`, `AGENT-TOOL-CONFIG-DIFF.md`, `ALLERGY-ALERT-DIFF.md`,
    `BILLING-AR-DIFF.md`, `AR-ACCOUNT-DETAIL-DIFF.md` (every punch-list item RESOLVED across ARDETAIL.P1–P6),
    `APPOINTMENT-DETAIL-DIFF.md` (core RESOLVED across APPT.P1–P3), and `AUTH-SCREENS-DIFF.md` (§4.1/§4.2 resolved
    by the AUTH-SEC security sprint, §3 by AUTH-VIS). The remaining notes across the pass are **HONEST BACKEND
    GAPS, not parity failures** — the real Swiss QR-bill [IBAN/reference], send-QR-bill/reminder-from-the-page
    (sending stays inside the existing `DunningService` + agent-cap path), the two optional Appointment follow-ons
    (APPT.P4 room-capability, APPT.P5 preferred-practitioner) — plus the ONE open product decision, the password
    policy. The decoded wireframes live in the **gitignored** `resources/prototype/` (regenerate by decoding the
    bundle, never committed).
12. **The scoping doc** (`careos-hospital-expansion-scoping.md`) — **NOT in-repo** (external); the hospital build is
    captured in the six HOSPITAL-PHASE maps above.
13. **this file** (`docs/ONBOARDING.md`).

---

## 2. Environment + how to run

- **Stack:** Laravel 12 (PHP 8.2) · Inertia v2 + Vue 3 + TS + Tailwind v4 (**Eucalyptus Glow**) · separate offline
  **Nurse PWA** (`nurse-pwa/`). **Dev DB** = MariaDB 10.4 @ `127.0.0.1:3306` (database `careos`). **Prod + CI** =
  MySQL 8 + Redis 7 + Node 22. Redis-compatible server @ `127.0.0.1:6379` (Predis). Horizon runs via Memurai
  locally; local Windows PHP lacks `pcntl` so `php artisan horizon` exits after startup (CI Linux has
  `pcntl`/`posix`). Sessions are DB; Fortify + Sanctum.
- **Windows + PowerShell** — run commands one per line (no `&&` chaining). A **Bash tool (Git Bash)** is also
  available for POSIX scripts. PHP CLI = `C:\xampp\php\php.exe`; Composer = `C:\xampp\php\php.exe C:\xampp\php\composer`.

**Migrate + seed the demo tenants:**
```
php artisan migrate                         # apply pending migrations (dev DB). migrate:status → zero pending.
php artisan migrate:fresh --seed            # WIPES + rebuilds + base seeders (permission/plan catalog)
php artisan db:seed --class=DemoClinicSeeder    # Praxis Lindenhof (CHF, clinic resources, realistic vitals)
php artisan db:seed --class=DemoSpitexSeeder    # Spitex Sonnengarten (EU-Generic home-care)
php artisan db:seed --class=DemoDentalSeeder    # Zahnarztpraxis Morgenstern (CHF, general-dental)
php artisan db:seed --class=DemoHospitalSeeder  # Klinik Bergblick (CHF — the SIX hospital verticals + composite episode)
```
**FOUR** demo seeders now, all reconcile-to-the-unit + chain-verify (D-147 added the hospital one). **`DemoHospitalSeeder`**
(Klinik Bergblick) makes the six hospital verticals runtime-demonstrable — 20 users, one per hospital role (each
`twoFactorEnabled`, so log in via Playwright with the fixed secret), wards/beds/theatre, tenant-authored catalogs, and
**THE COMPOSITE EPISODE**: one patient ED→admit→beds→meds→surgery→labs→imaging billed on ONE `invoiceStay` invoice
(CHF 5187.20, 13 charges), reconciling δ=0. Its period is the CURRENT month (the others bill the previous month). All
four run manually (none is wired into `DatabaseSeeder`). **NOTE:** the QA audits leave mutations — re-seed to reset.

**Quality gates (must be green before commit):**
```
composer check          # = lint (Pint) ; analyse (PHPStan L5) ; test (Pest). ~45-60 min — RUN IN BACKGROUND.
composer test:mysql     # migrate:fresh --force + migrate:status + Pest — the MySQL-parity run (WIPES data)
composer test:smoke     # php artisan test tests/Feature/Smoke — route-reachability (FIX.5), fast.
                        #   4 tests: staff routes · per-role RBAC · GUEST routes (AUTH-SEC.2) · portal.
composer eval           # Pest Evals suite (the AI electric-fence eval locks)
composer fix            # Pint auto-fix
```
> **`composer check` now runs ~45–60 min (far exceeds the tool timeout) — always run it in the BACKGROUND, and
> VERIFY the actual Pint/PHPStan/Pest text from the log. The exit code has LIED more than once** (a Pint style
> failure returned exit 0; a wrapper returned exit 0 while the log said `error code 1` with 2 tests failed).
> **Never report a gate green on the exit code alone — read the log text, then confirm the CI check-run.**
> Pint runs FIRST and halts the chain on any style nit — a new test/controller file commonly trips
> `fully_qualified_strict_types` / `ordered_imports` / `no_unused_imports`; auto-fix with `composer fix` (or
> `pint <files>`) then re-run.
> **Current baseline (verified at `8a9a867`, AUTH-VIS): Pest 1209 passed / 16,080 assertions · PHPStan L5
> `[OK] No errors` · Pint `passed` · `composer test:smoke` 4/4 · Vitest `npm run test:unit` 29 passed (5 files) ·
> `npm run build` green.** (Earlier reference points: 1146 at ARDETAIL.P3 `9c95246`; 953 at the pre-parity build.)

**Frontend:**
```
npm run build           # Vite build (main app) — must be green when you touch .vue/.ts
npm run build:pwa       # Nurse PWA build
npm run test:unit       # Vitest (main app)
npm run test:pwa        # Vitest (Nurse PWA)
```

**Scheduler / queues.** Horizon runs the queues (reminders, exports, etc.). The scheduler (`routes/console.php`)
runs overlap-guarded commands on cadences (asserted by `ScheduleRegistrationTest`): `audit:verify-chains`,
`credentials:refresh-status`, `nursing:materialize-visits` (planned nursing visits), `clinical:evaluate-recalls`,
**`hospital:accrue-bed-days`** (inpatient bed-day charges), `billing:dunning-run`, `billing:reconcile`,
`appointments:dispatch-reminders`, `scheduling:expire-waitlist-offers`.

**Demo login + 2FA:** user `andrea.lindenhof` / password `demo-password` (org_admin — holds billing.manage,
dental.chart, note.write/sign, order.manage, admission.manage, ward/bed.manage, formulary/dispense.manage,
surgery.manage, ed.manage, lab.catalog/lab.result, radiology.catalog/radiology.study, etc.). MFA is mandatory; the
factory TOTP secret is the fixed **`JBSWY3DPEHPK3PXP`** — derive the current OTP via google2fa (see
`memory/browser-verify-playwright-2fa-recipe`).

---

## 3. Hard rules (never violate — these OVERRIDE defaults)

- **ELECTRIC FENCE — record-not-judge / render-not-judge (across ALL eight verticals).** Every clinical surface —
  vitals, labs, odontogram, perio, dentist diagnosis, imaging, inpatient ward-vitals, the eMAR, the WHO checklist,
  ED triage, lab reference-ranges, the radiology report — stores FACTS a clinician entered; the system NEVER
  grades, scores, stages, flags, detects, diagnoses, interprets, computes-acuity, or computes-a-finding.
  Concretely:
  - **No AI in the clinical-decision path** — a diagnosis/finding/report is clinician-AUTHORED (no suggested/
    differential/likelihood); imaging has no AI/CV (no caries/pathology/radiology CADe-CADx, no overlay,
    no auto-read, no confidence score).
  - **No computed acuity / early-warning / risk** — no homemade NEWS/MEWS, no surgical-risk predictor, no ED
    triage-acuity algorithm. ASA/Mallampati + ED triage acuity are clinician-**ASSIGNED** recorded facts.
  - **Checklists RECORD, they do not ENFORCE** — the WHO Surgical Safety Checklist never gates the case.
  - **Ranges/results DISPLAYED, not FLAGGED** — a lab reference range is shown beside the raw value; the system
    computes NO high/low/abnormal/critical flag or delta-check (the clinician reads value-vs-range).
  - **Reports/acuity/ASA AUTHORED/ASSIGNED, not computed** — the radiologist AUTHORS the report (prose); the
    triage nurse ASSIGNS the acuity; the anaesthetist ASSIGNS ASA.
  - **Every clinical-safety JUDGMENT = a certified-partner seam, null-object, never homemade** — drug
    interaction/dose (`MedicationSafetyProvider`), triage acuity (`TriageAcuityProvider`), HL7/FHIR lab feed
    (`LabConnectivity`), DICOM/PACS/CAD (`ImagingConnectivity`), anaesthesia device-data — each is an interface
    with only a Null/Manual no-op impl, ADVISORY + human-owned, incapable of auto-blocking by design; a homemade
    version is a PERMANENT non-goal (regulated medical-device territory).
  - **Implant traceability is record-keeping, not a device verdict** — lot/serial/UDI → patient is a factual
    recall lookup, never a computed device-safety judgment.
  - AI elsewhere (ops/admin lane) is **draft-until-approved** with autonomy caps (clinical/financial hard-capped
    at "approve", never "auto"). Fence violations are caught by recursive payload assertions, the `composer eval`
    locks, schema/column fences, and adversarial greps over each module's `src`.
- **Fail-closed multi-tenancy.** `TenantContext` + `BelongsToTenant`; a no-context query throws; cross-tenant
  references throw `CrossTenantReferenceException`. **Request-level tests must `forget()` the TenantContext before
  the request** (the C-1 / FIX.1 lesson) or they mask tenant resolution (SubstituteBindings runs before
  IdentifyTenantFromUser — resolve tenant-scoped route params from a STRING id in-controller, not model binding).
- **Money = integer minor units, never floats.** All amounts are `*_minor` ints.
- **Append-only ledgers + clinical records.** Model `updating`/`deleting` guards **and** DB triggers
  (`SIGNAL SQLSTATE '45000'`, portable across MariaDB 10.4 + MySQL 8, dropped in `down()`). A correction is a NEW
  row + a reason. (Order/OrderResult, specimen/study events, lab/imaging order overlays, dispense/charge ledgers.)
- **Reconcile-to-the-unit (billing LAUNCH BLOCKER).** Charges/invoices/payments reconcile with delta 0
  (`ReconciliationEngine`, I4 `delta_minor === 0`). **ALL pricing/charge/VAT/line-total math lives ONLY in the
  billing engine.** Every vertical that bills — dental, inpatient (bed-to-billing), pharmacy, surgery, ED, lab,
  radiology — REUSES `ChargeCaptureService::captureManual` + tenant-authored `TariffItem`s; the engine snapshots
  the fee + computes the line total. **Proven by an adversarial grep** (`line_total_minor`/`vat_total_minor`/
  `subtotal_minor`/`vatMinor`/`intdiv(` must be absent from every `Modules/*/src`). The composite episode
  (ED→inpatient, or an inpatient's lab/radiology charge) rides ONE stay invoice via `invoiceStay`, still δ=0.
- **Scheduling / stock-safety by construction (the concurrency idiom).** A double-book / oversell is impossible by
  construction: the `lockResource → assertNoOverlap` (or `lock → assert → decrement`, or the tenant-row-locked
  gapless-number/accession generator) idiom in ONE `DB::transaction`, reused across **booking, nursing visits,
  beds, dispensing, theatres, surgical stock, lab accessions, imaging accessions** — each **hammer-proven**.
- **Catalogs are tenant-authored — no licensed code sets bundled** (no ADA CDT, ICD-10, SNODENT, LOINC, CPT,
  RadLex, licensed drug DB, or national tariff set). The dental procedure catalog, diagnosis pick-list, orderable
  list, formulary, lab test catalog, imaging exam catalog, bed-day/surgical/ED tariffs are the tenant's own terms.
- **The Encounter-reused-untouched-via-link-table pattern.** Inpatient (`ward_rounds`), surgery
  (`surgical_case_encounters`), ED (`ed_visit_encounters`), radiology (`imaging_study_reports`) each link a reused
  Clinical `Encounter`/`ClinicalNote` from a vertical-side table — Clinical's schema + sign-and-lock +
  one-open-per-practitioner invariants are NEVER modified.
- **MySQL-parity mutable-moment convention (P0P.G15).** A column that an UPDATE-able table mutates must be
  `dateTime()`, never `timestamp()` (MariaDB 10.4 gives the first non-nullable TIMESTAMP an implicit
  `ON UPDATE CURRENT_TIMESTAMP`; MySQL 8 does not). `MutableMomentParityTest` scans every table and enforces this.
- **Standing UI rule (P0D.GU).** Vue is presentational; authorization, validation, and state transitions are
  enforced + tested SERVER-SIDE. Tests assert BEHAVIOR, not markup. **Gotcha:** `case` is a JS reserved word —
  never an Inertia prop name (use `surgicalCase`).
- **Cross-module contact via services/events, never cross-module Eloquent** (arch tests enforce). App-layer
  controllers/providers compose multiple modules (D-017); cross-module audit hooks live in
  `app/AppServiceProvider` so verticals stay free of Audit models. Peer verticals never import each other (a
  shared pattern is COPIED, not imported; a cross-vertical link is a SOFT app-layer id).
- **i18n keys only** in the UI; string-id route params, not model binding (FIX.1/D-090). **Date-only values**
  render via the shared `formatDateOnly` helper — never `new Date(dateOnly)` (M-2/FIX.3). **vue-i18n treats `.`
  as a path separator** — never a dotted key segment as data (store the underscore form and map it; APPT.P1).
- **THE AGENT IS A GOVERNED CONTAINER (AGENT.P1–P6, D-140s).** Effective autonomy =
  **MIN(configured, tool ceiling, role RBAC ceiling)** — **configuration can only ever NARROW, never widen**, and a
  forged higher level is clamped by the resolver, not by the UI. The fence is **toggle-free**. The agent **DRAFTS**
  (suggest-only, through the ApprovalQueue) and a **HUMAN commits** anything consequential: **the agent never
  auto-sends, never commits money, and never escalates to legal debt-enforcement/Betreibung** — the last is
  **agent-EXCLUDED BY CONSTRUCTION** (no `AiTool` capability, no AiCore reference, and the only files reaching the
  service are the service, the model and the operator-gated controller, asserted as an exact list; so "0
  auto-escalated" is structural, not a displayed number). A limit may only STOP the agent, never widen it. The
  uncertainty escalation is **always-on and un-removable**. **Every metric is real-or-honestly-absent** — never a
  fabricated number; if the backend cannot produce it, OMIT it and say so rather than inventing a plausible value.
- **2FA IS MANDATORY AND LOCKED for staff (SETTINGS.P4) — no skip, postpone or disable path exists**, and a test
  asserts the route table contains none. A session restored from the **remember-me recaller is RE-CHALLENGED**
  (AUTH-SEC.1/D-158): the password factor stays remembered, the second factor never does. The challenge-passed
  proof is written in exactly two places, both requiring a valid code. **Never add an auth affordance weaker than
  the enforced gate** — no skip-2FA, no 2FA-bypassing "trust this device", no weakened reset, no SSO button
  without an SSO backend. Guest pages (`/login`, `/forgot-password`, `/reset-password/{token}`, `/invite/{token}`)
  are covered by the guest-route smoke (AUTH-SEC.2/D-159) because every other smoke authenticates first.
- **Never assert on serialised JSON text.** `json_decode` an audit context and assert its MEANING. A raw-JSON
  substring passes on dev MariaDB 10.4 and FAILS on CI MySQL 8, which normalises and re-serialises JSON columns
  (space after the colon, keys reordered). This shipped CI-red once — APPT.P2, fixed in `27fa22c` (D-156).

---

## 4. Gate workflow discipline

- **Execute ONLY the pasted gate.** Several gates may arrive in one message — do the FIRST, then STOP.
- **VERIFY FROM REPO REALITY BEFORE BUILDING.** If a pasted gate's precondition commit is **not** HEAD, or the
  work it describes **already exists**, **STOP and say so** — do not build it twice and do not quietly adapt.
  The repo is ground truth; a gate description can be stale.
- **MAP-FIRST for a new vertical — but there are NONE left.** Every new vertical/phase started with a
  reconciliation/scope MAP before code (the "don't force the wrong abstraction" analysis; net-new entities each
  earned their own model — `Bed`/`Stay`, `TheatreSlot`, `EdVisit`, `Specimen`, `ImagingStudy` — while
  orders/results/notes were REUSED from Clinical). **All eight verticals and all six hospital phases are built:
  do NOT invent a new vertical, and do NOT invent a new wireframe-parity page — that pass is closed.**
- **Open every gate with `git log --oneline -1`** (state it) + confirm CI green for that commit. **Close with
  `git log --oneline -2`** (confirm your gate is on top). *(The open/close git-log bookend is the anti-skip guard.)*
- **End state per gate:** `composer check` FULLY green (verified from the log text, not the exit code) + `composer
  test:smoke` green + `npm run build` green when frontend was touched + the **GATE REPORT** + **exactly ONE
  commit**, then **STOP**. Then the **MEMORY PROTOCOL**: append `memory/LOG.md`, update the touched
  `memory/modules/*.md`, `PROJECT-STATE.md`, and `DECISIONS.md` (+ `DEFERRED.md` when you park something).
- **ADD tests; never modify an existing behavior test** (a genuinely-changed contract is the rare, flagged
  exception). Extend the FIX.5 route smoke with any new route (GET 200 + a withheld-role 403 write).
- **CI rule — local-green ≠ CI-green.** Verify each pushed commit's CI DIRECTLY via the GitHub check-runs API (no
  `gh`/docker here: `git credential fill` + `curl`; repo `Subhankhan12/careos`). The route-smoke test (FIX.5)
  exists because a request-time 500 (C-1) once shipped green.
- **QA rule.** Safety-sensitive surfaces (the fence, billing, RBAC, kiosk PHI, every record-not-judge surface) get
  browser-verified, not just unit-tested.
- **Adversarial-grep rule.** After billing/clinical work, grep to PROVE no pricing/charge/VAT math (and no
  AI/CV/finding/CAD/suggestion/severity/risk/acuity logic) leaked into the module.
- **Commits:** Git Bash heredoc for the message (a PowerShell here-string once corrupted a commit); end with the
  `Co-Authored-By:` trailer your running environment specifies — the history carries `Claude Opus 4.8 (1M context)`
  on earlier commits and `Claude Opus 5 (1M context)` from AUTH-VIS on, so take it from your own instructions
  rather than copying a pinned version from this file. **Never rewrite pushed history on `main`** (force-push is
  blocked — get the commit right the first time).

---

## 5. What's built (the EIGHT verticals) + current focus

**THE BUILD IS COMPLETE.** One shared platform (Phases 0/A/B/C/D/E/F/G + Phase-P hardening P0P.G1–G16) carries
**eight built + green verticals; ALL hospital phases are built:**

- **CLINIC — delivered.** Patients/CRM, scheduling + booking (no-double-book), clinical (encounters, sign-&-lock
  SOAP notes, problems/allergies/vitals/meds), billing (tariffs → charges → invoices → payments → dunning →
  reconciliation), comms/inbox, telehealth, the AiCore agents (grounded/governed/fenced), reporting. Design-wired
  (CLINIC.W1–W7), QA-audited + fixed (FIX.1–5, POLISH.1–3, UI.F1–2), admin (W8/W8b/W8c/W9/W10).
- **DENTAL — general-dentist (DENTAL.G1–G9).** Odontogram → procedure catalog + billing → perform-a-procedure
  (atomic) → phased fee-scheduled treatment plan → perio → dentist-authored diagnosis → imaging upload/view/read.
  Record-not-judge; billing reuses the engine; tenant-authored catalogs.
- **HOME-CARE / SPITEX (Phase E + P0P.G12).** Service agreements → RRULE-planned visits → dispatcher board
  (concurrency-safe assignment) → GPS proof-of-visit → the offline **Nurse PWA** (day-pack sync, offline queue +
  conflict resolution) → incidents + timesheets. Competency matching.
- **INPATIENT / ADT (Hospital Phase 1, HOSPITAL.G1–G7).** Beds/wards (net-new `Bed`, concurrency-safe claim) ·
  `Stay` above an UNMODIFIED `Encounter` · admit/transfer/discharge (atomic) · ward board · bedside charting ·
  SBAR handover · bed-to-billing (reconciles) · discharge summary + LOS.
- **PHARMACY (Hospital Phase 2, PHARMACY.G1–G5).** Tenant-authored formulary · medication orders · eMAR
  (append-only) · dispensing + inventory (concurrency-safe) · billing (reconciles). Drug-safety = the
  `MedicationSafetyProvider` certified-partner seam (null-object, never auto-blocks).
- **LAB / LIS (Hospital Phase 3, LAB.G1–G6).** A lab test IS a Clinical `Order` (~85% reuse) · tenant-authored
  test catalog · lab order overlay (specimen + recorded priority) · specimen tracking (net-new: accession + legal
  state machine) · manual result entry (reuses `OrderResult`; reference range DISPLAYED, NO computed flag) ·
  review worklist (reuses order→review) · billing (reconciles). HL7/FHIR/analyzer FEED (LAB.G7) = the
  `LabConnectivity` certified-partner seam (manual today; homemade HL7 = not built) — a manual record-keeping
  shell pending the partner.
- **RADIOLOGY / RIS (Hospital Phase 4, RAD.G1–G5).** An imaging order IS a Clinical `Order` (~95% reuse) ·
  tenant-authored exam catalog · imaging order overlay (modality/body-part + recorded priority) · the net-new
  `ImagingStudy` (accession + legal ordered→acquired→reported) · modality worklist · the radiologist REPORT
  (reuses sign-and-lock `ClinicalNote` — AUTHORED prose, NO computed image finding/CAD/auto-read) · report
  routing · billing (reconciles). DICOM/PACS IMAGE FEED (RAD.G6) = the `ImagingConnectivity` certified-partner
  seam (not built) — an order-form-with-no-image shell pending PACS (the study is metadata; no diagnostic viewer).
- **SURGERY / OR (Hospital Phase 5, SURGERY.G1–G5).** Theatre scheduling (net-new `TheatreSlot`, overlap-safe) ·
  case lifecycle + op docs (reuses Clinical) · WHO checklist (RECORDED, not enforced) · consumables + implant
  lot/serial/UDI traceability (concurrency-safe stock) · billing (reconciles). ASA assigned-not-computed;
  computed surgical-risk = non-goal; anaesthesia device-data = partner seam.
- **ED / Emergency Department (Hospital Phase 6, ED.G1–G6).** Net-new `EdVisit` flow entity · triage
  (nurse-ASSIGNED acuity, `TriageAcuityProvider` seam) · tracking board · clinical docs (reuses Clinical) ·
  disposition + the ED→ADT handoff (admit reuses `AdmissionService` → an inpatient `Stay`, admission_type=emergency,
  atomic) · billing (reconciles; composite emergency→inpatient episode on one invoice). Computed triage acuity =
  non-goal/certified-partner.
- **INSURANCE / CLAIMS — NOT built** (needs a clearinghouse partner; a future commercial decision).

**CURRENT STAGE = DEPLOYMENT, NOT building.** The build is complete AND **audited** — THREE audits
(`docs/FULL-SYSTEM-QA-REPORT.md`: a full-system QA audit, a re-audit at real coverage ~45%→~80% once the hospital
tenant was seeded, and the **A11Y.1** fix) converged on **ZERO must-fix**: the electric fence holds LIVE across all
eight verticals (record-not-judge, often declared in the UI), billing reconciles δ=0 for every tenant incl. the
composite ED→inpatient episode, RBAC is airtight per-vertical, append-only holds, the kiosk leaks no PHI. The only
findings were 2 Low a11y items, **both fixed (D-148)**. There are **no more verticals to build.** The outpatient
verticals (clinic/dental/home-care) target **2–3 prospective paying customers** (DONE, NOT deployed); the hospital
build was driven by a **committed mid-size general-hospital buyer** (now complete).

**🏁 AND THE NINE-PAGE WIREFRAME-PARITY PASS IS COMPLETE TOO (closed by AUTH-VIS, `8a9a867`, 2026-08-17).**
Admin Settings · Approval Queue · Branches · Agent & Tool Config · Billing & AR · Allergy Alert (safe part) ·
AR Account Detail · Appointment Detail (core) · Auth Screens. It ran under one rule — **match the visual, but
surface the real thing or nothing, and never weaken an enforced gate** — and it earned its keep: auditing the
auth screens against their wireframe exposed **TWO High LIVE SECURITY DEFECTS**, both since fixed and both
STRENGTHENING the floor: **AUTH-SEC.1** (a session restored from the remember-me cookie reached the app with no
2FA challenge — a standing second-factor bypass, now re-challenged; D-158) and **AUTH-SEC.2**
(`/forgot-password` + `/reset-password/{token}` returned **HTTP 500** with no Fortify view bound, so a locked-out
user had no self-service recovery — **plus the coverage gap that let it ship green**: every route smoke
authenticated first, so no PUBLIC page had ever been requested; the smoke now drives guest routes; D-159).
**AUTH-VIS** then closed the last visual item (the enrolment "Can't scan?" fallback, rendering the user's OWN
real secret — never the wireframe's demo literal; D-160). **There is NO parity work left.**

**THE HIGHEST-VALUE TRACK — and the answer to "what's next?" — is DEPLOYMENT.** The buildable work, in priority
order: **(a) DEPLOYMENT** · **(b) Waitlist Management** (audited, chain not started) · **(c) Operator Mode
G4–G11** (deliberately deferred to post-first-customer — see the banner at the top of this file) · (d) the two
optional Appointment follow-ons · (e) the password-policy decision · (f) the certified-partner seams · (g) the
earlier parked items. Full list with triggers in `DEFERRED.md`. **The security-critical work that was worth
doing ahead of deployment is DONE** (the Operator Mode security core closed a live containment gap).

1. **DEPLOY** the built verticals to the paying customers — staging deploy → smoke-test → onboard: to a Linux host,
   wire real email + LiveKit, import via the P0P.G6 CSV tool. Ready: `docs/DEPLOY-RUNBOOK.md` (audited against the
   8-vertical code — the accrual cron + all 17 role templates fixed), `docs/DEPLOY-ENV.production.template` (71 keys,
   placeholders + a MUST-FILL list; `SESSION_SECURE_COOKIE=true` verified present), the rehearsed onboarding
   (4 programmatic steps), and four demo seeders for pilot/sales tenants.
   **⚠️ KNOWN AND UNRESOLVED: a staging error was hit earlier and never debugged.** It is not diagnosed and not
   written up — expect to reproduce it as the first real step, and do not assume the deploy path is clean just
   because the suite and CI are green.
2. **PARTNERSHIP/INTEGRATION** work that fills the certified-partner seams — drug-safety (pharmacy), HL7/FHIR
   (lab), PACS/DICOM (radiology), anaesthesia device-data (surgery), triage acuity (ED). Business conversations
   with long lead times, not gates.

**⚠️ ONE OPEN PRODUCT DECISION (not a defect, not a build task):** the password policy is `Password::default()` —
min 8 characters, no `Password::defaults()` configured, so **no mixed-case/digit/symbol/breach check**. The reset
flow enforces whatever is configured; choosing what it *should* be needs the product owner. Deliberately not
slipped into a security sprint.

**Do NOT invent a new vertical, and do NOT invent a new parity page.** If asked to build, the honest answer is:
the remaining value is DEPLOYMENT + PARTNERSHIPS. A new gate is only justified when a specific customer/partner
need pulls a concrete feature forward (e.g. the CH/KVG billing pack once a Spitex coordinator confirms the model,
a certified-partner adapter once a partner is signed, or one of the triggered items in `DEFERRED.md`).
**Wait for the pasted gate.**
