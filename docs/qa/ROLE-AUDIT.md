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
| 3 — Billing / finance | 1 | 4 | 8 | 2 | 15 |
| **Total to date** | **3** | **11** | **25** | **13** | **52** |

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

## Cross-phase patterns

Three phases are complete across three unrelated role groups — front-desk, clinical and financial.
**A pattern that appears in all three is a systemic defect, not a local one**, and four now do.

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

### Still open as a candidate

**Identity references are inconsistent at the schema level** (`P2-C1` sub-finding, recorded as
D-196). Phase 3 found no third namespace on the money tables — `payments.recorded_by`,
`payment_allocations.allocated_by` and `invoice_adjustments.created_by` are all `users.id`, matching
`signed_by` / `charted_by` / `ordered_by`. `clinical_notes.author_id` (→ `staff_profiles.id`) remains
the sole outlier, so this is still one instance rather than a pattern.
