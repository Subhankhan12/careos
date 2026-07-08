# AGENTS.md — CareOS Master Brief (authoritative)

This is the **single source of truth** for every agent working on CareOS, regardless of tool.
Claude Code reads `CLAUDE.md`; Codex reads `codex.md`; both are thin pointers to THIS file.
**Trust the repo over any description, including this file** — if reality and this document
disagree, verify from the repo and flag the drift.

## Project

CareOS is an **agentic, multi-tenant healthcare operations SaaS** for clinics, dental practices,
facility nursing, and home-nursing/agency care. It targets **Europe first, USA second**.

Market packs:
- **Pack #1 — EU-Generic billing** (first).
- **Pack #2 — US / EVV lane** (second).

## Stack

- **Framework/PHP:** Laravel 12 on XAMPP's existing **PHP 8.2** (`C:\xampp\php`) — **no Herd**.
  Do not install or switch PHP.
- **OS/shell:** Windows, PowerShell in the VS Code terminal. Use Windows commands and paths.
  Run commands one per line; do not rely on `&&` chaining.
- **DEV database:** existing XAMPP **MariaDB 10.4 on port 3306**, database **`careos`**
  (`DB_CONNECTION=mysql`). Other databases on 3306 stay untouched.
- **PROD target + CI = MySQL 8.** Write **portable SQL** that runs on both MariaDB 10.4 and
  MySQL 8. Validate/migrate to MySQL 8 before production (MariaDB 10.4 is EOL).
- **Frontend:** Inertia v2 + Vue 3 + TypeScript + Tailwind v4 + vue-i18n. Nurse PWA is a
  separate SPA (later phase).
- **Cache/queue/session:** Laravel 12 **defaults (database)** now. **Redis + Horizon** later
  (Memurai or WSL2; client = `predis` on Windows).
- **AI/agent layer (later):** a custom provider-agnostic **LlmManager**-style HTTP layer
  (Anthropic primary) with cost ledger, budget gate, circuit breaker, versioned prompt registry
  — NOT a framework AI SDK.
- **Tests:** Pest. **Static analysis:** PHPStan (larastan) level 5 minimum. **Style:** Pint.

## HARD RULES (never violate)

- **ELECTRIC FENCE:** no diagnosis, no triage, no symptom assessment, no dosing logic — anywhere
  in code, prompts, or AI features. Ever.
- **Fail-closed tenancy:** every tenant-owned row carries `tenant_id`; queries without an
  established tenant context must **throw**. Never widen tenant scope for cross-tenant features —
  use **explicit share objects** only.
- **Money is integers in minor units.** Never floats.
- **Append-only:** `audit_events`, `ai_interactions`, and financial ledgers are append-only.
- **AI is draft-until-approved**, visibly labeled, and logged.
- **i18n keys only** — no hardcoded UI strings.
- **Cross-module contact goes through services + domain events, never cross-module Eloquent.**
  Enforced by Pest architecture tests (`tests/Architecture/ModuleBoundariesTest.php`).

## UI rule (standing)

Vue components are PRESENTATIONAL. All authorization, validation, and state-transition rules
are enforced and tested SERVER-SIDE. Components render props and dispatch actions; they never
encode business rules. A component may *display* a rule (e.g. hide a Sign button without
permission) but the server must independently enforce it.

Feature tests assert BEHAVIOR — HTTP status, redirects, DB state, audit rows, and
`assertInertia(component + props)`. They must NEVER assert on markup, DOM structure, or CSS
classes.

Consequence: any page must be replaceable by a visual redesign without touching controllers,
routes, prop contracts, or tests. If deleting every .vue file would lose a guard or a rule,
that rule is in the wrong place — move it to the server.

Rationale: CareOS builds functional-plain UI in gates; a coherent visual redesign pass follows
later. This rule keeps that swap a re-skin, not a rewrite.

## Workflow

- Work in **gates**. Execute only the gate that is pasted; never start the next gate; no
  "while I'm at it" extras.
- Every UI gate inherits the standing **UI rule**: Vue components are presentational, while
  authorization, validation, state transitions, and behavior tests live server-side.
- **One gate = one commit**, message format `P<phase>.G<n>: ...` (e.g. `P0A.G4: ...`).
  Consolidation at each phase end (`P<phase>.C: ...`).
- **Verify from repo reality** — never state a result you did not observe in actual output.
- Run **`composer check`** (lint + analyse + test) green **before every commit**.
- Never run destructive commands or install system-level software without asking.
- **STOP after each gate** — end with `composer check` green, the specified GATE REPORT, and
  exactly one commit.

## Module map

Platform · Audit · People · Patients · Scheduling · Clinical · Nursing · Billing · Comms ·
AiCore · Dental · Interop.

**Boundary rule:** cross-module contact goes through **services + domain events**, never
cross-module Eloquent. Where two modules must be composed (e.g. Audit needs the Platform tenant
context), the composition lives in the **application layer (`app/`)**, which may depend on both;
modules never depend on each other. Enforced by `tests/Architecture/ModuleBoundariesTest.php`.

## MEMORY PROTOCOL (every agent, every task)

**BEFORE a task** — read, in order:
1. `AGENTS.md` (this file).
2. `PROJECT-STATE.md` — where the project stands, gates done, next action.
3. `DECISIONS.md` and `DEFERRED.md` — architecture decisions and parked work.
4. The relevant `memory/modules/<Module>.md` for the module(s) you will touch.

**AFTER a task** — leave a durable record:
1. Append **one** entry to `memory/LOG.md` (newest at bottom): commit hash + one-line summary +
   test count where known. Append-only — never rewrite past lines.
2. Update the touched `memory/modules/*.md` (status, key classes, invariants, open items).
3. Update `PROJECT-STATE.md` (current phase, gates done, next action).
4. Log any new architecture decision in `DECISIONS.md` (append-only; supersede by new entry).

Keep memory entries **short and factual**. The repo is the truth; memory is the index into it.

## Pointer

**Claude Code reads `CLAUDE.md`; Codex reads `codex.md`; both are thin pointers to THIS file.
`AGENTS.md` is authoritative.**
