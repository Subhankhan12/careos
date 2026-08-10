# Agent & Tool Config — Wireframe-Parity Diff (audit only)

**Scope:** diff the intended live surface against the decoded wireframe
`resources/prototype/agent-tool-config.wireframe.html` on every axis (agent list/detail, autonomy ladder, tool
whitelist, permission ceiling, rate/timing limits, the fence vault, action ledger, styling, states, copy, AND
governance/fence semantics). **This is an audit. No app code was changed.** The next page of the wireframe-parity
pass (Admin Settings ✓, Approval Queue ✓, Branches ✓).

- **Date:** 2026-08-10 · **Branch:** `main` · **HEAD:** `a865a31` · **CI:** green.
- **Env:** `migrate:fresh --seed` + `DemoClinicSeeder`. Driven in Playwright as
  `andrea.lindenhof@praxis-lindenhof.test` (org_admin, 2FA).

> **This page is GOVERNANCE-DENSE and the wireframe is fence-honest.** It is an **agent-centric SUPERSET** of the
> already-built **SETTINGS.P2** `/admin/agents` card, which is **per-TOOL autonomy** over `AutonomyPolicy`. The
> crux is a MODELLING decision (§6: an `Agent` entity vs. an agent-shaped view over the existing per-tool policy)
> and the discipline that **every governance control stays capped / read-only — the config UI must NEVER raise
> autonomy past a ceiling or grant a capability past the cap** (the cap is server-enforced, as P2 already proved).

---

## 1. The live surface found & how the wireframe maps onto it

| Live (SETTINGS.P2) | Detail |
|---|---|
| Route | `GET /admin/agents` + `POST /admin/agents` (`ai.manage`) |
| Controller | `App\Http\Controllers\AgentAutonomyController` — a READ + WRITE-THROUGH-`AutonomyPolicy` window; adds no domain logic, no raw setting write |
| Policy | `Modules\AiCore\Services\AutonomyPolicy` — per-tool level in tenant setting `ai.autonomy.<tool.key>`; `LEVELS` = off/suggest/approve/auto; `cap()` = per-tool `autonomyCeiling` + clinical/financial hard-cap at **approve**; `set()` **clamps**; `effectiveCeiling()` = `cap(AUTO)` (read-only view) |
| Tool set | `ToolRegistry::all()` (the real governed tools; reserved `demo.*` hidden) — **10 tools** live |
| Runtime | `AgentRuntime` enforces the level at call time; `ApprovalQueue` holds `approve`-level actions; the P5 `fence_refused` status + the append-only `ai_interactions` ledger already exist |
| Enable/disable | `KillSwitch` (`ai.feature.<feature>.enabled`) exists as a backend concept — **not wired to this page** |
| Vue | `resources/js/pages/Admin/Agents.vue` (SettingsLayout + the Settings sub-nav) |

**Live shape (baseline, verified in browser):** ONE glass card, a governance banner ("How far each governed agent
may act. The server enforces every ceiling — this page only reflects it."), then a **per-TOOL list** (10 rows):
each row = tool name + category chip + `tool.key` + "Ceiling: <level>" + an **Off/Suggest/Approve/Auto** pill group
where levels **above the tool's ceiling render locked (padlock, disabled)**. One Save. Verified caps: **clinical →
Suggest** (Approve+Auto locked: `clinical.draft_recall_message`, `clinical.summarize_since_last_visit`);
**financial → Approve** (Auto locked: `billing.*`); **operational → Approve** (Auto locked: `comms.*`,
`scheduler.*`, `nursing.*`) — with `comms.draft_reply`/`comms.classify_document` capped at **Suggest**. **Auto is
locked on every tool.** All default to Suggest.

**Mapping / the fundamental difference:** the live model is **per-TOOL**; the wireframe is **per-AGENT**. The
wireframe's left list is 4 **agents** (Front-desk / Recall / Clinical-summary / Dispatch), each with its OWN
autonomy ladder + a **whitelist of the tools it may call** + rate/timing limits + live metrics. In the live model
there is **no `Agent` entity** — "agents" are code classes (`app/AiCore/Agents/*`: Inbox, FollowUp,
ClinicalSummary, Dispatch, Billing) and autonomy is a setting **per tool**, not per agent. So the wireframe's
per-agent page is a **superset that implies a new modelling layer** (see §6). Much of what it draws maps onto real
mechanisms (the ladder → `AutonomyPolicy`; the ceiling → `effectiveCeiling`; the fence vault → the electric-fence
invariants; fence-refused → the P5 status/ledger) — but *grouped by agent* and with *new configurable knobs* that
have no backend yet.

---

## 2. Section-by-section diff

Class: **(a)** visual/layout · **(b)** feature/backend gap · **(c)** governance/fence (match visual, keep the
cap/read-only) · **(d)** correctly-more-real. Sev weighted by governance.

| Section | Wireframe | Live (P2) | Diff | Class | Sev |
|---|---|---|---|---|---|
| **Page frame / tabs** | Glass top-nav; **Agents · Action ledger** tabs; `Admin/Governance/Agents` | SettingsLayout + Settings sub-nav ("Agents" item); no tabs | Different IA; no Action-ledger tab | (a)/(b) | Med |
| **Agent LIST (left)** | Selectable agents (Front-desk/Recall/Clinical-summary/Dispatch) + status chips (Draft only / Needs approval / Always reviewed / Paused) + counts + **[Add/New agent]** | **absent** — no agent list; a flat per-tool list instead | No per-agent master list / no agent entity | (b) | High |
| **Hero + live metrics** | Dark hero: **drafts today · approved-as-is % ring · fence-refused 7d** | **absent** | No metrics surfaced | (b) | Med |
| **Flow pipeline** | message → GROUNDED draft → **checked vs ceiling** → **the electric fence (fails closed)** → REQUIRED human review; "no setting here opens that gate" | **absent** (banner states the thesis in one line) | Missing the drawn guardrail diagram | (a)/(c) | Med |
| **Autonomy ladder** | L1 Draft-only (current) · L2 Auto-send-low-risk (available) · **L3 Fully-autonomous LOCKED** ("enforced in code") | Off/Suggest/Approve/**Auto** pills; levels > ceiling **locked** | Same MEANING, different shape (4 levels + Off vs 3 rungs); **both cap the top rung** | (c)/(d) | Med |
| **Tools it may call (whitelist)** | per agent: "3 of 5 enabled"; each tool = permission key + risk; enabled ones toggle; **out-of-ceiling tools LOCKED** (comms.send high; clinical.read outside-ceiling) | the tool list IS the page, but there is **no enable/disable toggle** and no per-agent whitelist — every governed tool is shown with its autonomy pills | No tool enable/disable; no agent→tool whitelist; risk labels absent | (b)/(c) | High |
| **Permission ceiling** | **read-only RBAC mirror** (patient.view consent-checked / appointment.propose / comms.draft allowed / comms.send human-sends / patient.edit denied / clinical.sign denied); "inherited from role — change the role to change the ceiling" | **absent** on this page (RBAC lives on `/admin/roles`; the per-tool `permission` is shown as a chip only) | Missing the read-only ceiling panel | (a)/(c) | Med |
| **Rate & timing limits** | max drafts/hour · quiet hours · **escalate-below-confidence** slider · **escalate-uncertainty always-on** | **absent** — no such settings exist | New configurable knobs; no backend | (b) | Med |
| **Fence vault ("not configurable")** | graphite card: ai-assisted labelling · human approves send · clinical always reviewed · consent-scoped · immutable ledger · re-grounded — "nothing here can weaken them" | the one-line banner asserts the cap; the invariants are enforced in code but **not drawn** as a vault | Missing the explicit invariant card | (a)/(c)/(d) | Low |
| **Action ledger tab** | a tab (per-call allowed/refused, immutable) | **absent** as a view (data exists: `ai_interactions` + Governance dashboard AI-usage) | Missing the ledger view here | (b) | Low |
| **Styling** | Eucalyptus Glow + **dark hero** + **graphite vault** + rings + risk-metered cards + `eucardIn` | glass Card + euca banner + pill level group + padlock; radius/pills match | dark-hero/vault/ring/pipeline visuals absent | (a) | Med |
| **New-agent flow** | [New agent] → a wizard (companion `New Agent Wizard.html` on disk) | **absent** (no agent entity to create) | Needs the agent entity first | (b) | Low |

---

## 3. Visual deltas to reach parity

Master-detail (agent list | agent detail); the **dark hero** with the approved-as-is **ring** + fence-refused
metric; the **flow pipeline** (message→grounded→ceiling→fence→human, refused branch shown); the **autonomy ladder**
as a visual ladder (current rung glows, top rung padlocked); **risk-metered tool cards** with lock states; the
**read-only permission-ceiling** panel; a **confidence-threshold slider**; and the graphite **fence vault**
(deliberately toggle-free, distinct from the green configurable sections). Reuse existing utilities (`.glass-card`,
`.euca-card-in`/`eucardIn`, `.nav-pill-active`, `.settings-surface`, status pills, `--color-danger`) — verify in
the browser before adding any "missing" style.

---

## 4. Feature / backend gaps (wireframe elements with no/partial live backend)

1. **The `Agent` entity (b) — the big one.** No agent row exists; autonomy is per-tool. A per-agent page (its own
   autonomy, tool whitelist, limits, metrics, status, add/new) needs a modelling decision (§6). Flag as its own
   gate.
2. **Tool enable/disable + per-agent whitelist (b/c).** `KillSwitch` (per-feature enable) exists but isn't wired
   here, and there's no agent→tool whitelist. Building a toggle must be **capped to ≤-ceiling tools** and a forged
   enable of a locked/out-of-ceiling tool refused server-side.
3. **Rate & timing limits (b).** max-drafts/hour, quiet-hours, escalate-below-confidence — **no settings exist**
   (grep clean). New tenant settings the runtime must actually read (else they'd be faked knobs). Its own gate.
4. **Hero live metrics (b).** drafts-today / approved-as-is % / fence-refused-7d — derivable from `ai_interactions`
   + `agent_actions` (the Governance dashboard already computes similar), but not surfaced here. Aggregate queries.
5. **Action-ledger view (b).** the `ai_interactions` ledger is real + append-only; a per-agent ledger tab is a new
   read view.
6. **Flow-pipeline + fence-vault + permission-ceiling panels (a/surface-it).** No backend needed — they SURFACE
   real, already-enforced invariants (the electric fence, the RBAC ceiling). Presentational.

---

## 5. Governance / fence verification (enforced server-side? surfaced? — match visual, keep the cap)

| Gate | Enforced server-side? (code path) | Surfaced? (browser) | Verdict / rule |
|---|---|---|---|
| **Autonomy ladder ≤ ceiling; top locked** | **YES.** `AutonomyPolicy::cap()` clamps to the tool's `autonomyCeiling`; clinical/financial hard-cap at `approve`; `set()` applies the clamp on write (a forged higher level persists as the ceiling — locked by `AgentAutonomyTest`, SETTINGS.P2). `effectiveCeiling()` is the read-only cap. | **YES** — verified: clinical rows lock Approve+Auto, financial/operational lock Auto; the server "enforces every ceiling" banner. | **Solid cap.** The agent-shaped ladder must map to the SAME per-tool `cap()`; keep clamp-on-write. Never offer a rung the runtime wouldn't honor. |
| **Tool whitelist / enable capped to ≤ ceiling** | **PARTIAL** — per-tool autonomy is capped; there is no enable/disable here (`KillSwitch` is per-feature, elsewhere). | **NO** (no toggle). | **Feature gap + fence-critical.** If built: enabling is capped to ≤-ceiling tools; a forged enable of a locked/out-of-ceiling tool is refused server-side. Don't let the whitelist grant a capability past the cap. |
| **Permission ceiling = read-only RBAC mirror** | **YES.** Each tool declares a `permission`; `ApprovalQueue::approve` re-authorises against it (a reviewer/agent lacking it → 403/fail-closed). RBAC roles are edited on `/admin/roles`. | **PARTIAL** — the per-tool `permission` chip shows; the read-only ceiling panel isn't drawn. | **Reflect-only.** Surface it read-only (role-derived); never make the ceiling editable here (mirror the Admin Settings RBAC-reflect-only discipline). |
| **Rate/timing limits real** | **NO** — no such settings exist. | **NO.** | **Backend gap.** If built, they must be settings the runtime READS. **"Escalate uncertainty"** should map to the REAL clinician-attention hand-off (SETTINGS.P5 `clinician_attention_at`, the Inbox agent's clinical refusal) — **always-on, not disableable** (it's the fence, not a knob). |
| **Fence vault invariants** | **YES, all real + in code:** output labelled `AI draft — requires human review` (AiInteractionRecorder); a human approves every send (`ApprovalQueue`/DraftReplyTool posts as the human, `ai_assisted`); clinical output always reviewed (clinical tools cap at suggest; InboxAgent refuses clinical → handoff); consent-scoped reads (patient `auditRead`/consent gates); append-only `ai_interactions` (DB triggers block UPDATE/DELETE); re-grounding at run (`preview()` re-runs on approve). Locked by the `tests/Evals/` suite. | **PARTIAL** — the banner asserts the cap; the vault card isn't drawn. | **Surface-it, toggle-free.** Render the invariants as a non-configurable card; NO control on the page may weaken them (by design). |
| **Fence-refused metric + ledger** | **YES.** The P5 `AgentAction::STATUS_FENCE_REFUSED` + the append-only `ai_interactions` ledger + audit. | **NO** (not on this page; the Governance dashboard shows AI-usage/fence counts). | **Surface-it (real data).** The hero fence-refused count + the ledger tab read real records. |

---

## 6. THE MODELLING DECISION (surface, do NOT make)

**Is "agent" a real entity, or only per-tool autonomy?** Today: **per-tool** (`ai.autonomy.<tool.key>`); agents are
code classes with no configurable DB row. The wireframe's per-agent page (an agent with its OWN autonomy level,
tool whitelist, rate/timing limits, metrics, status, and an add-new flow) implies a **new `Agent` modelling
layer**. Options — a product/architecture decision, its own gate:

- **(a) Agent-shaped VIEW over the existing per-tool `AutonomyPolicy`** (no new entity): define agents as a fixed
  mapping of the code agents → their tool sets (e.g. Front-desk → `comms.draft_reply`/`comms.classify_document`;
  Scheduler → `scheduler.*`; …), and render the per-agent page as a grouping of the real per-tool autonomy + the
  real per-tool ceilings. Autonomy stays per-tool under the hood (the cap is unchanged); "agent autonomy" = the
  group's controls. Cheapest; no new writable state; keeps the P2 cap authoritative. Rate/timing limits + metrics
  are still separate gaps.
- **(b) A real `Agent` entity** (its own autonomy/whitelist/limits/enabled, per tenant): a genuine new backend
  (table + policy + runtime wiring + audit + tests). Richer, matches the wireframe literally, but a large gate —
  and every new control MUST stay capped (agent autonomy still clamped by the per-tool/clinical-financial caps;
  the whitelist still ≤ ceiling; the permission ceiling still role-derived read-only).
- **(c) Hybrid:** ship the agent-shaped VIEW (a) now (surfacing existing gates: ladder, ceiling, fence vault,
  fence-refused metric from real data), and queue the writable extras (tool whitelist toggle, rate/timing limits,
  action-ledger view) as separate backend gates.

**Rule regardless of choice:** the cap stays server-enforced (clamp-on-write), the permission ceiling stays
read-only/role-derived, the fence vault stays toggle-free, and "escalate uncertainty" stays always-on. The config
UI must never be able to raise autonomy past a ceiling or grant a capability past the cap.

---

## 7. Correctly-more-real items (keep — do NOT trim to the wireframe)

- **The real per-tool cap** — clinical→suggest, clinical/financial→approve, per-tool `autonomyCeiling`, **clamp on
  write** (a forged higher level can't persist). The wireframe's ladder is a nicer face on this; keep the clamp.
- **The 10 real governed tools** from `ToolRegistry::all()` with real `permission` + `category` (the wireframe
  mocks 4 agents × a few tools). Real set, real keys.
- **The electric-fence invariants** actually enforced in code + locked by `tests/Evals/` (not just drawn).
- **The P5 `fence_refused` status + append-only `ai_interactions` ledger + audit** — real governance data behind
  the metrics/ledger.
- **RBAC lives on `/admin/roles`** (the ceiling is genuinely role-derived) — reflect-only here.

---

## 8. Prioritized parity punch-list

**Surface existing gates first (safe, presentational); modelling + writable knobs are decisions/gates; fence-
critical items called out.**

1. **[Decision · b] The modelling call (§6)** — agent-shaped VIEW over per-tool `AutonomyPolicy` (a) vs. a real
   `Agent` entity (b) vs. hybrid (c). Everything agent-centric depends on this. Recommend (c): view now, writable
   extras as gates.
2. **[Med · a/surface-it] Agent-shaped presentation over the existing cap** — master-detail (agent list | detail),
   the autonomy ladder mapped to the real per-tool `cap()`, the read-only permission-ceiling panel, the flow
   pipeline, and the graphite **fence vault** (toggle-free) surfacing the real invariants. No backend beyond the
   agent→tool grouping. **Fence-critical:** the ladder offers only ≤-ceiling; clamp-on-write unchanged.
3. **[Med · b/surface-it] Hero metrics + fence-refused + action-ledger tab** — from the real `ai_interactions` +
   `agent_actions`/`fence_refused` data (aggregate queries; the Governance dashboard already computes similar).
4. **[High · b/c fence-critical] Tool enable/disable + per-agent whitelist** — wire `KillSwitch` (or a whitelist)
   with enabling **capped to ≤-ceiling tools**; a forged enable of a locked tool refused server-side. Its own gate.
5. **[Med · b] Rate & timing limits** — new tenant settings the runtime actually reads (max-drafts/hour,
   quiet-hours, confidence-escalation). **"Escalate uncertainty" maps to the real always-on clinician-attention
   hand-off (SETTINGS.P5), not a disableable knob.** Its own gate.
6. **[Low · b] New-agent wizard** — needs the `Agent` entity (option b) first; companion `New Agent Wizard.html`
   on disk.
7. **[Keep · d]** the real per-tool cap + clamp, the 10 governed tools, the enforced fence invariants, the P5
   fence_refused/ledger, RBAC-reflect-only.

**Fence guard for the whole page:** match the wireframe's governance visual while keeping every cap server-enforced
— the autonomy ladder ≤ ceiling (clamp on write), the tool whitelist ≤ ceiling, the permission ceiling read-only
(role-derived), the fence vault toggle-free, escalation always-on. Where the wireframe implies a control that would
weaken a cap if built naively, it stays **capped/read-only**; where it needs new state (agent entity, limits,
whitelist), that's a flagged decision / separate gate — never a faked or cap-weakening control.

---

## 9. Parity progress (RESOLVED status per punch-list)

Updated as AGENT.P1–… land. One commit per part.

| Punch-list item | Status | Commit |
|---|---|---|
| 1 · The modelling decision (agent entity vs. view) | **RESOLVED (P1)** — chose a real `Agent` entity built as a GOVERNED CONTAINER (option b), with the capped resolver so it only narrows | `AGENT.P1` |
| (foundation) · Agent entity + capped effective-level resolver | **RESOLVED (P1)** | `AGENT.P1` |
| 2 · Agent-shaped presentation over the cap | **RESOLVED (P2)** — agent list + per-agent detail shell + the autonomy ladder, over the P1 resolver | `AGENT.P2` |
| 3 · Hero metrics + fence-refused + action-ledger tab | **RESOLVED (P2 shell + P5 real data)** — per-agent hero metrics (drafts today / approved-as-is % / fence-refused 7d) + the action-ledger tab, ALL computed from the real ai_interactions ledger + ApprovalQueue outcomes; honest "—" where no source; read-only view of the immutable ledger | `AGENT.P2` / `AGENT.P5` |
| 4 · Tool enable/disable + per-agent whitelist (capped) | **RESOLVED (P1 backend + P4 UI)** — the whitelist store + narrowing resolver (P1) + the editable "N of M enabled" panel: remit tools toggle, out-of-remit LOCKED, forged enable dropped, whitelisting never grants past ceiling (P4) | `AGENT.P1` / `AGENT.P4` |
| 7 · Permission-ceiling mirror (read-only RBAC reflection) | **RESOLVED (P3)** — per-agent exercised/withheld permissions, role-derived, reflect-only (no edit path); links to Roles | `AGENT.P3` |
| 8 · Electric-fence vault ("not configurable") | **RESOLVED (P3)** — 6 code-enforced, eval-locked invariants displayed toggle-free (no disable path) | `AGENT.P3` |
| 5 · Rate & timing limits | **RESOLVED (P6)** — max drafts/hour + quiet hours are REAL settings the AgentRuntime CONSULTS (a hit stops drafting / defers to a human); the escalate-below-confidence threshold is honestly DEFERRED (no confidence signal — rendered read-only "planned", not a phantom control) | `AGENT.P6` |
| (5b) · "Escalate uncertainty — always on" | **RESOLVED (P6)** — maps to the REAL clinician-attention hand-off; locked-on, NO disable path (the floor is un-removable) | `AGENT.P6` |
| 6 · New-agent wizard | **RESOLVED (P6)** — creates a P1 governed container of a REAL kind, capped from birth (effective = MIN from creation; a forged create above a ceiling is clamped; kind constrained to real code-class capabilities) | `AGENT.P6` |

**ALL PUNCH-LIST ITEMS RESOLVED — the Agent & Tool Config page reaches wireframe parity (P1 → P6).** The chain:
P1 entity + capped resolver → P2 list + ladder → P3 permission mirror + fence vault → P4 tool whitelist →
P5 metrics + action ledger → P6 rate/timing limits + always-on escalation + new-agent wizard. FOUR pages of the
wireframe-parity pass are now complete (Admin Settings · Approval Queue · Branches · Agent & Tool Config). Next:
Allergy Alert (the safe, record-not-compute part).

**P1 note — the Agent entity + the capped effective-level resolver (the safety foundation, NO UI):**
- **Entity** (`Modules\AiCore\Models\Agent`, `agents` table, `BelongsToTenant`): per-tenant, maps to a real
  code-class agent (`key`), with a CONFIGURED `autonomy_level`, a `status` (active/paused), and a `tool_keys`
  whitelist. Additive migration + **backfill for existing tenants**; **new tenants seeded via a `Tenant::created`
  hook** (app-layer, alongside the RBAC provisioner) — the 6 canonical agents (`AgentRegistry`: inbox / scheduler /
  recall / clinical_summary / dispatch / billing) covering all 10 real governed tools.
- **THE CAPPED RESOLVER** (`AgentResolver::effectiveLevel`): an agent's effective autonomy for a tool =
  **MIN(configured, tool ceiling [`AutonomyPolicy::effectiveCeiling`], role ceiling)**. Config can only NARROW; it
  never raises past the tool ceiling or the role RBAC ceiling. A paused agent or a non-whitelisted tool → OFF
  (not callable). Wired into `AgentRuntime` (optional `?Agent` param — additive; null = the existing per-tool path,
  unchanged): with an entity, the runtime acts on the capped level; the role ceiling is OFF when the acting user
  lacks the tool's permission.
- **CLAMP-ON-WRITE + CLAMP-AT-RUNTIME (defense in depth):** `AgentConfigService::configure` (app-layer, audited
  `agent.configured`) clamps on write (valid enum, valid status, whitelist restricted to REAL tool keys); the
  resolver clamps at call time regardless — a **forged** stored `auto` or a whitelisted out-of-ceiling tool still
  resolves to the capped level (proven).
- **The agent sits UNDER `AutonomyPolicy` + the role ceiling + the fence — none weakened.** The per-tool
  SETTINGS.P2 cap is unchanged and is one of the min() terms; the electric-fence eval + AgentAutonomyTest +
  ApprovalQueue suites stay green. **NO UI this gate** (P2+ builds the surfaces; RBAC admin.manage is applied by the
  P2 config controller — no route yet). Locked by `tests/Feature/AiCore/AgentEntityTest.php` (7).

**P2 note — the agent list + per-agent detail shell + the AUTONOMY LADDER (presentational, over the P1 resolver):**
- **The page** (`/governance/agents`, `AgentConfigController`, `Governance/Agents.vue`, `admin.manage`,
  tenant-scoped, agents resolved by string id → cross-tenant = 404): a LEFT agent list (the tenant's real `Agent`
  rows — name/key, status chip, configured-level pill) → selects into a per-agent DETAIL shell. Tabs
  (Agents · Action ledger — the ledger content is P5; the tab shell exists with an honest "no placeholder numbers"
  note).
- **The detail shell:** a dark hero (agent identity + status + effective ceiling + configured level + tool count;
  live activity metrics deferred to P5 with an honest note — no faked numbers) + a presentational **flow pipeline**
  describing the REAL runtime path (message → grounded draft → checked-vs-ceiling → the electric fence → human
  review; no new logic).
- **THE AUTONOMY LADDER (the governance control):** renders the real `off/suggest/approve/auto` rungs but offers
  ONLY levels ≤ the agent's **effective ceiling** — `AgentResolver::agentCeiling()` = **MIN of the tool ceilings the
  agent touches** (per the P1 resolver; the runtime further narrows by the role ceiling + fence). Higher rungs
  render LOCKED with the reason. Selecting a level + a status active/paused toggle writes through
  `AgentConfigService::configure` (P1 clamp-on-write + audit `agent.configured`).
- **THE CAP (proven):** the ladder cannot set an agent above its effective ceiling — the UI offers only ≤-ceiling
  rungs AND `AgentConfigController::configure` **CLAMPS a forged higher level to the ceiling server-side** before
  persisting; the resolver caps again at call time (P1). Browser-verified: a forged `POST autonomy_level=auto` to
  the inbox agent (ceiling suggest) persisted as `suggest`, not `auto`. `AgentConfigService` (P1) itself is
  UNCHANGED — the ceiling clamp lives in the P2 controller, so no P1 behaviour test is modified.
- **PURELY additive over P1** — no whitelist edit (P4), no metrics/ledger data (P5), no rate/timing limits (P6). The
  per-tool SETTINGS.P2 card is unaffected; the fence/AutonomyPolicy/role ceiling are not weakened. Locked by
  `tests/Feature/Governance/AgentConfigTest.php` (8) + P1's `AgentEntityTest` still green.

**P3 note — the two REFLECT-ONLY / TOGGLE-FREE panels (real gates surfaced, nothing weakened):**
- **The permission-ceiling MIRROR** (`AgentConfigController::permissionMirror`): a READ-ONLY reflection of the real
  RBAC + tool permissions, per agent. Two groups, both derived from LIVE data (never fabricated): `exercised` = the
  distinct real `permission` of each whitelisted tool (the EXACT `Gate::allows($tool->permission)` targets that
  `ApprovalQueue::approve` re-authorises at approve — so the ceiling is role-derived); `withheld` = the sensitive
  HUMAN-ONLY permissions (note.sign / patient.edit / medication.prescribe / allergy.override) — each included ONLY
  after verifying **no registered tool carries it** (so "denied" is derived from the registry, not invented). NO
  write path on this page; caption "inherited from the role — change the role to change the ceiling" links to the
  real Roles surface (`/admin/roles`). Structurally reflect-only: the only `governance.agents.*` routes are `index`
  (GET) + `configure` (POST autonomy_level/status) — the configure endpoint IGNORES any forged permission/tool_keys
  body, so the mirror cannot be edited here.
- **The electric-fence VAULT** (`AgentConfigController::FENCE_INVARIANTS`): 6 CODE-ENFORCED, eval-locked invariants
  shown TOGGLE-FREE (the card has zero interactive controls — browser-verified). Each is genuinely enforced + locked
  by the eval harness (`tests/Evals/`, 37 cases):
  1. **AI output is labelled** — `AiInteractionRecorder::LABEL = 'AI draft — requires human review'` forced on every
     row (`AiInteractionRecorder.php:12,:54`) + DB default; `DraftReplyTool` posts `aiAssisted:true`. Eval:
     `CrossCuttingAgentEvalTest.php:87`.
  2. **Human approves before send** — `DraftReplyTool::execute` throws without a human sender (`:54-57`); posts as
     the human; ceiling `suggest`; approve re-authorises + executes (`ApprovalQueue.php:78-103`). No auto-send tool.
     Eval/feature: `ClinicalAgentsTest.php:281-293`.
  3. **Clinical never autonomous** — `AutonomyPolicy::cap` clamps clinical/financial ≤ approve (`AutonomyPolicy.php:65-67`);
     clinical tools ceiling `suggest`; interpretive/diagnostic requests refused (`ClinicalSummaryAgent`/`InboxAgent`).
     Eval: `CrossCuttingAgentEvalTest.php:53-77`, `InboxAgentEvalTest.php:87-110`.
  4. **Consent/tenant-scoped** — `TenantScope` fail-closed (throws when unscoped, `TenantScope.php:21-39`) +
     `BelongsToTenant`; messaging blocked without consent (`ClinicalAgentsTest.php:281-283`).
  5. **Append-only ledger** — model guards + DB triggers (SIGNAL 45000) on `ai_interactions`
     (`AiInteraction.php:64-73` + migration `:40-47`) and `audit_events` (`AuditEvent.php:52-58` + migration
     `:18-25`); hash-chain `verifyChain` (`AuditService.php:121-136`).
  6. **Re-authorise + re-ground at approve** — `ApprovalQueue::approve` re-checks the tool permission (`:81`,
     `authorize :268-273`) and re-executes/re-grounds from live state (`:103`; `DraftReplyTool::preview`); an edit is
     not a bypass. Eval/feature: `ApprovalFenceRefusedTest.php:100-131`.
  There is NO route/action to disable any invariant (structurally confirmed — no `permission|fence|invariant` route
  under `governance.agents`). Locked by `tests/Feature/Governance/AgentConfigTest.php` (+5 P3 tests).

**P4 note — the EDITABLE tool whitelist (narrows the callable set; whitelisting never grants past the ceiling):**
- **The panel** (`AgentConfigController::toolWhitelist`, rendered "tools it may call · N of M enabled"): the agent's
  CANDIDATE remit tools (`AgentRegistry::AGENTS[key]['tools']`, registered + ceiling above OFF) are toggle-able
  (enabled = in `tool_keys`); every OTHER governed tool (minus `demo.*`) renders **LOCKED** ("outside this agent's
  remit"). Toggling posts `tool_keys` to the existing `configure` endpoint.
- **The write path** (`configure` extended to validate `tool_keys` array): the requested keys are intersected with
  the agent's candidate set (`candidateToolKeys`) — a forged enable of a locked (out-of-remit), unregistered, or
  non-callable tool is **DROPPED here** — then written through `AgentConfigService::configure` (P1 clamp: real
  registered keys only + audit `agent.configured`). `AgentConfigService` (P1) is UNCHANGED — the remit clamp lives
  in the P4 controller, so P1's test (which stores a real out-of-remit key via the service directly) still passes.
- **THE CAP (proven):** whitelisting changes the CALLABLE SET, never the AUTHORITY. Disabling a tool → the resolver
  returns OFF for it (narrowed, P1). A forged `tool_keys` including a locked/unregistered key is dropped server-side
  (browser-verified: a POST of `[comms.draft_reply, billing.preflight_invoice(out-of-remit), not.a.real.tool]` to the
  inbox agent persisted as `[comms.draft_reply]` only). A whitelisted tool is STILL capped at runtime — effective =
  MIN(config, ceiling, role) (browser/test-verified: scheduler configured AUTO + whitelisted → effective APPROVE,
  not AUTO). Enabling never raises a tool past its ceiling.
- **The P3 mirror reflects the real updated whitelist** (browser-verified: disabling comms.classify_document dropped
  `note.write` from the mirror's exercised set) — still read-only. Locked by `tests/Feature/Governance/AgentConfigTest.php`
  (+5 P4 tests). No existing behaviour changed; fence eval + P1/P2/P3 + AutonomyPolicy + ApprovalQueue stay green.

**P5 note — per-agent hero metrics + the action-ledger tab (REAL data only; no faked numbers):**
- **Attribution** — the `agent` column on `ai_interactions`/`agent_actions` is a free-form string with NO FK to
  `Agent::key`; the codebase writes it two ways (bare key from seeders/direct ApprovalQueue calls, e.g. `'inbox'`;
  the code-class const from production agents, e.g. `InboxAgent::AGENT = 'inbox-agent'`). New
  `AgentRegistry::LEDGER_ALIASES` is the single source of truth mapping each canonical agent → the ledger string(s)
  that belong to it, so metrics are a real `WHERE agent IN (...)`, never a guessed join.
- **The hero metrics** (`App\Services\AgentMetricsService::hero`) — each a REAL count or honestly absent:
  `draftsToday` = `ai_interactions` outcome `proposed` today; `fenceRefused7d` = `agent_actions` status
  `fence_refused` in 7d (danger-tinted); `approvedAsIsPct` = executed-without-edit (`edited_payload IS NULL`) / total
  resolved — **null → "—" when nothing is resolved yet** (never a fabricated 0/100).
- **The action-ledger tab** (`AgentMetricsService::ledger`) — the P2 tab shell now shows the real, newest-first rows
  of the append-only `ai_interactions` ledger (tenant-scoped): agent (canonical label via the alias map), tool,
  outcome, and — for a `fence_refused` row — the fence's own reason + a system tag. A **read-only VIEW of an
  immutable table** (no edit/delete route; the model + DB triggers block UPDATE/DELETE); client-side agent filter.
- **THE HONESTY RULE (proven)** — every metric + row is a real record. Browser-verified on the demo tenant:
  inbox = 2 drafts / 0% approved-as-is / 1 fence-refused; scheduler = 1 draft / **"—"** approved-as-is / 0 fence;
  billing = honest zeros. The ledger listed the real `fence_refused` row (system-attributed, real reason). The
  fence_refused was **seeded through the real path** (DemoClinicSeeder proposes a non-groundable draft then approves
  it → the fence fires and is recorded) — not hand-inserted. Locked by `tests/Feature/Governance/AgentConfigTest.php`
  (+6 P5 tests). No governance guarantee weakened; one demo-fixture thread count in `DemoClinicSeederTest` (3→4) was
  updated to reflect the added fence thread (a fixture reconciliation, not a behaviour change).

**P6 note — rate/timing limits (runtime-read) + always-on escalation (no disable) + new-agent wizard (capped from birth):**
- **The limits are REAL settings the runtime reads** (`AgentRuntime::runTool`, on the Agent-entity path): `max_drafts_per_hour`
  (the runtime counts the agent-kind's drafts in the rolling hour via `AgentRegistry::ledgerNames` and STOPS drafting
  at the cap → `rate_limited`) and `quiet_hours_start`/`quiet_hours_end` (a real hour-of-day window → `quiet_hours`).
  A limit can only STOP the agent (defer to a human), never widen it. Stored per-agent (nullable), validated to
  bounds + audited (`AgentConfigService`). Browser + test verified the runtime genuinely consumes each.
- **The escalate-below-confidence threshold is honestly DEFERRED** — the codebase has NO confidence/uncertainty
  signal, so wiring a numeric threshold would be a phantom control (the SETTINGS.P3 discipline). It renders read-only
  "planned", not an editable knob; nothing is persisted that the runtime ignores.
- **"Escalate uncertainty — always on"** maps to the REAL clinician-attention hand-off (documented in
  `NotificationSettingsController`: "there is no path to suppress them"). It renders LOCKED-ON with no toggle; there
  is NO route/action to disable it (structurally confirmed — no `escalat|disable|confidence` route under
  `governance.agents`; the configure endpoint ignores any forged disable field), so the floor is un-removable.
- **The new-agent wizard** (`AgentConfigController::store`, admin.manage, tenant-scoped, audited `agent.created`):
  creates a P1 governed container of a REAL kind (a new `kind` column separate from the unique `key`; the kind must
  be an `AgentRegistry::AGENTS` key — you cannot create a non-existent capability). It is **capped from birth** —
  whitelisted to the kind's real remit, and a requested autonomy is clamped to the new agent's effective ceiling
  (the resolver caps it at runtime too). Browser + test verified: a forged `autonomy_level=auto` on a billing-kind
  agent was created at `approve` (never above the ceiling); effective = MIN. Locked by
  `tests/Feature/Governance/AgentConfigTest.php` (+7 P6 tests). No existing behaviour weakened; the `kind` column
  backfills = `key` so P1–P5 (seeded agents, kind === key) are unchanged.
