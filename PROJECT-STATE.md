# PROJECT-STATE.md

Short, factual snapshot of where the project stands. Updated at consolidations and after gates
(per the MEMORY PROTOCOL in AGENTS.md).

- **Current phase:** Phase A — Platform core — **COMPLETE**.
- **Commits:** 16 on `main`; Phase A = 11 (P0A.G1–G8, P0A.GM, P0A.GF, P0A.GF3), pushed to
  `origin/main` (https://github.com/Subhankhan12/careos); working tree clean, up to date.
- **Verified quality (from actual output):** `composer check` green — Pint `passed`,
  PHPStan level 5 `[OK] No errors`, Pest **75 passed / 202 assertions**. `npm run build` green
  (Inertia + Vue 3 + TS + Tailwind v4). CI **green on MySQL 8** for the latest commit
  `6ae661c` (CI #15, confirmed by the user).
- **Stack (verified):** Laravel 12.63.0 on PHP 8.2.12; DEV DB = `careos` on XAMPP MariaDB
  10.4.32 (127.0.0.1:3306); default DB cache/queue/session drivers; Fortify + Sanctum.
- **Proven in Phase A:**
  - Fail-closed multi-tenancy (TenantContext + BelongsToTenant; no-context queries throw).
  - Fortify auth + **mandatory TOTP MFA** + tenant identification (suspended tenants denied).
  - Org hierarchy (branches/departments) with cross-tenant FK guard.
  - Custom **RBAC** with branch-scoped assignments + `Gate::before` (super-admin sole bypass).
  - Plans (integer minor units) + feature flags + typed settings.
  - Append-only, hash-chained, monthly-partitioned `audit_events` + AuditService
    (`verifyChain`, DB UPDATE/DELETE triggers), portable on MariaDB 10.4 + MySQL 8.
  - Audit integration (auth/RBAC/config events) + read-logging + time-boxed break-glass.
  - Inertia/Vue3/TS/Tailwind v4 shell (login → 2FA → role redirect; app/admin landings).
  - Cross-agent memory system (AGENTS.md + memory/) as the single source of truth.
  - CI builds the frontend and runs the suite on MySQL 8 (Node 22).
- **Next action:** **Phase B — People & Patients.** Execute only the gate that is pasted.
