# CareOS — Onboarding (read this FIRST)

The single file a new session (Claude Code **or** Codex) reads first. After this, the read order below
gives you the COMPLETE, accurate context of everything built: **SIX verticals** (clinic · dental · home-care ·
inpatient · pharmacy · surgery) on one shared multi-tenant platform.

> **One-liner to paste at the start of a new session:**
> *"CareOS is a multi-tenant, agentic healthcare-operations SaaS (Laravel 12 · Inertia/Vue 3 · Eucalyptus
> Glow · offline Nurse PWA). BACKEND FEATURE-COMPLETE: SIX built + green verticals — CLINIC (delivered +
> admin), DENTAL (G1–G9), HOME-CARE/SPITEX, INPATIENT/ADT (Hospital P1), PHARMACY (Hospital P2), SURGERY/OR
> (Hospital P5); INSURANCE not built. Current focus is DEPLOY, not building. Read docs/ONBOARDING.md →
> AGENTS.md → PROJECT-STATE.md → DECISIONS.md → DEFERRED.md → memory/LOG.md first. HARD RULES: electric fence
> (record-not-judge; no AI in the clinical-decision path; checklists RECORD not ENFORCE; safety judgment =
> certified partner only, never homemade), fail-closed tenancy, integer-minor money, append-only ledgers +
> DB triggers, reconcile-to-the-unit, P0D.GU. Execute ONLY the pasted gate; open with `git log --oneline -1`,
> end with composer check + smoke green + ONE commit, verify CI, then STOP."*

---

## 1. Read order

1. **`AGENTS.md`** — single source of truth: project, stack, hard rules, workflow, module map, MEMORY PROTOCOL.
2. **`PROJECT-STATE.md`** — authoritative "where we are" snapshot (status, six verticals, focus, latest commit + suite counts).
3. **`DECISIONS.md`** — architecture decision log, **D-001 → D-129** (append-only; never edit past entries).
4. **`DEFERRED.md`** — the parked backlog, each item with its pull-forward TRIGGER (incl. the hospital seams/phases).
5. **`memory/LOG.md`** — one line per completed gate; the full build history (Phases 0/A–G · P0P · CLINIC.W1–W10 · FIX.1–5 · POLISH.1–3 · UI.F1–2 · DENTAL.G1–9 · HOSPITAL.G1–7 · PHARMACY.G1–5 · SURGERY.G1–5).
6. **`memory/modules/*.md`** — per-module deep notes (17): Platform, Audit, AiCore, People, Patients, Scheduling,
   Clinical, Billing, Comms, FrontDesk, Nursing, Reporting, Import, **Dental, Hospital, Pharmacy, Surgery**.
7. **The vertical MAPS** (each vertical is MAP-FIRST — a reconciliation/scope map before code):
   `docs/CLINIC-DELIVERY-MAP.md` · `docs/DENTAL-DELIVERY-MAP.md` · `docs/HOSPITAL-PHASE1-ADT-MAP.md` ·
   `docs/HOSPITAL-PHASE2-PHARMACY-MAP.md` · `docs/HOSPITAL-PHASE5-SURGERY-MAP.md`.
8. **`docs/FEATURE-INVENTORY.md`** — classified gap map (why each remaining thing is unbuilt).
9. **`docs/MASTER-STATUS-REPORT.md`** — cross-vertical status + gap audit.
10. **`docs/DB-PARITY.md`** — MariaDB-10.4 (dev) ↔ MySQL-8 (prod/CI) parity notes.
11. **The audit reports** — `docs/QA-AUDIT-REPORT.md` (live-browser QA + FIX.1–5), `docs/DEEP-AUDIT-REPORT.md`,
    `docs/FULL-EXERCISE-AUDIT-REPORT.md` (all-roles exercise). Plus `docs/ONBOARDING-REHEARSAL-REPORT.md`,
    `docs/DISCOVERY.md`, `docs/SCREENS.md`, `docs/AGENT-EVALS.md`.
12. **The scoping doc** (`careos-hospital-expansion-scoping.md`) — **NOT in-repo** (external); the hospital
    build is captured in the three HOSPITAL-PHASE maps above.
13. **this file** (`docs/ONBOARDING.md`).

---

## 2. Environment + how to run

- **Stack:** Laravel 12 (PHP 8.2) · Inertia v2 + Vue 3 + TS + Tailwind v4 (**Eucalyptus Glow**) · separate
  offline **Nurse PWA** (`nurse-pwa/`). **Dev DB** = MariaDB 10.4 @ `127.0.0.1:3306` (database `careos`).
  **Prod + CI** = MySQL 8 + Redis 7 + Node 22. Redis-compatible server @ `127.0.0.1:6379` (Predis). Horizon
  runs via Memurai locally; local Windows PHP lacks `pcntl` so `php artisan horizon` exits after startup
  (CI Linux has `pcntl`/`posix`). Sessions are DB; Fortify + Sanctum.
- **Windows + PowerShell** — run commands one per line (no `&&` chaining). A **Bash tool (Git Bash)** is also
  available for POSIX scripts. PHP CLI = `C:\xampp\php\php.exe`; Composer = `C:\xampp\php\php.exe C:\xampp\php\composer`.

**Migrate + seed the demo tenants:**
```
php artisan migrate                         # apply pending migrations (dev DB). migrate:status → zero pending.
php artisan migrate:fresh --seed            # WIPES + rebuilds + base seeders (permission/plan catalog)
php artisan db:seed --class=DemoClinicSeeder    # Praxis Lindenhof (CHF, clinic resources, realistic vitals)
php artisan db:seed --class=DemoSpitexSeeder    # Spitex Sonnengarten (EU-Generic home-care)
php artisan db:seed --class=DemoDentalSeeder    # Zahnarztpraxis Morgenstern (CHF, general-dental)
```
All three demo seeders reconcile-to-the-unit and chain-verify. **There is NO inpatient / pharmacy / surgery
demo seeder yet** — the hospital verticals are proven by their feature tests (which build their own fixtures +
reconcile), not a rich demo tenant; a `DemoHospitalSeeder` is a documented follow-up (`DEFERRED.md`).

**Quality gates (must be green before commit):**
```
composer check          # = lint (Pint) ; analyse (PHPStan L5) ; test (Pest). Runs ~25 min — RUN IN BACKGROUND.
composer test:mysql     # migrate:fresh --force + migrate:status + Pest — the MySQL-parity run (WIPES data)
composer test:smoke     # php artisan test tests/Feature/Smoke — route-reachability (FIX.5), fast
composer eval           # Pest Evals suite (the AI electric-fence eval locks)
composer fix            # Pint auto-fix
```
> **`composer check` runs ~25 min (exceeds the tool timeout) — always run it in the BACKGROUND, and VERIFY
> the actual Pint/PHPStan/Pest text from the log tail. The exit code has LIED before (a Pint style failure
> returned exit 0).** Pint runs FIRST and halts the chain on any style nit — a new test file commonly trips
> `fully_qualified_strict_types` / `ordered_imports` / `no_unused_imports`; auto-fix with `composer fix` (or
> `pint <files>`) then re-run. Current baseline: **Pest 837 passed / 2 skipped / 8346 assertions** (the 2
> skips = Redis-Horizon + one reminder-infra case, green in CI on Redis 7).

**Frontend:**
```
npm run build           # Vite build (main app) — must be green when you touch .vue/.ts
npm run build:pwa       # Nurse PWA build
npm run test:unit       # Vitest (main app)
npm run test:pwa        # Vitest (Nurse PWA)
```

**Scheduler / queues.** Horizon runs the queues (reminders, exports, etc.). The scheduler (`routes/console.php`)
runs **9 overlap-guarded commands** on cadences (asserted by `ScheduleRegistrationTest`): `audit:verify-chains`,
`credentials:refresh-status`, `nursing:materialize-visits` (planned nursing visits), `clinical:evaluate-recalls`,
**`hospital:accrue-bed-days`** (inpatient bed-day charges), `billing:dunning-run`, `billing:reconcile`,
`appointments:dispatch-reminders`, `scheduling:expire-waitlist-offers`. (Only `audit:ensure-partitions` is not
yet wired — see `DEFERRED.md`.)

**Demo login + 2FA:** user `andrea.lindenhof` / password `demo-password` (org_admin — holds billing.manage,
dental.chart, note.write, surgery.manage, admission.manage, etc.). MFA is mandatory; the factory TOTP secret is
the fixed **`JBSWY3DPEHPK3PXP`** — derive the current OTP via google2fa (see
`memory/browser-verify-playwright-2fa-recipe`).

---

## 3. Hard rules (never violate — these OVERRIDE defaults)

- **ELECTRIC FENCE — record-not-judge / render-not-judge (across ALL six verticals).** Vitals, labs,
  odontogram, perio, dentist diagnosis, imaging, inpatient ward-vitals, the eMAR, and the WHO checklist store
  FACTS a clinician entered; the system NEVER grades, scores, stages, flags, detects, diagnoses, or predicts.
  Concretely:
  - **No AI in the clinical-decision path** — a diagnosis is clinician-authored (no suggested/differential/
    likelihood); imaging has no AI/CV (no caries/pathology detection, no overlay).
  - **No computed acuity / early-warning / risk** — no homemade NEWS/MEWS, no surgical-risk predictor, no ED
    triage acuity algorithm. ASA/Mallampati are clinician-**ASSIGNED** recorded facts.
  - **Checklists RECORD, they do not ENFORCE** — the WHO Surgical Safety Checklist never gates the case; a
    case transitions through its full lifecycle regardless of checklist completeness (a blocking checklist
    would be a safety-enforcement medical device).
  - **Safety JUDGMENT = certified partner only, never homemade** — the medication-safety engine (drug
    interaction/dose/contraindication) is a null-object `MedicationSafetyProvider` seam, ADVISORY + human-owned,
    incapable of auto-blocking by design. Same posture for lab HL7/FHIR, PACS/DICOM, anesthesia device-data.
  - **Implant traceability is record-keeping, not a device verdict** — lot/serial/UDI → patient is a factual
    recall lookup, never a computed device-safety judgment.
  - AI elsewhere (ops/admin lane) is **draft-until-approved** with autonomy caps (clinical/financial hard-capped
    at "approve", never "auto"). Fence violations are caught by recursive payload assertions, the `composer eval`
    locks, schema/column fences, and adversarial greps over each module's `src`.
- **Fail-closed multi-tenancy.** `TenantContext` + `BelongsToTenant`; a no-context query throws; cross-tenant
  references throw `CrossTenantReferenceException`. **Request-level tests must `forget()` the TenantContext
  before the request** (the C-1 / FIX.1 lesson) or they mask tenant resolution (SubstituteBindings runs before
  IdentifyTenantFromUser — resolve tenant-scoped route params from a STRING id in-controller, not model binding).
- **Money = integer minor units, never floats.** All amounts are `*_minor` ints.
- **Append-only ledgers + clinical records.** Model `updating`/`deleting` guards **and** DB triggers
  (`SIGNAL SQLSTATE '45000'`, portable across MariaDB 10.4 + MySQL 8, dropped in `down()`). A correction is a
  NEW row + a reason.
- **Reconcile-to-the-unit (billing LAUNCH BLOCKER).** Charges/invoices/payments reconcile with delta 0
  (`ReconciliationEngine`, I4 `delta_minor === 0`). **ALL pricing/charge/VAT/line-total math lives ONLY in the
  billing engine.** Every vertical that bills — dental, inpatient (bed-to-billing), pharmacy, surgery — REUSES
  `ChargeCaptureService::captureManual` + tenant-authored `TariffItem`s; the engine snapshots the fee + computes
  the line total. **Proven by an adversarial grep** (`line_total_minor`/`vat_total_minor`/`subtotal_minor`/
  `vatMinor`/`intdiv(` must be absent from `Modules/{Dental,Hospital,Pharmacy,Surgery}/src`).
- **Scheduling / stock-safety by construction (the concurrency idiom).** A double-book / oversell is impossible
  by construction, not by validation: the `lockResource → assertNoOverlap` (or `lockOnHand → assert → decrement`)
  idiom in ONE `DB::transaction` is reused across **booking, nursing visits, beds, dispensing, theatres, and
  surgical stock** — each **hammer-proven** (N OS processes race; exactly 1 wins).
- **Catalogs are tenant-authored — no licensed code sets bundled** (no ADA CDT, ICD-10, SNODENT, licensed drug
  DB, or national tariff set). The dental procedure catalog, diagnosis pick-list, orderable list, formulary,
  bed-day/surgical tariffs are the tenant's own terms.
- **Standing UI rule (P0D.GU).** Vue is presentational; authorization, validation, and state transitions are
  enforced + tested SERVER-SIDE. Tests assert BEHAVIOR, not markup. Wiring a prototype screen re-skins a built
  page against its existing route/props/actions — it can never change a rule. **Gotcha:** `case` is a JS reserved
  word — never an Inertia prop name (use `surgicalCase`).
- **Cross-module contact via services/events, never cross-module Eloquent** (arch tests enforce). App-layer
  controllers/providers compose multiple modules (D-017); cross-module audit hooks live in `app/AppServiceProvider`
  so verticals stay free of Audit models. Peer verticals never import each other (Surgery's inpatient stay-link is
  a SOFT app-layer id, not a Hospital dependency) — a shared pattern is COPIED, not imported.
- **i18n keys only** in the UI (no hardcoded strings); string-id route params, not model binding (FIX.1/D-090).
- **Date-only values** render via the shared `formatDateOnly` helper — never `new Date(dateOnly)` (UTC-parse
  shifts a day for behind-UTC viewers; the M-2/FIX.3 lesson).

---

## 4. Gate workflow discipline

- **Execute ONLY the pasted gate.** Several gates may arrive in one message — do the FIRST, then STOP.
- **MAP-FIRST for a new vertical.** Every new vertical/phase starts with a reconciliation/scope MAP before code
  — the "don't force the wrong abstraction" analysis (how a Bed became a net-new `Bed`/`Stay`, a theatre block a
  net-new `TheatreSlot`, a med order its own entity — each rather than bending an existing model).
- **Open every gate with `git log --oneline -1`** (state it) + confirm CI green for that commit. **Close with
  `git log --oneline -2`** (confirm your gate is on top). *(Gates were silently skipped historically — the
  open/close git-log bookend is the anti-skip guard.)*
- **End state per gate:** `composer check` FULLY green (verified from the log text, not the exit code) + `composer
  test:smoke` green + `npm run build` green when frontend was touched + the **GATE REPORT** + **exactly ONE
  commit**, then **STOP**. Then the **MEMORY PROTOCOL**: append `memory/LOG.md`, update the touched
  `memory/modules/*.md`, `PROJECT-STATE.md`, and `DECISIONS.md` (+ `DEFERRED.md` when you park something).
- **ADD tests; never modify an existing behavior test** (tracking a genuinely-changed contract is the rare
  exception — flag it). Extend the FIX.5 route smoke with any new route (GET 200 + a withheld-role 403).
- **CI rule — local-green ≠ CI-green.** Verify each pushed commit's CI DIRECTLY via the GitHub check-runs API
  (no `gh`/docker here: `git credential fill` + `curl`; repo `Subhankhan12/careos`). The route-smoke test (FIX.5)
  exists because a request-time 500 (C-1) once shipped green.
- **QA rule.** Safety-sensitive surfaces (the fence, billing, RBAC, kiosk PHI, every record-not-judge surface)
  get browser-verified, not just unit-tested.
- **Adversarial-grep rule.** After billing/clinical work, grep to PROVE no pricing/charge/VAT math (and no
  AI/CV/suggestion/severity/risk logic) leaked into the module.
- **Commits:** Git Bash heredoc for the message (a PowerShell here-string once corrupted a commit); end with
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`. Never rewrite pushed history on `main`.
  Leave `docs/DEPLOY-RUNBOOK.md` untracked.

---

## 5. What's built (the six verticals) + current focus

**BACKEND FEATURE-COMPLETE.** One shared platform (Phases 0/A/B/C/D/E/F/G + Phase-P hardening P0P.G1–G16) carries
**six built + green verticals:**

- **CLINIC — delivered.** Patients/CRM, scheduling + booking engine (no-double-book), clinical (encounters,
  sign-&-lock SOAP notes, problems/allergies/vitals/meds), billing (tariffs → charges → invoices → payments →
  dunning → reconciliation), comms/inbox, telehealth, the AiCore agents (KB front-desk, scheduler, billing,
  dispatch, inbox, summary — all grounded/governed/fenced), reporting. Wired to Eucalyptus Glow (CLINIC.W1–W7),
  QA-audited + fixed (FIX.1–5, POLISH.1–3, UI.F1–2), and the ADMIN vertical (W8/W8b/W8c/W9/W10).
- **DENTAL — general-dentist set (DENTAL.G1–G9).** Odontogram → procedure catalog + billing → perform-a-procedure
  (atomic) → phased fee-scheduled treatment plan → perio charting → dentist-authored diagnosis → imaging
  upload/view/read → demo-readiness. Record-not-judge; billing reuses the engine; tenant-authored catalogs.
- **HOME-CARE / SPITEX (Phase E, G1–G9 + P0P.G12).** Service agreements → RRULE-planned visits → dispatcher board
  (validated, concurrency-safe assignment) → GPS proof-of-visit → the offline **Nurse PWA** (day-pack sync,
  offline queue + conflict resolution, visit execution) → incidents + timesheets. Competency matching.
- **INPATIENT / ADT (Hospital Phase 1, HOSPITAL.G1–G7).** Beds/wards (net-new `Bed`, concurrency-safe claim) ·
  `Stay` above an UNMODIFIED `Encounter` · admit/transfer/discharge (atomic) · ward board · bedside charting ·
  SBAR handover · bed-to-billing (reconciles) · discharge summary + LOS.
- **PHARMACY (Hospital Phase 2, PHARMACY.G1–G5).** Tenant-authored formulary · medication orders · eMAR
  (append-only) · dispensing + inventory (concurrency-safe) · billing (reconciles). The medication-safety
  JUDGMENT is a certified-partner seam (null-object, never auto-blocks).
- **SURGERY / OR (Hospital Phase 5, SURGERY.G1–G5).** Theatre scheduling (net-new `TheatreSlot`, overlap-safe) ·
  case lifecycle + op docs (reuses Clinical) · WHO checklist (RECORDED, not enforced) · consumables + implant
  lot/serial/UDI traceability (concurrency-safe stock) · billing (reconciles). ASA assigned-not-computed;
  computed surgical-risk = non-goal; anesthesia device-data = partner seam.
- **INSURANCE / CLAIMS — NOT built** (needs a clearinghouse partner; a future phase).

**CURRENT FOCUS = DEPLOY, not building.** The outpatient verticals (clinic/dental/home-care) target **2–3
prospective paying customers** (DONE, NOT deployed); the hospital build was driven by a **committed mid-size
general-hospital buyer**. Deploy to a Linux host, wire real email + LiveKit, import via the P.6 CSV tool,
onboard — the runbook (`docs/DEPLOY-RUNBOOK.md`) + rehearsed onboarding are ready.

**The remaining hospital value is PARTNERSHIP/INTEGRATION-gated, not code-gated:** Lab (Phase 3) + Radiology
(Phase 4) are mostly integration SHELLS pending partners (HL7/FHIR, PACS/DICOM); ED (Phase 6) is a buildable
board with **triage as the fence line** (assigned, never computed). The "smart" hospital features gate on the
certified drug-safety engine. The insurance/claims vertical follows (clearinghouse). The Spitex CH/KVG billing
model must be confirmed with coordinators before the CH statutory pack is committed (`docs/DISCOVERY.md`).

**Do not open a new gate unless a customer/partner need pulls a specific feature forward.** The well of safe
build-without-a-customer is largely dry; the next real unit of progress is DELIVERY.
