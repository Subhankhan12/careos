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
| 3 | Billing (`billing`) | ⏳ planned |
| 4 | Nursing / Spitex (`coordinator`, field nurse + Nurse PWA) | ⏳ planned |
| 5 | Pharmacy (`pharmacist`, `pharmacy_technician`) | ⏳ planned |
| 6 | Surgery / OR (`surgeon`, `anesthetist`, `scrub_nurse`, `surgical_scheduler`) | ⏳ planned |
| 7 | ED (`ed_physician`, `triage_nurse`, `ed_charge_nurse`) | ⏳ planned |
| 8 | Lab + Radiology (`lab_tech`, `pathologist`, `radiographer`, `radiologist`) | ⏳ planned |
| 9 | Bed / records (`bed_manager`, `him_records`) | ⏳ planned |
| 10 | Admin / governance (`org_admin`) + patient portal | ⏳ planned |

## Severity summary (running)

| Phase | CRITICAL | HIGH | MEDIUM | LOW | Total |
|---|---|---|---|---|---|
| 1 — Reception / front-desk | 1 | 3 | 8 | 6 | 18 |
| 2 — Clinician (doctor / dentist) | 1 | 4 | 9 | 5 | 19 |
| **Total to date** | **2** | **7** | **17** | **11** | **37** |

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
| `P2-C1` | CRITICAL | ✅ **FIXED** | QA-FIX.2a | `<pending>` |
| `P2-H1` | HIGH | ⏳ in the same gate (Part 2) | QA-FIX.2b | — |
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

> ✅ **FIXED — QA-FIX.2a, commit `<pending>` (D-195, D-196, D-197).** The cause was a single argument:
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

## Cross-phase patterns

Two phases are now complete, so this section carries real weight. Each pattern below is stated with
the finding IDs it generalises, and with what the second phase *added* to the Phase 1 candidate.

### 1. Ungated UI controls in front of correctly-gated servers — **CONFIRMED, and worse**

`P1-H1` · `P1-M2` · `P1-L5` → `P2-H2` · `P2-M1`

Phase 1 flagged this as a candidate. Phase 2 confirms it and finds the extreme case: for
`ed_physician`, **every one of the four links on the landing page returns 403**, and for `doctor` and
the dentist two of the three quick actions do. The shape is consistent across both phases — **the
server is right and the page is wrong**. Phase 2 also isolates *where* the split lives: the top nav
**is** permission-aware (it correctly hides Scheduling, Dental and Approvals from roles that lack
them), while hero CTAs, dashboard cards and quick-action lists are rendered unconditionally. A fix
that teaches the page body what the nav already knows would close this class in both phases at once.

### 2. Timestamp and locale rendering divergence between sibling screens — **CONFIRMED, and now clinical**

`P1-C1` · `P1-M3` · `P1-L2` → `P2-H3` · `P2-M3` · `P2-L4`

Phase 1 saw the inbox print raw UTC while appointment history printed local, and three date formats
across reception surfaces. Phase 2 shows the divergence is not two screens but **two mechanisms**,
now reaching the clinical record: some components print the stored UTC verbatim, others hand the
instant to a browser locale API that resolves to **the viewer's own machine zone**. At one instant
the note editor showed `17:03:53` (UTC), `10:04 AM` (viewer's zone) and *should* have shown `19:03`
(the practice's). Date formats went from three to **four**, including US `M/D/YYYY` in a de-CH
tenant — and the perio screen prints the same date in two formats at once. `QA-FIX.1a` did not cause
this; by making storage honest it **removed the accident that was hiding it**. The remedy is the one
`D-192` already names: a display boundary, applied at every clinical surface rather than at the
Inertia prop alone.

### 3. A recorded status asserted without the event that earns it — **NEW, spans two phases**

`P1-M1` → `P2-H1`

Phase 1 found the day-board setting `status = arrived` while `checked_in_at` stayed NULL. Phase 2
finds the same pair produced from a completely different entry point and role: a clinician clicking
**"Document"** to write a note silently fires `confirmed → arrived → in_progress`, again leaving
`checked_in_at` and `check_in_source` NULL. Two roles, two surfaces, one defect shape — attendance is
inferred from an unrelated action. This is the clearest **D-179** pattern in the audit so far, and
its blast radius is reporting and billing, not just the board.

### 4. A granted permission with no surface to exercise it — **NEW**

`P1-H1` → `P2-H4` · `P2-M2` · `P2-M6`

Phase 1's `P1-H1` was the inverse (a surface offered to a role lacking the permission). Phase 2 finds
the mirror image and it is more common: `doctor` holds `note.write`, `medication.prescribe`,
`patient.edit` and `encounter.manage`, and **the clinical chart exposes an affordance for none of
them**; five of seven physician roles lack `medication.prescribe` in a product that has no
prescribing screen at all; the dentist is refused the fee schedule whose prices they are shown.
Permission templates and UI affordances are drifting apart in **both** directions, which suggests
they are maintained independently and never reconciled.

### 5. The fences hold — **CONFIRMED across both phases**

`P1` positives → the Phase 2 "guards verified holding" section

Worth recording as a pattern in its own right: across two role groups and every clinical surface in
this phase, **no computed clinical judgment was found**, vitals stayed raw, `D-169` held under
positive control **twice** (severe vs mild allergy; 6 mm vs 2 mm pocket, both byte-identical),
`D-172` held with **no drawing layer present at all** (0 `<canvas>`, 0 `<svg>`), the note editor's
agent boundary held, a signed note was not editable in place, and forged POSTs were refused with
clear 403s. The programme's hard rules are not eroding — the defects this audit is finding are in
**presentation, navigation and attribution**, not in the fences.

### Still open as a candidate

**Identity references are inconsistent at the schema level** (`P2-C1` sub-finding): `author_id` →
`staff_profiles.id`, `signed_by` / `charted_by` / `ordered_by` → `users.id`. One phase is not enough
to call this a pattern; if a later phase finds a third namespace or another mis-attribution, it
becomes one.
