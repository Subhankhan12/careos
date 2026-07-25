# CareOS — Full Two-Vertical, All-Roles Live Exercise + Audit

**Method:** a REAL browser (Chromium via Playwright, driven by Node scripts) logged in as every role across
both verticals + the patient portal, drove ~90 screen visits, captured HTTP status / console errors /
load-time / full-page screenshots for each, ran a recursive **fence-key scan of every fenced surface's
actual Inertia props** (`window.history.state.page.props`), re-checked RBAC by URL, and exercised
create-flows with **DB before/after** snapshots. **Audit + report only — no application code was changed.**

- **HEAD:** `c88a2df` (POLISH.1). `git status` clean; `migrate:status` **0 pending**; **CI `success`** on `main`.
- **Tooling note (honest):** the Playwright **MCP server was not loadable in this non-interactive session**,
  so I drove the *same Playwright engine* via Node scripts against the app. Results are genuine observed
  browser output (screenshots I inspected visually, captured console/timing, DB deltas) — not the MCP tool,
  and not fabricated.
- **One environment fix was required to run:** the seeded `.env` points cache/queue at **Redis**, but Memurai
  was stopped and this sandbox can't elevate to start the service, so the login POST 500'd
  (`StreamInitException 6379`). I switched `CACHE_STORE=database` + `QUEUE_CONNECTION=sync` (the DEV default
  per AGENTS.md) in `.env` (uncommitted, backed up, restored after). **This is an environment issue, not an
  app defect** — in prod (Redis running) the original config is correct.

---

## 1. Environment & coverage

| Item | Value |
|---|---|
| App URL | `http://127.0.0.1:8000` (`php artisan serve`, single-threaded PHP built-in server) |
| Stack | Laravel 12 / PHP 8.2 · MariaDB 10.4 @3306 · **cache/session/queue = database/sync** (Redis unavailable this session) · assets `npm run build` |
| Seed | `migrate:fresh --seed` + the three demo seeders — **all reseeded clean** |
| Tenants | **praxis-lindenhof** (clinic, CHF, de) · **zahnarztpraxis-morgenstern** (dental, CHF) · **spitex-sonnengarten** (home-care) |
| 2FA | fixed factory secret `JBSWY3DPEHPK3PXP` (decrypt-verified); TOTP computed in-harness |

**Seed integrity:** all three seeders completed; each runs `verifyChain` internally and their paired tests
(`Demo{Clinic,Spitex,Dental}SeederTest`) assert reconcile-to-the-unit (δ=0) + chain-verify + idempotency —
green in the POLISH.1 `composer check`. Row counts confirmed live: clinic 15 patients / 7 INV / 12 appts;
dental 4 patients / 3 INV / charted odontograms + perio + a plan; spitex 8 patients / 7 INV.

**Roles driven (11 sessions, login+2FA each):** clinic **org_admin / reception / billing / doctor**; dental
**org_admin(dentist-owner) / doctor(dentist) / reception**; spitex **coordinator / org_admin / nurse**; **portal
patient** (Erika). *(3 sessions initially flaked on the 30s-TOTP boundary in the harness — re-run cleanly with a
hardened retry; see §3 note.)*

**Create-flows performed (DB-verified):** ✅ recurring-series **create + END** (POLISH.1); ✅ **dental chart a
tooth** (append-only); ✅ patient-register **live duplicate warning**. **Not completed in-harness** (multi-step
form selectors — *harness* limitation, not an app finding): full new-patient wizard submit, KB-article create,
CSV-import upload. Their pages load 200 and their backends are covered by the feature-test suite.

**Not reached (honest limits):** the **offline Nurse PWA** (separate SPA, not browser-drivable here); **kiosk
check-in** (no kiosk device is seeded, and I did not issue one in-harness); **partner-gated dental imaging
capture / DICOM / 3D** (non-goal, absent by design); the full **billing write-loop** (issue draft / credit-note
/ payment / over-allocation-block) was not driven in-harness — those pages load 200 and the money math is
locked by the reconciliation + hammer suites.

---

## 2. Fences + prior fixes — CONFIRMED HOLDING LIVE

**Electric fence — rigorous prop-scan** (recursive forbidden-key scan over each surface's real Inertia props):

| Surface | Prop keys | Forbidden judgment keys |
|---|---|---|
| Odontogram | 16 | **0** |
| Perio | 11 | **0** |
| Diagnoses | 13 | **0** |
| Imaging | 11 | **0** |
| Treatment plans | 12 | **0** |
| Orders-to-review | 8 | **0** |
| Clinical chart | 22 | **1 — `allergies.0.severity="severe"`** |

The single hit is the **recorded** allergy attribute the clinician documented (`{substance:Penicillin,
reaction:…, severity:"severe", status:active}`) — a stored fact, not a computed judgment on
vitals/labs/odontogram/perio/diagnosis/imaging. Exactly the acceptable case the code audit classified. **Zero
computed severity / score / grade / stage / risk / DMFT / flag / abnormal / differential / suggested / likelihood
keys on any fenced surface.**

**Visually confirmed:** odontogram chart-key reads *"Colour marks the condition the dentist charted — not its
severity. Nothing here is scored, graded, or flagged."*; a **6 mm perio pocket renders as raw `6/0•`** in the
identical neutral style as a shallow site, under *"…raw measurements only… Nothing here is staged, graded,
scored, or flagged. You read the numbers and interpret them."*; the allergy banner is **amber, not red**.

**Prior fixes — all hold live:**
- **C-1 (string-id, no 500):** every detail page (patient 360, clinical chart, invoice detail, all 5 dental
  patient pages) returned **200**; **no request-time 500 anywhere**.
- **M-5 styled denials:** reception→`/billing` etc. render the in-shell Eucalyptus Glow **"ERROR 403 — You
  don't have access to this area"** card, not a bare Symfony page.
- **M-4 nav gating:** each role's top-nav shows only its permitted items (reception 4, billing 2, dental-doctor
  6, coordinator 5, org_admin 15) — server Gate authoritative (URL denials still 403).
- **M-1 landing figures:** org_admin dashboard shows real numbers (Outstanding **787.11 CHF**, Active patients 2).
- **W8–W10 admin:** settings / roles / branches / kiosks / governance / approvals / KB / staff-telehealth all
  load 200 for org_admin; governance is read-only.
- **POLISH.1 (this branch):** **Orders + Import nav entries present**; OrdersReview 200 (reception denied 403);
  the day-board **"Active recurring series"** panel renders and **End series** works end-to-end (series
  `active → ended`, DB-confirmed); dental-chart write is **append-only** (`tooth_records` 10→11, a new row).

---

## 3. Functional bug list (by severity)

### 🔴 Critical / 🟠 High / 🟡 Medium
**NONE.** Every working screen returned **200** (or the correct **403** on a denial); **no 500, no blank page,
no app console error** on any of the ~90 visits; all fences, RBAC, and the POLISH.1 write-flows held.

### 🟢 Low / observational
- **L-A — org_admin top-nav is over-dense (15 items; "Knowledge base" wraps to two lines).** POLISH.1 added
  *Orders* + *Import*, worsening the previously-flagged density (deep-audit L-E). Not broken; a menu-grouping of
  admin/governance items would help at narrower widths. *Classify (a) cosmetic/responsive.*
- **L-B — the patient-register wizard is multi-step and the harness did not complete a full new-patient submit.**
  The live **duplicate warning fired correctly** (entering Erika's demographics surfaced a match); the full
  happy-path submit was not driven here. *Not an app defect — a harness limitation; noted for transparency.*

*(Harness note — NOT app bugs: in the first sweep, 3 of 11 logins stalled on the 30s-TOTP window boundary and
their subsequent "200"s were the login page — which briefly looked like "doctor sees /billing 200". Re-running
with a boundary-safe retry, **doctor is correctly 403 on /billing and /settings** — no RBAC hole. This is
exactly why the exercise is run live.)*

---

## 4. Critical / safety callouts (explicitly probed)

| Probe | Result |
|---|---|
| Fence break in the UI (graded vital/perio/odontogram, auto-diagnosis, AI imaging finding, DMFT/severity) | ✅ **NONE** — prop-scan clean on all 7 fenced surfaces; the prototype's DMFT/finding-count/flags are **correctly omitted** live. |
| PHI leak / public URL | ✅ none observed; documents/images stream through authed routes (the sweep hit no public asset for PHI). |
| RBAC hole | ✅ **NONE** — every denial is 403 by URL; nav-hiding is cosmetic; the doctor "billing 200" was a harness artifact, corrected to 403. |
| Billing UI ≠ backend | ✅ figures consistent (landing Outstanding 787.11 CHF matches the reconciling seed); full write-loop not driven in-harness (test-locked). |
| Append-only / data-integrity break | ✅ dental chart write created a **new** `tooth_records` row (10→11), not an update; series-end set `status=ended` without deleting occurrences. |
| Orphaned charge/record | none observed (no billing writes driven; dental-chart wrote only the append-only row). |

---

## 5. UI/UX findings (prioritized)
1. **Nav density (L-A)** — org_admin's 15-item nav wraps at 1440px; group admin/governance under a menu. *(a)*
2. **New-patient wizard depth** — several steps before commit; the live dup-warning is a genuinely good UX
   (match + reasoning surfaced as you type). Consider a progress affordance. *(a)*
3. **Import wizard now reachable** — POLISH.1's nav entry fixes the prior "URL-only" gap (confirmed: `/imports`
   loads from the top nav for `data.import` holders). ✅
4. **Empty states are honest and on-brand** (day-board "Nothing scheduled today yet", empty Saturday grid).
5. **Accessibility (observational):** forms carry real labels; the segmented 2FA inputs are individually
   fillable; nav is keyboard-reachable. **Not done:** a dedicated axe-core/contrast pass on the dense
   odontogram + perio grids (tap-target size, screen-reader order) — needs a runtime a11y audit. *(a/needs-run)*

## 6. Design / wireframe-fidelity (per representative screen)
| Screen | Verdict |
|---|---|
| Landing, Patient 360, Clinical chart, Day-board, Odontogram, Perio, Portal, Billing, Admin, 403 | **Matches** the Eucalyptus Glow prototype (glass top-bar + pill nav, deep-eucalyptus tiles, soft stat cards, amber-not-red accents, 22px page titles, Inter). |
| **Odontogram — correctly MORE REAL than the mockup** | The prototype shows a **"7 DMFT" caries index**, a **"1 finding" count**, and **"Flagged · one site to watch"**. The live app **deliberately omits all three** (electric fence — record-not-judge) and says so on the chart key. **Correct divergence, not drift — do NOT "fix".** |
| Role-gated nav / tenant chip / real German data + empty states | Live app correctly diverges from the mockup's fixed 6-item nav + fake data. **Correct, not drift.** |
| Portal | No pay button (PSP deferred) — correct omission. |

**No visual drift-to-fix was found** beyond the standing nav-density cosmetic. UI.F1/F2 fidelity holds live.

## 7. Performance findings
- Every page **640–1064 ms** server-render on the **single-threaded `artisan serve`** (prod php-fpm + Redis
  would be faster) — no slow page, no hang.
- **Zero JS console errors** on every working page (the only console entries were the intended 403 resource
  logs on denial probes).
- Main JS bundle **343 KB** (cached once; per-page chunks tiny: Odontogram 16 KB, DayBoard 26 KB) — acceptable.
- No N+1 symptom at seeded volume (patient 360, chart, day-board, dense odontogram/perio all sub-1.1s).

## 8. Accessibility findings
Observational only (no axe run): labelled inputs, individually-labelled 2FA segments, working keyboard/focus,
adequate eucalyptus contrast on the surfaces reviewed. **Recommended:** a dedicated contrast + AT sweep of the
**dense odontogram/perio grids** and the count-chip tabs (tap-target size, SR reading order). *Matches the
deep-audit's standing a11y recommendation; unchanged.*

## 9. Missing / feature-gap (classified; cross-checked vs `docs/MASTER-STATUS-REPORT.md`)
The prototype pack carries **~110 wireframes**; the app ships **57 pages**. Every gap observed live matches the
master report's classification — **confirmed, nothing new** except the concrete DMFT-omission (§6):

- **(B) Another vertical:** Insurance Claim / Eligibility / Claim-Fully/Partially-Covered / Rejected; dental
  specialist Crown Prep / RCT / Endo / Ortho / Chair Scheduling / Scan Library-Upload-Compare / Inventory &
  Sterilization. *A real insurance or specialist-dental customer would need these; own phase.*
- **(C) Partner-gated:** Prescription & Refill (e-Rx), Take Payment/Failed Payment (PSP), Lab connectivity,
  live dental imaging capture. *Need an external partner.*
- **(D) Discovery-gated:** Payment Reconciliation (camt.053 / CH-KVG).
- **(E) Safe UI-over-backend (cheap, pull on need):** My Account, Statement of Account, AR Account Detail,
  Governance Ledger Export, Recall Due List, Service Catalog/Create, Provider Availability, Care Plan Review,
  Referral Out, Waitlist Management page, Appointment Detail, Notification Center, Portal Invite/Reset,
  consent-request flow, governance action-detail drilldowns. *A real clinic would eventually want several.*
- **(F) Buildable-unvalidated:** No-Show Follow-Up, Patient Flow, Medical History Intake, Payment Plan,
  Super-Admin Tenants/Create, operator-mode/break-glass UI, New Agent Wizard / Agent & Tool Config.
- **(H) Intentional non-goal (fence):** odontogram DMFT/severity, Lab Result Review AI-flagging, AI
  caries/pathology detection — **correctly absent; never build.**

## 10. What's solid / works well
- **The electric fence is genuinely enforced end-to-end** — rigorous prop-scan clean on all 7 fenced surfaces;
  the DMFT/finding/flag the *prototype designed in* are deliberately removed live. This is the product's core
  promise and it holds under real inspection.
- **RBAC is airtight** — server Gate authoritative; every denial 403 by URL; nav correctly gated per role.
- **Zero functional defects** — no 500/blank/console-error across ~90 visits in both verticals + portal.
- **POLISH.1 landed correctly in the browser** — Orders + Import reachable from nav; the series-end panel works
  (active→ended); dental-chart append-only.
- **Design is polished + coherent** (Eucalyptus Glow), sub-1.1s pages, real German-locale demo data, honest
  empty states, styled 403, amber (never red) allergy banner.
- **Append-only + audit** hold (dental write = new row; +8 audit_events for the session's writes).

## 11. Verdict
**Both the CLINIC and DENTAL verticals are demo/deliver-ready.** Driven live, role-by-role, across both
verticals + portal, **no Critical/High/Medium functional, safety, fence, RBAC, billing, or data-integrity
defect was found**, and every prior fix (C-1, M-series, W8–W10) plus **POLISH.1** holds in a real browser.

**Must-fix before delivery (Critical/High safety/correctness):**
> **NONE.**

**Polish / later (all category (a), non-blocking):**
- Group the org_admin admin/governance nav under a menu (15-item density, worse post-POLISH.1).
- Run a dedicated a11y/contrast pass on the dense odontogram/perio grids.
- (Optional) drive the remaining create-flows (full register, KB, import upload, billing write-loop) in a
  follow-up harness pass — their pages load and backends are test-locked, but a live UI confirmation is nice.

---

> **Demo-data note:** this exercise left LIVE mutations in the demo tenants — one recurring series **ended**,
> **+1 dental tooth-record** (append-only chart), and audit rows. **Re-run the seeders to reset:**
> `php artisan migrate:fresh --seed` then `db:seed --class=DemoClinicSeeder|DemoSpitexSeeder|DemoDentalSeeder`.
> The `.env` cache/queue drivers were temporarily switched to database/sync (Redis unavailable) and **restored**
> to the committed values after the run.

*Driven live via Playwright (Chromium) with per-screen status/console/timing capture, recursive fence prop-scans,
RBAC-by-URL checks, and DB before/after deltas. No application code, route, controller, prop, test, or migration
was changed.*
