# DECISIONS.md — Architecture Decision Log (append-only)

Append new decisions; never edit or delete past ones. Supersede by adding a new entry that
references the old ID.

- **D-001 — Laravel 12 on PHP 8.2.** Use the existing XAMPP CLI PHP (`C:\xampp\php`); no
  Herd, no PHP install/switch.
- **D-002 — App-layer fail-closed tenancy instead of RLS.** Tenancy is enforced in the
  application layer (every tenant-owned row carries `tenant_id`; no tenant context ⇒ throw),
  not via database row-level security.
- **D-003 — DEV database = existing XAMPP MariaDB 10.4 on port 3306.** A separate `careos`
  database; existing projects on 3306 remain untouched.
- **D-004 — PRODUCTION target = MySQL 8; CI runs against MySQL 8 for parity.** Portable SQL
  required (must work on both MariaDB 10.4 and MySQL 8). Validate/migrate to MySQL 8 before
  production, because MariaDB 10.4 is EOL.
- **D-005 — Default DB cache/queue drivers in Phase 0.** Redis + Horizon come later; on
  Windows the Redis client will be `predis` to avoid phpredis PECL pain.
- **D-006 — Frontend = Inertia v2 + Vue 3 + TypeScript + Tailwind.**
- **D-007 — Separate offline-first Nurse PWA** (a distinct SPA from the main app).
- **D-008 — Agent layer = custom provider-agnostic LlmManager-style HTTP layer** (Anthropic
  primary) with cost ledger, budget gate, circuit breaker, and versioned prompt registry —
  no framework AI SDK.
- **D-009 — Autonomy dial: off / suggest / approve / auto**, with clinical and financial
  actions capped at `approve` (never `auto`).
- **D-010 — EU-Generic pack first, US second via the EVV lane.**
- **D-011 — EU region cell first; PHI never crosses cells.**
- **D-012 — Plain internal Modules** (no nwidart or other third-party module manager).
- **D-013 — Append-only + hash-chained audit with read-logging.**
