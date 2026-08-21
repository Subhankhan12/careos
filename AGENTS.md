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

> **STATE IN ONE LINE (as of `c086de5`, 2026-08-17):** the BUILD is COMPLETE — eight verticals, all six hospital
> phases, three clean QA audits — the **nine-page wireframe-parity pass is CLOSED**, and the **Operator Mode
> SECURITY CORE (G1–G3) is DONE**, which closed a live super-admin containment gap. **The highest-value track is
> DEPLOYMENT + partnership integrations.** Do NOT invent a vertical, a hospital phase, or a parity page; wait for
> the pasted gate. Full detail in `PROJECT-STATE.md`; parked work + triggers in `DEFERRED.md`.
>
> **⏸️ Two things are DELIBERATELY parked — neither is unfinished by accident:** **Operator Mode G4–G11**
> (operator-convenience UI; backend inert with **no HTTP route or UI** — see `memory/modules/OperatorMode.md`)
> and **Waitlist Management** (audited in `docs/wireframe-parity/WAITLIST-MANAGEMENT-DIFF.md`, chain not
> started). Do not "finish" either unprompted.

## Stack

- **Framework/PHP:** Laravel 12 on XAMPP's existing **PHP 8.2** (`C:\xampp\php`) — **no Herd**.
  Do not install or switch PHP.
- **OS/shell:** Windows, PowerShell in the VS Code terminal. Use Windows commands and paths.
  Run commands one per line; do not rely on `&&` chaining.
- **DEV database:** existing XAMPP **MariaDB 10.4 on port 3306**, database **`careos`**
  (`DB_CONNECTION=mysql`). Other databases on 3306 stay untouched.
- **PROD target + CI = MySQL 8.** Write **portable SQL** that runs on both MariaDB 10.4 and
  MySQL 8. Validate/migrate to MySQL 8 before production (MariaDB 10.4 is EOL).
- **Frontend:** Inertia v2 + Vue 3 + TypeScript + Tailwind v4 + vue-i18n. The **Nurse PWA is BUILT**
  — a separate offline-first SPA under `nurse-pwa/` (`npm run build:pwa`), P0E.G5–G7.
- **Cache/queue/session:** **Redis + Horizon are BUILT and in use** (P0C.G0; `QUEUE_CONNECTION=redis`,
  `REDIS_CLIENT=predis`, Memurai locally on Windows).
- **AI/agent layer: BUILT** (P0C.G7 + AGENT.P1–P6) — a custom provider-agnostic **LlmManager**-style HTTP
  layer (Anthropic primary) with cost ledger, budget gate, circuit breaker, versioned prompt registry —
  NOT a framework AI SDK.
- **Tests:** Pest. **Static analysis:** PHPStan (larastan) level 5 minimum. **Style:** Pint.

## HARD RULES (never violate)

- **ELECTRIC FENCE — RECORD, NEVER JUDGE.** No diagnosis, triage, symptom assessment or dosing logic
  anywhere in code, prompts, or AI features. Ever. As the build widened, the fence hardened into one
  rule with many faces — CareOS **records** clinical facts and **never computes a clinical judgment**:
  no acuity/severity/EWS/early-warning score, no surgical-risk or ASA computation, no lab abnormal-flag,
  no imaging finding or CAD, no drug-allergy cross-reactivity or interaction checking. Concretely:
  **checklists RECORD, they do not ENFORCE** (no case-gating); **reference ranges are DISPLAYED, never
  FLAGGED**; **reports, acuity and ASA are AUTHORED/ASSIGNED by a clinician, never computed**.
  Every clinical-safety judgment is a **certified-partner null-object seam** — advisory, human-owned,
  and structurally incapable of auto-blocking. **A homemade version is a permanent NON-GOAL**, not a
  backlog item: it would make CareOS a medical device.
- **A FENCE TEST MUST BITE — every absence assertion needs a POSITIVE CONTROL.** The fence is enforced by
  tests that assert something is NOT present (no judgment key in a payload, no forbidden token in a source
  scan, no licensed code set in the repo). Such a test is **vacuously true over an empty subject**: a payload
  scan whose fixture recorded no rows, a glob that resolves to no files, a source scan whose directory has
  moved. It then passes for ever while protecting nothing, and buys false confidence. So: **prove the subject
  is non-empty before scanning it** (assert the rows/files exist, and name what must be among them), **make
  the fixture representative** — include the data that would TEMPT the breach (an abnormal vital, a deep
  pocket, a severe allergy, an expensive fee, a populated pick-list) — and **mutation-check it**: introduce the
  forbidden thing and confirm the suite turns red. A guard that has never been seen to fail is not yet a
  guard. See D-174 (the vacuous vitals scan) and D-173 (a scan that stopped resolving when its file moved).
- **GOVERNANCE:** agent autonomy = **MIN(configured, tool ceiling, role RBAC ceiling)**; configuration can
  only ever NARROW, never widen. The fence is toggle-free. The agent **DRAFTS** (suggest-only, through the
  ApprovalQueue) and a **HUMAN commits** anything consequential: the agent never auto-sends, never commits
  money, and never escalates to legal debt-enforcement/Betreibung. Every displayed metric is
  **real-or-honestly-absent** — never a fabricated number.
- **2FA is MANDATORY and LOCKED** for staff (no skip/disable path), including a re-challenge when a session
  is restored from the remember-me recaller (AUTH-SEC.1).
- **Fail-closed tenancy:** every tenant-owned row carries `tenant_id`; queries without an
  established tenant context must **throw**. Never widen tenant scope for cross-tenant features —
  use **explicit share objects** only.
- **Money is integers in minor units.** Never floats. **ALL money math lives in the billing engine** —
  a page/report never sums or derives a figure it displays. Every movement (charges, invoices, payments,
  credit notes, write-offs, contractual adjustments, payment-plan installments) must
  **reconcile-to-the-unit, δ=0**, proven by test.
- **Append-only:** `audit_events`, `ai_interactions`, and financial ledgers are append-only — enforced by
  ORM guards **and DB triggers**, not by convention.
- **Concurrency idiom:** `lockResource` → `assertNoOverlap` inside one transaction for anything that can
  double-book or double-spend (beds, theatres, slots, stock).
- **Portability:** use `dateTime()` (not `timestamp()`) for mutable moments (P0P.G15), and never assert on
  serialised JSON text — `json_decode` and assert the meaning (MySQL 8 re-serialises JSON columns).
- **AI is draft-until-approved**, visibly labeled, and logged.
- **i18n keys only** — no hardcoded UI strings.
- **Cross-module contact goes through services + domain events, never cross-module Eloquent.**
  Enforced by Pest architecture tests (`tests/Architecture/ModuleBoundariesTest.php`).
- LAUNCH BLOCKER — a tenant's billing period must reconcile to the unit (billing:reconcile, all six
  invariants ok with delta_minor === 0) before any real invoicing goes live. No exceptions.

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
- **One gate = one commit**, prefixed with the gate id: `P<phase>.G<n>: ...` for build gates
  (`P0A.G4:`), the gate's own name for later chains (`SETTINGS.P6:`, `APPT.P2:`, `AUTH-VIS:`).
  Consolidation at each phase end (`P<phase>.C: ...`).
- **Verify from repo reality** — never state a result you did not observe in actual output. Open with
  `git log --oneline -1` and close with `git log --oneline -2`. **If a pasted gate's precondition commit
  is not HEAD, or the work already exists, STOP and say so** rather than building it twice.
- Run **`composer check`** (lint + analyse + test) green **before every commit** — it takes ~45–60 min, so
  run it in the background, and **read the log text**: the wrapper's exit code has lied.
- **Local-green is NOT CI-green.** Verify every gate against the GitHub **check-runs API** after pushing
  (dev is MariaDB 10.4; CI and prod are MySQL 8, and they differ).
- Never run destructive commands or install system-level software without asking.
- **STOP after each gate** — end with `composer check` green, the specified GATE REPORT, and
  exactly one commit. Never start the next gate unprompted.

## Module map

**20 modules are built and PSR-4-registered** (verified on disk):

AiCore · Audit · Billing · Clinical · Comms · Dental · ED · FrontDesk · Hospital · Import ·
Lab · Nursing · Patients · People · Pharmacy · Platform · Radiology · Reporting · Scheduling · Surgery.

> **`Interop` is the ONLY planned placeholder** — the deferred HL7/FHIR + claims lane, deliberately not on
> disk (a certified-partner seam, see `DEFERRED.md`). See `docs/MASTER-STATUS-REPORT.md` for the full map.

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
