# CareOS — Onboarding (read this FIRST)

The single file a new session (Claude Code **or** Codex) reads first. Read it in this order; after it, §1's read
order gives you the complete, accurate context.

---

## 0. The SIX things that decide what you should do

### 1. THE BUILD IS COMPLETE AND AUDITED

**EIGHT verticals** on one shared multi-tenant platform — clinic · dental · home-care (Spitex) · inpatient/ADT ·
pharmacy · surgery/OR · ED · lab (LIS) · radiology (RIS) — plus a separate **offline Nurse PWA**. Three
full-system QA audits, zero must-fix. A11Y.1 done. Deploy-readiness audited, production `.env` template written,
and **first-customer provisioning built and tested** (`plans:seed` · `tenant:create` · `tenant:add-admin`,
`b006d07`, D-165) — the readiness verdict is **🟢 GO**.

**HL7/FHIR (LAB.G7) and DICOM/PACS (RAD.G6) are correctly ABSENT** — they are certified-partner seams, wired as
interfaces with a Manual/Null implementation bound (`LabConnectivity` → `ManualLabConnectivity`;
`ImagingConnectivity` → `NullImagingConnectivity`). There is no HL7 parser or DICOM client in `composer.json`,
and there must not be a homemade one.

### 2. THE BUILDABLE WIREFRAME-PARITY PROGRAMME IS COMPLETE — DO NOT INVENT WORK

**The original NINE pages** — Admin Settings `e7cabf0` · Approval Queue `ea0e9b3` · Branches `a865a31` ·
Agent & Tool Config `d0199e3` · Allergy Alert **safe part only** `46e45d1` · Billing & AR `aa82ea0` ·
AR Account Detail `3ddc7ab` · Appointment Detail core `ca90273` · Auth Screens (§3 security work).

**And SIX domain batches:**

| Batch | Tip | Screens | Deferred / declined |
|---|---|---|---|
| Dental | `7d7f354` | 9 of 13 | four net-new subsystems deferred; **~20 computed-clinical-judgment items refused** |
| Patients & Clinical | `4d50cba` | 9 of 12 | Allergy Alert partner-gated · Care Plan Review ~70% refused · two subsystems deferred |
| Portal | `ae00b5a` | **11/11** | — |
| Governance & AI | `8f0b2e8` | **10/10** | 7 already parity-complete from APPROVAL/AGENT |
| Comms | `ab9e62c` | 2 core | **screens 2/4/6 DECLINED (D-188)** — a per-topic/per-channel consent, household and campaign model the product does not have |
| Scheduling | `cc0ed68` | 3 core | **P4 waitlist DEFERRED by decision** (create path does not exist → feature unreachable; keeps **D-191 closed by absence**) · **No-Show Follow-Up DECLINED (D-188)** — triage by clinical risk |

> **DO NOT invent a vertical, a parity page or a batch.** There is none left. If a pasted task names one,
> verify it against the repo first (see §4's re-paste rule).

### 3. SECURITY WORK — fixes, not parity

| Fix | Commit | What it closed |
|---|---|---|
| **AUTH-SEC.1** | `4334017` | Remember-me no longer bypasses the 2FA challenge — **a ~400-day standing bypass** |
| **AUTH-SEC.2** | `39c0413` | Password-reset views bound + a **guest-route smoke**, so a public 500 cannot ship green |
| **AUTH-VIS** | `8a9a867` | 2FA enrolment manual-secret fallback (the user's own real secret) |
| **OPMODE.G1** | `41a8dea` | **The LIVE super-admin containment gap** — `Gate::before` AND `PermissionService::has` both made fail-closed and grant-gated, regression-guarded |
| **D-185** | PT.P6 chain | Invite refusals made **indistinguishable in shape** — a tenant-enumeration oracle |
| **PT.P7** | `ae00b5a` | The portal password-reset broker — patients had **no self-service recovery at all** |

### 4. OPERATOR MODE — the security core is DONE; G4–G11 are DELIBERATELY DEFERRED

**G1 `41a8dea` · G2 `0afaa67` · G3 `c086de5`** = the security core + the full approval backend, **done and
live-safe**.

**G4–G11 are deferred on purpose (D-164):** operator-facing UI. The backend is **inert — there is no HTTP
route** (verify: zero operator routes in `route:list`). It adds no safety property G1–G3 do not already enforce,
does not block deploy, and is **not unfinished by accident**. Plan: `docs/features/OPERATOR-MODE-MAP.md`.
**Do not "finish" it.**

### 5. THE TEN-PHASE ROLE-BY-ROLE QA PROGRAMME IS COMPLETE — ZERO OPEN CRITICALS

**Ten audit phases** drove every role in a **real browser** (Playwright MCP), one role group per phase:
reception `06a3f78` · clinician `a5e17dc` · billing `a5cea30` · nursing/Spitex + Nurse PWA `4dfd59c` ·
pharmacy `3199a7c` · surgery/OR `6784bfa` · ED `9c4dc63` · lab + radiology `f5959c8` · bed management +
medical records `41cc78e` · admin/governance + patient portal `9507803` (+ addendum `ab59c27`).
**Ten fix gates** (QA-FIX.1 … QA-FIX.10) across **29 code-changing parts** closed the top of the list.

**`docs/qa/ROLE-AUDIT.md` IS THE AUTHORITATIVE RECORD — 7 180 lines, and it is append-only by its own rule:**

- **Findings are recorded permanently and are NEVER removed when fixed.** A fixed finding keeps its **ID**,
  its **evidence** and its **reproduction**, and gains a **FIXED banner** naming the gate, the commit and the
  decision. Read a banner before re-investigating anything.
- **Nothing is rewritten.** Corrections are appended as dated blocks or correction banners — see the
  `P10-M6` correction and the *"STATE AS OF THIS RECONCILIATION"* block at the end of the file.
- **186 findings · 41 fixed · 145 open — 0 CRITICAL · 34 HIGH · 80 MEDIUM · 31 LOW.** Counts verified by
  counting the artifact. **Counting `####` headings gives 157 and is WRONG** — Phases 1, 4 and 5 record some
  MEDIUM/LOW findings in condensed grouped form. Count distinct IDs.

**WHAT ZERO OPEN CRITICALS MEANS, and what it does not.** **No known defect remains that loses data,
falsifies a clinical or financial record, or breaches authorisation.** All 24 findings recorded as CRITICAL
are fixed. It was reached by fixing them, not by moving them: **no finding was withdrawn and none was merged
away.** The one honest exception is **`P4-C4`, re-graded CRITICAL → HIGH by QA-FIX.4b** because the defect
was latent rather than active (the shipped client only ever sends UTC; the offending evidence came from a
curl-crafted action) — **and it was fixed in that same gate**, so it does not contribute to the zero.

**THE 34 OPEN HIGHs ARE A DIFFERENT CLASS** and none blocks deployment: defects of **reach** (a permission
with no surface, a module with no nav entry — 16 of the 34), **visibility** (a refusal nobody sees — 4),
**recording** (PHI disclosed with no audit row — 3), **display/locale** (3), **attribution not surfaced** (2),
**one partial write** (`P4-H2`, the last of six), and five misleading or irreversible operations. Grouped into
seven families with the precedent fix for each in `DEFERRED.md` — **take a family, not a phase.**

**Do not start a QA-FIX.11, and do not pick a HIGH to fix unprompted.** Wait for a pasted gate.

### 6. THE ONE REMAINING TRACK IS DEPLOYMENT + PARTNERSHIPS

If asked *"what's next?"* — the answer is **DEPLOY**. ⚠️ An **undiagnosed staging error** is still parked and
**no detail about it was ever captured**; expect to reproduce it from scratch. Everything else is in
`DEFERRED.md`, prioritised, including the open gaps this programme surfaced (portal session invalidation,
the unrecorded telehealth join, unguarded availability withdrawal, unaudited catalog/availability writes, the
waitlist create-path blocker, the two Appointment follow-ons, and the password-policy product decision).

---

## 0b. THE METHOD RULES — read these before you write a test in this repo

The fences are in §3; these are the **formulations that made them hold**, and they are the most transferable
output of everything done here. Each one exists because a green test proved nothing, or because a tool lied.
Full text in `DECISIONS.md`, which runs **D-001 → D-223** with **no gaps and no duplicates** (the QA era is
**D-192 → D-223**).

### The guard-vacuity rules — a green assertion that proves nothing is the recurring failure

- **D-174 — an absence assertion over an empty collection is VACUOUSLY TRUE.** A payload scan whose fixture
  recorded no rows, a glob that resolves to no files, a count rendered from an empty list: all pass for ever
  while protecting nothing. Always add a **positive control** proving the subject was non-empty, **over data
  that would TEMPT the breach** — an abnormal vital, a severe allergy, a deep pocket, an expensive fee, a
  populated pick-list. *Written after a vitals fence scan passed over a fixture with no vitals.*
- **D-182 — a refusal test must be one that would SUCCEED without its guard.** Give the refused path
  everything it needs except the thing under test. *Written because "a soft-suspended branch offers no slots"
  passed **with the guard deleted** — the fixture's branch had no resources, so the finder returned empty
  either way.*
- **D-183 — a guard behind another guard cannot be tested through the front door.** Defence in depth is only
  real if each layer is pinned SEPARATELY, with a subject only that layer can refuse (call the service
  directly). An earlier guard otherwise hides a later one from its own test.
- **D-187 — a mutation that changes nothing proves nothing.** *Written on `TenantContext::system()`, which is
  a **no-op while a tenant is in context** — so "mutating" a scoped lookup to system mode removed nothing and
  the guard was never exercised.* **Grep-confirm every mutation actually applied before you run the suite.**
- **D-189 — a symmetric or round-numbered fixture lets a hardcoded assertion pass.** If a test pins a
  *choice*, the fixture must make the two choices give **different** answers. Make fixtures awkward: uneven
  amounts, distinct names, three items rather than one. *Appeared six times.*

### The comment-stripping rule — this bit FIVE times

**Any scan for a forbidden token must strip comments first, because the file explaining why the token is
forbidden will contain it.** A structural scan reddened on its own explanatory comment in **QA-FIX.6a**, twice
in **QA-FIX.7b** (the D-170 equivalence-table scan and the `'~'` sentinel guard), and twice more in
**QA-FIX.8a** — five times. The rule: **strip comments before scanning, or target the code expression rather
than the character**, and add a D-174 positive control proving the strip did not hollow the scan out.

Its corollary, and the reason every fix gate in this repo now does it: **a mutation must be verified applied
by a COMMENT-STRIPPED grep before the test runs.** Three false confirmations were caught that way, two of them
because the grep counted the pattern inside the author's own explanatory comment — and in QA-FIX.9b a mutation
**silently failed to apply while the suite stayed green**, which without the comment-stripped count would have
read as a vacuous test.

### A fixture whose two people are the same person cannot catch a misattribution

This is why **`P2-C1`, `P6-C2`, `P7-C1` and `P9-C3` all survived their own suites**: each was an attribution
defect — a note, an assessment, a triage, a ward round recorded against the wrong person — and in every case
the existing test fixture had the actor and the attributed person be the **same user**, so storing either one
gave an identical result. **Make actor ≠ subject on purpose.** The related pattern is *resolving a person by
convenience* (a dropdown default, an alphabetical `first()`, an unconditional copy of a stay's admitting
clinician); the dropdown is only its most visible form. Remedy: `StaffProfile::forUser()` returns **null**
rather than guessing (D-195, D-216), and a caller that cannot identify the actor **refuses to write**.

### Tools lie — read the output, not the status

- **EXIT CODES LIE. Read the log text.** `composer check` has **exited 0 with failures at least four times**:
  QA-FIX.9a (Pint had FAILED), QA-FIX.10a (only the last 12 lines were captured, so Pint and PHPStan were
  never actually seen), QA-FIX.10b (exited 0 with **2 failing tests**), and earlier in the programme. Confirm
  **Pint `passed`**, **PHPStan `[OK] No errors`** and the **Pest summary line** individually, by reading them.
- **LOCAL-GREEN ≠ CI-GREEN.** Verify every gate through the GitHub API at
  `commits/<sha>/check-runs`. MariaDB (dev) and MySQL 8 (CI) disagree about JSON re-serialisation, Redis is
  present on CI and absent locally, and time-relative fixtures flake on slower runners. *Note: GitHub's
  check-run retention expires — three QA-FIX.6 commits now return `total_count: 0`, which is retention, not
  failure; their contemporaneous LOG entries record them green.*
- **A FILE-LEVEL GREP ANSWERS THE WRONG QUESTION.** `grep -i audit` over a controller tells you whether **that
  file** audits, not whether **the request** audits — the answer can live one call deep. *QA-FIX.10a's export
  enumeration wrongly reported `DentalImageController::download` as unaudited; it audits inside
  `DentalImagingService::fileContents()`, and the controller's own docblock said so.* **An enumeration that
  reports a negative must follow the calls first.**
- **WHEN A SERVICE METHOD IS THE GATE, ENUMERATE ITS CALLERS BEFORE CONCLUDING THE GATE HOLDS.** *`P10-C3`:
  the approval queue's gates were verified in both directions, but nobody asked which OTHER routes call
  `ApprovalQueue::approve` — one did, ungated, and any clinician could execute a clinical agent action by
  posting its id.* Verifying a gate at one call site proves that call site, not the gate.

### The browser catches what tests cannot

Code reading is a complement, never a substitute. The instances that earned the rule:

| What the suite said | What the browser showed |
|---|---|
| `P6-C1` / `P8-C1` — tests green | A `<script setup>` name collision (`invoice` as both prop and function) made `v-if="invoice"` permanently true. The screen printed **"Total: NaN"** under *"No charge captured yet."*, and the issue button never rendered. |
| `P7-C3` — tests green | `localeCompare` on an acuity string. ESI and CTAS survived by luck; **Manchester inverted**, so a control labelled *"Recorded acuity"* put the **least** urgent patient first. |
| **QA-FIX.4d — all 36 unit tests passed on a fix that did not work** | The dedupe memo was claimed AFTER an `await`. Sequentially correct; but `@change` and `@click` are in flight **simultaneously**, so the second call read a stale memo. Driven: **"Pending offline actions: 2"** and two rows on the server. A green suite describing a fix that did nothing. |
| `P10-C2` — no unit test would ask | The waitlist half-commit appears only if you change the world **between** propose and approve and then inspect three tables. |
| **QA-FIX.10b — 8 green unit tests** | **vue-i18n resolves a message key as a DOTTED PATH**, so `actions.document.shared` never matched a flat `"document.shared"` entry. The tests passed because the fake translator did a flat lookup — **the stub was easier to satisfy than the real thing, so it tested the stub.** The page rendered the raw action. |
| **And the reverse** | Grepping `Portal/Login.vue` for `errors` returned **zero**, while the browser showed *"Those credentials do not match our records."* **The grep was wrong.** Code reading needs the browser as much as the browser needs code reading. |

### Two more, earned late

- **A finding that needs a precondition the product cannot create is still a legitimate browser finding** —
  *if* you state exactly how the precondition was arranged and **restore it afterwards**. Phase 8 nulled a
  `user_id`; Phase 9 provisioned a role through `/admin/roles`; Phase 10 detached a permission from a role row
  to make an unreachable guard reachable. Each was restored and verified.
- **A pattern definition is a claim, and claims get corrected by evidence.** Pattern 1's root cause was
  written wrongly for seven phases (blamed on the nav map; QA-FIX.7d proved it was ungated `<Link>`s in the
  page body) and Pattern 7's shape was corrected twice. Both were fixed with **correction banners, not quiet
  amendments.**

### The honesty rules — what may appear on a screen

- **D-170** — never invent a backend, agent or tool to match a mock. Omit, and **state the omission**.
- **D-176** — **an unbacked PRESENCE is worse than an absence.** A disabled control still says the capability
  exists.
- **D-179** — never assert an action never taken. *A tool that RETURNS is a tool that succeeded: when
  `FillFromWaitlistTool` returned `booked => false`, the queue recorded the action as "Approved" although it
  booked nothing (`P10-C2`). It must throw.*
- **D-188** — when a wireframe assumes a **different architecture**, decline it rather than shipping a
  misleading reduction.
- **D-197** — record, don't rewrite. Historical rows written by a now-fixed defect are **counted and
  reported**, not silently corrected.
- **D-199** — **one operation, one transaction.** Six instances of create-then-associate-outside-a-transaction
  were found (`P3-C1`, `P4-H2`, `P6-M10`, `P7-M5`, `P8-H2`, `P10-C2`); five are fixed and **`P4-H2` is the
  last one open.**
- **D-221** — an export that names patients writes **two shapes the product already had**: a ledger row for
  the file, and an `action = 'read'` row per named patient. **Do not invent an action string** — a bespoke
  action produces a well-formed, hash-chained row that no patient can ever see.

**The rest, all load-bearing:** D-166 (a stat tile is CLOSED — no computed value enters one) · D-169 (a
severity ramp needs no judgment word — the rule lives in the **styling**) · D-171 (licensed data kept out by a
repo-wide scan) · D-172 (on a clinical image the breach is **DRAWING**) · D-173 (a guard dies on a file
**MOVE** — scans must resolve their subject) · D-181 (patient-facing and staff-facing components do not merge)
· D-184 (prove the **carve-out**, not just the rule) · D-190 (do not write a record that may be false into an
**append-only** table) · D-191 (an undocumented ordering column is a fence hole waiting for a label) · D-210
(`RefusalNotice` — a refusal nobody can see is not a refusal) · D-214 (the nav map and the page body must
agree about what the role can do).

## 1. Read order

1. **`AGENTS.md`** — single source of truth: project, stack, hard rules, workflow, module map, MEMORY PROTOCOL.
2. **`PROJECT-STATE.md`** — authoritative "where we are" snapshot (BUILD COMPLETE · eight verticals · all hospital
   phases · focus = deploy + partnerships · latest commit + suite counts).
3. **`docs/qa/ROLE-AUDIT.md`** — **the authoritative QA record, and the largest artifact in the repo.** Ten
   phases of role-by-role browser driving; **186 findings, 41 fixed, 145 open (0 CRITICAL · 34 HIGH · 80
   MEDIUM · 31 LOW)**. **Append-only by its own rule:** a fixed finding is never removed — it keeps its ID,
   evidence and reproduction and gains a **FIXED banner**. **Read the banner before re-investigating
   anything.** Start at the fix-status table near the top, then the *"STATE AS OF THIS RECONCILIATION"*
   block at the very end, which supersedes the stale counts in the programme-closing summary.
4. **`DECISIONS.md`** — architecture decision log, **D-001 → D-223** (223 entries), verified with **no gaps and
   no duplicates** (append-only; **never edit a past entry**). The QA era is **D-192 → D-223**. The ones a new
   session actually needs are collected in **§0b above** — the guard-vacuity rules
   (**D-174/D-182/D-183/D-187/D-189**) and the honesty rules (**D-170/D-176/D-179/D-188/D-197/D-199/D-221**). Also load-bearing: **D-149** (the wireframe-parity discipline itself),
   **D-155/156/157** (the Appointment Detail chain — real display · the real `LEGAL_TRANSITIONS` action row ·
   the real slot-finder + overlap guard; **D-156** is why the day-board may compose confirm→arrive),
   **D-158/159/160** (the auth security sprint), **D-161→D-164** (the Operator Mode chain, ending in *paused
   after its security core, deliberately, with no HTTP surface*), **D-165** (first-customer provisioning) and
   **D-185** (refusals must be indistinguishable in shape).
5. **`DEFERRED.md`** — the parked backlog, each item with its pull-forward TRIGGER, **plus the QA open list
   grouped into seven families with the precedent fix for each** — take a family, not a phase.
6. **`memory/LOG.md`** — one line per completed gate; the full build history (Phases 0/A–G · P0P ·
   CLINIC.W1–W10 · FIX.1–5 · POLISH.1–3 · UI.F1–2 · DENTAL.G1–9 · HOSPITAL.G1–7 · PHARMACY.G1–5 ·
   SURGERY.G1–5 · ED.G1–6 · LAB.G1–6 · RAD.G1–5 · A11Y.1 · the OPMODE.G1–G3 security core · the original
   parity gates [SETTINGS.P1–6 · APPROVAL.P1–7 · BRANCH.P1–5 · AGENT.P1–6 · ALLERGY.P1 · BILLAR.P1–7 ·
   ARDETAIL.P1–6 · APPT.P1–3 · AUTH-SEC.1 · AUTH-SEC.2 · AUTH-VIS] · **the six domain batches**
   [DENTAL-B.P1–6 · PC.P1–7 · PT.P1–7 · GOV.P1–3 · COMMS.P1–2 · SCHED.P1–3] · the reconciliation entries).
   **~254 entries, and EVERY marker carries a real, resolvable commit hash** — a LOG line maps to the commit
   that shipped it. **The marker convention is repo-wide, not LOG-only:** sweep with
   `grep -rn "<pending>" memory/ docs/ *.md`, because module files carry their own markers and an earlier
   file-scoped sweep left nine of them stale for ten days. Never backfill by `--amend` (it re-hashes the
   commit); backfill in the *following* commit.
7. **`memory/modules/*.md`** — per-module deep notes (21, incl. the cross-module **`OperatorMode.md`** — read it
   before touching anything operator-related): Platform, Audit, AiCore, People, Patients, Scheduling,
   Clinical, Billing, Comms, FrontDesk, Nursing, Reporting, Import, **Dental, Hospital, Pharmacy, Surgery, ED,
   Lab, Radiology**.
8. **The vertical MAPS** (each vertical is MAP-FIRST — a reconciliation/scope map before code):
   `docs/CLINIC-DELIVERY-MAP.md` · `docs/DENTAL-DELIVERY-MAP.md` · `docs/HOSPITAL-PHASE1-ADT-MAP.md` ·
   `docs/HOSPITAL-PHASE2-PHARMACY-MAP.md` · `docs/HOSPITAL-PHASE3-LAB-MAP.md` ·
   `docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md` · `docs/HOSPITAL-PHASE5-SURGERY-MAP.md` · `docs/HOSPITAL-PHASE6-ED-MAP.md`.
9. **`docs/FEATURE-INVENTORY.md`** — classified gap map (why each remaining thing is unbuilt).
10. **`docs/MASTER-STATUS-REPORT.md`** — cross-vertical status + gap audit.
11. **`docs/DB-PARITY.md`** — MariaDB-10.4 (dev) ↔ MySQL-8 (prod/CI) parity notes (the P0P.G15 `dateTime()` rule).
12. **The audit reports** — `docs/QA-AUDIT-REPORT.md` (live-browser QA + FIX.1–5), `docs/DEEP-AUDIT-REPORT.md`,
    `docs/FULL-EXERCISE-AUDIT-REPORT.md` (all-roles exercise). Plus `docs/ONBOARDING-REHEARSAL-REPORT.md`,
    `docs/DISCOVERY.md`, `docs/SCREENS.md`, `docs/AGENT-EVALS.md`, `docs/DEPLOY-RUNBOOK.md`.
11b. **`docs/wireframe-parity/*.md`** — the parity programme's audit/diff reports. **SEVENTEEN docs, ALL
    COMPLETE/RESOLVED — the programme is CLOSED.** The original nine: `ADMIN-SETTINGS-DIFF.md`,
    `APPROVAL-QUEUE-DIFF.md`, `BRANCHES-DIFF.md`, `AGENT-TOOL-CONFIG-DIFF.md`, `ALLERGY-ALERT-DIFF.md`,
    `BILLING-AR-DIFF.md`, `AR-ACCOUNT-DETAIL-DIFF.md`, `APPOINTMENT-DETAIL-DIFF.md`, `AUTH-SCREENS-DIFF.md`.
    The six batch diffs: `DENTAL-BATCH-DIFF.md`, `PATIENTS-CLINICAL-BATCH-DIFF.md`, `PORTAL-BATCH-DIFF.md`,
    `GOVERNANCE-AI-BATCH-DIFF.md`, `COMMS-BATCH-DIFF.md`, `SCHEDULING-BATCH-DIFF.md`. Plus
    `WIREFRAME-INVENTORY.md` and `WAITLIST-MANAGEMENT-DIFF.md`. **What is still noted in them are HONEST
    BACKEND GAPS and DELIBERATE DECLINES, not parity failures** — see `DEFERRED.md` (e) and (f). The decoded
    wireframes live in the **gitignored** `resources/prototype/` (regenerate by decoding the bundle; never
    committed).
13. **The scoping doc** (`careos-hospital-expansion-scoping.md`) — **NOT in-repo** (external); the hospital build is
    captured in the six HOSPITAL-PHASE maps above.
14. **this file** (`docs/ONBOARDING.md`).

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

## 3. THE FENCES — the product's safety case, and what ten phases of driving proved

**Ten phases of adversarial driving eroded NO FENCE.** Every one of the 186 findings was a defect of
**presentation, navigation, attribution, a partial write, a recording gap or authorisation** — never of the
engines or the fences. Nothing computed a clinical judgment, no acuity was derived, no severity was styled, no
money was computed page-side, no agent ceiling was exceeded, and no append-only record was mutated. `P5-C1`
came closest and was **not** a fence failure: the medication-safety seam was correct and the screen simply did
not show it.

The safety case in one table, each row with the **strongest positive control actually driven** — not the rule
as written, but the observation that would have failed had the fence given way. Full evidence in
`docs/qa/ROLE-AUDIT.md` §3.

| Fence | What it forbids | Strongest control driven |
|---|---|---|
| **Record-not-judge / render-not-judge** (D-169) | Any colour, band, flag, arrow or weight that grades a clinical value | A **mild** and a **severe** allergy render **byte-identically**. A Kalium **6.8** against a `3.5–5.1` range renders in the same container, span class, colour `rgb(42,51,42)`, weight 600, size 18px and border as an in-range 4.2 — with the abnormal value **created by driving**, because the seed had none. |
| **Acuity is ASSIGNED, never computed** | Computing, suggesting, prefilling or tinting a triage level | `NullTriageAcuityProvider` returns `none()`; its docblock distinguishes *"'CareOS makes no acuity claim', not 'this patient is low acuity'"*; the empty state reads *"No automated suggestion. The triage nurse assigns the acuity."* The one place the recorded judgment was displayed in the wrong ORDER was found and fixed (`P7-C3`). |
| **Medication safety is a null-object seam** | Any interaction, cross-reactivity or contraindication check | `MedicationSafetyProvider` states in the product's own words that this *"is a certified-partner function and is not performed here"* — **with no cleared state and no green tick anywhere** on the dispensing screen. A homemade version is a permanent NON-GOAL, not a backlog item. |
| **Result interpretation** | Abnormal flags, range verdicts, critical alerts, deltas, CAD | None exist. Reference ranges are displayed as tenant-authored data beside the value and the screens say so; a radiology amendment produced v2 while **v1 survived byte-identical**. |
| **Checklists RECORD, they do not ENFORCE** | A blocking checklist or a computed surgical risk | A case was **completed at 0 of 17 checked**, with nothing anywhere claiming otherwise. ASA III and ASA I render identically. |
| **Money is engine-computed, and δ=0** | Any page-side derivation of a figure the engine owns | `ChargeSetReader` returns the engine's stored amount **already formatted**, so no Vue does money arithmetic (D-208/D-215). The composite hospital episode reconciles **δ=0 across six invariants**. |
| **Agent ceilings, human commit** | Any autonomy above the tool's ceiling; any agent acting without a human | A forged `auto` for three tools was **clamped** to `suggest`/`approve`/`approve`, and the audit row recorded the **clamped** values. Bulk approval refused a forged clinical id (`excluded: 1`) and skipped a cross-tenant one. Approve **re-authorises** (403 driven) and **re-grounds** against live state (a double-booking refused by a live resource lock). A human edit is re-validated by the tool's own fence. |
| **Append-only records** | Editing or deleting a clinical or audit fact | Model `appendOnly()` guards **plus** `SIGNAL '45000'` database triggers on `lab_results`, `order_results`, `imaging_study_events`, `stay_events`, `audit_events`. The audit ledger is a per-tenant hash chain verified by replay — the governance dashboard reported **Valid, 379 events**. |
| **Tenant and patient isolation, fail-closed** | Reaching another tenant's or another patient's row | **Every** forged cross-patient portal probe refused — 404 on documents, invoices, consents, appointments and check-in; 403 with an explicit message on telehealth and messages. A cross-tenant agent-action id in a forged bulk was skipped fail-closed. `TenantContext` refuses to query without a tenant. |
| **Disclosure is visible to the patient** | A read of a patient record that leaves no trace | ~65 staff call sites and eight portal surfaces write `action = 'read'` rows carrying the patient id; the access-log screen and its nDSG/GDPR export are **ONE query**, so the file cannot disagree with the screen; viewing and exporting are themselves audited and appear in the next view. The gaps were recorded, and `P9-C1`/`P9-C2` are fixed — `P9-H2`, `P10-H5` and `QF10a-H1` remain open. |
| **No invented workflow or metric** (D-170/176/179) | A control that lies, a number with nothing behind it | The KB ranks nothing and scores nothing; governed-agent cards print `—` where there is no denominator; the AI *"Estimated cost CHF 0.02"* is a real `SUM(cost_minor)`. The referral — the one third-party-facing artifact — deliberately **transmits nothing and says so**. |

## 3a. Hard rules (never violate — these OVERRIDE defaults)

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
  orders/results/notes were REUSED from Clinical). **All eight verticals and all hospital phases are built,
  and the BUILDABLE PARITY PROGRAMME — the original nine pages AND all six domain batches — is COMPLETE.**
  **Do NOT invent a vertical, a parity page or a batch.** The repo-reality rule above caught **three re-pasted
  gates** during this programme (COMMS.P2, SCHED.P1 and one earlier); each time HEAD already contained the work,
  and the right move was to report that rather than rebuild it.
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

## 5. What's built (the EIGHT verticals)

> **Current focus lives in §0.5: DEPLOYMENT + partnerships.** Nothing in this section is queued work — it is
> the inventory of what exists.

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

**🏁 AND THE ENTIRE BUILDABLE WIREFRAME-PARITY PROGRAMME IS COMPLETE TOO (tip `cc0ed68`, SCHED.P3,
2026-08-30).** Its first leg — the NINE pages — closed by AUTH-VIS (`8a9a867`, 2026-08-17): Admin Settings ·
Approval Queue · Branches · Agent & Tool Config · Billing & AR · Allergy Alert (safe part) · AR Account Detail ·
Appointment Detail (core) · Auth Screens. **Then the SIX domain batches closed the rest — see §0.2.** It ran
under one rule — **match the visual, but
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
