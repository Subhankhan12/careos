# CareOS — Onboarding (read this FIRST)

The single file a new session (Claude Code **or** Codex) reads first. After this, the read order below
gives you the complete, accurate context of everything built.

> **One-liner to paste at the start of a new session:**
> *"CareOS is a multi-tenant agentic healthcare-ops SaaS. Backend is FEATURE-COMPLETE; three verticals —
> CLINIC (delivered + admin), DENTAL (G1–G8 built), INSURANCE (not built). Current focus is DEPLOY, not
> building. Read docs/ONBOARDING.md → AGENTS.md → PROJECT-STATE.md → DECISIONS.md → DEFERRED.md →
> memory/LOG.md before doing anything. Hard rules: electric fence (record-not-judge, no AI in the
> clinical-decision path), fail-closed tenancy, integer-minor money, append-only ledgers + triggers,
> P0D.GU. Execute only the pasted gate, end with composer check + smoke green + ONE commit, then STOP."*

---

## 1. Read order

1. **`AGENTS.md`** — single source of truth: project, stack, hard rules, workflow, module map, MEMORY PROTOCOL.
2. **`PROJECT-STATE.md`** — authoritative "where we are" snapshot (status, focus, latest commit + suite counts).
3. **`DECISIONS.md`** — architecture decision log, D-001 → D-106 (append-only; never edit past entries).
4. **`DEFERRED.md`** — the parked backlog, each item with its pull-forward TRIGGER.
5. **`memory/LOG.md`** — one line per completed gate; the full build history.
6. **`memory/modules/*.md`** — per-module deep notes (Platform, Patients, Scheduling, Clinical, Nursing,
   Billing, Comms, AiCore, Audit, Import, FrontDesk, Reporting, People, Dental).
7. **`docs/SCREENS.md`** — the factual re-skin brief (Inertia pages + nurse-PWA screens, routes/guards/props).
8. **`docs/CLINIC-DELIVERY-MAP.md`** — screen→backend mapping (HISTORICAL — build-status marks are stale; see banner).
9. **`docs/DENTAL-DELIVERY-MAP.md`** — the dental build sequence (G1–G8 built; G9+ / partner-gated later).
10. **`docs/FEATURE-INVENTORY.md`** — classified gap map (A–F: why each remaining thing is unbuilt).
11. **`docs/DB-PARITY.md`** — MariaDB-10.4 (dev) ↔ MySQL-8 (prod/CI) parity notes.
12. **`docs/QA-AUDIT-REPORT.md`** — the live-browser QA audit + FIX.1–FIX.5 remediation.
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
```
Both demo seeders reconcile-to-the-unit and chain-verify. **No dental demo seeder yet** (a follow-up).

**Quality gates (must be green before commit):**
```
composer check          # = lint (Pint) ; analyse (PHPStan L5) ; test (Pest). Runs ~16 min — RUN IN BACKGROUND.
composer test:mysql     # migrate:fresh --force + migrate:status + Pest — the MySQL-parity run (WIPES data)
composer test:smoke     # php artisan test tests/Feature/Smoke — route-reachability (FIX.5), fast
composer eval           # Pest Evals suite (the AI electric-fence eval locks)
composer fix            # Pint auto-fix
```
> **`composer check` runs ~16 min (exceeds the tool timeout) — always run it in the BACKGROUND, and VERIFY
> the actual Pint/PHPStan/Pest output from the log tail. The exit code has LIED before (a Pint style failure
> returned exit 0).** Pint runs FIRST and halts the chain on any style nit — a new test file commonly trips
> `fully_qualified_strict_types` / `ordered_imports` / `no_unused_imports`; auto-fix with `composer fix` (or
> `pint <files>`) then re-run.

**Frontend:**
```
npm run build           # Vite build (main app) — must be green when you touch .vue/.ts
npm run build:pwa       # Nurse PWA build
npm run test:unit       # Vitest (main app)
npm run test:pwa        # Vitest (Nurse PWA)
```

**Demo login + 2FA:** user `andrea.lindenhof` / password `demo-password` (org_admin — holds billing.manage,
dental.chart, note.write, etc.). MFA is mandatory; the factory TOTP secret is the fixed
`JBSWY3DPEHPK3PXP` — derive the current OTP via google2fa (see `memory/browser-verify-playwright-2fa-recipe`).

---

## 3. Hard rules (never violate — these OVERRIDE defaults)

- **ELECTRIC FENCE — record-not-judge / render-not-judge.** Vitals, labs, odontogram, perio, diagnosis,
  imaging store FACTS the clinician entered; the system NEVER grades, scores, stages, flags, detects, or
  diagnoses. **No AI in the clinical-decision path** — a diagnosis is dentist-authored (no suggested/
  differential/likelihood); imaging has no AI/CV (no caries/pathology detection, no overlay). AI elsewhere
  is **draft-until-approved** with autonomy caps (clinical/financial hard-capped at "approve"). Fence
  violations are caught by recursive payload assertions + the `composer eval` locks + adversarial greps.
- **Fail-closed multi-tenancy.** `TenantContext` + `BelongsToTenant`; a no-context query throws;
  cross-tenant references throw `CrossTenantReferenceException`. Request-level tests must `forget()` the
  TenantContext before the request (the C-1 / FIX.1 lesson) or they mask tenant resolution.
- **Money = integer minor units, never floats.** All amounts are `*_minor` ints.
- **Append-only ledgers + clinical records.** Model `updating`/`deleting` guards **and** DB triggers
  (`SIGNAL SQLSTATE '45000'`, portable MariaDB 10.4 + MySQL 8). A correction is a NEW row + a reason.
- **Reconcile-to-the-unit (billing LAUNCH BLOCKER).** Charges/invoices/payments reconcile with delta 0.
  **No pricing/charge/VAT/line-total math outside the billing engine** — dental billing REUSES
  `ChargeCaptureService`/`TariffResolver` (a procedure IS a tariff item); verified by adversarial grep.
- **Catalogs are tenant-authored — no licensed code sets bundled** (no ADA CDT, ICD-10, SNODENT, SSO). The
  dental procedure catalog + diagnosis pick-list + orderable list are the tenant's own terms.
- **Scheduling-safety.** Branch/resource deactivation guards + opening-hours → slot-engine; a scheduling
  conflict is impossible by construction (no-double-book engine), not by validation.
- **Standing UI rule (P0D.GU / D-standing).** Vue is presentational; authorization, validation, and state
  transitions are enforced + tested SERVER-SIDE. Tests assert BEHAVIOR, not markup. Wiring a prototype
  screen re-skins a built page against its existing route/props/actions — it can never change a rule.
- **Cross-module contact via services/events, never cross-module Eloquent** (arch tests enforce this). App-
  layer controllers compose multiple modules (D-017). Dental MAY use Patients/Scheduling/Clinical/Billing +
  Audit services, but NOT Audit models, AiCore, Nursing, or Comms.
- **i18n keys only** in the UI (no hardcoded strings); string-id route params, not model binding (FIX.1/D-090).

---

## 4. Gate workflow discipline

- **Execute ONLY the pasted gate.** Several gates may arrive in one message — do the FIRST, then STOP.
- **Open every gate with `git log --oneline -1`** (state it) + confirm CI green for that commit. **Close with
  `git log --oneline -2`** (confirm your gate is on top). *(G4/G7/G8/G10 were silently skipped historically —
  the open/close git-log bookend is the anti-skip guard.)*
- **End state per gate:** `composer check` FULLY green (verified from the log, not the exit code) + `composer
  test:smoke` green + `npm run build` green when frontend was touched + the **GATE REPORT** + **exactly ONE
  commit**, then **STOP**. Then the **MEMORY PROTOCOL**: append `memory/LOG.md`, update the touched
  `memory/modules/*.md`, `PROJECT-STATE.md`, and `DECISIONS.md` (+ `DEFERRED.md` when you park something).
- **ADD tests; never modify an existing behavior test** (tracking a genuinely-changed contract is the rare
  exception — flag it). Extend the FIX.5 route smoke with any new route.
- **CI rule — local-green ≠ CI-green.** Verify each pushed commit's CI DIRECTLY via the GitHub check-runs API
  (no `gh`/docker here: `git credential fill` + `curl`). The route-smoke test (FIX.5) exists because a
  request-time 500 (C-1) once shipped green.
- **QA rule.** Safety-sensitive surfaces (the fence, billing, RBAC, kiosk PHI, dental record-not-judge) get
  browser-verified, not just unit-tested.
- **Adversarial-grep rule.** After billing/dental work, grep to prove NO pricing/charge/VAT math (and no
  AI/CV/suggestion/severity logic) leaked into the module — excluding comment lines.
- **Commits:** Git Bash heredoc for the message (a PowerShell here-string once corrupted a commit); end with
  `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`. Never rewrite pushed history on `main`.

---

## 5. Current focus + next move

**BACKEND FEATURE-COMPLETE. THREE verticals for THREE prospective paying customers:**
- **CLINIC — fully delivered** (W1–W7 wired to Eucalyptus Glow, QA-fixed FIX.1–5, admin vertical W8–W10).
- **DENTAL — general-dentist feature set built** (G1–G8: odontogram → procedures+billing → perform →
  treatment plan → perio → diagnosis → imaging). Record-not-judge throughout; billing reuses the engine.
- **INSURANCE / CLAIMS — NOT built** (needs a clearinghouse partner; a future phase).

**The next unit of progress is DEPLOYMENT, not another gate.** Deploy to a Linux host, wire real email +
LiveKit, import each customer's data via the P.6 CSV tool, onboard. The insurance vertical follows using the
proven wiring/build patterns. The **Spitex / CH-billing discovery answer** (is Swiss Spitex KVG/KLV
insurance-reimbursed, not clean cash-pay? — UNPROVEN) still unlocks **eMAR + the CH statutory billing pack**
when confirmed. The well of safe build-without-a-customer-need work is done — do not open a new gate unless a
customer need pulls a specific feature forward.

**Deploy-ready:** MySQL 8 parity proven (`docs/DB-PARITY.md`); NOT yet deployed. Still needed: real email
transport, a production LiveKit key/secret, production config. Documented follow-ups: full per-widget
timezone display; the resource-availability admin screen (W8c); dental patient/chart cross-link + a dental
demo seeder + the dental long-poles (live imaging capture/DICOM/3D overlay, licensed code sets, G9–G11) — all
in `DEFERRED.md`.
