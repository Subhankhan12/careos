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
- **D-014 — Custom tenant-aware RBAC, not spatie/laravel-permission.** Roles/permissions are
  tenant-owned with branch-scoped assignments and ABAC-condition slots; integrated via
  `Gate::before`, with platform super-admin (tenant_id null) as the ONLY bypass (P0A.G4).
- **D-015 — Users are global-email for now.** Single nullable `tenant_id` per user (null =
  super-admin); multi-tenant same-email membership is deferred (P0A.G2, see DEFERRED.md).
- **D-016 — DB triggers are the active append-only guard for `audit_events` in dev.** BEFORE
  UPDATE/DELETE triggers `SIGNAL SQLSTATE '45000'`; a least-privilege DB user (UPDATE/DELETE
  revoked) is the production defence-in-depth, deferred (P0A.G6).
- **D-017 — Cross-module composition lives in the app layer.** Modules never depend on each
  other (arch-test enforced); glue that needs two modules (e.g. Audit + Platform tenant context,
  break-glass) lives in `app/` via services/contracts (P0A.G7).
- **D-018 — AGENTS.md is the single source of truth across agents.** `CLAUDE.md` and `codex.md`
  are thin pointers; every task follows the MEMORY PROTOCOL (P0A.GM).
- **D-019 — Inertia pages live in `resources/js/pages` (lowercase).** Matches inertia-laravel's
  `pages.paths` for case-sensitive Linux/CI parity (P0A.GF3).
- **D-020 — CI builds the frontend and runs on MySQL 8.** `npm ci` + `npm run build` before
  tests (Vite manifest), Node 22; full suite runs against MySQL 8 for production parity
  (P0A.GF / P0A.GF3).
- **D-021 - Patient identifiers are optional attributes, not dedupe keys.** `patient_identifiers`
  may store external/national/insurance/member IDs for CRM context, but matching/dedupe must not
  treat them as unique patient identity keys (P0B.G2).
- **D-022 - Patient merge reversal restores only records moved by the merge.** `patient.unmerged`
  uses the `patient.merged` audit snapshot to restore the source patient and child rows moved
  during that merge; records created on the target afterward remain on the target (P0B.G3).
- **D-023 - Captured patient consents store immutable template snapshots.** `patient_consents`
  stores the signed template key/title/body/scope version alongside the template FK so consent
  proof and scope resolution are stable even after newer template versions supersede old text
  (P0B.G4).
- **D-024 - Patient portal identity is separate from staff users.** Use tenant-owned
  `portal_accounts` plus a dedicated `patient` session guard, not `users`, so patient logins are
  isolated from Fortify staff/admin MFA and RBAC. Portal sessions carry `portal_tenant_id` and
  re-establish tenant context before guard rehydration; portal access is gated by
  `ConsentService::has(patient, 'portal.access')` (P0B.G5).
