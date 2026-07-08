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
  agent, tool key, autonomy level, status, proposer/reviewer, approve/reject/execute timestamps,
  rejection reason, why/diff/input/proposed output/edited payload/result.
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
- AiCore may use Platform for tenant/settings/RBAC primitives; it does not depend on Audit or domain
  modules. Audit composition lives in `app/`.

## Status

**P0C.G8 COMPLETE.** The governed runtime foundation is live and now runs the first two agents:
Scheduler Agent (waitlist fill proposals + slot suggestions) and Front-Desk Agent (tenant KB-only
FAQ with refusal/escalation). Local `composer check` is green: 168 tests / 711 assertions.

## Open items

- Future gates add UI for approval queue / KB administration and richer production-grade vector
  retrieval. All agents/tools must continue through AiCore governance.
- Expand the prompt eval harness beyond the minimal eval-passed flag before real prompt rollout.
