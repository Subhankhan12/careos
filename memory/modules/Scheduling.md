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

## Status

**Phase C COMPLETE / active.** Redis-compatible server is reachable locally, Predis and Horizon are
installed, Horizon is configured for dev supervisors, the sanity queue round-trip test passes, and
the Scheduling service catalog, resource calendars, no-double-book booking engine, appointment
lifecycle, waitlist, queued reminders, reception day-board, quick-book, and public online booking
are registered with tests. Scheduler Agent tools now wrap waitlist fill proposals and slot
suggestions under AiCore approval governance. D.7 added the day-board Document handoff to Clinical.
Local `composer check` is green: 205 tests / 1013 assertions. Local `cmd /c npm run build` is green.

## Open items

- Later gates add realtime day-board refresh and UI surfaces for agent proposals.
