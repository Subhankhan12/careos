# CareOS — Onboarding (read this FIRST)

The single file a new session (Claude Code **or** Codex) reads first. After this, the read order below gives you
the COMPLETE, accurate context of everything built: **EIGHT verticals** (clinic · dental · home-care · inpatient ·
pharmacy · surgery · ED · lab · radiology) on one shared multi-tenant platform. **THE BUILD IS COMPLETE — all
hospital phases are built.** The next progress is **deployment + certified-partner integrations, not more code** —
AND, running alongside deploy prep, a page-by-page **WIREFRAME-PARITY PASS** (bring each app page up to its designed
wireframe without weakening a single enforced gate). See §5.

> **WIREFRAME-PARITY PASS (in progress — D-149).** The loop, per page: **decode** the self-unpacking "bundler-shell"
> wireframe HTML → readable HTML in the **gitignored** `resources/prototype/` (no app change, no commit) → **AUDIT**
> the live page against it into `docs/wireframe-parity/<PAGE>-DIFF.md` (report-only commit) → **FIX** per-part as
> `P0D.GU` gates, **one part = one commit + STOP**, GATE REPORT + CI-green each. **The discipline (non-negotiable):
> match the LOCKED/CAPPED visual, but NEVER weaken a real enforced server gate (suggest-cap, mandatory 2FA,
> required reject-reason, the AutonomyPolicy ceiling, approve = re-authorise + re-ground + still-pending); never
> regress a "correctly-more-real" item; RBAC is reflect-only (server Gate stays authoritative); surface-don't-
> fabricate (every permission/ceiling/source shown is REAL or an honest absence — no faked control/count/copy).**
> **Where each page stands (SEVEN pages COMPLETE):** Admin Settings (SETTINGS.P1–P6, `e7cabf0`) ·
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
> asserted as an exact list, so "0 auto-escalated" is structural; D-154). Then the remaining decoded pages:
> **Appointment Detail CORE COMPLETE (APPT.P1–P3) · Auth Screens (the last page).** Do NOT start the next part unprompted — each is its own re-issued gate.

> **One-liner to paste at the start of a new session:**
> *"CareOS is a multi-tenant, agentic healthcare-operations SaaS (Laravel 12 · Inertia/Vue 3 · Eucalyptus Glow ·
> offline Nurse PWA). THE BUILD IS COMPLETE — EIGHT built + green verticals on one platform: CLINIC (delivered +
> admin), DENTAL (G1–G9), HOME-CARE/SPITEX, INPATIENT/ADT (Hospital P1), PHARMACY (P2), LAB/LIS (P3), RADIOLOGY/RIS
> (P4), SURGERY/OR (P5), ED (P6) — ALL hospital phases built. Current focus is DEPLOY + PARTNERSHIPS, NOT building
> (there are no more verticals to build); a page-by-page WIREFRAME-PARITY pass also runs alongside (SEVEN pages done —
> Admin Settings · Approval Queue · Branches · Agent&Tool Config · Allergy safe-part · Billing & AR · AR Account
> Detail [P1 ledger → P2 dunning → P3 visual → P4 record-payment → P5 payment-plan → P6 Betreibung, operator-only +
> agent-excluded by construction]; remaining Appointment Detail · Auth Screens — match the visual, NEVER weaken a gate, surface-don't-fabricate, one
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
3. **`DECISIONS.md`** — architecture decision log, **D-001 → D-157** (append-only; never edit past entries; **D-149**
   = the wireframe-parity discipline, **D-150** = the Billing & AR reconcile extension + engine-computed reporting,
   **D-151** = AR Account Detail read-only-over-the-engine + the agent-never-commits-money / never-escalates-Betreibung line,
   **D-152** = the AR record-payment gate WIRES the guarded `PaymentService` rather than becoming a second payment path,
   **D-153** = payment plans SCHEDULE money against a real balance and never create or move it,
   **D-154** = Betreibung is a human legal act — operator-only on a dedicated narrower permission, eligibility-gated,
   append-only, and agent-excluded BY CONSTRUCTION).
4. **`DEFERRED.md`** — the parked backlog, each item with its pull-forward TRIGGER (the certified-partner seams +
   medical-device non-goals + earlier parked items).
5. **`memory/LOG.md`** — one line per completed gate; the full build history (Phases 0/A–G · P0P · CLINIC.W1–W10 ·
   FIX.1–5 · POLISH.1–3 · UI.F1–2 · DENTAL.G1–9 · HOSPITAL.G1–7 · PHARMACY.G1–5 · SURGERY.G1–5 · ED.G1–6 ·
   LAB.G1–6 · RAD.G1–5 · A11Y.1 · the WIREFRAME-PARITY gates [SETTINGS.P1–6 · APPROVAL.P1–7 · BRANCH.P1–5 ·
   AGENT.P1–6 · ALLERGY.P1 · BILLAR.P1–7 · ARDETAIL.P1–6 · APPT.P1–3] · the reconciliation entries).
6. **`memory/modules/*.md`** — per-module deep notes (20): Platform, Audit, AiCore, People, Patients, Scheduling,
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
14. **`docs/wireframe-parity/*.md`** — the wireframe-parity pass (D-149/D-150/D-151): per-page audit/diff reports +
    per-part progress. SEVEN docs: `ADMIN-SETTINGS-DIFF.md`, `APPROVAL-QUEUE-DIFF.md`, `BRANCHES-DIFF.md`,
    `AGENT-TOOL-CONFIG-DIFF.md`, `ALLERGY-ALERT-DIFF.md`, `BILLING-AR-DIFF.md` (all COMPLETE/RESOLVED), and
    `AR-ACCOUNT-DETAIL-DIFF.md` (**COMPLETE — every punch-list item RESOLVED across ARDETAIL.P1–P6**; the only
    remaining notes are two HONEST BACKEND GAPS, not parity failures: the real Swiss QR-bill [IBAN/reference] and
    send-QR-bill/reminder-from-the-page, which stays inside the existing DunningService + agent-cap path). The decoded wireframes live in the **gitignored** `resources/prototype/`
    (regenerate by decoding the bundle, never committed).
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
composer check          # = lint (Pint) ; analyse (PHPStan L5) ; test (Pest). Runs ~30 min — RUN IN BACKGROUND.
composer test:mysql     # migrate:fresh --force + migrate:status + Pest — the MySQL-parity run (WIPES data)
composer test:smoke     # php artisan test tests/Feature/Smoke — route-reachability (FIX.5), fast
composer eval           # Pest Evals suite (the AI electric-fence eval locks)
composer fix            # Pint auto-fix
```
> **`composer check` runs ~30 min (exceeds the tool timeout) — always run it in the BACKGROUND, and VERIFY the
> actual Pint/PHPStan/Pest text from the log tail. The exit code has LIED before (a Pint style failure returned
> exit 0).** Pint runs FIRST and halts the chain on any style nit — a new test/controller file commonly trips
> `fully_qualified_strict_types` / `ordered_imports` / `no_unused_imports`; auto-fix with `composer fix` (or
> `pint <files>`) then re-run. **Current baseline: Pest 1146 passed / 15,159 assertions** (as of ARDETAIL.P3,
> `9c95246`; earlier reference: 953 at the pre-parity build), plus **Vitest `npm run test:unit` 29 passed**; PHPStan
> L5 `[OK]`; Pint clean.

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
  render via the shared `formatDateOnly` helper — never `new Date(dateOnly)` (M-2/FIX.3).

---

## 4. Gate workflow discipline

- **Execute ONLY the pasted gate.** Several gates may arrive in one message — do the FIRST, then STOP.
- **MAP-FIRST for a new vertical.** Every new vertical/phase starts with a reconciliation/scope MAP before code —
  the "don't force the wrong abstraction" analysis. The net-new entities each earned their own model (`Bed`/`Stay`,
  `TheatreSlot`, `EdVisit`, `Specimen`, `ImagingStudy`) while orders/results/notes were REUSED from Clinical.
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
- **Commits:** Git Bash heredoc for the message (a PowerShell here-string once corrupted a commit); end with
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`. Never rewrite pushed history on `main`.

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
build was driven by a **committed mid-size general-hospital buyer** (now complete). The only remaining progress:
1. **DEPLOY** the built verticals to the paying customers — staging deploy → smoke-test → onboard: to a Linux host,
   wire real email + LiveKit, import via the P.6 CSV tool. Ready: `docs/DEPLOY-RUNBOOK.md` (audited against the
   8-vertical code — the accrual cron + all 17 role templates fixed), `docs/DEPLOY-ENV.production.template` (71 keys,
   placeholders + a MUST-FILL list), and the rehearsed onboarding (4 programmatic steps).
2. **PARTNERSHIP/INTEGRATION** work that fills the certified-partner seams — drug-safety (pharmacy), HL7/FHIR
   (lab), PACS/DICOM (radiology), anaesthesia device-data (surgery). Business conversations with long lead times,
   not gates.

**Do NOT invent a new vertical.** If asked to build, the honest answer is: the remaining value is DEPLOYMENT +
PARTNERSHIPS. A new gate is only justified when a specific customer/partner need pulls a concrete feature forward
(e.g. a demo seeder for a hospital pilot, the CH/KVG billing pack once a Spitex coordinator confirms the model, a
certified-partner adapter once a partner is signed).
