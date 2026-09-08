# CareOS — Role-by-Role QA Audit (cumulative)

**Purpose.** A per-role audit of the live product, driven in a real browser (Playwright MCP) and
cross-read against the code. One phase per role group. This file is **CUMULATIVE**: each phase
APPENDS its own section. Nothing here is ever rewritten or removed — a later phase may add a
"superseded by" note, never edit an earlier finding.

**The standing rule: findings are RECORDED, NOT FIXED.** No app code, test or seeder is changed by
an audit phase. Fixes are separate, later gates that reference finding IDs (e.g. `P1-H3`). This
keeps the audit an honest snapshot rather than a moving target, and stops a fix in one role's
surface silently invalidating another role's evidence.

**Method.** Every page in scope is DRIVEN in a real browser — logged in as each role, clicking the
real controls. Code reading is a COMPLEMENT: it explains a finding's cause and confirms a guard.
It is never a substitute for the browser, and no finding below is recorded from code alone unless
its wording says so explicitly.

**Severity.** `CRITICAL` data loss / wrong clinical or financial data / security · `HIGH` a core
operation is broken or a permission is wrong · `MEDIUM` a workflow is degraded or a state is
missing · `LOW` cosmetic / polish.

## Phases

| # | Role group | Status |
|---|---|---|
| **1** | **Reception / front-desk** (`reception`, `admissions_clerk`) | ✅ **DONE** — 2026-09-04 |
| **2** | **Clinician / physician** (`doctor` — medical **and** dental, `ed_physician` driven; `hospitalist`, `surgeon`, `anesthetist`, `pathologist`, `radiologist` compared but not driven) | ✅ **DONE** — 2026-09-05 |
| **3** | **Billing / finance** (`billing`, `org_admin` and `pharmacist` — the only three roles holding a `billing.*` permission) | ✅ **DONE** — 2026-09-06 |
| **4** | **Nursing / Spitex** (`nurse`, `coordinator`, `ward_nurse`, `charge_nurse` + the offline **Nurse PWA**) | ✅ **DONE** — 2026-09-06 |
| **5** | **Pharmacy** (`pharmacist`, `pharmacy_technician`) | ✅ **DONE** — 2026-09-07 |
| **6** | **Surgery / OR** (`surgeon`, `anesthetist`, `scrub_nurse`, `surgical_scheduler`; `org_admin` for the billing surface no surgery role can reach) | ✅ **DONE** — 2026-09-07 |
| **7** | **ED** (`ed_physician`, `triage_nurse`, `ed_charge_nurse`) | ✅ **DONE** — 2026-09-07 |
| **8** | **Lab + Radiology** (`lab_tech`, `pathologist`, `phlebotomist`, `radiographer`, `radiologist`; `org_admin` for the billing surfaces no lab/radiology role can reach) | ✅ **DONE** — 2026-09-08 |
| **9** | **Bed management + Medical records** (`bed_manager`, `him_records`; `ward_nurse`, `doctor`, `billing` and `org_admin` driven only for positive controls those two roles cannot reach) | ✅ **DONE** — 2026-09-08 |
| 10 | Admin / governance (`org_admin`) + patient portal | ⏳ planned |

## Severity summary (running)

| Phase | CRITICAL | HIGH | MEDIUM | LOW | Total |
|---|---|---|---|---|---|
| 1 — Reception / front-desk | 1 | 3 | 8 | 6 | 18 |
| 2 — Clinician (doctor / dentist) | 1 | 4 | 9 | 5 | 19 |
| 3 — Billing / finance | 1 | 4 | 8 | 2 | 15 |
| 4 — Nursing / Spitex (incl. Nurse PWA) | **5** | 5 | 10 | 3 | 23 |
| 5 — Pharmacy | 2 | 3 | 7 | 2 | 14 |
| 6 — Surgery / OR | 3 | 5 | 10 | 2 | 20 |
| 7 — Emergency Department | 3 | 5 | 7 | 2 | 17 |
| 8 — Lab + Radiology | 2 | 5 | 7 | 3 | 17 |
| 9 — Bed management + Medical records | 3 | 6 | 11 | 5 | 25 |
| **Total to date** | **21** | **40** | **77** | **30** | **168** |

*(Counts are as RECORDED at audit time and are not restated when a later gate re-grades a finding.
`P4-C4` was re-graded **CRITICAL → HIGH** by QA-FIX.4b — the defect was latent rather than active,
because the shipped client only ever sends UTC. The correction is written into that finding's FIXED
banner and its fix-status row, where the evidence sits.)*

## Fix status

Findings are recorded here permanently and are **never removed when fixed** — a fixed finding keeps
its ID, its evidence and its reproduction, and gains a FIXED banner naming the gate and commit that
closed it. That way a later phase can tell "this was never a problem" apart from "this was a problem
and here is what was done about it".

Two findings were pulled forward out of phase order because they blocked Phase 2: a clock skew makes
every later phase's timestamp observation suspect, and past-time booking was live on the public path.

| ID | Severity | Status | Gate | Commit |
|---|---|---|---|---|
| `P1-C1` | CRITICAL | ✅ **FIXED** | QA-FIX.1a | `78a05db` |
| `P1-H3` | HIGH | ✅ **FIXED** | QA-FIX.1b | `f6b619a` |
| `P2-C1` | CRITICAL | ✅ **FIXED** | QA-FIX.2a | `e8a7a48` |
| `P2-H1` | HIGH | ✅ **FIXED** | QA-FIX.2b | `706ed77` |
| `P3-C1` | CRITICAL | ✅ **FIXED** | QA-FIX.3a | `348d41c` |
| `P3-H1` | HIGH | ✅ **FIXED** | QA-FIX.3b | `d6f0cc5` |
| `P4-C1` | CRITICAL | ✅ **FIXED** | QA-FIX.4a | `ba7ddec` |
| `P4-C4` | CRITICAL → **HIGH** (latent, see banner) | ✅ **FIXED** | QA-FIX.4b | `ce2ebaa` |
| `P4-C2` · `P4-C3` | CRITICAL | ✅ **FIXED** | QA-FIX.4c | `3710efe` |
| `P4-C5` | CRITICAL | ✅ **FIXED** | QA-FIX.4d | `6f48c24` |
| `P4-H3` | HIGH | ✅ **FIXED** | QA-FIX.4e | `e7fc442` |
| `P5-C1` | CRITICAL | ✅ **FIXED** | QA-FIX.5a | `b9f5c91` |
| `P5-C2` | CRITICAL | ✅ **FIXED** | QA-FIX.5b | `88d50eb` |
| `P5-M4` | MEDIUM | ✅ **FIXED** | QA-FIX.5b | `88d50eb` |
| `P6-C1` | CRITICAL | ✅ **FIXED** | QA-FIX.6a | `9d5c047` |
| `P6-M10` | MEDIUM | ✅ **FIXED** | QA-FIX.6a | `9d5c047` |
| `P6-L2` | LOW | ✅ **FIXED** | QA-FIX.6a | `9d5c047` |
| `P6-C2` | CRITICAL | ✅ **FIXED** | QA-FIX.6b | `f8b7a7b` |
| `P6-C3` | CRITICAL | ✅ **FIXED** | QA-FIX.6c | `1388af3` |
| `P7-C1` · `P7-C2` | CRITICAL | ✅ **FIXED** | QA-FIX.7a | `d3e0f3c` |
| `P7-C3` | CRITICAL | ✅ **FIXED** | QA-FIX.7b | `ec3695e` |
| `P7-H2` | HIGH | ✅ **FIXED** | QA-FIX.7c | `fd7b350` |
| pattern 1 (`P1-H1`·`P2-H2`·`P3-M7`·`P4-H5`·`P5-H2`·`P6-H4`·`P7-H4`) — the OVER-OFFER half | HIGH | ✅ **FIXED** | QA-FIX.7d | `c999181` |
| `P8-C1` | CRITICAL | ✅ **FIXED** | QA-FIX.8a | `8636ea1` |
| `P8-C2` | CRITICAL | ✅ **FIXED** | QA-FIX.8b | `5a16624` |
| `P8-H2` · `P7-M5` | HIGH · MEDIUM | ✅ **FIXED** | QA-FIX.8c | `946cf87` |
| all others | — | 📋 recorded, not fixed | — | — |

*(A commit cannot contain its own hash. Per the repo-wide marker convention, `<pending>` is backfilled
in the FOLLOWING commit — never by amending, which would re-hash the commit and invalidate the hash
just written.)*

### Open decisions raised by the fixes

- **The historical +2 h skew is NOT rewritten (D-193).** Rows written by web requests before QA-FIX.1a
  carry the tenant's local wall clock in UTC columns. Scope: any tenant whose `timezone` is not UTC
  (all four demo tenants are Europe/Zurich); columns `audit_events.occurred_at`, `messages.created_at`,
  `appointments.created_at`/`status_changed_at`, and any `expires_at` minted on a web path; window
  CLINIC.W8b → QA-FIX.1a. A bulk correction is a data migration over append-only, hash-chained tables
  and cannot be done honestly — the per-row offset depends on the tenant zone AND the DST state on that
  date, and rewriting `occurred_at` would either break `verifyChain()` or require re-hashing the chain.
  **No customer is live, so this is cheap to decide now.** Product owner's call: leave history as-is
  (recommended) or fund a scoped correction with its own gate and a base-marker column. Do not let a
  future session tidy these rows silently.
  **Scope is narrower than "every web write":** the mutation was on the **web group only**; the API
  group never had it and **portal requests self-skipped** (portal tenant context is a route-level
  alias that runs after group middleware). A remediation assuming otherwise would over-correct.

- **`appointments.starts_at` is a SECOND, undeclared time base — surfaced, not created, by QA-FIX.1a.**
  It holds a **naive local wall clock** (derived from a date + opening-hour offset, never from `now()`).
  Six `where('starts_at', '>=', now())` comparisons lined up **by accident** while web-path `now()` was
  also local; with `now()` correctly UTC they are now uniformly off by the tenant offset. Sites:
  `BranchService.php:132` · `ResourceService.php:46` · `PortalHomeController.php:31,69` ·
  `InboxPatientContextReader.php:145` · `InboxDraftEngine.php:207` · the cancel window at
  `PortalAppointmentController.php:159`. **Direction:** the two deactivation guards become more
  conservative; the "next appointment" readers may name a just-passed appointment; **the portal cancel
  window is the only one that loosens.** None of the six has a test. **Not patched in this gate** —
  the honest repair is to declare `starts_at`'s base (a migration with its own gate), not zone
  conversions across six untested call sites. Recorded in `DEFERRED.md` with a trigger.

- **The past-start guard is strict on the four interactive paths only — waitlist-accept and recurring
  series are deliberately NOT strict (D-194).** `book()` keeps a permissive default because it is also
  the repo's historical-RECORDING path; making it strict by default would break both demo seeders and
  13 existing behaviour fixtures that legitimately book elapsed dates, which this gate forbade
  touching. The four paths the finding covers (day-board quick-book, staff reschedule, portal
  self-booking, public booking) each pass or inherit the strict value, and the finder means a
  legitimate UI cannot produce a past slot in the first place. **The residual:** a FUTURE interactive
  caller added to `book()` would inherit the permissive default, and waitlist-accept / recurring-series
  can still place a past start (a waitlist offer's ~30 min TTL keeps that exposure narrow). Product
  owner's call whether to invert the default and fix the fixtures in a gate of its own.

- **Notes written under the old attribution are RECORDED, NOT REWRITTEN (D-197).** Every note created
  through the day-board **Document** button before `QA-FIX.2a` carries the appointment's practitioner
  as its author rather than the clinician who typed it, and pre-fix amendments carry the superseded
  version's author rather than the amender's. **Scope, measured at fix time:** 24 notes across the
  four demo tenants, earliest `2026-08-25`; 2 show author ≠ signatory and both are the legitimate
  radiology shape rather than this defect. Where the documenting clinician *is* the appointment's
  practitioner — the common single-handed-practice case — the stored value was already correct.
  **A bulk correction is not safe:** `ClinicalNote::updating` refuses any change to a signed note, so
  it cannot go through the model at all, and **the true author is not recoverable from the note row**
  — only by reconstructing it from the audit chain (`encounter.opened` / `note.signed` carry the real
  `actor_id`). Rewriting a signature-bearing clinical row from a reconstruction is not a quiet
  operation. No customer is live. Product owner's call: leave history as it stands (recommended — the
  audit chain holds the truth and the immutability guard stays intact) or fund a scoped correction
  with its own gate and a column recording what evidence corrected each row.

- **`author_id` and `signed_by` speak different identity languages, and this gate did not unify them
  (D-196).** On one row, `author_id` is a ULID FK to `staff_profiles.id` while `signed_by` is an
  integer `users.id`; `tooth_records.charted_by` and `orders.ordered_by` are also `users.id`, so
  `author_id` is the odd one out. Unifying them is a migration over a versioned, append-only clinical
  table plus every reader of both columns — its own gate. **This gate did not deepen the split:** no
  new column, no third namespace, and the single place that must compare the two does so explicitly
  in one method with the reason at the call site. **The risk of leaving it** is not theoretical: an
  early Phase-2 query compared a ULID against the integer column, MySQL coerced it to `1`, and the
  audit briefly "found" that a note was authored by *Test User*.
---

## Phase 1 — Reception / Front-desk

**Date:** 2026-09-04 · **HEAD at audit:** `080f38d` · **CI at audit:** check-run `check` →
`completed / success` (GitHub check-run API) · **Tree:** clean apart from untracked
`docs/marketing-site/`.

### Roles covered

From `RbacProvisioner::ROLE_TEMPLATES` (26 templates), the reception / front-desk group is:

| Role | Permissions | Driven as |
|---|---|---|
| `reception` — "Reception" | `patient.view`, `appointment.manage`, `comms.manage` | Nadia Steiner · Praxis Lindenhof |
| `admissions_clerk` — "Admissions Clerk" | `patient.view`, `patient.edit`, `appointment.manage`, `comms.manage`, `admission.manage` | Rita Moser · Klinik Bergblick |

`admissions_clerk` is in-group because its own template comment defines it as *"Registration +
admission intake: **reception's set** + patient.edit + ADT"* — it is the hospital front desk.

**Considered and excluded, with reasons** (so the boundary is visible rather than assumed):
`coordinator` (nursing dispatch/roster — Phase 4), `surgical_scheduler` (OR list, theatre-scoped —
Phase 6), `bed_manager` (bed/ward operations, no patient-facing desk work — Phase 9). All three
hold `appointment.manage` or adjacent scheduling rights but none is a patient-facing arrival desk.

### Environment

- **DB:** MariaDB 10.4 (dev). Migrations all applied, `migrate:status` clean, 0 tenants before seeding.
- **Seeded:** base catalogs + all four demo seeders. Verified **by query**, not by exit code.
- **Redis:** initially **DOWN** — and `POST /login` returned **HTTP 500** because the login throttle
  is Redis-backed (`RateLimiter` → `RedisStore` → Predis connection refused). Recorded as `P1-M8`.
  Memurai was started (`memurai.exe --port 6379`) to proceed; `Redis::connection()->ping()` then OK.
- **Browser:** Playwright MCP throughout, Chromium, 1512×900 plus a 375×844 narrow-viewport pass.

**Seed-data adequacy (verified by query, Praxis Lindenhof):** 15 patients · 12 appointments · 2
today · **all 8 lifecycle statuses present** (booked, confirmed, arrived, in_progress, completed,
cancelled, no_show, rescheduled) · 1 waitlist entry · 6 threads · 7 invoices · near-duplicate
patient pairs for dedupe testing.

**Two requested fixtures were ABSENT and are stated, not glossed:**

- **No checked-in patient** — `checked_in_at` was NULL on every appointment. One was created during
  the audit by driving the real Arrive path; that is what exposed `P1-M1`.
- **No soft-suspended branch** — every tenant has exactly one branch, `active=1`,
  `accepts_online_bookings=1`. Reception cannot create that state (needs `admin.manage`), so
  reception's view of a suspended branch is **untested** (see *Not tested*).

> **PERFORMANCE IS EXPLICITLY OUT OF SCOPE for this phase.** This box is MariaDB with array/dev
> drivers and a locally-started Redis; timing numbers here would be meaningless. Performance is
> deferred to a staging re-run.

### Surfaces driven (explicit, so gaps are visible)

| Surface | reception | admissions_clerk |
|---|---|---|
| `/login`, `/two-factor-challenge` | ✅ | ✅ |
| `/app` landing + quick actions | ✅ | ✅ |
| `/patients` index + search | ✅ | ✅ |
| `/patients/register` (4-step wizard) | ✅ (403) | ✅ full submit, both outcomes |
| `/patients/{id}` Patient 360 + tabs | ✅ | — |
| `/scheduling/day-board` + resource lanes + stat tiles | ✅ | ✅ (empty state) |
| Day-board Arrive / Cancel / No-show / Document | ✅ | — |
| Day-board **Quick-book** modal | ✅ | — |
| `/scheduling/appointments/{id}` detail + action row | ✅ | — |
| Appointment **reschedule** (real slot finder, confirmed) | ✅ | — |
| Appointment **cancel** + reason validation | ✅ | — |
| Waitlist auto-fill panel + freed-slot picker | ✅ | ✅ (empty) |
| `/comms/inbox` list, filters, thread, **reply sent** | ✅ | ✅ (reachable) |
| Portal-invite issuance (`POST /portal/invitations`) | ✅ (403) | ✅ (allowed) |
| `/scheduling/availability` | ✅ (200) | ✅ (200) |
| RBAC forgery: 13 GET + 3 forged POST | ✅ | ✅ |
| Narrow viewport 375×844 | ✅ | — |
| Keyboard: modal Escape / focus / activation | ✅ | — |

---

### CRITICAL

#### `P1-C1` — Web requests write **tenant-local wall-clock into UTC datetime columns**, including the append-only audit ledger

> ✅ **FIXED — QA-FIX.1a, commit `78a05db` (D-192, D-193).** The process-wide
> `date_default_timezone_set()` is removed; storage is UTC from web, CLI, queue and scheduler alike,
> and the tenant's zone is resolved explicitly at the presentation boundary
> (`App\Services\DisplayTimezone`) so the shared `timezone` prop keeps the same value.
> **Re-measured in the browser, same steps as below:** CLI `21:41:45` UTC (Zurich local `23:41:45`)
> vs stored `messages.created_at` `21:40:58` and audit `occurred_at` `21:40:25`–`21:40:59` — a **48 s**
> gap that is just elapsed time, against **+7200 s** before. `audit:verify-chains` → `CHAIN:OK` on all
> four tenants. **Historical rows are deliberately NOT rewritten — see the open decision above.**
> Guarded by `tests/Feature/Platform/TimezoneStorageParityTest.php` (9, mutation-checked: reintroducing
> the mutation turns 4 red; every test runs on a non-UTC tenant with an explicit offset positive control).

- **Role:** both (any role; surfaced while driving reception)
- **Page/route:** every authenticated write path. Observed on
  `POST /scheduling/appointments/{id}/reschedule`, `POST /comms/inbox/reply`, and the resulting
  `audit_events` rows.
- **What I did:** Marked an appointment arrived, rescheduled another, and posted an inbox reply —
  all through the UI as `reception`. Then read the stored rows and compared against `now()` from the
  CLI (true UTC).
- **What happened vs what should have:**

  | Row written via web UI | Stored value | True UTC at that moment | Skew |
  |---|---|---|---|
  | `appointments.created_at` | `2026-09-04 22:21:27` | `~20:21:27` | **+2 h** |
  | `messages.created_at` | `2026-09-04 22:28:08` | `20:28:34` | **+2 h** |
  | `audit_events.occurred_at` (×3) | `22:15:36`, `22:21:28`, `22:28:08` | `~20:15–20:28` | **+2 h** |
  | Seeder rows (CLI, same day) | `20:05:17` | `20:05:17` | none ✓ |

  Every datetime written during a web request is stored as the tenant's **local wall clock**
  (Europe/Zurich, UTC+2) in a column every other consumer reads as UTC. Rows written by CLI, queue
  workers, cron and seeders are stored in true UTC. **The same column now holds two incompatible
  time bases.**

- **Cause:** `app/Http/Middleware/ApplyTenantLocaleTimezone.php:41` calls
  `date_default_timezone_set($timezone)`. `now()` then returns a Carbon in the tenant zone, and
  Eloquent serialises that wall-clock verbatim. The middleware's own docblock
  (`ApplyTenantLocaleTimezone.php:16-17`) asserts the opposite — *"it does NOT touch
  `config('app.timezone')`, so Eloquent keeps serialising UTC"* — and that claim is **false**.
  D-095 records the same incorrect reasoning.
- **Why CRITICAL:** it is silent, systemic wrong data in an **append-only, hash-chained** ledger.
  `AuditService::verifyChain()` replays ordered by `occurred_at ASC, id ASC` while `prev_hash` is
  linked at INSERT time — exactly the hazard D-066 was written about. With two time bases mixed,
  any CLI/queue-written row (true UTC ≈ 20:30) inserted *after* a web row (stored 22:28) sorts
  *before* it on replay, diverging replay order from hash-link order.
- **Stated precisely — the chain is NOT broken today.** `php artisan audit:verify-chains` returns
  `CHAIN:OK` for all four tenants (355 / 460 / 67 / 234 events), because every web row so far
  post-dates every CLI row. The mechanism for a future break is present; the break has not
  occurred. I did not manufacture one, as that would corrupt the demo tenant's ledger.
- **Also affected (same root cause, not separately verified):** any comparison of a stored timestamp
  against `now()` in a scheduled command — reminder dispatch, dunning day-counts, offer-expiry
  sweeps, bed-day accrual — and cross-row ordering between web- and worker-written records.
- **Only manifests when the tenant timezone ≠ UTC.** Praxis Lindenhof is Europe/Zurich.

---

### HIGH

#### `P1-H1` — `reception` cannot register a patient or issue a portal invite, yet the app advertises both

- **Role:** `reception`
- **Page/route:** `GET /patients/register`, `POST /patients`, `POST /portal/invitations`
- **What I did:** Clicked "Register patient" from the dashboard hero, from the Quick-actions panel,
  and from the `/patients` index header. Then forged `POST /patients` and `POST /portal/invitations`
  directly from the authenticated session.
- **What happened:** All three UI entry points land on **HTTP 403** "You don't have access to this
  area". Both forged POSTs return `403 {"message":"This action is unauthorized."}`.
- **What should have happened:** Registering a walk-in patient and issuing a portal invite are the
  canonical front-desk tasks. The task brief lists both as reception surfaces.
- **Cause:** `Modules/Patients/src/Http/Controllers/PatientRegistrationController.php:19,29,44` and
  `Modules/Patients/src/Http/Controllers/PortalInvitationController.php:15` all gate on
  `patient.edit`. The `reception` template
  (`Modules/Platform/src/Services/RbacProvisioner.php` → `'reception' => ['patient.view',
  'appointment.manage', 'comms.manage']`) **does not include `patient.edit`**.
- **Proof it is a template asymmetry, not a design decision:** `admissions_clerk` — documented as
  "reception's set + patient.edit" — gets **200** on the identical route. The server guard is
  correct and consistent; the reception template is the outlier.
- **Note:** this is an over-restriction *and* a dead-end — the UI offers the action in three places
  to a role that can never complete it. Whether reception *should* hold `patient.edit` is a product
  decision; either the permission or the three CTAs should change.

#### `P1-H2` — Patient registration **fails silently** unless four unmarked fields are filled

- **Role:** `admissions_clerk` (the role that *can* register)
- **Page/route:** `/patients/register` → `POST /patients`
- **What I did:** Filled only the fields the wizard marks `required` (first name, last name, DOB,
  sex), clicked through to Review, pressed **Create patient**.
- **What happened:** The page stayed on Step 4. **No error appeared anywhere** — no field error, no
  banner, no toast. `POST /patients` → `302` back to the form. No patient created. The user has no
  way to know why. I repeated it after filling Payer + Member ID and it *still* failed silently.
- **What should have happened:** Either the submit succeeds, or the blocking fields are marked
  required and their errors are shown.
- **Cause (two defects compounding):**
  1. `resources/js/pages/Patients/Register.vue:44-45` **always** submits an `identifiers` row
     (`{system:'', value:''}`) and a `coverages` row (`{payer_name:'', member_id:'', …}`). Server
     rules `identifiers.*.system|value` and `coverages.*.payer_name|member_id` are
     `required_with:*` (`PatientRegistrationController.php:99-105`), and `ConvertEmptyStringsToNull`
     turns `''` into `null` — so the always-present rows always fail.
  2. `Register.vue:166-171` binds `:error` **only** on the Identity-step inputs. The Step-3 inputs
     (`identifier_system`, `identifier_value`, `payer_name`, `member_id`) have **no `:error`
     binding**, so their validation messages are never rendered.
- **Proven both directions:** filling **all four** Step-3 fields → the submit succeeded and
  redirected to `/patients/01m1q261v4c4ax0f72bx9apyk6`. Leaving any blank → silent failure. A
  direct `POST /patients` with `identifiers: []` returned **200** and created MRN-000009, confirming
  the API is fine and the wizard payload is the fault.
- **Impact:** the default path — a self-pay patient with no external identifier — cannot be
  registered through the UI, and the clerk gets no explanation.

#### `P1-H3` — The slot finder offers **times in the past**, and the system books them

> ✅ **FIXED — QA-FIX.1b, commit `f6b619a` (D-194).** Two independent layers, because they answer
> different questions. **The finder** (`AvailableSlotFinder`) now skips any slot whose start has
> already passed in the **branch's** clock, so every consumer inherits it — day-board, staff
> reschedule, portal self-booking and the public form. **The booking funnel** (`createBooking`, which
> both `book()` and `bookOnline()` reach) refuses a past start anyway, because the finder not offering
> something is only a UI fact: a stale tab or a forged POST arrives at the service directly (D-183).
> **The boundary is strictly "has already started"** — SCHED.P2 established there is no min-notice
> setting, so a notice window would be invented policy (D-170).
> **Backdated RECORDING is preserved:** `$allowPastStart` is a call-site constant, never read from a
> request, so nothing a client sends can relax it; `bookOnline()` defaults to refusing while `book()`
> stays permissive because it is also the repo's historical-recording path (both demo seeders build a
> real elapsed week through it). The four interactive callers pass the strict value.
> **Three controllers were letting the refusal escape as HTTP 500** — day-board, portal and public —
> and now redirect back with a field error; on the public form that 500 would have met an anonymous
> visitor.
> **Re-measured in the browser, same panel as below:** for the elapsed working day `2026-09-04` the
> finder returned **0 slots** (was: 08:00 "soonest"), while the future Tuesday `2026-09-08` returned
> **19** — the positive control that proves the finder is filtering, not simply broken. A forged
> past quick-book POST returned **302 with "has already passed"**, not a 500, and created **0**
> appointments, while the same POST at a future time created 1.
> Guarded by `tests/Feature/Scheduling/PastSlotGuardTest.php` (10, mutation-checked: neutralising the
> finder filter turns 2 red, neutralising the booking guard turns 5 — the layers fail independently).

- **Role:** `reception`
- **Page/route:** `/scheduling/appointments/{id}` → reschedule panel; also the day-board Quick-book
  modal
- **What I did:** At **20:21 UTC / 22:21 local**, opened Reschedule on a booked appointment. The
  panel offered `09/04/2026 · 08:00` labelled **"soonest"**, plus 08:30, 09:00, 09:30 — all earlier
  the same day. Selected 08:00, entered a reason, confirmed.
- **What happened:** The reschedule **succeeded**. A new appointment was created with
  `starts_at = 2026-09-04 08:00:00`, status `booked`, **742 minutes (12 h 22 m) in the past**. No
  warning, no confirmation step, no server refusal. The old appointment was correctly marked
  `rescheduled`, so the state machine itself behaved.
- **What should have happened:** past times should not be offered, and a booking into the past
  should be refused.
- **Cause:** `Modules/Scheduling/src/Services/AvailableSlotFinder.php:45,62,65` — the cursor starts
  at the branch opening time (`$date->startOfDay()` plus the opening window) and walks to end-of-day
  with **no comparison against `now()` anywhere**. `BookingService` has **no past-time guard**
  either (a grep for `isPast` / `now()` in that service returns nothing).
- **Second surface browser-verified:** the day-board **Quick-book** modal offers `08:00–09:00`,
  `08:30–09:30`, … for today at 22:2x local — the same past slots.
- **Wider blast radius (identified by code, not browser-verified here):** the same finder is called
  by `DayBoardController`, `DayBoardActionController::slots`, `PortalAppointmentController::slots`
  (**patient self-booking**) and `PublicBookingController` (**public online booking**). A patient
  could plausibly self-book into a slot earlier today. Those three should be verified in their own
  phases before the fix is scoped.

---

### MEDIUM

#### `P1-M1` — Two surfaces answer "how many are checked in?" differently; desk arrivals are invisible to reporting

- **Role:** `reception` · **Route:** `/scheduling/day-board`, `MetricsService::checkedInCount`
- **What I did:** Pressed **Arrive** on a booked appointment. The day-board "Checked in" tile went
  0 → 1. Then read the row.
- **What happened:** `status = arrived` but **`checked_in_at` stayed NULL**.
- **Cause:** the tile is `Modules/Scheduling/src/Http/Controllers/DayBoardController.php:141` →
  `$count(Appointment::STATUS_ARRIVED)`. `MetricsService::checkedInCount`
  (`Modules/Reporting/src/Services/MetricsService.php:136-148`) instead filters
  `whereNotNull('checked_in_at')`. The staff Arrive path never writes that column; only the
  kiosk/portal path does (`Modules/FrontDesk/src/Services/CheckInService.php:83-84`).
- **Consequences:** (a) reporting **under-counts** — its own docblock (`MetricsService.php:133`)
  claims the metric covers "self check-in **or reception**", which is untrue; (b)
  `waiting_minutes` (`DayBoardController.php:271`) derives from `checked_in_at`, so a desk-arrived
  patient shows **no waiting time** — directly degrading the reception workflow the field exists
  for; (c) "checked in" means two different things in one product.
- **Not a crash:** `CheckInService.php:78-80` correctly skips the status hop for an already-arrived
  appointment, so a desk-arrival followed by a kiosk check-in is handled safely.

#### `P1-M2` — Controls offered to roles that cannot use them; one strands the user

- **Role:** `reception` (and `admissions_clerk` for the first)
- **(a) "Nursing dispatch" quick action** — `/app` offers it to both roles; neither holds
  `dispatch.manage`; clicking gives 403. Cause: `resources/js/pages/App/Landing.vue:171-179` —
  three hardcoded `<Link>`s with **no permission gating**, unlike `AppLayout`'s nav which is gated
  through `NAV_PERMISSIONS`.
- **(b) Day-board "Document" button** — shown on every appointment card to `reception`, which lacks
  `encounter.manage`. Clicking navigates to `/scheduling/day-board/open-encounter` and renders a
  403 page, **losing the day-board's date, branch and filter context**; the user must return via
  the dashboard. Cause: `resources/js/Components/ScheduleGrid.vue:172` gates the button only on
  `v-if="appointment.patient_id"` — never on a permission — while its sibling lifecycle buttons use
  `offers(appointment, …)`.
- **Security is correct in both cases**: the refusal is server-enforced
  (`Modules/Clinical/src/Services/EncounterService.php:39` → `$this->authorize(...)`). This is a UX
  finding, not a permission hole.
- **Structural note:** `OpenEncounterFromAppointmentController` has **no explicit
  `Gate::authorize`**, relying on the service's internal check — a divergence from the
  gate-at-the-top pattern every sibling controller follows. Defence-in-depth works here; the
  inconsistency is worth aligning.

#### `P1-M3` — The same date renders in three different formats, one of them US month/day in a de-CH tenant

- **Role:** `reception` · **Routes:** `/patients`, `/patients/{id}`, `/scheduling/appointments/{id}`
- **Observed** for one patient's date of birth:

  | Surface | Rendered | Correct for a `de` tenant? |
  |---|---|---|
  | `/patients` index | `12.03.1954` | ✅ |
  | Inbox context pane | `12.03.1954` | ✅ |
  | Patient 360 | `1954-03-12` | ✗ raw ISO |
  | Appointment detail | `09/09/1986` | ✗ **US M/D/Y** |

- **Cause:** `resources/js/lib/date.ts:21` — `formatDateOnly(value, locale = 'en', …)` **defaults to
  `'en'`**. Four call sites omit the locale argument and so render US format regardless of tenant:
  `resources/js/pages/Scheduling/AppointmentDetail.vue:146` and `:383`,
  `resources/js/pages/Dental/Odontogram.vue:88`, `resources/js/pages/Dental/PerioChart.vue:126`.
  Every Billing / Patients-index / Dental-index / Inbox call site correctly passes `locale.value`.
  Separately, `resources/js/pages/Patients/Show.vue:99` prints the raw ISO string with **no
  formatter at all**.
- **Why it matters here:** on the appointment detail an appointment on **4 September** displays as
  `09/04/2026`, which a Swiss receptionist reads as **9 April**. The date is genuinely ambiguous on
  the screen used to confirm appointments with patients.

#### `P1-M4` — No navigation at all below 768 px

- **Role:** `reception` · **Route:** all authenticated pages · **Viewport:** 375×844
- **What happened:** the primary nav is `class="hidden … md:flex"` — `display:none` below the `md`
  breakpoint — and there is **no hamburger or menu replacement** (`hamburgerCount: 0`). "Search" and
  "Notifications" are hidden too. The only working header control is **Sign out**.
- **Consequence:** on a phone or narrow tablet a receptionist cannot move between Dashboard,
  Patients, Scheduling and Inbox at all; they can only follow in-page links. Reception is a likely
  tablet role.
- **Good, for contrast:** there is no page-level horizontal overflow at 375 px, and the wide
  schedule grid correctly scrolls inside its own `min-w-[780px]` container.

#### `P1-M5` — The Quick-book modal cannot be dismissed by any mouse action

- **Role:** `reception` · **Route:** `/scheduling/day-board`
- **What I did:** Opened Quick-book, then tried every dismissal in turn.
- **What happened:** **✕ Close is unclickable** — `document.elementFromPoint` at the button's centre
  returns the sticky `<header>`, at **every scroll position** (verified at `scrollY=1651` and
  `scrollY=0`). The ✕ sits at viewport y≈40–75 and the sticky header occupies y=0–80; both are
  viewport-anchored, so they always overlap. **Escape does nothing.** **Backdrop click does
  nothing.** Keyboard (focus the ✕, press Enter) **does** close it.
- **Not a hard trap:** the header paints above the modal, so the nav links stay clickable and the
  user can leave the page — losing anything typed.
- **Also on this dialog:** focus is **not moved into it** on open (`document.activeElement` remains
  the Quick-book trigger outside the overlay), and it has `role="dialog" aria-modal="true"` with
  **no `aria-labelledby`** — so it has no accessible name.

#### `P1-M6` — A staff reschedule is recorded as "booked online"

- **Role:** `reception` · **Route:** `POST /scheduling/appointments/{id}/reschedule`
- **What happened:** the appointment created via the reschedule panel was stored with
  `source = online` **and** `booked_by = 45` (Nadia Steiner, a staff user). The day-board tile
  "Booked online" counted it — under the caption *"Recorded as having come from the public booking
  form."* It did not; a receptionist created it.
- **Cause:** `Modules/Scheduling/src/Services/AppointmentService.php:210` — `reschedule()` passes
  `$locked->source` straight into `BookingService::book()`, inheriting the original appointment's
  provenance instead of recording the new booking's actual origin.
- **Class:** a displayed figure asserting something untrue about how a record came to exist — the
  D-179 family.

#### `P1-M7` — No link to password recovery from the login page

- **Role:** all staff · **Route:** `/login`
- **What happened:** `/forgot-password` renders (HTTP 200 — AUTH-SEC.2 bound the views), but
  `resources/js/pages/Auth/Login.vue` contains **zero** occurrences of "forgot". There is no link to
  it from the login screen, so a locked-out user must already know the URL.
- **Why it matters:** AUTH-SEC.2 / D-159 exists precisely because *"a locked-out user had no
  self-service recovery"*. The route was fixed; the entry point was never added, so the user-facing
  outcome is still "no discoverable recovery".

#### `P1-M8` — Redis unavailable ⇒ **HTTP 500 on login**, not a handled degradation

- **Role:** all · **Route:** `POST /login`
- **What I did:** attempted the first login with Redis down (its normal state on this box).
- **What happened:** HTTP 500. Stack: `ThrottleRequests` → `RateLimiter::tooManyAttempts` →
  `RedisStore::get` → Predis connection refused.
- **Consequence:** a Redis outage in production is a **total login outage presented as a 500**, not
  a graceful failure or a maintenance page. The deploy runbook specifies Redis for cache/queue, so
  this is a real operational dependency worth an explicit decision (fail-open to a local limiter, or
  a handled error page).
- **Environment-conditional**, and the reason Memurai was started to continue the audit.

---

### LOW

- **`P1-L1` — Waitlist "Fill a freed slot" lists slots that are not free.** The picker offers every
  appointment on the board, including one that is `booked` and one whose patient has already
  `arrived`. Only cancelled / no-show / rescheduled are genuinely freed. Cause:
  `resources/js/pages/Scheduling/DayBoard.vue:341` — `v-for="appt in appointments"` with no status
  filter. An offer on an occupied slot would fail later at `assertNoOverlap`, wasting an offer and
  confusing a patient. *(The panel's own copy is otherwise exemplary — see Positives.)*
- **`P1-L2` — Inbox timestamps are raw UTC; appointment history is tenant-local.** A message stored
  `20:05:17` UTC displays as `20:05` in the inbox, while the appointment history renders the same
  clock as `22:21` local. Two staff surfaces, two timezones, two hours apart. Related to the known
  deferred "full per-widget timezone display" item, and entangled with `P1-C1`.
- **`P1-L3` — The allergy block renders twice on Patient 360** — once in the dark hero tile
  (`text-white/75`) and again in the allergy banner (`text-ink-muted`). Duplicate presence on one
  screen. *(Both render severity as a recorded fact with constant styling — D-169 holds, see
  Positives.)*
- **`P1-L4` — Ungrammatical action-panel heading.** Cancelling shows **"REASON FOR CANCEL
  APPOINTMENT"** — the panel interpolates the button label verbatim. Reads as broken copy on a
  patient-facing workflow. The reason input also has no placeholder and no label of its own.
- **`P1-L5` — `admissions_clerk` has no route to her own core function.** She holds
  `admission.manage` and `/hospital/wards` returns **200**, but there is **no Hospital/Ward nav
  entry** — she must know the URL. Relatedly, the day-board empty state tells her rooms are "set up
  under **Admin → Branches**", an area she gets 403 on. *(Correctly rendered as plain text, not an
  ungated link.)*
- **`P1-L6` — "Sex" and "Gender" are free-text inputs on registration.** Server validation is only
  `['required','string','max:50']`, so `not-a-valid-sex` was accepted through to the Review step. A
  constrained control would prevent inconsistent values (`f` / `female` / `weiblich`) accumulating
  in a field used for clinical display.

---

### Positives worth recording (fence and guard checks that HELD)

These were actively probed, not assumed:

- **RBAC has no holes for this group.** 13 GET routes and 3 forged POSTs (`/portal/invitations`,
  `/patients`, `/admin/branches`) from an authenticated reception session: every one correctly
  **403**, server-side. Reads correctly refused across billing, admin, settings, reporting, import,
  dental, pharmacy and ED.
- **D-176 (unbacked presence):** the fabricated `⚑ Flag` chip is **absent** from Patient 360.
- **D-169 (severity ramp in styling):** allergy severity renders as a recorded word with
  **constant** classes (`text-white/75`, `text-ink-muted`) — no `:class`/`:style` keyed to the value.
- **D-166 (closed stat tiles):** day-board and inbox tiles carry real counts with honest captions —
  *"A recorded pair of statuses, not a judgment about waiting"*, *"Every open thread in this
  practice, not just the page below"*.
- **D-179 (asserting an action never taken):** the waitlist panel states plainly that an offer
  *"holds the patient's place in the queue — not the slot"*, and that a clash will refuse the
  acceptance. Appointment history says *"recorded by the system"*.
- **D-156 (legal transitions):** the appointment action row offers exactly the machine's legal set —
  a `booked` appointment offers **Confirm**, never "Mark arrived".
- **D-157 (real slot finder):** reschedule merges the real finder's answers and re-checks
  server-side at confirm; the copy says so and it is true. *(Its past-slot flaw is `P1-H3`, a
  separate defect in the finder itself.)*
- **Honest RBAC-aware absence:** the inbox patient pane prints *"Your role does not include this."*
  for allergies and open balance rather than rendering a misleading blank.
- **403 page quality:** styled, in-shell, with a working "Back to dashboard" link.
- **Empty states:** *"No bookable resources yet"* with actionable guidance; *"No offers yet."*
- **Responsive containment:** no page-level horizontal overflow at 375 px; the wide grid scrolls in
  its own container.
- **`CheckInService`** correctly skips the status hop for an already-arrived appointment.

---

### Not tested, and why

- **Soft-suspended branch.** No such fixture exists (all four tenants have one active branch with
  online booking on) and reception cannot create one (`admin.manage`). Reception's view of a
  suspended branch is unverified. **Recommend the demo seeders grow a suspended branch**, since
  BRANCH.P1 built soft-suspend specifically to affect booking surfaces.
- **Kiosk check-in surface.** `/check-in` needs a kiosk device token, issued under `/admin/kiosks`
  (`admin.manage`). Out of reach for both audited roles. It matters for `P1-M1` and should be driven
  in the admin phase.
- **Waitlist offer → accept/decline cycle.** Only one waitlist entry exists and the freed-slot
  picker (`P1-L1`) made a clean offer awkward to stage. The panel was driven; a full
  offer → accept → booking round trip was not completed.
- **Public booking + portal self-booking against `P1-H3`.** Identified by code as sharing the
  unguarded finder; not driven here because both are outside the reception role. Should be verified
  in the portal phase before the fix is scoped.
- **Session expiry mid-form.** Not simulated; would need session manipulation mid-wizard.
- **Screen-reader semantics beyond the modal.** Only the Quick-book dialog's focus/label behaviour
  was inspected; no full assistive-technology pass.
- **Performance** — deliberately out of scope (see Environment).

---

## Phase 2 — Clinician (doctor / dentist)

**Date:** 2026-09-05 · **HEAD at audit:** `f6b619a` (QA-FIX.1b) · **CI at audit:** check-run `check`
→ `completed / success`, read from the GitHub check-run API before starting · tree clean apart from
untracked `docs/marketing-site/`.

### Roles covered

`RbacProvisioner::ROLE_TEMPLATES` defines **26** role templates. The **clinician (physician) group**
is these seven:

| Template | Permissions | Driven in a browser |
|---|---|---|
| `doctor` | 11 | ✅ **twice** — medical practice AND dental practice |
| `ed_physician` | 7 | ✅ specialist variant |
| `hospitalist` | 11 | ❌ permissions compared only |
| `surgeon` | 7 | ❌ permissions compared only |
| `anesthetist` | 5 | ❌ permissions compared only |
| `pathologist` | 7 | ❌ permissions compared only |
| `radiologist` | 7 | ❌ permissions compared only |

**THE STRUCTURAL FACT THAT SHAPED THIS PHASE: there is no `dentist` role template.** The gate asked
for "doctor vs dentist". They are **the same template** — the dental practice's clinician
(`luca.ferrari@zahnarztpraxis-morgenstern.test`) holds `doctor`, exactly as the medical practice's
(`matthias.brunner@praxis-lindenhof.test`) does. So both were driven separately, in their own
tenants, and the comparison below is a *vertical* comparison, not a template one.

**Excluded, with reasons.** Nursing (`nurse`, `ward_nurse`, `charge_nurse`, `scrub_nurse`,
`triage_nurse`, `ed_charge_nurse`) — a separate role group with its own planned phase. Pharmacy
(`pharmacist`, `pharmacy_technician`), technical lab/imaging (`lab_tech`, `phlebotomist`,
`radiographer`), front-desk/ops (`reception`, `coordinator`, `admissions_clerk`,
`surgical_scheduler`, `bed_manager`, `him_records`) and admin/finance (`org_admin`, `billing`) —
none is a clinician; several have their own phases already scheduled.

### Environment

- **Playwright MCP throughout.** Every page below was driven in a real browser. **No restart was
  needed** — the local install from Phase 1 (`~/.claude/mcp-local/`, configs pointing at
  `node .../cli.js`) started first time. Login is real: password + TOTP (fixed factory secret) for
  each of the three accounts.
- **Re-seeded before starting**: `migrate:fresh --seed` + all four demo seeders, 0 exceptions.
- **Redis: UP, stated honestly** — a Memurai process (dev licence) started manually in an earlier
  session, not a restored service. `cache=redis queue=redis session=database`.
- **PERFORMANCE IS OUT OF SCOPE** (MariaDB + a dev box); deferred to staging.
- **Browser environment, and why it matters below:** `Intl` timezone `America/Los_Angeles`,
  locale `en-US`, while the tenant is `Europe/Zurich` and `<html lang="de">`. That three-way split
  is what made `P2-H3` visible; it is stated so the reader can separate my machine from the defect.

**Data verified BY QUERY before driving** (per tenant): `praxis-lindenhof` 15 patients · 6 encounters
· 7 notes for the index patient (6 current + **1 amendment chain**) · 3 allergies at **severe /
moderate / mild** (the D-169 control set) · 15 vitals · 8 problems · 6 medications · 1 referral ·
3 recalls · 2 pending agent drafts. `zahnarztpraxis-morgenstern` **10 charted teeth · 2 perio exams
· 24 perio measurements · 2 treatment plans · 1 dental image**. `klinik-bergblick` 5 orderable items
· 7 orders · 4 lab orders · 3 imaging studies.

**One data gap, stated:** **no appointment exists for today.** Today is Saturday 2026-09-05 and the
seeders build a weekday week (2026-08-31 → 2026-09-04, all eight statuses present). I therefore drove
the day-board at `?date=2026-09-04` for appointment actions and used today only to check the empty
state. I did not create an appointment for today, because the finder correctly offers no Saturday
slots (availability is weekdays 1–5) and manufacturing one would have meant changing seeded data.

### Surfaces driven (explicit, so gaps are visible)

| Surface | doctor (medical) | doctor (dental) | ed_physician |
|---|---|---|---|
| `/app` landing | ✅ | ✅ | ✅ |
| `/patients` directory | ✅ | ✅ | ✅ (403 on register) |
| Patient 360 `/patients/{id}` | ✅ | — | — |
| Clinical chart — **all 8 tabs** | ✅ | — | ✅ |
| Note editor: draft → **sign** → **amend** | ✅ **end to end, twice** | — | — |
| Day-board + appointment actions | ✅ | ✅ (sweep) | 403 (correct) |
| Odontogram (chart a tooth) | — | ✅ **recorded a condition** | — |
| Perio charting | — | ✅ | — |
| Diagnoses | — | ✅ | — |
| Treatment plan | — | ✅ | — |
| Imaging library | — | ✅ | — |
| Dental fee schedule | 403 | **403** | — |
| Orders (place an order) | ✅ (empty catalogue) | — | ✅ **placed 2 orders** |
| Lab results review | ✅ | ✅ (sweep) | ✅ |
| Recalls (**completed one**) | ✅ | ✅ (sweep) | ✅ |
| Approval queue | **403** | **403** | **403** |
| Inbox | **403** | **403** | **403** |
| Ward board | ✅ | ✅ (sweep) | ✅ |
| ED board | 403 | 403 | ✅ |
| Snippets | ✅ (sweep) | ✅ (sweep) | ✅ (sweep) |
| Telehealth | ✅ (sweep) | ✅ (sweep) | ✅ (sweep) |
| Narrow viewport 375×844 | ✅ | — | — |
| Forged POSTs (RBAC) | ✅ 4 endpoints | — | — |
| Session expiry mid-form | — | — | ✅ |

**Route sweeps** were run in-session over **47 GET routes** for the doctor and **24** for the dentist
(byte-identical results) and **16** for the ED physician.

### CRITICAL

#### `P2-C1` — A signed clinical note is **attributed to a clinician who neither wrote nor signed it**

> ✅ **FIXED — QA-FIX.2a, commit `e8a7a48` (D-195, D-196, D-197).** The cause was a single argument:
> `OpenEncounterFromAppointmentController` resolved the appointment's practitioner once and passed it
> to **both** `EncounterService::open()` (right) and `ClinicalNoteService::saveDraft()` (wrong, where
> it becomes `author_id`). **Two different questions now get two answers:** *whose visit is this* is
> the ENCOUNTER, and it legitimately stays the booked clinician — **deliberately unchanged**, with a
> test asserting it still equals the appointment's practitioner so the fix is provably surgical;
> *who wrote this down* is the NOTE, and it is now the authenticated user, resolved through the new
> `StaffProfile::forUser()`, which **returns null rather than guessing** — a caller that cannot
> identify the actor refuses to write the note.
> **The same principle fixed the amendment path**, which had been inheriting the superseded version's
> author, so a correction written by Dr. B was recorded as Dr. A's work.
> **The signature now names the SIGNATORY.** The lock line rendered `author_name` under a "Signed ·"
> label; it renders `signed_by_name` now. Author and signatory can legitimately differ — the seeded
> radiology reports are authored by Dr. Lang and signed by Dr. Berg — so when they differ the view
> names **both, distinctly** ("Written by X · Signed by Y"), rather than letting one stand in for the
> other.
> **Two features were silently repaired:** `UnsignedNotesWorklist` ("my unsigned notes") had been
> filing Brunner's notes in Keller's worklist, and `ClinicalSummaryInsertController` looks for "the
> draft authored by the current clinician" and so could **never** match a Document-created note.
> **Re-measured in the browser, same steps as below:** as Brunner, Document on a Dr. Keller
> appointment → the draft was attributed to **Dr. med. Matthias Brunner** (was: Sofia Keller), and
> after signing the page reads **"Signed · Dr. med. Matthias Brunner · 2026-09-05 20:25:43"**.
> Stored: `author_id` → Brunner, `signed_by` → 3 (Brunner), and the **encounter's practitioner is
> still Dr. med. Sofia Keller**.
> **Historical rows are deliberately NOT rewritten (D-197)** — `ClinicalNote::updating` refuses any
> change to a signed note, and the true author is recoverable only by reconstruction from the audit
> chain. See the open decision above.
> Guarded by `tests/Feature/Clinical/NoteAuthorshipTest.php` (8, mutation-checked: restoring the old
> authorship turns 4 red, restoring the old amendment inheritance reddens the amendment test alone).
> Every fixture makes the actor and the appointment's practitioner **different people and asserts it**
> — the pre-existing `ClinicalUiTest` fixtures made them the same person, which is why this survived.

- **Role:** `doctor` · **Route:** `/scheduling/day-board` → "Document" → `/clinical/notes/{id}/edit`
- **What I did:** Logged in as **Dr. med. Matthias Brunner** (`users.id = 3`). Opened the day-board
  at `2026-09-04`. The 09:30 appointment for Beatrice Weber is assigned to the practitioner resource
  **"Dr. Keller"** (`appointment_resources` → `resources.id 01m1s7mmq06ryfx9ege04z9xzb` →
  `staff_profile_id 01m1s7me3j2cjwy0z15rej306t` = **Dr. med. Sofia Keller**, `user_id = 4`). Clicked
  **Document**. Typed into Subjective: *"QA Phase 2 authorship probe: this text was typed by Dr.
  Matthias Brunner while logged in as Brunner."* Clicked **Sign note**, typed `SIGN`, confirmed
  **Sign permanently**.
- **What happened:** The note editor showed **"Dr. med. Sofia Keller"** as the version author from
  the moment it opened, and after signing the page states:

  > **Signed · Dr. med. Sofia Keller · 2026-09-05 17:13:32**

  In the database: `author_id = 01m1s7me3j2cjwy0z15rej306t` → **Sofia Keller**, while
  `signed_by = 3` → **Matthias Brunner**, and the audit row `note.signed` correctly carries
  `actor_id = 3`. **Brunner wrote every word and pressed every button; the clinical record names
  Keller.**
- **What should have happened:** the note should be authored to the clinician who wrote it, or —
  if attributing to the appointment's practitioner is deliberate — the screen must not present that
  person as the *signatory*.
- **Cause:** `ClinicalNote.author_id` is populated from the **encounter's practitioner**, which
  "Document" derives from the appointment's practitioner resource, not from the authenticated user.
  The encounter created by that click carries `practitioner_id` = the same Keller profile.
- **Why this is CRITICAL:** it is wrong clinical data in the medico-legal record, and a **D-179
  breach** (an asserted action never taken — the UI asserts a signature Keller never made). The truth
  survives only in the audit chain, which is not what a clinician reads. It is also **not** house
  style: `tooth_records.charted_by` and `orders.ordered_by` both correctly record the acting user
  (verified — see the guards section). The note path is the outlier.
- **Sub-finding (STRUCTURE):** `author_id` and `signed_by` sit on the same row but reference
  **different identity tables** — `author_id` → `staff_profiles.id` (ULID), `signed_by` → `users.id`
  (integer). `tooth_records.charted_by` and `orders.ordered_by` also use `users.id`. Three "who"
  columns across the clinical tables, two identity namespaces, no agreement.

### HIGH

#### `P2-H1` — Opening a note silently marks the patient **arrived** and the appointment **in progress**

> ✅ **FIXED — QA-FIX.2b, commit `706ed77` (D-198).** `EncounterService::moveAppointmentToInProgress()`
> walked every intermediate state so an encounter could always be opened — each hop a legal edge, so
> nothing was bypassed. **The defect was in the meaning, not the mechanics:** the code answered "how do
> I get this appointment to in_progress?" when the question was "has anyone actually said this patient
> is here?"
> **The chosen design is the gate's option (c).** `arrived → in_progress` is still composed — the one
> honest case, since the patient is already recorded present, so a clinician opening their note *is*
> the visit starting. From `booked` or `confirmed` **nothing is transitioned**: the encounter and the
> note are still created, so documentation is never blocked, and the appointment keeps the status it
> earned.
> **No confirmation prompt was invented.** A flag no surface sends would be an unbacked presence
> (D-176), and the honest control already sits **directly beside Document on the same row** — the
> day-board's **Arrive** button. **The D-156 compose is untouched** (`DayBoardActionController:35-38`
> still walks confirm → arrive) and a test pins it: that compose is legitimate *because a human pressed
> a button whose meaning is the arrival*.
> **Re-measured in the browser, same steps as below.** Document on a **booked** appointment: the audit
> now shows **only `encounter.opened`** — zero attendance transitions, against three before — the
> status stays **`booked`**, `checked_in_at` and `check_in_source` stay NULL, and the note is still
> created. Positive control on a clean appointment: **Arrive → Document** still reaches
> **`in_progress`**, so the fix is a guard, not a removal.
> **`checked_in_at` is untouched and always was** — only a real check-in writes it
> (`FrontDesk\CheckInService:83`). **`P1-M1` itself remains OPEN:** the day-board's own Arrive button
> still sets `status = arrived` without writing `checked_in_at`, so desk arrivals stay invisible to
> `MetricsService::checkedInCount`. That is a front-desk-surface decision and this gate deliberately
> did not widen into it.
> Guarded by `tests/Feature/Clinical/DocumentationAttendanceTest.php` (7), every test starting from a
> BOOKED appointment so the old code *succeeds* without the fix (D-182). Mutation-checked: restoring
> the compose reddens the 3 guard tests while the 4 controls stay green.

- **Role:** `doctor` · **Route:** `/scheduling/day-board` → "Document"
- **What I did:** Clicked **Document** once, to write a note. Nothing else.
- **What happened:** the audit shows three status transitions fired at the same instant
  (17:11:12, `actor_id = 3`): **`appointment.confirmed` → `appointment.arrived` →
  `appointment.in_progress`**. The appointment moved `booked` → `in_progress`. **`checked_in_at` is
  NULL and `check_in_source` is NULL** — the record asserts the patient arrived while holding no
  evidence that they did. No confirmation was requested, no warning shown, no undo offered.
- **What should have happened:** writing a note is documentation, not attendance. Either the
  transition is confirmed by the user, or it is not performed.
- **Why HIGH:** attendance drives reporting and billing, and a no-show documented by a clinician
  opening the wrong row is now indistinguishable from a real arrival. **D-179.**
- **CROSS-PHASE:** this is Phase 1's `P1-M1` (status `arrived` with `checked_in_at` NULL) recurring
  from a **different entry point and a different role** — evidence the pair is systemic, not local.

#### `P2-H2` — A clinician's landing page is a wall of dead ends; for the ED physician **every link 403s**

- **Roles:** `ed_physician`, `doctor`, dentist · **Route:** `/app`
- **What I did:** enumerated every `<a href>` inside `<main>` on the landing page and fetched each
  one in-session.
- **What happened:** for **`ed_physician`** the landing page contains exactly **four** unique links
  and **all four return 403**:

  | Link | Status |
  |---|---|
  | `/patients/register` (hero CTA **and** a quick action) | **403** |
  | `/scheduling/day-board` (hero CTA, "Today's schedule" card, and its footer link) | **403** |
  | `/nursing/dispatch` (quick action) | **403** |
  | `/comms/inbox` (quick action) | **403** |

  For **`doctor` and the dentist**, two of the three quick actions — "Nursing dispatch" and
  "Unified inbox" — are 403.
- **What should have happened:** the landing page should offer what the role can do.
- **Cause:** the **top nav is permission-aware and correct** (it shrinks to Dashboard / Patients /
  Orders / Telehealth for the ED physician, hiding Scheduling and Dental), but the **page body is
  not** — the hero CTAs, the schedule card and the quick-action list are rendered unconditionally.
- **Why HIGH:** the primary landing surface for a clinical role is entirely non-functional, and the
  role cannot tell which links are real until it clicks them.
- **CROSS-PHASE:** Phase 1's `P1-M2` / `P1-H1` "ungated control in front of a correctly-gated
  server", now at its worst.

#### `P2-H3` — Clinical timestamps are shown in **two wrong clocks**, never the practice's

- **Roles:** `doctor`, dentist · **Routes:** the chart, the note editor, dental tooth history,
  imaging, order results
- **What I did:** the tenant setting is `timezone = Europe/Zurich` (UTC+2 on this date) and storage
  is UTC since `QA-FIX.1a`. I created a note amendment at a known wall clock and read back what the
  screen said.
- **What happened:** at the single instant `2026-09-05T17:04:51Z` the note editor displayed **three
  different clocks and none of them was Zurich**:

  | Shown as | Value | What it actually is |
  |---|---|---|
  | version list / "Signed ·" | `2026-09-05 17:03:53` | raw **UTC** |
  | "Draft saved · " | `10:04 AM` | the **viewer's machine** zone (America/Los_Angeles), US 12-hour |
  | *(correct)* | `19:03` | tenant **Europe/Zurich** |

  I signed a note at **19:05:50 Zurich**; the record reads `2026-09-05 17:05:50`. The **dental**
  surfaces differ again: tooth history and imaging render `9/5/2026, 9:51:12 AM` — the **browser's**
  timezone in US format. Lab results on the chart render raw UTC (`2026-09-05 16:51:31`).
  Meanwhile appointment times are correct (`starts_at 09:30` displays `09:30`) because `starts_at`
  is a **naive local wall clock**, not an instant.
- **What should have happened:** one declared display zone — the practice's — everywhere.
- **Cause:** storage is right; the **display boundary is missing on clinical surfaces**. Some
  components print the stored UTC verbatim; others hand the instant to `toLocaleString()`, which
  resolves to whatever machine the clinician is sitting at. `QA-FIX.1a` corrected storage and
  thereby *revealed* this: while writes were tenant-local, printing raw looked right.
- **Why HIGH not CRITICAL:** the stored values are correct and internally consistent, and the audit
  chain is truthful; the defect is presentational. It is HIGH rather than MEDIUM because a
  medico-legal signature time is off by the tenant offset, and because the dental variant differs
  **per viewer**, so two clinicians reading the same record see different times.
- **CROSS-PHASE:** Phase 1's `P1-L2` (inbox raw UTC vs appointment history local) generalised — the
  divergence is not two screens, it is two *mechanisms*, and it now touches the clinical record.

#### `P2-H4` — The clinical chart cannot record anything the clinician is permitted to record

- **Role:** `doctor` · **Route:** `/clinical/chart/{patient}`
- **What I did:** opened all eight tabs and enumerated every visible control on each.
- **What happened:**

  | Tab | Controls offered |
  |---|---|
  | Timeline | search box only — encounter rows are plain `<div>`s, `cursor:auto`, no link |
  | Notes | `Open →` on existing notes. **No "new note".** |
  | Problems | none |
  | Vitals | none |
  | Medications | none — **no prescribe / record** |
  | Documents | `Download` only — **no upload** |
  | Orders | `<select>` + reason + Routine/Urgent + **Place order** ← the only create affordance |
  | Care | none |

  The `doctor` template holds **`note.write`, `note.sign`, `medication.prescribe`,
  `encounter.manage`, `patient.edit`** — the chart exposes an affordance for **none** of them.
  Notes turn out to be creatable **only** from a day-board appointment's "Document" button, so a
  clinician looking at a patient's chart must leave it, find the right date on the day-board and
  click there; and a patient with no appointment appears to have no note path at all.
- **What should have happened:** a granted clinical capability should have a surface, and the chart
  is where a clinician expects it.
- **Why HIGH:** core clinical operations (write a note about the patient in front of you, record a
  medication, record a vital) have no route from the patient's own record.

### MEDIUM

#### `P2-M1` — No clinician can reach the approval queue; only an administrator can approve an agent draft

`/governance/approvals` is **403** for `doctor`, the dentist and `ed_physician`. Querying
`permission_role` shows **`ai.manage` is held by `org_admin` alone**. So a clinical agent draft can
only ever be reviewed by an administrator, not by a clinician — the inverse of the intended safety
property. The 403 page itself is good (see guards), and the nav correctly does **not** advertise the
queue. **Stated honestly:** the two drafts seeded in this tenant are operational
(`scheduler.fill_from_waitlist`, `comms.draft_reply`), so **I could not exercise a clinical draft end
to end**; the finding rests on the permission map plus the 403s, not on a clinical approval I saw.

#### `P2-M2` — Five of the seven physician roles cannot prescribe

| Role | vs `doctor` |
|---|---|
| `hospitalist` | `+ admission.manage`, `− dental.chart` |
| `surgeon` | `+ surgery.manage, surgery.schedule` · **`− medication.prescribe`**, `− allergy.override, appointment.manage, patient.edit, snippet.manage.shared` |
| `anesthetist` | `+ surgery.manage` · **`− medication.prescribe`, `− order.manage`** (+ the same others) |
| `ed_physician` | `+ admission.manage, ed.manage` · **`− medication.prescribe`** (+ the same others) |
| `pathologist` | `+ lab.catalog, lab.result` · **`− medication.prescribe`** |
| `radiologist` | `+ radiology.catalog, radiology.study` · **`− medication.prescribe`** |

All seven can write and sign notes. A **pathologist** or **radiologist** not prescribing is
defensible; a **surgeon** and an **ED physician** unable to prescribe reads as accidental, and an
**anaesthetist who can neither prescribe nor order** (`− order.manage`) cannot request a pre-op
investigation. **Honesty:** this is **not observable in the UI**, because no prescribing surface
exists anywhere (`P2-H4`) — the evidence is the role template and the permission tables, not the
browser.

#### `P2-M3` — The same kind of date renders in **four** formats, one of them US month/day in a de-CH tenant

Measured across surfaces driven in this phase:

| Surface | Rendering | Style |
|---|---|---|
| `/patients`, `/dental` lists | `12.03.1954` | Swiss `DD.MM.YYYY` |
| Patient 360, chart header | `1954-03-12`, `1954-03-12 (72)` | ISO |
| Chart allergy "Recorded" / "Confirmed" | `9/5/2026`, `9/5/2024` | **US `M/D/YYYY`** |
| Dental chart header | `05/22/1979` | **US `MM/DD/YYYY`** |
| Perio, one page, one date | `09/01/2026` **and** `2026-09-01` | both, together |
| Chart timeline, note versions | `2026-08-03 09:00:00` | ISO datetime |
| "Draft saved", tooth history, imaging | `10:04 AM`, `9/5/2026, 9:51:12 AM` | US 12-hour, viewer's zone |

`9/5/2026` is genuinely ambiguous to a Swiss reader (5 September vs 9 May). This **extends Phase 1's
`P1-M3`** from three formats on the reception surfaces to four across the clinical ones, and the
perio screen prints the *same date* in two formats simultaneously.

#### `P2-M4` — No navigation below 768 px, and for this role **Search is gone too**

At **375 × 844** on `/clinical/chart/{id}`, measured by bounding box and computed style: the only
visible header control is **"Sign out"**. Hidden: all six nav links, **Search** and Notifications.
`header nav` renders 0 visible links; `<main>` offers 2 in-page links. The Search button carries
`hidden … sm:flex`, so it disappears below 640 px as well — Phase 1 recorded the nav gap (`P1-M4`),
and for the clinician **the search escape hatch is also unavailable**. No horizontal overflow
(`scrollWidth 360 ≤ 375`), so the page reads fine; it simply cannot be left.

#### `P2-M5` — Encounters are listed but cannot be opened

The chart Timeline lists six encounters with type, status and timestamp. Each row is a plain `<div>`
with `cursor: auto`, no anchor inside and no anchor ancestor. There is no encounter detail surface
reachable from the chart, so a clinician can see that an encounter exists but not open it.

#### `P2-M6` — The dentist cannot view the dental fee schedule, but a pharmacist can

`/dental/fee-schedule` is **403** for the dentist in their own dental practice.
`FeeScheduleController:29,59,76` authorises **`billing.manage`**, held by `org_admin`, `billing` and
**`pharmacist`** — not by `doctor`. The dentist *does* see the resulting estimates inside the
treatment plan (`CHF 900.00`), so they can quote a fee they cannot inspect or correct. Reading a
price list arguably wants a read-level permission rather than `billing.manage`.

#### `P2-M7` — An amended note's primary link opens the **superseded** version

On the chart's Notes tab, the amended note's card is headed **"Version 1"** and its primary
**`Open →`** points at the superseded v1 (`…9dzc2k`). The current v2 (`…zjgvf6`) is reachable only
through the nested **"Open version →"** entry beneath it. A clinician clicking the note in the list
lands on the outdated text; the amendment is what the record means to say.

#### `P2-M8` — The order form renders in full against an empty catalogue, with no empty state

`praxis-lindenhof` has `orderableItems = 0`. The Orders tab still renders the complete form — an
**empty `<select>`**, a "Reason (documented)" field and Routine/Urgent — with no message explaining
that nothing is orderable. **The "Place order" button is correctly `disabled`**, so this is a
missing-state problem and *not* an unbacked control (D-176 holds).

#### `P2-M9` — Session expiry mid-form discards typed input

Filled the order form, then deleted every row from `sessions` (the session driver is `database`, so
this is a real expiry, and the gate noted Phase 1 could not test this). Submitting produced a clean
branded **419** page — *"Your session expired · For your security your session timed out. Please
sign in again and retry."* — with a "Back to dashboard" link. **The handling is good** (see guards);
the gap is that the typed content is gone, with no warning beforehand and no restoration after
signing back in.

### LOW

#### `P2-L1` — The allergy is rendered twice on Patient 360

The dark hero band and the amber banner directly beneath it carry the identical text
(*"Penicillin · Anaphylaxis requiring adrenaline and admission. · severe"*), one above the other.

#### `P2-L2` — `<html lang="de">` while the entire interface is English

The document declares German and dates on the dashboard and day-board render in German
("Samstag, 5. September 2026", "Fr., 4. September 2026"), but every label, heading and dialog is
English — including the signing gate, which asks a Swiss-German clinician to **"Type SIGN to
confirm"**. **No raw i18n keys were found rendering** (see guards); this is untranslated UI, not
broken translation.

#### `P2-L3` — Validation runs before authorization, leaking the schema to unauthorized callers

Forged POSTs from the `doctor` session to `/comms/inbox/*` return **422 with the full validation
message** ("The thread id field is required", "The action field is required") and only return **403**
once the payload is complete. The action is correctly blocked either way; an unauthorized caller
simply learns the field names first.

#### `P2-L4` — Age is abbreviated differently on the medical and dental charts

`72 y` on the medical chart and Patient 360; `47 yrs` on the dental chart header.

#### `P2-L5` — A medical practice is offered dental charting for every patient

`praxis-lindenhof` is a general medical practice, yet the doctor's nav carries **Dental**, `/dental`
lists all 15 medical patients with "Open dental chart →", and every Patient-360 hero shows a
prominent **"Dental chart →"** button. This follows from `doctor` holding `dental.chart` and both
demo tenants sharing one `plan_id`. **Not a cross-tenant leak** — the patients listed are the
practice's own — but it puts an unused vertical in front of every clinician.

### Guards verified holding (probed in the browser, not assumed)

These were actively attacked and held. This is evidence the programme's fences survive contact.

**The clinical judgment fence — record, don't judge**

- **Vitals stay raw.** All BP values on the chart (77, 81, 125, 128, 132) render with a
  **byte-identical** class `py-1 pr-3 tabular-nums text-ink`, colour `rgb(42,51,42)`, transparent
  background, `font-weight 400` — an elevated 132/81 is styled exactly like a normal 125/77. Zero
  matches for *normal|high|low|elevated|abnormal|critical|flag|score|trend|percentile|range*. Zero
  trend arrows. Zero `<canvas>`. The four `<svg>` are 14–20 px icons, not sparklines.
- **No drug-allergy computation, and the product says so:** *"These are recorded facts — CareOS
  surfaces them, it does not compute drug-allergy conflicts."* and *"No automated medication-safety
  checking is configured… drug-allergy interaction, cross-reactivity and contraindication checking is
  a certified-partner function and is not performed here… they are never automatic and never block a
  prescription."*
- **Odontogram:** *"Colour marks the condition the dentist charted — **not its severity**. Nothing
  here is scored, graded, or flagged."* No DMFT, no index, no finding count, no "sites to watch".
- **Perio:** *"These are raw measurements only… Nothing here is staged, graded, scored, or flagged.
  You read the numbers and interpret them."* **No BOP %, no mean pocket depth, no total, no stage or
  grade, no trend.** Prior exams are shown as raw values (`3/0 4/0• 6/0•`) captioned *"Raw values as
  recorded"*.
- **Diagnosis:** *"You write the diagnosis. Nothing here suggests, proposes, ranks, or auto-fills a
  diagnosis, and no diagnosis is derived from the charting, perio, or imaging. The status is your
  determination."* The clinician's own term list is *"Not a coded set, not ranked, not suggested."*
- **Treatment plan:** *"You author this plan — nothing is auto-suggested."* and *"Estimating is not
  billing — a procedure is charged only when it's performed."* No auto-selected procedure or code.
- **Recall worklist:** *"it is a date sort, not a priority ranking, and no recall is scored, ranked
  or highlighted as more important than another."*

**D-169 — no severity-keyed styling (positive-controlled twice)**

- **Allergies:** a **severe** allergy (Erika Baumgartner, Penicillin) and a **mild** one
  (Reto Zimmermann, Pollen) produce the **byte-identical** class string
  `border border-warning/40 bg-warning-soft`, computed background `rgb(245,236,216)` and the same
  border. The word *severe* itself renders `text-ink-muted` `rgb(90,102,90)`, weight 400. The amber
  means "this patient has allergies", **not** "how bad".
- **Perio pockets:** `2/0`, `3/0`, `4/0`, `5/0` and `6/0` all render `font-mono`,
  colour `rgb(90,102,90)`, transparent background, weight 400. **A 6 mm pocket is not tinted.**

**D-172 — nothing is drawn on a clinical image**

The imaging library states *"This is a viewer. The system does not analyse images — no AI, no
auto-findings, no overlay, no caries or pathology detection"*, *"Zoom and drag change what you see,
not what is recorded. **Nothing is marked on the image**"*, and carries a "WHAT THIS VIEWER DOES NOT
DO" block. **Measured: 0 `<canvas>` and 0 `<svg>` on the page — there is no drawing layer at all.**

**The note editor's agent boundary**

*"You author this note. CareOS stores and versions your text — it does not write, complete, rephrase
or suggest clinical content, and nothing is inserted or signed without you."* and *"Vitals and
results appear as the raw documented values. The editor never colours or interprets them."* Driving
draft → sign → amend twice, **nothing was auto-inserted and nothing auto-signed**.

**A signed note is not editable in place**

On a signed note the SOAP fields render as plain text:
`document.querySelectorAll('textarea:not([readonly]):not([disabled])').length === 1` — and that one
is the *amendment reason*. Verified before signing (on a seeded note) and again on the note I signed
myself. The only action offered is **Create amendment**, described as *"Creates a fresh editable
version prefilled from the signed note. The original stays exactly as signed."*

**The version chain is append-only and complete**

*"Every version stays reachable, including the original. Nothing here deletes."* After I signed v2,
v1 remained signed and openable. The Notes tab's count (**6**) correctly counts *current* versions
against 7 stored rows — the superseded original is retained but not double-counted.

**Signing is a deliberate act**

Signing opens a modal — *"Signing permanently locks this note. Corrections afterwards happen only as
visible amendments"* — showing patient, encounter and `0 of 0 required sections filled`, and the
**"Sign permanently" button stays `disabled` until "SIGN" is typed**. Verified by a click that was
refused.

**Storage is one time base (QA-FIX.1a still holding)**

My browser-written note recorded `created_at 2026-09-05 17:03:53` and `signed_at 17:05:50`, and the
audit rows `note.amended` / `note.signed` landed at `17:03:53.568` / `17:05:50.633` — **agreeing with
CLI `now()` in UTC to the second**. The tooth record I charted stored `17:24:27` and the order I
placed stored `17:30:16`, both UTC. Web writes and CLI share one base.

**Attribution is correct everywhere except notes**

`tooth_records.charted_by = 21` = the logged-in dentist's `users.id`; `orders.ordered_by = 36` = the
logged-in ED physician's `users.id`; every audit row carried the acting user. Only
`clinical_notes.author_id` names someone else (`P2-C1`).

**RBAC holds in both directions**

- The two surprising 200s are **correct by design**: `hospital/wards` authorises `patient.view`
  (`WardBoardController:44`) and gates writes separately on `bed.manage` (`:124`), computing
  `$canManageBeds` / `$canAdmit` so unavailable actions are never rendered; `lab/results/review`
  authorises `order.manage` (`LabReviewController:36`) and results route to the ordering clinician.
- **Forged POSTs are refused.** With a valid CSRF token and a valid `thread_id`, the doctor's
  session got `403 "This user cannot manage communications."` on `/comms/inbox/reply` and
  `/comms/inbox/status`, and `403 "This user cannot run this AI tool."` on `/comms/inbox/ai-draft`.
  **No RBAC hole was found in the "should not be able to" direction.**
- The nav correctly **hides** what a role cannot reach (Approvals never advertised; Scheduling and
  Dental absent for the ED physician). The failure is confined to the page *body* (`P2-H2`).

**Error and edge states are handled, not raw**

- **403** → *"You don't have access to this area"*, explains the cause, offers "Back to dashboard".
- **419 session expiry** → *"Your session expired… Please sign in again and retry"*, with a way back.
- **404** on a mistyped dental URL → *"Page not found · CareOS"*, branded.
- **Empty states are honest:** the day-board on a Saturday reads all zeros with *"Arrived and not yet
  started. A recorded pair of statuses, not a judgment about waiting"*; the ward board says *"No wards
  yet"*; the diagnosis list says *"No terms yet — add your own, or just use free text above."*
- **Consent is enforced and explained:** a recall row states *"No comms consent on record — a message
  cannot be sent to this patient until they consent."*

**No raw i18n keys**

Walking every visible text node on the chart for `^[a-z_]+(\.[a-z_]+){1,3}$` returned **zero**
suspects. (A naive scan of raw HTML appears to find permission keys, but those are the Inertia
`data-page` JSON payload, not rendered text — ruled out by measuring the live DOM.)

**Keyboard reachability**

The perio grid documents and provides keyboard navigation: *"Arrow keys move between sites and teeth;
Enter moves down."* Primary actions (Record, Save draft, Sign note, Place order, Mark Completed) are
real `<button>` elements reachable by tab.

### Ruled out after measurement (recorded so they are not re-reported)

- **Patient-360 page title looked like a bare "CareOS".** An Inertia mount race **in my observation
  only** — `document.title` settles to `Erika Baumgartner · CareOS`. Not a defect.
- **A lab result appeared to run into its timestamp** (`4.22026-09-05 16:51:31`). Measured rects show
  two inline spans on one line with an **8 px gap**; it was an `innerText` artifact. Not a defect.
- **`hospital/wards` / `lab/results/review` reachable by a GP.** Correct by design (above).
- **Notes tab shows 6 against 7 stored rows.** 7 = 6 current + 1 superseded; the count is right.

### Not tested, and why

- **`hospitalist`, `surgeon`, `anesthetist`, `pathologist`, `radiologist` were not driven in a
  browser.** Their permission sets were compared (`P2-M2`) but no session was opened as them. Four of
  the five belong to phases already scheduled (6 Surgery, 7 ED, 8 Lab + Radiology), so driving them
  here would duplicate that work; `hospitalist` is the one genuine gap.
- **A clinical agent draft was never approved.** Only operational drafts were pending, and no
  clinician can reach the queue anyway (`P2-M1`). The approve path's re-authorisation and re-grounding
  behaviour is therefore **unverified in this phase** — it needs a phase whose role holds `ai.manage`.
- **No appointment exists today**, so appointment actions were driven on `2026-09-04` (see
  Environment). The "today" path was exercised only as an empty state.
- **Referrals were not driven.** One referral exists in the seed data, but no referral surface
  appeared in the clinician's navigation or in the 47-route sweep, and I did not locate a create path
  from the chart or Patient 360. Whether a clinician-reachable referral surface exists is **open**.
- **Image upload was not exercised.** The imaging library's upload form was inspected and its fences
  measured, but I did not upload a file.
- **`him_records` / medical-records roles** are out of this group and belong to phase 9.
- **Performance** is out of scope by instruction (dev box, MariaDB, array/dev drivers).
- **The planned code-survey workflow did not complete** and was stopped. It was a targeting aid only;
  every finding above is browser-derived, with code read solely to explain a cause already observed.

---

## Phase 3 — Billing / Finance

**Date:** 2026-09-06 · **HEAD at audit:** `706ed77` (QA-FIX.2b) · **CI at audit:** check-run `check`
→ `completed / success`, read from the GitHub check-run API before starting · tree clean apart from
untracked `docs/marketing-site/`.

### Roles covered

The billing/finance group is defined by who holds a `billing.*` permission. There are exactly three
such roles out of the 26 templates:

| Template | Money permissions | Driven in a browser |
|---|---|---|
| `billing` | `billing.view`, `billing.manage`, **`billing.escalate`** | ✅ the primary pass |
| `org_admin` | `billing.view`, `billing.manage`, **`billing.escalate`** | ✅ sweep + escalation probe |
| `pharmacist` | **`billing.manage` only** — no `billing.view`, no `billing.escalate` | ✅ the ARDETAIL.P6 separation test |

**`billing.escalate` is held by `org_admin` and `billing` alone** — verified by query over
`permission_role`, and the template carries the reason in a comment: *"the billing office owns
debt-enforcement; the clinical roles that hold billing.manage for charge capture do not."* That is
the ARDETAIL.P6 separation, and it holds in the browser (see the guards section).

**Excluded, with reasons.** Every other template holds **no** `billing.*` permission at all —
verified by query, not assumed: `doctor`, `ed_physician`, `reception`, `coordinator`, `him_records`,
and the nursing, lab, radiology, surgery and pharmacy-technician roles. **A note for the record:**
Phase 2's brief said `ed_physician` also holds `billing.manage`; the permission tables say it does
**not** (its 7 permissions contain no `billing.*`). The role-template file carries a comment
mentioning "billing.manage (G6)" next to `ed_physician`, but the permission array beneath it does
not include it — a comment/code divergence worth knowing, not a defect in itself.

### Environment

- **Playwright MCP throughout**, no restart needed. Three real logins (password + TOTP).
- **Re-seeded** all four demo tenants, 0 exceptions.
- **Redis: UP, stated honestly** — a Memurai process (dev licence) started manually in an earlier
  session, not a restored service.
- **PERFORMANCE IS OUT OF SCOPE** (MariaDB + dev drivers), deferred to staging.
- Browser zone `America/Los_Angeles`, locale `en-US`; tenant `Europe/Zurich`. Stated because it is
  what makes `P3-M2` visible and separates my machine from the defect.

**Data verified BY QUERY — and the gaps stated rather than worked around.** The four demo seeders
produce: 7 invoices in `praxis-lindenhof` (6 `INV` + 1 `CN`), balances across
issued / partially_paid / paid, 4 payments including one partly allocated (Erika, CHF 219.78 with
25.00 left unallocated) and **an allocation reversal** (a −1000 row with
`reverses_allocation_id` and a reason), 1 dunning event at level 1 **with its captured CHF 15.00 fee
charge**, and the hospital tenant's composite episode.

**What the seeders do NOT produce, and what I did about it:** there are **zero**
`invoice_adjustments` (so no write-off and no contractual adjustment), **zero** `payment_plans` and
**zero** `debt_enforcement_escalations` in any tenant, and `SimulatedBillingMonthSeeder` creates none
either. I therefore created those states **through the UI** as the gate's functional tests — a
payment plan and a credit note — and for the two that cannot be created at all, said so:

- **A write-off / contractual adjustment cannot be created through the product at all.** That is
  finding `P3-H3`, not a data gap.
- **No account can reach Betreibung eligibility in this environment.** The configured policy is
  level 1 at 14 days past due and level 2 at 30 (tenant setting `billing.dunning`); the most overdue
  account is 24 days past due, so the terminal stage is 6 days away and cannot be reached without
  moving the clock or editing data — neither of which an audit may do. **I therefore never drove the
  Betreibung escalation to its confirm step, and no legal proceeding was filed against demo data.**
  I verified the gate from the refusal side instead, which is the direction that matters.

**⚠️ DEMO DATA I MUTATED (stated, per the gate's instruction).** All left in place — findings are
recorded, not fixed:

| What | Where |
|---|---|
| **3 phantom payments** (2 × CHF 500.00, 1 × CHF 10.00) | Viktor Odermatt — created by the refused operations in `P3-C1` |
| 1 payment plan (CHF 169.61 over 3) + installment #1 settled (CHF 56.53) | Viktor Odermatt |
| **Credit note CN-2 (−CHF 313.00)** against INV-1 | Erika Baumgartner |

### Surfaces driven (explicit, so gaps are visible)

| Surface | `billing` | `org_admin` | `pharmacist` |
|---|---|---|---|
| `/app` landing | ✅ (all 4 links 403) | ✅ | ✅ |
| `/billing/report` (BILLAR.P6) | ✅ **all δ=0 checked** | ✅ 200 | 403 |
| `/billing/aging` | ✅ | ✅ 200 | 403 |
| `/billing/invoices` list | ✅ | ✅ 200 | 403 |
| Invoice detail + line items | ✅ | — | 403 |
| **Invoice PDF download** | ✅ **bytes inspected** | — | — |
| `/billing/new-invoice` | ✅ | ✅ 200 | ✅ **200 — see `P3-H4`** |
| `/billing/payments` list | ✅ | ✅ 200 | 403 |
| **Record payment (+ over-allocation)** | ✅ **UI + 3 forged POSTs** | — | — |
| **Payment plan (create, ceiling, settle)** | ✅ **end to end** | — | — |
| **Credit note (issue)** | ✅ **CN-2 created** | — | — |
| `/billing/credit-notes` | ✅ | ✅ 200 | 403 |
| `/billing/dunning` + run | ✅ **ran it** | ✅ 200 | 403 |
| AR account detail (ledger, timeline) | ✅ 2 accounts | ✅ | — |
| **Betreibung escalation** | ✅ **2 forged POSTs** | ✅ **forged POST** | ✅ **forged POST** |
| **CSV export** | ✅ **content checked** | — | — |
| Fee/tariff catalogs (dental, pharmacy, surgery) | ✅ 200 | — | ✅ 200 |
| Clinical/admin surfaces (negative RBAC) | ✅ **6 forged POSTs** | — | — |
| Narrow viewport 375×844 | ✅ | — | — |

Route sweeps were run in-session: **29 routes** for `billing`, 12 for `pharmacist`, 10 for
`org_admin`.

### CRITICAL

#### `P3-C1` — A **refused** record-payment still commits the payment

> ✅ **FIXED — QA-FIX.3a, commit `348d41c` (D-199).** The guard was never the problem —
> `PaymentService::allocate()` refused correctly every time. **The controller composed two service
> calls with nothing around them**, so the payment committed before the allocation was even
> attempted. `record()` + `allocate()` now run inside **one `DB::transaction`**, and the guard's
> exception propagates out of it, so a refusal unwinds the payment and every allocation line already
> applied on the same submission.
> **A rollback is the only available fix:** `Payment` is append-only at the model level
> (`static::updating` and `static::deleting` both throw), so a compensating delete would itself be
> refused — the write must never happen.
> **The correct shape already existed in the same file:** the payment-plan path refuses cleanly
> because `PaymentPlanService::create()` wraps its whole operation in `DB::transaction`. This matches
> that discipline.
> **The legitimate unallocated payment is preserved, structurally rather than by a flag:** with no
> allocation lines `allocate()` is never called, nothing throws, and the transaction commits — money
> received today and applied tomorrow, and an overpayment's remainder, both still work. Only an
> allocation that is *attempted and refused* unwinds the payment.
> **The audit row rolls back with it, deliberately** — `record()` audits inline and `AuditService`
> runs on the same connection, so the ledger cannot claim a payment that does not exist; a test
> asserts `verifyChain()` is still OK after a rolled-back append.
> **Re-measured in the browser, same steps as below:** the UI attempt (CHF 500.00 against a CHF
> 169.61 open invoice) plus both forged POSTs left `payments` at **4 — the seeded baseline,
> unchanged — against 7 in Phase 3**; allocations stayed 6, INV-5's open balance stayed 16961,
> **zero `payment.recorded` audit rows** were written, and the chain verified OK.
> **The sibling `PaymentController::store()` was deliberately NOT changed** — it composes the same
> pair but *discloses*, redirecting to the payment it kept with the error beside it, which is a
> coherent desk workflow. Recorded in `DEFERRED.md` with its residual rather than widening the gate.
> Guarded by 6 ADDED tests in `AccountRecordPaymentTest.php` (3 D-182-shaped absence assertions +
> 3 positive controls). Mutation-checked: replacing the transaction with a plain IIFE reddens 5 while
> all 3 positive controls stay green.
> **Two existing tests asserted the old behaviour and are corrected in place** — one asserted "the
> receipt itself stands (money WAS received)", the other asserted that the first allocation line was
> kept when a later line was refused, so a refused multi-line payment had left both an orphan payment
> *and* a partly-applied invoice.

- **Role:** `billing` · **Route:** `/billing/accounts/{account}` → Record payment
- **What I did:** On Viktor Odermatt (INV-5, open CHF 169.61) I entered **AMOUNT RECEIVED 500.00**
  and allocated **500.00** to INV-5, then pressed *Record payment*.
- **What happened:** the page returned the guard's message — **"Cannot allocate more than the invoice
  open balance."** — Balance due stayed CHF 169.61 and the ledger was unchanged. Any operator would
  conclude nothing was written. **A CHF 500.00 payment row was created anyway**, and it is fully
  visible on `/billing/payments` as received money (`500.00 CHF · unallocated 500.00 CHF`).
  Repeating it by **forged POST** (valid CSRF, correct `amount_minor` field names) twice more —
  once over-allocating the invoice, once allocating more than the payment itself — created two more.
  The payments list went from **4 rows to 7**, and **CHF 1'010.00 of money that was never received**
  now sits on the account.
- **What should have happened:** a refused write leaves nothing behind, or the message says what was
  kept.
- **Cause:** `Modules/Billing/src/Http/Controllers/AccountDetailController.php`
  - `:182` `$payment = $payments->record(...)` — **committed first, unconditionally**
  - `:195-204` the allocation loop runs afterwards and `return`s a redirect with the error on
    `InvalidArgumentException`.
  There is **no `DB::transaction` around the record + allocate pair** and no rollback. The in-code
  comment reads *"Nothing was posted for this line"* — true of the allocation **line**, and
  misleading about the operation, because the payment is already committed.
- **Why CRITICAL:** it writes **false financial records** — rows asserting money was received that
  never was — while telling the operator the opposite, and every retry adds another. One screen
  already reports them as income: `/billing/aging`'s *COLLECTED (MONTH TO DATE)* counts payments by
  `received_on` (see `P3-H1`), so CHF 1'010.00 of phantom cash is included in it.
- **What is NOT affected, verified:** the reconciled figures are untouched. The engine computes
  Collections from **allocations**, so after all three phantom payments the report still showed
  *COLLECTED* 1058.03, *TOTAL AR* 743.61 and a roll-forward that ties. The allocation guard itself
  never yielded: INV-5's open balance stayed 16961 and its allocation count stayed 2 throughout.

### HIGH

#### `P3-H1` — Two billing screens report different "COLLECTED" figures

- **Role:** `billing` · **Routes:** `/billing/aging` vs `/billing/report`, same day, same window
- **Observed on screen:**

  | Screen | Label | Value |
  |---|---|---|
  | `/billing/aging` | COLLECTED (MONTH TO DATE) | **1066.53 CHF** |
  | `/billing/report` | COLLECTED (PERIOD) — 2026-09-01 to 2026-09-06 | **1114.56 CHF** |

- **Adjudicated against the ledger:** `1114.56` is the sum of **allocations** by `allocated_at`;
  `1066.53` is the sum of **payments** by `received_on` in September
  (500.00 + 56.53 + 500.00 + 10.00).
- **Cause:** `Modules/Billing/src/Http/Controllers/AgingController.php:40` calls
  `paymentsReceivedTotalMinor($actor, $monthStart, $today)` — `MetricsService:230-243`, *"sum of
  payments with `received_on` in the range"*, i.e. **gross cash received**. The management report
  instead sums **allocations** by `allocated_at` (`MetricsService:587`), i.e. **cash applied**.
- **What should have happened:** two money surfaces in one module should not answer "how much did we
  collect?" with two numbers under the same word, and neither page states its basis.
- **Why HIGH not CRITICAL:** neither figure disagrees with the engine — each *is* the engine
  answering a different question, and both are legitimate accounting concepts (receipts vs applied
  collections). It is HIGH because a reader cannot tell which is which, and because the receipts
  basis silently absorbs unallocated money — including `P3-C1`'s phantom payments.

> ✅ **FIXED — QA-FIX.3b, commit `d6f0cc5` (D-200).** Neither figure was wrong, so neither engine
> method was touched: this is a **labelling fix**, and the tests pin both definitions in place.
>
> - `/billing/aging` no longer says "Collected". It says **"Cash received (month to date)"** and
>   carries the basis underneath: *"Money received, by payment date. Refunds are separate rows and are
>   not netted here. This is not the same as collections applied to invoices — see the management
>   report."* The wording is taken from what `MetricsService::paymentsReceivedTotalMinor()`
>   (`MetricsService:230-243`) actually computes, not paraphrased from the label.
> - `/billing/report` already rendered **both** quantities and named neither. Its **"Collected
>   (period)" card** (`Report.vue:227`, the prop `collection_rate.collections_minor`) now reads
>   *"Payments applied to invoices, by allocation date. Reversals net out. This is the figure that
>   reduces AR."* (`netCollectionsMinor`, `MetricsService:587`), and its
>   "Cash received" line reads *"Money received, by payment date — including anything not yet applied
>   to an invoice."*
> - **The two numbers still differ, and that is now readable rather than contradictory.** A reader on
>   either screen can see which question is being answered and why the other page's total is not the
>   same.
> - **Pinned by mutation, on both surfaces.** `tests/Feature/Billing/CollectedBasisLabelsTest.php`
>   (6 tests) drives the two bases apart — CHF 400.00 received against CHF 100.00 applied — and
>   asserts each page renders the basis its caption claims. Swapping `AgingController:40` to the
>   applied basis reddens it; so does swapping `BillingReportController:173`. An earlier version of
>   these tests asserted only the engine methods and the captions and **survived the first mutation** —
>   the caption was an unchecked claim until the render assertion was added.
> - **What this fix does NOT do:** it does not net refunds into the receipts figure and does not
>   reconcile the two totals into one. The unallocated-money component of the receipts basis is
>   legitimate; the *phantom* component this finding referred to was removed separately by
>   `QA-FIX.3a` (`348d41c`), which stopped refused writes from leaving payments behind.

#### `P3-H2` — "PDF" invoices and dunning letters are plain-text files

- **Role:** `billing` · **Routes:** `/billing/invoices/{id}/pdf` ("Download PDF"), and every dunning
  letter written by a reminder
- **What I did:** fetched the invoice PDF in-session and inspected the bytes.
- **What happened:** 607 bytes, served as `Content-Type: application/pdf`,
  `Content-Disposition: attachment; filename="INV-5.pdf"`. Structural check of the payload:

  | marker | present |
  |---|---|
  | `N 0 obj` | ❌ |
  | `xref` | ❌ |
  | `trailer` | ❌ |
  | `stream` | ❌ |
  | `%%EOF` | ❌ |

  The first three lines are `%PDF-1.4` / `CareOS EU-Generic VAT invoice` / `Seller: CareOS tenant`,
  and the last is `Total: 16961` (raw minor units). **It is a plain-text file whose first line is the
  literal string `%PDF-1.4`.** No PDF reader can open it — there is no object structure at all.
- **Cause:** `Modules/Billing/src/Services/InvoicePdfRenderer.php:36` builds
  `$lines = ['%PDF-1.4', …]` and writes `implode("\n", $lines)`.
  `Modules/Billing/src/Services/DunningLetterRenderer.php:25` does the identical thing for reminder
  letters — the seeded `INV-1-L1.pdf` on disk is **212 bytes with zero structural markers**.
- **Why HIGH:** an invoice and a payment reminder are the artifacts a practice sends to patients, and
  the reminder is the document a Betreibung filing would rest on. **D-176** — the control asserts a
  capability the system does not have, and nothing on screen qualifies it.

#### `P3-H3` — Write-offs and contractual adjustments cannot be created at all

- **Role:** `billing` · **Route:** none exists
- **What I did:** looked for the control on every billing surface I drove, then searched the routes
  and the Vue tree.
- **What happened:** the AR roll-forward has two dedicated lines — **"−Contractual adjustments"** and
  **"−Write-offs"** — and both can only ever display `0.00 CHF`, because **nothing in the product can
  create one**. There is no route, no controller and no UI. `AdjustmentService` is fully built
  (`writeOff()`, `contractual()`, a reversal path and reconciliation integration) and covered by
  `tests/Feature/Billing/WriteOffAdjustmentTest.php`, but its only non-test references are its own
  model docblock and a migration comment — **no production caller**.
- **Why HIGH:** writing off a bad debt and recording an insurer's contractual adjustment are core AR
  operations for the role this phase audits, and the report advertises both. This is **cross-phase
  pattern 4** (a granted capability with no surface) in its strongest form yet: the capability is
  built, tested and reported on, and unreachable.

#### `P3-H4` — The pharmacist is refused every billing **read** surface but can open invoice **creation**

- **Role:** `pharmacist` (holds `billing.manage` only) · **Route:** `/billing/new-invoice`
- **What I did:** swept the billing routes in-session as `sofia.rieder@klinik-bergblick.test`.
- **What happened:**

  | Route | Status |
  |---|---|
  | `billing/invoices`, `billing/report`, `billing/aging`, `billing/dunning`, `billing/payments`, `billing/credit-notes` | **403** |
  | **`billing/new-invoice`** | **200** |

  The read surfaces require `billing.view`, which the pharmacist lacks; the creation surface is gated
  on `billing.manage`, which PHARMACY.G5 granted them so dispensing charges reach the billing engine.
  The result is a role that **cannot see a single invoice, payment or the AR position, yet can open
  the screen that assembles and issues an invoice.** Its breadcrumb links to `/billing/invoices`,
  which **403s for this very role** — a dead end inside the module.
- **Why HIGH:** an invoice is a legal financial document; the permission that exists for *charge
  capture* should not also open *invoice issuing*, and a create-without-read grant is the wrong shape.
- **Stated honestly:** the page rendered *"No validated, un-invoiced charges to bill."* — that tenant
  had none at the time — so **I could not complete an invoice creation as the pharmacist**. The
  reachability of the surface is established; the completed write is not.

### MEDIUM

#### `P3-M1` — The Swiss money formatter is used on 1 of 12 billing surfaces

`resources/js/lib/money.ts` exports `formatSwissMoney()` producing **`CHF 4'820.00`** — apostrophe
group separator, currency first — with its own test (`money.test.ts:26`). **Only
`AccountDetail.vue:21` imports it.** The other eleven billing surfaces hand-roll
`` `${(minor / 100).toFixed(2)} ${currency}` ``: `Aging.vue:24`, `Report.vue:81`,
`CreditNotes/Index:30`, `CreditNotes/Show:38`, `Dunning/Index:32`, `Invoices/Index:42`,
`Invoices/New:32`, `Invoices/Show:48`, `Payments/Index:32`, `Payments/Record`, `Payments/Show`.
**Observed:** the report renders `1058.03 CHF` and `1801.64 CHF` (should be `CHF 1'058.03` /
`CHF 1'801.64`); the account detail renders `CHF 169.61`; the invoice detail renders `169.61 CHF`.
Same module, two orders and no thousands separator on eleven of twelve screens.

#### `P3-M2` — Date-entry defaults come from the viewer's calendar, not the practice's

The record-payment form's **RECEIVED ON** defaulted to `2026-09-05` while the practice date
(Europe/Zurich) was `2026-09-06`; the browser is `America/Los_Angeles`. The payment I recorded
through the UI is dated **05.09.2026** on the payments list. The payment-plan form's **FIRST
INSTALLMENT DUE** defaults the same way, and the consequence is visible: the plan I created was
immediately shown with installment #1 **"Overdue"** on the day it was agreed.
**Cause:** `AccountDetail.vue:209-212` `todayLocal()` uses `new Date()`. Its own comment shows the
author deliberately avoided the UTC slice (*"not the UTC slice of an ISO string, which shifts a day
behind UTC"*) but landed on **browser**-local rather than **tenant**-local — the fix was half-right.
**Money consequence:** a payment can be dated into the wrong day and therefore the wrong period.
*(The server-side stamps are correct: the settled installment recorded "Paid 2026-09-06".)*

#### `P3-M3` — Dates render in two formats inside the billing module

Swiss `DD.MM.YYYY` on the invoice list, invoice detail, payments list and dunning worklist
(`26.08.2026`, `13.08.2026`); ISO on the AR account ledger (`2026-08-14`, `2026-08-26`), the report
header (`2026-09-01 to 2026-09-06`) and the payment-plan schedule (`2026-10-05`); German long form
on the app landing (`Sonntag, 6. September 2026`) and the aging page (`As of 06. September 2026`).
The split tracks the BILLAR-era vs ARDETAIL-era surfaces. **Cross-phase pattern 2, third role group.**

#### `P3-M4` — "Send reminders" gives no feedback and writes no audit row

Pressing it left the page byte-identical: no *"0 reminders prepared"*, no toast, no error. The
operator cannot tell whether the run happened, failed, or found nothing to do. **No audit row was
written for the operator's action** (0 dunning audit rows after the click) while the payment-plan
actions in the same session did audit (`billing.payment_plan_created`, `payment.recorded`,
`payment.allocated`, `billing.payment_plan_installment_paid`).
**The engine was right to do nothing** — policy is level 1 at 14 days past due and level 2 at 30;
the invoices were 9 and 24 days past due. The defect is that the operator is told nothing.

#### `P3-M5` — "REMINDERS SENT" contradicts the "Prepared" rows beneath it

On the AR account dunning panel the stat card reads **"REMINDERS SENT — 1"** while the only event
below it is labelled **"Prepared"**. Nothing was sent: the event's status is `CREATED`, and the
underlying model is honest — `DunningService::deliver()` returns false when no channel is registered
or the send throws, `status = SENT` is written **only** on real delivery, and the `dunning.sent`
audit row likewise. One panel contradicts itself and the card asserts an action not taken (**D-179**);
only the label overstates.

#### `P3-M6` — The account ledger says "every invoice" and omits credit notes

The AR account ledger is captioned *"every invoice · amount, paid & running balance"*, but a credit
note never appears in it. Viktor Odermatt holds CN-1 (−CHF 37.84) against INV-5; his ledger lists
INV-2 and INV-5 only. The credit note **is** shown on the invoice detail (*"CREDIT NOTES / CN-1 /
-37.84 CHF"*), so it is not invisible everywhere — but the account that owes the money never shows it.
**This is not wrong arithmetic:** `MetricsService:652-658` documents that a **partial** credit note
deliberately *"does NOT cancel or reduce the invoice balance"* (only a full credit sets open to 0 via
`CANCELLED_BY_CREDIT_NOTE`), so the CHF 169.61 balance is the engine's stated intent. The gap is
visibility: a clerk working the account cannot see that a credit exists against it.

#### `P3-M7` — The billing role's landing page is four dead ends

`/app` as `billing` contains exactly **four** unique `<main>` links and **all four return 403**:
`/patients/register`, `/scheduling/day-board`, `/nursing/dispatch`, `/comms/inbox`. The nav is
correct (Dashboard + Billing only); the page body is not. **Cross-phase pattern 1, third role group**
— the same shape as `ed_physician` in Phase 2.

#### `P3-M8` — No navigation below 768 px

At **375 × 844** on the billing surfaces the only visible header control is **"Sign out"**; Dashboard,
Billing, Search and Notifications are all hidden and `header nav` renders 0 visible links.
**Better than earlier phases in one respect:** there is no horizontal overflow
(`scrollWidth 360 ≤ 375`) and the money tables sit in an `overflow-x: auto` parent, so the figures
are readable — the page simply cannot be left. **Cross-phase pattern, third role group.**

### LOW

#### `P3-L1` — A refused escalation says nothing

The forged Betreibung POSTs were correctly refused and wrote nothing, but the account page showed **no
message** — only the standing *"Not available: the dunning process has not reached its final reminder
stage."* notice. Defensible, since the UI never offers the control; noted because a silent refusal and
a silent success look identical to a caller.

#### `P3-L2` — The New-invoice breadcrumb points at a surface the role cannot open

`/billing/new-invoice`'s only link is a breadcrumb to `/billing/invoices`, which **403s** for the
`pharmacist` who can reach the page (`P3-H4`). A one-link page whose one link is a dead end.

### Guards verified holding (probed in the browser, not assumed)

**THE MONEY FENCE — every δ=0 claim checked arithmetically ON SCREEN**

On `/billing/report` as `billing`, each engine claim was verified by adding up what the page itself
displayed:

| Claim on screen | Check | Result |
|---|---|---|
| AR roll-forward *"Ties, delta = 0"* | 1801.64 + 0.00 − 1058.03 − 0.00 − 0.00 | = **743.61** = Closing AR ✓ |
| AR aging partitions the range | 0.00 + 743.61 + 0.00 + 0.00 + 0.00 | = **743.61** = Total outstanding ✓ |
| By-payer *"Groups tie to total"* | Self-pay 743.61 | = Total 743.61 ✓ |
| Top-overdue *"Rollup ties"* | 313.00 + 261.00 + 169.61 | = **743.61** ✓ |
| Account ledger *"Ledger ties"* | running balance 169.61 = outstanding; totals 319.36 − 149.75 | = **169.61** ✓ |
| Payment plan *"Schedule ties"* | 56.53 + 56.53 + 56.55 | = **169.61** exactly, last absorbing the remainder ✓ |

**AND THE SAME TIES SURVIVED TWO REAL WRITES — the positive control that proves they are not
vacuous.** After settling installment #1 (CHF 56.53) and then issuing a **full credit note**
(−CHF 313.00), every figure moved together and the badge stayed truthful:

| | start | after installment | after credit note |
|---|---|---|---|
| Total AR | 743.61 | 687.08 | **374.08** |
| Collections | 1058.03 | 1114.56 | 1114.56 |
| Charges billed | 0.00 | 0.00 | **−313.00** |
| Roll-forward ties? | ✓ | 1801.64 − 1114.56 = 687.08 ✓ | 1801.64 − 313.00 − 1114.56 = **374.08** ✓ |
| Aging sum | 743.61 | 687.08 | 374.08 ✓ |
| Top-overdue rollup | 743.61 | 687.08 | 261.00 + 113.08 = 374.08 ✓ |
| The account's own page | 169.61 | 113.08 | Erika drops off entirely ✓ |

**A credit note is carried as negative "+Charges billed", so the roll-forward has a home for it.**
I had hypothesised the opposite — that a credit note appears in no roll-forward line and would break
δ=0 — and the browser refuted it. Recorded as tested-and-refuted rather than reported.

**NO PAGE-SIDE MONEY MATH** — adversarial grep over all 12 billing Vue surfaces plus what I observed:

- **Zero `.reduce(` and zero `.sum(`** anywhere in `resources/js/pages/Billing/`.
- Every `_minor` reference in the templates is a **single engine field** passed to `money()` —
  `money(ledger.account_outstanding_minor)`, `money(row.running_balance_minor)`, and so on. No
  template adds, subtracts, ratios or aggregates two money fields.
- The only client arithmetic is the **minor→major unit conversion** inside one formatter per page.
- The report says so itself and it is true: *"Every figure is computed by the reporting engine over
  the reconciled ledger and displayed here; this page performs no money math."*
- **The CSV export carries raw engine integers**, not formatted strings —
  `section,metric,value_minor_or_ratio` with `headline,total_ar_minor,68708` and
  `aging,days_1_30,68708`, tying exactly to the on-screen 687.08 CHF. No formatting or rounding drift
  between screen and export, and **DSO exports as an empty value** rather than a fabricated 0.

**THE GUARDED WRITES**

- **Over-allocation refused four ways.** Allocation > the invoice's open balance (UI **and** forged
  POST) → *"Cannot allocate more than the invoice open balance."*; allocation > the payment itself
  (forged) → refused; a negative allocation → `422 "must be at least 1"`. Throughout, INV-5's open
  balance stayed `16961` and its allocation count stayed `2`. *(The payment row that survives these
  refusals is `P3-C1`; the allocation guard itself never yielded.)*
- **The payment plan cannot exceed the real outstanding.** Scheduling CHF 500.00 against CHF 169.61
  outstanding → *"A payment plan cannot schedule more than the account outstanding balance."* — and
  **unlike record-payment it left no orphan row** (`payment_plans` stayed 0, installments 0). The
  plan path refuses cleanly; the payment path does not.
- **Betreibung is operator-only, eligibility-gated, and refuses under forgery.** As `billing` (who
  *does* hold `billing.escalate`), forged POSTs with a reason and `confirmed:true` were refused on a
  stage-1-of-2 account **and** a stage-0-of-2 account; as `org_admin`, likewise. After every attempt
  `debt_enforcement_escalations` was still **0** with no enforcement audit row. **No legal proceeding
  was filed against demo data.**
- **ARDETAIL.P6's narrower permission holds in the browser.** The `pharmacist` — who holds
  `billing.manage` — was refused the escalation with **403 "This action is unauthorized."** A role
  holding `billing.manage` for charge capture genuinely cannot file legal proceedings.
- **Agent exclusion is structural, and the page says so:** *"The AI agent can draft reminders for
  approval — it has no way to start, approve or file a legal proceeding."* The route comment records
  that the two enforcement actions are the only callers of `DebtEnforcementService`, with no agent
  tool, job or schedule able to reach it.
- **Collections count allocations, not payments** — three phantom payments totalling CHF 1'010.00
  moved no reported figure on the management report.
- **A dunning fee is a new draft charge, never a mutation of the original invoice**
  (`DunningService:230-250`: *"A dunning fee is a NEW draft charge that appears on a future document.
  The original invoice is never touched."*) — CHF 15.00 on Erika's timeline.
- **Dunning delivery is honest** — `status = SENT` only on real delivery, else `CREATED` → rendered
  "Prepared"; the `dunning.sent` audit row is written only when something was actually sent.

**RBAC IN BOTH DIRECTIONS**

- **Forged clinical writes as `billing` are refused — tested with REAL ids.** My first attempt used
  dummy ids and returned 404s, which prove nothing: model resolution runs before authorization on
  several routes. Re-run with real ids:

  | Forged POST | Result |
  |---|---|
  | `/scheduling/day-board/open-encounter` | **403** "This user cannot manage encounters." |
  | `/scheduling/day-board/transition` (arrive) | **403** "This action is unauthorized." |
  | `/clinical/notes/{id}/sign` | **403** "This action is unauthorized." |
  | `/comms/inbox/reply` | **403** "This user cannot manage communications." |
  | `/comms/inbox/status` | **403** "This user cannot manage communications." |
  | `POST /patients` | **403** "This action is unauthorized." |

- The `billing` nav shows **Dashboard + Billing only**, and `/patients`, `/reporting`, `/admin`,
  `/settings`, and every clinical surface are 403. `org_admin` correctly reaches all billing surfaces
  and is still **403 on `/admin`** (the platform area).

**D-169 ON MONEY — no severity tint on age or overdue-ness**

All five aging buckets (Current, 1-30, 31-60, 61-90, 90+) render with the **byte-identical** class
`font-medium text-ink`, colour `rgb(42,51,42)` and transparent background. **A 90+ day bucket is not
tinted red.** The page also refuses to invent a provision: *"Amounts are factual and are not adjusted
for expected collectability."*

**HONEST "—" RATHER THAN A FABRICATED METRIC (BILLAR.P3)**

**DSO** renders **"—"** with the qualifier *"over 6 days"*, and **NET COLLECTION RATE** renders
**"—"** with *"Charges 0.00 CHF less contractual 0.00 CHF"*. Neither invents a number from a zero
denominator. The payment form is equally plain: *"This records money already received — there is no
card capture."* — no pay/charge affordance is offered (**D-176**).

**ONE TIME BASE ON BILLING WRITES (QA-FIX.1a still holding)**

The payment I recorded through the UI stored `created_at 2026-09-06 01:13:24` and the credit note
`01:23:40`, both matching CLI `now()` **in UTC**. Web writes and CLI share one base on the money path.

**THE FROZEN INVOICE COLUMNS ARE NOT READ BY ANY SURFACE**

`invoices.status` and `invoices.open_balance_minor` disagree with the live `invoice_balances`
projection for 4 of 7 invoices (e.g. INV-6 `45250` vs `0`/paid) — `invoices.status` says "issued" for
all seven. **No screen reads the stale value:** the invoice list correctly shows INV-6 "Paid", INV-3
"Partially paid", INV-2/4 "Paid", and `MetricsService:654` documents the freeze deliberately (*"the
frozen `invoices.status` stays ISSUED"*). I raised this as a candidate CRITICAL and withdrew it after
checking the browser.

### Not tested, and why

- **Betreibung was never driven to its confirm step**, so the *successful* escalation path — the
  append-only record, the withdraw action, and the audit row it writes — is **unverified**. No
  account can reach the terminal dunning stage in this environment (policy needs 30 days past due;
  the oldest is 24), and reaching it would require moving the clock or editing data. The refusal
  direction is fully covered.
- **A write-off and a contractual adjustment could not be exercised** — they cannot be created
  through the product at all (`P3-H3`), so the operator-gating and reconciling behaviour BILLAR.P1
  describes is verified only by its tests, not by this audit.
- **No invoice was created as the `pharmacist`** — the tenant had no validated un-invoiced charges,
  so `P3-H4` establishes reachability but not a completed write.
- **Charge-capture surfaces were not driven.** They belong to the clinical and pharmacy phases; the
  `billing` role reaches the three pricing catalogs (dental, pharmacy, surgery — all 200) but I did
  not exercise capture itself.
- **The hospital tenant's composite ED→inpatient episode was seeded and confirmed present but not
  driven end to end** — its reconciliation is exercised by `DemoHospitalSeederTest`, and this phase's
  browser time went to the `praxis-lindenhof` AR surfaces where the money guards concentrate.
- **Session expiry mid-form was not repeated here** — Phase 2 established the behaviour (a branded
  419 page with a way back, typed input discarded) and nothing in billing changes it.
- **Performance is out of scope** by instruction.
- **`org_admin` was swept, not fully driven** — it is the subject of the planned Phase 10, and
  driving it fully here would duplicate that phase.

---


## Phase 4 — Nursing / Spitex (incl. the Nurse PWA)

**Date:** 2026-09-06 · **Top commit at audit time:** `d6f0cc5` (QA-FIX.3b), CI `completed / success`
confirmed via `commits/<sha>/check-runs`. Tree clean apart from untracked `docs/marketing-site/`.

**AUDIT ONLY.** No app code, test or seeder was changed. The only writes are those made by *driving
the product* (a check-in, notes, vitals) plus one deliberate token revocation; all demo tenants were
re-seeded afterwards and verified by query.

### Environment

| Item | State |
|---|---|
| Demo tenants | Re-seeded before the audit; verified by query |
| Spitex tenant | `spitex-sonnengarten` — Spitex Sonnengarten, branch Zürich Wipkingen, `Europe/Zurich` |
| Nursing data | 55 planned visits · 36 execution visits · 36 visit tasks · 36 vitals · 36 notes · 5 care plans · 5 service agreements · 36 timesheet lines · 8 patients |
| Nurses | 5 (Hans Brunner 22 planned, Verena Huber 20, Ana Silva 7, Miriam Steffen 4, David Okafor 1) — **cross-assignment RBAC has real material** |
| **Redis** | **UP — honestly.** `PING` → `+PONG` on 127.0.0.1:6379 (Memurai). `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis` |
| App timezone | `UTC` (storage), tenant/branch `Europe/Zurich`, `locale=de` |
| Out of scope | **Performance — deferred to staging**, per the gate |

**Data-coverage limitation, stated rather than papered over.** The seed does **not** contain visits
across all four states: `planned_visits` are only `assigned` (54) and `planned` (1), and all 36
execution visits are `completed`. There is **no seeded in-progress or missed visit**. I created an
`in_progress` visit by driving a real check-in through the sync API; **missed** was never exercised
because nothing in the product reaches that state (see `P4-H3` — the PWA cannot check in or out at
all).

### Roles covered

| Role | Driven as | Tenant |
|---|---|---|
| `nurse` | `hans.brunner@spitex-sonnengarten.test` (field/Spitex) | spitex-sonnengarten |
| `coordinator` | `margrit.keller@spitex-sonnengarten.test` | spitex-sonnengarten |
| `ward_nurse` | `lena.studer@klinik-bergblick.test` | klinik-bergblick |
| `charge_nurse` | `petra.frei@klinik-bergblick.test` | klinik-bergblick |

**Excluded, with reasons:** `scrub_nurse` (`nadia.brun@klinik-bergblick.test`) → **Phase 6** (Surgery/OR);
`triage_nurse` (`yusuf.demir@…`) and `ed_charge_nurse` (`marco.bianchi@…`) → **Phase 7** (ED). These
three are nursing by title but their surfaces are the OR and ED boards, which those phases own.
There is **no Spitex-specific role template** — the field nurse holds the same `nurse` template as a
practice nurse, so Spitex-vs-practice is a *vertical* difference, not a permission one (the same
shape Phase 2 found for doctor-vs-dentist).

Capability sets (`RbacProvisioner::ROLE_TEMPLATES`, 26 templates): `nurse` and `ward_nurse` are
**identical** — `patient.view`, `appointment.manage`, `encounter.manage`, `note.write`, `note.sign`,
`order.manage`. `charge_nurse` adds `note.supervise`, `reporting.view`, `bed.manage`. `coordinator`
is disjoint from all of them — `patient.view`, `appointment.manage`, `agreement.manage`,
`dispatch.manage`, `competency.manage`, `timesheet.approve`, `reporting.view`.

### Surfaces driven

| Surface | Route | Roles driven | Result |
|---|---|---|---|
| Landing / workspace | `/app` | all four | renders; offers links that 403 (`P4-H5`) |
| Landing at 390 px | `/app` | nurse | **no navigation at all** (`P4-M1`) |
| Dispatch board | `/nursing/dispatch` | coordinator ✅ · nurse/ward/charge 403 | renders; raw UTC (`P4-H4`) |
| Nurse competencies | `/nursing/competencies` | coordinator ✅ | reachable, linked from dispatch |
| Ward board | `/hospital/wards` | ward_nurse ✅ · charge_nurse ✅ | renders; **unlinked anywhere** (`P4-M4`) |
| Patients / Orders / Scheduling / Telehealth | various | nurse, ward_nurse | 200 |
| Timesheets / service agreements | *none exists* | coordinator | **404 on 9 probed URLs** (`P4-M2`) |
| Nurse PWA | `/nurse-pwa/` | nurse | see the PWA table below |
| Nurse API | `/api/nurse/{login,day-pack,sync}` | nurse | see `P4-C1`, `P4-H1` |

### The PWA / offline dimension — what was actually driven

The PWA is a separate Vue app (`nurse-pwa/`, built to `public/nurse-pwa/`) served from the Laravel
app's own origin, talking to `/api/nurse/*` with a Sanctum Bearer token.

| # | Offline scenario | Driven? | Result |
|---|---|---|---|
| 1 | Install / load / service-worker registration | ✅ | **Registers and activates.** `sw.js` scope `/nurse-pwa/`, state `activated`, controls the page; `manifest.webmanifest` present |
| 1b | Login on the **default origin** (`127.0.0.1:8000`) | ✅ | ❌ **419 CSRF — login impossible** (`P4-C1`) |
| 2 | App opens offline, assigned visit list renders | ✅ | ✅ Works — day pack, patient detail, allergies, meds, problems, care goals, vitals history all render with no network |
| 2b | Record care offline (vitals, note) | ✅ | ✅ Queued; counter increments honestly ("Pending offline actions: 1") |
| 3a | Survives reconnect and reaches the server | ✅ | ⚠️ Only if the session never broke — see 3b |
| 3b | **Survives a page reload while offline** | ✅ | ❌ **Permanently stranded** (`P4-C2`) |
| 3c | Duplicated if sync runs twice / user resubmits | ✅ | ✅ Replay of the same `client_uuid` is deduped — **but one gesture already queues two actions** (`P4-C5`) |
| 3d | Server changed the visit while nurse was offline | ✅ | ✅ **Detected and refused** — `schedule_changed_server_wins` |
| 3e | Accurate user-visible indication of unsynced work | ✅ | ⚠️ Count is right; state messages contradict each other (`P4-M8`), and after a wipe it reads `0` for work that was destroyed (`P4-C3`) |
| 4 | Honest offline / pending / failure states | ✅ | ⚠️ No connectivity indicator at all (`P4-M6`); no false "Saved" — the wording is "Pending", which is correct |
| 5 | What is cached, and is it only this nurse's patients | ✅ | ✅ **Correctly scoped** — day pack returned 1 visit / 1 patient / 3.9 KB, not the patient list |
| 5b | Expired session offline → reconnect with queued work | ✅ | ❌ **Queued care silently deleted** (`P4-C3`) |

**How the offline dimension was reached, stated plainly.** `P4-C1` blocks PWA login on the app's own
origin. To answer questions 2–5 at all I ran the *same, unmodified* application on a second port
(`127.0.0.1:8123`, `artisan serve`) — an origin **not** in `SANCTUM_STATEFUL_DOMAINS` — where login
returns 200. **No application code, config, test or seeder was changed.** Every offline result below
was observed on that origin; `P4-C1` itself was reproduced on the default origin, in the browser,
with zero cookies.

---

### CRITICAL

#### `P4-C1` — The Nurse PWA cannot authenticate or sync from the origin it is served on

- **Role:** `nurse` · **Route:** `POST /api/nurse/login`, `POST /api/nurse/sync`
- **Steps:** open `http://127.0.0.1:8000/nurse-pwa/`, enter
  `hans.brunner@spitex-sonnengarten.test` / `demo-password`, press **Sign in**.
- **What happened:** the form silently returns to "Nurse login". The network call is
  **`419 CSRF token mismatch`**. Reproduced in the browser after clearing every cookie,
  `localStorage`, `sessionStorage` and IndexedDB — response body
  `{"message":"CSRF token mismatch."}`.
- **The asymmetry is the dangerous part.** With a browser `Origin` header:

  | Call | Status |
  |---|---|
  | `GET /api/nurse/day-pack` | **200** — patient data downloads to the field device |
  | `POST /api/nurse/sync` | **419** — recorded care can never be uploaded |
  | `POST /api/nurse/sync` *without* `Origin` (control) | 422 — the endpoint itself works |

  CSRF applies only to state-changing verbs, so **PHI flows out to the device and nothing can come
  back**.
- **Cause:** `bootstrap/app.php:28` calls `$middleware->statefulApi()`, applying Sanctum's
  `EnsureFrontendRequestsAreStateful` to every `api` route. `config/sanctum.php:21` lists
  `127.0.0.1:8000` / `localhost` as stateful domains — **the very origin the PWA is served from**
  (`public/nurse-pwa/`). The client uses plain same-origin `fetch()` with **no CSRF handshake**
  (`nurse-pwa/src/api.ts:24,46,60,90` — no `/sanctum/csrf-cookie`, no `X-XSRF-TOKEN`, no
  `credentials`). Proven by isolation: identical request **without** `Origin` → 200 + Bearer token;
  **with** `Origin` → 419; and from a non-stateful port (`:8123`) → 200.
- **Why CRITICAL:** the entire offline nursing product is unusable as deployed, and the one direction
  that *does* work is the one that puts patient data on a phone.


> ✅ **FIXED — QA-FIX.4a, commit `ba7ddec` (D-201).** `statefulApi()` is **removed** from
> `bootstrap/app.php`; the API is token-only, which is what all six of its routes already were.
>
> - **The study found no cookie client to protect.** Every `api/*` route authenticates with a Sanctum
>   personal access token (`nurse:day-pack` ability, 12 h expiry) and the Nurse PWA is the **only**
>   consumer — the Inertia app, the patient portal and the kiosk all use `web` routes.
>   `routes/api.php`'s own docblock already described them as token-authenticated. The removed line
>   had been added speculatively "for the future PWA / SPA token auth", which is self-contradictory:
>   `statefulApi()` enables **cookie** auth, which token auth does not use.
> - **Option (b) — making the client do the CSRF handshake — was rejected on architecture.** It would
>   turn a token client into a cookie client, put a session cookie on a field phone, make the
>   `tokenCan` ability model redundant, and give the offline story a primitive that cannot survive a
>   reload. Option (a) matches what the routes already do.
> - **This was never local-only.** `SANCTUM_STATEFUL_DOMAINS` derives from `APP_URL`, and the PWA is
>   served from `public/nurse-pwa/` on that same host — so the 419 would occur in production too.
> - **Nothing else changed posture.** CSRF for the Inertia app, the portal and the kiosk lives in the
>   `web` group and is untouched; a test asserts `web` still contains `ValidateCsrfToken`. The token
>   guard still refuses a missing token, a bad password and a token lacking the ability.
> - **Verified in a real browser on the DEFAULT origin `127.0.0.1:8000`** — the origin Phase 4 could
>   not use at all. Before: `POST /api/nurse/login` → **419**, the form silently reset. After: login
>   **200**, the day pack rendered Margrit Ackermann's visit, and a sync round-trip completed with
>   **`POST /api/nurse/sync` → 200**. The workaround port (`:8123`) was **not** used.
> - **An honest note on what guards this.** Restoring `statefulApi()` reddens only the
>   middleware-composition assertion; the request-level tests keep passing because Laravel's
>   `ValidateCsrfToken` self-skips under `runningUnitTests()`, so no feature test can observe the 419
>   a browser gets. The structural assertion is the guard and the browser is the proof — recorded in
>   the test file itself so a later reader does not mistake the HTTP tests for regression guards.

#### `P4-C2` — A page reload permanently strands every piece of care already recorded offline

- **Role:** `nurse` · **Surface:** Nurse PWA
- **Steps (driven, single clean action):** log in online → go offline (`navigator.onLine === false`,
  fetches throw) → record vitals **systolic 199 / diastolic 91** → "Pending offline actions: **1**",
  1 outbox row in IndexedDB → **reload the page** → log back in → press **Sync**.
- **What happened:** after the reload the nurse is returned to the **login screen**. After
  re-login the UI reads "Pending offline actions: **1** — Sync needs retry.", the outbox row is
  still in IndexedDB, and **no `POST /api/nurse/sync` request is ever issued** (confirmed in the
  network log). Server-side: `visit_vitals` still 36, **systolic 199 absent**, `nurse_sync_actions`
  0. Repeating the sync changes nothing, for ever.
- **Cause:** `nurse-pwa/src/storage/dayPackStore.ts:38-57` derives the AES-GCM key with HKDF **from
  the session token** and holds it only in module memory (`let sessionKey`); the token itself is
  likewise memory-only (`api.ts:21 let bearerToken`). A reload clears both. A new login mints a
  **new token → a different key**, so ciphertext written under the old key can never be decrypted
  and the outbox can never be replayed. The same cause leaves the day pack unreadable — the UI shows
  **"No synced visits."** even though the day-pack fetch returned 200.
- **Why CRITICAL:** documented patient care is destroyed by an ordinary phone event (tab evicted,
  browser restarted, device sleep). The encryption design is otherwise good; the defect is that the
  **key lifetime is shorter than the data's**.


> ✅ **FIXED — QA-FIX.4c, commit `3710efe` (D-203)** — jointly with `P4-C3`, which shares its
> root cause. The full banner is under `P4-C3` below: the day-pack cache and the outbox were sharing
> a key whose lifetime only suited the cache, so a reload stranded the queue for ever. The outbox now
> uses a device-lifetime non-extractable key and survives.

#### `P4-C3` — Reconnecting with an expired session silently DELETES all unsynced care

- **Role:** `nurse` · **Surface:** Nurse PWA
- **Steps (driven):** log in → offline → record vitals **systolic 177 / diastolic 91** → "Pending
  offline actions: **1**", 1 outbox row → revoke the token server-side (exactly what a **12-hour
  token expiry** does — `expires_at` is 12 h from login) → regain signal → press **Sync**.
- **What happened:** the **entire local store is deleted** — `outboxRows: 0`, `totalRows: 0`, the
  IndexedDB store is empty. The UI then reads **"Pending offline actions: 0"**. Server-side:
  **systolic 177 never arrived** (vitals still 36). The recorded care is gone, with no export, no
  warning, no confirmation, and a counter that now says there is nothing outstanding.
- **Cause:** `nurse-pwa/src/api.ts:100-104` — on `401` or `403` the client calls
  `wipeLocalStore()` and throws `nurse.sync.revoked`, **destroying every queued action before it has
  ever been transmitted**. The intent is plainly a security one (drop PHI when the session dies), but
  it is applied to the *outbox* as well as the *cache*, so it deletes the only copy of work that has
  never reached the server.
- **Why CRITICAL:** this is the gate's explicit trigger — lost recorded care — and it is the most
  likely of all these paths to occur in the field, because a 12-hour token and a day-long shift with
  poor signal are exactly the intended use.


> ✅ **FIXED — QA-FIX.4c, commit `3710efe` (D-203).** `P4-C2` and `P4-C3` shared one root cause and
> are closed together: **the day-pack cache and the outbox were sharing a key whose lifetime only
> suited the cache.**
>
> - **The threat model first.** The encryption exists for a **lost or stolen field device** —
>   ciphertext at rest must not hand an attacker the patient's allergies, medications, problems and
>   vitals history. Deriving the key from the session token bought that for free: close the tab and
>   the cached PHI is unreadable, with no key stored anywhere.
> - **The defect was a lifetime mismatch, not the encryption.** The cache is a *copy* of server data
>   and may be destroyed freely. The outbox is the **only** copy of care the nurse has already
>   recorded and the server has never seen. One key was serving both, so the property that correctly
>   protects the cache is exactly what stranded (`P4-C2`) and then deleted (`P4-C3`) the queue.
> - **The fix splits them.** The day pack keeps the session-derived, memory-only key and is still
>   cleared on wipe. The outbox is encrypted under a **device-lifetime AES-GCM key, generated
>   `extractable: false` and stored as a `CryptoKey` in IndexedDB** — usable on this device, never
>   readable out. `wipeLocalStore()` now removes the cached records and clears the session key while
>   **preserving** un-transmitted work.
> - **Residual risk, stated rather than minimised.** An attacker with the unlocked device who can run
>   script in this origin can now read **queued** entries — what this nurse recorded on this round —
>   where previously they could read nothing at rest. They still cannot read the day-pack cache,
>   cannot extract the key, and the outbox drains to empty on a successful sync. The trade is
>   deliberate: **silently destroying documented patient care is the worse failure.**
> - **A behaviour change worth naming:** the idle-timeout wipe and explicit logout also stop
>   destroying un-transmitted work, so queued care now survives a logout and syncs on the nurse's
>   next sign-in.
> - **Legacy rows are skipped, not deleted.** Outbox rows written by the broken build are encrypted
>   under a session key that no longer exists and were already unrecoverable; replay now skips
>   records it cannot decrypt instead of throwing — which also stops one unreadable row jamming every
>   later action (the `P4-H1` shape).
> - ⚠️ **WHAT THIS DOES NOT FIX, recorded rather than folded in silently:** a nurse who reloads
>   **while offline** still cannot re-enter the app, because login requires the network. Their
>   recorded care is now safe and syncs on the next sign-in, but the app is not usable offline after
>   a reload.
> - **Ten tests, mutation-checked twice:** restoring the destructive wipe reddens the three `P4-C3`
>   tests; putting the outbox back on the session key reddens **eight**, including two pre-existing
>   ones. Positive controls assert the cache **is** still wiped, that **no plaintext PHI** reaches
>   the device, that the device key is non-extractable and `exportKey` rejects, and that a successful
>   sync still **drains** the queue.
> - **Verified in a real browser on the DEFAULT origin** — both Phase-4 scenarios re-driven; see the
>   gate report for the before/after and for what the offline-flag limitation allowed.

#### `P4-C4` — Every device timestamp is stored as wall-clock in a UTC column (11 write sites)

- **Role:** `nurse` · **Surface:** `POST /api/nurse/sync`
- **Steps:** sync a `check_in` with `device_timestamp: 2026-09-06T07:35:00+02:00` (= **05:35:00 UTC**).
- **What happened:** the row stored **`2026-09-06 07:35:00`**. `config('app.timezone')` is `UTC`, so
  the column is UTC and the correct value is `05:35:00`. **The offset is discarded and the device's
  local wall-clock is written — a 2-hour error** for a Swiss nurse in summer.

  | | value |
  |---|---|
  | sent by device | `2026-09-06T07:35:00+02:00` |
  | correct UTC | `2026-09-06 05:35:00` |
  | **stored** | **`2026-09-06 07:35:00`** |
  | CLI `now()` at the time | `2026-09-06 15:39:26` UTC |

- **Cause:** every device timestamp is assigned as a **raw string** to a datetime column —
  `(string) $action['device_timestamp']` — instead of being parsed to UTC.
  `Modules/Nursing/src/Services/NurseSyncService.php` lines **215, 246, 302, 348, 394, 436, 465,
  503, 564, 596, 677**, covering `occurred_at` (EVV check-in/out events), `recorded_at` (vitals,
  notes, observations), `completed_at` (task completion), `captured_at` (attachments) and the ledger's
  own `device_timestamp`.
- **Why CRITICAL:** these are the times that say **when care was delivered**. For Spitex they are the
  EVV record underpinning billing and verification. This is the *same defect class* as `P1-C1`
  (D-192/D-193, fixed by `QA-FIX.1a` for web requests) on a path that gate never touched — and here
  the clock belongs to a phone the server does not control.


> ✅ **FIXED — QA-FIX.4b, commit `ce2ebaa` (D-202).** Device times are now parsed to UTC at **one**
> boundary (`NurseSyncService::normaliseDeviceTimes()`), before dispatch and inside the existing
> per-action transaction. All eleven call sites are unchanged — they receive a value with no offset
> left to misinterpret.
>
> ⚠️ **CORRECTION TO THIS FINDING'S SEVERITY, recorded rather than quietly edited.** The finding
> above is written as though every stored EVV time were currently two hours out. **It is not.** The
> shipped PWA sends `new Date().toISOString()` (`nurse-pwa/src/storage/dayPackStore.ts:74`) — always
> a `Z` instant — which Carbon parses as UTC, so **rows written by the shipped client were already
> correct**, and the incident's `occurred_at` is normalised client-side the same way. The `+02:00`
> evidence in the steps above came from a **curl-crafted action**, not from the product's own client.
> The defect is therefore **LATENT rather than ACTIVE**: the server had no defence and was correct
> only by accident of a client convention. Still worth fixing — these are the EVV times that justify
> Spitex billing, and a second client, a native wrapper, or a changed client default would corrupt
> them silently — but "every device timestamp is stored as wall-clock" overstated what was happening
> in practice, and the record is corrected here.
>
> - **The mechanism, measured.** These columns are cast `'datetime'`, so Eloquent parses with Carbon
>   and serialises with `format('Y-m-d H:i:s')` **in the Carbon's own timezone**: `+02:00` → `07:35`,
>   `-05:00` → `00:35`, `Z` → `05:35`. Only the last is the true instant. A *raw*
>   `DB::table()->insert()` of either ISO form throws `Incorrect datetime value` under strict mode —
>   the corruption is specifically the Eloquent-cast path, which is why it looked like a clean write.
> - **Policy (D-170).** The device's stated instant is recorded as given and converted to UTC. **No
>   trust window, no clock-skew correction** — the product has no basis to judge a device clock.
>   `device_timestamp` is already validated `['required','date']`; the incident's `payload.occurred_at`
>   is **not** (payload contents never are — `P4-H1`), so an unparseable value is **rejected** with
>   `validation_failed` rather than silently replaced by `device_timestamp`, which would record the
>   incident at a time the reporter never stated (D-176 / D-179).
> - **No historical rewrite** (D-193 / D-197) — and nothing to rewrite: the shipped client's rows were
>   already correct UTC instants.
> - **Ten tests, every fixture using a non-zero offset** (a `Z` fixture would prove nothing — D-174).
>   The sharpest asserts *the same instant sent three ways* (`+02:00`, `-05:00`, `Z`) stores **one**
>   value. Mutation-checked: neutering the conversion reddens **8 of 10**, and the two that stay green
>   are exactly the positive controls.
> - **Verified in a real browser on the DEFAULT origin** — a live sync from the PWA, with the stored
>   row compared against CLI `now()` in UTC exactly as Phase 4 measured it.

#### `P4-C5` — One "Save note" gesture writes TWO identical clinical notes

- **Role:** `nurse` · **Surface:** Nurse PWA → visit detail → Note
- **Steps (one natural gesture):** click the note textarea, type
  `QA4-DUPPROBE single note typed once and saved once`, click **Save note**, then **Sync**.
- **What happened:** "Pending offline actions: **2**" from a single note, two outbox rows, and after
  sync **two identical `visit_notes` rows on the server** (37 → 39) — same body, same
  `recorded_at 2026-09-06 15:40:24`, different ids.
- **Cause:** `nurse-pwa/src/App.vue:382` binds `@change="saveNote(selectedVisit)"` on the textarea
  **and** `:384` binds `@click="saveNote(selectedVisit)"` on the button. Clicking the button blurs
  the textarea, so **both** handlers fire. Each call enqueues a fresh action with its own
  `client_uuid`, so the server's `client_action_uuid` dedupe (which is otherwise correct — see
  *guards holding*) cannot collapse them: they are, as far as it can tell, two distinct notes.
- **Why CRITICAL:** duplicated recorded care in the patient record, from the single most common
  action a field nurse performs. A duplicated nursing note is a clinical-record integrity defect, not
  a cosmetic one.


> ✅ **FIXED — QA-FIX.4d, commit `6f48c24` (D-204).** One gesture now records one note.
>
> - **The shape is unique to the note, and that was checked rather than assumed.** Every other
>   control has exactly one handler — vitals, incident, signature and both task buttons are `@click`
>   only, and the photo input's `@change` is its only binding (correct for a file input). Nothing
>   else needed changing.
> - **`@change` is not a mistake, so it was not deleted.** The handler is named `autosaveVisitNote`:
>   autosave-on-blur is deliberate for a field app, where a nurse who taps away mid-note should not
>   lose it. Deleting it would fix the duplicate by removing a real behaviour; deleting the button
>   would remove the affordance a nurse expects to press. **Both are kept** and the save is made
>   **idempotent for unchanged text** — the second event of one gesture has nothing new to record.
> - **Deliberately NOT a general "dedupe identical consecutive actions" rule.** Two identical vitals
>   readings minutes apart are legitimately **two observations**; suppressing the second would DROP
>   recorded care — the failure this gate exists to fix. A test pins that. The guard is scoped to the
>   note draft and keyed by visit, so the same sentence on a different patient is still a real note.
> - ⚠️ **The first version of this fix was wrong, and only the browser caught it.** The guard
>   originally recorded the memo AFTER awaiting the enqueue. Sequentially that is fine and it passed
>   every unit test — but `@change` and `@click` are in flight at the same time, so the second call
>   read the stale memo and enqueued anyway: driven in a real browser it produced **two notes exactly
>   as before**. The memo is now claimed BEFORE the await, and rolled back if the enqueue throws.
>   **This is why the browser step is mandatory** — a green suite described a fix that did not work.
> - **Nine tests**, including the exact gesture, **the concurrent gesture** (`Promise.all` of both
>   handlers), a failed enqueue not poisoning the memo, autosave-alone still recording,
>   editing-after-saving still recording, and the identical-vitals control. Mutation-checked twice:
>   removing the guard reddens the gesture tests; restoring the after-await ordering reddens the
>   concurrent one.
> - **Verified in a real browser on the DEFAULT origin** — note typed once, Save pressed once, synced,
>   and the server counted **exactly one** `visit_notes` row where Phase 4 measured two.

---

### HIGH

#### `P4-H1` — `/api/nurse/sync` returns 500 on two reachable inputs, and one bad action jams the queue for ever

- **Role:** `nurse` · **Route:** `POST /api/nurse/sync`
- Two crash paths, both driven:

  | input | response |
  |---|---|
  | `check_in` whose payload omits `client_visit_uuid` | **500** `Undefined array key "client_visit_uuid"` |
  | `check_in` on a visit already checked in (new `client_uuid`) | **500** `Only scheduled visits can be checked in.` |

- **Cause (first):** `NurseSyncService.php:204` reads the key **defensively** for the lookup —
  `$payload['client_visit_uuid'] ?? ''` — and `:208` dereferences **the same key undefensively** on
  the create path, three lines later. `NurseSyncController.php:28-33` validates only the action
  *envelope* (`client_uuid`, `type`, `payload` is-an-array, `device_timestamp`, `sequence`); payload
  contents are never validated, so a malformed action reaches the service.
  **Cause (second):** a domain exception from the visit state machine escapes as a 500 instead of the
  service's own clean `rejected` result, which every other refusal path uses correctly.
- **The jam is the real damage.** `nurse-pwa/src/api.ts:83-113` sends the **entire outbox as one
  batch** and, on any non-OK response, **removes nothing** (`throw new Error('nurse.outbox.failed')`).
  A single malformed or un-satisfiable action therefore **permanently blocks every other action on
  that device**: each retry resends the same batch, hits the same 500, and nothing ever drains.
  `syncOutboxWithRetry` retries 3× with backoff and cannot help, because the input never changes.
- **Why HIGH:** a poison pill in a field device's care queue, reachable from ordinary input.

#### `P4-H2` — A crashed sync batch commits part of itself while telling the device everything failed

- **Role:** `nurse` · **Route:** `POST /api/nurse/sync` · **This is cross-phase pattern 6's second instance.**
- **Steps:** send one batch containing `[a valid visit_note, a check_in missing client_visit_uuid]`.
- **What happened:** HTTP **500** with **no `results` array** — the client is told the whole sync
  failed. Server-side the **good note committed**: `visit_notes` 36 → 37, the row is present, and its
  ledger entry `qa4-good-note-1` is `accepted`.
- **Cause:** `NurseSyncService.php:97` wraps **each action** in its own `DB::transaction`, so
  completed actions are durable before a later action throws; the throw then escapes
  `NurseSyncController.php:36` and the whole response is replaced by a 500. There is no batch-level
  transaction and no partial-result envelope.
- **Consequence:** the device keeps those actions queued for ever (per `P4-H1`), so the pending count
  permanently **overstates** unsynced work while the server already holds it. Idempotency limits the
  damage — a retry would dedupe by `client_uuid` rather than duplicate the note — so this is HIGH,
  not CRITICAL. **It is the same shape as `P3-C1`:** a refused operation leaving a partial record,
  now in the offline queue, exactly where the gate predicted it would recur.

#### `P4-H3` — The PWA cannot check in or out of a visit, so no visit can ever be started or closed from the field

- **Role:** `nurse` · **Surface:** Nurse PWA visit detail
- **What I did:** enumerated **every** control on the visit detail screen in the browser.
- **What happened:** the complete set is *Sync, Log out, the visit selector, 7 vitals number inputs,
  Queue vitals, a note textarea, Save note, a photo file input, Queue signature, an incident
  datetime/category/severity/notes group, Queue incident* — plus task done / not-done buttons when
  tasks exist. **There is no check-in and no check-out control.**
- **Cause:** `nurse-pwa/src/App.vue` imports `queueTaskDone`, `queueTaskNotDone`, `queueIncidentReport`,
  `queueVisitPhoto`, `queueVisitSignature`, `queueVisitVitals` and a note action — but **never
  `check_in` or `check_out`**, which the server fully implements
  (`NurseSyncService.php:103-104`), together with EVV location / accuracy / manual-reason handling.
- **Consequences, all observed:** the day pack reports `execution_visit_id: null` and `status:
  assigned`; no execution `Visit` is ever created from the field; **EVV is unreachable** (see the
  fence note below — the model is good and nothing can feed it); and queued vitals/notes reference a
  visit that does not yet exist. This is also why the seed contains no in-progress or missed visits.
- **Why HIGH:** starting and closing a visit is *the* core operation of a home-care round, and it is
  the one thing the field app cannot do.


> ✅ **FIXED — QA-FIX.4e, commit `e7fc442` (D-205).** Check in / Check out are wired to the
> **existing** server actions through the **existing** offline queue.
>
> - **The fix-or-feature call, made before writing code: this is WIRING.** The client already
>   produced the exact payload both handlers need (`baseVisitPayload()` emits `planned_visit_id`,
>   `visit_id`, `client_visit_uuid`, `nurse_resource_id`, `patient_id`) and
>   `enqueueOutboxAction(type, payload)` is generic — so the queue, sync, retry, replay idempotency
>   and the QA-FIX.4c device-key encryption all apply unchanged. **What would have made it a feature,
>   and a STOP, is GPS capture** — permission prompts, accuracy handling, a location UI, a distance
>   threshold. None was added.
> - **EVV honesty is preserved exactly as Phase 4 verified it.** The server accepts *either* a
>   `location` (whose GPS fields it then requires) *or* a `manual_reason`. This client captures no
>   GPS, so it states that and sends no coordinates: `manual_reason` is stored and `location`,
>   `accuracy_meters` and `distance_meters` stay **NULL**. No position is fabricated (D-176/D-179)
>   and no accuracy or distance threshold is invented (D-170). The screen says so too — *"No location
>   is captured on this device; the visit records the time and the stated reason only."*
> - **Only the action the visit's state allows is offered.** `VisitService` throws on an out-of-order
>   transition and `P4-H1` turns an escaped throw into a 500, so the UI shows Check in, or Check out,
>   or neither — driven by `execution_visit_id` plus what this device has queued since (offline, a
>   queued check-in has not reached the server). **This does not fix `P4-H1`, which stays open** — it
>   stops the client walking into it.
> - **Six server tests and six client tests**, including **the full field round** (check in → vitals
>   → note → check out, all accepted, visit `completed`), the EVV honesty assertion, and two positive
>   controls: cross-assignment still refused with `schedule_changed_server_wins` and **no `Visit`
>   created**, and QA-FIX.4b's UTC boundary still holding on a check-in.
> - **Consequence:** `in_progress` and `completed` visits can now exist from the field at all, which
>   is why the Phase-4 seed contained none.
> - **Verified in a real browser on the DEFAULT origin** — a full round driven online and with an
>   offline segment; see the gate report.

#### `P4-H4` — Every nursing time is rendered as raw UTC, so a field nurse reads their visit two hours early

- **Roles:** `nurse` (PWA), `coordinator` (dispatch board) · **Cross-phase pattern 2, fourth phase.**
- **What happened:** the visit window renders as **`2026-09-06T05:30:00+00:00 - 2026-09-06T06:30:00+00:00`**.
  The tenant is `Europe/Zurich`; the actual appointment is **07:30–08:30 local**. Of **32 timestamps**
  on the PWA screen, **all 32** are raw ISO/UTC and **none** is Swiss format
  (`/\d{2}\.\d{2}\.\d{4}/` → no match). The dispatch board shows the same visit as
  `2026-09-06 05:30:00 - 06:30:00`, also raw UTC, also no Swiss format.
- **Why HIGH rather than MEDIUM:** in every earlier phase this pattern produced a *confusing* date.
  Here it produces an **operationally wrong one**: a nurse reading their round sees a time two hours
  before the appointment. It also compounds `P4-C4` — the write side stores local-as-UTC while the
  read side prints UTC-as-local, so the two errors are in opposite directions and neither cancels.

#### `P4-H5` — Every nursing role's landing page offers links that role cannot open

- **Roles:** all four · **Route:** `/app` · **Cross-phase pattern 1, fourth phase.**
- **Measured in the browser:**

  | Role | Offered quick actions that 403 |
  |---|---|
  | `nurse` | `/patients/register`, `/nursing/dispatch`, `/comms/inbox` — **all three** |
  | `coordinator` | `/patients/register`, `/comms/inbox` |
  | `ward_nurse` | `/patients/register`, `/nursing/dispatch`, `/comms/inbox` — **all three** |
  | `charge_nurse` | `/patients/register`, `/nursing/dispatch`, `/comms/inbox` — **all three** |

- **What Phase 4 adds is that the page already has the answer.** The Inertia payload for `/app`
  carries an explicit permission map — for the nurse, `"dispatch.manage": false` and
  `"comms.manage": false` — and the page still renders both links.
- **Cause:** `resources/js/pages/App/Landing.vue:171,174,177` render the three quick actions with
  **no `v-if`**, while the same file's KPI row at `:87` is commented *"shown per the actor's
  permissions"* and **is** gated. One file gates one section and not the other.
- The 403 page itself is graceful and honest ("You don't have access to this area… Back to
  dashboard") — the defect is purely that the link is offered.

---

### MEDIUM

- **`P4-M1` — No navigation whatsoever below 768 px, and this phase's primary device is a phone.**
  At 390×844 as `nurse`, all five primary nav links (`/app`, `/patients`, `/clinical/orders/review`,
  `/scheduling/day-board`, `/telehealth`) measure **0×0**, and there is **no menu button**
  (`burgerCount: 0`); Search and Notifications are `hidden`. The header is reduced to "CareOS · HB ·
  Sign out". No horizontal overflow. **Cross-phase pattern 3, fourth phase** — but unlike earlier
  phases this is not a polish issue: the nursing group's own app is a phone surface.
- **`P4-M2` — `timesheet.approve` and `agreement.manage` are granted to `coordinator` with no surface.**
  Driven: nine candidate URLs (`/nursing/timesheets`, `/timesheets`, `/nursing/agreements`,
  `/nursing/service-agreements`, `/agreements`, …) all **404**, and the coordinator's entire
  navigation is `/app`, `/patients`, `/scheduling/day-board`, `/nursing/dispatch`, `/reporting`,
  `/nursing/competencies`. **No controller anywhere enforces either permission.** `TimesheetService`
  and `ServiceAgreementService` are fully built, and the seed carries **36 timesheet lines and 5
  service agreements** that nothing in the product can display. **Cross-phase pattern 4, fourth phase.**
- **`P4-M3` — `note.supervise` is granted to `charge_nurse` and enforced by nothing.** No controller
  references it; no supervise/countersign affordance exists on any surface driven. Same pattern.
- **`P4-M4` — The ward board is reachable but unreferenced.** `/hospital/wards` returns 200 for both
  `ward_nurse` and `charge_nurse`, yet the string `hospital/wards` appears **nowhere in
  `resources/js/`** — it is in no nav, no card, no breadcrumb. Both roles must type the URL. This is
  the inverse of `P4-H5`: there, a link with no permission; here, a permission with no link.
- **`P4-M5` — The outbox sequence is allocated non-atomically and collides.**
  `dayPackStore.ts:194` computes `Math.max(...entries.map(e => e.sequence)) + 1` — a read-then-write
  with no atomicity. Observed twice in the browser: two entries at `sequence 000000000002`, and later
  two at `000000000001`. The server **sorts the batch by this value**
  (`NurseSyncService.php:76 ->sortBy(... 'sequence')`), so colliding entries are applied in arbitrary
  order. For a "task not done" followed by a "task done", or a corrected vitals reading, the applied
  outcome is undefined. **D-191 shape** — an ordering column whose meaning is real but whose
  uniqueness is not guaranteed.
- **`P4-M6` — The PWA never says whether it is online.** The status text is **identical** online and
  offline ("Offline day-pack ready" is a static label about the cache, not a connectivity state).
  Verified by capturing the same region before and after `setOffline(true)`. A field nurse cannot
  tell from the screen whether their work is reaching the server.
- **`P4-M7` — `locale` is `de` and the entire UI is English.** The `/app` payload carries
  `"locale":"de"` and dates render in German ("Sonntag, 6. September 2026"), while every string
  renders in English ("Welcome to your CareOS workspace", "QUICK ACTIONS"). `resources/js/lang/`
  contains **only `en.json`** — there is no German catalogue, so every key falls back. A Swiss
  home-care product ships a German-locale tenant with an English-only interface.
- **`P4-M8` — The two sync status lines contradict each other.** After the `P4-C3` wipe the header
  reads **"Pending offline actions: 0 … Sync needs retry."** simultaneously — nothing pending, yet
  retry needed. The same pair appears transiently after re-login. Whichever is true, both cannot be.
- **`P4-M9` — The `nurse` role has no nursing surface in the web app at all.** The only nursing web
  routes are `nursing/dispatch` (+assign/unassign), `nursing/competencies` (+5 mutations) and
  `hospital/wards` — dispatch and competencies are `coordinator`-only and 403 for `nurse`. A field
  nurse's entire operational surface is the PWA, which means `P4-C1` leaves them with **nothing**.
- **`P4-M10` — The visit detail renders a "Tasks" heading with no content and no empty state.** The
  day pack returned `tasks: []` for the driven visit and the section renders as a bare heading, with
  no "no tasks" message — indistinguishable from a failed load.

### LOW

- **`P4-L1` — "Last synced" prints raw millisecond ISO** (`2026-09-06T15:20:10.761Z`) to a field
  nurse, in a UI with no other machine-readable field.
- **`P4-L2` — 500 responses from `/api/nurse/sync` return the full Laravel stack trace** (exception
  class, absolute Windows file paths, ~60 frames) to the device. Debug-mode behaviour, but this is
  the API a phone talks to.
- **`P4-L3` — The PWA login failure surfaces only as a console `Error: nurse.login.failed`.** On the
  default origin the form simply resets with **no message on screen** — the user is told nothing at
  all (this is how `P4-C1` presents to a real nurse).

---

### Guards verified holding

Driven in the browser, not inferred:

- **Day-pack scoping is correct.** `GET /api/nurse/day-pack` as Hans Brunner returned **one visit,
  one patient, 3 942 bytes** — his own assigned visit for the date, with that patient's clinical
  detail. It is **not** the tenant's patient list. The endpoint takes only a `date`; the nurse is
  resolved server-side from the token via `StaffProfile` → `Resource`, so there is no parameter to
  tamper with.
- **Cross-assignment is refused, two ways.** Hans attempting `check_in` on a planned visit assigned to
  Verena Huber → **`rejected / schedule_changed_server_wins`**. Hans attempting `visit_vitals` on her
  visit while **forging `nurse_resource_id` to her resource id** → **`rejected / visit_not_found`**.
  `NurseSyncService::scheduleChanged()` (`:762-772`) checks both `visit->resource_id` and
  `plannedVisit->assigned_resource_id` against the *token's* resource set.
- **Idempotency holds.** Replaying an identical action (same `client_uuid`) produced **one** visit
  (36 → 37), **one** ledger row, and returned the recorded result rather than writing again.
- **No plaintext PHI on the device.** IndexedDB rows are `{id, iv, ciphertext, updatedAt}` only; a
  search of the whole store for the note text I had just typed found **nothing**
  (`hasPlaintext: false`). AES-GCM with an HKDF-derived key — the design is sound; only its key
  lifetime is wrong (`P4-C2`).
- **D-169 holds.** A **severe** penicillin allergy with "Anaphylaxis requiring adrenaline and hospital
  admission" renders in `rgb(15,23,42)` on a transparent background at font-weight 400 — **byte-identical
  styling to the neutral "Penicillin" text beside it**. Severity is recorded, never tinted.
- **The clinical fence is absolute on nursing surfaces.** `nurse-pwa/src/vitalsDisplay.ts` returns raw
  values over time and its own docblock states it "never computes or attaches a band, range, flag,
  normal/abnormal marker, score, arrow, or delta". Confirmed on screen: systolic 126/134/131/128/136
  render as plain numbers with dates. A sweep for `risk score` / `acuity` / `fall risk` / `suggest` /
  `recommend` / `predict` / `ai-` across **the whole PWA source and the whole Nursing module** returns
  **zero** hits. No computed acuity, no fall score, no auto-generated care suggestion.
- **EVV records honestly rather than inventing.** `visit_events` carries `location`, `location_index`,
  `accuracy_meters`, `location_source`, `manual_reason`, `distance_meters`, `recorded_by`. A check-in
  supplying a `manual_reason` and no GPS stored **`manual_reason = "QA4 audit"` with location NULL** —
  it records the absence of a location instead of fabricating one. (The model is good; `P4-H3` means
  nothing in the field app can feed it, and `P4-C4` means its times are wrong.)
- **The ward board's view/mutate split is correct, proven by positive control.**
  `WardBoardController:44` gates viewing on `patient.view` and `:124` gates mutation on `bed.manage`.
  Driven: `ward_nurse` (no `bed.manage`) sees the board with **zero buttons**; `charge_nurse` (with it)
  sees **"Mark free" / "Block"** on every bed. The absence is genuinely permission-driven, not a
  missing feature.
- **Tenant scoping on sync is fail-closed.** `NurseSyncService::nurseResources()` throws 403 if the
  token's `tenant_id` does not match the resolved tenant, before any action is processed.
- **The pending counter does not lie about what it can see, and never claims "Saved".** Queuing one
  vitals action showed "Pending offline actions: 1" and a failed sync says "Sync needs retry" — the
  wording is honest about work being *pending*, not stored. **No D-179 breach in the happy path.**
  (Its failure mode is `P4-C3`, where the count is right about an empty store that should not be empty.)

### Not testable, and why

- **A `missed` visit.** No product path reaches that state — the PWA cannot check in or out
  (`P4-H3`) and no web surface transitions a nursing visit. Recorded rather than skipped.
- **Medication administration as a distinct act.** The day pack carries the patient's medication list
  (read-only, correctly), but there is **no administration action** in the client's action set and no
  `medication_administered` type on the server. Nursing med-admin does not exist as an operation to
  drive; the nearest available act is a free-text note.
- **Offline behaviour across a reload with the offline flag held.** Playwright's
  `context.setOffline(true)` did **not** survive `page.reload()` in this MCP build, so the
  post-reload state in `P4-C2` was observed with connectivity **restored**. This makes the finding
  stronger, not weaker — the work is stranded even with a working network — but the strictly-offline
  reload is stated as unverified rather than claimed.
- **Performance** — out of scope per the gate, deferred to staging.


## Phase 5 — Pharmacy

**Date:** 2026-09-07 · **Top commit at audit time:** `81b7138` (the QA-FIX.4 hash backfill), CI
`completed / success` confirmed via `commits/<sha>/check-runs`. Tree clean apart from untracked
`docs/marketing-site/`.

**AUDIT ONLY.** No app code, test or seeder was changed. The only writes are those made by *driving
the product* (two dispenses, one via each role) plus two allergies recorded through the product's own
`ClinicalListService::recordAllergy()` — see the environment note. All demo tenants were re-seeded
afterwards and verified back to baseline by query.

### Environment

| Item | State |
|---|---|
| Pharmacy tenant | `klinik-bergblick` — the **only** tenant with pharmacy data |
| Formulary | 3 items: Paracetamol, **Amoxicillin**, Enoxaparin (all priced, all with a `tariff_item_id`) |
| Stock | 2 rows (Amoxicillin 97, Paracetamol 196). **Enoxaparin has no stock row** |
| Medication orders | 3 active — Karin Weber (Paracetamol, Enoxaparin), **Greta Zimmermann (Amoxicillin)** |
| Dispenses / charges | 2 / 2 at baseline |
| **Redis** | **UP — honestly.** `PING` → `+PONG` on 127.0.0.1:6379 (Memurai) |
| App timezone | `UTC` storage; tenant `Europe/Zurich`; browser (viewer) `America/Los_Angeles` |
| Out of scope | **Performance — deferred to staging**, per the gate |

**THE FENCE CONTROL HAD TO BE CREATED, AND HOW IT WAS CREATED MATTERS.** The gate requires a patient
with a recorded allergy that plausibly interacts with a stocked drug. `klinik-bergblick` had **zero
allergies** (they exist only in `praxis-lindenhof` and `spitex-sonnengarten`). Greta Zimmermann has an
**active Amoxicillin order**, so a **severe Penicillin allergy — "Anaphylaxis requiring adrenaline and
hospital admission"** — was recorded against her, plus a **mild Latex** allergy later as the D-169
positive control. Both were written through the product's own `ClinicalListService::recordAllergy()`
rather than by seeding — because **the product has no HTTP route or UI control that records an
allergy at all** (`P5-M7`), so the service is the closest thing to its real path. Stated rather than
glossed.

**Batches and expiry: the model has none.** `medication_stocks` is
`id, tenant_id, formulary_item_id, location, on_hand, unit, reorder_threshold, …` and `dispenses` is
`… medication_order_id, formulary_item_id, quantity, dispensed_by, dispensed_at, stay_id`. There is
no batch number, no lot, no expiry column anywhere, so "dispense from which batch" has no answer to
audit (`P5-M5`).

### Roles covered

| Role | Driven as | Permissions |
|---|---|---|
| `pharmacist` | `sofia.rieder@klinik-bergblick.test` | `patient.view`, `formulary.manage`, `dispense.manage`, `billing.manage` |
| `pharmacy_technician` | `tim.graf@klinik-bergblick.test` | `patient.view`, `dispense.manage` |

**Excluded, with reasons:** none. `RbacProvisioner::ROLE_TEMPLATES` contains exactly two pharmacy
roles and both were driven separately on identical routes. Prescribing is a physician act
(`doctor` / `hospitalist`, Phase 2) and medication *administration* on a ward is nursing (Phase 4);
neither is a pharmacy role.

**Pharmacist vs technician — the asymmetry is deliberate and correct at the route layer:**

| Route | `pharmacist` | `pharmacy_technician` | Correct? |
|---|---|---|---|
| `/pharmacy/inventory` | 200 | **200** | ✅ the template says the technician "manages stock" |
| `/pharmacy/formulary` | 200 | **403** | ✅ no `formulary.manage` |
| `/pharmacy/pricing` | 200 | **403** | ✅ no `formulary.manage` |
| `/billing/new-invoice` | 200 | **403** | ✅ no `billing.manage` |
| dispensing screen + Dispense | 200 / works | 200 / works | ✅ both hold `dispense.manage` |

**But the asymmetry does not stop at the route layer, and that is `P5-C2`:** the same Dispense button
produces a *billable* dispense for one role and a *permanently unbilled* one for the other, with no
visible difference.

### Surfaces driven

| Surface | Route | Roles | Result |
|---|---|---|---|
| Landing | `/app` | both | offers 4 links that 403 (`P5-H2`) |
| Landing @ 390 px | `/app` | pharmacist | no nav, no menu button (`P5-M6`) |
| Formulary | `/pharmacy/formulary` | pharmacist ✅ · technician 403 | renders, `store_url` present |
| Inventory | `/pharmacy/inventory` | both ✅ | stock + append-only movement log; US dates |
| Pricing | `/pharmacy/pricing` | pharmacist ✅ · technician 403 | prices with **no currency** (`P5-M3`) |
| **Dispensing** | `/pharmacy/patients/{p}/dispensing` | both ✅ | **no allergy, no seam** (`P5-C1`) |
| eMAR | `/pharmacy/patients/{p}/emar` | pharmacist ✅ | renders; US dates |
| Medication orders | `/pharmacy/patients/{p}/medications` | pharmacist ✅ | renders; no allergy |
| Dispense action | `POST …/dispense` | both | drives end to end; refusals probed |
| New invoice | `/billing/new-invoice` | pharmacist ✅ · technician 403 | **permanently empty** (`P5-H1`) |
| Clinical chart | `/clinical/chart/{p}` | pharmacist ✅ | the honest seam lives **here** |
| Ward board | `/hospital/wards` | both ✅ | reachable (gated on `patient.view`) |

---

### CRITICAL

#### `P5-C1` — The dispensing screen shows neither the recorded allergy nor the safety-seam statement

- **Roles:** `pharmacist`, `pharmacy_technician` · **Route:** `/pharmacy/patients/{patient}/dispensing`
- **Steps:** record a **severe Penicillin allergy** (anaphylaxis) for Greta Zimmermann, who has an
  **active Amoxicillin order**; open the dispensing screen as the pharmacist.
- **What happened — the screen, verbatim and in full:**

  ```
  PHARMACY · DISPENSING
  Greta Zimmermann
  Dispense a patient's active medication orders.
  Active orders
  Amoxicillin · 1 Kapsel
  97 on hand
  Dispense
  DISPENSING HISTORY
  Amoxicillin · ×3      9/6/26, 6:49 PM
  ```

- **THE HONESTY FENCE ITSELF HOLDS, AND THAT MUST BE SAID FIRST.** There is **no** "no interactions
  found", **no** "safe to dispense", **no** green tick, **no** cleared state, **no** computed
  severity or interaction grade, and **no** auto-substitution or alternative suggestion. Nothing on
  this screen asserts a check that did not happen. **There is no D-179 breach.**
- **The defect is the opposite failure: total silence.** The product *holds* a documented anaphylactic
  allergy to the class of the drug being handed over, and the screen where it is handed over shows
  neither the allergy nor the honest "no automated checking is configured" statement it shows
  elsewhere. A pharmacist dispensing Amoxicillin to this patient sees **nothing at all**.
- **Cause:** `Modules/Pharmacy/src/Http/Controllers/DispensingController.php:36-58` builds the Inertia
  payload from `patient` (**id and name only**), `orders`, `history` and `actions`. No allergy data is
  passed, so the screen structurally cannot show one. More broadly, **the entire Pharmacy module never
  reads the allergy list** — a search of `Modules/Pharmacy/src/` finds allergies mentioned only in the
  `MedicationSafetyProvider` contract's own docblocks, and **no `resources/js/pages/Pharmacy/*` file
  mentions allergies at all**. The same silence holds on `…/medications` and `…/emar`.
- **The contrast is what makes this severe.** The product already contains an *exemplary* honest panel
  — `AllergyRecordPanel`, rendered at `resources/js/pages/Clinical/Chart.vue:232` — and the pharmacist
  **can** reach it (`/clinical/chart/{patient}` → 200). It is simply not on, or linked from, the
  dispensing screen. See *guards verified holding* for its verbatim text.
- **Why CRITICAL:** the gate's own trigger is "a medication-safety misrepresentation". This is not a
  false assurance — it is the omission of a recorded, life-threatening fact at the single point in the
  product where a drug is physically released, when the same fact is displayed two clicks away. The
  omission is the misrepresentation: a screen that lists everything relevant to a dispense, and omits
  the anaphylaxis, reads as though there were nothing to say.

> ✅ **FIXED — QA-FIX.5a, commit `b9f5c91` (D-206).** The recorded allergies and the medication-safety
> seam now render on **all three** medication-action screens — `…/dispensing`, `…/medications` and
> `…/emar` — using the **same** `AllergyRecordPanel` the clinical chart renders, so the wording cannot
> drift into a second dialect.
>
> - **No new panel and no move.** `AllergyRecordPanel` already lived in the shared
>   `resources/js/Components/`, so the behaviour-identity question never arose. A single
>   `Pharmacy\Support\PatientSafetyRecord` assembles the payload — one path, not three copies.
> - **THE EMPTY STATE WAS THE REAL RISK, and it is answered directly.** "No recorded allergies" read by
>   a pharmacist as *"checked, and clear"* would be a worse defect than the silence this closes. So the
>   seam renders **whether or not any allergy exists**, and the empty state states the **record** and
>   immediately denies being a check: *"No allergies are recorded for this patient. That is the state
>   of the record — it is not the result of a check."* No tick, no success colour, no reassurance.
> - **The fence is unchanged.** Nothing compares the allergy list against the drug being dispensed —
>   that comparison *is* the certified-partner judgment (ALLERGY.P1). No interstitial confirm, no
>   "dispense anyway", no blocking verdict, no ranking, no severity styling (D-169); the list is
>   ordered by **substance**, asserted.
> - **The clinical chart is untouched.** `alwaysShowSeam` defaults to **false** and the chart does not
>   pass it — a positive control asserts that.
> - **No second audit path**: exactly one read-audit row per render, asserted.
> - **A test-design lesson, caught by mutation and recorded rather than hidden.** The payload test for
>   the empty case stayed **green** when the panel's own condition was reverted to
>   `v-if="active.length > 0"` — the exact D-179 shape — because the server payload is identical either
>   way. With no `@vue/test-utils` in this repo, the guard is a structural template assertion plus the
>   browser verification; the payload test is regression cover, not the guard.
> - **Verified in a real browser as BOTH pharmacy roles**, reproducing Phase 5's exact steps, including
>   the no-allergy case — see the gate report for the verbatim screen text.


#### `P5-C2` — A technician's dispense is silently never billed, and nothing surfaces it

- **Role:** `pharmacy_technician` · **Route:** `POST /pharmacy/medication-orders/{order}/dispense`
- **Steps:** as `tim.graf`, open Greta's dispensing screen and press **Dispense** (quantity 1).
- **What happened:** the dispense succeeded — stock 96 → **95**, `dispenses` 3 → **4**, the row records
  `dispensed_by = tim.graf` — and `dispense_charges` stayed at **3**. **No charge was created.** The
  screen is byte-for-byte the same success view the pharmacist gets: same history entry, same
  decremented stock, no notice, no warning, no difference of any kind.
- **Cause:** `PharmacyBillingService::chargeForDispense()` opens with
  `Gate::forUser($actor)->authorize('billing.manage')` — a permission the technician template
  deliberately does **not** grant — and `DispensingController.php:81-86` calls it inside
  `try { … } catch (Throwable) { }` with the comment *"best-effort billing; a charge failure must
  never block the (completed) dispense."* The authorization failure is a `Throwable`, so it is
  swallowed exactly like a transient billing hiccup.
- **This is the role's PRIMARY ACTION, not an edge case.** The `pharmacy_technician` template's own
  comment reads *"Dispenses + manages stock UNDER a pharmacist"*. Dispensing is what the role is for,
  so on the intended configuration **every** medication a technician hands out is unbilled.
- **And it is invisible.** The same comment promises the loss is *"reconcilable later"*, but a search
  finds **no report, query, screen or command anywhere that lists dispenses without charges**
  (`P5-M4`). Nothing accumulates, nothing alerts, nothing can be reconciled from.
- **Why CRITICAL:** silent, permanent, unreconcilable loss of financial records, produced by the
  ordinary use of a role whose whole purpose triggers it. The *clinical* record is intact — the
  dispense, the stock movement and the audit trail are all correct — so this is a financial-data loss,
  not a clinical one, but it is complete and undetectable.

> ✅ **FIXED — QA-FIX.5b, commit `88d50eb` (D-207).** The dispense still does not bill — **branch
> (b)** — but it is no longer **silent**, which is what this finding is about.
>
> - **Branch (b), and why.** `ChargeCaptureService::authorize()` requires `billing.manage` on the
>   **actor** for *every* capture path in the product, and Lab, Radiology, ED and Surgery all expose
>   charge capture as an explicitly **operator-initiated** act by a billing-permitted human. Making
>   Pharmacy the one exception would break the engine's own rule rather than fix a bug — and expanding
>   who may cause a money write is a compliance decision, not an engineering one.
> - **What (a) would take, recorded so the owner can decide.** `chargeForDispense()` would authorise
>   the **tenant's** right to bill rather than the actor's (the accrual is deterministic — the engine
>   snapshots the tariff, no amount is chosen). **Consequence of not doing it:** on the intended
>   configuration the practice does not bill for medications a technician hands out. That is now
>   **visible** rather than silent, which is what makes the decision possible to take deliberately.
> - **The actual defect, wrong under either branch, is fixed.** An authorization failure and a
>   transient failure were swallowed **identically** by an empty `catch (Throwable) {}`. They are now
>   separate paths — `pharmacy.dispense.uncharged.not_permitted` (info; a policy outcome, not a fault)
>   and `pharmacy.dispense.uncharged.billing_failed` (warning, with the exception class) — and **both
>   are recorded rather than discarded**.
> - **The property the catch existed for is preserved and asserted.** A genuine billing failure still
>   does **not** unwind the dispense: the drug has physically left the shelf. A test pins that.
> - **`P5-M4` is closed too, without a migration or an invented workflow.** "A dispense with no charge"
>   was already expressible, so `Dispense::query()->uncharged()` needed no schema change. The
>   dispensing screen marks each unbilled row **"Not billed"** and states the count. It deliberately
>   does **not** say *why* — an unpriced medication and a not-permitted actor land in the same list —
>   and claims no reconciliation the product does not perform (D-170): *"This is the state of the
>   ledger; CareOS does not reconcile them automatically."*
> - **Eight tests, mutation-checked twice:** restoring the empty catch reddens the
>   recorded-not-discarded test; neutering the visibility reddens the on-screen test while its positive
>   control stays green.
> - **Verified in a real browser** as both roles, counting `dispenses` and `dispense_charges` before and
>   after each — see the gate report.


---

### HIGH

#### `P5-H1` — The pharmacist can open `/billing/new-invoice` and can never bill a single thing on it (resolves `P3-H4`)

- **Role:** `pharmacist` · **Route:** `/billing/new-invoice` · **This is Phase 3's `P3-H4` carried
  forward.** Phase 3 could only establish that the page was *reachable*; the gate asks this phase to
  complete or refuse the write. **The answer is: the write is refused — not by a permission, but by a
  state transition that has no surface.**
- **Steps:** as the pharmacist, dispense Amoxicillin (which creates a charge), then open
  `/billing/new-invoice`.
- **What happened:** the page renders and says, in full: *"New invoice — Assemble an invoice from a
  patient's validated charges and issue it. **No validated, un-invoiced charges to bill.**"* — while a
  charge for that very dispense exists, `status = draft`, `invoice_id = NULL`, description
  "Amoxicillin", `line_total_minor = 120`.
- **Cause, in three parts:**
  1. `ChargeCaptureService.php:143` creates captured charges as **`STATUS_DRAFT`**.
  2. `InvoiceDraftController` — the controller behind this screen — filters `STATUS_VALIDATED` at
     **lines 43, 76 and 124** for listing, drafting and issuing respectively, and never validates
     anything.
  3. `PharmacyBillingService::invoicePatient()` **does** the whole job correctly — it calls
     `validateForPatientPeriod()` (which promotes draft → validated) and then issues through the
     existing flow — and it has **no route and no production caller anywhere in the codebase**.
- **So the pharmacist's charge-capture path terminates.** They may dispense, a draft charge is
  created, and the only billing surface their `billing.manage` unlocks is permanently empty for the
  charges they generate. **`P3-H4` is therefore worse than Phase 3 recorded:** not merely "reachable
  while 403 on every billing read", but reachable, un-refused, and structurally unusable.
- **Why HIGH:** the role holds `billing.manage` specifically so it can "bill dispensed meds through
  the existing engine" (the template's own comment), and that is exactly what it cannot do.

#### `P5-H2` — Every pharmacy role's landing page offers links that role cannot open

- **Roles:** both · **Route:** `/app` · **Cross-phase pattern 1, fifth phase.**
- **Measured:** for **both** roles, `/patients/register`, `/scheduling/day-board`, `/nursing/dispatch`
  and `/comms/inbox` are offered and **all four return 403**.
- **The page already holds the answer, as Phase 4 proved.** The Inertia payload carries an explicit
  permission map; for the pharmacist it reads `"comms.manage": false`, `"dispatch.manage": false`,
  `"appointment.manage": false` — and the links render anyway
  (`resources/js/pages/App/Landing.vue:171,174,177`, still ungated).
- **Phase 5 adds a new detail with a consequence:** that permission map contains **14 fixed keys and
  none of the pharmacy permissions** — no `dispense.manage`, no `formulary.manage`. The shell
  therefore cannot tell a pharmacist from anyone else, which is also why the Pharmacy module appears
  in no navigation (`P5-M1`). One shared map both over-offers and under-offers.

#### `P5-H3` — A dispense cannot be reversed, corrected or cancelled by any path

- **Roles:** both · **Surface:** the whole Pharmacy module
- **What I did:** dispensed end to end, then looked for any reversal, return, void or cancel path.
- **What happened:** `Dispense` and `StockMovement` are strictly **append-only** — both throw
  `DispensingException::appendOnly()` on `updating` **and** `deleting`
  (`Dispense.php:54-55`, `StockMovement.php:67-68`). That is correct and is recorded below as a guard
  holding. But **`DispensingService` offers no compensating action at all**: no reverse, no return, no
  void. A dispense recorded against the wrong patient, the wrong drug or the wrong quantity is
  permanent.
- **Stock can be corrected** via `POST /pharmacy/inventory/{stock}/adjust`, so the *count* can be made
  right — but the dispense record, its patient attribution and its charge all stand.
- **Why HIGH:** an append-only clinical record with no compensating entry is a half-built ledger. The
  Billing module solved exactly this with credit notes and reversals; dispensing has neither.

---

### MEDIUM

- **`P5-M1` — The Pharmacy module appears in no navigation, for either role.** Driven: the pharmacist's
  nav is *Dashboard, Patients* only; the technician's is the same. All of `/pharmacy/formulary`,
  `/inventory`, `/pricing` and the dispensing screens are reachable **only by typing the URL**. The
  cause is concrete and shared with `P5-H2`: the shell's permission map omits every pharmacy
  permission, so the nav cannot know the user is a pharmacist. Same shape as Phase 4's `P4-M4` ward
  board — a permission-correct surface with no link — but here it is the role's **entire module**.
- **`P5-M2` — Dates render in US format in the viewer's timezone.** The dispensing history shows
  `9/6/26, 6:49 PM`; the DB holds `2026-09-07 01:49 UTC`, which is `03:49` in the tenant's
  `Europe/Zurich`. The displayed value is `America/Los_Angeles` — the **browser's** zone, not the
  practice's — in `M/D/YY` with AM/PM. Cause: `resources/js/pages/Pharmacy/Dispensing.vue:35`
  `new Intl.DateTimeFormat(locale.value, { dateStyle:'short', timeStyle:'short' }).format(new Date(iso))`
  — the browser-locale mechanism Phase 2 identified. Same on Inventory and eMAR.
  **Cross-phase pattern 2, fifth phase.**
- **`P5-M3` — The pricing screen shows money with no currency at all.** Verbatim: *"MED-AMOX-500 ·
  Current: 1.20"*, *"MED-ENOX-40 · Current: 25.00"*, *"MED-PARA-500 · Current: 0.80"*. No `CHF`, no
  Swiss group separator, no `formatSwissMoney`. Phase 3's `P3-M1` found eleven of twelve billing
  surfaces hand-rolling a formatter; this is a step further — a money screen with no currency unit
  whatsoever.
- **`P5-M4` — Nothing anywhere lists uncharged dispenses.** `DispensingController.php:80` states the
  loss is *"reconcilable later"*, but no report, query, screen or console command selects dispenses
  lacking a `dispense_charges` row. The claim in the comment is unbacked, and it is what makes
  `P5-C2` permanent rather than merely delayed.
- **`P5-M5` — No batch or expiry tracking exists in the model.** `medication_stocks` has no batch, lot
  or expiry column and `dispenses` has no batch reference, so "from which batch" and "is it expired"
  cannot be asked, let alone answered. For a dispensing pharmacy this is a recall-traceability gap.
  Recorded as an absence the operator can see, not a false claim.
- **`P5-M6` — No navigation below 768 px.** At 390×844 on the dispensing screen both nav links measure
  0×0 and there is **no menu button**; no horizontal overflow, and the Dispense control itself stays
  usable. **Cross-phase pattern 3, fifth phase.**
- **`P5-M7` — An allergy cannot be recorded anywhere in the product.**
  `ClinicalListService::recordAllergy()` exists, is tenant- and permission-guarded, dispatches
  `ClinicalRecordChanged`, and has **no HTTP route**: the route table contains no allergy write, and
  the clinical chart's `actions` payload carries `can_write_notes`, `can_sign_notes`, `can_order` and
  four order URLs but **nothing for allergies**. The chart displays allergies it provides no way to
  create. This is why the fence control for this phase had to be written through the service.
  **Cross-phase pattern 4.**

### LOW

- **`P5-L1` — `/billing/new-invoice`'s only breadcrumb is a dead end for the role that can reach it.**
  The page links back to `/billing/invoices`, which returns **403** for `pharmacist` — the same
  breadcrumb Phase 3 flagged in `P3-H4`, still present.
- **`P5-L2` — Enoxaparin is in the formulary, priced and prescribed, but has no stock row.** It appears
  in the receive-stock dropdown and on the pricing screen, and Karin Weber has an active order for it,
  yet the inventory "On hand" table lists only Amoxicillin and Paracetamol. Dispensing it would hit
  `DispensingException::noStock`. A seeded-data gap rather than a code defect, recorded because it is
  the only path by which the `noStock` refusal is reachable.

---

### Guards verified holding

Driven in the browser unless noted:

- **THE MEDICATION-SAFETY SEAM IS EXEMPLARY — where it is shown.** On `/clinical/chart/{patient}`,
  reachable by the pharmacist, the panel reads verbatim:

  > **RECORDED ALLERGIES** — *Documented by a clinician. These are recorded facts — CareOS surfaces
  > them, it does not compute drug-allergy conflicts.*
  > Penicillin · Anaphylaxis requiring adrenaline and hospital admission · **Severe** · Source
  > `patient_reported` · Not yet confirmed
  >
  > **Automated medication-safety checking** — *No automated medication-safety checking is configured.
  > Recorded allergies are shown above; drug-allergy interaction, cross-reactivity and contraindication
  > checking is a certified-partner function and is not performed here.*
  >
  > *When a licensed partner is connected, its findings appear here as advisory notes for the
  > prescriber — they are never automatic and never block a prescription.*

  This is precisely the honest statement the gate describes: it names the absent function, attributes
  it to a certified partner, and promises only advisory, non-blocking behaviour if one is ever
  connected. **No "no interactions found", no cleared state, no green tick, no grading, no
  substitution.** `P5-C1` is that this panel is absent from the dispensing screen — not that it is
  wrong.
- **D-169 holds, proven with a positive control rather than inferred.** A second **mild** Latex allergy
  was recorded alongside the **severe** Penicillin one and the two were compared on screen: the
  severity chips are byte-identical — colour `rgb(201,155,63)`, background `oklab(0.7157 0.0173
  0.1197 / 0.2)`, weight 600 — and both cards share the same border and background. An anaphylactic
  allergy renders exactly like a mild rash. Severity is recorded text, never styling.
- **The dispense is atomic and correctly recorded.** `DispensingService.php:52` wraps the dispense row,
  the stock decrement and the append-only `StockMovement` in **one `DB::transaction`**, taking the
  stock row `lockForUpdate` and re-checking `insufficientStock` **inside** the lock. Driven: stock
  97 → 96, one dispense, one movement, one charge.
- **The dispense row answers every question asked of it except batch.** `dispensed_by` resolved to
  `sofia.rieder` (the acting pharmacist), `quantity = 1`, linked to the medication order that
  authorises it and to the patient, and `dispensed_at = 2026-09-07 01:55:26` against a CLI
  `now()` of `01:55:45` — **19 seconds apart, correct UTC**, so the QA-FIX.1a spot-check passes.
- **Refused dispenses leave nothing behind — cross-phase pattern 6 does NOT recur here.** Four
  refusals were driven and the ledger re-counted after each: insufficient stock (quantity 100 000) →
  refused; quantity `0` → **422**; quantity `-5` → **422**; a forged non-existent order id → **404**.
  After all four: `on_hand` 96 (unchanged), dispenses 3, charges 3, movements 5 — **all unchanged**.
- **Authority is structural.** The dispense route takes a `MedicationOrder` id, so a dispense with no
  prescription behind it is not expressible; a forged id 404s at model binding, before any
  authorization or stock logic runs.
- **`Dispense` and `StockMovement` are strictly append-only** — both throw on `updating` *and*
  `deleting` (`Dispense.php:54-55`, `StockMovement.php:67-68`).
- **The technician's route-level RBAC is correct in both directions**: 403 on formulary, pricing and
  `/billing/new-invoice` (no `formulary.manage`, no `billing.manage`); 200 on inventory and dispensing,
  which the role template explicitly intends.
- **CSRF is genuinely enforced on web routes.** Forged POSTs without a token returned **419 CSRF token
  mismatch** — an incidental but welcome confirmation that QA-FIX.4a narrowed only the `api` group.
- **D-191 does not recur.** Pharmacy list ordering is meaningful and deterministic:
  `dispensed_at DESC, id DESC`, `administered_at DESC, id DESC`, `name, code` — every ordering column
  has an obvious meaning and a stable tiebreak.

### Not testable, and why

- **A dispense from a specific batch, or an expiry check.** The model has no batch or expiry column
  (`P5-M5`), so there is nothing to drive.
- **The `noStock` refusal.** Only Enoxaparin lacks stock (`P5-L2`) and it is prescribed to a different
  patient; the reachable refusals (`insufficientStock`, non-positive quantity, forged id) were all
  driven instead.
- **A billing failure that is *not* an authorization failure.** `P5-C2` exercises the swallowed
  `catch (Throwable)` via the technician's missing `billing.manage`. Producing a genuine billing
  *hiccup* (an unpriced medication) would require creating an unpriced formulary item **and** a
  prescription for it — prescribing is a physician act outside this role group — so the second route
  into the same swallow is recorded from the code path rather than driven.
- **Performance** — out of scope per the gate, deferred to staging.


## Phase 6 — Surgery / Operating theatre

**Date:** 2026-09-07 · **Top commit at audit time:** `5aea00f` (the QA-FIX.5 hash backfill), CI
`completed / success` confirmed via `commits/<sha>/check-runs`. Tree clean apart from untracked
`docs/marketing-site/`.

**AUDIT ONLY.** No app code, test or seeder was changed. The only writes are those made by *driving
the product*: three surgical cases scheduled, one driven through its full lifecycle, two checklist
items confirmed (one then un-confirmed), one ASA assessment recorded twice, one consumable usage,
one implant placement. All demo tenants are re-seeded afterwards.

### Environment

| Item | State |
|---|---|
| Surgery tenant | `klinik-bergblick` — the **only** tenant with surgical data |
| Baseline case | **1** — Karin Weber, *Laparoskopische Appendektomie*, status **`post_op`** |
| Theatre / slots | 1 theatre, 1 slot — **both written by the seeder only** (see `P6-H2`) |
| Checklist | 1 checklist, **4** instantiated items against **17** template items |
| Items / stock | 2 (`Steriler Tupfer` 500, `Titanschraube` 20) |
| **Implant placements** | **0 at baseline** — the recall path had never been exercised |
| Case charges | 3 (seeder-written; see `P6-C1` for why the UI cannot write one) |
| **Redis** | **UP — honestly.** `PING` → `+PONG` on 127.0.0.1:6379 (Memurai) |
| App timezone | `UTC` storage; tenant `Europe/Zurich`; browser (viewer) `America/Los_Angeles` |
| Out of scope | **Performance — deferred to staging**, per the gate |

**WHAT THE SEED DOES NOT CONTAIN, stated rather than worked around.** The gate asked for this
explicitly. `klinik-bergblick` seeds **one** surgical case in **one** status (`post_op`). There is
**no** case in `scheduled`, `pre_op`, `in_progress`, `completed` or `cancelled`; **no** second
theatre; **no** second slot; and **zero** implant placements, so the lot/UDI recall lookup had no
data to find. Rather than seed around this, the missing states were **created by driving the product**
— three cases scheduled through the real form, one taken `scheduled → pre_op → in_progress →
completed` through the real buttons, and an implant placed through the real form. Every finding below
is therefore reproducible from the shipped seed plus the steps given.

### Roles covered

| Role | Driven as | Permissions (`RbacProvisioner::ROLE_TEMPLATES`) |
|---|---|---|
| `surgeon` | `isabelle.vogt@klinik-bergblick.test` | `patient.view`, `encounter.manage`, `note.write`, `note.sign`, `order.manage`, `surgery.manage`, `surgery.schedule` |
| `anesthetist` | `johann.wyss@klinik-bergblick.test` | `patient.view`, `encounter.manage`, `note.write`, `note.sign`, `surgery.manage` |
| `scrub_nurse` | `nadia.brun@klinik-bergblick.test` | `patient.view`, `note.write` |
| `surgical_scheduler` | `beat.suter@klinik-bergblick.test` | `patient.view`, `appointment.manage`, `theatre.manage`, `surgery.schedule` |
| `org_admin` (for billing only) | `anke.berg@klinik-bergblick.test` | the only role in the tenant holding `billing.manage` |

All four surgery roles were driven **separately**, each with its own login and 2FA. `org_admin` was
added because **no surgery role holds `billing.manage`**, so the case-billing surface is unreachable
by any of the four and would otherwise have gone undriven.

**The RBAC matrix, measured in the browser (not inferred):**

| Route | Permission | `surgeon` | `anesthetist` | `scrub_nurse` | `surgical_scheduler` |
|---|---|---|---|---|---|
| `/surgery/cases` | `surgery.manage` | **200** | **200** | 403 | 403 |
| `/surgery/cases/{case}` | `surgery.manage` | **200** | **200** | 403 | 403 |
| `/surgery/cases/{case}/checklist` | `note.write` | **200** | **200** | **200** | 403 |
| `/surgery/cases/{case}/supplies` | `note.write` | **200** | **200** | **200** | 403 |
| `/surgery/inventory` | `surgery.manage` | **200** | **200** | 403 | 403 |
| `/surgery/cases/{case}/billing` | `billing.manage` | 403 | 403 | 403 | 403 |
| `/surgery/pricing` | `billing.manage` | 403 | 403 | 403 | 403 |
| theatre create / slot booking | `theatre.manage` / `surgery.schedule` | **no route exists at all** (`P6-H1`) |

### Surfaces driven

| Surface | Route | Roles | Result |
|---|---|---|---|
| Landing | `/app` | all four | no surgery link for any role (`P6-M1`); offers `Nursing dispatch`, which 403s (`P6-M2`) |
| OR case list | `/surgery/cases` | surgeon ✅ · anesthetist ✅ · other two 403 | schedules a case with **no theatre field** (`P6-H2`) |
| Case detail | `/surgery/cases/{case}` | surgeon ✅ · anesthetist ✅ | full lifecycle driven; ASA recorded twice (`P6-C2`) |
| WHO checklist | `…/checklist` | all three `note.write` holders ✅ | honest page (`guards`), invisible from the case (`P6-M4`) |
| Consumables / implants | `…/supplies` | surgeon ✅ · scrub nurse ✅ · org_admin ✅ | usage + implant driven; refusals invisible (`P6-C3`) |
| Inventory + recall | `/surgery/inventory` | surgeon ✅ · anesthetist ✅ | recall lookup driven **end to end** (`guards`) |
| **Case billing** | `…/billing` | all four **403** · org_admin ✅ | **"Total: NaN", no capture form** (`P6-C1`) |
| Surgical pricing | `/surgery/pricing` | all four 403 · org_admin ✅ | money with no currency (`P6-L2`) |
| Medication orders | `/pharmacy/patients/{p}/medications` | surgeon ✅ | view only — no prescribe control (`P6-H5`) |
| Clinical orders | `/clinical/orders/review` | surgeon ✅ · anesthetist **403** | the `order.manage` anomaly's real cost (`P6-H5`) |
| Day-board | `/scheduling/day-board` | scheduler ✅ | shows **nothing surgical** (`P6-H1`) |

---

### CRITICAL

#### `P6-C1` — Surgical billing is structurally unreachable, and shows "Total: NaN" over real charges

- **Roles:** `org_admin` (the only holder of `billing.manage`) · **Route:** `/surgery/cases/{case}/billing`
- **Steps:** log in as `anke.berg@klinik-bergblick.test`; open the **seeded** case's billing page
  (`/surgery/cases/01m1xexfnsey0c847yraajwe81/billing`).
- **What happened — the screen, verbatim:**

  ```
  SURGERY · BILLING
  Karin Weber
  Laparoskopische Appendektomie
  Back to case
  Charges
  Laparoskopische Appendektomie · ×1     2,500.00
  Theatre time · ×90                       450.00
  Steriler Tupfer · ×8                      24.00
  Total                                        NaN
  View invoice
  ```

- **Four separate defects, one cause.**
  1. **`Total NaN`** is displayed where **CHF 2,974.00** belongs — a wrong financial figure on screen.
  2. The label reads **"Total"** (the label reserved for an *issued invoice's authoritative* figure)
     although **no invoice exists**; the honest "Estimate" label is unreachable.
  3. **The charge-capture form does not render** — `document.querySelectorAll('main form').length` is
     **0** for a user who holds `billing.manage`.
  4. **"View invoice" links to the current page** (`/surgery/cases/{id}/billing`), not to an invoice.
- **Cause — a name collision between a prop and a function in `<script setup>`.**
  `resources/js/pages/Surgery/CaseBilling.vue` declares the prop `invoice` at **line 20**
  (`invoice: { id: string; url: string; total_minor: number } | null`) and then a **function of the
  same name** at **line 35** (`function invoice(): void { router.post(props.actions.invoice_url, …) }`).
  In `<script setup>` both are exposed to the template, and the function binding shadows the prop.
  **A function is always truthy**, so every `invoice` test in the template inverts:

  | Template (line) | Intended | Actual |
  |---|---|---|
  | `<form v-if="actions.can_bill && !invoice">` (55) | show capture form | `!fn` = `false` → **never renders** |
  | `<Link v-if="invoice" :href="invoice.url">` (83) | only when invoiced | always; `fn.url` is `undefined` |
  | `<button v-else-if="… charges.length">issueInvoice</button>` (84) | issue invoice | `v-else-if` **never reached** |
  | `{{ invoice ? total : estimate }}` (79–80) | estimate pre-invoice | always "Total", `money(undefined)` → **NaN** |

- **This was verified against the server, not inferred.** The controller returns
  `can_bill: true` (`Gate::forUser($anke)->allows('billing.manage')` → `true`, checked in tinker) and
  `invoice: null` (`surgical_case_charges` for the driven case → **0 rows**, so `$invoiceId` is null at
  `SurgicalBillingController.php:37-40`). With those props the form *must* render and the link *must
  not*. The rendered DOM does the exact opposite, which is the collision's signature.
- **The build was checked and is NOT stale** — `public/build/manifest.json` (Sep 6 22:53) is newer than
  every `resources/js/pages/Surgery/*.vue` (Jul 27), and the shipped chunk
  `public/build/assets/CaseBilling-BNETCUuc.js` contains all 13 `surgery.billing.*` keys including
  `capture` and `captureHint`. The form is in the bundle; its `v-if` can never be true.
- **The consequence is the whole vertical's revenue path.** `POST …/billing/charge` and
  `POST …/billing/invoice` both exist and are correctly gated, and `SurgicalBillingService` is fully
  built — but **the only surface that calls them cannot render either control**. Driven end to end: an
  implant was placed (`Titanschraube`, lot `LOT-QA6-778`), stock decremented **20 → 19**, and
  `surgical_case_charges` for that case remained at **0**. A priced titanium screw was implanted in a
  patient and **cannot be billed through the product at all**.
- **Why CRITICAL:** wrong financial data rendered on screen (`NaN` for CHF 2,974.00) *and* a complete
  billing surface that cannot perform either of its two operations. The seeded case's 3 charges exist
  only because the **seeder** called the service directly.

> ✅ **FIXED — QA-FIX.6a, commit `9d5c047` (D-208).** The action is now `issueInvoice()`, so nothing
> shadows the `invoice` prop, and **every money figure on the screen is the engine's, formatted by the
> engine**.
>
> - **A rename alone would NOT have been enough, and that is the substance of this fix.** `money()`
>   carried **no currency** (`(minor / 100).toLocaleString(locale)`), so a pure rename would have
>   rendered `2,974.00`, not `CHF 2'974.00`. And the page derived its own money — `quantity ×
>   unit_price_minor` per line plus a client-side sum — a **second derivation** of figures the engine
>   had already computed and stored in `charges.line_total_minor`. Both are gone: the page now holds
>   no rate, does no arithmetic, divides by nothing and picks no currency.
> - **The totalling went into Billing, not Surgery, for a concrete reason.** Surgery's existing money
>   fence (`SurgicalBillingTest`) is a byte-level scan forbidding `line_total_minor` **anywhere** in
>   `Modules/Surgery/src`; naming the column in the Surgery controller would have reddened a passing
>   guard, and weakening that guard to fit the fix would have been the wrong trade. The new
>   `Modules/Billing/src/Services/ChargeSetReader` follows `PatientBalanceReader` — the class written
>   for this exact defect on the patient portal — and returns each line's stored engine amount plus
>   their Σ already formatted. **Surgery now names no money column at all** and the fence stays green,
>   untouched. Currency is read from the charges' own tariff catalog, the same source
>   `IssueService::tenantCurrencyFromCharges()` uses.
> - **Making the figure visible meant making it accurate.** Once an invoice exists the page shows the
>   invoice's own total — but `invoiceCase()` gathers every validated, uninvoiced charge for the
>   patient across the whole service **day**, so that figure can legitimately exceed the case's lines.
>   On the seeded case it does, by a lot: the three surgical charges sum to **CHF 2,974.00** while the
>   composite stay invoice they sit on totals **CHF 6,687.20**. Labelling that "Total" directly under
>   the case's lines would have asserted that it summed them, so the label is now **"Invoice total"**
>   (pre-invoice it stays "Estimated total", a net ex-VAT Σ of *this case's* charges). A naive rename
>   would have shipped `6,687.20` labelled "Total" — plausible-looking and wrong, which is worse than
>   `NaN`.
> - **BROWSER-VERIFIED as `org_admin`** (the only role holding `billing.manage`; no surgery role does).
>   **Before —** `Total  NaN`, `document.querySelectorAll('main form').length === 0`, "View invoice"
>   pointing at the current page. **After —** the seeded case renders
>   `Laparoskopische Appendektomie ×1  CHF 2'500.00 · Theatre time ×90  CHF 450.00 · Steriler Tupfer ×8
>   CHF 24.00 · Invoice total  CHF 6'687.20`, with "View invoice" now resolving to
>   `/billing/invoices/01m1ydcwnwed6s6gv94q60yxr8`. On a fresh, uninvoiced case the **capture form
>   renders** (`forms: 1`), charges were captured **through the UI for the first time**, and the page
>   showed `CHF 2'500.00 + CHF 225.00` under **`Estimated total  CHF 2'725.00`** — which ties — with the
>   **"Issue invoice" button now reachable**. No `NaN` anywhere.
> - **`SurgicalPricing.vue` was fixed in the same part**, because the new fence covers every Surgery
>   surface and it formatted prices client-side too. Displayed prices now arrive as `price_formatted`;
>   `price_minor` still ships because the **edit field** is populated in major units from it. That
>   input-affordance/displayed-figure distinction is why `/ 100` is deliberately *not* in the fence's
>   forbidden list, and the test says so explicitly rather than leaving it looking like an oversight.
> - **This also closes `P6-L2`** (Surgery money rendered with no currency unit) for both money surfaces.
>
> **FOUND WHILE FIXING, NOT FIXED — the identical collision ships in two other modules.** Verified from
> source: **`Lab/Billing.vue`** (prop `:18`, `function invoice()` `:42`) and
> **`Radiology/Billing.vue`** (`:18`, `:41`) carry the same prop/function collision, and are **worse**
> than Surgery's was — their `<div v-if="invoice">` always renders, so both pages announce the work is
> **"Issued"** and show its total as **"NaN"** on cases that were never invoiced, while the
> issue-invoice control behind `v-if="isCharged && !invoice"` never renders at all. `ED/Billing.vue`
> has the client-side money sum but **no** collision (it already names its action `issueInvoice()` —
> the in-repo precedent this fix follows). All three still derive money client-side, so Phase 3's
> "zero page-side sums across the billing surfaces" holds only for `resources/js/pages/Billing/`.
> Recorded for **Phase 7 (ED)** and **Phase 8 (Lab + Radiology)** rather than fixed here, per gate
> discipline.

#### `P6-C2` — The ASA assessment is attributed to a person the operator picks, silently overwritable, and the only unaudited write in the module

- **Roles:** `surgeon`, `anesthetist` (anyone with `surgery.manage`) · **Route:** `POST /surgery/cases/{case}/anesthesia`
- **Steps, driven as `johann.wyss` (the anesthetist) on a case whose primary surgeon is Isabelle Vogt:**
  1. Record **ASA III / Mallampati II**, selecting **"Tim Graf"** — a `pharmacy_technician` — from the
     *Anesthetist* dropdown. Screen shows `ASA class: III · Mallampati: II`.
  2. Record again: **ASA I**, selecting *Dr. med. Johann Wyss*. Screen shows `ASA class: I`.
- **What the record holds afterwards:**

  ```
  asa_class        I                      (III is gone — no prior value anywhere)
  mallampati       II
  asa_assessed_by  Tim Graf [tim.graf@klinik-bergblick.test]   → then Johann Wyss
  asa_assessed_at  2026-09-07 08:36:29
  ```

- **Three distinct defects.**
  1. **The attribution is the dropdown pick, not the actor.** `asa_assessed_by` is `char(26)` — a
     `staff_profiles.id` — written from the submitted `anesthetist_id`
     (`SurgicalCaseService.php:143`). The actual actor (`$actor`) is used **only** for
     `Gate::forUser($actor)->authorize('surgery.manage')` at line 130 and is then **discarded**. There
     is no `asa_recorded_by`. So Johann Wyss recorded an ASA III and the permanent record says a
     **pharmacy technician** assessed it. Nothing checks that the named person is an anesthetist, has
     consented, or was present.
  2. **It is overwritten in place with no history.** `$case->forceFill([...])->save()`
     (`SurgicalCaseService.php:140-146`) replaces the class, the assessor and the timestamp. The prior
     ASA III is not retained anywhere.
  3. **It produces no audit event.** A query of every distinct surgery audit action in the tenant
     returns `surgical_case.scheduled`, `surgical_case.pre_op / in_progress / completed / post_op`,
     `surgical_checklist.opened`, `surgical_checklist.item_confirmed`, `surgical_item.created`,
     `surgical_item.used`, `surgical_stock.received`, `surgical_stock.used` — and **no ASA/anesthesia
     action of any kind**. Both writes above produced **zero** `audit_events` rows.
- **What makes this severe is the contrast, not the omission alone.** The module audits *everything
  else*, and the checklist beside it is **append-only with actor and timestamp on every row**. The ASA
  class — the single clinical judgment recorded on a surgical case, the one that drives anaesthetic
  planning — is the one field that is mutable, mis-attributable and untraced. The docblock at
  `SurgicalCaseService.php:120` describes it as a **"RECORDED FACT, with provenance"**; the provenance
  it stores is a name the operator chose, and there is no record of who chose it.
- **The electric fence itself is intact** — see *guards verified holding*. No risk score is computed
  from ASA anywhere; this finding is about the record's integrity, not about a computed judgment.
- **Why CRITICAL:** a clinical assessment that can be attributed to an uninvolved colleague, changed
  afterwards without trace, and reconstructed from nothing. `D-196` (identity-namespace drift) is the
  mechanism behind the first defect — see the cross-phase note.

> ⚠️ **CORRECTION TO THIS FINDING, made during QA-FIX.6b and recorded rather than quietly amended.**
> Defect **(c)** above says the ASA write is *"the **only** write in the Surgery module that raises no
> audit event"*. **That is wrong, and the error is mine.** `SurgicalCaseService::addTeamMember()`
> (`:106-116`) is a second one: `grep SurgicalCaseTeamMember app/Providers/AppServiceProvider.php`
> returns **nothing**, so adding a person to a surgical team is also unaudited. It also carries defect
> **(b)** — it writes with `updateOrCreate`, so re-roling a team member **overwrites `team_role` in
> place with no history**, exactly as the ASA did.
>
> The evidence for the mistake was in the audit's own text the whole time: the distinct-action list
> quoted under defect (c) contains no team action, and I read that as "the ASA is the exception"
> rather than "two things are missing". The ASA is still the more severe of the two — it is a clinical
> judgment rather than a staffing note — so the CRITICAL grade stands on its own terms, but the word
> "only" does not.
>
> **The team-write gap is NOT fixed by QA-FIX.6b** (that part is scoped to the ASA) and is recorded
> against `P6-M5`, which already covers team attribution. See its note below.

> ✅ **FIXED — QA-FIX.6b, commit `f8b7a7b` (D-209).** All three defects, each closed by the same
> change: an assessment is now an **append-only row** rather than four columns overwritten in place.
>
> - **THE SHAPE IS NOT NEW — the ED vertical already applies it to this exact kind of value.**
>   `ed_triages` records a NURSE-ASSIGNED acuity append-only with provenance and DB triggers, and
>   `EdTriage`'s own docblock names `SurgicalCase::asa_class` as the shape it followed. ED copied the
>   ASA's *fence* posture and added the discipline; the ASA never got it back. The new
>   `surgical_case_anesthesia_assessments` is that recipe applied where it started, alongside the
>   module's own `surgical_checklist_items` and `surgical_case_events`.
> - **(a) ATTRIBUTION — two people, two columns, and neither can stand in for the other** (the
>   QA-FIX.2a / D-195 rule). `assessed_by` remains the clinician whose judgment it is, because the
>   anaesthetist who assessed the patient genuinely is not always the person at the keyboard. The new
>   `recorded_by` is **the ACTOR**, taken from the authenticated user and never from the request — a
>   test posts a forged `recorded_by` and asserts it is ignored entirely.
> - **(b) HISTORY — a revision is a NEW row.** Guarded twice: model `updating`/`deleting` guards
>   (belt) and `SIGNAL '45000'` DB triggers (suspenders), so the record survives even a write that
>   bypasses Eloquent. A test drives `DB::table(...)->update()` straight at the driver and asserts the
>   database refuses it.
> - **(c) AUDIT — on the EXISTING path, not a second one.** A `created` hook in `AppServiceProvider`
>   emitting `surgical_case.anesthesia_assessed`, exactly like every sibling. A test asserts **exactly
>   one** audit row per assessment, so a second path would fail it, and that the hash chain still
>   verifies.
> - **`surgical_cases.asa_*` is deliberately left in place** as the denormalised CURRENT value — the
>   same posture as `status` beside `surgical_case_events`. No historical row is rewritten (the
>   D-193/D-197/D-202 precedent) and no existing reader breaks.
> - **BROWSER-VERIFIED by re-driving this finding's own steps**, as `johann.wyss` (anesthetist) on the
>   seeded case. Recorded **ASA III / Mallampati II naming Tim Graf**, then overwrote with **ASA I**.
>   The screen now shows:
>
>   ```
>   ASSESSMENT HISTORY
>   ASA class I   · Mallampati II   Assessed by Dr. med. Johann Wyss · recorded by Dr. med. Johann Wyss
>   ASA class III · Mallampati II   Assessed by Tim Graf             · recorded by Dr. med. Johann Wyss
>   ASA class II  · Mallampati I    Assessed by Dr. med. Johann Wyss · recorded by Dr. Anke Berg
>   ```
>
>   **The ASA III survives the overwrite** — this finding's "III is gone" is now three rows deep — and
>   the middle row shows the technician still *named* as assessor while the person who actually typed
>   it is permanently recorded beside them. The bottom row is the seeded assessment and is the
>   cleanest proof the two fields are independent: the seeder names Wyss as assessor while `org_admin`
>   Anke Berg is the actor who ran it. Confirmed in the database: three assessment rows, three
>   `surgical_case.anesthesia_assessed` audit rows, `verifyChain()` true, and the case's denormalised
>   current class `I`.
> - **NOT changed, deliberately:** the staff dropdown is still unfiltered, so a non-anaesthetist can
>   still be *named*. That is `P6-M6` (role-blind selectors, which affects the surgeon and team
>   pickers too); constraining this one selector would half-close it and leave its siblings, so it
>   stays open. The fix makes the naming **traceable**, not impossible.
> - **Guarded by** eleven tests, mutation-checked three ways — attributing to the picked person
>   instead of the actor reddens two; removing the append-only row reddens two; removing the audit
>   hook reddens two. **The fixture deliberately makes actor ≠ picked person**, which is precisely why
>   `P2-C1`'s identical defect survived its own suite: where the two coincide, no assertion can tell
>   which was stored.

#### `P6-C3` — Every refusal in the Surgery module is invisible: 13 error flashes, zero renderers

- **Roles:** all · **Routes:** every `POST` in the Surgery module
- **Steps (driven twice, two different guards):**
  1. On `…/supplies`, select the implant `Titanschraube`, leave **Lot / Serial / UDI blank**, click
     **Place implant**.
  2. On the same page, select `Steriler Tupfer`, enter quantity **99999** (stock is 490), click
     **Record use**.
- **What happened, both times:** the page reloaded, **nothing was recorded**, and **no message of any
  kind appeared**. Network shows `POST …/supplies/implant → 302 Found` — a success-shaped redirect.
  A regex sweep of the rendered page for `error|required|invalid|insufficient|stock|Fehler|nicht`
  returned **false**.
- **The server side is correct — that is the point.** `lot_number` is `['required','string','max:120']`
  (`CaseSuppliesController.php:90`), and the stock guard fires inside the transaction: `on_hand`
  stayed at 490 and no `case_item_usages` row was written. Both refusals are real and both flash a
  message: `return back()->withErrors(['surgical_supplies' => $e->getMessage()])`.
- **Cause: nothing renders them.** `grep -rn "errors" resources/js/pages/Surgery/` returns **zero
  matches across all seven Surgery pages** (`Case.vue`, `CaseBilling.vue`, `CaseBoard.vue`,
  `CaseSupplies.vue`, `Checklist.vue`, `Inventory.vue`, `SurgicalPricing.vue`). Meanwhile the module's
  controllers contain **13** `->withErrors([...])` call sites — 5 in `SurgicalCaseController`, 3 in
  `SurgicalPricingController`, 3 in `CaseSuppliesController`, 2 in `SurgicalBillingController`. Every
  one of them flashes into a void.
- **Notably, there is not a single empty `catch` in the module.** Phase 5's Pharmacy shape
  (`catch (Throwable) { }`) does **not** recur here: every catch names its exception and flashes a
  message. The module does the right thing at the controller layer and loses it entirely at the view
  layer — one missing renderer, 13 silenced guards.
- **The theatre consequence is what makes this CRITICAL rather than HIGH.** A scrub nurse recording the
  sponges used at sign-out gets a page that reloads and looks normal whether the write succeeded or
  was refused. The consumption is then missing from the case record — and, because charges are
  captured from recorded usage, missing from billing too. Combined with `P6-C1` the case is unbillable
  anyway; combined with the **recall lookup**, an implant whose lot was mistyped short of validation
  is simply not in the traceability index, and the recall screen will honestly report "no implants
  match" for a device that is in the patient.

> ⚠️ **TWO CORRECTIONS TO THIS FINDING, made during QA-FIX.6c.**
> 1. **"13" is wrong — there are 16 `withErrors` sites**, and the enumeration above missed
>    `SurgicalInventoryController` (3 sites) entirely while over-counting `CaseSuppliesController`
>    (2, not 3). The real distribution is `SurgicalCaseController` 5, `SurgicalPricingController` 3,
>    `SurgicalInventoryController` 3, `CaseSuppliesController` 2, `SurgicalBillingController` 2,
>    `SurgicalChecklistController` 1.
> 2. **"The server refuses correctly" is not true of every refusal.** Two that a user can reach were
>    not 302s at all — they escaped as **uncaught HTTP 500s**, so they were worse than invisible:
>    asking for theatre minutes before theatre time is priced (`TariffNotFoundForDateException`, never
>    caught anywhere), and re-using a surgical item code (`surgical_items` carries
>    `unique(tenant_id, code)` and nothing checked it before the INSERT). `bootstrap/app.php` renders
>    the branded error page for **403/404/419/503 only**, so a 500 got neither a page nor an error bag.
>    Neither is caught by CI: `test:smoke` covers GET routes only.

> ✅ **FIXED — QA-FIX.6c, commit `1388af3` (D-210).**
>
> - **The mechanism is the product's, not a new one.** Inertia's middleware already shares the error
>   bag on every response, and `Admin/Branches.vue` and the Billing surfaces already read
>   `page.props.errors` and render it in the house danger classes. The new
>   `resources/js/Components/RefusalNotice.vue` is a presentation wrapper over that same path so six
>   pages cannot drift apart in wording or styling (D-170).
> - **It reads the WHOLE bag, and that is the load-bearing decision.** Two shapes arrive there:
>   `$request->validate()` keys by **field** (`lot_number`, `quantity`) while the controllers'
>   `withErrors` keys by **domain** (`surgical_supplies`, `surgical_billing`). Naming keys would have
>   silently missed half the refusals — which is exactly how a module can look handled while most of
>   its refusals stay invisible.
> - **It authors no copy of its own (D-176).** The text is the server's own sentence rendered
>   verbatim, and with an empty bag the component renders nothing. It cannot manufacture a refusal
>   that did not occur — which is why a generic renderer is the *safe* choice here and a per-key copy
>   table would not have been: several Surgery domain keys cannot fire from any live control.
> - **`Checklist.vue` deliberately has NO error region**, and a test pins its absence. Its controller
>   validates `template_item_id` (required) and `checked` (required boolean), both always supplied by
>   the page's only control, so no refusal there is reachable. An affordance for it would be the same
>   defect class as an unbacked badge. The other six pages all have a reachable refusal.
> - **The two 500s are now refusals.** `SurgicalBillingController::charge` catches
>   `TariffNotFoundForDateException` — narrowly, not `Throwable` — and `createItem` gains a
>   tenant-scoped `Rule::unique`, so the duplicate code is reported on the field. **This is a
>   deliberate 500 → 302 status change and it weakens nothing**: both still refuse, nothing is
>   written either way, and `chargeCase()` remains transactional (D-208). The DB unique index remains
>   the hard guard; the rule only makes it speak.
> - **`role="alert"` is the one addition beyond the established pattern.** The repo has **zero**
>   `role="alert"` or `aria-live` anywhere, and an error a screen reader never announces is invisible
>   in precisely the way this finding is about.
> - **BROWSER-VERIFIED — three refusals driven as `surgeon`, including one of the former 500s.**
>   Verbatim, in a `role="alert"` carrying the house classes:
>
>   | Gesture | Phase 6 | Now |
>   |---|---|---|
>   | Place implant, lot blank | 302, nothing, silence | **"The lot number field is required."** |
>   | Record use ×99999 (492 on hand) | 302, nothing, silence | **"Insufficient stock for surgical item …: requested 99999, on hand 492."** |
>   | Add item with an existing code | **uncaught 500** | **"The code has already been taken."** |
>
>   And the **positive control held in the browser**: creating a valid item showed **no alert at all**,
>   with the write confirmed in the database — so the tests cannot be satisfied by a page that always
>   shows something. The refusals still refused: 0 implant placements, 1 usage (the seeded one), and
>   exactly one item with the duplicated code.
> - **Guarded by** eight tests, mutation-checked three ways: removing `<RefusalNotice />` from a page
>   reddens the structural guard; removing the tariff catch makes the response a literal **500** again;
>   removing `Rule::unique` reddens the duplicate-code test.
>
> **NOTED, NOT FIXED:** the insufficient-stock message names the item by **ULID** rather than by name
> ("Insufficient stock for surgical item `01m1ytkgme6q1wjvjf3rvpkq4p`…"). It is the service's own
> message rendered verbatim, which is the established idiom (D-152), and it is accurate — the count is
> the actionable part and the user chose the item a moment earlier — but it reads poorly. Changing it
> means editing a domain exception's wording, which is not what this part is for.

---

### HIGH

#### `P6-H1` — The Surgical Scheduler cannot open a single surgery route, and its two permissions gate a service with no controller

- **Role:** `surgical_scheduler` (`beat.suter@klinik-bergblick.test`)
- **Measured in the browser, every surgery route:**

  ```
  /surgery/cases                        403
  /surgery/cases/{case}                 403
  /surgery/cases/{case}/checklist       403
  /surgery/cases/{case}/supplies        403
  /surgery/cases/{case}/billing         403
  /surgery/inventory                    403
  /surgery/pricing                      403
  /scheduling/day-board                 200   ← shows nothing surgical
  ```

- **The role template says:** *"Runs the OR list — authors theatres + books surgical blocks"*
  (`RbacProvisioner.php:248-254`), granting `theatre.manage` and `surgery.schedule`.
- **Neither permission is consumed by any route.** They are authorised **only** inside
  `TheatreSchedulingService::createTheatre()` (line 38) and `::bookSlot()` (line 59), and a repo-wide
  search for consumers of that service finds **the seeder, the test suite, and one artisan command**
  (`AttemptBookSlotCommand`, which exists to feed the parallel-hammer test) — **no HTTP controller**.
  There is no `/surgery/theatres` route, no OR-list route, and no booking route anywhere in
  `routes/web.php`.
- **So the role that is named for the OR list cannot open the OR list, author a theatre, or book a
  block.** Its fallback is the generic `/scheduling/day-board`, which was driven and shows
  *"No bookable resources yet"* — no theatres, no surgical cases, nothing about the OR.
- **Why HIGH:** an entire shipped role has no reachable function in the vertical it names. This is
  cross-phase pattern 4 (a granted capability with no surface) at its most complete — not a missing
  affordance on a working screen, but a missing screen for a whole role.

#### `P6-H2` — The theatre double-booking guard is correct, tested, and unreachable; the path the product actually uses has no overlap check at all

- **Role:** `surgeon` · **Route:** `POST /surgery/cases`
- **Steps:** on `/surgery/cases`, schedule *Nora Bianchi · "QA6 concurrency probe A"* with primary
  surgeon **Dr. med. Isabelle Vogt** at **2026-09-10 09:00**. Then schedule *Marco Fischer · "QA6
  concurrency probe B"* with **the same surgeon at the same instant**.
- **What happened — both accepted, no warning, rendered side by side:**

  ```
  Nora Bianchi · QA6 concurrency probe A     2026-09-10 09:00 · Dr. med. Isabelle Vogt   Scheduled
  Marco Fischer · QA6 concurrency probe B    2026-09-10 09:00 · Dr. med. Isabelle Vogt   Scheduled
  ```

- **The guard the gate asked about is real and it is good.**
  `TheatreSchedulingService::bookSlot()` opens a `DB::transaction`, calls `lockTheatre()` — a literal
  `select id from theatres where tenant_id = ? and id = ? for update` — then `assertNoOverlap()` with
  the correct half-open predicate (`existing.starts_at < new.ends_at AND existing.ends_at >
  new.starts_at`) over `TheatreSlot::BLOCKING_STATUSES`, and it has a dedicated parallel-hammer test.
  It is a faithful copy of the `BookingService::lockResource`→`assertNoOverlap` idiom.
- **And the product never calls it.** The case-scheduling form has exactly four fields — Patient,
  Primary surgeon, Procedure, **Scheduled at** — and **no theatre field at all**.
  `SurgicalCaseController::store()` → `SurgicalCaseService::schedule()` (lines 41-60) does
  `SurgicalCase::query()->create([...])` with **no theatre, no slot, no lock, no overlap assertion and
  no transaction**. `theatre_slots` is written by the seeder alone.
- **So the concurrency invariant protects a table the product has no way to write**, while the table
  it does write — `surgical_cases.scheduled_at` — has no guard whatsoever. Two surgeons, one theatre,
  one time is not even expressible; two *cases* for one surgeon at one instant is accepted silently.
- **This is `D-183`'s shape inverted** — not a guard behind a guard, but a guard behind *nothing*: a
  correct, well-tested refusal that no user can ever reach. Compare `D-182` (a refusal must be
  reachable): here it is unreachable by construction.

> ⛔ **STILL OPEN — QA-FIX.6 Part 4 STOPPED here deliberately, and this is the determination.**
> That gate asked, before writing anything: is this **wiring** (an existing tested guard has no
> caller — the QA-FIX.4e shape) or a **feature** (theatre scheduling has no HTTP path and building
> one is new capability)? It is a **FEATURE**, on four independent counts, each verified:
>
> 1. **No HTTP consumer exists.** `TheatreSchedulingService` is referenced by no controller and no
>    route. The only route matching "theatre" is `surgery.pricing.theatre-time`, which sets a *tariff
>    price*, not a theatre. Nothing outside the Surgery services and tests even references the
>    `Theatre` model.
> 2. **A theatre cannot be created through the product at all**, so there would be nothing to pick.
>    `createTheatre()` is likewise callable only from the seeder, the tests and an artisan command.
> 3. **THE DECIDING FACT: a case cannot express a duration.** A `TheatreSlot` is a *bounded* block
>    (`starts_at` **and** `ends_at`), and `surgical_cases` has **no duration, length or end column**.
>    Wiring a theatre picker onto the case form would still not permit a booking, because the product
>    has nowhere to say how long a case runs. The data model cannot supply the guard's input.
> 4. **Reaching `QA-FIX.1b`'s past-start guard would contradict a documented decision.** That guard
>    (`BookingService`, the `$allowPastStart` call-site constant) is internal to the Appointment /
>    `Resource` / branch-clock path, and the SURGERY.G1 decision deliberately keeps a theatre a
>    Surgery-owned entity *not* forced into Scheduling's `Resource` — precisely because an
>    `Appointment` has no per-booking duration. Routing surgical scheduling through `BookingService`
>    to borrow the guard would undo the reason `TheatreSlot` exists.
>
> **So the gate's condition — "IF AND ONLY IF the guard is reachable by wiring an existing path" — is
> not met, and building the path inside a fix gate is exactly what it forbids.**
>
> **What Phase 6 actually observed is a THIRD invariant, and it is worth separating.** The driven
> defect was *two cases for one **surgeon** at one instant*. The theatre guard would not catch that
> even if it were wired: `assertNoOverlap` guards a **theatre**, not a person. Nothing anywhere in the
> product checks that a surgeon is already committed. Scheduling *can* express practitioner
> occupancy (`lockResource` over a `Resource`), but surgical cases do not use `Resource` — by the same
> G1 decision.
>
> **Exactly what closing each half would require:**
>
> | Half | Status | What closing it needs |
> |---|---|---|
> | Theatre double-booking (`P6-H2`) | **still stands** | A theatre management surface (create/list), a **duration** on the surgical case (migration + form), a theatre picker, and a route/controller pair calling the existing `bookSlot()`. The guard itself needs no change. |
> | Surgeon double-booking (observed) | **still stands** | A per-surgeon overlap guard that **does not exist anywhere**, plus the same missing duration. Either give surgical cases a `Resource`-backed booking (contradicting G1) or author a new Surgery-side guard. |
> | Past-dated case (`P6-H3`) | **still stands** | Independently small: a past-start refusal in `SurgicalCaseService::schedule()`. It does **not** depend on the theatre work and would suit its own gate. |

#### `P6-H3` — A surgical case can be scheduled six years in the past, and is then displayed as upcoming

- **Role:** `surgeon` · **Route:** `POST /surgery/cases`
- **Steps:** schedule *Simone Arnold · "QA6 past-time probe"* with **Scheduled at = 2020-01-01T08:00**.
- **What happened:** accepted without complaint, redirected straight to the case detail, and the case
  list renders it among the upcoming work:

  ```
  Simone Arnold · QA6 past-time probe    2020-01-01 08:00 · Dr. med. Isabelle Vogt   Scheduled
  ```

- **Cause:** `SurgicalCaseService::schedule()` validates the tenant of the patient and surgeon and
  nothing else about the time; `SurgicalCaseController::store()` validates `scheduled_at` as a date
  only. The `datetime-local` input carries **no `min` attribute**, so there is no client hint either.
- **QA-FIX.1b added exactly this guard to the Scheduling module** — bookings in the past are refused
  there. The surgery path does not reuse that machinery and has no equivalent of its own, which is the
  same root as `P6-H2`: Surgery re-implements scheduling instead of going through the module that
  already has the guards.
- **Why HIGH:** an OR list is a forward-planning instrument. A case dated 2020 sitting in `Scheduled`
  is indistinguishable at a glance from tomorrow's work, and the list is ordered by `scheduled_at`
  descending (`SurgicalCaseController.php:39`), so a mistyped year silently sorts a live case to the
  bottom of the board.

#### `P6-H4` — The scrub nurse's only two surfaces are unreachable: no link, and the routes that would lead there 403

- **Role:** `scrub_nurse` (`nadia.brun@klinik-bergblick.test`)
- **What she can open:** `…/checklist` **200** and `…/supplies` **200** — both gated `note.write`,
  both fully functional (a checklist item was confirmed as her, driven).
- **What she cannot open:** `/surgery/cases` **403**, `/surgery/cases/{case}` **403**,
  `/surgery/inventory` **403** — all `surgery.manage`, which `scrub_nurse` deliberately lacks.
- **There is no path from any of the first set to any of the second.** Her top nav is
  **Dashboard · Patients** only; no surgery link exists for any role (`P6-M1`). The checklist page's
  **only** link is *"Back to case"* → which **403s for her**. So the two pages that are her entire job
  in the product are reachable **only** if someone hands her a 26-character case ULID out of band.
- **Her landing page makes it worse, not better.** Of the four quick actions offered,
  `/nursing/dispatch` **403**, `/comms/inbox` **403** and `/scheduling/day-board` **403**; only
  `/patients/register` works, and registering patients is not a scrub nurse's job. She is offered four
  links, three refuse her, and the two that would help are not offered.
- **Why HIGH:** the permission design is correct and deliberate — the scrub nurse *should* confirm
  checklist items and record consumables without managing cases. The navigation makes that correct
  design unusable.

#### `P6-H5` — What the Phase-2 permission anomalies actually cost (the assigned follow-up)

Phase 2 recorded, by comparison rather than by driving, that `surgeon` lacks `medication.prescribe`
and that `anesthetist` lacks both `medication.prescribe` and `order.manage`. This phase **drove** them.

**The surgeon, missing `medication.prescribe`.**
- Driven: as `isabelle.vogt`, opened `/pharmacy/patients/{p}/medications` → **200**. The page renders
  the patient's medication list and the honest safety seam, and contains **zero buttons** — the
  prescribe form is correctly withheld from a role without the permission.
- **The cost is real and clinical:** the operating surgeon cannot write the post-operative
  prescription — analgesia, antibiotic prophylaxis, thromboprophylaxis — for the patient she has just
  operated on. She can read the med list and add nothing to it. In the product's own model that work
  must be handed to a `doctor` or `hospitalist`, and there is no surface that expresses the handoff.
- She **does** hold `order.manage`, so `/clinical/orders/review` → **200**; labs and imaging are
  available to her.

**The anesthetist, missing both `medication.prescribe` and `order.manage`.**
- Driven: as `johann.wyss`, `/clinical/orders/review` → **403**, and the top nav **loses the "Orders"
  link entirely** (measured: `Dashboard · Patients · Telehealth`, against the surgeon's
  `Dashboard · Patients · Orders · Telehealth`).
- **The cost is larger than the surgeon's, and it is the more surprising of the two.** Anaesthesia
  *is* drug administration, and the anesthetist cannot prescribe a medication anywhere in CareOS.
  Nor can they order the pre-operative workup — no bloods, no ECG, no imaging — because
  `order.manage` gates `Modules/Clinical/src/Services/OrderService.php:229`,
  `OrderableItemService.php:94`, `RadiologyOrderController.php:76` and `LabResultService.php:105`.
- What they **can** do is manage the surgical case and record the ASA class (`surgery.manage`), write
  and sign notes, and — through `surgery.manage` — **author surgical inventory items and adjust
  theatre stock**, which is a warehouse act rather than a clinical one.
- **The shape is consistent across both roles:** the surgery templates grant the *case-management*
  permissions generously and the *clinical-ordering* permissions not at all. Whether that is intended
  is a product decision and is not for this audit to make; what the audit can say is that **neither
  role can perform the medication act that its specialty is defined by**, and that this is invisible
  until you try — there is no message anywhere explaining the absence, only a missing form
  (`surgeon`) or a 403 (`anesthetist`).

---

### MEDIUM

#### `P6-M1` — No surgery permission exists in the shell's navigation map, so no role ever sees a surgery link

The staff top-nav gates its links on `HandleInertiaRequests::NAV_PERMISSIONS`
(`app/Http/Middleware/HandleInertiaRequests.php:24-41`) — a fixed list of **14** keys:
`patient.view`, `appointment.manage`, `encounter.manage`, `dispatch.manage`, `comms.manage`,
`billing.view`, `reporting.view`, `audit.view`, `ai.manage`, `admin.manage`, `dental.chart`,
`order.manage`, `competency.manage`, `data.import`.

**None of `surgery.manage`, `surgery.schedule` or `theatre.manage` appears.** The shell therefore
cannot recognise a surgeon, an anesthetist, a scrub nurse or a surgical scheduler, and **no surgery
link is rendered for any role** — measured on all four. A surgeon's workspace offers
*Dashboard · Patients · Orders · Telehealth* and, in quick actions, **"Nursing dispatch"**. The entire
operating-theatre vertical is reachable only by typing a URL. This is the same undersized map Phase 5
named for Pharmacy (`P5-M1` / `P5-H2`); Surgery is the second vertical it excludes.

#### `P6-M2` — The landing page offers "Nursing dispatch" to all four surgery roles, and it 403s

Driven for each role. `Landing.vue` renders `/patients/register`, `/nursing/dispatch` and
`/comms/inbox` unconditionally while the shared Inertia payload already carries the actor's
permissions. For `anesthetist` and `scrub_nurse`, `/nursing/dispatch` → **403**; for `scrub_nurse`
`/comms/inbox` and `/scheduling/day-board` also 403 (3 of 4 offered links refuse her). Identical in
shape to `P1-H1` / `P2-H2` / `P3-M7` / `P4-H5` / `P5-H2` — sixth consecutive phase.

#### `P6-M3` — The case detail offers a Billing link that 403s for both roles that can see the case

`resources/js/pages/Surgery/Case.vue:79-81` renders the checklist, supplies **and billing** links with
**no `v-if`**, although the same file correctly gates its forms on `actions.can_manage` (line 88, 110,
137) and `actions.can_write_note` (line 160). Billing is `billing.manage`, which **no surgery role
holds**, so every surgeon and anesthetist is shown a link that refuses them. The in-page instance of
`P6-M2`, and notable because the correct pattern is used ten lines below.

#### `P6-M4` — A case's checklist state is invisible from the case, so a completed case looks the same whether the checklist was run or not

Driven: the case was taken `scheduled → pre_op → in_progress → **completed**` with **zero** checklist
items confirmed, no team members, no notes and no ASA. Nothing warned, nothing blocked, and — the
finding — **nothing on the case detail records the fact**. The header shows only a link labelled
*"WHO safety checklist"*, with no count, no state and no date.

**The checklist page itself is exemplary and is recorded as a guard holding** (see below): it says
*"This checklist is a record of what the team confirmed. It does not block the surgery — the team owns
the decision to proceed."* and shows honest per-phase counts (`0 of 7`, `0 of 5`, `0 of 5`).

**The defect is that this honesty lives only on a page nobody is required to open.** The case detail —
the page the surgeon, the anesthetist and any later reader actually use — carries none of it. A
completed case with 0 of 17 confirmed and one with 17 of 17 are byte-identical on the surface that
matters. Surfacing the count on the case would cost one integer and would not make the checklist
blocking, so it does not touch the D-179 fence.

#### `P6-M5` — Team membership records no attribution, and cannot distinguish planned from present

`surgical_case_team_members` is `id, tenant_id, surgical_case_id, staff_profile_id, team_role,
created_at, updated_at` — and that is all. There is **no** `added_by`, **no** `present` / `actual`
marker, **no** scrub-in/scrub-out time, and **no** way to record that someone was relieved mid-case.
`SurgicalCaseService::addTeamMember()` writes the row and no event. So the "surgical team" is a flat
list of names with no record of who asserted them or whether they were in the room — which is the same
question `P2-C1` raised about attribution, one table further on.

> ➕ **EXTENDED during QA-FIX.6b (still open, not fixed).** Two further properties of this same write
> were found while fixing `P6-C2`, and they make this finding worse than recorded:
> - **It is UNAUDITED.** `grep SurgicalCaseTeamMember app/Providers/AppServiceProvider.php` returns
>   nothing, so adding or re-roling a member of a surgical team raises no audit event at all. This
>   corrects `P6-C2`'s claim that the ASA write was the *only* unaudited write in the module — there
>   were two. The correction is written into `P6-C2` above, where its evidence sits.
> - **It OVERWRITES with no history.** `SurgicalCaseService.php:112` uses `updateOrCreate` keyed on
>   `(surgical_case_id, staff_profile_id)`, so re-roling someone replaces `team_role` in place. Who
>   was scrubbed in as what, and when it changed, is unrecoverable — the same defect `P6-C2`'s
>   `forceFill` had.
>
> **Why it was not fixed alongside `P6-C2`:** QA-FIX.6b is scoped to the ASA assessment. The remedy
> here is the same append-only + audit-hook recipe and would be small, but it is a different write on
> a different table, and widening a fix part to cover findings a gate did not name is how scope
> creeps. Recorded for whichever gate takes `P6-M5`.

#### `P6-M6` — The surgeon, anesthetist and team dropdowns are role-blind: a pharmacy technician is offered as "Primary surgeon"

All three staff selectors on `/surgery/cases` and the case detail list **every one of the 20 staff
profiles in the tenant**, unfiltered. Offered as *Primary surgeon* and as *Anesthetist*: `Tim Graf`
(pharmacy technician), `Sara Roth` (phlebotomist), `Rita Moser` (admissions clerk), `Elena Costa`
(lab tech), `Urs Baumann` (bed manager), `Yusuf Demir` (triage nurse). `SurgicalCaseController::
staffOptions()` returns the unfiltered profile list, and the service validates only that the profile
is in the tenant. **Driven to a write in `P6-C2`**, where a pharmacy technician was successfully
recorded as the assessing anesthetist on a real case.

#### `P6-M7` — Surgical counts are a tick-box, not numbers, so a discrepancy cannot be recorded

The gate asked what the product does about counts. The answer: the WHO checklist carries the item
*"Instrument, sponge, and needle counts are correct"* as a **boolean confirmation** and there is no
numeric count anywhere — no swab count, no instrument count, no needle count, no first/second count,
and **no way to record that a count did not reconcile**. `/surgery/cases/{case}/supplies` records
*consumables used* (item + quantity) and *implants placed*, which is issue-tracking, not counting.

This is **honest as far as it goes** — the tick says only that the team confirmed the counts, and
CareOS does not claim to have counted anything. It is recorded as MEDIUM rather than as a fence
problem because the gap is a *missing capability* in a surface that otherwise models theatre practice
closely: a retained-item event is precisely the case where the count did **not** reconcile, and the
product provides nowhere to write that down.

#### `P6-M8` — A confirmed checklist item can be un-confirmed after the case is closed, and the screen shows no history

Driven on a case already in `completed`: ticked *"Patient has confirmed identity, site, procedure, and
consent"* (a **sign-in**, pre-anaesthesia item) — count went `0 of 7` → `1 of 7`; clicked it again —
back to `0 of 7`, with no warning, no confirmation prompt and no visible trace.

**The database is better than the screen, and that correction matters.** The un-confirm did **not**
mutate the first row: `surgical_checklist_items` holds **two** append-only rows for the same
`template_item_id`, one `checked=1 confirmed_by=31 confirmed_at=08:27:46` and one `checked=0
confirmed_by=31 confirmed_at=08:28:05`, and **both** produced a
`surgical_checklist.item_confirmed` audit event. The record is honest; the *presentation* discards it.

**What is left as the finding:** (a) the screen shows neither who confirmed an item nor when, so the
append-only history is invisible where it is needed; (b) nothing indicates that a sign-in item was
confirmed **after** the case completed — the case closed at 08:26:45 and the item was confirmed at
08:27:46, a contradiction the record contains and the screen cannot show; and (c) a safety item can be
silently re-opened by a mis-click with no confirmation step.

#### `P6-M9` — The actor is stored on every case event and usage row, and displayed on none of them

The case detail's HISTORY panel renders `Start pre-op · 2026-09-07 08:26` — the transition and the
time, and **no name**. The supplies page renders `Steriler Tupfer · ×2 · 2026-09-07 08:31` — no name.
Both underlying tables *do* carry the actor: `surgical_case_events.performed_by` (verified as the
**actor**, not the plan — Johann Wyss acting on Isabelle Vogt's case recorded `performed_by = Wyss`)
and `case_item_usages.used_by`. So the data is correct and the display simply omits it. This is the
benign half of the `P2-C1` shape: attribution captured, attribution not shown.

#### `P6-M10` — Surgical charge capture writes its idempotency key last, so a partial failure both orphans charges and defeats the guard against re-billing

**ESTABLISHED FROM CODE, NOT DRIVEN — and the reason is `P6-C1`.** The capture control cannot render,
so there is no browser path to this code at all. It is recorded because the gate asked for cross-phase
pattern 6 to be probed in **both** directions, and this is the *refused-write* direction that `P6-C1`
(the succeeded-write direction) would otherwise have left unexamined. Per this audit's method
statement, the wording says so explicitly rather than implying a driven result.

- **Route:** `POST /surgery/cases/{case}/billing/charge` → `SurgicalBillingService::chargeCase()`
- **The shape:** `chargeCase()` (lines 106-138) has **no `DB::transaction`**. It captures N charges in
  three separate steps — the procedure, the theatre time, and one per priced consumable/implant — and
  **then**, in a second loop, writes the N `SurgicalCaseCharge` link rows:

  ```php
  $captured->push($this->charges->captureManual(…$procedureCode…));   // commit 1
  $captured->push($this->charges->captureManual(…THEATRE_TIME_CODE…)); // commit 2
  foreach ($this->pricedUsageTotals($case) as $code => $quantity) {    // commits 3..N
      $captured->push($this->charges->captureManual(…$code…));
  }
  foreach ($captured as $charge) {                                     // the links, only now
      SurgicalCaseCharge::query()->create([...]);
  }
  ```

  Each `captureManual` commits independently — `ChargeCaptureService::capture()` wraps **each single
  charge** in its own `DB::transaction` (line 128). So a throw partway through leaves the earlier
  charges **durable on the patient's account with no link row**.
- **The idempotency guard is what makes this worse than an orphan.** `chargeCase()` opens by reading
  `surgical_case_charges` to decide whether the case is already billed (lines 112-115). Those link
  rows are written **after** every charge. So a partial failure leaves the guard reading *empty*, and
  a retry — the natural response to an error — **re-captures every charge that already succeeded**.
  The mechanism intended to prevent double-billing is the one that permits it.
- **The escaping exception is real.** `SurgicalBillingController::charge()` catches only
  `SurgicalBillingException|CrossTenantReferenceException`; a `TariffNotFoundForDateException` raised
  by a later `captureManual` (an unpriced consumable code, or a tariff with no version covering the
  case's service date) is **not** caught and escapes the controller.
- **This is the third module with the Phase-3 shape.** `P3-C1` (payment then allocation),
  `P4-H2` (per-action transactions with no batch boundary), and now surgical charge capture — the
  same create-then-associate pair outside a transaction that Phase 3 predicted would generalise.
- **Recorded MEDIUM, not CRITICAL, and the reason is stated:** it is **latent**. No user can trigger
  it while `P6-C1` stands. The Phase-4 precedent for grading a latent defect below its active
  severity is `P4-C4` (re-graded CRITICAL → HIGH for exactly this reason). **It becomes active the
  moment `P6-C1` is fixed**, which is the order those two findings should be read in.

> ✅ **FIXED — QA-FIX.6a, commit `9d5c047` (D-208), in the SAME part as `P6-C1` and for that
> reason.** Fixing C1 makes the capture control reachable, so shipping C1 alone would have switched on
> a defect nobody had ever run.
>
> - **The unit of idempotency is the CASE** ("a case is billed once" — the link table's own migration
>   docblock), so the case's whole charge set is now the unit of atomicity: one `DB::transaction`
>   around every capture **and** its link row, with each link written **beside** its charge rather than
>   in a later pass. That pairing is copied from `BedBillingService::accrueBedDays()`, whose unit is a
>   bed-day and which has always been transactional — the discipline already existed in the repo.
> - **The case row is additionally locked `FOR UPDATE`** (the `lockTheatre` / `lockResource` idiom) so
>   two concurrent captures serialise instead of both reading an empty idempotency guard. Without it,
>   "billed once" was true only for sequential retries: `unique(tenant_id, charge_id)` cannot stop two
>   racing captures, because the charge ids differ.
> - **The hash-chained audit ledger is safe under this, and that was checked rather than assumed.**
>   `AuditService::record()` re-derives the chain head **from the database on every append**
>   (`SELECT hash, occurred_at … ORDER BY … LIMIT 1 FOR UPDATE`) — there is no cached head anywhere —
>   so a rollback takes its audit rows with it and the next append reads the unchanged real head. No
>   gap, no dangling `prev_hash`. A test asserts the chain still verifies after a failed capture.
> - **What it costs, stated rather than glossed:** nested `DB::transaction` is a savepoint, so
>   `AuditService`'s **per-tenant** `FOR UPDATE` on the latest `audit_events` row is now held from the
>   first capture until the whole capture commits, rather than being released per charge. Every other
>   audited write in that tenant serialises behind that window. It is bounded — tariff resolution plus
>   N charge inserts and N link rows, with no HTTP, PDF or queue work inside the closure — and it is
>   the price of the charges and their links being one fact. A rollback also erases the audit trace of
>   the **attempt**; correct for chain integrity, and worth knowing when reading the log.
> - **Guarded by four tests, mutation-checked.** Removing the outer transaction reddens three of them:
>   the failed capture then leaves 1 orphan charge and 0 links, and the retry double-bills the
>   procedure exactly as the finding describes. The fourth exercises a clean run and correctly stays
>   green. **The retry test is the D-182 shape**: it would pass trivially without the guard only if the
>   guard worked, and it fails loudly without it.
> - **BROWSER-VERIFIED, which this finding never was before** — it was code-established precisely
>   because `P6-C1` made it unreachable. Charges were captured **through the UI** on a fresh case, and
>   the database showed **2 charges and 2 link rows** — every charge paired. Pressing *Capture charges*
>   a second time produced **no duplicates** (still 2), and `verifyChain()` returned true afterwards.
> - **No historical rows rewritten** (the D-193/D-197/D-202 precedent): only the write path changed.
>
> **FOUND WHILE FIXING, NOT FIXED:** `Modules/ED/src/Services/EdBillingService.php` carries the
> **identical** shape — idempotency read (`:102`), captures (`:115`, `:121`), link loop (`:125`), and
> **no transaction**. Recorded for **Phase 7** rather than fixed here, per gate discipline.

---

### LOW

#### `P6-L1` — A fifth date mechanism: ISO string-slicing, which prints stored UTC verbatim

Four of the seven Surgery pages format timestamps as
`iso.replace('T', ' ').slice(0, 16)` — `Case.vue:64`, `CaseBoard.vue:29`, `CaseSupplies.vue:37`,
`Inventory.vue:45`. The payload is `->toIso8601String()` (e.g. `2026-09-10T09:00:00+00:00`), so the
slice keeps the **UTC wall-clock and silently discards the offset**: a theatre booking stored as
09:00 UTC displays as `2026-09-10 09:00` to a Zurich user for whom it is **11:00**.

This is a **new mechanism**, distinct from the two Phase 2 named (raw-UTC printing and
browser-locale `Intl`): it is string truncation, which cannot be timezone-aware even in principle. It
also means Surgery is the one module whose dates are *not* affected by the viewer's locale — the
Phase-5 `America/Los_Angeles` divergence does not appear here, because nothing is converted at all.
Recorded LOW because the format is at least stable and unambiguous (`YYYY-MM-DD HH:mm`); the
two-hour offset it hides is the real cost.

#### `P6-L2` — Surgery money renders with no currency unit

`CaseBilling.vue:39` and `SurgicalPricing.vue:43` both use
`(minor / 100).toLocaleString(locale.value, { minimumFractionDigits: 2, maximumFractionDigits: 2 })`
with **no currency**. Driven: the case-billing screen shows `2,500.00`, `450.00`, `24.00` — no `CHF`,
and US grouping (`2,500.00` rather than the Swiss `2'500.00`). The shared, tested `formatSwissMoney()`
exists and is not used. Identical to `P5-M3` and to the Phase-3 currency finding — third consecutive
phase, third module.

> ✅ **FIXED — QA-FIX.6a, commit `9d5c047` (D-208)**, as a consequence of fixing `P6-C1` rather than
> as a separate effort: once the engine formats the figures, the currency comes with them. Both Surgery
> money surfaces now render server-formatted amounts — **browser-verified** as `CHF 2'500.00`,
> `CHF 450.00`, `CHF 24.00`, `CHF 6'687.20` and `CHF 2'725.00`, with the Swiss apostrophe grouping and
> the currency read from the charges' own tariff catalog rather than assumed. The client-side
> `toLocaleString` is gone from every Surgery surface and a fence test keeps it out.
>
> **Note on scope:** this closes the finding for **Surgery only**. `P6-L2`'s sibling instances outside
> this module — and `P5-M3`, and the Phase-3 currency finding — are untouched, and the ED, Lab and
> Radiology billing surfaces still format money client-side (recorded under `P6-C1`'s banner).

---

### Guards verified holding (probed in the browser, not assumed)

- **The electric fence is intact across the whole vertical.** The case detail states, in the product's
  own words: *"The ASA and Mallampati classes are assigned by the anesthetist and recorded here — **the
  system computes no surgical-risk score**."* Driven with ASA III and ASA I: no score, no band, no
  colour, no ranking, no "high risk" label, no suggested action. A search of the Surgery module for
  risk/score/predict/suggest/recommend derivation returns nothing that computes a judgment.
- **The WHO checklist is honest about what it is and what it is not.** Verbatim: *"This checklist is a
  record of what the team confirmed. It does not block the surgery — the team owns the decision to
  proceed."* The service docblock is equally explicit that it *"NEVER touches the case status"* and
  that a blocking checklist *"would be a safety-enforcement medical device"*. The read model exposes a
  factual `checked / total` (`0 of 7 confirmed`) and **never** a "passed" or "safe to proceed" verdict.
  **This is D-179 handled correctly**, and it is the clearest statement of the fence the audit has
  found outside the medication seam. Driven: the case completed with 0 of 17 and nothing anywhere
  claimed the checklist had been done.
- **The checklist record is append-only with full attribution.** A confirm and a subsequent
  un-confirm wrote **two** rows, each with `confirmed_by` and `confirmed_at`, and each raised a
  `surgical_checklist.item_confirmed` audit event. Nothing was mutated or deleted.
- **Case-event attribution is the ACTOR, not the plan — the `P2-C1` shape held.** Probed deliberately
  with a role split: `johann.wyss` (anesthetist) transitioned a case whose `primary_surgeon_id` is
  Isabelle Vogt, and `surgical_case_events.performed_by` recorded **Wyss**. An earlier probe of mine
  was confounded (I drove Vogt's case as Vogt, where actor and plan coincide) and was redone with a
  second user before this was recorded.
- **The implant recall lookup works end to end, and its wording is honest.** Driven: placed a
  `Titanschraube` with lot `LOT-QA6-778`, serial `SN-QA6-001`, UDI `UDI-QA6-XYZ` on a real case; stock
  decremented **20 → 19**; the placement appeared in *Implants placed* **and** in *Patient implant
  history*; then searched `/surgery/inventory?lot=LOT-QA6-778` and got
  `Simone Arnold · Titanschraube · lot LOT-QA6-778 · UDI UDI-QA6-XYZ · 2026-09-07 08:47`. The screen
  describes itself as *"A factual traceability lookup, not a device-safety verdict"*, and its empty
  state says *"No implants **match** that lot / UDI / serial"* — a statement about the index, not a
  clearance. **This is the strongest feature in the module.**
- **Implant traceability is enforced, not merely requested.** `lot_number` is `required`; a placement
  with a blank lot was refused server-side (`P6-C3` is that the refusal is invisible, not that it is
  absent).
- **The stock guard holds under an over-quantity request.** 99999 against 490 on hand: nothing
  recorded, `on_hand` unchanged, no `case_item_usages` row, no stock movement.
- **The surgery lifecycle is legal-transitions-only, driven.** From `in_progress` the UI offered only
  **Complete** — *Cancel case* correctly disappeared once the case was underway; from `completed`,
  only **Move to post-op**. Each transition wrote an append-only `SurgicalCaseEvent` **and** a
  `surgical_case.<status>` audit event.
- **The module audits comprehensively.** Distinct actions observed: `surgical_case.scheduled`,
  `surgical_case.pre_op / in_progress / completed / post_op`, `surgical_checklist.opened`,
  `surgical_checklist.item_confirmed`, `surgical_item.created`, `surgical_item.used`,
  `surgical_stock.received`, `surgical_stock.used`, plus patient-scoped `read` logging on every case
  view. `P6-C2` is severe precisely *because* it is the single exception to this.
- **The Phase-5 fix holds in a Phase-6 context.** Reached as the **surgeon**,
  `/pharmacy/patients/{p}/medications` renders the QA-FIX.5a panel with its honest empty state
  verbatim: *"No allergies are recorded for this patient. That is the state of the record — it is not
  the result of a check."* and *"No automated medication-safety checking is configured… is a
  certified-partner function and is not performed here."* Confirmed rendering for a role the fix was
  not written for.
- **Tenant isolation and RBAC hold at the route layer.** Every 403 above is a genuine server refusal
  from `Gate::authorize`, not a hidden link; the scheduler's 403s were measured by direct `fetch`
  rather than by absence of a control.

### Ruled out after measurement (recorded so they are not re-reported)

- **The served JS bundle is not stale.** Suspected while diagnosing `P6-C1`, because the rendered DOM
  contradicted the source. Checked: `public/build/manifest.json` (Sep 6 22:53) postdates every
  `resources/js/pages/Surgery/*.vue` (Jul 27), and the shipped chunk contains all 13
  `surgery.billing.*` translation keys including the capture form's. The contradiction is the prop /
  function name collision, not a build-freshness problem.
- **There are no empty `catch` blocks in the Surgery module.** Phase 5's `catch (Throwable) { }` shape
  was searched for specifically and does not recur: all 13 catches name their exception type and flash
  a message. `P6-C3` is a *rendering* gap, not a swallowing one.
- **Consumable usage and stock decrement are transactional together.** The over-quantity refusal left
  neither a usage row nor a movement row nor a changed `on_hand`.

### Not tested, and why

- **Performance** — explicitly out of scope per the gate, deferred to staging.
- **Concurrent theatre booking under real contention** — the parallel-hammer test exercises it at the
  service layer, but `P6-H2` establishes there is **no HTTP path** to book a slot, so there is nothing
  to drive in a browser.
- **Issuing a surgical invoice** — blocked by `P6-C1`: the button cannot render, so the
  `POST …/billing/invoice` path has no reachable trigger. The route and service were read, not driven.
- **`cancelled` as a terminal state with `status_reason` / `cancelled_at`** — the columns exist and the
  *Cancel case* button was observed appearing and disappearing at the correct lifecycle points, but the
  cancellation itself was not driven, to leave the driven case in a state comparable to the seed.
- **A second tenant's surgical data** — `klinik-bergblick` is the only tenant with any, so
  cross-tenant surgical isolation was verified from the service's `assertSameTenant` calls and the
  existing test, not driven.

## Phase 7 — Emergency Department

**Date:** 2026-09-07 · **Top commit at audit time:** `6aebf15` (the QA-FIX.6 Part 4 determination backfill),
CI `completed / success` confirmed via `commits/<sha>/check-runs`. Tree clean apart from untracked
`docs/marketing-site/`; no live `<pending>` markers.

**AUDIT ONLY.** No app code, test or seeder was changed. The only writes are those made by *driving the
product*: two triages recorded, two re-triages, one refused triage, three flow transitions, and one
ED→inpatient admission. All demo tenants were re-seeded beforehand and verified by query.

### Environment

| Item | State |
|---|---|
| ED tenant | `klinik-bergblick` — the **only** tenant with ED data |
| ED visits | **4** — `dispositioned/admit` (with a stay), `dispositioned/discharge`, `arrived`, `triaged` |
| Triages | **3**, all ESI, at levels **2, 4 and 3** — a most-urgent and least-urgent pair, the D-169 control set |
| Visit events | 13, across `arrived / triaged / in_treatment / awaiting_disposition / dispositioned` |
| ED charges | 4 (`ED-ATTENDANCE` ×2, `ED-XRAY` ×2), **all invoiced** |
| ED→inpatient | present — the composite episode: a `dispositioned/admit` visit carrying a `stay_id` |
| **Redis** | **UP — honestly.** `PING` → `+PONG` (Memurai), and genuinely in use (`CACHE_STORE=redis`) |
| App timezone | `UTC` storage; tenant `Europe/Zurich`; browser (viewer) `America/Los_Angeles` |
| Out of scope | **Performance — deferred to staging**, per the gate |

**WHAT THE SEED DOES NOT CONTAIN, stated rather than worked around.** No visit currently sits in
`in_treatment` or `awaiting_disposition` (both appear only in the event log); **no
`left_without_being_seen` visit at all**; **no `transfer` disposition**; **no re-triage** (one triage per
visit); and **only the ESI scale** — `MANCHESTER` and `CTAS` are selectable but unexercised. The missing
states were created **by driving the product** rather than by seeding: the flow was advanced
`triaged → in_treatment → awaiting_disposition → dispositioned/admit` through the real buttons, and both
re-triages and the Manchester comparison below were recorded through the real form.

### Roles covered

| Role | Driven as | Permissions (`RbacProvisioner::ROLE_TEMPLATES`) |
|---|---|---|
| `ed_physician` | `clara.meier@klinik-bergblick.test` | `patient.view`, `encounter.manage`, `note.write`, `note.sign`, `order.manage`, `ed.manage`, `admission.manage` |
| `triage_nurse` | `yusuf.demir@klinik-bergblick.test` | `patient.view`, `encounter.manage`, `note.write`, `ed.manage`, `triage.record` |
| `ed_charge_nurse` | `marco.bianchi@klinik-bergblick.test` | `patient.view`, `encounter.manage`, `note.write`, `note.sign`, `note.supervise`, `order.manage`, `reporting.view`, `ed.manage`, `triage.record` |

**Excluded, with reasons:** none — `ROLE_TEMPLATES` contains exactly these three ED roles. `org_admin` was
*not* driven as an ED role; it appears below only where noted, because **no ED role holds `billing.manage`**
and the ED billing surface is otherwise undriveable.

**The asymmetries, measured:**

| | `ed_physician` | `triage_nurse` | `ed_charge_nurse` |
|---|---|---|---|
| `triage.record` (record a triage) | **no** | yes | yes |
| `admission.manage` (admit from ED) | yes | **no** | **no** |
| `note.sign` | yes | **no** | yes |
| `order.manage` | yes | **no** | yes |
| `billing.manage` | **no** | **no** | **no** |
| `medication.prescribe` | **no** | **no** | **no** |

### Surfaces driven

| Surface | Route | Roles | Result |
|---|---|---|---|
| Landing | `/app` | ed_physician | **all four links 403** (`P7-H4`); no ED link in nav |
| ED tracking board | `/ed/board` | physician ✅ · nurse ✅ | counts live and consistent; acuity sort **inverts** on Manchester (`P7-C3`) |
| Board @ 390 px | `/ed/board` | physician | **0 of 4 nav links visible**, no menu button (`P7-M4`) |
| Triage (read) | `/ed/visits/{v}/triage` | physician ✅ (no form — correct) | seam states "No automated suggestion" |
| Triage (record) | `POST …/triage` | triage nurse ✅ | attributed to a **defaulted** picked person (`P7-C1`) |
| Re-triage | `POST …/triage` | triage nurse ✅ | **appends** — earlier triage survives ✅ |
| Refused triage | `POST …/triage` | triage nurse | **silent** — no message, nothing recorded (`P7-H2`) |
| Flow transitions | `POST …/transition` | triage nurse ✅ | legal-only; counts updated live |
| Disposition | `/ed/visits/{v}/disposition` | nurse ✅ (no Admit) · physician ✅ (Admit) | state-gated honestly |
| **ED→inpatient admit** | `POST …/disposition` | ed_physician ✅ | stay created; **admitting clinician defaulted** (`P7-C2`) |
| ED clinical record | `/ed/visits/{v}/record` | physician ✅ | encounters, vitals, orders — **no medication section** (`P7-H3`) |
| ED billing | `/ed/visits/{v}/billing` | **all three 403** | unreachable by the whole group (`P7-H5`) |
| Clinical orders | `/clinical/orders/review` | physician ✅ | reachable |
| Ward board | `/hospital/wards` | physician ✅ | reachable |

---

### THE TRIAGE / ACUITY BOUNDARY — this phase's assigned question

**1. Does the product compute or suggest an acuity? NO — and this is the cleanest seam in the audit.**
`NullTriageAcuityProvider` is the only shipped implementation and returns `AcuityResult::none()` for every
call. Its docblock states the crucial distinction in the product's own words: *"returning `none()` means
'CareOS makes no acuity claim', not 'this patient is low acuity'"*, and calls a homemade acuity computer
**"a PERMANENT non-goal"**. Driven: the triage form's suggestion area renders *"No automated suggestion.
The triage nurse assigns the acuity."* — which cannot be read as a clearance (the `P5-C1` lesson applied
correctly). The acuity select is **`Select a level` with no default** — the clinical judgment is never
prefilled. A repo-wide search for anything deriving acuity from vitals, complaint, age or time returns
nothing.

**2. D-169 on a legitimately ordinal field — the hardest case in the product, and it PASSES byte-for-byte.**
With an **ESI 1** (most urgent) and an **ESI 3** both active on the board, every computed style is
identical:

| | Nora Bianchi (ESI 1) | Paul Widmer (ESI 3) |
|---|---|---|
| card class | `glass-card p-4 border-euca-200 bg-euca-50` | *identical* |
| card background | `rgb(247, 250, 245)` | *identical* |
| card border | `rgb(220, 232, 215)` | *identical* |
| badge class | `rounded-full bg-ink/5 px-2.5 py-1 text-xs font-semibold text-ink` | *identical* |
| badge bg / colour / weight / size | `oklab(…/0.05)` / `rgb(42,51,42)` / `600` / `12px` | *identical* |

The **only** difference is the text: "ESI 1" versus "ESI 3". The acuity badge carries **one fixed class
string with no `:class` binding at all** (`Board.vue:127`). The colour that *does* vary is
`statusClass(v.status)` — the **flow state**, explicitly commented *"an operational colour (NOT a clinical
severity)"*. The sort control is labelled **"Recorded acuity"**, not "priority"; an untriaged patient reads
**"Not yet triaged"**, never a default level. This is the PC.P7 formulation honoured exactly: the ordering
is a fact the clinician assigned, and the product adds no tint, ramp, breach timer or target on top of it.

**3. …BUT THE ORDERING ITSELF IS WRONG ON TWO OF THE THREE SCALES — see `P7-C3`.** The board sorts by
`localeCompare` on the level *string*. That is correct for ESI and CTAS (`'1'…'5'`) and **inverts** for
Manchester (`red, orange, yellow, green, blue`), which sorts alphabetically to `blue, green, orange, red,
yellow`. Driven and confirmed in the browser.

**4. The Phase-6 inheritance — PARTIAL, and the split matters.** Phase 6 found `EdTriage`'s docblock names
`SurgicalCase::asa_class` as the shape it followed, and that ED added the record discipline the ASA lacked.
Verified in the browser:

| Property | Result |
|---|---|
| Re-triage **appends** (never overwrites) | ✅ **HOLDS** — driven; the earlier ESI 3 survives beside the new MANCHESTER red, and `ed_triages` carries `SIGNAL '45000'` UPDATE/DELETE triggers |
| The write is **audited** | ✅ **HOLDS** — `ed_triage.recorded`, and the audit's `actor_id` is the **real actor** (`yusuf.demir`) |
| Attributed to the **ACTOR** | ❌ **FAILS** — the record names a *picked, defaulted* person (`P7-C1`) |

So ED inherited two-thirds of the discipline. The actor **is** recoverable — from the audit ledger — but
the clinical record itself misattributes.

**5. No other computed clinical judgment.** No EWS/NEWS, no deterioration score, no sepsis or risk flag, no
predicted disposition. The disposition screen states: *"The disposition is the clinician's decision — the
system records it, it never computes or suggests it."* The only computed figure on the board is an elapsed
time (`12 min`) — a plain duration since arrival, with **no target, no breach threshold and no colour**.

---

### CRITICAL

#### `P7-C1` — A triage is attributed to a person the form pre-selects, and the actual clinician is stored nowhere on the record

- **Role:** `triage_nurse` · **Route:** `POST /ed/visits/{visit}/triage`
- **Steps:** log in as `yusuf.demir` (the triage nurse); open an untriaged visit's triage page; fill the
  presenting complaint; choose **ESI 1**; **do not touch the "Triage nurse" dropdown**; submit.
- **What happened:** the triage history reads **"Triaged by Beat Suter"**. Beat Suter is the
  **`surgical_scheduler`** — not a nurse, not in the ED, and not the person who recorded it. The database
  confirms `triaged_by → Beat Suter [beat.suter@klinik-bergblick.test]` while the actor was
  `yusuf.demir@klinik-bergblick.test`.
- **Cause.** `EdTriageController.php:92` validates `'triaged_by' => ['required','string']` — **the client
  submits it**; `:105` resolves *any* `StaffProfile` in the tenant; `TriageService.php:83` writes
  `'triaged_by' => $nurse->id`. The actor is used only for the Gate and is then discarded, and
  **`ed_triages` has no actor column at all** (`id, tenant_id, patient_id, ed_visit_id, triaged_by,
  triaged_at, presenting_complaint, acuity_scale, acuity_level, created_at, updated_at`).
- **WHY THIS IS WORSE THAN `P6-C2`, which it otherwise mirrors exactly.** The surgical ASA required an
  operator to *actively pick* the wrong person. Here `Triage.vue:40` **pre-selects one**:
  `triaged_by: props.options.nurses[0]?.id ?? ''` — the first of **20 unfiltered staff profiles**, ordered
  by display name. Measured in the browser: the dropdown was already set to "Beat Suter" before any
  interaction. **The default path produces the wrong attribution**; a nurse must notice a field they have
  no reason to touch in order to get it right.
- **The mitigation, stated precisely.** The audit ledger *does* record the true actor
  (`ed_triage.recorded`, `actor_id = 37 = yusuf.demir`), so the real clinician is recoverable — from the
  audit trail, not from the clinical record. That is better than the ASA had before QA-FIX.6b, and it is
  not the same as the record being right.
- **Why CRITICAL:** a triage is the ED's core safety record and its acuity drives who is seen first. A
  record naming an uninvolved non-clinician as the assessor is a triage-record misrepresentation.

> ✅ **FIXED — QA-FIX.7a, commit `d3e0f3c` (D-211).** QA-FIX.6b's remedy, applied unchanged.
> `ed_triages` gains **`recorded_by`** — a `users` FK written from the authenticated actor inside
> `TriageService::record` and deliberately absent from the controller's validation rules, so it cannot
> be submitted. `triaged_by` is untouched and still means the nurse whose assessment it is; the two are
> resolved separately on the triage screen and neither substitutes for the other (D-195).
> **`Triage.vue:40`'s default is gone** — `triaged_by: ''` with a non-selectable "Select the triage
> nurse" prompt and `required`, matching the server rule that was always there. The audit context now
> carries both people.
> **WHY A COLUMN HERE AND NOT AN EVENT ROW.** `ed_visit_events.performed_by` already records an actor,
> but a triage only transitions the visit on the FIRST triage — a **re-triage**, the case this table is
> append-only to support, appends no event at all, so for every re-triage there would be no actor
> anywhere on the clinical record. A test drives exactly that and asserts only one `triaged` event
> exists for two triage rows.
> **The column is NULLABLE and no historical row is rewritten** (D-211, following D-193/D-197/D-202):
> rows written before it existed genuinely have no recorded actor, and inventing one would be a
> fabricated attribution — the defect, not the fix. Those actors remain in the audit ledger, which is
> where this finding recovered them.
> **Guarded by** six tests, mutation-checked: removing `'recorded_by' => $actor->id` reddens the actor
> test; restoring `props.options.nurses[0]?.id` reddens the structural guard. A positive control asserts
> the server still **refuses** a triage naming no nurse, so the default was not removed by loosening a
> rule. A second positive control re-asserts the fence at column level, because a new column is exactly
> when a judgment column could slip in.

#### `P7-C2` — The same defaulted attribution on an INPATIENT ADMISSION: the admitting clinician is whoever sorts first

- **Role:** `ed_physician` · **Route:** `POST /ed/visits/{visit}/disposition` (the ED→inpatient handoff)
- **Steps:** as `clara.meier` (ED physician), advance a visit to `awaiting_disposition`, open the
  disposition page, click **Admit**, **touch neither the bed nor the clinician select**, submit.
- **What happened:** the admission succeeded — visit `dispositioned/admit` with a `stay_id`, a `Stay`
  created `admitted` / `admission_type=emergency` / bed `CH-02` assigned, both steps audited with the real
  actor. **And `stays.admitting_clinician_id` names Beat Suter**, the surgical scheduler, while
  `clara.meier` performed the admission.
- **Cause.** `Disposition.vue:29`:
  `const form = reactive({ note: '', bed_id: props.actions.beds[0]?.id ?? '', clinician_id: props.actions.clinicians[0]?.id ?? '' })`
  — **both** the bed and the admitting clinician default to the first option. `EdDispositionController.php:103-104`
  accepts them from the request; `:117` passes them straight through.
- **Why CRITICAL, and arguably worse than `P7-C1`:** the admitting clinician is carried on the **inpatient
  stay**, outside the ED entirely, where later readers have no reason to suspect it. It is also a
  **third** instance of one pattern in this module — triaged-by, admitting clinician, and bed all default
  to "first in the list" — so the shape is systemic rather than a slip.

> ✅ **FIXED — QA-FIX.7a, commit `d3e0f3c` (D-211), and it needed NO new column.**
> **A CORRECTION TO THIS FINDING, made while fixing it.** The finding treats `P7-C2` as `P7-C1` on a
> different table and implies the same remedy. It is not. `stay_events.performed_by` **already records
> the admission actor** — a `users` FK written from `AdmissionService::admit`'s own `$actor`, inside the
> same transaction as the `Stay`, never request-sourced. Phase 7 in fact observed this ("both steps
> audited with the real actor") without drawing the consequence: the actor was never missing from the
> admission, it was missing from every ED **surface**. Adding `stays.recorded_by` would have created a
> second, independently-writable home for one fact — what D-199 exists to prevent — so it was not added,
> and a test pins its absence so a later "consistency with `ed_triages`" pass does not add one.
> **What was actually wrong, and is fixed:** `Disposition.vue:29`'s two defaults are gone
> (`bed_id: '', clinician_id: ''`, non-selectable prompts, `required`), and the disposition screen now
> names **both** people for an admitted visit — the clinician named as admitting, and the actor who
> performed it, read back from the `admitted` stay event.
> **THE BED, decided separately, because a bed is not a person.** It is not an attribution and it is not
> D-195. It is fixed for a different reason: admitting **claims** the bed (free → occupied, a real ward
> and a real place the patient goes), and the default answered "which bed" with "the alphabetically
> first free one across every ward", which is not a reason. `bed_id` was already `required` server-side;
> the change is that the client stops supplying an answer nobody gave. A positive control asserts an
> empty admit is still refused **and that the bed is not claimed**.
> **The nurse/clinician distinction stays real.** An ED charge nurse routinely admits on the physician's
> decision, so the two columns can legitimately differ — which is precisely why neither may be inferred
> from the other.

#### `P7-C3` — The ED board's "Recorded acuity" sort INVERTS clinical priority on the Manchester scale

- **Role:** any with `ed.manage` · **Route:** `GET /ed/board`
- **Steps:** re-triage one active visit to **MANCHESTER red** (most urgent) and another to **MANCHESTER
  blue** (least urgent); on the board click **Sort by → Recorded acuity**.
- **What happened, verbatim from the rendered board:**

  | Position | Patient | Acuity |
  |---|---|---|
  | **1** | Nora Bianchi | **MANCHESTER blue** — the LEAST urgent |
  | 2 | Paul Widmer | **MANCHESTER red** — the MOST urgent |

- **Cause.** `Board.vue:52`:
  `list.sort((a, b) => (a.acuity?.level ?? '~').localeCompare(b.acuity?.level ?? '~'))` — a **lexicographic
  sort on the level string**. `EdTriage::LEVELS` defines ESI and CTAS as `'1'…'5'` (where alphabetical
  order coincides with clinical order) and **Manchester as `['red','orange','yellow','green','blue']`**,
  whose alphabetical order is `blue, green, orange, red, yellow` — unrelated to urgency, and in practice
  inverted at both ends.
- **The scale is fully reachable:** the triage form's scale select offers all three (`ESI`, `MANCHESTER`,
  `CTAS`) and the level select then renders the Manchester colours in the *correct* clinical order — so the
  form is right and only the board's ordering is wrong.
- **A second, related consequence:** mixed scales on one board are compared as raw strings, so an `ESI 2`
  and a `MANCHESTER red` are ordered by the accident of `'2'` versus `'red'`.
- **Why CRITICAL:** this is not a styling nicety — it is the one place the board makes a clinical ordering
  claim, under a control labelled "Recorded acuity", and on a supported scale it presents the least urgent
  patient first. It is the exact inverse of the guarantee the D-169 result above establishes so carefully.

> ✅ **FIXED — QA-FIX.7b, commit `ec3695e` (D-212).** The board orders by the level's position in
> **its own scale**, read off `EdTriage::LEVELS` by the new `EdTriage::levelPosition()` and sent on the
> board payload. The level is the nurse's judgment and the order is the scale's published one — CareOS
> transcribes, and contributes neither. `localeCompare` on the level string is gone.
> **THE FINDING'S SECOND CONSEQUENCE IS FIXED THE ONLY WAY IT HONESTLY CAN BE.** Mixed scales are not
> interleaved: visits are **grouped by scale**, ordered within each group, with the group order being the
> scale's NAME — an arbitrary, stable, non-clinical tiebreak, chosen precisely because it asserts nothing.
> No ESI↔Manchester equivalence table was built and a test pins its absence, because mapping ESI 2 onto
> Manchester orange is a clinical claim this product has no basis to make (D-170).
> **A THIRD DEFECT ON THE SAME LINE, NOT IN THE FINDING, FOUND WHILE FIXING IT.** The `'~'` sentinel for
> untriaged visits sorted them **FIRST**, not last as the line's own comment claimed — `~` orders before
> digits and letters in ICU collation. So an acuity-sorted board led with the patients who had no recorded
> acuity at all. Untriaged now sort last, and a test asserts it.
> **`LEVELS`'s ORDER IS NOW LOAD-BEARING AND SAYS SO (D-191 applied).** Its docblock previously called it
> a closed set "for data-entry validation ONLY" — nothing stated the sequence meant anything, so indexing
> it for display would have been the undocumented ordering D-191 warns about. All three lists are pinned
> by a test, because **alphabetising the Manchester list is an innocent tidy-up that would silently
> re-invert this very display**.
> **Guarded by** nine tests, mutation-checked three ways (alphabetising Manchester, restoring the
> `localeCompare`, dropping `position` from the payload). A positive control asserts the board still
> **defaults to arrival order**, so acuity ordering stays something staff ask for rather than the board's
> standing judgment.

---

### HIGH

#### `P7-H1` — No HTTP path registers an ED presentation: the vertical's entry point has no surface

`EdVisitService::register()` (`:39`) is called from **the seeder only** —
`DemoHospitalSeeder.php:433, 572, 640, 643` — and from nowhere else. `EdBoardController` uses the service
solely for `activeVisits()` (read) and `transition()`. There is no route, no controller action and no form
that creates an `EdVisit`. **Every ED visit that exists in the product was written by the seeder**, so a
patient cannot be brought into the emergency department at all. This is the `P6-H1` shape (a whole
capability with no surface) at the *first* step of the workflow rather than a peripheral one.

#### `P7-H2` — ED renders none of its refusals — the `P6-C3` defect, unfixed outside Surgery

The ED controllers carry **10** `->withErrors([...])` sites, and **zero** of the five ED pages read
`errors`, `usePage` or `flash` (measured per file: `Billing 0/0/0`, `Board 0/0/0`, `Disposition 0/0/0`,
`Documentation 0/0/0`, `Triage 0/0/0`). **Driven:** submitting a triage with the acuity level left as
"Select a level" produced **no alert, no error text and no record** — the page reloaded unchanged and the
history still showed the previous entry. A refusal and a success are indistinguishable, exactly as in
Surgery before QA-FIX.6c. QA-FIX.6c fixed `resources/js/pages/Surgery/*` only; `RefusalNotice.vue` exists
and is not used here.

> ✅ **FIXED — QA-FIX.7c, commit `fd7b350` (D-213). AN ADOPTION, NOT A DESIGN.** All five ED pages now
> import and render the EXISTING `RefusalNotice.vue` — five imports, five tags, no new component, no new
> mechanism and no new copy (D-170). A test asserts ED rolled none of its own: any ED page reading
> `page.props.errors` directly reddens it.
> **THE WHOLE-BAG PROPERTY IS WHY THE EXISTING COMPONENT FITS.** ED's refusals arrive in both shapes —
> `validate()` keys by FIELD (`triaged_by`, `bed_id`, `practitioner_id`, `status`), the controllers key by
> DOMAIN (`triage`, `ed_visit`, `disposition`, `encounter`, `vital`, `order`, `ed_billing`). A component
> naming keys would have missed half of them, which is how a module can look handled while most of its
> refusals stay invisible.
> **ALL FIVE PAGES TAKE IT, UNLIKE SURGERY, AND THE DIFFERENCE IS DELIBERATE.** QA-FIX.6c excluded
> `Checklist.vue` under D-176 because no rule it validated could fail from a live control. Every ED page
> has a refusal a user can reach, so the guard **names the reachable refusal per page** rather than
> asserting the five as a block.
> **THE BOARD REFUSES IN TWO LAYERS — found here, not in the finding.** `dispositioned` is excluded by the
> route's `in:` rule and never reaches the service (FIELD-keyed `status`); `awaiting_disposition` passes
> validation and is then refused by the transition guard (DOMAIN-keyed `ed_visit`, the layer this finding's
> count refers to). Both are driven separately and both reach the page through the one notice.
> **NO GUARD WAS SOFTENED.** Every driven refusal still refuses and still leaves nothing behind — no triage
> row, no `Stay`, no status change. A positive control asserts a SUCCESSFUL triage flashes no error at all,
> so the suite cannot be satisfied by a page that always shows something.
> **Guarded by** eight tests, mutation-checked two ways (deleting a `<RefusalNotice />`; giving one ED page
> its own errors block).

#### `P7-H3` — The ED physician cannot prescribe anything, and the ED record has no medication surface at all

`ed_physician` holds no `medication.prescribe` — nor does any ED role. Phase 2 flagged the permission gap;
this phase drove what it costs. The **ED clinical record** (`/ed/visits/{v}/record`) offers *Treatment
encounters*, *Vitals* and *Orders*, and a text search of the rendered page for `medic|prescri|drug|Medikament`
returns **nothing**: there is no medication section, no link to the medication surface, and no affordance to
order a drug. `order.manage` covers clinical orders (labs/imaging), not medications. In an emergency
department — where analgesia, antiemetics and antibiotics are among the most common interventions — the
treating clinician cannot order any of them, and the record does not acknowledge the gap.

#### `P7-H4` — All four of the ED physician's landing links 403, and no ED link exists anywhere in the shell

Driven as `ed_physician`, every `<main>` link on `/app` returns **403**: `/patients/register`,
`/scheduling/day-board`, `/nursing/dispatch`, `/comms/inbox`. This is `P2-H2` **unchanged five phases
later**. The top nav shows *Dashboard · Patients · Orders · Telehealth* — **no ED entry** — because
`HandleInertiaRequests::NAV_PERMISSIONS` is a fixed 14-key list containing no `ed.manage` and no
`triage.record` (the same undersized map Phase 5 named for Pharmacy and Phase 6 for Surgery). The ED board
is reachable only by typing a URL.

#### `P7-H5` — No ED role can reach ED billing: all five routes are 403 for the entire group

Every `/ed/visits/{visit}/billing*` route is gated `billing.manage` (`EdBillingController.php:32, 87, 105,
127, 150`), and none of `ed_physician`, `triage_nurse` or `ed_charge_nurse` holds it — confirmed in the
browser (403 as `ed_physician`). ED charges therefore exist in the seed but are unreachable by the people
who generate them, exactly the Phase-6 Surgery shape (`P6-H5`'s sibling). The `ed.disposition.openBilling`
link is rendered behind `v-if="actions.can_bill"`, so it is correctly withheld rather than offered-and-refused.

---

### MEDIUM

#### `P7-M1` — A triage nurse can record a DISCHARGE, while being unable to sign a note or place an order

The disposition write is gated `ed.manage` (`EdDispositionController.php:95`), which all three ED roles
hold; only **admit** additionally requires `admission.manage` (`:42`). Driven: as `triage_nurse` the form
offered **Discharge** and **Transfer out** (Admit correctly withheld). So a nurse who cannot sign a clinical
note (`note.sign`) or place an order (`order.manage`) can nonetheless record the decision to send a patient
home. Whether that is intended is a product question; the audit records that the permission which gates a
discharge is the same one that gates moving a patient between flow states.

#### `P7-M2` — Every staff picker in ED is role-blind and 20 entries long

The triage "Triage nurse" select and the disposition "clinician" select are both populated from
`StaffProfile::query()->orderBy('display_name')->limit(200)` (`EdTriageController.php:75`) with no filter of
any kind — the same defect Phase 6 recorded as `P6-M6` for Surgery. Measured in the browser: 20 options,
led by "Beat Suter" (surgical scheduler), "Dr. Anke Berg" (org admin), "Dr. med. Clara Meier". This is the
mechanism that makes `P7-C1` and `P7-C2` land on a non-clinician by default.

#### `P7-M3` — Dates and times render in US format in the viewer's timezone

The triage history renders **`9/7/2026, 4:05:01 PM`** for a row stored at `2026-09-07 23:05:01 UTC` — that
is `M/D/YYYY` with a 12-hour clock, resolved to the **viewer's** `America/Los_Angeles`, for a tenant whose
timezone is `Europe/Zurich` and whose locale is `de`. Pattern 2, seventh consecutive phase. (The stored
value itself is correct: `triaged_at 23:05:01` against a CLI `now()` of `23:05:28` UTC — no clock skew.)

#### `P7-M4` — No navigation below 768 px, on a board that is plausibly a tablet

Measured at 390 px on `/ed/board`: **0 of 4** nav links have a non-zero width and there is no menu button
(the only header control is the 36 px avatar). The board content itself does **not** overflow horizontally
(`scrollWidth` 390 = viewport), so the cards reflow correctly — the defect is navigation only. Worth stating
because an ED tracking board is a plausible wall-display or nurses'-station tablet surface.

#### `P7-M5` — `EdBillingService` has the `P6-M10` shape: captures then links, with no transaction

**Established from code, not driven** — `P7-H5` makes the surface unreachable for every ED role, so there is
no browser path to it. `EdBillingService.php` performs the idempotency read (`:101`), the captures
(`:113-121`) and the link loop (`:123-125`) with **no `DB::transaction`**, so a throw partway through leaves
earlier charges durable and unlinked while the idempotency guard — those same link rows — reads empty. Phase
6 recorded this while fixing the Surgery twin (QA-FIX.6a, D-208) and deliberately did not fix it; it is
restated here with its own ID because it belongs to this role group.

#### `P7-M6` — The ED billing surface derives money client-side

`resources/js/pages/ED/Billing.vue:39` computes `props.charges.reduce((sum, c) => sum + c.quantity * c.unit_price_minor, 0)`
and formats it locally — the same second-derivation defect QA-FIX.6a removed from Surgery, and the same
breach of the Phase-3 finding that no billing surface derives its own totals. **Code-established** for the
same reason as `P7-M5`.

#### `P7-M7` — Two visit states and one disposition are unreachable in practice

`left_without_being_seen` is a legal state with a board button ("Left (LWBS)"), and `transfer` is a legal
disposition offered on the form — but neither exists in the seed, and `P7-H1` means no new presentation can
be created to exercise them from a clean start. They were reachable only because the seeder had already
created visits; on a fresh tenant the ED has no entry point and therefore no states at all.

---

### LOW

#### `P7-L1` — The board's elapsed time is computed client-side and does not tick

`Board.vue:68` computes `Math.round((Date.now() - new Date(iso).getTime()) / 60000)` at render. It is an
honest fact (elapsed since arrival, with no target or breach threshold — see the acuity section), but it is
frozen until the page is reloaded, so a board left open on a wall display shows steadily staler durations.

#### `P7-L2` — The `ed.triage.level` translation key is null and unused

`resources/js/lang/en.json` has no value for `ed.triage.level`. Checked in the browser rather than assumed:
the acuity label renders correctly as **"ASSIGNED ACUITY"** from a different key, so nothing is broken today
— the entry is simply dead. Recorded only so a future reader does not mistake it for the live key.

---

### Guards verified holding (probed in the browser, not assumed)

- **The acuity seam is the cleanest in the product** — see the boundary section above. No computation, no
  suggestion, no prefill of the level, and an empty state that denies being a check.
- **D-169 holds on the hardest possible field** — ESI 1 and ESI 3 render byte-identically across card class,
  background, border, badge class, badge background, colour, weight and size.
- **A re-triage APPENDS.** Driven: an ESI 3 and a subsequent MANCHESTER red both stand in the history.
  `ed_triages` carries `ed_triages_no_update` and `ed_triages_no_delete` `SIGNAL '45000'` triggers.
- **The triage write is AUDITED with the real actor** — `ed_triage.recorded`, `actor_id` = the logged-in
  nurse, even though the record's own `triaged_by` names someone else.
- **The visit flow is legal-transitions-only.** Driven: from `triaged` the board offered *Start treatment*
  and *Left (LWBS)*; from `in_treatment` only *Ready for disposition* — LWBS correctly disappeared, matching
  `EdVisit::TRANSITIONS`.
- **Board counts are live and consistent.** After advancing one visit the summary moved from
  `2 in department / 2 waiting / 0 in treatment` to `2 / 1 / 1` with no reload and no disagreement against
  the rendered cards.
- **The disposition surface is state-gated honestly** — on a `triaged` visit it says *"This visit is not yet
  awaiting disposition."* and renders no form, rather than offering a control that would fail.
- **Admit is withheld, not offered-and-refused.** The `triage_nurse` sees only *Discharge* / *Transfer out*;
  the `ed_physician`, who holds `admission.manage`, additionally sees *Admit*.
- **The ED→inpatient handoff completes and is audited on both sides.** Driven: `ed_visit.dispositioned` and
  `admission.admitted`, both with the real actor, producing a `Stay` with `admission_type=emergency` and an
  assigned bed. `EdVisitService::transition()` is wrapped in `DB::transaction` (`:108`).
- **No empty `catch` anywhere in the ED module.** Every catch names its exception types and flashes a
  message — Phase 5's Pharmacy shape does not recur (the defect is that nothing renders those messages,
  `P7-H2`).
- **The disposition states its own fence:** *"The disposition is the clinician's decision — the system
  records it, it never computes or suggests it."*
- **Stored times are correct UTC** — a driven triage stored `23:05:01` against a CLI `now()` of `23:05:28`.

### Not tested, and why

- **Performance** — explicitly out of scope per the gate, deferred to staging.
- **ED billing end to end** — `P7-H5`: all five routes are 403 for every ED role, so `P7-M5` and `P7-M6` are
  labelled code-established rather than driven. Driving them would require `org_admin`, who is not an ED role.
- **`left_without_being_seen` and `transfer`** — `P7-M7`: absent from the seed, and with no way to register a
  presentation there is no clean path to create one.
- **A second tenant's ED data** — `klinik-bergblick` is the only tenant with any, so cross-tenant ED
  isolation was read from the services' `assertSameTenant` calls rather than driven.
- **`ed_charge_nurse` was driven only on the read surfaces and the RBAC matrix**, not through a second full
  write workflow: its permission set is a superset of `triage_nurse` plus `note.sign`/`order.manage`/
  `reporting.view`, and the writes it can perform were already driven as the other two roles.
## Cross-phase patterns

Seven phases are complete across seven unrelated role groups — front-desk, clinical, financial,
nursing (including the product's only offline surface), pharmacy, the operating theatre and the emergency
department. **A pattern that appears in all seven is a systemic defect, not a local one.** The per-pattern sections below were
written at Phase 3; the **Phase 4**, **Phase 5** and **Phase 6** updates near the end of this section
state, for each pattern, whether it recurs.

### 1. Ungated UI in front of a correctly-gated server — **PRESENT IN ALL THREE PHASES**

`P1-H1` · `P1-M2` · `P1-L5` → `P2-H2` → **`P3-M7`** · **`P3-L2`**

Every role group's landing page offers links its own role cannot open, and the count is not
improving: reception (Phase 1), **all four** of `ed_physician`'s `<main>` links (Phase 2), and now
**all four** of `billing`'s (Phase 3 — `/patients/register`, `/scheduling/day-board`,
`/nursing/dispatch`, `/comms/inbox`). Phase 3 also found it *inside* a module: `/billing/new-invoice`
is reachable by the `pharmacist` and its only link is a breadcrumb to `/billing/invoices`, which
403s for that same role.

**The diagnosis is stable and narrow.** The top nav **is** permission-aware in all three phases — it
correctly shrank for the ED physician and shows only Dashboard + Billing for the billing clerk. The
hero CTAs, the "Today's schedule" card, the quick-action list and in-page breadcrumbs are rendered
unconditionally. One fix — teaching the page body what the nav already knows — closes the class
across every phase. **This is the single highest-yield fix the audit has found.**

### 2. Timestamp and locale divergence — **PRESENT IN ALL THREE PHASES, and now it touches money**

`P1-C1` · `P1-M3` · `P1-L2` → `P2-H3` · `P2-M3` · `P2-L4` → **`P3-M2`** · **`P3-M3`** · **`P3-M1`**

Phase 1 found three date formats and an inbox printing raw UTC. Phase 2 found **four** formats and
proved it was two *mechanisms* — some components print stored UTC verbatim, others hand the instant
to a browser locale API that resolves to the viewer's own machine. Phase 3 shows the same split
inside one module (Swiss `DD.MM.YYYY` on the BILLAR-era screens, ISO on the ARDETAIL-era ones) and
adds a **money consequence**: the record-payment and payment-plan forms default their date inputs
from the **viewer's** calendar, so a payment can be dated into the wrong day — and a plan created
today was immediately shown with its first installment **"Overdue"**.

Phase 3 adds a **currency** dimension of the same shape: a shared, tested `formatSwissMoney()`
(`CHF 4'820.00`) exists and is used on **1 of 12** billing surfaces; the other eleven hand-roll a
formatter that drops the Swiss group separator and flips the currency to the end.

**The through-line across all three phases is the same:** a correct shared helper exists, and most
call sites do not use it. `QA-FIX.1a` fixed the *storage* base; the *display* boundary it named is
still missing at the call sites.

### 3. A recorded status asserted without the event that earns it — **TWO PHASES, and the honesty model is sound**

`P1-M1` → `P2-H1` (fixed by `QA-FIX.2b`) → **`P3-M5`**

Phase 1 found `status = arrived` with `checked_in_at` NULL; Phase 2 found the same pair produced by
a clinician merely opening a note. Phase 3's instance is milder and instructive: the AR dunning panel
shows **"REMINDERS SENT — 1"** above an event labelled **"Prepared"**.

**What Phase 3 adds is that the underlying model is honest.** `DunningService` writes `status = SENT`
only when a channel actually delivered, and audits `dunning.sent` only then — the data never lies.
Only the **card label** overstates. The pattern is therefore narrowing: the defect has moved from the
*records* (Phases 1–2) to the *labels* (Phase 3), which is the right direction.

### 4. A granted capability with no surface to exercise it — **TWO PHASES, and Phase 3's instance is the strongest**

`P2-H4` · `P2-M2` · `P2-M6` → **`P3-H3`** · **`P3-H4`**

Phase 2 found a chart that offers no affordance for `note.write`, `medication.prescribe`,
`patient.edit` or `encounter.manage` — all held by the role. Phase 3 finds the same shape at its
sharpest: **write-offs and contractual adjustments cannot be created anywhere in the product**, yet
`AdjustmentService` is fully built and tested, the `invoice_adjustments` table exists, and the AR
roll-forward carries **two dedicated lines** for them that can only ever read `0.00`.

Phase 3 also supplies the **mirror image**, which is new: the `pharmacist` holds `billing.manage` for
charge capture and is thereby handed the **invoice-creation** surface while being 403 on every
billing read. Permissions and affordances are drifting apart **in both directions** in two unrelated
modules, which points at them being maintained independently and never reconciled.

### 5. The fences hold — **CONFIRMED IN ALL THREE PHASES**

Phase 1's positives → Phase 2's clinical fences → Phase 3's money fences.

Across three role groups and every surface driven, the hard rules have not eroded. Phase 2 verified
the clinical fences under positive control (D-169 twice, D-172 with **no drawing layer present at
all**, the note agent boundary, a signed note not editable in place). Phase 3 verified the **money**
fences the same way: **all six δ=0 claims checked arithmetically on screen and re-checked after two
real writes** (an installment and a full credit note) that moved AR by CHF 369.53 and still tied;
**zero client-side aggregation** in twelve billing Vue surfaces; the over-allocation guard refusing
four ways; the payment-plan ceiling; **Betreibung refusing under forgery for all three roles**, with
ARDETAIL.P6's narrower `billing.escalate` proven in the browser against a `billing.manage` holder;
D-169 holding on aging buckets; and DSO / net-collection-rate rendering an honest **"—"** rather than
a number invented from a zero denominator.

**The defects this audit keeps finding are in presentation, navigation, attribution and partial
writes — not in the engines or the fences.**

### 6. A refused write that leaves a partial record — **NEW in Phase 3, and worth watching**

**`P3-C1`**

`AccountDetailController::recordPayment()` commits the payment, then allocates, and returns the
allocation guard's error without a transaction or a rollback — so a refused operation leaves real
money records behind while telling the operator it failed. Notably the **payment-plan** path on the
same page does *not* do this: its refusal left no orphan row. One controller, two write paths, two
different transaction disciplines.

**Watch for it in later phases:** any surface that performs a create-then-associate pair outside a
transaction has the same shape. It is not yet a pattern — one instance — but it is the kind that
generalises, and Phase 3 found the counter-example (the plan path) that shows the correct discipline
already exists in the same file.


### Phase 4 update — four phases of evidence

Phase 4 covered a fourth unrelated role group (home-care nursing) **and** the product's only offline
surface. Each standing pattern is stated below as present or absent, explicitly.

**1. Ungated UI — PRESENT, fourth phase, and now provably self-contradicting.** `P4-H5`: all three
quick actions 403 for `nurse`, `ward_nurse` and `charge_nurse`; two of three for `coordinator`. What
Phase 4 adds is proof the page **already holds the answer** — the Inertia payload carries
`"dispatch.manage": false` and `"comms.manage": false` for the nurse, and
`Landing.vue:171,174,177` render the links anyway while `:87` in the *same file* gates the KPI row
"per the actor's permissions". The Phase 3 diagnosis ("teach the page body what the nav knows") is
now not merely the highest-yield fix but a **one-file** one for the landing page.

**2. Timestamp / locale divergence — PRESENT, fourth phase, and it has crossed from display into
storage.** `P4-H4` is the display half at its worst: **32 of 32** timestamps on the PWA are raw
ISO/UTC, no Swiss format anywhere, so a field nurse reads a 07:30 visit as **05:30**. `P4-C4` is the
new half — the *write* path stores the device's wall-clock into UTC columns across **eleven** sites,
so recorded care times are two hours out. **The two errors run in opposite directions and do not
cancel.** `QA-FIX.1a` fixed this defect class for web requests (D-192/D-193); the nurse-sync path was
never in that gate's scope. `P4-M7` adds a locale dimension: the tenant is `locale=de` and
`resources/js/lang/` contains **only `en.json`**.

**3. A status asserted without the event that earns it — ABSENT in Phase 4, and the reason is
instructive.** No nursing surface fabricated a state. The opposite held: EVV **records the absence of
a GPS fix** as `manual_reason` with `location` NULL rather than inventing a location, and the visit
state machine refused a check-in it could not justify. The pattern's trajectory across four phases —
records (1–2) → labels (3) → **nothing (4)** — continues in the right direction.

**4. A granted capability with no surface — PRESENT, fourth phase, and this is now the most
persistent pattern in the audit.** `P4-M2` (`timesheet.approve`, `agreement.manage` — no controller
enforces either, 9 URLs 404, yet 36 timesheet lines and 5 service agreements are seeded), `P4-M3`
(`note.supervise`, enforced nowhere), and `P4-H3` in its sharpest form yet: the server implements
`check_in`/`check_out` with full EVV handling and **the field client offers no control for either**,
so the core operation of a home-care round is unreachable. `P4-M4` supplies the mirror image again —
the ward board is permission-correct for two roles and linked from **nowhere in `resources/js/`**.
Four phases, four modules, both directions: permissions and affordances are maintained independently
and never reconciled.

**5. The fences hold — CONFIRMED IN ALL FOUR PHASES.** The nursing surfaces are clinical, and the
electric fence is intact: D-169 verified byte-for-byte (a **severe** anaphylaxis allergy renders in
exactly the neutral text's colour and weight); `vitalsDisplay.ts` returns raw values and its docblock
forbids "a band, range, flag, normal/abnormal marker, score, arrow, or delta"; and a sweep of the
entire PWA source and Nursing module for risk/acuity/fall-score/suggest/recommend/predict/AI returns
**zero** hits. Phase 4 adds **offline-integrity guarantees that genuinely hold**: day-pack scoping to
the nurse's own assigned visits (1 visit, 3.9 KB — not the patient list), cross-assignment refused
both plainly and **under a forged `nurse_resource_id`**, replay idempotency by `client_action_uuid`,
AES-GCM-only storage with **no plaintext PHI** on the device, and a ward board whose view/mutate
split was proven by positive control.

**As of Phase 4 the summary needs one amendment.** Phases 1–3 concluded that "the defects are in
presentation, navigation, attribution and partial writes — not in the engines or the fences." That
still holds for the *engines and fences*, but Phase 4 found five CRITICALs that are **none of those
things**: they are defects in the **offline data lifecycle** — a key that expires before the data it
protects (`P4-C2`), a security wipe applied to un-transmitted work (`P4-C3`), a timezone lost at the
write boundary (`P4-C4`), and a duplicate write from a double-bound event handler (`P4-C5`). The
offline surface is the least mature part of the product the audit has reached, and it is the one
handling care records that exist nowhere else.

**6. A refused write that leaves a partial record — SECOND INSTANCE, exactly where Phase 3 predicted.**

Phase 3 wrote: *"any surface that performs a create-then-associate pair outside a transaction has the
same shape… it is the kind that generalises."* `P4-H2` is that recurrence, in the offline queue:
`NurseSyncService.php:97` wraps **each action** in its own transaction with **no batch-level
transaction**, so when a later action throws, the earlier ones are already durable while the client
receives a bare **500 with no `results` array** and is told the whole sync failed. Driven: a batch of
`[valid note, malformed check_in]` returned 500 and the note **committed** (36 → 37, ledger
`accepted`).

**It is one instance in a different module, so this is now a pattern rather than a curiosity** — and
the two instances differ in an instructive way. `P3-C1` left a partial record and told the operator
nothing; `P4-H2` leaves a partial record and tells the device *the opposite of the truth*, which then
drives `P4-H1`'s permanent queue jam. The mitigating factor here, absent in Phase 3, is that replay
idempotency stops the retry duplicating the committed note — the correct discipline exists in the
same file, just not at the batch boundary.


### Phase 5 update — five phases of evidence

Phase 5 covered a fifth role group whose defining risk is medication safety. Each standing pattern is
stated below as present or absent, explicitly.

**1. Ungated UI — PRESENT, fifth phase, and Phase 5 explains *why* it persists.** `P5-H2`: **all four**
quick actions 403 for **both** pharmacy roles, with `Landing.vue:171,174,177` still ungated while the
payload carries `"comms.manage": false`, `"dispatch.manage": false`. The new detail is the cause of
the cause: that permission map has **14 fixed keys and contains no pharmacy permission at all** — no
`dispense.manage`, no `formulary.manage`. So the shell cannot recognise a pharmacist, which
simultaneously makes it **over-offer** links they cannot use (`P5-H2`) and **under-offer** the module
they live in (`P5-M1`). One undersized map produces both halves of the pattern.

**2. Timestamp and locale divergence — PRESENT, fifth phase.** `P5-M2`: dispensing history, inventory
movements and the eMAR all render `9/6/26, 6:49 PM` — `M/D/YY` with AM/PM, in the **viewer's**
`America/Los_Angeles`, for a stored `2026-09-07 01:49 UTC` that is `03:49` in the tenant's
`Europe/Zurich`. `Dispensing.vue:35` hands the instant to `Intl.DateTimeFormat`, the exact
browser-locale mechanism Phase 2 named. Phase 5 adds a **currency** instance that is worse than Phase
3's: the pricing screen prints `Current: 1.20` with **no currency unit at all** (`P5-M3`).

**3. No navigation below 768 px — PRESENT, fifth phase.** `P5-M6`: both nav links measure 0×0 at
390 px with no menu button, this time on the dispensing screen.

**4. A granted capability with no surface — PRESENT, fifth phase, in three distinct forms.**
`P5-H1`: `PharmacyBillingService::invoicePatient()` performs validate-then-issue correctly and has
**no route and no production caller**, which is precisely why the pharmacist's `/billing/new-invoice`
is permanently empty. `P5-M7`: `ClinicalListService::recordAllergy()` is fully built and guarded and
**no route records an allergy** — the chart displays a list the product provides no way to create.
`P5-M1`: the whole Pharmacy module is reachable but unlinked. Five phases, five modules.

**5. The fences hold — CONFIRMED IN ALL FIVE PHASES, and Phase 5 is the strongest instance yet.**
The `MedicationSafetyProvider` seam states in the product's own words that *"drug-allergy interaction,
cross-reactivity and contraindication checking is a certified-partner function and is not performed
here"* — the hardest thing in the audit to say honestly, said plainly, with no cleared state and no
green tick anywhere. D-169 was proven under **positive control** (a mild and a severe allergy
rendering byte-identically). The dispense triple is atomic under a row lock, `Dispense` and
`StockMovement` are append-only, and four refusals left the ledger untouched.
**`P5-C1` is not a fence failure — it is the fence being absent from one screen.**

**6. A refused write leaving a partial record — ABSENT here, but Phase 5 finds its MIRROR IMAGE, and
that is the more interesting result.** The dispense path is the exact create-then-associate shape the
gate predicted, and it is **correctly transactional**: four driven refusals left `on_hand`, dispenses,
charges and movements all unchanged. But `P5-C2` is the same defect class inverted — **a SUCCEEDED
write leaving an incomplete record**. `chargeForDispense()` sits *outside* the transaction inside
`catch (Throwable) { }`, so a technician's dispense commits the clinical half and silently drops the
financial half.

**The pattern therefore generalises beyond refusals.** Phases 3 and 4 asked *"what does a refused
operation leave behind?"*; Phase 5 shows the question must also be *"what does a **successful**
operation fail to leave behind?"* — and that the second is harder to see, because there is no error,
no refusal message and no visible difference at all. `P3-C1` and `P4-H2` at least told the user
something had gone wrong. `P5-C2` tells them nothing, because from the user's point of view nothing
did.


### Phase 6 update — six phases of evidence

Phase 6 covered the operating theatre, whose defining risks are scheduling concurrency, checklist
honesty and team attribution. Each standing pattern is stated below as present or absent, explicitly.

**1. Ungated UI — PRESENT, sixth consecutive phase, and Phase 6 shows the cost is no longer only
cosmetic.** `P6-M2`: `Landing.vue` offers *Nursing dispatch* to all four surgery roles and it 403s;
for the `scrub_nurse`, **three of the four** offered links refuse her. `P6-M3` finds it in-module —
`Case.vue:79-81` renders checklist, supplies **and billing** links with no `v-if`, ten lines above
forms that *are* correctly gated on `can_manage`. The new severity is `P6-H4`: for the scrub nurse
this stops being an annoyance and becomes **the reason she cannot do her job** — her two permitted
surfaces have no link, the case list that would lead there 403s, and the checklist page's only link
("Back to case") refuses her. Ungated UI over-offers; the same absent gating logic under-offers, and
the second half is what strands a role.

**2. Timestamp and locale divergence — PRESENT, sixth phase, with a FIFTH mechanism.** Phase 2 named
two (raw-UTC printing, browser-locale `Intl`); Phase 3 added hand-rolled currency; Phase 4 added the
storage-side device clock. `P6-L1` adds **string truncation**: four Surgery pages format with
`iso.replace('T',' ').slice(0,16)`, which keeps the UTC wall-clock and **discards the offset**, so a
09:00 UTC theatre booking reads `09:00` to a Zurich user for whom it is 11:00. It is the only
mechanism so far that cannot be timezone-aware even in principle. `P6-L2` repeats the currency half
for the third consecutive phase: `toLocaleString` with no currency, `2,500.00` where `CHF 2'500.00`
belongs, while the tested `formatSwissMoney()` sits unused.

**3. A status asserted without the event that earns it — ABSENT as a data defect; PRESENT as a
presentation one, in a new form.** No surgery surface fabricates a state: the lifecycle is
legal-transitions-only, each move writes an append-only event **and** an audit row, and the checklist
is append-only with actor and timestamp. `P6-M4` is the inverse failure and is worth naming as its
own shape: **a real state that the surface declines to show**. A case completed with **0 of 17**
checklist items confirmed is byte-identical, on the case page, to one completed with 17 of 17 — the
honest count exists, one click away, on a page nobody must open. Phases 1–2 recorded states that were
asserted without evidence; Phase 6 records evidence that exists without being surfaced.

**4. A granted capability with no surface — PRESENT, sixth phase, and this is the most complete
instance the audit has found.** Previous instances were a missing affordance on a working screen
(`P2-H4`, `P4-H3`) or a service with no route (`P3-H3`, `P5-H1`). `P6-H1` is a **whole role with no
reachable function**: `surgical_scheduler` holds `theatre.manage` and `surgery.schedule`, both
consumed *only* by `TheatreSchedulingService`, whose only callers are the seeder, the test suite and
one artisan command written to feed a test. There is no theatre route, no OR-list route and no
booking route. Measured: the scheduler is **403 on all seven surgery routes**, and the generic
day-board it falls back to shows *"No bookable resources yet"*. `P6-C1` supplies a second form —
`SurgicalBillingService` is complete and correctly routed, and its **only** surface cannot render
either of its two controls, so no surgical case can be billed at all. Six phases, six modules.

**5. The fences hold — CONFIRMED IN ALL SIX PHASES, and Phase 6 is the clearest non-medication
statement of the fence yet.** The WHO checklist says, on screen: *"This checklist is a record of what
the team confirmed. It does not block the surgery — the team owns the decision to proceed."* Its
service docblock states that a blocking checklist *"would be a safety-enforcement medical device"* and
that the read model exposes a factual `checked / total` and **never** a "passed / safe-to-proceed"
verdict — driven, and the case completed at 0 of 17 with nothing anywhere claiming otherwise. The
case detail states *"the system computes no surgical-risk score"* and, driven with ASA III and ASA I,
computes none: no band, no colour, no ranking, no suggested action. The implant recall lookup calls
itself *"a factual traceability lookup, not a device-safety verdict"* and its empty state says *"No
implants **match**"* — a statement about the index, not a clearance. **D-179 is handled correctly
here**, which matters because a surgical safety checklist is the single most tempting place in the
product to build an enforcement gate.

**But Phase 6 also finds the first place where the record itself can be falsified.** `P6-C2`: the ASA
class is attributed to whoever the operator **picks** from a role-blind list of all 20 staff, is
**overwritten in place** with no history, and is **the only write in the module that raises no audit
event** — driven, with a pharmacy technician successfully recorded as the assessing anesthetist. The
fence is about not computing judgments; this is about not being able to trust who made one. They are
different failures and the audit should not let the first excuse the second.

**6. A partial record — PRESENT IN BOTH DIRECTIONS, which the gate asked to be probed and which no
earlier phase has had at once.**

*The succeeded-write direction (Phase 5's reframing).* Phase 5 moved the question from *"what does a
refused operation leave behind?"* to *"what does a **successful** operation fail to leave behind?"*.
`P6-C1` is that question again with a structural rather than an exception-handling cause: an implant
was placed, stock decremented 20 → 19, the placement is traceable to the patient — and
`surgical_case_charges` stayed at **0**, permanently, because the capture control cannot render.
Nothing failed; nothing was caught; there is no error to find. The financial half of a completed
clinical act simply has no path into existence.

*The refused-write direction (Phase 3's original shape), third module.* `P6-M10`:
`SurgicalBillingService::chargeCase()` captures N charges — each committing in its **own**
`DB::transaction` inside `ChargeCaptureService::capture()` — and only then writes the
`SurgicalCaseCharge` link rows, with **no outer transaction**. A throw partway through (an unpriced
consumable raises `TariffNotFoundForDateException`, which the controller does not catch) leaves the
earlier charges durable and unlinked. **And the link rows are the idempotency key**: `chargeCase()`
decides whether a case is already billed by reading them, so a partial failure leaves that guard
reading empty and a retry re-captures everything that already succeeded. The mechanism intended to
prevent double-billing is the one that permits it. This is `P3-C1` and `P4-H2`'s shape in a third
module, exactly as Phase 3 predicted when it wrote that "it is the kind that generalises".

**The two are recorded at different severities on purpose, and the reason is a general one.**
`P6-C1` is CRITICAL because it is *active* — it is happening on every surgical case today. `P6-M10`
is MEDIUM because it is *latent*: `P6-C1` removes the only surface that could trigger it. **Fixing
`P6-C1` activates `P6-M10`**, so the two must be read and fixed in that order. Phase 4 set the
precedent for grading a latent defect below its active severity when it re-graded `P4-C4`
CRITICAL → HIGH; this is the first time the audit has found a latent defect whose activation is
gated by another finding in the same phase.

`P6-C3` is the other half and is new in shape: **not a swallowed exception, but a swallowed
message**. The Surgery module contains **no empty `catch`** — Phase 5's Pharmacy shape was searched
for specifically and does not recur; all 13 catches name their exception and call
`->withErrors([...])`. But **no Surgery page renders `errors`** — zero matches across all seven Vue
files — so every one of those 13 guards refuses correctly and reports into a void. Driven twice: a
blank implant lot and a 99999-unit stock request each produced a `302`, no record, and **no message**.
The correct discipline exists at the controller layer in every single case, and is lost entirely at
the view layer. Where Phases 3–5 found individual write paths with the wrong transaction or catch
discipline, Phase 6 finds a whole module whose refusals are correct and **invisible** — which, from
the user's seat, is indistinguishable from success.


### Phase 7 update — seven phases of evidence

Phase 7 covered the Emergency Department, whose defining question is whether a product may hold an acuity
at all. Each standing pattern is stated below as present or absent, explicitly.

**1. Ungated UI — PRESENT, seventh consecutive phase, and this one is a REGRESSION TEST THAT FAILED.**
`P7-H4`: all **four** of `ed_physician`'s landing links 403 — `/patients/register`,
`/scheduling/day-board`, `/nursing/dispatch`, `/comms/inbox`. That is not a new instance: it is `P2-H2`
re-driven **five phases later and unchanged**. The cause has been named three times now — the shell's
`NAV_PERMISSIONS` is a fixed 14-key list, and it omits `ed.manage` and `triage.record` exactly as it omits
the pharmacy and surgery keys. Seven phases, seven role groups, one undersized map: it simultaneously
**over-offers** links the role cannot use and **under-offers** the module the role lives in, so the ED
tracking board — the group's primary surface — is reachable only by typing a URL.

> ⚠️ **CORRECTION TO THIS DIAGNOSIS, made during QA-FIX.7d and recorded rather than quietly amended.**
> The sentence above blames `NAV_PERMISSIONS`'s size for BOTH halves. That is wrong for the over-offer,
> which is the half every phase actually measured. The map had nothing to do with it: `Landing.vue`
> carried **eight `<Link>`s and gated NONE of them**, so `/app` offered the same four destinations to
> everyone regardless of the map's contents. Phase 3 had already got this right and it was not carried
> forward — `P3-M7`: *"The nav is correct (Dashboard + Billing only); the page body is not."* Adding
> `ed.manage` to the map would not have removed a single one of the 403s any phase drove.
> The map's size is real, but it belongs **only** to the under-offer half.
>
> ✅ **THE OVER-OFFER IS FIXED — QA-FIX.7d, commit `c999181` (D-214).** `Landing.vue` now reads
> `auth.user.permissions` (the prop `AppLayout` has gated on since FIX.4) and hides what the role cannot
> open; a panel whose every action is hidden does not render (D-176). `NAV_PERMISSIONS` gained exactly one
> key, `patient.edit`, by the D-107 / D-110 route. The guard is the PROPERTY, not the template: for six
> roles × four destinations the flag must agree with the server (false ⇒ 403, true ⇒ not 403) — the
> assertion seven phases were making by hand. The server Gate is untouched; a positive control asserts the
> ED physician still gets 403 on all four by URL.
>
> ⛔ **THE UNDER-OFFER IS DELIBERATELY NOT TAKEN — QA-FIX.7d stopped here and reported.** Six built
> modules (ED, Surgery, Pharmacy, Lab, Radiology, Hospital) still have no shell entry. The remedy is
> precedented and small (D-107 and D-110 both added a key plus a role-gated nav item), **but doing it six
> times collides with D-111**, which exists because org_admin's *15 flat top-nav items* were a live
> finding flagged by all three audits; it capped day-to-day items at 10 and records that POLISH.1's "+2"
> worsened the problem. Six more top-level entries would re-create the exact defect D-111 fixed. The
> narrower option — grouped menus in the D-111 shape — requires deciding grouping, labels and which
> modules are day-to-day, which is information architecture rather than wiring. **Still open, by
> decision.**

**2. Timestamp and locale divergence — PRESENT, seventh phase.** `P7-M3`: the triage history renders
`9/7/2026, 4:05:01 PM` — `M/D/YYYY`, 12-hour, in the **viewer's** `America/Los_Angeles` — for a row stored
`23:05:01 UTC` in a tenant whose zone is `Europe/Zurich` and whose locale is `de`. The storage half is
correct (a driven write landed within 27 seconds of CLI `now()`), so this remains purely a display defect,
now in its seventh module.

**3. No navigation below 768 px — PRESENT, seventh phase, and newly load-bearing.** `P7-M4`: at 390 px,
**0 of 4** nav links have a non-zero width and there is no menu button. What Phase 7 adds is that the
surface is an **ED tracking board** — the most plausible wall-display or nurses'-station tablet in the
product. The board's own cards reflow correctly (no horizontal overflow); it is only navigation that
disappears.

**4. A granted capability with no surface — PRESENT, seventh phase, and it has moved to the FIRST step of
a workflow.** `P7-H1`: `EdVisitService::register()` is called **only from the seeder** (four call sites);
no route, no controller action and no form creates an `EdVisit`. Every ED visit in the product was written
by the seeder, so **a patient cannot be brought into the emergency department at all**. Previous instances
were a missing affordance on a working screen (`P2-H4`, `P4-H3`), a service with no route (`P3-H3`,
`P5-H1`), or a whole role with no reachable function (`P6-H1`). This one is the *entry point* of the
vertical: every surface downstream of arrival works, and arrival itself has no door. `P7-H5` supplies the
familiar second form — five ED billing routes gated on a permission **no ED role holds**.

**5. The fences hold — CONFIRMED IN ALL SEVEN PHASES, and Phase 7 is the strongest result the audit has
produced.** Acuity is the hardest case in the product: a genuinely ordinal clinical field, on the one
screen whose job is to say who is seen first. The product does not compute it, does not suggest it, does
not prefill it, and does not tint it. `NullTriageAcuityProvider` returns `none()` and its docblock draws
the distinction that matters — *"'CareOS makes no acuity claim', not 'this patient is low acuity'"* — while
calling a homemade acuity computer **a permanent non-goal**. The empty state reads *"No automated
suggestion. The triage nurse assigns the acuity."* and cannot be mistaken for a clearance. And the D-169
positive control passes **byte-for-byte**: an ESI 1 and an ESI 3 share card class, background, border,
badge class, badge background, colour, weight and size, differing only in their text. The one colour that
varies tracks the **flow state**, and its own comment says so. Seven phases in, no fence has eroded.

**But Phase 7 also produces the audit's first case where an honest fence sits on top of a wrong
computation.** `P7-C3`: having refused to rank patients, the board then offers a "Recorded acuity" sort
implemented as `localeCompare` on the level string — correct for ESI and CTAS (`'1'…'5'`), and **inverted
for Manchester** (`red…blue` sorts alphabetically to `blue…red`). Driven: the least urgent patient rendered
first. The judgment was never computed; the *ordering of the recorded judgment* was, and got it wrong.

**6. A partial record — PRESENT IN BOTH DIRECTIONS, and the succeeded-write direction has become the
module's signature.** The refused direction is `P7-H2`: 10 `withErrors` sites, **zero** ED pages reading
`errors`, and a driven refusal that produced no message and no record — the `P6-C3` defect exactly, which
QA-FIX.6c fixed for `pages/Surgery/*` only. The succeeded direction is `P7-C1` and `P7-C2`: writes that
**complete successfully and leave the wrong fact behind**. A triage records an uninvolved surgical
scheduler as the assessing nurse; an inpatient admission records the same person as the admitting
clinician. Nothing failed, nothing was caught, and there is no error to find — Phase 5's reframing
(*"what does a **successful** operation fail to leave behind?"*) extended from a missing side effect to a
**false one**.

**7. THE PATTERN PHASE 7 ELEVATES: attribution by dropdown default.** This is no longer an instance, it is
a class. `P6-C2` found a surgical ASA attributed to a *picked* person. Phase 7 finds the same shape three
times in one module — `ed_triages.triaged_by`, `stays.admitting_clinician_id` and `stays.current_bed_id` —
and **worse in kind**, because `Triage.vue:40` and `Disposition.vue:29` **pre-select the first entry of an
unfiltered, alphabetically-ordered staff list**. The ASA required an operator to choose the wrong person;
ED produces the wrong person by default, from a field the user has no reason to touch. In both driven
cases the record named `Beat Suter` — a `surgical_scheduler` who was not present, is not a clinician, and
sorts first. The audit ledger holds the true actor in both cases, so the fact is recoverable — from the
audit trail, never from the clinical record. **QA-FIX.6b's remedy for the ASA (name both people, take the
actor from the session, never from the request) applies unchanged to all three of these columns.**
### Still open as a candidate

**Identity references are inconsistent at the schema level** (`P2-C1` sub-finding, recorded as
D-196). Phase 3 found no third namespace on the money tables — `payments.recorded_by`,
`payment_allocations.allocated_by` and `invoice_adjustments.created_by` are all `users.id`, matching
`signed_by` / `charted_by` / `ordered_by`. `clinical_notes.author_id` (→ `staff_profiles.id`) was
the sole outlier through Phase 5.

**Phase 6 promotes this from a candidate to a pattern, and shows it has a consequence.** The Surgery
module uses **both namespaces on the same case row**:

| Column | Type | Namespace | Written from |
|---|---|---|---|
| `surgical_case_events.performed_by` | `bigint` | `users.id` | the **actor** ✅ |
| `case_item_usages.used_by` | `bigint` | `users.id` | the **actor** ✅ |
| `surgical_checklist_items.confirmed_by` | `bigint` | `users.id` | the **actor** ✅ |
| **`surgical_cases.asa_assessed_by`** | **`char(26)`** | **`staff_profiles.id`** | **a value the operator picks** ❌ |

The one column in the module that uses the `staff_profiles` namespace is also the one column that is
**not** written from the actor — because a `staff_profiles.id` is a *thing you can choose from a
dropdown*, whereas a `users.id` is *who is logged in*. The namespace drift and the attribution defect
in `P6-C2` are the same fact seen twice. That makes this two instances in two modules with a
demonstrated failure mode, rather than a stylistic inconsistency, and it cost an audit probe: the
first attempt to test attribution resolved a `char(26)` staff id against `users` and returned a
plausible but entirely wrong name, which was caught and redone.

---

## Phase 8 — Lab + Radiology

**Date:** 2026-09-08 · **Top commit at audit time:** `c86207a` (the QA-FIX.7d hash backfill), CI
`completed / success` confirmed via `commits/<sha>/check-runs` before driving anything · **Method:**
every surface below driven in a real browser via Playwright MCP against a freshly re-seeded database,
cross-read against the code.

### Roles covered

All **five** roles in `RbacProvisioner::ROLE_TEMPLATES` (26 total) whose permissions name a Lab or
Radiology capability, each driven **separately** with its own login:

| Role | Permissions | Account driven |
|---|---|---|
| `lab_tech` | `patient.view`, `order.manage`, `lab.result` | `elena.costa@klinik-bergblick.test` |
| `pathologist` | `patient.view`, `encounter.manage`, `note.write`, `note.sign`, `order.manage`, `lab.catalog`, `lab.result` | `georg.huber@…` |
| `phlebotomist` | `patient.view`, `lab.result` | `sara.roth@…` |
| `radiographer` | `patient.view`, `order.manage`, `radiology.study` | `fabio.ricci@…` |
| `radiologist` | `patient.view`, `encounter.manage`, `note.write`, `note.sign`, `order.manage`, `radiology.catalog`, `radiology.study` | `miriam.lang@…` |

**`phlebotomist` IS included** — it holds `lab.result` and owns specimen collection, so it belongs to
this group rather than to nursing. **`org_admin`** (`anke.berg@…`) was driven ADDITIONALLY, and only
because it is the sole way to reach the two billing surfaces (see `P8-H5`); it is not a phase-8 role.

**Excluded:** every other role in the 26. `ed_physician`/`triage_nurse`/`ed_charge_nurse` (phase 7),
`surgeon`/`anesthetist`/`scrub_nurse`/`surgical_scheduler` (phase 6), `pharmacist`/`pharmacy_technician`
(phase 5), the four nursing roles (phase 4), `billing` (phase 3), `doctor`/`dentist` (phase 2),
`reception` (phase 1), and `bed_manager`/`him_records`/`org_admin`/`super_admin` (phases 9–10). No role
outside the five names a `lab.*` or `radiology.*` permission.

### Surfaces driven

| Surface | Route | Driven as | Result |
|---|---|---|---|
| Lab test catalog | `GET /lab/catalog` | pathologist (only holder) | ✅ tenant-authored menu, ranges as reference data |
| Lab orders (place) | `GET/POST /lab/patients/{p}/orders` | lab_tech, radiographer | ✅ no attribution selector |
| Specimens | `GET /lab/orders/{o}/specimens` | lab_tech | ✅ collect → receive driven; accession generated |
| Specimen transition | `POST /lab/specimens/{s}/transition` | lab_tech | ✅ `in_lab` recorded with the actor |
| Result entry | `GET /lab/orders/{o}/results`, `POST /lab/specimens/{s}/results` | lab_tech | ✅ **abnormal value created by driving** |
| Result review | `GET /lab/results/review` | all five | ⚠️ empty for four of them (`P8-M5`) |
| Lab billing | `GET /lab/orders/{o}/billing` | org_admin (all five 403) | ❌ `P8-C1`, `P8-H5` |
| Radiology catalog | `GET /radiology/catalog` | radiologist (only holder) | ✅ |
| Radiology orders (place) | `GET/POST /radiology/patients/{p}/orders` | radiographer | ✅ order placed by driving |
| Modality worklist | `GET /radiology/worklist` | radiographer, radiologist | ✅ **state created by placing an order** |
| Study + acquire | `GET /radiology/orders/{o}/study`, `POST …/study/acquire` | radiographer | ✅ acquired by driving; no viewer, and it says so |
| Report author / sign / amend | `GET/POST /radiology/orders/{o}/report`, `…/sign`, `…/amend` | radiologist | ❌ `P8-C2`; ✅ amend → v2 driven |
| Radiology billing | `GET /radiology/orders/{o}/billing` | org_admin (all five 403) | ❌ `P8-C1`, `P8-H5` |
| Landing (pattern 1) | `GET /app` | radiographer | ✅ over-offer CLOSED by QA-FIX.7d |

### Environment

**Redis is UP** (Memurai, `PING → +PONG`), `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`,
`SESSION_DRIVER=database`. Four demo tenants re-seeded and verified by query before driving
(4 tenants / 43 users / 35 patients). **PERFORMANCE IS OUT OF SCOPE** — deferred to staging.

**What the seed does NOT contain**, stated rather than worked around:
- **No abnormal lab value.** Both numeric results are in range (Kalium `4.2` against `3.5–5.1`; CRP
  `3.1` against `< 5`). The D-169 positive control therefore required an abnormal value, which was
  **created by driving the product** — specimen received, Kalium `6.8` entered through the real form.
- **No imaging order awaiting acquisition** — all three seeded orders already had studies. The
  worklist state was **created by driving**: a CT Abdomen order placed through the ordering form.
- **No user lacking a linked `StaffProfile`.** All 20 profiles carry a `user_id` and all 12
  `note.write` holders are linked, so `P8-C2`'s fallback cannot fire on seed data. See that finding
  for exactly how the state was created and restored.
- **No un-invoiced radiology order with charges**, so `P8-C1` was driven in both the never-charged and
  the fully-invoiced state to show the defect is state-independent.

### CRITICAL

#### `P8-C1` — Both billing pages announce an invoice that does not exist, show its total as `NaN`, and hide the only control that could issue one

- **Roles:** none of the five can reach these pages at all (`P8-H5`); driven as `org_admin` ·
  **Routes:** `GET /lab/orders/{o}/billing`, `GET /radiology/orders/{o}/billing`
- **Steps:** open either billing page on **any** order — charged or not, invoiced or not.
- **What happened, verbatim from the rendered page.** On a lab order that has never been charged, the
  page renders `Charges` → **"No charge captured yet."** and then, immediately below:

  > **Issued invoice**
  > **Total: NaN**
  > **Open invoice**

  Radiology is byte-for-byte the same on its never-charged order (`RAD-CT-ABD — CT Abdomen`). On a
  **genuinely invoiced** lab order (`LAB-CBC`, invoice `01m1zvmwpv…`), the charge row renders
  correctly — `LAB-CBC · Blutbild · 1 · 25.00 · 25.00 · invoiced` — and the block below **still** reads
  `Total: NaN`. **The invoice block is wrong in every state**: it never shows a real total and its
  "Open invoice" link resolves to `/lab/orders/{o}/billing`, the page you are already on.
- **Cause — a prop/function name collision, the `P6-C1` shape that QA-FIX.6a fixed in Surgery.**
  `Lab/Billing.vue:18` declares the prop `invoice: { id; url; total_minor } | null`, and `:42-44`
  declares `function invoice(): void`. In Vue `<script setup>` the setup-const wins, so every template
  reference to `invoice` is **the function**, which is always truthy:
  - `:105` `v-if="invoice"` → always true → the "Issued invoice" card **always renders**;
  - `:107` `money(invoice.total_minor)` → `undefined / 100` → `Intl.NumberFormat.format(NaN)` → the
    literal string **`NaN`** (no throw, so the `:27` fallback never runs and would also yield `NaN`);
  - `:108` `:href="invoice.url"` → `undefined` → the self-link;
  - `:98` `v-if="isCharged && !invoice"` → `!function` is permanently `false` → **the issue-invoice
    button never renders**, and neither does the inpatient explanatory note at `:102`.
  `Radiology/Billing.vue` is identical at `:18`, `:41-43`, `:104`, `:106`, `:107`, `:97`, `:101`.
- **The consequence beyond the display.** `POST /lab/orders/{o}/billing/invoice` and its radiology twin
  exist and are routable, but **nothing renders that can call them** — so issuing an outpatient lab or
  imaging invoice is impossible through the product. The client-side `estimateMinor` computed
  (`Lab:33`) is also dead, since its only render site sits inside the permanently-false block.
- **Why CRITICAL:** wrong financial data on a billing screen in two modules; the page **asserts a
  billing action that never occurred** (D-179); and a whole revenue capability has no reachable path.
  It is worse than Surgery's was — Surgery showed `NaN` only after charging, whereas these two claim
  "Issued" on an order that has never been touched.

> ✅ **FIXED — QA-FIX.8a, commit `8636ea1` (D-215).** The QA-FIX.6a / D-208 remedy, applied to both
> modules. `invoice()` → `issueInvoice()` un-shadows the prop, so `v-if="invoice"` now reads the PROP:
> the "Issued" figure appears only when an invoice exists, `invoice.url` is the invoice's own URL, and
> the issue-invoice button renders on a charged, uninvoiced order — **an outpatient lab or imaging
> invoice can now be issued through the product, which it could not be before.**
> **THE RENAME WAS THE SMALL HALF.** Both pages also derived their own money — `quantity ×
> unit_price_minor` per line, a client-side `.reduce()`, and `Intl.NumberFormat` with **no currency**, so
> a pure rename would have rendered `2,974.00` where the tenant's figure is `EUR 2'974.00`. Every amount
> now comes from **`ChargeSetReader`**, already summed and formatted, with the currency read from the
> charges' own tariff catalog. The Rate column ships `rate_formatted` instead of a number, and the dead
> `tariffs` prop (declared in both pages, used in neither, carrying a rate) is gone — **the page cannot
> multiply what it does not have.**
> **THE FENCE IS WHY THE DEFECT EXISTED, AND IT STAYS GREEN.** Both modules forbid the engine's total
> columns byte-for-byte under their own `src/`, and the controllers' own comments said the fence *"keeps
> every money math out of Lab"* — so the arithmetic had been pushed into the Vue, the one place it must
> never be. `ChargeSetReader` lives in `Modules/Billing`, so the module SHOWS the figure without NAMING
> the column: no fence was loosened, and the arithmetic went back to the engine.
> **LABELS, per QA-FIX.6a's reasoning:** pre-invoice **"Estimated total"** (the net ex-VAT Σ of THIS
> order's lines); once issued **"Invoice total"** (the invoice's own figure — `invoiceOrder()` gathers
> every validated uninvoiced charge for the patient across the whole service DAY, and VAT is added at
> issue, so it can legitimately exceed those lines). The table column is now **"Amount"**, because it is
> the engine's line total and never was an estimate.
> **Guarded by** ten tests, mutation-checked three ways (restoring `function invoice(`, restoring a
> page-side derivation, resetting the label), each grep-confirmed applied before its test ran. Positive
> controls hold Surgery unchanged and both module fences green.

#### `P8-C2` — A radiology report can be attributed to a person who did not write it, chosen alphabetically, with no dropdown anyone could correct

- **Role:** `radiologist` · **Route:** `POST /radiology/orders/{o}/report`
- **Cause.** `ImagingReportController::resolve()` (`:153-170`):

  ```php
  $radiologist = StaffProfile::query()->where('user_id', $actor?->getKey())->first()
      ?? StaffProfile::query()->orderBy('display_name')->firstOrFail();
  ```

  The docblock states the intent exactly — *"The radiologist authors their OWN report — resolved from
  the acting user's linked StaffProfile."* The first clause implements it. **The `??` fallback silently
  substitutes the alphabetically first staff profile in the tenant.**
- **Steps + what happened (driven).** With the seed, all 20 staff profiles carry a `user_id` and all
  12 `note.write` holders are linked, so the fallback cannot fire. The state was therefore created:
  `staff_profiles.user_id` for `Dr. med. Miriam Lang` was set to `NULL` — **the only reachable way, as
  no product surface unlinks a profile** — then a report was authored **through the real form** while
  signed in as `miriam.lang` (radiologist, `note.write`). The stored note reads:

  > `author_id → Beat Suter (profession = coordinator)`

  **The link was restored immediately afterwards and verified** (0 unlinked profiles remain).
- **Why CRITICAL, and why it is the most severe form of pattern 7 the audit has found.** `P7-C1` and
  `P7-C2` required an operator to leave a dropdown untouched — the wrong name was at least *on screen*.
  Here there is **no dropdown at all**: the substitution happens server-side, invisibly, and the
  surface then names nobody (`P8-H3`), so the misattribution is unobservable from the product. It
  lands on a **signed clinical report**. And it selects **Beat Suter, the `surgical_scheduler`/
  coordinator — the same person, by the same alphabetical mechanism, as both Phase-7 criticals.**
- **When it fires in practice:** whenever a user holding `note.write` + `radiology.study` has no linked
  `StaffProfile`. That is the default state of a newly provisioned user — `tenant:add-admin` and role
  assignment do not create a profile — so a real tenant is more exposed than the demo seed. With
  **zero** staff profiles it instead throws (`firstOrFail`), i.e. a 500 rather than a wrong name.


> ✅ **FIXED — QA-FIX.8b, commit `5a16624` (D-216).** `resolve()` now returns a NULLABLE profile from
> `StaffProfile::forUser()` — the QA-FIX.2a / D-195 helper that returns null rather than guessing — and
> authoring **refuses** when the actor cannot be identified, in the same words the two Clinical
> controllers already use: *"Refuse rather than guess."* The `?? orderBy('display_name')->firstOrFail()`
> is gone.
> **THIS FINDING REWROTE PATTERN 7, AND THE FIX REFLECTS THAT.** Seven phases called the pattern
> "attribution by dropdown default"; QA-FIX.7a's remedy was to remove the default. **This module has no
> dropdown**, so that remedy would not have touched this instance. The real shape is *resolving a person
> by convenience when the identity is unknown*.
> **THE GUESS CORRUPTED TWO RECORDS.** The resolved profile also went to `reportEncounter()`, so a
> substituted author became the report **encounter's practitioner** as well. A test now pins both.
> **SIGNING IS DELIBERATELY UNAFFECTED** — a signature is the acting USER (`signed_by`), needs no
> profile, and stays reachable for an account without one; blocking it would have been an
> over-correction, and a test pins that it still works. The legitimate author/signatory split Phase 2
> found is pinned too.
> **THE SHAPE IS UNIQUE IN THE CODEBASE.** A sweep across `app/` and `Modules/` for a SINGLE person
> resolved by sort order returns **exactly one hit — this one**. The five other
> `StaffProfile::query()->orderBy('display_name')` sites build option LISTS for dropdowns; they present
> choices rather than resolving an identity, and remain the separate open list-width question.
> **NO HISTORICAL ROW IS REWRITTEN, and the count cannot honestly be given as a number.** A substitution
> is NOT detectable by comparing author to audit actor, because *author ≠ actor is legitimate here* — it
> is exactly the two-person shape recorded above. It is identifiable only when the author happens to be
> the alphabetically-first profile AND the actor lacked a profile at the time, and the second condition
> is recorded nowhere. **In the demo data: 2 report notes, 0 substituted.** On a real tenant the exposure
> is higher, since a newly provisioned user has no profile by default.
> **The refusal is VISIBLE:** `Radiology/Report.vue` adopts the existing `RefusalNotice.vue`, because
> this part introduces a refusal there and a refusal nobody can see is the `P6-C3` defect. `P8-H1` (the
> other eleven pages) stays open.
> **Guarded by** seven tests, mutation-checked two ways. The fixture makes the actor ≠ the
> alphabetically-first profile **and asserts it** — the reason `P2-C1`, `P6-C2` and `P7-C1` all survived
> their own suites.

### HIGH


#### `P8-H1` — Neither Lab nor Radiology renders any refusal: the `P6-C3` / `P7-H2` defect, third module

**18 `->withErrors([...])` sites across 10 controllers** (Lab: billing 3, catalog 1, orders 1, results 1,
specimens 2; Radiology: report 3, study 2, billing 3, catalog 1, orders 1) — and
`grep -rln "RefusalNotice|page.props.errors" resources/js/pages/Lab resources/js/pages/Radiology`
returns **nothing**. A refused action and a successful one are indistinguishable: the page reloads,
nothing is recorded, no message appears. QA-FIX.7c adopted `RefusalNotice.vue` for `pages/ED/*` only;
QA-FIX.6c did `pages/Surgery/*`. The component exists and is used by 11 pages in two other modules.
**Code-established** as to the count; the *shape* was driven in Phase 7 and is unchanged here.

#### `P8-H2` — Lab and Radiology billing capture a charge and link it outside any transaction, so a retry can double-bill

`grep -c "DB::transaction"` returns **0** for both `LabBillingService.php` and
`RadiologyBillingService.php`. `LabBillingService::chargeOrder` is the exact `EdBillingService` shape
recorded as `P7-M5`: idempotency read of the link table (`:93-96`) → `captureManual` (`:107`) →
`LabOrderCharge::create` (`:108`), **with nothing wrapping the last two**. If the link write fails, the
`Charge` exists and the link does not; the idempotency guard reads the *link* table, so it does not see
the orphan, and the next attempt **captures a second charge for the same order**. Radiology is
identical. Contrast `RadiologyOrderService::place` (`:67`), which *is* wrapped — so the module knows the
idiom and the billing path omits it. **Code-established:** the failure needs an induced write error,
which the audit does not do. (`P8-C1` also makes the invoice step unreachable, so the double-bill is
currently only reachable via the charge step.)


> ✅ **FIXED — QA-FIX.8c, commit `946cf87` (D-217), and it closes `P7-M5` with it.** All three billing
> services now wrap their capture and its link row in ONE `DB::transaction`, with the owning row locked
> `FOR UPDATE` (tenant-scoped) and the idempotency read moved INSIDE the lock, so two concurrent captures
> serialise instead of both reading an empty guard. The link is written BESIDE its charge, never in a
> later pass — the QA-FIX.6a / D-208 remedy copied unchanged.
> **`EdBillingService` WAS INCLUDED BECAUSE IT WAS THE WORST OF THE THREE.** It captured every charge into
> a collection first and wrote the links in a **separate later loop**, so one failure orphaned *every*
> charge rather than one — the exact `P6-M10` shape. Fixing the two milder twins while leaving the worst
> one open, one file away, would have been indefensible; all three take the identical remedy; and ED
> billing is currently unreachable by any ED role (`P7-H5`), so the change cannot destabilise a live path.
> **ONLY ED CAN BE DEMONSTRATED LIVE, AND THAT IS STATED.** Because it captures more than one charge, a
> mid-capture failure is reachable by pricing the attendance but not the service code — so the orphan, the
> double-billing retry, and the audit/hash-chain properties are all live tests. Lab and Radiology capture
> exactly one charge per order, so there is no "partway" to fail at without mocking the link write; their
> atomicity rests on the structural guard plus the identical remedy. A parallel-hammer concurrency test
> was considered and **not** added — the suite already carries five and they time out under load.
> **Guarded by** eight tests, mutation-checked three ways: removing any one service's transaction reddens
> its guard, and removing ED's fails **four** tests. Each mutation was confirmed by a comment-stripped
> count, because every one of these files now explains the old defect in prose naming the thing counted.
#### `P8-H3` — Every actor is recorded correctly and no surface names any of them

The data model is right throughout: `order_results.entered_by`, `specimen_events.performed_by`,
`imaging_study_events.performed_by` and `clinical_notes.signed_by` are all `users` FKs holding the
**authenticated actor**, and `clinical_notes.author_id` is the `staff_profiles` clinician — the
two-person shape QA-FIX.2a/D-195 asks for. **Driven and verified:** my specimen receive recorded
`elena.costa`, my acquire recorded `fabio.ricci`. **And not one of them is displayed.** The report
version block renders `Version 1 · Signed · Sep 07, 11:35 PM` and the study history renders
`Ordered · … / Acquired · …` — a text scan of the whole report page for `Dr.|med.|Lang|Berg` returns
**nothing**. This is `P7-C2`'s mirror image: there the surface was missing while the actor was
recorded, and the same is true here across both modules.

#### `P8-H4` — Lab and Radiology have no entry anywhere in the shell: 34 routes reachable only by typing a URL

`AppLayout.vue:35-46` (10 primary items) and `:51-57` (5 admin items) contain no `/lab` or `/radiology`
href, and `NAV_PERMISSIONS` (`HandleInertiaRequests.php:24-46`, now 15 keys) contains none of
`lab.catalog`, `lab.result`, `radiology.catalog`, `radiology.study` — so even an added nav item would
be hidden by `canSee` at `AppLayout.vue:59`. **Driven as `radiographer`: the nav renders exactly
Dashboard / Patients / Orders.** No page outside `pages/Lab` and `pages/Radiology` links to either
module. The two worklists staff are meant to work from daily — `/lab/results/review` and
`/radiology/worklist` — are URL-only. **This is pattern 1's UNDER-OFFER half, which QA-FIX.7d
deliberately did not take** (D-214: six top-level entries would re-create the density defect D-111
fixed); this phase is the first evidence of what it costs a role group in practice. The single
navigational thread is *outbound* — `Radiology/Report.vue:96` links to `/clinical/orders/review`.

#### `P8-H5` — Both billing surfaces are gated on a permission no role in this group holds

`LabBillingController:34,85,105,122` and `RadiologyBillingController:34,84,104,121` all authorize
`billing.manage`. **Driven: all five roles receive 403** on both billing routes. This is the `P7-H5`
shape exactly (five ED billing routes on a permission no ED role held) and the `P6` surgery-billing
shape before it — a third module whose billing exists but is unreachable by anyone who works in it.
Combined with `P8-C1`, the lab/imaging billing path is unreachable twice over: by permission for the
group, and by a dead control for everyone else.

### MEDIUM

#### `P8-M1` — Timestamps render in the viewer's zone and US format; the tenant's zone is shipped and read by nothing

**Driven:** a lab result written at `2026-09-08 06:44:12` UTC (verified **62 seconds** from CLI `now()`,
so **storage is correct**) rendered as **`Sep 07, 11:44 PM`** — the viewer's `America/Los_Angeles`, a day
and nine hours out. The branch zone is `Europe/Zurich`, where it should read `08.09.2026 08:44`. Eight
page-local `fmt()` helpers exist across eight pages; **none passes a `timeZone` option**, and six omit
the **year** entirely (`{day, month:'short', hour, minute}`), so a prior-year record is indistinguishable
from this year's in the specimen, study and report histories. Two pages (`Lab/Orders.vue:37`,
`Radiology/Orders.vue:38`) use bare `toLocaleString()`, giving a *second* format for the same instant
inside one module. Compounding it: `HandleInertiaRequests.php:74` ships the tenant `timezone` prop and
claims *"the client converts for display using this zone"* — **no Vue page consumes it**. Pattern 2,
**eighth consecutive phase**; display-only, storage sound.

#### `P8-M2` — Both billing pages do money arithmetic in the view layer while claiming they do not

`Lab/Billing.vue:92` renders `money(c.quantity * c.unit_price_minor)` and `:33` sums
`charges.reduce((sum, c) => sum + c.quantity * c.unit_price_minor, 0)`; `money()` divides by 100
(`:26`, fallback `:29`). Radiology is identical at `:91`, `:32`. Even the authoritative issued-invoice
total is re-formatted client-side (`:107` / `:106`) from a raw `total_minor` integer. **Both files carry
a header comment at `:8` stating "NO money math here — the engine owns pricing/line-totals."** This is
the D-208 shape QA-FIX.6a removed from Surgery, still live in two modules — so Phase 3's "zero
page-side sums" holds only for `resources/js/pages/Billing/`, exactly as this phase's brief anticipated.

#### `P8-M3` — No currency is shipped to either billing page, so every figure is a bare number

`LabBillingController:73` and `RadiologyBillingController:72` emit `['id','url','total_minor']` only;
neither `ChargeRow` type carries a currency, and `money()` uses `Intl.NumberFormat` with no
`style:'currency'`. **Driven:** the charge table reads `25.00` with no unit anywhere on the page. A CHF
tenant and a EUR tenant render identical unlabelled amounts. `Invoice` does carry `currency`
(`Invoice.php:31,74`), so the datum exists and is simply not sent.

#### `P8-M4` — A lab result is published by the act of entering it: there is no release or verification step

Recording a result advances the clinical order to `resulted` in the same transaction — **driven:** the
order moved `ordered → resulted` the moment the value was saved. There is no preliminary/held/verified
state, no release route among the 16 Lab routes, and no `released_by`/`verified_by` column;
`order_results` carries `result_value`, `entered_by`, `entered_at`, `source` and nothing else. The
entering user **is** recorded (the actor), and `/lab/results/review` is the *ordering clinician's*
acknowledgement queue, not a release gate. Recorded as a **finding about the model, not a defect in it**:
for a single-operator lab this is coherent and honest, but a tech-enters → pathologist-verifies →
released workflow cannot be expressed, and nothing on screen tells a clinician whether the value they
are reading has been checked by a second person.

#### `P8-M5` — `/lab/results/review` is reachable by four roles and permanently empty for three of them

**Driven:** as `lab_tech` the page renders *"No results awaiting your review"* immediately after that
same tech recorded a result. The queue is scoped to the **ordering clinician**, so `lab_tech`,
`radiographer` and `radiologist` reach a page (200) that can never show them anything, while
`phlebotomist` is correctly refused (403). An always-empty destination for three of five roles is the
D-176 shape — an affordance for something that cannot happen — and the empty state does not say *"this
queue is for the clinician who ordered the test"*, so it reads as "nothing outstanding".

#### `P8-M6` — Six data tables have no narrow-viewport behaviour, diverging from the rest of the app

`grep -rn overflow` over `pages/Lab` and `pages/Radiology` returns **nothing**: all six tables
(`Lab/Orders:85`, `Lab/Catalog:70`, `Lab/Billing:75`, `Radiology/Orders:90`, `Radiology/Catalog:73`,
`Radiology/Billing:74`) are bare `<table class="w-full">` with no wrapper — including the six-column
billing table. Fourteen tables elsewhere (Billing ×12, Scheduling ×2) **do** use `overflow-x-auto`, so
this is a divergence from an established convention rather than a missing decision.
`Radiology/Orders.vue:65` additionally pins `grid-cols-3` at every width. The two modules carry six
responsive utility classes in total, all in the catalog forms.

#### `P8-M7` — The `stat` priority is coloured on three pages and not on the fourth

`Lab/Orders.vue:100`, `Radiology/Orders.vue:107` and `Radiology/Worklist.vue:85` bind
`o.priority === 'stat' ? 'text-danger' : 'text-ink'`; `Lab/Review.vue:94` renders the same field with a
static class. **This stays inside the fence** — it colours a flag the clinician *set*, never a derived
value (D-169 governs computed judgments, not recorded ones) — and is recorded as a visual
inconsistency, with the reasoning stated so a later pass does not "fix" it by tinting a result value.

### LOW

#### `P8-L1` — No navigation below 768 px

**Driven at 390×844 on the radiology study page: 0 of 3 nav links have a non-zero width and there is no
menu button.** `AppLayout.vue:113` is `hidden … md:flex` and the 228-line file contains no hamburger or
drawer. Content itself does not overflow (`scrollWidth === clientWidth === 390`), so this is purely
navigation. Pattern 3, **eighth consecutive phase** — and it compounds `P8-H4`: on a phone a URL-only
module has no fallback entry point at all.

#### `P8-L2` — The amendment reason is collected through a native `prompt()` dialog

**Driven:** clicking *Amend (new version)* raises the browser's own `prompt("Reason for the
amendment:")`. The reason is captured and displayed correctly, but a native dialog cannot be styled,
translated, validated, or made accessible, and it is the only such dialog encountered in eight phases.

#### `P8-L3` — The lab result form has no client-side `required`, and the exam select pre-selects the first catalog entry

The result-value input carries no `required` attribute, so an empty submit reaches the server (the
refusal is then invisible — `P8-H1`). Separately, `Radiology/Orders.vue:32` initialises
`radiology_exam_id: props.exams[0]?.id ?? ''`, so an order placed without touching the select is a
**CT Abdomen** — the first entry alphabetically. This is **not** pattern 7 (an exam is not a person),
but it is the same first-in-list shape QA-FIX.7a judged worth removing for the ED bed, and a CT carries
a radiation dose. Recorded at LOW because the exam is the form's visible subject, unlike a bed or a
name buried in a field nobody looks at.

### Guards verified holding

#### THE RESULT-RELEASE FENCE — this phase's assigned question, answered in four parts

A lab result and a radiology report become clinically actionable the moment they are visible, so this
is the phase's hardest boundary. Each part was driven.

**1. Is a result released by a human act, or by a side effect?** **By the act of entering it** — there
is no separate release step (`P8-M4`). Recording a value advances the order to `resulted` in the same
transaction; there is no preliminary/verified state and no release route. A radiology report is
different and stronger: it has an **explicit two-step human act** — *Save draft* then *Sign & file
report* — and only signing routes it to the ordering clinician's worklist. **Driven both.**

**2. Is the releaser recorded — the ACTOR, or a picked person?** **The actor, from the session, in
every case.** `order_results.entered_by`, `specimen_events.performed_by`,
`imaging_study_events.performed_by` and `clinical_notes.signed_by` are all `users` FKs written from the
authenticated user; **not one is request-sourced**, and no Lab or Radiology form contains a person
selector at all. Driven: my specimen receive recorded `elena.costa`, my acquire recorded `fabio.ricci`.
**The one exception is `P8-C2`** — the report *author* (as distinct from its signatory) can fall back to
an alphabetical pick — and it is filed as this phase's second CRITICAL.

**3. Is a released result immutable, amendable-with-history, or silently editable?** **Immutable, belt
and suspenders.** `LabResult` has model `updating`/`deleting` guards throwing `appendOnly()` (`:51-52`),
and the database carries `SIGNAL '45000'` triggers on `lab_results`, `order_results` **and**
`imaging_study_events` (6 of the 92 triggers). A radiology report is **amendable with history**:
**driven** — amending a signed report created **Version 2 (Draft)** carrying the amendment reason, while
**Version 1 (Signed) survived byte-identical** (`created_at == updated_at`, verified in the database,
`supersedes_id` chaining v2 → v1). Nothing is silently editable.

**4. Does anything COMPUTE an interpretation?** **No — and the product says so, repeatedly and
correctly.** No abnormal/high/low flag, no reference-range verdict, no critical-value alert, no delta
against a prior result, no AI imaging finding. Reference ranges are stored on the tenant-authored test
catalog (`lab_tests.reference_range`, e.g. `3.5–5.1 mmol/L`) and rendered **beside** the value as
reference data. The result screen states it in the product's own words:

> *"The reference range is reference data shown beside the value — the clinician reads value against
> range. The system never computes an abnormal, high, low or critical flag."*

The imaging seam is equally honest — `Radiology/Study.vue` renders no image, no canvas and no viewer,
and says: *"Image storage and viewing (DICOM/PACS) are provided by a certified imaging partner and are
not available here. This is the study record (metadata) — not a diagnostic viewer."* The connectivity
seam is a null implementation (`NullImagingConnectivity`). **D-172 holds.**

#### `D-169` ON RESULT VALUES — the positive control, byte-for-byte

A value outside its reference range is the most tempting thing in the product to tint red. The seed
contained no abnormal value, so one was **created by driving**: Kalium **`6.8`** entered through the
real form against a recorded range of `3.5–5.1` (clearly high), compared with the existing in-range
**`4.2`** on the same test. The two rendered entries are **identical markup apart from the digits**:

| | `6.8` (high) | `4.2` (normal) |
|---|---|---|
| container | `rounded-lg border border-euca-100 p-4` | *identical* |
| value span | `text-lg font-semibold text-ink` | *identical* |
| computed colour | `rgb(42, 51, 42)` | *identical* |
| font weight / size | `600` / `18px` | *identical* |
| border | `rgb(237, 243, 234) 0.8px` | *identical* |
| range span | `text-xs text-ink-subtle`, `rgb(108, 119, 108)` | *identical* |

Across all 12 Lab/Radiology pages there are **seven** dynamic class bindings and **zero** style
bindings; not one reads a result value or a range. **D-169 passes.**

#### The author / signatory split (Phase 2's observation, re-verified)

Phase 2 recorded that seeded radiology reports are authored by Dr. Lang and signed by Dr. Berg. **The
data confirms a genuine two-person shape in two different id spaces:** `clinical_notes.author_id` →
`staff_profiles` (`Dr. med. Miriam Lang`), `clinical_notes.signed_by` → `users`
(`anke.berg@klinik-bergblick.test`). Neither stands in for the other, and the signatory is the actor.
**But the requirement was that both be named DISTINCTLY in the browser, and they are not named at
all** — see `P8-H3`. So: the model is right, the surface is silent, and `P8-C2` shows the author half
can additionally be wrong.

#### Other guards confirmed holding

- **Pattern 7 is ABSENT from every Lab and Radiology FORM** — no attribution dropdown exists anywhere
  in either module, and no attribution field is accepted from the request. The one instance
  (`P8-C2`) is server-side. This is a genuinely better result than Phases 6 and 7.
- **Ordering is by a recorded flag, never a computed urgency.** Both worklists use a fixed
  `{stat:0, urgent:1, routine:2}` presentation order over the flag the clinician set, with the
  alternative sort being the timestamp — and each carries a comment saying so. **No `localeCompare` on
  a clinical level** (the `P7-C3` defect) exists here.
- **Order placement is transactional** (`RadiologyOrderService::place:67`), and the modality/body-part
  placeholder honestly previews what the server will store (`$modality ??= $orderable->…`) — checked
  because it looked like a placeholder being saved as a value, and it is not.
- **Cross-tenant is fail-closed** on every surface driven; every read is `patient.view`-gated and
  patient-scoped read-logged.
- **Specimen accessioning is a real fact** — `ACC-000004` generated on collection, with the transition
  trail `collected → in_lab → resulted`, each event carrying its actor.

### RBAC — all five roles on identical routes, driven

Every cell below is a real HTTP status from a real authenticated browser session.

| Route | `lab_tech` | `pathologist` | `phlebotomist` | `radiographer` | `radiologist` |
|---|---|---|---|---|---|
| `GET /lab/catalog` | 403 | **200** | 403 | 403 | 403 |
| `GET /lab/patients/{p}/orders` | 200 | 200 | 200 | 200 | 200 |
| `GET /lab/orders/{o}/specimens` | 200 | 200 | 200 | 200 | 200 |
| `GET /lab/orders/{o}/results` | 200 | 200 | 200 | 200 | 200 |
| `GET /lab/results/review` | 200 | 200 | **403** | 200 | 200 |
| `GET /lab/orders/{o}/billing` | 403 | 403 | 403 | 403 | 403 |
| `GET /radiology/catalog` | 403 | 403 | 403 | 403 | **200** |
| `GET /radiology/patients/{p}/orders` | 200 | 200 | 200 | 200 | 200 |
| `GET /radiology/worklist` | 403 | 403 | 403 | **200** | **200** |
| `GET /radiology/orders/{o}/study` | 200 | 200 | 200 | 200 | 200 |
| `GET /radiology/orders/{o}/report` | 200 | 200 | 200 | 200 | 200 |
| `GET /radiology/orders/{o}/billing` | 403 | 403 | 403 | 403 | 403 |

**Asymmetries worth naming.** The catalogs are correctly narrow — only `pathologist` may author the lab
menu, only `radiologist` the imaging menu — but that means **`lab_tech` cannot see the reference ranges
it results against**, and `radiographer` cannot see the exam catalog it acquires from. Both worklists
are correctly gated. **`/lab/results/review` is the one inconsistency**: `phlebotomist` is refused while
three roles that can never have content are admitted (`P8-M5`). And **every role can read a radiology
report and a lab result for any patient** — broad, but a deliberate consequence of `patient.view` being
the clinical-record gate, and every such read is audit-logged.

*Forging the forbidden:* the billing routes were requested directly by URL as all five roles and refused
every time (403, not a redirect and not a partial render); no Lab/Radiology surface leaked a payload to
a role the Gate refuses.

### Cross-phase patterns — EIGHT phases of evidence

**1. Ungated UI — THE OVER-OFFER IS CLOSED; THE UNDER-OFFER IS NOT.** For the first time in eight
phases this pattern does **not** produce a 403. Driven as `radiographer`, `/app` renders **zero** body
links and therefore zero dead ends, where Phases 2–7 each measured four. **QA-FIX.7d (D-214) closed
it**, and this phase is its independent confirmation on a role group the fix was not written against.
**The under-offer half remains open by decision** and this phase is the first to measure its cost:
`P8-H4` — 34 routes, two daily worklists, and both modules unreachable by clicking anything.

**2. Timestamp and locale divergence — PRESENT, eighth phase**, and the worst instance yet: `P8-M1`,
a result rendered `Sep 07, 11:44 PM` for a row stored `06:44 UTC` in a `Europe/Zurich` branch — a **day
and nine hours** out. Storage remains correct in every phase. New this phase: six formatters omit the
**year**, two use a different format from the other six *inside the same module*, and the tenant
timezone prop that D-192 added is shipped on every response and **read by nothing**.

**3. No navigation below 768 px — PRESENT, eighth phase** (`P8-L1`): 0 of 3 nav links visible, no menu
button. Newly load-bearing here because these two modules have no nav entry at any width.

**4. A granted capability with no surface — PRESENT, eighth phase, in both directions at once.**
`P8-H5`: both billing surfaces gated on `billing.manage`, which none of the five roles holds — the
`P7-H5` / Phase-6 shape, third module. `P8-C1`: and for the one role that *can* reach them, the
issue-invoice control never renders, so the capability has no path for anybody.

**5. The fences hold — CONFIRMED IN ALL EIGHT PHASES, and Lab/Radiology is the cleanest result yet.**
The product refuses to interpret a result at exactly the point where interpreting would be most useful
and most dangerous: no abnormal flag, no range verdict, no critical alert, no delta, no CAD, no viewer.
D-169 passes **byte-for-byte** on a value 33% above its range. Reference ranges are displayed as
reference data and never as a judgment, and the screens say so in their own words. **No fence has
eroded in eight phases.**

**6. A partial record — PRESENT IN BOTH DIRECTIONS.** Refused: `P8-H1`, 18 `withErrors` sites and zero
pages reading the bag — the `P6-C3` defect in its third module. Succeeded-but-wrong: `P8-C2`, a report
that saves cleanly and records the wrong author; and `P8-H2`, a charge that can be captured with its
link missing, leaving an orphan the idempotency guard cannot see.

**7. Attribution by dropdown default — ABSENT AS A DROPDOWN, PRESENT IN A WORSE FORM.** This is the
phase's most important pattern result. **Not one attribution dropdown exists in either module**, and no
attribution field is request-sourced — a genuinely better starting position than Phases 6 and 7. But
`P8-C2` shows the same failure arriving **server-side**: a silent `?? StaffProfile::orderBy('display_name')->first()`
fallback that attributes a signed radiology report to the alphabetically first staff member. Driven, it
selected **Beat Suter** — the same person the Phase-7 criticals landed on. The pattern is therefore not
about dropdowns at all: it is about **resolving a person by convenience when the real one is unknown**,
and removing the dropdown does not remove it.

**8. A NEW PATTERN THIS PHASE: the module-local formatter.** Eight `fmt()` helpers, two `money()`
helpers and zero imports from `@/lib/date` or `@/lib/money` across 12 pages. Each module re-implements
presentation locally, so a decision recorded once — Swiss money grouping (D-091's neighbour), the
tenant timezone (D-192), the shared date helper — reaches only the module that was open at the time.
`P8-M1`, `P8-M2` and `P8-M3` are three faces of this one cause, and it predicts that phases 9 and 10
will find the same three again unless the helper is made the only path.

### Anything untestable, and why

- **`P8-H2`'s double-bill** could not be driven: it needs an induced failure between the charge capture
  and the link write. **Code-established** (`grep -c "DB::transaction"` = 0 in both services), the
  `P6-M10` / `P7-M5` precedent.
- **`P8-H1`'s count** is code-established (18 sites / 0 readers); the *behaviour* was driven in Phase 7
  and the component adoption is unchanged here.
- **`P8-C2` required a created state.** No product surface unlinks a `StaffProfile`, and every seeded
  `note.write` holder is linked, so the fallback cannot fire on seed data. `staff_profiles.user_id` was
  set to `NULL` for one user, the report authored **through the real form**, and the link **restored and
  verified** immediately (0 unlinked profiles remain). The *finding* is browser-established; only the
  *precondition* was arranged.
- **The lab worklist could not be driven with content for three roles** — `/lab/results/review` is
  scoped to the ordering clinician, and no seeded lab order was placed by one of the five roles.
- **PACS/DICOM behaviour is untestable by design** — the seam is a null implementation and the product
  states that image viewing is a certified-partner function.
- **Performance is out of scope**, deferred to staging per the phase brief.

---

## Phase 9 — Bed management + Medical records

**Date:** 2026-09-08 · **Top commit at audit time:** `1d12612`, CI `completed / success` confirmed via
`commits/<sha>/check-runs` before driving anything · **Method:** every surface below driven in a real
browser via Playwright MCP against a freshly re-seeded database, cross-read against the code. **AUDIT
ONLY — nothing was fixed.**

### Roles covered

The two roles in `RbacProvisioner::ROLE_TEMPLATES` that name a bed/ward or records capability, each
driven **separately** with its own login:

| Role | Permissions | Account driven |
|---|---|---|
| `bed_manager` | `ward.manage`, `bed.manage`, `patient.view`, `reporting.view` | `urs.baumann@klinik-bergblick.test` |
| `him_records` | `patient.view`, `note.supervise`, `document.view`, `audit.view` | **none seeded** — provisioned by driving `/admin/roles` (below) |

**`him_records` has no account in any of the four demo tenants.** The role *template* is seeded in every
tenant (`RbacProvisioner:201-206`); no user holds it. It was provisioned **through the product**, not by
SQL: signed in as `org_admin` (`andrea.lindenhof@praxis-lindenhof.test`), opened `/admin/roles`, selected
*Health Information / Records* for `nadia.steiner@praxis-lindenhof.test` and clicked **Assign**.
`UserRoleController::assign` REPLACES a user's assignments, so she held exactly `him_records` and nothing
else. Verified in the database (`user 9 role=him_records`) and in the ledger (`role.revoked` then
`role.assigned`, both `actor=2`, 23:15:40) before driving anything as her. **Restored to `Reception`
through the same screen at the end, and verified.**

**Tenant choice, stated:** `bed_manager` was driven in `klinik-bergblick` — the hospital tenant, the only
one with wards, beds and stays. `him_records` was driven in `praxis-lindenhof`, because
`DemoHospitalSeeder` seeds **zero documents and zero consent templates**, so the records fence could not
be tested in the hospital tenant at all. Three further accounts were driven ONLY to establish positive
controls the two phase roles cannot reach: `lena.studer@klinik-bergblick.test` (`ward_nurse`, for
`P9-C3` / `P9-H3`), `matthias.brunner@…` (`doctor`, for `P9-C2`) and `thomas.ammann@…` (`billing`, for
`P9-C1`). They are not phase-9 roles.

**Excluded:** every other role in the 26 — phases 1–8 covered them, and phase 10 covers `org_admin`,
`super_admin` and the patient portal. Outside these two, only `org_admin` names a `ward.*`, `bed.*` or
`document.view` permission (it holds all of them).

### Surfaces driven

| Surface | Route | Driven as | Result |
|---|---|---|---|
| Ward board | `GET /hospital/wards` | bed_manager | ⚠️ discloses every inpatient, **no read row** (`P9-H2`) |
| Bed status write | `POST /hospital/beds/{bed}/status` | bed_manager | ✅ Block driven; audited, hash-chained, actor correct |
| Admission detail | `GET /hospital/admissions/{stay}` | bed_manager | ⚠️ raw ISO-8601 (`P9-M1`) |
| Bedside chart | `GET /hospital/admissions/{stay}/chart` | bed_manager, ward_nurse | ❌ `P9-C3`, `P9-M2` |
| Ward round (start) | `POST …/rounds` | ward_nurse | ❌ `P9-C3` — attributed to the admitting clinician |
| Observation (record) | `POST …/vitals` | ward_nurse | ❌ `P9-C3` — `recorded_by` is the admitting clinician |
| Refusal (repeat round) | `POST …/rounds` a second time | ward_nurse | ❌ `P9-H3` — server refuses, screen shows nothing |
| Handover | `GET …/handover` | bed_manager | ✅ read-logged; no click path to it (`P9-M5`) |
| Discharge summary | `GET …/discharge-summary` | bed_manager | ⚠️ same event, different clock (`P9-M1`) |
| ADT writes | `POST …/transfer`, `…/discharge`, `POST /hospital/admissions` | bed_manager | ✅ **403** — correctly refused |
| Roles & access | `GET /admin/roles`, `POST /admin/roles/assign` | org_admin | ✅ assignment audited in both directions |
| Patient 360 | `GET /patients/{p}` | him_records | ⚠️ there is no Documents tab at all (`P9-H5`) |
| Access log (PC.P5) | `GET /patients/{p}/access-log` | him_records | ❌ `P9-C2` — a release does not appear |
| Access-log export | `GET …/access-log/export` | him_records | ✅ 200, and the export audits itself |
| Document download | `GET /clinical/documents/{d}` | him_records | ⚠️ 200 via `patient.view`, not `document.view` (`P9-H4`) |
| Document release | `POST /clinical/documents/{d}/share` | him_records / doctor | ❌ `P9-H5` (403); ✅ performed as doctor |
| Consent capture / withdraw | `POST /patients/{p}/consents`, `…/withdraw` | him_records / doctor | ❌ 403 for him_records; ✅ both driven as doctor |
| AR report export | `GET /billing/report/export` | billing | ❌ `P9-C1` — patient identifiers leave with **no audit row** |
| Governance | `GET /governance` | him_records | ✅ 200 read-only; the export correctly 403s (`audit.export`) |
| Landing (pattern 1) | `GET /app` | bed_manager, him_records | ✅ over-offer CLOSED; under-offer total (`P9-M5`) |

### Environment

**Redis is UP** (Memurai, `PING → +PONG`), `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`,
`SESSION_DRIVER=database`. Four demo tenants re-seeded and verified by query before driving (4 tenants /
43 users / 35 patients; beds 4 cleaning / 1 occupied / 1 free / 1 blocked; 3 stays, 1 admitted).
**PERFORMANCE IS OUT OF SCOPE** — deferred to staging.

**What the seed does NOT contain**, stated rather than worked around:
- **No `him_records` user, in any tenant.** Provisioned through `/admin/roles` and restored — see above.
- **No documents and no consent templates in `klinik-bergblick`.** `DemoHospitalSeeder` seeds neither, so
  every records/disclosure drive had to move to `praxis-lindenhof`.
- **No ward round, note or observation on the admitted stay** — the chart was empty. `P9-C3` and `P9-M2`
  therefore required state **created by driving the product**: a ward round started and an observation
  (128 / 76 / 84) recorded through the real forms as `ward_nurse`. Those records remain. They are
  append-only clinical facts; deleting them would be a worse act than leaving them, and they are correctly
  attributed in the ledger even though they are misattributed in the chart — which is the finding.
- **State changed and restored:** Nadia Lüthi's `portal` consent was withdrawn (to prove enforcement) and
  re-granted through the same screen — the withdrawn record remains, correctly, because consent history is
  append-only; her document was shared and then unshared back to its seeded state. Bed CH-02 was moved to
  `blocked` by driving and left there — an honestly recorded act, reversible through the product.

### CRITICAL

#### `P9-C1` — Patient identifiers leave the system in a CSV that writes no audit row at all

- **Role:** `billing` (`billing.view`) · **Route:** `GET /billing/report/export`
- **Steps:** signed in as `thomas.ammann@praxis-lindenhof.test`, requested the AR report export.
- **What happened.** The file downloaded (HTTP 200, 1 674 bytes) and contains, verbatim:

  > `top_overdue,grand_total_overdue_minor,74361`
  > `top_overdue,account_count,3`
  > `top_overdue:01m21kskmvn0d1185pfjjm3750,total_overdue_minor,31300`
  > `top_overdue:01m21kskmvn0d1185pfjjm3750,max_days_overdue,26`
  > `top_overdue:01m21kskmvn0d1185pfjjm3750,max_stage,1`

  Three patients, keyed by patient id, each with their overdue balance, days overdue, dunning stage and
  invoice count.
- **The audit table was snapshotted immediately before the drive** (`max(occurred_at) =
  2026-09-08 23:23:30.573387`, 1 196 rows) **and read immediately after.** Exactly **two** new rows exist:
  `auth.logout` (actor 3) and `auth.login` (actor 10). **The export wrote nothing.**
- **Code:** `Modules/Billing/src/Http/Controllers/BillingReportController.php:80-147` —
  `Gate::authorize('billing.view')`, then `streamDownload`. `grep -ni audit` over the whole file returns
  **no matches**. Contrast the three exports that do audit themselves:
  `PatientAccessLogController.php:105`, `GovernanceLedgerExportController.php:68-81`,
  `AccountingExportService.php:72-80`.
- **Why CRITICAL and not HIGH.** It is an **unrecorded disclosure**, this phase's own definition. The row
  is missing from *both* views: absent from the tenant's audit ledger entirely, and — having no
  `patient_id` — unable to appear in a patient's access log even if it existed. Nothing in the product can
  answer "who took a copy of our overdue-patient list, and when".
- **A Phase-3 miss, not a Phase-3 regression.** Phase 3 drove this controller (this document pins
  `BillingReportController:173` at line 1404) without checking the export's audit posture. It surfaced
  here only because the phase-9 fence forces the question "does every disclosure reach the patient's log".

#### `P9-C2` — A record release is invisible to the patient it discloses, on the screen built to show exactly that

- **Roles:** `him_records` (reader), `doctor` (releaser) · **Routes:** `POST /clinical/documents/{d}/share`,
  `GET /patients/{p}/access-log`, `GET /patients/{p}` → *Access log* tab
- **Steps, end to end in the browser.** (1) As `doctor`, released Nadia Lüthi's referral letter to her
  patient portal — HTTP 200, `{"shared_with_patient":true,"shared_at":"2026-09-08 23:20:44"}`. (2) Opened
  her Patient-360 **Access log** tab. (3) Separately, opened the dedicated PC.P5 access log for Erika
  Baumgartner, whose seeded release is recorded at 22:56:40.
- **What happened.** Neither surface shows the release. Nadia Lüthi's tab lists only `patient` reads at
  23:20 by `user3`. Erika Baumgartner's PC.P5 screen reports **"5 recorded accesses by 1 distinct actors"**
  and lists five rows — `patient_access_log`, `document_download`, `patient_access_log_export`,
  `patient_access_log`, `patient_360` — every one of them mine. Her document release is not among them.
- **Why.** `PatientAccessReport::query()` is `… WHERE tenant_id <=> ? AND action = ? AND patient_id = ?`
  with `'read'` bound (`PatientAccessReport.php:122-124`). A release is dispatched as `DocumentChanged`
  and audited by `AppServiceProvider.php:789-805` under **`action = 'document.shared'`** — a correctly
  written, patient-scoped, hash-chained row that the report's action filter excludes. Confirmed in the
  data: `2026-09-08 22:56:40.097219 | document.shared | pid=01m21kskmvn0d1185pfjjm3750`.
- **The claim it falsifies is printed on the page.** The screen states: *"Every recorded read of this
  patient's record, by every kind of actor — staff, the patient themself through the portal, automated
  agents and system processes. No actor type, surface or age of entry is filtered out unless you narrow it
  above."* and then names **one** limitation (operator mode). `PatientAccessReport`'s docblock says it more
  strongly still — *"ONE KNOWN GAP, RECORDED RATHER THAN PAPERED OVER"*. There is a second gap; it is not
  recorded; and it is the one category a subject-access request is actually about.
- **Scope.** It is not only `document.shared`. Every non-`read` action carrying a `patient_id` is
  structurally invisible: `document.uploaded`, `consent.granted`, `consent.withdrawn`, and the ADT events.
  The word `read` in that query does work the page's own text does not admit to.
- **Why CRITICAL.** The phase brief's test is verbatim *"is the release itself RECORDED — appearing in the
  patient's own access log"*. It does not appear. The disclosure IS in the ledger, so this is not data
  loss — it is a transparency failure on the **nDSG Art. 25 / GDPR Art. 15 artifact**, which the product
  hands to the patient as complete. A partial log presented as exhaustive is precisely the failure PC.P5
  exists to prevent, and its own docblock says so.

#### `P9-C3` — Every ward round, note and observation is stored as the ADMITTING CLINICIAN, not the person who did it — even when the actor has a staff profile

- **Role:** `ward_nurse` · **Routes:** `POST /hospital/admissions/{stay}/rounds`, `POST …/vitals`
- **Steps, in the browser.** Signed in as Lena Studer (`ward_nurse`; holds `note.write` and
  `encounter.manage`), opened Rolf Schmid's bedside chart, clicked **Start ward round**, then **Record
  observation** and saved 128 / 76 / 84.
- **What happened, verbatim from the screen.** The round redirected into the note editor, which shows:

  > **Version 1** · draft · **Dr. med. Martin Keller** · 2026-09-08 23:29:55

  …directly above the page's own honesty statement: *"You author this note. CareOS stores and versions
  **your** text."* Back on the chart, the round is listed as **Dr. med. Martin Keller**. Martin Keller is
  the stay's admitting clinician. He did not start the round.
- **Confirmed in the database and against the ledger.** `encounters.practitioner_id` → *Dr. med. Martin
  Keller*; `clinical_notes.author_id` → *Dr. med. Martin Keller*; `vitals.recorded_by` → *Dr. med. Martin
  Keller*. The audit rows for the same two acts are `encounter.opened` and `vital.recorded`, both
  **`actor=25` (Lena Studer)**. The system knows who acted, and stores someone else in every clinical
  column.
- **Code:** `Modules/Hospital/src/Services/BedsideChartService.php:66` —
  `$clinician = StaffProfile::query()->findOrFail($stay->admitting_clinician_id);` — passed as the
  Encounter's practitioner (`:72`) and the note's author (`:77`); `:96` passes the same profile into
  `ClinicalListService::recordVital`'s `$recorder` position. `$actor` travels alongside in all three calls
  and is used **only** for the gate and the audit row.
- **Strictly worse than `P8-C2`.** Radiology's fallback fired only when the actor had *no* linked profile.
  This substitutes **unconditionally**. Lena Studer has a profile, and
  `StaffProfile::forUser(User::find(25))` returns *Lena Studer* correctly — the right answer is one call
  away and is never asked for.
- **`AdmissionController` is not at fault.** `admitting_clinician_id` is a genuine domain field — the
  clinician responsible for the stay, legitimately not the person typing — required, resolved to a typed
  model, with no default offered on the form (`AdmissionController.php:88-98`). That is a person
  **chosen**. The defect is re-purposing a chosen clinical-responsibility field as the **attribution** of
  acts other people perform.
- **No affordance could reveal it.** `startRound` posts an empty body (`StayChart.vue:51`) and the vital
  form carries only clinical values — there is no person field and no dropdown to inspect, the same
  aggravating condition `ImagingReportController.php:161-162` names for the Radiology instance.
- **It carries an operational cost too.** Because the practitioner is always the same person,
  `EncounterService`'s one-open-encounter-per-practitioner invariant collapses a whole stay to one
  concurrent round: a second clinician is refused with *"Patient already has an open encounter with this
  practitioner"* — naming a practitioner who is neither of them. Driven; see `P9-H3`.
- **Mitigation, stated.** Signing is correct: `ClinicalNote::signed_by` records the acting user and the
  editor exposes `signed_by_is_author`, so a signed round note carries the right signer over the wrong
  author. Nothing ever corrects the author — `NoteEditorController::update` re-passes the note's existing
  `author_id` on every save (`:124-128`, `:430-433`).

### HIGH

#### `P9-H1` — A bed manager can move an OCCUPIED bed to `cleaning` and permanently wedge the admitted patient

- **Role:** `bed_manager` (`bed.manage`) · **Route:** `POST /hospital/beds/{bed}/status`
- **The transition is legal and the service never looks at the stay.** `Bed::TRANSITIONS` includes
  `STATUS_OCCUPIED => [STATUS_CLEANING]` (`Bed.php:65-70`). `BedService::setStatus` rejects only
  `occupied` as a **target** (`:80-82`), then defers to `Bed::canTransition` (`:91`). There is no `Stay`
  lookup anywhere in `BedService`.
- **The wedge.** Both `AdmissionService::transfer` (`:128`) and `::discharge` (`:169`) call
  `BedService::release`, which throws `BedStatusTransitionException` unless the bed is still `occupied`
  (`BedService.php:147-149`). Once the bed is `cleaning`, the stay can be neither discharged nor
  transferred, and there is **no product path back to `occupied`** — the only writer of that status is
  `claim`, which requires `free` and belongs to a *different* stay's admission.
- **The UI is the only guard, and the server has none.** `WardBoard.vue:99-101` maps
  `occupied: []` — the board deliberately offers no status button on an occupied bed, which is why the
  drive as `bed_manager` showed only `Mark free` / `Block` on unoccupied beds. But the endpoint validates
  `status` as `['required','string','max:40']` with **no `in:` rule** (`WardBoardController.php:128-131`),
  and `bed.manage` is all it checks. This is pattern 1 inverted: everywhere else the UI over-offers what
  the server refuses; here the UI is the refusal and the server accepts.
- **It is pinned as correct.** `tests/Feature/Hospital/WardBedManagementTest.php:114` asserts a
  `bed_manager` performing exactly this call succeeds, so the behaviour would survive a refactor.
- **NOT DRIVEN LIVE, deliberately, and this is stated rather than worked around.** Executing it would
  strand the demo tenant's only admitted patient with no product path to recover — an unrecoverable
  change to the seed made in the name of an audit. The finding is code-established across four cited
  files, all read first-hand; the browser evidence is the complementary half (the UI's own refusal to
  offer the button, driven and confirmed).

#### `P9-H2` — The ward board discloses every admitted patient and writes no read row

- **Role:** `bed_manager` · **Route:** `GET /hospital/wards`
- **What the page discloses.** Driven live: the board renders, per occupied bed, the occupant's full name,
  the ward, the bed label and the admission time — `IM-ICU-1 · ICU · Occupied · Rolf Schmid · In bed 7d
  15h`. `WardBoardController.php:76` emits `trim($stay->patient->first_name.' '.$stay->patient->last_name)`
  for every occupied bed in every active ward.
- **What it records.** Nothing. `grep -rn auditRead Modules/Hospital/src/` returns exactly four call sites
  — `AdmissionController.php:37`, `BedsideChartController.php:42`, `DischargeSummaryController.php:46`,
  `HandoverController.php:32` — and `WardBoardController::show` is not among them. Confirmed against the
  ledger: my sweep of five hospital routes produced **four** `read` rows (`stays` ×4, plus one
  `patient`/`patient_360`), all carrying `patient_id`; the ward-board GET in the same sweep produced none.
- **Why it matters.** Of the five Hospital read surfaces, the four that show ONE patient are logged and the
  one that shows EVERY patient is not. A staff member can enumerate the whole inpatient census — who is in
  the building, in which ward, in which bed, since when — and leave no trace in any patient's access log.
  It is the same completeness hole as `P9-C2` approached from the other side: there the disclosure is
  recorded but filtered out; here it is never recorded.
- The patient index (`PatientIndexController`) is also unlogged and returns names + MRNs; that is at least
  arguable for a search surface. A per-bed occupancy disclosure is not a search.

#### `P9-H3` — Eleven refusal sites, zero renderers: no Hospital page shows a refusal

- **Role:** `ward_nurse` (any) · **Route:** `POST /hospital/admissions/{stay}/rounds`
- **Driven live.** With a round already open, clicked **Start ward round** a second time. The page did not
  navigate, the round list did not change, and **nothing at all appeared on screen** — no banner, no
  inline message, no toast. Network shows the POST returning `302` back to the chart, which then
  re-rendered.
- **The server did send a refusal.** Repeating the same POST with the real Inertia headers returns HTTP
  200, `component: "Hospital/StayChart"`, and:

  > `"errors": { "round": "Patient already has an open encounter with this practitioner." }`

  The message is produced, shared to the page, and silently discarded by the component.
- **Count:** 11 `->withErrors()` sites across the six Hospital controllers — `AdmissionController.php:100`,
  `:123`, `:145`; `BedBillingController.php:33`; `BedsideChartController.php:122`, `:150`, `:174`;
  `DischargeSummaryController.php:129`, `:146`; `HandoverController.php:86`;
  `WardBoardController.php:138`. On the Vue side, none of the five Hospital pages reads
  `page.props.errors`, imports `RefusalNotice`, or passes an `onError` handler, and `AppLayout` renders no
  error surface either.
- **This is D-170 / `P6-C3` / `P8-H1` again, ninth phase.** `RefusalNotice.vue` exists and its own docblock
  describes the identical prior finding; ED, Radiology and Surgery pages adopted it. Hospital did not.
- **Aggravating.** The one message a user would actually hit names the wrong person, because of `P9-C3`:
  *"…with this practitioner"* refers to the admitting clinician, not to either clinician involved. Showing
  it unchanged would replace an invisible refusal with a misleading one.

#### `P9-H4` — `document.view` gates nothing; clinical documents are gated by `patient.view`

- **Role:** `him_records` · **Route:** `GET /clinical/documents/{d}`
- **Driven live:** as `him_records`, downloading Erika Baumgartner's lab report returns **200**, and the
  download appears in her access log as `document_download` (correctly — that path *is* audited).
- **But not because of `document.view`.** `DocumentDownloadController` is a 27-line controller whose only
  gate is `Gate::authorize('patient.view')` (`:14`). A whole-tree grep for `document.view` returns four
  hits and not one is a gate: its catalog definition (`RbacProvisioner.php:69`), two role templates
  (`:107` org_admin, `:205` him_records), and an operator-grant PHI mapping
  (`OperatorAccessService.php:73`).
- **Consequence.** The permission whose written description is *"View and download patient clinical
  documents (HIM/records)"* controls nothing. Every `patient.view` holder — **25 of the 26 role
  templates**, every role but `billing`, including `bed_manager`, `phlebotomist`, `radiographer`,
  `surgical_scheduler` and `admissions_clerk` — can already download any patient's clinical documents.
  There is no narrower door for record access than "can see patients", and `him_records` gains nothing at
  all from the permission that names it.
- This is pattern 4 (a granted capability with no surface) in its purest form yet: not a capability
  without a screen, but a **permission without a gate**.

#### `P9-H5` — The records role cannot do records work: it is refused the only release action and cannot record consent

- **Role:** `him_records` · **Routes:** `POST /clinical/documents/{d}/share`, `POST /patients/{p}/consents`
- **Driven live, both refused.**

  > `share` → **403** `{"message":"This user cannot manage clinical documents."}`
  > `consents` → **403** `{"message":"This action is unauthorized."}`

- **Why.** Releasing a document requires `note.write` (`DocumentService::authorizeWrite`, `:216-220`);
  capturing or withdrawing a consent requires `patient.edit` (`PatientConsentController:16`, `:37`).
  `him_records` holds neither. Every doctor and every nurse holds `note.write`; reception, admissions and
  the hospitalist hold `patient.edit`.
- **The inversion.** The role built to handle records requests can **take data out** — download any
  document, export a patient's whole access log as CSV — but cannot perform the one governed release the
  product implements, and cannot record the consent that would authorise it. Authority to release sits
  with clinical authors; authority to consent sits with front-desk and clinicians; the records role has
  neither.
- **There is also nowhere to do it from.** The Patient 360 tabs are Demographics / Contacts / Coverages /
  Consents / Access log — **there is no Documents tab**. `resources/js/pages/` contains no staff-facing
  document page at all (only `Portal/Documents.vue`, which is the *patient's* view). Upload, download,
  share, unshare, reclassify and delete are JSON/route-only, reachable by typing a URL.

#### `P9-H6` — The nightly bed-day accrual credits an arbitrary org_admin, bypassing the resolver written to stop exactly this

- **Surface:** `hospital:accrue-bed-days`, scheduled 05:30 daily across every active tenant
  (`routes/console.php:65-68`)
- **Code:** `AccrueBedDaysCommand::resolveBillingActor` (`:59-69`) is
  `RoleAssignment::query()->where('role_id', $roleId)->value('user_id')` — **no `ORDER BY`**, no check
  that the user genuinely holds `billing.manage`, and no exclusion of a branch-scoped assignment
  (`role_user.branch_id` is nullable). Its own docblock claims the charges are *"Attributed to the
  tenant's billing-capable admin"*, which the query does not verify.
- **It persists.** `ChargeCaptureService` writes `'created_by' => $actor->id` on every bed-day charge and
  audits the capture under that actor. The person credited with a tenant's inpatient revenue is whichever
  row the engine happens to return.
- **The canonical fix already exists and Hospital is the only scheduled command that skips it.**
  `SystemActorResolver::forPermission()` is documented as deterministic (`orderBy('id')`), verifies the
  permission is held tenant-wide via `PermissionService`, excludes super-admins, and **returns null so the
  caller skips the tenant rather than guessing** — the D-195 posture. `DunningRunCommand:54`,
  `ReconcileCommand:61` and `ReportingSummaryCommand:45` all use it; nothing in `Modules/Hospital` does.
- This is `P9-C3`'s server-side twin: the same "resolve a person by convenience" shape, unattended,
  nightly, and cross-tenant.

### MEDIUM

#### `P9-M1` — The admission page prints raw ISO-8601, and two Hospital pages disagree about when the same event happened

- **Driven live as `bed_manager`.** `/hospital/admissions/{stay}` renders, on screen:

  > **ADMITTED** `2026-09-01T08:00:00+00:00`
  > Bed journey · Admitted to bed `2026-09-08T22:57:28+00:00` · Transferred `2026-09-08T22:57:28+00:00`

  `Admission.vue` interpolates the server string directly (`:117`, `:121`, `:145`) and imports no date
  helper at all. This is not a timezone nuance — it is a machine string on the inpatient record's primary
  page.
- **And the same event reads differently elsewhere.** The discharge-summary page renders that identical
  bed-journey entry as **`Admitted to bed · Sep 8, 2026, 3:57 PM`** — a different format *and* a different
  clock (browser zone vs. the raw `+00:00`), on two pages about one stay, in one browser session.
- **Root cause, standing:** not one of the five Hospital pages passes `timeZone` to any formatter, and a
  repo-wide grep for `timeZone` across `resources/js` returns zero hits. The tenant's display zone *is*
  already shared to every page as the `timezone` Inertia prop
  (`HandleInertiaRequests.php:65-74`) and no page consumes it — **D-192 states this in its own text** as
  the standing deferred item. Hospital is confirmed inside its blast radius; the ISO-8601 half is new.
- Three of the four Hospital formatters also omit the year entirely (`Handover.vue:36`,
  `StayChart.vue:44`, `WardBoard.vue:90` use `{day, month, hour, minute}`), on append-only, long-lived
  records. Driven: the round I started renders as `Sep 08, 04:29 PM`.

#### `P9-M2` — Observations render as bare chips: no timestamp, no unit, no direction

- **Driven live.** After recording 128 / 76 / 84 as `ward_nurse`, the chart shows:

  > `SYSTOLIC 128` · `DIASTOLIC 76` · `HEART RATE 84`

  and nothing else — no time, no unit, no axis, no hover title.
- **The data is already in the payload.** `BedsideChartService` orders vitals `orderByDesc('recorded_at')`
  and emits `recorded_at` per point; `StayChart.vue:17` declares it in the `VitalPoint` type. The template
  (`:118-124`) renders only `{{ point.value }}`.
- **D-191:** a reverse-chronological clinical sequence with no written meaning. With more than one reading
  the viewer cannot tell whether the leftmost value is the newest or the oldest, nor when any of them was
  taken — on the surface a nurse uses to judge a trend.

#### `P9-M3` — The discharge summary prints an amount with no currency, through a module-local formatter

- `DischargeSummary.vue:77-79` defines `fmtAmount(minor)` as
  `(minor / 100).toLocaleString(locale.value, {minimumFractionDigits: 2, maximumFractionDigits: 2})` and
  renders it bare at `:225`. `formatSwissMoney` / `formatSwissAmount` are imported by no Hospital page.
- `money.ts:7-9` states the Swiss grouping is done by hand *"so the separator is a deterministic straight
  apostrophe regardless of the runtime's ICU data"*. This page reintroduces exactly the ICU variance the
  helper was written to remove: the same invoice renders `4'820.00`, `4,820.00` or `4.820,00` depending on
  the viewer's browser.
- **Worse than Phase 8's instance:** the figure carries **no currency at all**.
  `DischargeSummaryController.php:94-100` emits `total_minor` and `issue_date` and never emits `currency`,
  while `Invoice` carries a `currency` column whose fallback is `'EUR'`. The episode close-out states an
  amount with no unit. This is pattern 8 (the module-local formatter), ninth phase.

#### `P9-M4` — The date-only day-shift D-091 exists to prevent, in a page written after the fix

- `DischargeSummary.vue:73-75`: `fmtDay(iso)` does `new Date(iso)` on the invoice `issue_date`, and
  `DischargeSummaryController.php:100` emits that field as `toDateString()` — a bare `YYYY-MM-DD`.
- `resources/js/lib/date.ts` exists precisely for this; its docblock names the failure
  (*"`new Date("1954-03-12")` parses as UTC midnight and re-renders in the local zone"*), and
  `formatDateOnly` is imported by no Hospital page. A behind-UTC viewer sees the stay's invoice dated one
  day earlier than it was issued — the M-2 / D-091 class, re-created.

#### `P9-M5` — Bed management has no nav entry at any width, and its two clinical surfaces have no click path from anywhere

- **Driven live.** `bed_manager`'s navigation is Dashboard · Patients · Reporting. `him_records`'s is
  Dashboard · Patients · Admin. Neither can reach the ward board by clicking; both landings offer nothing
  in the body (`landingBodyLinks: {}` — the QA-FIX.7d gating, working as intended).
- `grep -rn "/hospital" resources/js` returns **zero hits across the entire frontend**. `primaryNav` and
  `adminNav` (`AppLayout.vue:35-57`) contain no Hospital item, and `NAV_PERMISSIONS`
  (`HandleInertiaRequests.php:24-46`) contains none of `ward.manage`, `bed.manage`, `admission.manage`,
  `document.view` or `note.supervise`.
- **Inside the module it is no better.** The whole Hospital folder contains two `<Link>`s — Admission →
  discharge summary, and StayChart → the note editor. Nothing links to `/hospital/admissions/{stay}/chart`
  or `…/handover`. The ward board is *sent* `occupant.show_url` by its controller
  (`WardBoardController.php:78`), declares it in its type (`WardBoard.vue:21`) and **never renders it** —
  the board cannot open the occupant's stay. Confirmed live: the occupied tile shows a name and a length
  of stay and no link.
- **The cost is now total, not partial.** This is D-214's under-offer half, ninth phase, and `bed_manager`
  is the first role whose *entire* remit is URL-only: the ward board is the sole routed surface exercising
  `bed.manage`, and it is unreachable by navigation.

#### `P9-M6` — Two `ward.manage` capabilities and two `bed.manage` capabilities have no HTTP surface at all

- `WardService::create` / `rename` / `deactivate` are the **only** `ward.manage` gates in the codebase, and
  `WardService` is consumed by exactly one caller — `WardBoardController::show`, which uses `activeWards()`
  only. There is no route, controller or command that creates, renames or deactivates a ward.
- `BedService::create` and `::deactivate` are likewise routeless; the only routed `bed.manage` action is
  `setStatus`.
- So of `bed_manager`'s four permissions, one (`ward.manage`) is entirely unreachable, one (`bed.manage`)
  is reachable for one of its three operations, and the ward/bed estate can only be created by a seeder.
  Pattern 4, ninth phase, in the "capability with no surface" direction.

#### `P9-M7` — Nothing binds bed occupancy to a stay, and the board silently hides the second patient

- `AdmissionService` guards one active stay per **patient** (`:60-68`, patient row lock), never per bed.
  `stays.current_bed_id` has a foreign key and **no unique constraint** and no trigger
  (`2026_07_26_000003_create_stays_table.php:22-48`). `BedService::claim` requires only that the bed is
  `free`.
- Reachable through the product by chaining `P9-H1`: occupied → cleaning → free (both legal for
  `bed.manage`), then admit a second patient into the same bed.
- The board then hides one of them: `WardBoardController.php:52-56` builds occupancy as
  `Stay::…->get()->keyBy('current_bed_id')`, and `keyBy` keeps the last row — the other patient disappears
  from the ward board entirely.

#### `P9-M8` — "Invoice this stay" is four independently committed transactions; a failure at the last step leaves an orphan draft and a retry builds a second

- `BedBillingService::invoiceStay` (`:158-196`) has **no enclosing transaction**. It calls, in order:
  `accrueBedDays` (which commits per day), `validateForPatientPeriod` (own transaction),
  `createDraftFromCharges` (own transaction), `issue` (own transaction).
- `charge.invoice_id` is set only inside `issue`. An `issue` failure rolls back only `issue`: the draft
  `Invoice` and its lines stay committed while the charges revert to `validated` / `invoice_id NULL`. The
  re-gather at `:172-179` filters on exactly that state, and `:185` calls `createDraftFromCharges`
  unconditionally — there is no lookup for an existing draft. A retry produces a second draft over the
  same charges.
- This is the D-199 shape (one operation, one transaction) that QA-FIX.8c closed in Lab, Radiology and ED.
  Hospital is the remaining instance, and it is a money path.

#### `P9-M9` — "Invoice this stay" 500s on any tenant that has not run the demo seeder

- `BedBillingController.php:32` catches only `AdmissionException|InvalidArgumentException`. The reachable
  failure is `TariffNotFoundForDateException`, which `extends RuntimeException` — outside that catch.
  Path: controller → `invoiceStay` → `accrueBedDays` → `captureManual` → `ChargeCaptureService` →
  `TariffResolver:49`.
- The `BED-DAY-*` tariff items come only from `BedBillingService::seedStarter` (`:81`), which has **no
  route and no console command** — `HospitalServiceProvider` registers only `AttemptBedClaimCommand` and
  `AccrueBedDaysCommand`, and the only callers are `DemoHospitalSeeder:324` and tests. Dental, Lab and
  Nursing all expose their `seedStarter` through a controller; Hospital does not.
- So a real first customer reaches an unhandled 500 on the module's only money action, with no surface
  anywhere to author the tariffs that would prevent it.

#### `P9-M10` — The nightly accrual has no error handling and leaks tenant context on failure

- `AccrueBedDaysCommand:42-44` calls `accrueBedDays` bare inside a nested tenant/stay loop; the
  tenant-context restore sits at `:47-51`, after the loop and **not in a `finally`**. Throwing paths inside
  include three `findOrFail` calls and the tariff resolution above.
- One bad stay therefore aborts the entire cross-tenant nightly sweep — every later tenant is skipped
  silently — and leaves `TenantContext` pinned to the failing tenant. The sibling command in the same
  directory (`AttemptBedClaimCommand:62-68`) restores the previous tenant in a `finally`.

#### `P9-M11` — `Stay`'s declared state machine is dead code

- `Stay::TRANSITIONS` (`:60-63`) and `Stay::canTransition` (`:132-135`) have **zero call sites**.
  `AdmissionService` instead asserts `status !== STATUS_ADMITTED` inline at `:114` and `:164`.
- Every peer module wires its own: `Bed::canTransition` is called at `BedService.php:91`, and
  `EdVisitService:93`, `SpecimenService:83`, `MedicationOrderService:94`, `ImagingStudyService:101` and
  `SurgicalCaseService:73` do the same. Hospital's stay is the one declared-but-unenforced machine — a
  written rule the code does not consult, which is the D-176 shape applied to logic rather than to UI.

### LOW

#### `P9-L1` — No navigation below 768 px, ninth phase — and newly load-bearing

- **Driven live at 390 × 844 on the ward board:** all three nav links (`/app`, `/patients`, `/reporting`)
  measure **0 × 0**; the only interactive chrome left is Search, Notifications and Sign out. There is no
  drawer and no hamburger anywhere in the 228-line `AppLayout` (`:113`,
  `hidden … md:flex`, with no `md:hidden` counterpart).
- The Hospital pages themselves degrade correctly — mobile-first with `sm:`/`lg:`/`xl:` only, no tables,
  no `overflow-*`, and no horizontal overflow at 390 px (measured).
- **Why it matters more here.** The bedside chart and the shift handover are the two surfaces a nurse uses
  on a phone at the bedside, and they have no nav entry at any width (`P9-M5`) *and* no link from any
  other page. On a phone there is neither navigation nor a path.

#### `P9-L2` — The portal consent screen advertises three scopes nothing seeds, checks or enforces

- `Portal/Consents.vue:33-39` maps five scope keys to labels: `portal.access`, `comms.email`,
  `documents.read`, `messages.write` and **`research.share`**.
- Only two exist anywhere in PHP. A whole-tree grep for `documents.read`, `messages.write` and
  `research.share` returns **no matches** outside that map — no template seeds them, no
  `consents->has(...)` call site names them.
- `research.share` is, on its face, a third-party disclosure consent. The vocabulary promises a control the
  product has never implemented. It renders only if a template with that scope existed, so it is inert
  today — the D-176 shape (unbacked presence), recorded rather than treated as a feature.

#### `P9-L3` — One report, two renderings: the Patient-360 tab shows raw actor ids where the PC.P5 screen shows names

- **Driven live, same patient, minutes apart.** The dedicated access log renders
  `Nadia Steiner · read patient · Staff user · patient_access_log · 11:17 PM`. The Patient-360 *Access log*
  tab renders `P patient01m21ksnkypkt0a0fvf0dwf0vm · patient · 22:56` and `U user3 · patient · 23:20`.
- `PatientAccessReport` is deliberately the single query behind both, so the *rows* agree — but only one
  surface resolves actor ids to names. The 360 tab, which any `patient.view` user can open, is the less
  legible of the two, and it is the one a clinician actually meets.

#### `P9-L4` — `BreakGlassService` has no production consumer, and the governance dashboard lists an action nothing emits

- `app/Services/BreakGlassService.php` is the one component that demands a written reason before access
  (`request(User, string $scope, string $reason, int $ttlSeconds)`), and it audits
  `break_glass.request`. Outside its own file it is referenced only by two docblocks and three tests. No
  route, no middleware and no Gate consults a grant.
- `GovernanceDashboardController.php:88` lists `break_glass.granted` among the surfaced security actions —
  an action nothing currently writes (D-179: an asserted action never taken).
- **It matters to this phase's fence.** No read anywhere in the product records *why* it happened. The
  reason-capture discipline exists and is tested; it is simply not wired into any access decision — which
  is exactly the field an accountable-disclosure log would need.

#### `P9-L5` — A bed's status has no provenance anywhere in the product

- The bed transition I drove is fully recorded: `bed.status_changed`, `actor=28` (Urs Baumann),
  `{"from_status":"free","to_status":"blocked"}`, hash-chained, timestamped within 15 s of the CLI clock.
  There is no `bed_events` table and none is needed — the audit chain is the record.
- **But no surface can reach it.** The board tile payload (`WardBoardController.php:68-83`) carries no
  last-changed-by and no timestamp. The only `audit.view` screen selects
  `['id','occurred_at','action','actor_type','resource_type']` (`GovernanceDashboardController.php:279`) —
  **no `resource_id` and no `actor_id`** — and shows the last 15 tenant events. The patient access log is
  `action = 'read'` only. And `bed_manager` does not hold `audit.view` at all.
- So "who blocked bed IM-ISO-1, and when?" — the bed manager's most ordinary question about their own
  ward — is answerable only by a DBA. The data is honest; the product cannot show that it is.

### The phase's fence — DISCLOSURE, answered question by question

**1. Does a records-release surface exist at all?** **No — and that is the finding.** There is no model,
migration, service, controller, route or page whose subject is releasing a record. Every occurrence of
"disclosure" in PHP is a docblock describing an existing *read* as a disclosure. Greps for
`release of information`, `records request`, `roi_request` and `record_request` return only the nDSG/GDPR
**subject-access** comments. The `him_records` role's nominal job has no implementation; the concept is
absent, not partial.

**2. Who may release, and to whom?** The only release the product implements is
`DocumentService::shareWithPatient` — a document made visible in the **patient's own portal**. Its holder
is `note.write`: every doctor and every nurse. `him_records` is refused it (`P9-H5`, driven). There is no
third-party recipient anywhere: `audit_events` has no recipient, purpose or legal-basis column, so even a
correctly audited release could only ever record *"staff member X touched resource Y"*, never *"a copy of
the chart went to insurer Z on basis W"*. The schema, not just the UI, would have to grow.

**3. Is consent required, and ENFORCED rather than prompted?** **Enforced — proven live, in both
directions.** With Nadia Lüthi's `portal` consent granted, the release succeeded (200). I then withdrew
that consent **through the product** (Patient 360 → Consents → reason → Withdraw; the record flipped to
`withdrawn`), retried the identical POST, and got:

> **403** `{"message":"Portal access consent is required to share documents."}`

That is `DocumentService.php:105` throwing, not a prompt. It is one of four hard consent gates —
`shareWithPatient`, `TelehealthService:110`, `ThreadService:408`, `NotificationService:177`. Two further
call sites are display-only flags (`RecallWorklistController:131`, `InboxPatientContextReader:195`), and
in both cases the actual send is enforced downstream by `NotificationService`. **Consent is the one part
of this fence that is genuinely built.**

**Two qualifications, both checked.** (a) Withdrawal does **not** retract an existing release — the
`shared_with_patient` flag stays `true` (verified: it was still shared after the withdrawal, until I
unshared it explicitly). That is not exploitable, because `EnsurePortalConsent` guards the entire portal
route group (`routes/web.php:917`) and fail-closes on `portal.access`, so the patient cannot reach any
document at all; the stale flag is an inconsistency, not an exposure. Recorded as an observation, not a
finding. (b) No staff-side read or export checks consent anywhere: the document download, the access-log
CSV, the governance ZIP and the billing CSV all check permissions only. There is no legal-basis check
standing between a permitted user and a file.

**4. Is a release RECORDED — does it appear in the patient's access log?** **No.** `P9-C2`, driven twice.
The row exists in the ledger and is excluded from the patient-facing report by the `action = 'read'`
filter, on a screen that tells the patient its only gap is operator mode. And the one export that can
carry patient identifiers out of the building writes **no row at all** (`P9-C1`).

**5. What IS honest here.** Three things, stated because they are the reason the fence holds as well as it
does. The access log is a **single query** shared by screen, tab and export, so the CSV cannot disagree
with what was on screen. Viewing and exporting it are themselves audited as patient-scoped reads
(`:54`, `:105`) and show up in the log next time. And the export fence is drawn correctly:
`audit.export` is deliberately narrower than `audit.view` and `him_records` sits on the read-only side —
its only file-producing capability is the per-patient access-log CSV, which audits itself. `P9-C1` is the
hole in a wall that is otherwise built.

### Bed state honesty — answered

**It passes, and it is the strongest single result in this phase.**

- **Driven live:** blocked bed CH-02 as `bed_manager`. One audit row appeared — `bed.status_changed`,
  `actor=28` (Urs Baumann), context `{"from_status":"free","to_status":"blocked", …}` — hash-chained,
  timestamped within 15 s of the CLI clock. Nothing else changed.
- `bed.status` is written in exactly **three** places, all inside `BedService`, each under a
  `SELECT … FOR UPDATE` row lock that re-reads the authoritative current status. No factory, seeder,
  controller or command writes the column directly — `DemoHospitalSeeder:306-309` drives `claim` /
  `release` / `setStatus` like everyone else.
- **`free → occupied` is structurally impossible by hand.** `setStatus` rejects `occupied` as a target
  outright, so occupancy is only reachable through the concurrency-safe `claim()`, which re-asserts `free`
  under the lock. Occupancy always corresponds to exactly one winning claim.
- **`cleaning → free` is always a recorded human act.** Nothing auto-frees a bed: no timer, no scheduled
  command, no side effect. The only scheduled Hospital command accrues bed-days and never touches status.
- **Admit / transfer / discharge are atomic and tested.** One outer `DB::transaction` per action, the
  append-only `StayEvent` written inside it, transfer claims the new bed before releasing the old, and
  `HospitalAdmissionTest:170`/`:191-197` pin that a forced failure leaves no orphan stay, no stuck bed and
  no phantom audit row.
- **What it cannot do is explain itself** (`P9-L5`), and its one status write can wedge a patient
  (`P9-H1`). The record is honest; the product cannot show it, and the guard on who may write it is
  incomplete.

**QA-FIX.7a's bed default — verified, and it holds.** D-211 (this document, lines 4570-4574) recorded the
admit form's bed selector as `bed_id: ''` with `required` and an explicit `selectBed` placeholder. Re-read
in `WardBoard.vue:57`, `:105`, `:191-197`: unchanged, no pre-selection, and `resetForm` returns every
field to `''`. Phase 8's dropdown-default instance does not recur anywhere in Hospital — a repo-wide grep
for `[0]?.id` / `props.x[0]` across the five pages returns nothing.

### The standing patterns, ninth phase

**1. Ungated UI — THE OVER-OFFER STAYS CLOSED; THE UNDER-OFFER IS NOW TOTAL.** Second consecutive phase
with the over-offer closed: both roles' landings render an empty body (`landingBodyLinks: {}`), and
`bed_manager`'s ADT writes are refused server-side (403 on transfer, discharge, admit, handover, vitals,
discharge-summary, invoice — all driven). The under-offer half, deliberately not taken by QA-FIX.7d
(D-214), reaches its limit here: `grep -rn "/hospital" resources/js` returns **zero hits**, so
`bed_manager` — a role whose entire remit is one screen — has no path to that screen from anywhere in the
product (`P9-M5`). And `P9-H1` finds the inverse shape for the first time: a screen that correctly refuses
to offer an action, over a server that accepts it.

**2. Timestamp and locale divergence — PRESENT, ninth phase, and the worst instance yet.** Not a
divergence but an absence: the admission page prints `2026-09-01T08:00:00+00:00` on screen (`P9-M1`).
Alongside it, the same bed-journey event renders as `Sep 8, 2026, 3:57 PM` on the discharge summary — two
pages, one stay, one session, two clocks and two formats. Plus a re-created D-091 date-only day-shift
(`P9-M4`) in a page written after that fix landed.

**3. No navigation below 768 px — PRESENT, ninth phase** (`P9-L1`): 3 of 3 links at 0 × 0, no drawer.
Newly load-bearing because the bedside chart and handover are phone surfaces that also have no nav entry
at any width and no link from any page.

**4. A granted capability with no surface — PRESENT, ninth phase, in its purest form yet.** Three
distinct shapes at once: a **permission with no gate** (`document.view`, `P9-H4`); **service methods with
no route** (`ward.manage` entirely, two thirds of `bed.manage`, `P9-M6`); and a **service with no
consumer** (`UnsignedNotesWorklist`, the sole `note.supervise` check, has no route and no caller — so
`him_records`'s second distinguishing permission is as inert as its first).

**5. The fences hold — CONFIRMED IN ALL NINE PHASES.** D-169: the ward board's only colour mapping is
keyed to the four housekeeping states, the fence is stated in the page header and mirrored server-side,
and the observations I recorded render as identical unstyled chips regardless of value — no bands, no
flags, no arrows, no early-warning score. D-170: no invented workflow — the referral, the one third-party-
facing artifact, deliberately transmits nothing and says so. The bed board carries no acuity field at all.
Nine for nine.

**6. A partial record — PRESENT IN BOTH DIRECTIONS.** *Refused:* `P9-H3`, 11 `withErrors` sites and zero
readers, driven live — the server produced `"Patient already has an open encounter with this
practitioner"` and the screen showed nothing. *Succeeded:* `P9-M8`, `invoiceStay` as four independently
committed transactions leaving an orphan draft and duplicating on retry — the D-199 shape QA-FIX.8c closed
in three modules and not in this one.

**7. Resolving a person by convenience — PRESENT, ninth phase, and this is the most severe instance the
audit has found.** `P9-C3`: unconditional substitution of the admitting clinician for the acting user
across three columns, proven end-to-end in the browser and against the ledger, with the correct answer
(`StaffProfile::forUser`) one call away and never asked for. `P9-H6` is its unattended twin, nightly and
cross-tenant. Phase 8 called this pattern "resolving a person by convenience"; Phase 9 shows it is not a
fallback-only defect — it can be the primary path.

**8. The module-local formatter — PRESENT, second phase** (`P9-M3`): one `fmtAmount`, one `fmtDate`, one
`fmtDay`, one `fmt`, none of them the shared helper, and the money one drops the currency entirely.

### What was NOT tested, stated rather than implied

- **`P9-H1` was not driven live.** Executing it would strand the demo tenant's only admitted patient with
  no product path to recover. Code-established across `Bed.php`, `BedService.php`,
  `WardBoardController.php` and `WardBedManagementTest.php`, all read first-hand; the browser half (the
  board correctly not offering the button) *was* driven.
- **`P9-M7` was not driven** for the same reason — it requires `P9-H1` first.
- **`P9-M8`, `P9-M9`, `P9-M10` are code findings.** `klinik-bergblick`'s admitted stay has no invoice and
  the demo seeder does seed the bed-day tariffs, so the failure paths are not reachable on seed data
  without removing them.
- **`him_records`'s `note.supervise` surface could not be driven** — `UnsignedNotesWorklist` has no route.
- **`document.view` could not be driven** — it gates nothing to drive.
- **The AR export's contents are patient ULIDs, not names or MRNs.** Within the tenant a ULID identifies
  the patient uniquely and joins to the record, which is why `P9-C1` is graded as a disclosure; the file
  does not itself print a name.
- **Performance is out of scope**, deferred to staging per the phase brief.
