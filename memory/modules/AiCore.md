# Module: AiCore (`Modules\AiCore`)

## Purpose

The AI/agent layer for CareOS: a custom provider-agnostic **LlmManager**-style HTTP layer
(Anthropic primary) with a cost ledger, budget gate, circuit breaker, and a versioned prompt
registry — NOT a framework AI SDK. All AI output is **draft-until-approved**, visibly labeled,
and logged; `ai_interactions` is append-only. The **ELECTRIC FENCE** applies: no diagnosis,
triage, symptom assessment, or dosing logic anywhere.

## Status

**Scaffolded, queue substrate ready.** Only the module skeleton + `AiCoreServiceProvider` exist
(from P0.G4). P0C.G0 added Redis/Horizon for future agent jobs, but no AiCore tables, services,
or prompts exist yet.

## Open items

- Everything inside AiCore itself: `LlmManager`, cost ledger, budget gate, circuit breaker,
  prompt registry, `ai_interactions` table, draft-approval workflow. Phase C activates these in
  later gates.
