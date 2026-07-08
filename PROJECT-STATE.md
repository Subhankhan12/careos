# PROJECT-STATE.md

Short, factual snapshot of where the project stands. Updated at consolidations and after gates
(per the MEMORY PROTOCOL in AGENTS.md).

- **Current phase:** Phase A — Platform core — in progress (foundations complete through the app shell).
- **Gates done:** Phase 0 (P0.G2–G5, P0.C); Phase A (P0A.G1–G8) + memory gate P0A.GM.
  All on `main`, pushed to `origin/main` (https://github.com/Subhankhan12/careos); tree clean.
- **Verified test count:** 75 passing / 202 assertions via `composer check` (as of P0A.G8) —
  Pint clean, PHPStan level 5 no errors. `npm run build` green (Inertia+Vue3+TS+Tailwind v4).
- **Delivered so far:** fail-closed multi-tenancy (TenantContext + BelongsToTenant); Fortify auth
  + mandatory TOTP MFA + tenant identification; org hierarchy (branches/departments); RBAC with
  branch-scoped assignments + Gate; plans/feature-flags/typed-settings; append-only hash-chained
  partitioned `audit_events` + AuditService; audit integration + read-logging + break-glass;
  Inertia/Vue3/TS/Tailwind shell (login → 2FA → role redirect, app/admin landings).
- **Stack (verified):** Laravel 12 on PHP 8.2; DEV DB `careos` on XAMPP MariaDB 10.4 (port 3306);
  default DB cache/queue/session drivers; Fortify + Sanctum; Inertia v2 + Vue 3 + TS + Tailwind v4.
- **CI:** GitHub Actions runs on push/PR to `main` against **MySQL 8** (production parity).
  Not observed from the Windows dev box (`gh` not installed) — check the Actions tab.
- **Next action:** continue per the master plan — People/Patients are next in the module map
  (Phase B). Execute only the gate that is pasted.
