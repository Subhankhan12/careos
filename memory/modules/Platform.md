# Module: Platform (`Modules\Platform`)

## Purpose

The tenancy + identity + access foundation: tenants, org hierarchy, users/auth, RBAC, per-tenant
configuration (plans, feature flags, settings), and break-glass grants. Everything tenant-owned
enforces fail-closed tenancy.

## Key tables

- `tenants` — platform-level (NOT tenant-owned). ULID id, name, slug (unique), region
  (`eu`/`us`, immutable after create), status (`provisioning`/`active`/`suspended`), `plan_id`.
- `users` — platform-level; nullable `tenant_id` (NULL = super-admin). Fortify 2FA columns +
  Sanctum tokens. Email globally unique for now.
- `branches`, `departments` — tenant-owned org hierarchy (`BelongsToTenant`); code unique per
  tenant / per tenant+branch; department branch must be same tenant.
- `roles` (tenant-owned), `permissions` (platform catalog), `permission_role`,
  `role_user` (tenant-owned assignment: nullable `branch_id`, `abac_conditions` JSON reserved).
  Catalog includes destructive `patient.merge`, appointment/encounter management, deterministic
  allergy override, Nursing agreement/dispatch management, and governed AI management. Starter
  `org_admin` receives all; coordinator receives Nursing agreement/dispatch management;
  doctor/nurse receive `encounter.manage`, `note.write`, and `note.sign`; doctor/org_admin receive
  `allergy.override`; org_admin receives `note.supervise`; reception does not.
- `plans` (platform; `price_minor` integer minor units, `limits`/`features` JSON),
  `feature_flags` (tenant-owned), `settings` (tenant-owned, typed value JSON). Nursing proof-of-
  visit privacy notice defaults to `nursing.visit.gps_privacy_notice`.
- `break_glass_grants` — tenant-owned, time-boxed emergency access (reason required, `expires_at`).

## Key services / classes

- `Services\TenantContext` — singleton; `set/current/id/has/forget`, `system(Closure)` escape hatch.
- `Concerns\BelongsToTenant` + `Concerns\TenantScope` — fail-closed global scope + `tenant_id` stamp.
- `Services\PermissionService` — `has(user, key, ?branchId)`; branch-scoped resolution.
- `Services\RbacProvisioner` — seeds permission catalog + starter roles per tenant (system mode).
- `Services\FeatureService` (flag resolution: override → plan default → false),
  `Services\SettingsService` (typed get/set + platform defaults).
- Middleware: `IdentifyTenantFromUser`, `EnsureTwoFactorEnabled`, `EnsureSuperAdmin`.
- Patient portal auth is intentionally separate in Patients (`portal_accounts` + `patient`
  guard); staff `users`/Fortify/RBAC remain for staff/admin only.
- Models: `Tenant`, `User`, `Branch`, `Department`, `Role`, `Permission`, `RoleAssignment`,
  `Plan`, `FeatureFlag`, `Setting`, `BreakGlassGrant`.

## Invariants enforced

- No tenant context + not system mode ⇒ tenant-owned queries THROW (`TenantContextMissingException`).
- `tenant_id` auto-stamped on create for tenant-owned models.
- Tenant region immutable after creation; cross-tenant references rejected (`CrossTenantReferenceException`).
- Mandatory TOTP MFA for all users; suspended tenants denied at login and at request time.
- Super-admin (tenant_id null) is the ONLY RBAC bypass (`Gate::before`).
- `patient.merge` is a permissioned destructive action granted to org-admin starter roles.
- `encounter.manage` is granted to org-admin, doctor, and nurse starter roles; reception is denied.
- `note.write` and `note.sign` are granted to org-admin, doctor, and nurse starter roles;
  reception is denied.
- `allergy.override` is granted to org-admin and doctor starter roles; nurse/reception do not
  receive it by default.
- `note.supervise` is granted to org-admin starter roles for tenant-team unsigned-note worklists;
  clinicians without it see only their own drafts.
- `agreement.manage` is granted to org-admin and coordinator starter roles for nursing service
  agreements; reception is denied.
- `dispatch.manage` is granted to org-admin and coordinator starter roles for Nursing dispatch;
  reception is denied.
- RBAC applies to staff `users` only; patient portal accounts do not receive staff permissions.
- Money as integer minor units; plans store `price_minor`.

## Status

**Phase A COMPLETE** (through P0A.C). Tenancy, org hierarchy, auth+MFA, RBAC, config
(plans/flags/settings), break-glass, and the auth flow (login → 2FA → role redirect) + app/admin
shells are in place and green on MariaDB (dev) and MySQL 8 (CI).
P0D.G1 adds `encounter.manage` to the permission catalog and starter doctor/nurse/org-admin roles.
P0D.G2 adds `note.write` and `note.sign` to the catalog and starter clinician roles.
P0D.G3 adds `allergy.override` to the catalog and starter org-admin/doctor roles.
P0D.G6 adds `note.supervise` to the catalog and starter org-admin roles.
P0E.G1 adds `agreement.manage` and the `coordinator` starter role for nursing service agreements.
P0E.G3 adds `dispatch.manage` to org-admin and coordinator starter roles for Nursing dispatch.
P0E.G4 adds the default `nursing.visit.gps_privacy_notice` setting text for staff privacy notice.

## Demo tenant (P0P.G1)

`DemoClinicSeeder` provisions ONE demo tenant, **Praxis Lindenhof** (slug `praxis-lindenhof`,
branch "Zürich Oberstrass", currency EUR, plan `eu_pro`), for design/sales/design-partner use:

    php artisan db:seed --class=DemoClinicSeeder

- **Idempotent by tenant slug**: if `praxis-lindenhof` exists the seeder returns immediately, so a
  second run adds nothing anywhere in the schema (asserted table-by-table).
- **Real provisioning path**: `Tenant::create` fires `RbacProvisioner::provisionTenant()`, which
  seeds starter roles in Phase A system mode — provisioning stays out of the audit chain. Everything
  after that runs as normal tenant-scoped actors, so the demo has a real 300+ row audit chain that
  verifies.
- **Never moves `now()` backwards.** `AuditService::verifyChain` replays ordered by `occurred_at`,
  so a back-dated write mid-run would order rows differently from how they were hash-linked and
  break the chain. Business dates are passed as explicit arguments instead.
- **Time anchors**: billing sits in the PREVIOUS full calendar month (`DemoClinicSeeder::period()`),
  which reconciles with all six invariants at delta 0; scheduling/dispatch/live clinical sit in the
  CURRENT week. Staff role assignments use `branch_id = null` (all-branches) because a branch-scoped
  assignment does not answer gate checks that pass no branch (`PermissionService::has`).
- Demo logins: `<first>.<last>@praxis-lindenhof.test` / `demo-password` (MFA pre-enrolled);
  portal accounts use `demo-portal-password`.

## Automation layer (P0P.G2)

The unattended cadences live in `routes/console.php`. Six commands, all
`withoutOverlapping()` + `onOneServer()`, all iterating `status = 'active'` tenants only:

| Command | Cadence |
| --- | --- |
| `credentials:refresh-status` | daily 02:10 |
| `nursing:materialize-visits` | daily 02:20 (rolling 8-week horizon) |
| `clinical:evaluate-recalls` | daily 02:30 |
| `billing:dunning-run` | daily 06:00 |
| `billing:reconcile` | daily 06:30 (launch-blocker monitor) |
| `appointments:dispatch-reminders` | every 15 min (enqueue only) |

**PRODUCTION RUNNER — nothing fires without it:**

    cron:        * * * * * cd /srv/careos && php artisan schedule:run >> /dev/null 2>&1
    supervisor:  php artisan horizon

`schedule:run` every minute is what drives all of the above; **Horizon must be running** or the
queued side (appointment reminders, notification deliveries) never drains — the scheduler only
ENQUEUES reminders, it does not send them.

**Local Windows cannot keep Horizon alive** — this PHP has no `pcntl`, so `php artisan horizon`
exits right after startup. Known LOCAL-only limitation (CI and Linux prod install `pcntl`/`posix`).
Locally use `php artisan schedule:work` (or a Task Scheduler entry) plus
`php artisan queue:work redis --queue=reminders,notifications` in place of Horizon. Nothing was
installed for this gate.

`SystemActorResolver::forPermission()` resolves the actor an unattended run acts as — see D-067
(never a super-admin, never branch-scoped, skip rather than escalate). `SettingsService::forget()`
removes a tenant override outright; `set($key, null)` is NOT equivalent because a stored null still
coerces on read (`'array'` reads back as `[]`).

P0P.G3 adds `audit:verify-chains` (daily 01:30) — see D-069. It lives in `app/Console/Commands/`,
NOT the Audit module: it needs Platform's `Tenant`/`TenantContext` and **Audit may not depend on
Platform** (same reason `App\Audit\PlatformAuditContext` lives in the app layer). Its
`IntegrityCheck` model lives in **Platform** for the mirror-image reason: the row is tenant-owned so
it needs `BelongsToTenant`, which Audit may not import. `integrity_checks` is append-only at model +
DB-trigger level, and a PASS is recorded as well as a failure — so a check that silently stops
running shows up as an absence rather than as nothing at all.

- Frontend UX gating + error pages (M-4/M-5, FIX.4): `HandleInertiaRequests::authUser()` shares
  `auth.user.permissions` (the nav-relevant keys resolved via `$user->can()`, super-admins all-true via
  `Gate::before`) so `AppLayout` hides links a role can't use — a UX hint only; the route Gate stays
  authoritative (a typed URL still 403s). `bootstrap/app.php` `withExceptions` renders an in-shell Inertia
  `Error` page (`resources/js/pages/Error.vue`, GuestLayout) for 403/404/419/503 and the portal
  consent-withdrawal lockout (403 on a `portal.*` route → "access withdrawn" message); PRESENTATION ONLY, the
  status code is preserved, and the renderer no-ops under `testing` so existing status assertions stay exact.
  See [[D-092]].
- CI route smoke (FIX.5): `tests/Feature/Smoke/RouteSmokeTest.php` drives every major GET route through the
  REAL middleware stack with `TenantContext::forget()` before each request (so `IdentifyTenantFromUser` sets
  context via the middleware, like a browser), asserting 200-not-500 for all roles + portal. This is the
  systemic guard against a request-time 500 (the C-1 class the pre-seeded feature tests masked). Runs as a
  dedicated CI step + inside `composer check`; local: `composer test:smoke`. See [[D-093]].
- Admin screens (CLINIC.W8) — the FIRST Platform Http controllers (`Modules/Platform/src/Http/Controllers/`).
  **SettingsController** (`/settings`, admin.manage): reads/writes tenant settings ONLY through the existing
  `SettingsService` (get/set) — editable = settlement `currency` (+ allow-list) and invoice-issuer identity
  `billing.seller_name`/`billing.seller_vat_id` (the keys that round-trip AND have a runtime consumer); tenant
  profile + branches shown read-only (no write backend); other clinic-settings listed as gaps, not faked.
  **UserRoleController** (`/admin/roles`, admin.manage): assigns one of the 6 seeded `is_system` role templates via
  the sanctioned raw `RoleAssignment::create(['user_id','role_id','branch_id'=>null])` (NO service exists — that IS
  the path; auto-audited by the `RoleAssignment::created`→`role.assigned` hook, so never bypass Eloquent / run in
  system mode). Assign REPLACES the user's role (role_user has no unique constraint → dedupe). A last-org_admin
  self-lockout guard lives in the controller (none in the RBAC layer). Server Gate stays authoritative — a user's
  effective perms are exactly the template's. Nav link is gated on `admin.manage` (added to
  `HandleInertiaRequests::NAV_PERMISSIONS`). See [[D-094]].

## Open items

- ABAC condition evaluation (`abac_conditions`) not yet implemented (Phase B, needs patients/audit).
- Multi-tenant same-email membership deferred (see DEFERRED.md).
- Redis/Horizon, silo tenancy tier, SSO/SAML deferred.
