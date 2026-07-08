# Module: Scheduling (`Modules\Scheduling`)

## Purpose

Scheduling and front-desk workflow: service catalog, bookable resources, no-double-book booking,
appointment lifecycle, waitlist, reminders, reception day-board, and public booking. P0C.G0
established the Redis/Horizon queue substrate; P0C.G1 adds the tenant-owned service catalog;
P0C.G2 adds resources and availability calendars.

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

## Key services / classes

- `App\Jobs\QueueSanityJob` - tiny infrastructure sanity job proving Redis queue round trips.
- `App\Providers\HorizonServiceProvider` - Horizon gate restricted to platform super-admins.
- `Providers\SchedulingServiceProvider` - loads Scheduling migrations.
- `Models\Service` - tenant-owned bookable service with structured resource requirements and
  branch availability helpers.
- `Models\ServiceBranch` - tenant-owned branch availability link; rejects cross-tenant service or
  branch references.
- `Models\Resource` - tenant-owned bookable resource; rejects cross-tenant branch/staff links and
  only allows staff links on practitioner resources.
- `Models\ResourceAvailability` - tenant-owned recurring or date-specific availability/block; rejects
  cross-tenant resource references and invalid time shapes.
- `Services\ServiceCatalog` - CRUD/validation for services and branch availability links.
- `Services\AvailabilityService` - computes concrete windows for a resource/date range.

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

## Status

**P0C.G2 COMPLETE.** Redis-compatible server is reachable locally, Predis and Horizon are
installed, Horizon is configured for dev supervisors, the sanity queue round-trip test passes, and
the Scheduling service catalog plus resource calendars are registered with tests.

## Open items

- C.3 adds the concurrency-safe booking engine and parallel hammer.
- Later gates add lifecycle/waitlist, reminders, and UI.
