# PROJECT-STATE.md

Short, factual snapshot of where the project stands. Updated at consolidations and after gates
(per the MEMORY PROTOCOL in AGENTS.md).

- **Current phase:** Phase C - Scheduling & front desk - **IN PROGRESS**.
- **Commits:** 25 on `main` after P0C.G1 (scheduling service catalog).
  Phase A = 11 (P0A.G1-G8, P0A.GM, P0A.GF, P0A.GF3), pushed to `origin/main`
  (https://github.com/Subhankhan12/careos).
- **Verified quality (from actual output):** `composer check` green - Pint `passed`,
  PHPStan level 5 `[OK] No errors`, Pest **120 passed / 497 assertions**; `cmd /c npm run build`
  green at P0B.C (Vite production build, 647 modules transformed).
- **Stack (verified):** Laravel 12.63.0 on PHP 8.2.12; DEV DB = `careos` on XAMPP MariaDB
  10.4.32 (127.0.0.1:3306); Redis-compatible server on 127.0.0.1:6379 with Predis; queue/cache
  use Redis + Horizon; sessions remain database; Fortify + Sanctum.
- **Proven in Phase A:**
  - Fail-closed multi-tenancy (TenantContext + BelongsToTenant; no-context queries throw).
  - Fortify auth + **mandatory TOTP MFA** + tenant identification (suspended tenants denied).
  - Org hierarchy (branches/departments) with cross-tenant FK guard.
  - Custom **RBAC** with branch-scoped assignments + `Gate::before` (super-admin sole bypass).
  - Plans (integer minor units) + feature flags + typed settings.
  - Append-only, hash-chained, monthly-partitioned `audit_events` + AuditService
    (`verifyChain`, DB UPDATE/DELETE triggers), portable on MariaDB 10.4 + MySQL 8.
  - Audit integration (auth/RBAC/config events) + read-logging + time-boxed break-glass.
  - Inertia/Vue3/TS/Tailwind v4 shell (login -> 2FA -> role redirect; app/admin landings).
  - Cross-agent memory system (AGENTS.md + memory/) as the single source of truth.
  - CI builds the frontend and runs the suite on MySQL 8 (Node 22).
- **Proven in Phase B:**
  - People module registered with fail-closed `staff_profiles` and `credentials`.
  - Credential expiry status is derived from `expires_on` with tenant setting
    `people.credentials.expiry_alert_days` (default 30 days); manual `revoked` is preserved.
  - `credentials:refresh-status` recomputes stored statuses idempotently; scheduling is deferred.
  - Credential create/update/revoke is audited from the app layer; staff-profile reads are not read-logged.
  - Patients module registered with fail-closed patient CRM tables: patients, contacts,
    identifiers, and coverages.
  - MRNs are generated per tenant as `MRN-000001` style values under a tenant-row `FOR UPDATE`
    lock and skip existing/soft-deleted MRNs.
  - Patient reads use the Phase A read-logging mechanism with `patient_id`; `PatientAccessReport`
    can list read audit rows for a tenant-scoped patient.
  - Patient identifiers are optional attributes, not unique dedupe/match keys (D-021).
  - Duplicate detection is demographic, tenant-scoped, explainable, and combines deterministic
    name/DOB/address/identifier scoring with FULLTEXT only as supporting evidence.
  - Patient merge requires `patient.merge`, a reason, and same-tenant source/target; it writes
    `patient.merged`, moves captured child rows, soft-deletes the source, and `patient.unmerged`
    restores only the rows moved by that merge (D-022).
  - Consent engine stores versioned tenant templates and patient consent captures with immutable
    signed template snapshots; `ConsentService::has()` is fail-closed and respects scopes,
    expiry, and withdrawal (D-023).
  - Patient portal identity uses separate tenant-owned `portal_accounts` with a dedicated
    `patient` guard/session; portal invite/activation/login is gated by `portal.access` consent
    and audited with patient-scoped events (D-024).
  - First staff-facing patient UI is in place: RBAC-gated patient index/search, registration
    wizard with live duplicate warnings, and patient 360 view with consents + access log.
  - CI is green on MySQL 8 for the latest pushed Phase B work.
- **Proven in Phase C so far:**
  - Redis-compatible server reachable on 127.0.0.1:6379 (`PING` => `PONG`).
  - `predis/predis` and `laravel/horizon` are installed; Horizon dashboard is guarded by
    `auth` + `super-admin`.
  - Queue/cache use Redis; sessions intentionally stay on the database.
  - CI workflow includes a Redis 7 service alongside MySQL 8 and installs `pcntl`/`posix` for
    Horizon on Linux.
  - A real Redis queue round-trip sanity job passes locally.
  - Scheduling module registered with fail-closed `services` and tenant-owned `service_branch`
    availability links.
  - `ServiceCatalog` validates duration, buffers, resource requirements, per-tenant code
    uniqueness, and same-tenant branch availability.
- **Next action:** Continue Phase C. Execute only Gate C.2 when pasted.
