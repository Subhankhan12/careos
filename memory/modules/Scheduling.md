# Module: Scheduling (`Modules\Scheduling`)

## Purpose

Scheduling and front-desk workflow: service catalog, bookable resources, no-double-book booking,
appointment lifecycle, waitlist, reminders, reception day-board, and public booking. P0C.G0
established the Redis/Horizon queue substrate; P0C.G1 adds the tenant-owned service catalog;
P0C.G2 adds resources and availability calendars; P0C.G3 adds the concurrency-safe booking
engine; P0C.G4 adds appointment lifecycle and waitlist; P0C.G5 adds queued reminders; P0C.G6
adds the reception day-board and public online booking surface; P0C.G8 adds governed Scheduler
Agent tools that wrap the safe waitlist and slot-finder paths.

## Key tables

- `services` - tenant-owned (`BelongsToTenant`). ULID id, `tenant_id`, `name`, per-tenant unique
  `code`, nullable `category`, default duration, before/after buffers, JSON
  `requires_resource_types`, `bookable_online`, `active`, timestamps.
- `service_branch` - tenant-owned availability link. `service_id`, `branch_id`, timestamps;
  unique `(tenant_id, service_id, branch_id)`. No rows means the service is available at all
  tenant branches.
- `resources` - tenant-owned (`BelongsToTenant`). ULID id, `tenant_id`, `type`
  (practitioner/room/chair/vehicle), `name`, nullable `staff_profile_id`, `branch_id`, `active`,
  timestamps. Indexed by `(tenant_id, type)` and `(tenant_id, branch_id)`.
- `resource_availability` - tenant-owned (`BelongsToTenant`). ULID id, `tenant_id`,
  `resource_id`, nullable `weekday`, nullable `start_time`/`end_time`, nullable date override,
  `is_available`, nullable `reason`, timestamps. Indexed by `(tenant_id, resource_id, weekday)`
  and `(tenant_id, resource_id, date)`.
- `appointments` - tenant-owned (`BelongsToTenant`). ULID id, `tenant_id`, nullable
  `rescheduled_from_id`, nullable `patient_id`, `service_id`, `branch_id`, `starts_at`,
  `ends_at`, `status`, nullable `status_reason`, nullable `booked_by`, nullable
  `status_changed_by`, nullable `status_changed_at`, `source`, nullable `notes`, timestamps.
  Indexed by `(tenant_id, branch_id, starts_at)`, `(tenant_id, patient_id, starts_at)`, and
  `(tenant_id, status)`.
- `appointment_resources` - tenant-owned (`BelongsToTenant`). ULID id, `tenant_id`,
  `appointment_id`, `resource_id`, timestamps. Unique `(tenant_id, appointment_id, resource_id)`;
  indexed for `(tenant_id, resource_id, appointment_id)` overlap lookups.
- `waitlist_entries` - tenant-owned (`BelongsToTenant`). ULID id, `tenant_id`, `patient_id`,
  `service_id`, nullable `branch_id`, nullable desired start/end window, `flexible`, `priority`,
  `status`, nullable offered start/end/branch fields, timestamps. Indexed by
  `(tenant_id, service_id, status)` and `(tenant_id, branch_id, status)`.
- `appointment_reminders` - tenant-owned (`BelongsToTenant`). ULID id, `tenant_id`,
  `appointment_id`, `type`, `channel`, `status`, `scheduled_for`, nullable `sent_at`,
  nullable `failed_at`, nullable `failure_reason`, timestamps. Unique
  `(tenant_id, appointment_id, type, channel)`; indexed by `(tenant_id, status, scheduled_for)`.

## Key services / classes

- `App\Jobs\QueueSanityJob` - tiny infrastructure sanity job proving Redis queue round trips.
- `App\Providers\HorizonServiceProvider` - Horizon gate restricted to platform super-admins.
- `Providers\SchedulingServiceProvider` - loads Scheduling migrations and registers the
  concurrency-test booking command.
- `Models\Service` - tenant-owned bookable service with structured resource requirements and
  branch availability helpers.
- `Models\ServiceBranch` - tenant-owned branch availability link; rejects cross-tenant service or
  branch references.
- `Models\Resource` - tenant-owned bookable resource; rejects cross-tenant branch/staff links and
  only allows staff links on practitioner resources.
- `Models\ResourceAvailability` - tenant-owned recurring or date-specific availability/block; rejects
  cross-tenant resource references and invalid time shapes.
- `Models\Appointment` - tenant-owned appointment row; rejects cross-tenant patient/service/branch
  references and defines lifecycle/blocking statuses.
- `Models\AppointmentResource` - tenant-owned appointment/resource consumption row; rejects
  cross-tenant appointment/resource references.
- `Models\WaitlistEntry` - tenant-owned waitlist request; rejects cross-tenant patient/service/
  branch references and invalid windows.
- `Models\AppointmentReminder` - tenant-owned reminder ledger; rejects cross-tenant appointment
  references and tracks pending/sent/skipped/failed delivery state.
- `Services\ServiceCatalog` - CRUD/validation for services and branch availability links.
- `Services\AvailabilityService` - computes concrete windows for a resource/date range.
- `Services\BookingService` - validates availability/buffers/RBAC and books appointments by locking
  each resource row in a transaction before overlap checks/inserts.
- `Services\AppointmentService` - enforces legal lifecycle transitions, cancellation, no-show, and
  atomic cancel-and-rebook rescheduling.
- `Services\WaitlistService` - creates/matches waitlist entries, offers slots, and accepts offers
  by booking through `BookingService`.
- `Services\ReminderPolicy` - reads tenant setting `scheduling.reminders.policy` with default
  24h + 1h offsets and email channel.
- `Services\ReminderDispatcher` - finds in-window active appointments and enqueues idempotent
  `SendAppointmentReminderJob` jobs on Redis queue `reminders`.
- `Services\ReminderChannelManager` plus `Contracts\AppointmentReminderChannel` - provider-free
  reminder channel abstraction; email implemented now.
- `Services\AvailableSlotFinder` - computes free concrete slots for a service/branch/date by
  combining resource availability with blocking appointment overlaps and service buffers.
- `Channels\EmailAppointmentReminderChannel` - sends through Laravel notification routing to the
  patient's primary email contact.
- `Jobs\SendAppointmentReminderJob` - queued reminder sender; re-establishes tenant context,
  locks the reminder row, re-checks status/consent/stale appointment state, then sends/skips/fails.
- `Console\DispatchAppointmentRemindersCommand` - tenant loop command for enqueueing due reminders.
- `Console\AttemptBookingCommand` - test harness command used by the parallel hammer to contend from
  separate PHP processes.
- `Http\Controllers\DayBoardController` - RBAC-gated staff day-board data endpoint and Inertia page.
- `Http\Controllers\DayBoardActionController` - lifecycle actions, quick-book, and slot preview for
  authenticated front-desk staff.
- `Http\Controllers\PublicBookingController` - tenant-slug public booking flow for online-bookable
  services.
- `App\AiCore\Tools\FillFromWaitlistTool` - app-layer AiCore/Scheduling integration tool; proposes
  matching waitlist fills and calls `WaitlistService::offer()` + `accept()` only after human
  approval.
- `App\AiCore\Tools\SuggestSlotsTool` - app-layer AiCore/Scheduling integration tool; returns slots
  from `AvailableSlotFinder` and never books.
- `Events\AppointmentBooked` - Scheduling event consumed by app-layer audit glue as
  `appointment.booked`.
- `Events\AppointmentTransitioned` - app-layer audit glue records `appointment.<status>`.
- `Events\WaitlistEntryStatusChanged` - app-layer audit glue records `waitlist.<status>`.
- `Events\AppointmentReminderDeliveryRecorded` - app-layer audit glue records
  `appointment_reminder.<status>`.

## Invariants enforced

- Queue and cache use Redis via Predis.
- Sessions remain on the database.
- Horizon dashboard routes carry `auth` + `super-admin`; tenant staff cannot access them.
- CI runs with MySQL 8 plus Redis 7 for queue-capable gates.
- Services are tenant-owned and fail closed without `TenantContext`.
- Service codes are unique per tenant.
- Duration must be greater than zero; buffers must be zero or greater.
- Each service requires at least one non-empty resource type.
- Branch availability links must reference same-tenant services and branches.
- Resources must reference a same-tenant branch.
- Practitioner resources may link to same-tenant staff profiles; room/chair/vehicle resources have
  no staff link.
- Resource availability must reference a same-tenant resource.
- Date-specific available rows override recurring windows for that date; date-specific unavailable
  rows subtract blocks, and an unavailable date row without times is full-day time off.
- Booking requires `appointment.manage` and a same-tenant service, branch, patient, and resources.
- Booking validates that each resource's held window (`starts_at - buffer_before` through
  `ends_at + buffer_after`) fits inside computed availability.
- No double-booking: inside one DB transaction, resource rows are locked in deterministic ID order,
  overlap rows for blocking statuses (`booked`, `confirmed`, `arrived`, `in_progress`) are checked
  with `FOR UPDATE`, and appointment/resource rows are inserted only if every required resource is
  free.
- Booking writes `appointment.booked` through app-layer audit glue; Scheduling does not depend on
  Audit models/services.
- Legal appointment transitions: `booked -> confirmed/cancelled/no_show/rescheduled`;
  `confirmed -> arrived/cancelled/no_show/rescheduled`; `arrived -> in_progress/cancelled`;
  `in_progress -> completed`; terminal states have no outgoing transitions.
- Cancellation requires a reason, records actor/reason on the appointment, deletes resource
  consumption rows, and audits `appointment.cancelled`.
- Reschedule marks the old appointment `rescheduled`, deletes old resource consumption rows, and
  books the new appointment through `BookingService` inside one transaction; failure rolls back the
  old appointment and resource rows.
- Waitlist matching is service-scoped, branch-scoped when requested, status `waiting`, and either
  flexible or covering the offered slot window.
- Reminder policy is tenant settings-driven. Default offsets are 1440 and 60 minutes before the
  appointment; default channel is email.
- Reminder sending is fail-closed on patient consent: the job sends email only when
  `ConsentService::has(patient, 'comms.email')` is true at send time.
- Reminder idempotency is enforced by the `appointment_reminders` unique key plus row locking in
  `SendAppointmentReminderJob`; sent/skipped rows are never sent again.
- Cancelled/rescheduled/completed/no_show appointments are stale for reminders and are skipped by
  the job even if a pending reminder was already queued.
- SMS and WhatsApp drivers are deferred behind the reminder channel interface.
- Reception day-board routes require auth plus `appointment.manage` and stay tenant-scoped.
- Quick-book previews only slots from `AvailableSlotFinder` and books through `BookingService`.
- Day-board appointment props include `patient_id` and an `openEncounterUrl`; the Document action
  posts to app-layer Clinical glue, which opens the encounter/draft note through server services
  and redirects to the note editor.
- Public booking uses `/book/{tenant:slug}` to establish tenant context without staff auth, exposes
  only active `bookable_online` services, rate-limits the flow, runs duplicate detection, and books
  through the same locked safe booking path with `source=online`.
- Public booking captures only minimal patient details required to create/reuse a patient and never
  runs triage, diagnosis, symptom assessment, or dosing logic.
- Realtime day-board refresh through Reverb is deferred; C.6 uses request/slot refreshes now.
- Scheduler Agent proposals are governed by AiCore and capped at approve. Nothing books from the
  waitlist until the approval queue executes the tool with a human approver.
- **Waitlist auto-fill (P0P.G9):** when a slot frees, reception offers it to a matching waitlist
  patient in one click. New `waitlist_offers` table (BelongsToTenant) + `WaitlistOffer` model with a
  lifecycle offered→accepted/declined/expired and a short TTL (`scheduling.waitlist.offer_ttl_minutes`,
  default 30). `WaitlistOfferService`: `candidates()` reuses `WaitlistService::matchingForSlot`;
  `offer()` creates a time-boxed hold (one open offer per entry) and fires `WaitlistOfferLifecycleChanged`;
  `accept()` books through the EXISTING `BookingService::book` (no-double-book resource lock) then marks
  the entry booked; `decline()`/`expire()`/`expireDue()` release the hold (entry stays `waiting`) so the
  next candidate can be offered. Expiry is checked/recorded OUTSIDE the booking transaction (so the throw
  doesn't roll it back). Concurrency proven by `WaitlistOfferHammerTest` — 6 offers on one freed slot,
  exactly one ACCEPTED. `ExpireWaitlistOffersCommand` (`scheduling:expire-waitlist-offers`, every 5 min,
  withoutOverlapping+onOneServer) sweeps timed-out offers per active tenant.
- **Notify path is app-layer composed (Scheduling may not use Comms):** the app-layer listener on
  `WaitlistOfferLifecycleChanged` audits every change (`waitlist_offer.*`) and, only on creation, sends
  the `waitlist.offer` built-in template (TRANSACTIONAL, so consent-gated on `comms.email`; skips
  fail-closed without consent — D-G4) through the Comms `NotificationService`. Channels: email/portal now,
  SMS when its driver lands.
- Reception UI is additive on the day-board (net-new, presentational): `waitlistOffers` prop + offer
  action URLs + a "Waitlist auto-fill" panel (find candidates for a freed slot → one-click offer →
  accept/decline; offer status visible). No existing day-board prop was removed. RBAC `appointment.manage`
  on every offer endpoint. See D-073.
- **Recurring / series appointments (P0P.G8, D-075):** reception books a repeating clinic appointment in
  one action. New `appointment_series` table (BelongsToTenant: patient/service/branch, `resource_ids` JSON,
  `rrule`, `timezone`, `start_time`, `duration_minutes`, `starts_on`, nullable `ends_on`, `status`
  active/ended); appointments gain nullable `series_id` FK + `occurrence_date`. `AppointmentSeriesService`:
  `preview()` (per-date free/conflict, books nothing — via `BookingService::checkAvailability`, a read-only
  clone of the booking checks), `create()` (builds the RFC-5545 rrule from freq/interval/byday/count|until,
  stores the series, materializes), `materialize()` (books each occurrence through the EXISTING
  `BookingService::book` with `series_id`+`occurrence_date`; idempotent on occurrence_date; guarded by
  status=active), `end()` (status→ended, stops future generation, never touches booked ones).
- **RRULE expansion reuses the E.2/DST-safe approach:** `recurr` with the series timezone yields the local
  occurrence DATES, then the `start_time` is RE-ANCHORED in the series tz per occurrence, so 09:00 local
  stays 09:00 across a DST boundary (tested Europe/Zurich spring-forward). Appointments store naive local
  wall-clock (consistent with the rest of Scheduling).
- **Conflict policy (never silently skip):** occurrences whose slot is taken are returned as a failure
  report `{date, reason}` (reasons reuse the booking exceptions: `resource_taken`/`outside_availability`);
  the free ones still book. `BookingService::book` gained optional `seriesId`/`occurrenceDate` params.
  Per-occurrence exceptions reuse the existing lifecycle: cancel ONE appointment (leaves series + rule
  intact), reschedule via the atomic reschedule. Day-board gains a net-new "make recurring" panel
  (freq/interval/day(s)/end + dated free/conflict preview → confirm); RBAC `appointment.manage`.

## Status

**Phase C COMPLETE / active.** Redis-compatible server is reachable locally, Predis and Horizon are
installed, Horizon is configured for dev supervisors, the sanity queue round-trip test passes, and
the Scheduling service catalog, resource calendars, no-double-book booking engine, appointment
lifecycle, waitlist, queued reminders, reception day-board, quick-book, and public online booking
are registered with tests. Scheduler Agent tools now wrap waitlist fill proposals and slot
suggestions under AiCore approval governance. D.7 added the day-board Document handoff to Clinical.
Local `composer check` is green: 205 tests / 1013 assertions. Local `cmd /c npm run build` is green.

- Branch OPENING HOURS (CLINIC.W8b) bound bookable times. `AvailableSlotFinder::forServiceBranchDate` and
  `BookingService::createBooking`/`checkAvailability` read `Modules\Platform\Services\BranchHoursService`
  (Scheduling→Platform is allowed). A branch with NO configured `branch_hours` rows keeps the engine's default
  07:00–19:00 scan window and imposes no booking constraint (so every hours-less test stays green); a configured
  branch bounds slots to its per-weekday [open, close] (closed day → no slots) and the write path throws
  `BookingUnavailableException::outsideBranchHours` for a start outside hours. Day-board + portal branch lists now
  filter `active=true` (public booking already did) so a deactivated branch is unbookable everywhere. See [[D-095]].

- Bookable-resource CRUD (CLINIC.W8c): the `Resource` (room/chair/vehicle) write path lives in the APP layer
  (`App\Http\Controllers\ResourceController` + `App\Services\ResourceService`) — created under a branch, edited/
  (de)activated by id, admin.manage-gated, tenant+branch scoped, audited (resource.created/updated/activated/
  deactivated app-layer hooks; Scheduling never imports Audit). App layer because the deactivation guard queries
  `Appointment` (via the `appointment_resources` pivot) and `arch('Platform does not depend on Scheduling')` forbids
  a cross-module guard inside a module. **No booking LOGIC changed:** `DayBoardController` + `AvailableSlotFinder::
  resourcesByType` already filtered `active=true`, so a new active resource is picked up and a deactivated one drops
  out automatically. **SCHEDULING SAFETY:** deactivation is soft (`active=false`; `appointment_resources`
  `restrictOnDelete`s a resource) and BLOCKED when the resource has future active appointments — the branch guard,
  mirrored. Practitioner resources stay staff-profile driven (People), excluded from the admin screen. A CRUD'd
  resource is immediately day-board-selectable but only OFFERED AS SLOTS once its `ResourceAvailability` windows are
  set (existing mechanism, unchanged) — a resource-availability screen is the flagged follow-up. See [[D-096]].

- **BRANCH.P3 (wireframe-parity correctness FIX) — practitioner-resource type is read-only.** A `practitioner`
  Resource is person-backed (`staff_profile_id` → StaffProfile; model invariant: only practitioners may link a
  staff profile); the admin type select offers facility types only (room/chair/vehicle), which can't represent a
  practitioner. `ResourceController::update` now branches: a **practitioner** update validates the NAME only and
  IGNORES any submitted `type` (stays practitioner — a forged retype has no effect); a **facility** keeps name+type
  editable within the facility-only `in:` rule (so a facility can't become a practitioner → 422). UI shows a
  read-only "Practitioner" label for practitioner rows, the editable select for facility rows. A FIX (stop an
  invalid edit), NOT a trim — facility CRUD + practitioner name/status (activate/deactivate, still guarded)
  unchanged. Locked by `tests/Feature/Scheduling/BranchPractitionerResourceTest.php` (6).

## Appointment Detail page (APPT.P1 — net-new display surface)

`GET /scheduling/appointments/{appointment}` (`scheduling.appointments.show`) -> `app/Http/Controllers/AppointmentDetailController` + `resources/js/pages/Scheduling/AppointmentDetail.vue`.
**APP-LAYER on purpose:** it composes Scheduling + Patients + Clinical (allergies) + Audit, so it lives in `app/`
(D-017) and Scheduling stays free of Clinical/Audit. `appointment.manage` (branch-scoped, as the day-board gates
it); the appointment is resolved from a STRING id in-controller (FIX.1) so an unknown/cross-tenant id 404s.
**Display sources:** `Appointment` (status/source/starts/ends/id/status_reason/status_changed_*) · `Service.default_duration_minutes` (the RECORDED length) · `AppointmentResource`->`Resource` (type/name only) · `Patient` + Clinical `Allergy` (recorded facts) · the timeline = append-only `audit_events` (`resource_type=appointment`, `context.from_status/to_status`, the `reason` column, `occurred_at`) merged with `AppointmentReminder` rows.
**THE FENCE (all three are tested):** the status pill shows the TRUE status and labels ALL EIGHT machine states (the wireframe drew four); resources expose EXACTLY `{id,name,type}` — the wireframe capability chips are OMITTED because `Resource` has no such field (a test pins the key set); the reminder channel is labelled exactly as recorded (**email only exists** — the page can never claim SMS) and provenance is real (portal actions attributed to the patient, unattributed rows to the system; never a fabricated "replied JA" — a test asserts no sms/replied/whatsapp string). Honest empty timeline. NO computed judgment, NO money, NO actions (action row =
APPT.P2, reschedule modal = APPT.P3).
**Day-board:** `appointmentSummary()` now carries `detail_url`; `ScheduleGrid.vue` links the patient name to it
(optional prop — other callers unaffected). **i18n gotcha:** vue-i18n treats `.` as a path separator, so audit
action keys are stored/looked up in underscore form (`appointment_booked`), with a raw-key fallback.
Locked by `tests/Feature/Scheduling/AppointmentDetailTest.php` (7) + the FIX.5 route smoke; browser-verified.

## Appointment Detail — action row (APPT.P2 — the real legal set, server-authoritative)

`POST /scheduling/appointments/{appointment}/transition` -> `AppointmentDetailController::transition`.
**`AppointmentService::legalTransitionsFrom(string $status)`** is a NEW read accessor over the SAME private
`LEGAL_TRANSITIONS` map `assertLegal()` enforces — it grants nothing (every move still goes through
`transition()`, which re-asserts legality inside the row lock) and it stops the UI drifting from the machine.
The controller renders `legalTransitionsFrom(status)` MINUS `rescheduled` (that needs the slot finder +
overlap guard — APPT.P3), so the row is: booked -> {confirm, cancel, no_show} · confirmed -> {arrive, cancel,
no_show} · arrived -> {start, cancel} · in_progress -> {complete} · terminal -> NONE.
**THE (a) RECONCILIATION:** the page shows the TRUE status, so a genuinely BOOKED appointment offers
**Confirm, never "Mark arrived"** (`booked->arrived` is not an edge); arrive appears only once confirmed.
**DELIBERATE DIVERGENCE (recorded, D-156):** the DAY-BOARD still composes `confirm()->arrive()` for a booked
appointment (two legal steps, both audited — option (c)); this page reflects the machine literally (option (a)).
Both legal; neither weakens the machine.
**REASON RULE from the service, not the page:** cancel is `required_if` (the service throws without one);
**no_show stays OPTIONAL** (the service permits null) — no stricter page-side rule invented (P0D.GU).
A forged illegal POST is refused by `assertLegal` with the record untouched; every accepted move writes the real
`appointment.<status>` audit row attributed to the operator. Locked by the APPT.P2 half of
`tests/Feature/Scheduling/AppointmentDetailTest.php` (13 total); browser-verified.
**THE REAL APPT.P2 TIP IS `27fa22c`, NOT `8874313`** — the gate shipped CI-RED and the fix is part of it. A test
asserted the audit context by matching the raw JSON substring `'"from_status":"booked"'`; that passes on dev
MariaDB 10.4 (which stores the JSON text as written) and FAILS on CI MySQL 8, which normalises a JSON column and
re-serialises it — space after the colon, keys reordered. **Standing rule: assert the MEANING of an audit context
by `json_decode`-ing it, never the serialised text** (APPT.P3 follows it). Local-green is not CI-green.

## Appointment Detail — reschedule (APPT.P3 — real finder + real overlap guard; core parity complete)

`POST /scheduling/appointments/{appointment}/reschedule` -> `AppointmentDetailController::reschedule`.
**THE SLOTS ARE THE FINDER'S:** `reschedulePanel()` MERGES `AvailableSlotFinder::forServiceBranchDate()`
answers across the next 14 days (<=4/day, <=12 total, the current slot excluded) — the finder is per-date by
design, so this is a merge of engine results, never a page-side availability computation. Offered only when
`rescheduled` is a legal move (reuses the P2 `legalTransitionsFrom`).
**THE PAGE NEVER PICKS RESOURCES:** confirm submits only `starts_at` + `reason`; the controller RE-RUNS the
finder at confirm, requires the slot to still be conflict-free, and uses ITS `resource_ids`.
**THE GUARD, TWICE OVER:** then `reschedule()` moves it — reason-required, assertLegal(->rescheduled), one
transaction with the old row lockForUpdate (links freed), re-booked via `BookingService::book` ->
`lockResource` -> `assertNoOverlap` (throws `BookingConflictException::resourceTaken`). A slot taken between
display and confirm is refused by the re-check, and any race past it by the guard. **Cannot double-book.**
`reschedule()` returns the NEW appointment (old -> `rescheduled`, terminal), so the operator is redirected there.
**OMITTED, NOT FAKED:** the "Dr. Weber only" toggle — the finder has NO preferred-resource parameter.
**Reading browser evidence:** two appointments MAY share a start time on DISJOINT resources — that is not a
double-book; the invariant is per-resource.
Locked by the APPT.P3 half of `tests/Feature/Scheduling/AppointmentDetailTest.php` (18 total); browser-verified.

## Open items

- **Appointment Detail parity is CORE-COMPLETE (P1→P3). Two OPTIONAL backend follow-ons stay queued — both are
  real backend gaps the page honestly OMITS rather than fakes, and neither blocks anything:**
  - **APPT.P4 — a room-capability field.** The wireframe drew "scanner · X-ray" capability chips on resources;
    `Resource` has no capability field, so the page exposes only `{id,name,type}`. Adding the chips without the
    field would fabricate a backend.
  - **APPT.P5 — a preferred-practitioner slot filter.** The wireframe drew a "Dr. Weber only" toggle;
    `AvailableSlotFinder` takes no preferred-resource parameter, so offering it would fabricate a filter the
    engine cannot honour.
- Later gates add realtime day-board refresh and UI surfaces for agent proposals.
- (POLISH.1, D-110) The recurring-series **end** action is now surfaced on the day-board (an
  "active recurring series" panel -> the existing `scheduling.series.end` route; `DayBoardController`
  passes `activeSeries` + `seriesEndUrl`). Presentational/additive only; no series domain logic changed.

### Scheduling batch audit (2026-08-29) — what the surfaces may and may not say

**The booking guard is absolute and single.** Staff `book()`, `bookOnline()`, quick-book,
series, reschedule and **waitlist accept** all funnel through `createBooking()` →
`lockResource()` (FOR UPDATE) → `assertNoOverlap()` (widened by the SERVICE's buffers). There is
**no override parameter** — do not build a "keep both" button, and never render a move
optimistically before the server confirms.

**`booked → arrived` is NOT legal** (confirmation first). `legalTransitionsFrom()` is the
server-authoritative read — **Appointment Detail uses it, the Day-Board does not** (S4). Fix that
before adding actions to the board.

**`WaitlistEntry.priority` is an UNDOCUMENTED int that ranking orders by FIRST.** Give it a
written operational meaning before any UI touches it, and never offer a clinical label
("Urgent · clinical") for it.

**The waitlist "hold" does NOT reserve a slot.** `OPEN_STATUSES` only blocks a second open offer
to the SAME entry; `assertNoOverlap` never reads `waitlist_offers`. Do not print "no one loses
the slot to silence".

**`WaitlistService::create()` has no route and no UI** — the waitlist is unreachable in
production. First thing any waitlist gate must fix (S1).

**Soft-suspend is ONLINE-ONLY:** `accepts_online_bookings` gates `SOURCE_ONLINE` alone; staff
booking into a suspended branch is deliberate.

**Does NOT exist:** a price/tariff on `Service` (Billing owns it) · slot granularity as a setting
(hardcoded 30-min stride) · min-notice-online · per-provider buffers · waitlist day/time
preferences · a booking-CONFIRMATION notification (only `appointment.reminder`).

**Better backed than expected:** `ResourceAvailability` already does weekly template AND dated
exceptions with a reason — Provider Availability is mostly UI over an existing model.

### SCHED.P2 — the service catalog screen (2026-08-29)

`ServiceCatalogController` (admin.manage, the Branches precedent) over the EXISTING
`ServiceCatalog` service. **The controller writes nothing itself** — it validates request shape
and delegates; `ServiceCatalog` owns duration/buffer/resource-type/unique-code rules. If you add
a rule, add it THERE.

**The five engine-read fields** (say so on any screen that edits them): `default_duration_minutes`
= slot length · `buffer_before/after_minutes` = the no-double-book window widening ·
`requires_resource_types` = what must be free · `active` = day-board + public · `bookable_online`
= public only.

**`AvailableSlotFinder::SLOT_STRIDE_MINUTES` is public** so a screen can show the real stride.
Do not retype 30 anywhere.

**NEVER put money on a Service.** Pricing is Billing's tariff catalog; the issued invoice is the
legal figure. Also absent by design: slot granularity as a setting, min-notice-online,
per-provider buffers, suggested duration.

**NO DELETE AFFORDANCE.** `appointments.service_id` is ON DELETE RESTRICT — a referenced service
cannot be removed, and the raw error is not an answer. Archive (`active = false`) is the verb.

**`services` has NO ordering column** — no sort/rank/position/priority. Do not add one without a
written operational meaning (D-191).

**KNOWN GAP (flagged, not built): service-catalog writes are not audited.** Scheduling reaches
the audit trail by dispatching events for an app-layer listener (`AppointmentTransitioned`);
service changes dispatch nothing. That is the in-pattern fix when someone takes it.

