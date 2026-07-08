# Module: Scheduling (`Modules\Scheduling`)

## Purpose

Scheduling and front-desk workflow: service catalog, bookable resources, no-double-book booking,
appointment lifecycle, waitlist, reminders, reception day-board, and public booking. P0C.G0
established the Redis/Horizon queue substrate; P0C.G1 adds the tenant-owned service catalog;
P0C.G2 adds resources and availability calendars; P0C.G3 adds the concurrency-safe booking
engine; P0C.G4 adds appointment lifecycle and waitlist.

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
- `Services\ServiceCatalog` - CRUD/validation for services and branch availability links.
- `Services\AvailabilityService` - computes concrete windows for a resource/date range.
- `Services\BookingService` - validates availability/buffers/RBAC and books appointments by locking
  each resource row in a transaction before overlap checks/inserts.
- `Services\AppointmentService` - enforces legal lifecycle transitions, cancellation, no-show, and
  atomic cancel-and-rebook rescheduling.
- `Services\WaitlistService` - creates/matches waitlist entries, offers slots, and accepts offers
  by booking through `BookingService`.
- `Console\AttemptBookingCommand` - test harness command used by the parallel hammer to contend from
  separate PHP processes.
- `Events\AppointmentBooked` - Scheduling event consumed by app-layer audit glue as
  `appointment.booked`.
- `Events\AppointmentTransitioned` - app-layer audit glue records `appointment.<status>`.
- `Events\WaitlistEntryStatusChanged` - app-layer audit glue records `waitlist.<status>`.

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

## Status

**P0C.G4 COMPLETE.** Redis-compatible server is reachable locally, Predis and Horizon are
installed, Horizon is configured for dev supervisors, the sanity queue round-trip test passes, and
the Scheduling service catalog, resource calendars, no-double-book booking engine, appointment
lifecycle, and waitlist are registered with tests. Local `composer check` is green: 140 tests /
572 assertions.

## Open items

- Later gates add reminders, front-desk UI, public booking, and scheduler-agent proposals.
