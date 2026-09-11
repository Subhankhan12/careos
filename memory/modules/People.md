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

## FINAL STATE after the ten-phase QA programme (2026-09-10, `805930e`)

**`StaffProfile::forUser()` is the attribution spine of the whole product, and four CRITICALs came from not
calling it.** `P2-C1` (a clinical note), `P6-C2` (an ASA assessment), `P7-C1` (an ED triage) and `P9-C3` (a
ward round, its note and its observations) were all the same defect: **a person resolved by convenience**
rather than asked for.

- **`forUser()` returns NULL rather than guessing** (D-195, D-216). A caller that cannot identify the actor
  must **REFUSE to write**, not fall back to an arbitrary profile. The old fallbacks — an alphabetical
  `first()`, a dropdown default, an unconditional copy of a stay's admitting clinician — are each recorded as
  a finding.
- **THE PATTERN IS "resolving a person by convenience"; the dropdown is only its most visible form.** Phase 7
  called it attribution-by-dropdown-default; Phase 8 found it with **no dropdown anywhere** (a server-side
  alphabetical `first()`); Phase 9 found it **unconditional**, with the correct answer one call away.
- **WHY ALL FOUR SURVIVED THEIR OWN TEST SUITES, and the rule to take from it:** every fixture made the actor
  and the attributed person the **same user**, so storing either gave an identical result. **A fixture whose
  two people are the same person cannot catch a misattribution.** Make actor ≠ subject on purpose.
- **Which attribution legitimately does NOT move** (D-195, D-220): *"whose visit is this"* is the **encounter**
  and keeps its booked clinician; *"who wrote this down"* is the **note** and is the authenticated user. **A
  ward round has no booking, so all three of its attributions are the person who performed it** — that rule
  does not transfer, and D-220 records why.
- **`SystemActorResolver::forPermission()`** (`Modules/Platform/src/Services/SystemActorResolver.php:45`) is
  the answer for **unattended** work, which has no session actor to ask about. **`P9-H6` is still open** and
  its remedy is exactly this call — every other scheduled command already uses it.
