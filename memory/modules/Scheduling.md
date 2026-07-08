# Module: Scheduling (`Modules\Scheduling`)

## Purpose

Scheduling and front-desk workflow: service catalog, bookable resources, no-double-book booking,
appointment lifecycle, waitlist, reminders, reception day-board, and public booking. P0C.G0
established the Redis/Horizon queue substrate; P0C.G1 adds the tenant-owned service catalog.

## Key tables

- `services` - tenant-owned (`BelongsToTenant`). ULID id, `tenant_id`, `name`, per-tenant unique
  `code`, nullable `category`, default duration, before/after buffers, JSON
  `requires_resource_types`, `bookable_online`, `active`, timestamps.
- `service_branch` - tenant-owned availability link. `service_id`, `branch_id`, timestamps;
  unique `(tenant_id, service_id, branch_id)`. No rows means the service is available at all
  tenant branches.

## Key services / classes

- `App\Jobs\QueueSanityJob` - tiny infrastructure sanity job proving Redis queue round trips.
- `App\Providers\HorizonServiceProvider` - Horizon gate restricted to platform super-admins.
- `Providers\SchedulingServiceProvider` - loads Scheduling migrations.
- `Models\Service` - tenant-owned bookable service with structured resource requirements and
  branch availability helpers.
- `Models\ServiceBranch` - tenant-owned branch availability link; rejects cross-tenant service or
  branch references.
- `Services\ServiceCatalog` - CRUD/validation for services and branch availability links.

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

## Status

**P0C.G1 COMPLETE.** Redis-compatible server is reachable locally, Predis and Horizon are
installed, Horizon is configured for dev supervisors, the sanity queue round-trip test passes, and
the Scheduling service catalog module is registered with tests.

## Open items

- C.2 adds bookable resources and availability calendars.
- Later gates add safe booking, lifecycle/waitlist, reminders, and UI.
