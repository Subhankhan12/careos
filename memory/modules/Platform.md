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
  Catalog includes destructive `patient.merge`, appointment/encounter management, and governed
  AI management. Starter `org_admin` receives all; doctor/nurse receive `encounter.manage`,
  `note.write`, and `note.sign`; reception does not.
- `plans` (platform; `price_minor` integer minor units, `limits`/`features` JSON),
  `feature_flags` (tenant-owned), `settings` (tenant-owned, typed value JSON).
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
- RBAC applies to staff `users` only; patient portal accounts do not receive staff permissions.
- Money as integer minor units; plans store `price_minor`.

## Status

**Phase A COMPLETE** (through P0A.C). Tenancy, org hierarchy, auth+MFA, RBAC, config
(plans/flags/settings), break-glass, and the auth flow (login → 2FA → role redirect) + app/admin
shells are in place and green on MariaDB (dev) and MySQL 8 (CI).
P0D.G1 adds `encounter.manage` to the permission catalog and starter doctor/nurse/org-admin roles.
P0D.G2 adds `note.write` and `note.sign` to the catalog and starter clinician roles.

## Open items

- ABAC condition evaluation (`abac_conditions`) not yet implemented (Phase B, needs patients/audit).
- Multi-tenant same-email membership deferred (see DEFERRED.md).
- Redis/Horizon, silo tenancy tier, SSO/SAML deferred.
