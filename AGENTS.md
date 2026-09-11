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

> **STATE IN ONE LINE (as of `805930e`, 2026-09-10):** the BUILD is COMPLETE — eight verticals, all six hospital
> phases — the **nine-page wireframe-parity pass is CLOSED** and the **six domain parity batches are COMPLETE**,
> the **Operator Mode SECURITY CORE (G1–G3) is DONE** (it closed a live super-admin containment gap), and **the
> TEN-PHASE ROLE-BY-ROLE QA PROGRAMME IS COMPLETE WITH ZERO OPEN CRITICALS.** **The highest-value track is
> DEPLOYMENT + partnership integrations.** Do NOT invent a vertical, a hospital phase, a parity page or a QA
> gate; wait for the pasted gate. Full detail in `PROJECT-STATE.md`; parked work + triggers in `DEFERRED.md`.
>
> **THE QA PROGRAMME, AND WHY ITS RECORD MATTERS MORE THAN ITS NUMBER.** Ten audit phases drove every role in a
> real browser; ten fix gates (QA-FIX.1 … QA-FIX.10, 29 code-changing parts) closed the top of the list.
> **`docs/qa/ROLE-AUDIT.md` is the authoritative record: 186 findings, 41 fixed, 145 open — 0 CRITICAL, 34 HIGH,
> 80 MEDIUM, 31 LOW.** It is **append-only by its own rule** — a fixed finding is **never removed**; it keeps its
> **ID, its evidence and its reproduction** and gains a **FIXED banner** naming the gate, commit and decision.
> **Read the banner before re-investigating anything**, and never delete or rewrite a finding.
>
> **What the zero means:** **no known defect remains that loses data, falsifies a clinical or financial record,
> or breaches authorisation.** All 24 findings recorded as CRITICAL are fixed — by fixing them, not by moving
> them: none was withdrawn and none was merged away. The one honest exception is **`P4-C4`, re-graded
> CRITICAL → HIGH by QA-FIX.4b** because the defect was latent rather than active — **and fixed in that same
> gate**, so it does not contribute to the zero. The 34 open HIGHs are a different class (reach, visibility,
> recording, locale, attribution-display, one partial write) and **none blocks deployment**; they are grouped
> into seven families with the precedent fix for each in `DEFERRED.md`.
>
> **THE FENCES HELD.** Ten phases of adversarial driving eroded **no fence** — every defect was in
> presentation, navigation, attribution, partial writes, recording gaps or authorisation, never in the engines or
> the fences. The safety case with the strongest **driven** control for each fence is in `docs/qa/ROLE-AUDIT.md`
> §3 and restated in `docs/ONBOARDING.md` §3.
>
> **THE METHOD RULES the programme produced are its most transferable output** — consolidated in
> `docs/ONBOARDING.md` §0b. Before writing a test here, know these: an absence assertion over an empty
> collection is vacuously true (**D-174**); a refusal test must be one that would succeed without its guard
> (**D-182**); a mutation that changes nothing proves nothing, so **grep-confirm it applied** (**D-187**); a
> **comment-stripped** scan, because the file explaining why a token is forbidden contains it (this bit **five
> times**); **exit codes lie — read the log text** (`composer check` exited 0 with failures at least four
> times); and **local-green ≠ CI-green** — verify via `commits/<sha>/check-runs`.
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
- **ONE TIME BASE: STORAGE IS UTC FROM EVERY PATH — web, CLI, queue and scheduler (D-192).** Never mutate
  the process-wide default timezone (`date_default_timezone_set()`). `now()` reads PHP's *process*
  default, and Eloquent serialises that wall clock verbatim, so mutating it makes web requests write the
  practice's local time into columns every other path fills with UTC — one column, two time bases,
  silently, including the append-only hash-chained `audit_events`. That shipped once and was caught only
  by a QA audit; the middleware's own docblock had claimed the opposite, which is how it survived review.
  **Tenant-local time is a DISPLAY concern:** resolve the zone explicitly at the presentation boundary
  (`App\Services\DisplayTimezone`) and convert there. A comment asserting a safety property is a claim
  like any other — it needs a test.
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
3. `DECISIONS.md` (**D-001 → D-223**, append-only, no gaps) and `DEFERRED.md` — architecture decisions and
   parked work, including the prioritised QA open list.
4. **`docs/qa/ROLE-AUDIT.md` if you are touching anything the QA programme audited** — which is every staff
   role. **Check for an existing finding and its FIXED banner before investigating a defect**; it may already
   be recorded, already fixed, or deliberately left open with the reason written down. Never remove or rewrite
   a finding.
5. The relevant `memory/modules/<Module>.md` for the module(s) you will touch.

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
