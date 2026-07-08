# Module: Scheduling (`Modules\Scheduling`)

## Purpose

Scheduling and front-desk workflow: service catalog, bookable resources, no-double-book booking,
appointment lifecycle, waitlist, reminders, reception day-board, and public booking. The module is
not implemented yet; P0C.G0 established the Redis/Horizon queue substrate it will use.

## Key services / classes

- `App\Jobs\QueueSanityJob` - tiny infrastructure sanity job proving Redis queue round trips.
- `App\Providers\HorizonServiceProvider` - Horizon gate restricted to platform super-admins.

## Invariants enforced

- Queue and cache use Redis via Predis.
- Sessions remain on the database.
- Horizon dashboard routes carry `auth` + `super-admin`; tenant staff cannot access them.
- CI runs with MySQL 8 plus Redis 7 for queue-capable gates.

## Status

**P0C.G0 COMPLETE.** Redis-compatible server is reachable locally, Predis and Horizon are
installed, Horizon is configured for dev supervisors, and the sanity queue round-trip test passes.

## Open items

- C.1 builds the actual `Modules\Scheduling` service catalog and registers the module.
- Later gates add resource calendars, safe booking, lifecycle/waitlist, reminders, and UI.
