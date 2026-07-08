# Module: AiCore (`Modules\AiCore`)

## Purpose

The AI/agent layer for CareOS: a custom provider-agnostic **LlmManager**-style HTTP layer
(Anthropic primary) with a cost ledger, budget gate, circuit breaker, and a versioned prompt
registry — NOT a framework AI SDK. All AI output is **draft-until-approved**, visibly labeled,
and logged; `ai_interactions` is append-only. The **ELECTRIC FENCE** applies: no diagnosis,
triage, symptom assessment, or dosing logic anywhere.

## Status

**Scaffolded, not yet implemented.** Only the module skeleton + `AiCoreServiceProvider` exist
(from P0.G4). No tables, services, or prompts yet. See the master plan **Phase H** and the
AiCore design for the build-out.

## Open items

- Everything: `LlmManager`, cost ledger, budget gate, circuit breaker, prompt registry,
  `ai_interactions` table, draft-approval workflow. All deferred to the AI phase.
