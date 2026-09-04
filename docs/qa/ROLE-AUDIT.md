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
| 2 | Clinical (`doctor`, `nurse`, `hospitalist`, `ward_nurse`, `charge_nurse`) | ⏳ planned |
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
| **Total to date** | **1** | **3** | **8** | **6** | **18** |

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

## Cross-phase patterns

*Empty — this section fills as phases accumulate.* Once two or more phases are complete, recurring
themes (a guard that fails the same way across roles, a component reused with the same defect, a
permission template shaped wrongly in the same direction) are recorded here with the finding IDs
they generalise. Candidates already visible from Phase 1, to be confirmed or dropped against later
evidence: **ungated UI controls in front of correctly-gated servers** (`P1-H1`, `P1-M2`, `P1-L5`)
and **timestamp / locale rendering divergence between sibling screens** (`P1-C1`, `P1-M3`,
`P1-L2`).
