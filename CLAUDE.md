# CLAUDE.md — CareOS Standing Instructions

Every future session reads this file first. Trust the repo over any description, including
this file — if reality and this document disagree, verify from the repo and flag the drift.

## Project

CareOS is an **agentic, multi-tenant healthcare operations SaaS** for clinics, dental
practices, facility nursing, and home-nursing/agency care. It targets **Europe first, USA
second**.

Market packs:
- **Pack #1 — EU-Generic billing** (first).
- **Pack #2 — US / EVV lane** (second).

## Dev environment

- **OS/shell:** Windows, PowerShell in the VS Code terminal. Use Windows commands and paths
  (backslashes, drive letters). Do not assume bash/Unix utilities. Run commands one per line
  and check each; don't rely on `&&` chaining or parsing `tree`.
- **Framework/PHP:** Laravel 12 on XAMPP's existing **PHP 8.2** (`C:\xampp\php`) — **no Herd**.
  Do not install or switch PHP. Composer already present.
- **DEV database:** the existing XAMPP **MariaDB on port 3306**, in a separate **`careos`**
  database. Other databases on 3306 (e.g. RestioX) stay untouched. `DB_CONNECTION=mysql`.
- **PRODUCTION target:** **MySQL 8**. **CI runs against MySQL 8** for parity. Write
  **portable SQL** that works on both MariaDB 10.4 and MySQL 8. Validate/migrate to MySQL 8
  before production (MariaDB 10.4 is EOL).
- **Cache/queue/session:** Laravel 12 **defaults (database)** in early phases. **Redis +
  Horizon** come later (Memurai or WSL2; client = `predis` on Windows to avoid phpredis PECL
  pain). Do not introduce Redis in Phase 0.

## Stack constants (do not deviate)

- Laravel 12, PHP 8.2 (existing XAMPP CLI PHP — do not install or switch PHP).
- Database (DEV): existing XAMPP MariaDB on 127.0.0.1 port 3306, database `careos`. Do NOT
  install MySQL 8; do NOT use port 3307. Production target is MySQL 8; CI runs MySQL 8;
  portable SQL required.
- Cache/queue/session on Laravel 12 defaults (database) for now. Redis + Horizon later.
- Frontend (later phases): Inertia v2 + Vue 3 + TypeScript + Tailwind. Nurse PWA is a
  separate SPA (later phase).
- AI/agent layer (later phases): a custom provider-agnostic LlmManager-style HTTP layer
  (Anthropic primary) with cost ledger, budget gate, circuit breaker, versioned prompt
  registry — NOT a framework AI SDK.
- Tests: Pest. Static analysis: PHPStan (larastan) level 5 minimum. Style: Pint.

## Workflow

- Work in **gates**. Execute only the gate that is pasted; never start the next gate; no
  "while I'm at it" extras.
- **One gate = one commit**, message format `P<phase>.G<n>: ...` (e.g. `P0.G4: ...`).
  Consolidation at each phase end (`P<phase>.C: ...`).
- **Verify from repo reality** — never state a result you did not observe in actual output.
- Run **`composer check`** (lint + analyse + test) green **before every commit**.
- Never run destructive commands or install system-level software without asking.

## Hard rules

- **ELECTRIC FENCE:** no diagnosis, no triage, no symptom assessment, no dosing logic —
  anywhere in code, prompts, or AI features. Ever.
- **Fail-closed tenancy:** every tenant-owned row carries `tenant_id`; queries without an
  established tenant context must throw. Never widen tenant scope for cross-tenant features —
  use explicit share objects only.
- **Money is integers in minor units.** Never floats.
- **Append-only:** `audit_events`, `ai_interactions`, and financial ledgers are append-only.
- **AI is draft-until-approved**, visibly labeled, and logged.
- **i18n keys only** — no hardcoded UI strings.

## Module map

Platform · Audit · People · Patients · Scheduling · Clinical · Nursing · Billing · Comms ·
AiCore · Dental · Interop.

**Boundary rule:** cross-module contact goes through **services + domain events**, never
cross-module Eloquent. Enforced by Pest architecture tests
(`tests/Architecture/ModuleBoundariesTest.php`).
