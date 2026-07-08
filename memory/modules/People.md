# Module: People (`Modules\People`)

## Purpose

Tenant-owned staff records distinct from auth users, plus a professional credential vault for
licenses, certifications, and registrations. Staff are not patients; staff-profile reads are not
read-logged.

## Key tables

- `staff_profiles` - tenant-owned (`BelongsToTenant`). ULID id, nullable `user_id`, names,
  `display_name`, `profession`, nullable `employee_ref`, nullable `primary_branch_id`, `status`.
- `credentials` - tenant-owned (`BelongsToTenant`). ULID id, `staff_profile_id`, type/name,
  optional issuing authority/identifier/document path, optional issue/expiry dates, stored status.

## Key services / classes

- `Models\StaffProfile` - has many credentials; belongs to nullable Platform `User` and primary
  `Branch`; rejects cross-tenant `primary_branch_id`.
- `Models\Credential` - belongs to staff profile; auto-derives stored status on create/expiry
  update unless manually revoked; scopes `expiringWithin($days)` and `expired()`.
- `Services\CredentialService` - computes valid/expiring/expired/revoked from `expires_on` and
  tenant setting `people.credentials.expiry_alert_days` (default 30).
- `Console\RefreshCredentialStatuses` - `credentials:refresh-status`, tenant-by-tenant,
  idempotent recomputation.
- App-layer audit glue in `App\Providers\AppServiceProvider` writes credential create/update/revoke
  audit events through `AuditService`; People does not depend on Audit.

## Invariants enforced

- Staff profiles and credentials are tenant-owned and fail closed without `TenantContext`.
- `staff_profiles.primary_branch_id` must reference a branch visible in the same tenant context.
- `credentials.staff_profile_id` must reference a staff profile visible in the same tenant context.
- Manual `revoked` status is preserved by status computation and refresh.
- Expiring means `expires_on` is today through the configured window, inclusive; expired means
  before today; null expiry is valid.

## Status

**Phase B COMPLETE.** People module registered; staff profiles, credential vault, expiry status
service/scopes, refresh command, app-layer audit integration, and tests are in place.

## Open items

- Schedule `credentials:refresh-status` once the scheduler is set up (see DEFERRED.md).
- UI/API surfaces arrive in later People/Patients gates.
