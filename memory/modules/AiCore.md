# Module: AiCore (`Modules\AiCore`)

## Purpose

The AI/agent layer for CareOS: a custom provider-agnostic **LlmManager**-style HTTP layer
(Anthropic primary) with a cost ledger, budget gate, circuit breaker, and a versioned prompt
registry — NOT a framework AI SDK. All AI output is **draft-until-approved**, visibly labeled,
and logged; `ai_interactions` is append-only. The **ELECTRIC FENCE** applies: no diagnosis,
triage, symptom assessment, or dosing logic anywhere.

## Key tables

- `ai_interactions` - tenant-owned append-only ledger. ULID id, `tenant_id`, feature, agent,
  provider, model/version, prompt hash, token and integer minor-unit cost estimate, tool calls,
  output ref, approver, latency, outcome, visible label, error/metadata, occurred timestamp.
  DB triggers block UPDATE and DELETE.
- `agent_actions` - tenant-owned approval queue. ULID id, tenant, related interaction, feature,
  agent, tool key, autonomy level, status (`pending`/`executed`/`rejected`/`fence_refused`),
  proposer/reviewer, approve/reject/execute/fence-refused timestamps, rejection reason (also holds
  the fence's reason on a fence_refused row), why/diff/input/proposed output/edited payload/result.
- `kb_articles` - tenant-owned active/inactive KB content for Front-Desk answers: title, body,
  tags, active flag, timestamps.
- `kb_embeddings` - tenant-owned portable vector-as-JSON embeddings for KB articles, keyed by
  article/model with a content hash.

## Key classes

- `Services\LlmManager` - provider-agnostic HTTP gateway; Anthropic is configured first, with
  timeout/retry/circuit-breaker and budget checks before outbound calls.
- `Services\BudgetGate` - checks tenant setting `ai.monthly_budget_minor` against current-month
  `ai_interactions.cost_minor` before calls.
- `Services\CircuitBreaker` - tenant/provider/feature cache-backed breaker; repeated failures open
  the circuit and route to manual.
- `Services\PromptRegistry` / `PromptVersion` - prompts as code with hash-pinned versions and a
  minimal eval-passed gate.
- `Services\ToolRegistry`, `Contracts\AiTool`, `Tools\EchoTool` - declared tool capabilities with
  schema, RBAC permission, category, and reversibility.
- `Services\AutonomyPolicy` - off/suggest/approve/auto dial, default suggest, clinical/financial
  hard-capped at approve.
- `Services\ApprovalQueue` and `Services\AgentRuntime` - propose/approve/edit/reject/execute flow
  around the demo echo tool.
- `Agents\FrontDeskAgent` - KB-only answer/escalate/refuse path for front-desk FAQ.
- `Retrieval\KbEmbeddingService` and `Retrieval\KbRetriever` - deterministic portable embeddings,
  cosine scoring in PHP, plus lexical support before any answer.
- `App\AiCore\Tools\FillFromWaitlistTool` - governed Scheduler Agent tool that proposes matching
  waitlist fills and books only after approval via Scheduling's `WaitlistService`.
- `App\AiCore\Tools\SuggestSlotsTool` - governed Scheduler Agent tool that proposes available
  slots from Scheduling's safe slot finder and never books.
- `App\AiCore\Agents\ClinicalSummaryAgent` / `Tools\ClinicalSummaryTool` - governed clinical
  Summary agent path. It is extractive/source-linked only, refuses interpretive requests, and is
  capped at `suggest`.
- `App\AiCore\Agents\FollowUpAgent` / `Tools\DraftRecallMessageTool` - governed clinical
  Follow-up agent path. It drafts recall wording only from deterministic recalls plus clinician
  templates, checks `comms.email` consent on approval, and is capped at `suggest`.
- `App\AiCore\Support\ClinicalSummarySourceValidator` - rejects any Summary line without a source
  resolving to that same patient's signed note SOAP field or clinical-list row.
- `App\AiCore\Agents\DispatchAgent` / `Tools\NursingProposeAssignmentsTool` /
  `Tools\NursingReplanDayTool` - governed Nursing dispatch agent path. It proposes visit-to-nurse
  assignments and replans only after deterministic Nursing validator acceptance, creates approval
  queue items, and executes assignments only on human approval.
- `App\AiCore\Support\NursingDispatchProposalEngine` - app-layer composition that binds dispatch
  proposals to Nursing's `AssignmentValidator` without adding cross-module dependencies inside
  AiCore.
- `App\AiCore\Agents\BillingAgent` / `Tools\SuggestChargeCodesTool` / `Tools\PreflightInvoiceTool` -
  governed Billing agent path (P0F.G8). Maps documented services to tariff codes as source-linked
  suggestions and explains deterministic F.3 validation results; both tools are FINANCIAL category
  with explicit `approve` ceilings.
- `App\AiCore\Support\BillingCodeSuggestionEngine` - app-layer composition that source-links every
  billing suggestion to real documented text (signed encounter note SOAP sections or completed-visit
  `visit_notes`) and resolves every code through `TariffResolver` at the service date; unsourced or
  unresolvable suggestions throw before the approval queue.
- `App\AiCore\Agents\InboxAgent` / `Tools\DraftReplyTool` / `Tools\ClassifyDocumentTool` - governed
  Inbox agent path (P0G.G6). DRAFT-ONLY, ceiling `suggest` on both tools; drafting never posts and
  classification never files without human confirmation; the patient match is never auto-applied.
- `App\AiCore\Support\InboxDraftEngine` - grounds every draft line in exactly three sources (thread
  history, active KB articles, the patient's own admin facts recomputed live) and throws in code on
  any unsourced/unresolvable claim; ungroundable asks are handoffs, never guesses.
- `Events\AiInteractionRecorded` and `Events\AgentActionLifecycleChanged` - app-layer audit glue
  records ledger/action paths into the audit chain without AiCore depending on Audit.

## Invariants

- No real LLM calls in tests; HTTP is faked.
- Provider keys come only from config/env and are never stored in `ai_interactions`.
- `ai_interactions` is append-only at both model and DB-trigger levels.
- Budget exhaustion records `budget_blocked`, sends no HTTP request, and routes to manual.
- Circuit-open state records `circuit_open`, sends no HTTP request, and routes to manual.
- Every AI output includes the visible label `AI draft - requires human review` plus human handoff.
- Kill switch setting `ai.feature.<feature>.enabled=false` disables a feature fail-closed and still
  writes ledger/audit records.
- Tool autonomy defaults to suggest. Clinical/financial tools cannot be set above approve.
- Demo echo tool requires `ai.manage`; `ai.manage` is in the RBAC catalog and org-admin role.
- Scheduler tools require `appointment.manage`, are capped at `approve`, and create approval-queue
  items; waitlist booking happens only when a human approves.
- Front-Desk KB answers may run automatically only when grounded in the current tenant's active KB
  and a retrieved article has lexical support; unknown questions escalate with no answer.
- Front-Desk medical/symptom/triage/dosing questions are refused and handed off.
- KB retrieval never crosses tenants and ignores inactive articles.
- Clinical Summary and Follow-up tools have explicit `suggest` ceilings; attempted approve/auto
  settings degrade to suggest.
- Clinical Summary never writes to the clinical record; only the clinician acceptance controller
  can insert an already source-validated draft into an editable note.
- Follow-up never selects recall recipients; recipients come from deterministic D.5 `RecallEngine`
  rows, and approval without `comms.email` consent returns blocked/no-send.
- Nursing Dispatch tools require `dispatch.manage`, are operational, and have explicit `approve`
  autonomy ceilings; attempted `auto` settings degrade to `approve`.
- Nursing Dispatch proposals are logistics-only: qualification codes, time windows, straight-line
  travel, and hour caps. Clinically framed prioritization requests are refused with human handoff,
  write `ai_interactions`, and create no `agent_actions`.
- Invalid Nursing Dispatch proposals are rejected before approval queue creation and recorded as
  `invalid_proposal`; pending proposals do not assign anything. Approval executes through
  `VisitAssignmentService::assign()`.
- Billing agent tools require `billing.manage`, are FINANCIAL category (hard-capped at `approve` by
  `AutonomyPolicy::cap()` even when `auto` is requested), and create approval-queue items; nothing is
  captured or issued while pending or rejected.
- Billing suggestion prices are NEVER trusted: preview prices come from the resolved tariff item and
  approval captures through `ChargeCaptureService`, which re-resolves the tariff itself — an
  agent-claimed `unit_price_minor` never reaches a charge row.
- Billing preflight mirrors the deterministic F.3 `ChargeValidator` exactly: reported violations are
  copied verbatim from the validator output; `llm_claims` in the input are counted and discarded.
  It never issues an invoice — issuing stays human through `IssueService` (fuzz-tested, D-058).
- Billing agent refuses clinically framed questions (treatment appropriateness, alternatives,
  patient condition) with human handoff, writes a `refused` `ai_interactions` row, and creates no
  `agent_actions`. Reads are patient-scoped read-logged with surface `billing_agent`.
- Inbox agent (D-065/D-G5): `comms.draft_reply` requires `comms.manage`, `comms.classify_document`
  requires `note.write`; both ceilings are `suggest` (auto/approve degrade). Clinical patient
  messages are refused BEFORE any tool runs: zero draft content anywhere, handoff note, thread
  flagged (`clinician_attention_at`), `refused` ledger row. A sent draft posts through
  `ThreadService` with `ai_assisted=true` and the HUMAN as author; classification files only the
  CATEGORY via `DocumentService::reclassify` and never moves a document between patients.
- AiCore may use Platform for tenant/settings/RBAC primitives; it does not depend on Audit or domain
  modules. Audit composition lives in `app/`.
- **Agent safety eval suite (P0P.G4, D-071):** `tests/Evals/` is a first-class named suite (`Evals`
  phpunit testsuite; `composer eval` = `pest --testsuite=Evals`; also runs inside `composer check`'s
  full Pest run). One file per agent + `CrossCuttingAgentEvalTest` + `Support/EvalHarness.php` (shared
  `ev*` primitives; not a `*Test.php` file). 37 evals / 398 assertions LOCK the fence/autonomy/
  grounding/"never trust the agent's numbers" rules as regression tests. Deterministic, mocks the LLM
  with fixed inputs, `evNoNetwork()` guarantees no real API call. It LOCKS existing behavior — never
  changes it. `docs/AGENT-EVALS.md` maps every locked property to its enforcing eval.

## Status

**Phase C COMPLETE / active; Phase D clinical agents, Phase E Dispatch agent, and Phase F Billing
agent added.** The governed runtime foundation runs Scheduler Agent, Front-Desk Agent, D.8 clinical
Summary + Follow-up agents, E.9 Nursing Dispatch proposals, and F.8 Billing map + preflight
suggestions. Local `composer check` is green: 358 tests / 2073 assertions.

## Approval-queue UI (CLINIC.W9)

The approval queue now has an admin surface — an app-layer `App\Http\Controllers\AiApprovalQueueController`
(`/governance/approvals`, `ai.manage`) that lists PENDING `agent_actions` and approves/rejects them ONLY through
`Services\ApprovalQueue::approve/reject` (never reimplemented). It adds NO new autonomy, NO create/propose route,
and never sets an autonomy level — the queue only holds items `AutonomyPolicy` already routed to human approval.
The cap binds server-side: `ApprovalQueue` re-authorizes the reviewer against the TOOL's own permission before
execute, so a reviewer with `ai.manage` but lacking a tool's permission is DENIED (403); the controller catches only
`AiCoreException` and lets `AuthorizationException` propagate. Reject executes nothing; approve runs only
`tool->execute()`; both are audited by the existing `AgentActionLifecycleChanged` glue (the controller adds no
audit). Actions resolve by string id (FIX.1) → cross-tenant/missing = 404. The paired Governance dashboard
(`audit.view`, read-only) surfaces the pending-action depth + AI-usage from the append-only `ai_interactions`
ledger + kill-switch state. Locked by `tests/Feature/Governance/AiApprovalQueueTest.php` (incl. the cap-binds-via-UI
test). See [[D-097]]. The P.4 eval suite was UNCHANGED by this gate.

**APPROVAL.P1 (wireframe-parity, presentational only):** `Governance/ApprovalQueue.vue` re-skinned over the SAME
props/gate — dashed glass cards + `eucardIn` + P1 pill buttons + a danger reject panel, a Pending/Resolved toggle,
and agent-type filter pills (All + the REAL agents `inbox`/`scheduler`, client-side; not hardcoded). Honest copy
(no "edit"/stat-strip claim yet). NO gate/backend change — approve/reject still route through `ApprovalQueue`
(re-authorise + re-ground + assert-pending + reason-required intact). Audit: `docs/wireframe-parity/APPROVAL-QUEUE-DIFF.md`;
locked by `tests/Feature/Governance/ApprovalQueueChromeTest.php` (3).
**APPROVAL.P2 (card anatomy, presentational):** `AiApprovalQueueController` now surfaces per pending action (present-only,
no gate change) `permission` (the tool's real `ToolDefinition::permission` — the one approve re-authorises against),
`ceiling` (`AutonomyPolicy::effectiveCeiling`, the cap, distinct from the proposed level), `queuedAt`, and `sources`
(real grounding from `proposed_output.lines[].source`, or `[]`/honest-absence — never fabricated); the Vue card shows the
tool-permission chip, the `ceiling:` cap, What/Why, the ↳ sources line, and keeps the full inspectable payload. Locked by
`tests/Feature/Governance/ApprovalCardAnatomyTest.php` (3). Remaining: stat strip, edit-before-sending, bulk-approve
(excludes clinical), resolved filters, fence-refused modelling, the re-authorise/re-ground caption.
**APPROVAL.P3 (approve-contract caption, presentational — RESOLVES the re-authorise/re-ground surfacing):** each pending
card now shows, beside its approve/reject controls, the caption "On approve, the server re-authorises you against
`<permission>` and re-grounds the draft / re-derives the action against current state before it posts / runs." It states
ONLY what the real path already does — `ApprovalQueue::approve()` re-authorises the reviewer against the tool's own
permission, asserts still-pending, then re-runs `tool->execute()` (which re-derives from live state via `preview()`; see
`DraftReplyTool::execute` "Re-ground the draft…" + `FillFromWaitlistTool::execute` re-runs `preview()`). The `<permission>`
is INTERPOLATED from the action's real tool permission (the P2 chip value — never hardcoded). The wording is ACCURATE
per action via a real `reGroundsDraft` flag the controller reads from the action's OWN `proposed_output` shape
(`body`/`lines`/`handoff` present → a draft → "re-grounds the draft…posts"; else → "re-derives the action…runs"), so it
never claims a draft where there is none. NO gate/backend change (approve/reject/re-authorise/re-ground/reason-required
untouched); the caption only renders when `canReview && permission`. Locked by
`tests/Feature/Governance/ApprovalContractCaptionTest.php` (3 — the surfaced permission is the real re-authorise target;
`reGroundsDraft` tracks the action's actual shape). Browser-verified: `comms.draft_reply`→comms.manage/draft-variant,
`scheduler.fill_from_waitlist`→appointment.manage/action-variant. Remaining: stat strip, edit-before-sending, bulk-approve
(excludes clinical/financial), resolved filters, fence-refused modelling.
**APPROVAL.P4 (edit-before-sending, over the EXISTING `editedPayload` support — additive, NOT a bypass):**
`ApprovalQueue::approve($action, $reviewer, ?array $editedPayload)` already re-authorised + asserted-pending + re-ran
`tool->execute($editedPayload ?? $input_payload)` (which re-grounds/re-derives) + recorded the `edited_payload` column;
P4 WIRES the wireframe's **[Edit before sending]** to it and EXTENDS provenance. **Service:** when `$editedPayload !== null`,
`approve()` stamps `result['human_edited']=true` (beside the tool's own markers, e.g. DraftReplyTool `ai_assisted`) and
`metadata:['human_edited'=>true]` on the `approved`+`executed` `ai_interactions` rows — an edited post is distinguishable
from an unedited one (which carries none). **Controller** reads an OPTIONAL `edited_payload` (`sometimes|array` — still
can't raise autonomy / swap the tool), passes it to `approve()`, flashes `approved_edited`; surfaces a new `inputPayload`
prop (the tool INPUT execute re-grounds, distinct from the `proposed_output` preview). **Vue** shows an *Edit before sending*
pill (canReview-gated) → an inline editor seeded from `inputPayload` (JSON; invalid JSON refused client-side) → *Approve
edited* POSTs `{edited_payload}` to the SAME approve route; reuses the P1/P2 visual + the P3 caption. **THE GATE
(unchanged): the edited approve STILL re-authorises + re-grounds + asserts-pending — the edit is the CONTENT, not the
gate.** RBAC canReview-gated (server re-authorises regardless). i18n unique `aiQueue.edit.*` + `flash.approved_edited`.
Locked by `tests/Feature/Governance/ApprovalEditBeforeSendingTest.php` (5 — edited approve posts the EDITED payload +
records human-edited [result + ledger]; unedited carries no marker; unauthorized edited approve → 403 nothing runs;
non-pending edited approve refused, result not overwritten; an edited CLINICAL draft [note.write] still re-authorised →
a reviewer without it denied). No existing behaviour test modified; the eval suite stays green. Browser-verified: an
edited demo.echo posted `result.message`='EDITED…', `human_edited`=true, `edited_payload` + ledger recorded; reject still
works. Remaining APPROVAL parts: stat strip, fence-refused modelling, bulk-approve (excludes clinical/financial),
resolved filters.
**APPROVAL.P5 (model fence-refused as a countable outcome + the stat strip from REAL data — fence UNCHANGED):**
The electric fence already refused a handed-off draft at approve time (`DraftReplyTool::execute` throws) but that
refusal was not countable — the action stayed PENDING. P5 RECORDS it without changing the fence. New
`Exceptions\FenceRefusalException` extends `AiCoreException` (existing catches still catch it); `DraftReplyTool::execute`
throws IT (same condition/message/moment). `ApprovalQueue::approve()` catches it → `recordFenceRefusal()`: transitions
to the new `AgentAction::STATUS_FENCE_REFUSED` (+ `fence_refused_at`; fence reason in `rejection_reason`), writes an
append-only `fence_refused` `ai_interactions` row (metadata.reason), fires `AgentActionLifecycleChanged(…, 'fence_refused')`
→ the generic glue audits `agent_action.fence_refused` (no glue change) → re-throws (nothing executes). Migration
`2026_08_22_000001` adds only `fence_refused_at` (status is a plain string — the new value needs no schema change).
**The fence's behavior/when-it-fires is UNCHANGED** — a non-handoff action still executes; the `tests/Evals/` fence
suite (37/398) is untouched + green. **Stat strip** (`AiApprovalQueueController::index` → `stats`): pending +
fenceRefused are real counts; approvedPct(30d) = executed ÷ resolved-in-window, avgReviewMinutes(30d) =
avg(resolvedAt−createdAt) — both from real timestamps, and **null → an honest "—" when no resolved action in the
window** (no denominator; never fabricated). Controller `approve()` catches `FenceRefusalException` first → redirect
status `fence_refused`; resolved query + `presentResolved` include `fence_refused` (a `resolvedAt()` coalescer). i18n
unique `aiQueue.stats.*` + `flash.fence_refused` + `status.fence_refused`. Locked by
`tests/Feature/Governance/ApprovalFenceRefusedTest.php` (4). No existing behaviour test modified. Remaining APPROVAL
parts: bulk-approve (excludes clinical/financial), resolved search/filters/reviewer/grouping.
**APPROVAL.P6 (the Resolved view: search + status/date/reviewer filters + grouping, over REAL data):** the resolved
section of `AiApprovalQueueController::index` is now server-side searchable/filterable over the real resolved set
(executed/rejected/P5 fence_refused). `allowedToolKeys($tools,$actor)` (tool keys whose permission the actor holds —
the same per-tool gate approve enforces) `whereIn('tool_key', …)` **RBAC-scopes** both the list and the counts
(fail-closed on an unregistered key); tenant-scoped by `BelongsToTenant`. `resolvedFilters()` reads real params
(rstatus/rq/rreviewer/rfrom/rto, dates regex-guarded); `applyResolvedFilters()` targets REAL columns only — search
matches `tool_key`/`agent`/`feature`/`why`/`rejection_reason` (not the nested payload), reviewer→`reviewed_by`,
date→`COALESCE(executed_at,rejected_at,fence_refused_at)`. Real per-status counts via groupBy. `presentResolved`
enriched with `toolName`, `reviewerName` (bulk-resolved), `systemAttributed` (fence_refused → the system, reviewerName
null), reason. `resolvedReviewers` = distinct real human reviewers for the dropdown. Vue: search box (debounced) +
status pills w/ real counts + reviewer select + date range, grouped-by-day (client-side), attribution "Resolved by
{reviewer}" or "Refused by the electric fence (system)". i18n extends `aiQueue.resolved.*`. Locked by
`tests/Feature/Governance/ApprovalResolvedViewTest.php` (4 — real badge/attribution/reason/counts; Fence-refused
filter = system-attributed fence rows w/ fence reason; search matches real `why` + reviewer maps to `reviewed_by`;
tenant + RBAC scoping). No existing behaviour test modified.
**APPROVAL.P7 (bulk-approve, LOW-RISK only; clinical+financial EXCLUDED server-side; per-item gate — APPROVAL QUEUE
PARITY COMPLETE):** `AiApprovalQueueController::bulkApprove` (POST `governance.approvals.bulk_approve`, `ai.manage`)
is a LOOP over the real `ApprovalQueue::approve()` — each selected item still re-authorises + re-grounds +
asserts-pending. **THE SAFETY GATE (server-enforced):** risk = the tool's REAL `ToolDefinition::category`
(`isClinicalOrFinancial()`); clinical + financial are refused for bulk BEFORE approve (a FORGED id for such an action
is never bulk-approved — stays pending — even for a reviewer holding note.write/billing.manage). Per item the fence
still fires (handoff → recorded fence_refused, P5, not forced) and per-item RBAC binds (unauthorized → skipped).
Returns a `bulk`=[approved,excluded,skipped] summary flash (new shared `bulk` flash key in HandleInertiaRequests).
`presentPending` gained `bulkEligible` (canReview && category∉{clinical,financial}) — a UX hint; the server
re-enforces. Vue: per-card checkbox on eligible cards, "Individual review only" chip on clinical/financial, a
bulk-action bar; i18n `aiQueue.bulk.*`. Locked by `tests/Feature/Governance/ApprovalBulkApproveTest.php` (4, incl.
THE EXCLUSION PROOF — a bulk with a clinical AND financial action refuses both while the low-risk item approves).
No existing behaviour test modified. **The Approval Queue page is now at FULL wireframe parity (P1→P7).**

## KB admin UI (CLINIC.W10)

The KB now has an admin surface — app-layer `App\Http\Controllers\KbArticleController` (`/governance/kb`,
`ai.manage`) CRUDs `KbArticle` rows (the front-desk agent's grounding source): list / create / edit / soft toggle
`is_active`. Writes go through the existing `KbArticle` model + `Retrieval\KbEmbeddingService::syncArticle` (the
existing embedding path, kept warm on save); no retrieval/agent logic added. App layer because a KB change is audited
(`kb.article.created/updated/activated/deactivated`) and AiCore may not depend on Audit. **The agent's grounding +
electric fence are UNCHANGED — `KbRetriever` already filters `where('is_active', true)`, so a deactivated article
immediately stops being grounded on** (locked by `tests/Feature/Kb/KbAdminTest.php`, which drives the retriever
before/after). Tenant-scoped (string ids → cross-tenant 404). The P.4 front-desk evals were NOT touched. See [[D-098]].

## Agents & automation settings card (SETTINGS.P2)

The autonomy dial now has an admin surface — an app-layer `App\Http\Controllers\AgentAutonomyController`
(`/admin/agents`, `ai.manage`, cross-linked from `/settings`) that lists the real registered governed tools
(`ToolRegistry::all()`, the reserved `demo.*` echo excluded → 10 tools) with an Off/Suggest/Approve/Auto control.
It is presentation over `AutonomyPolicy` and adds NO policy: it READS the current level via `levelFor()` and the
locked limit via the new `AutonomyPolicy::effectiveCeiling()` (= `cap(AUTO)`, the SAME clamp the runtime applies —
a read-only view), and WRITES only through `AutonomyPolicy::set()` (which clamps), auditing `ai.autonomy_changed`.
`ToolRegistry::all()` is a new read accessor (enumeration only). THE FENCE: the card can only LOWER autonomy — the
UI offers levels ≤ the ceiling AND `set()` clamps a forged higher level server-side (a POSTed `auto` persists as
`suggest` for a clinical tool, `approve` for a financial tool); `AutonomyPolicy::cap()` is un-weakened. Locked by
`tests/Feature/Admin/AgentAutonomyTest.php` (8 tests, incl. the clamp-above-ceiling fence). The eval suite was
UNCHANGED. See the DIFF doc `docs/wireframe-parity/ADMIN-SETTINGS-DIFF.md` and [[Platform]].

## Agent entity + capped resolver (AGENT.P1 — the safety foundation, NO UI)

The **Agent** is now a real per-tenant entity (`Modules\AiCore\Models\Agent`, `agents` table, BelongsToTenant) — a
GOVERNED CONTAINER, never a source of authority. It maps to a code-class agent (`key`) and holds a CONFIGURED
`autonomy_level`, a `status` (active/paused), and a `tool_keys` whitelist. `AgentRegistry::AGENTS` = the 6 canonical
agents (inbox/scheduler/recall/clinical_summary/dispatch/billing) covering all 10 real governed tools. Additive
migration `2026_08_25_000001` **backfills existing tenants**; **new tenants seeded via a `Tenant::created` hook**
(app-layer `AppServiceProvider`, beside the Platform RBAC provisioner — Platform must not depend on AiCore;
`AgentResolver::ensureForTenant($tenant)` runs in `TenantContext::system()` with explicit tenant_id).
**THE CAPPED RESOLVER** `AgentResolver::effectiveLevel(Agent, ToolDefinition, roleCeiling=AUTO)` = **MIN(configured,
tool ceiling [`AutonomyPolicy::effectiveCeiling`], role ceiling)**; paused/non-whitelisted → OFF. Config can only
NARROW — never raises past the tool ceiling or role RBAC ceiling. Wired into `AgentRuntime` (additive optional
`?Agent $agentEntity`; null = the existing per-tool `levelFor` path, unchanged); the role ceiling = OFF when the
acting user lacks the tool's `permission`. **Clamp-on-write** (`App\Services\AgentConfigService::configure`,
app-layer, audited `agent.configured`; valid enum/status; whitelist = real tool keys only) **+ clamp-at-runtime**
(the resolver mins regardless — a forged stored `auto` or a whitelisted out-of-ceiling tool still resolves capped).
The agent sits UNDER `AutonomyPolicy` + the role ceiling + the fence — none weakened; the SETTINGS.P2 per-tool cap
is one of the min() terms and unchanged. NO UI (P2+ builds the surfaces; RBAC admin.manage is the P2 controller's
job — no route yet). Locked by `tests/Feature/AiCore/AgentEntityTest.php` (7). See
`docs/wireframe-parity/AGENT-TOOL-CONFIG-DIFF.md` §6/§9.

## Per-agent governance surface + the autonomy ladder (AGENT.P2 — presentation over the P1 cap)

The per-agent page (`/governance/agents`, `App\Http\Controllers\AgentConfigController`,
`resources/js/Pages/Governance/Agents.vue`, **admin.manage**-gated, tenant-scoped) is the agent LIST + per-agent
DETAIL shell + **the AUTONOMY LADDER**, over the P1 resolver. Agents resolve **by string id** (never route-model
binding of a tenant-scoped model — FIX.1) → cross-tenant/missing = 404. The detail shell is a dark hero (identity +
status + **effective ceiling** + configured level + tool count; live metrics deferred to P5, honest no-fake-numbers
note) + a presentational flow pipeline naming the REAL runtime path (message → grounded draft → checked-vs-ceiling →
fence → human). Tabs: Agents · Action ledger (the ledger data is P5; the tab SHELL exists).
**THE LADDER** offers only levels ≤ the agent's **effective ceiling** — new
`AgentResolver::agentCeiling(Agent, roleCeiling=AUTO)` = **MIN of the tool ceilings the agent whitelists**
(`AutonomyPolicy::effectiveCeiling` per tool; forged/unregistered key contributes nothing; no-tools → OFF). Per-agent
ceilings: inbox/recall/clinical_summary → suggest; scheduler/dispatch/billing → approve. Higher rungs render LOCKED.
**THE CAP (proven):** a level change writes through `AgentConfigService::configure` (P1 clamp-on-write + audit), but
the ceiling clamp lives in the **P2 controller** (`clampToCeiling` = min-rank) — a forged `POST autonomy_level=auto`
above the ceiling is clamped server-side (browser-verified: inbox auto → suggest), and the resolver caps again at
call time. `AgentConfigService` (P1) is UNCHANGED, so no P1 test is modified. New i18n block `agentConfig.*` (reuses
`agents.levels.*`). PURELY additive: no whitelist edit (P4), no metrics/ledger data (P5), no limits (P6); the
per-tool SETTINGS.P2 card + AutonomyPolicy + role ceiling + fence are all unchanged. Locked by
`tests/Feature/Governance/AgentConfigTest.php` (8). See `docs/wireframe-parity/AGENT-TOOL-CONFIG-DIFF.md` §9.

## Two reflect-only / toggle-free governance panels (AGENT.P3 — real gates surfaced)

The per-agent detail also carries two READ-ONLY panels — DISPLAY of real gates, no editable control:
**(1) the permission-ceiling MIRROR** (`AgentConfigController::permissionMirror`): per agent, `exercised` = the
distinct real `permission` of each whitelisted tool (the exact `Gate::allows($tool->permission)` targets
`ApprovalQueue::approve` re-authorises — role-derived) + `withheld` = sensitive human-only permissions
(note.sign/patient.edit/medication.prescribe/allergy.override) each verified NOT carried by any registered tool
(denied is derived, never fabricated). Caption "change the role to change the ceiling" links `/admin/roles`. NO
write path here — the only `governance.agents.*` routes are index+configure, and configure IGNORES any forged
permission/tool_keys body. **(2) the electric-fence VAULT** (`AgentConfigController::FENCE_INVARIANTS`, 6 keys):
displays the code-enforced, eval-locked invariants TOGGLE-FREE (labelled ledger / human-approves-send /
clinical-never-autonomous / consent+tenant-scoped / append-only ledger / re-authorise+re-ground-at-approve) — each
cited to enforcing code + a `tests/Evals/` case in the DIFF doc §9; no route/action disables any. i18n `agentConfig.mirror.*`
+ `agentConfig.fence.*`. Locked by `tests/Feature/Governance/AgentConfigTest.php` (13 total; +5 P3: mirror reflects
real exercised perms, withheld is real+human-only, no-permission-edit + no-fence-disable route, configure ignores
forged fields, vault lists enforced invariants).

## Editable tool whitelist (AGENT.P4 — narrows the callable set, never grants past ceiling)

The per-agent detail also carries an EDITABLE tool whitelist ("tools it may call · N of M enabled",
`AgentConfigController::toolWhitelist`): the agent's CANDIDATE remit tools (`AgentRegistry::AGENTS[key]['tools']`,
registered + ceiling above OFF) toggle enabled/disabled; every other governed tool (minus `demo.*`) renders LOCKED
("outside this agent's remit"). Toggling posts `tool_keys` to `configure`, which INTERSECTS the request with the
candidate set (`candidateToolKeys` — a forged enable of a locked/out-of-remit/unregistered key is DROPPED) then
writes through `AgentConfigService::configure` (P1 clamp + audit). **`AgentConfigService` (P1) is UNCHANGED — the
remit clamp lives in the P4 controller** (so P1's out-of-remit-key test still passes; AutonomyPolicy injected via a
controller constructor for effectiveCeiling). THE CAP: whitelisting changes the callable SET, never the AUTHORITY —
disabling → resolver OFF (P1); a whitelisted tool is still MIN-capped at runtime (whitelisting never widens); a
forged locked/unregistered key is dropped server-side. The P3 mirror's `exercised` set recomputes from `tool_keys`
each load, so it reflects the real whitelist (still read-only). i18n `agentConfig.whitelist.*`. Locked by
`tests/Feature/Governance/AgentConfigTest.php` (18 total; +5 P4).

## Per-agent metrics + the action-ledger (AGENT.P5 — real data only, no faked numbers)

The per-agent hero metrics + the action-ledger tab are computed ONLY from real records
(`App\Services\AgentMetricsService`). **Attribution:** the `agent` column on `ai_interactions`/`agent_actions` is a
free-form string with NO FK to `Agent::key` — the codebase writes it two ways (bare key from seeders/direct
ApprovalQueue calls, e.g. 'inbox'; the code-class const from production agents, e.g. `InboxAgent::AGENT`=
'inbox-agent'; there is no scheduler agent class). `AgentRegistry::LEDGER_ALIASES` (+ `ledgerNames`/`displayName`) is
the single source of truth mapping each canonical agent → its ledger string(s), so metrics are a real
`WHERE agent IN (...)`. **Hero metrics** (each a real count or honest "—"): draftsToday = `ai_interactions`
outcome='proposed' today; fenceRefused7d = `agent_actions` status=fence_refused in 7d (danger-tinted);
approvedAsIsPct = executed-without-edit (`edited_payload IS NULL`) / total resolved — **null → "—" when nothing is
resolved** (never a fabricated 0/100). **Action-ledger tab** = the newest-first rows of the append-only
`ai_interactions` (tenant-scoped), each with agent(canonical label)/tool/outcome/detail; a fence_refused row is
system-attributed with the fence's own reason. A **read-only VIEW of an immutable table** (no edit/delete route;
model + DB triggers block UPDATE/DELETE). A real fence_refused is seeded through the real path in `DemoClinicSeeder`
(propose a non-groundable draft → approve → the fence fires + records fence_refused). i18n `agentConfig.hero.*` +
`agentConfig.ledger.*`. Locked by `tests/Feature/Governance/AgentConfigTest.php` (24 total; +6 P5). One demo-fixture
thread count in `DemoClinicSeederTest` (patient threads 3→4) was updated for the added fence thread.

## Rate/timing limits + always-on escalation + new-agent wizard (AGENT.P6 — parity COMPLETE)

The Agent & Tool Config page is now wireframe-parity COMPLETE (P1→P6). **Limits** (`agents` gains
`max_drafts_per_hour`, `quiet_hours_start`, `quiet_hours_end` — additive migration `2026_08_26_000001`) are REAL
settings `AgentRuntime::runTool` CONSULTS on the Agent-entity path (after the OFF check): quiet hours
(`Agent::isQuietHour`, overnight-aware) → `quiet_hours`; drafts/hour cap (counts the agent-kind's AgentActions in
the rolling hour) → `rate_limited`. A limit only STOPS the agent (defers to a human), never widens it; stored via
`AgentConfigService::configure` (validated bounds, nullable clears, audited). The **escalate-below-confidence
threshold is honestly DEFERRED** (no confidence signal in the codebase → read-only "planned", not a phantom). The
**always-on uncertainty escalation** = the real clinician-attention hand-off (no suppression key, per
`NotificationSettingsController`); LOCKED-ON, NO disable route/field — the floor is un-removable. **New-agent
wizard** (`AgentConfigController::store`, admin.manage): a new `kind` column (the canonical capability; distinct
from the unique `key`; backfilled `kind=key` so P1–P5 seeded agents are unchanged; `Agent::kind()` falls back to
key) creates a governed container of a REAL kind (kind ∈ `AgentRegistry::AGENTS` — no non-existent capability),
whitelisted to the kind's remit, autonomy CLAMPED to the new agent's ceiling — **capped from birth** (resolver caps
at runtime too), audited `agent.created`. `candidateToolKeys` + metrics attribution now key off `kind()`. i18n
`agentConfig.limits.*` + `agentConfig.create.*`. Locked by `tests/Feature/Governance/AgentConfigTest.php` (31 total;
+7 P6).

## Open items

- Richer production-grade vector retrieval is still unbuilt (KB admin UI now exists, W10 above; approval-queue UI, W9).
  All agents/tools must continue through AiCore governance.
- Expand the prompt eval harness beyond the minimal eval-passed flag before real prompt rollout.
  (Distinct from the P0P.G4 safety eval suite: that locks BEHAVIOR/guardrails deterministically; a
  prompt-quality harness would score real model outputs — still deferred.)
- New agents/tools MUST land with matching `tests/Evals/` locks (fence/autonomy/grounding/no-trust)
  and a row in `docs/AGENT-EVALS.md`.

### GOV.P4 — demo governance data (2026-08-24)

`DemoClinicSeeder` now reaches **every** `AgentAction` outcome, each by driving its real path:
`scheduler.suggest_slots` approved as-is, `comms.draft_reply` EDITED then approved,
`clinical.draft_recall_message` rejected with a reason, and the pre-existing fence refusal.
**Nothing writes a status column and nothing inserts a ledger row** — if you extend this, keep it
that way: `DemoGovernanceDataTest` asserts the fingerprints of a real traversal, so a hand-set
status turns it red.

**Choosing a tool to EXECUTE in a seeder: read its execute path first.**
`billing.preflight_invoice` looks like a pure report and is not — its validator persists
validation state and flipped the demo dunning fee from draft to validated. `scheduler.suggest_slots`
is the genuinely inert one (reads the finder, `books_on_approval: false`). Avoid
`fill_from_waitlist` (books), the nursing tools (write assignments) and `suggest_charge_codes`
(captures charges).

**Back-dating seeded work is SAFE, contrary to the obvious worry.** `AuditService::record()` forces
`occurred_at` strictly monotonic per tenant (`prevTime + 1µs`), so travelling the clock around a
real call cannot reorder the hash chain against `verifyChain()`'s `occurred_at ASC` replay. The
ledger and the action timestamps DO move. See [[demo-seeder-is-the-heavy-fixture]].

### GOV.P1 — the windowed governance reader (2026-08-24)

`AgentMetricsService::window(from, to)` is THE governance metrics reader: counts by real status,
by canonical agent (via `LEDGER_ALIASES`), by **registered tool only** (with the tool's real
ceiling), the ledger by outcome, the fence-refusal count and the live queue depth.

**`approvedAsIsPct` has ONE definition** — a private helper shared by `hero()` and `window()`.
If you add a third caller, call the helper; a second copy of the arithmetic is what the mutation
test exists to catch. The honest `null → "—"` is load-bearing: never default it to 0.

**Only `ToolRegistry` keys may be emitted.** The governance wireframe drew nine acting tool keys
(`comms.send`, `clinical.sign`, `billing.charge`, …) that were never built; printing one would
claim the capability exists. Unregistered keys are counted in `unregisteredTools` instead.

**The dashboard states what it cannot show** (`governance.omitted.*`): no confidence score, no
breach count, no KB-gap ranking, no "escalated" outcome. If a future gate sources one of these
for real, remove the line — do not leave the page contradicting itself.

**Vue trap:** do not name a prop `window` — it shadows the browser global inside an SFC.

### GOV.P2 — the "needs a human" reader (2026-08-24)

`App\Services\NeedsHumanReader` answers "what is waiting on a person right now" for agent
governance: **pending approvals** (`ai.manage`) and **threads still awaiting a clinician**
(`comms.manage`), each permission-scoped fail-closed and each linking to where a human acts.

**`clinician_attention_at` is set and NEVER cleared** (`InboxAgent::refuseClinical`; no writer
anywhere nulls it). Counting flagged threads directly gives a number that can never fall, so the
reader defines *still waiting* as flagged **and** open **and** no staff message since the flag.
If you touch that query, keep all three conjuncts pinned — a mutation showed the `open` clause
was dead until a test closed a thread instead of replying to it.

**The panel names what it does NOT cover** (`governance.needsHuman.elsewhere`): results to
review, recalls due, referrals to send, notes to sign. That is what stops its honest empty state
from reading as a global all-clear. If a new agent-governance blocking state appears, add it as a
category; if a clinical worklist changes, update the named list.

**The pending count is `AgentMetricsService::pendingApprovalCount()`** — shared with the GOV.P1
dashboard. Never re-query it locally.

### GOV.P3 — KB admin + last-saved-by (2026-08-24)

**All KB writes go through `App\Services\KbArticleService`** (write → re-embed → audit). The
controller and the demo seeder both use it, so a seeded article is indistinguishable from one a
person saved. Do not write `kb_articles` directly — an article created outside this path has no
audit row and will honestly show "no author on record".

**Last-saved-by is READ FROM THE AUDIT TRAIL, not a column.** `KbArticleService::SAVE_ACTIONS`
is the set the controller reads back. Do not add `updated_by`: it would duplicate a fact that
already exists, and existing rows could only be backfilled with a guess.

**`kb_articles` has no versioning** — edits mutate the row. The wireframe's "edit history" does
not exist; do not imply it.

**Refused, permanently, unless a real record appears first:** the KB-gap ranking and any
article quality/coverage/usefulness score. Nothing records ungrounded questions. The page states
both omissions (`kb.omitted.*`) and `KbAdminParityTest` scans fourteen aliases for them.

**Grounding usage IS real** (`{type: kb_article, id}` on draft lines) and may be shown as a
count — never as a rank, and never as the list's sort order.

### GOV.P5 — the ledger exporter (2026-08-25) · GOVERNANCE CORE COMPLETE

`App\Services\GovernanceLedgerExporter` exports the `ai_interactions` ledger for a window, gated
on the NEW **`audit.export`** permission (narrower than `audit.view`, which three roles hold).

**The column classification is the contract.** `DEFAULT_COLUMNS` is governance-only;
`OPT_IN_COLUMNS` (`metadata`, `error_message`) are free text and reachable only through the
`free_text` opt-in, which needs `admin.manage` ON TOP of `audit.export`. **If you add a column,
classify it first — the rule for anything unclear is OUT.**

**`audit_events` is deliberately NOT exported row-by-row** (it carries `patient_id` and a
free-text `reason` with real clinical content). Only its integrity state goes in the manifest.

**The manifest is not optional** — the download is a ZIP of payload + manifest, and the manifest
carries a SHA-256 of the payload bytes so truncation and alteration are detectable. Do not add a
path that emits the payload alone.

**Not built:** the wireframe's signed PDF. There is no signing key; a "signed" file with an
unmanaged key invites trust it cannot support. Stated on the dashboard.

