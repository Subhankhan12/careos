# DEFERRED.md

Deliberately deferred work. Not forgotten — parked until the right phase.

- Migrate/validate DEV database to **MySQL 8** before production (MariaDB 10.4 is EOL; prod
  target is MySQL 8).
- Upgrade to **Laravel 13 / PHP 8.3+** when convenient (PHP 8.2 security support ends
  ~Dec 2026).
- **Voice receptionist.**
- **Route optimization** (OR-tools).
- **MAR** (medication administration record).
- **Clinician countersigning for nurse observational visit notes.** E.7 stores nurse visit notes as
  visit execution documentation, not signed/locked SOAP clinical notes; countersign workflow comes later.
- **E-prescription rails** per market.
- **Lab HL7/FHIR feeds.**
- **US X12 claims** via clearinghouse.
- **WhatsApp channel.**
- **SMS / WhatsApp appointment reminder drivers.** C.5 adds the provider-free reminder channel
  interface and email implementation only; external SMS/WhatsApp providers come later.
- **Expanded AI prompt eval harness.** C.7 adds a minimal prompt registry eval-passed gate; richer
  offline/fixture evals come before real agent prompt rollout.
- **Production vector search for KB RAG.** C.8 stores portable vector-as-JSON embeddings and scores
  cosine similarity in PHP; ANN/vector indexes can replace this when the stack chooses a portable
  search backend.
- **Agent UI surfaces.** C.8 is backend + tests only; approval-queue and KB admin screens come in a
  later UI gate.
- **Qualified e-signature.**
- **Meilisearch** swap for FULLTEXT.
- **Silo tenancy tier.**
- **SSO / SAML.**
- **White-label.**
- **US EVV state aggregator exports.**
- **List-B AI** (partner-first).
- **Capacitor wrappers.**
- **Payroll connectors.**
- **Multi-tenant same-email membership** (one human belonging to several tenants).
  Users carry a single nullable `tenant_id` and email is globally unique for now
  (introduced in P0A.G2).
- **Least-privilege DB user for `audit_events`.** Production should run the app under
  a DB user with UPDATE/DELETE revoked on `audit_events` (defence in depth). Dev uses
  root, so the append-only BEFORE UPDATE/DELETE triggers are the active guard now
  (introduced in P0A.G6).
- **Schedule `audit:ensure-partitions`.** Wire it into the scheduler once the scheduler
  is set up, so upcoming monthly partitions are always provisioned (P0A.G6).
- **Schedule `credentials:refresh-status`.** Wire it into the scheduler once the scheduler
  is set up, so credential expiry status stays current outside manual command runs (P0B.G1).
- **Schedule `nursing:materialize-visits`.** E.2 adds the idempotent horizon command for planned
  nursing visits; wire it into the scheduler when recurring application scheduling is finalized.
- **Schedule `billing:dunning-run`.** F.6 adds the idempotent dunning evaluation command; wire it into
  the scheduler (per tenant, daily) once recurring application scheduling is finalized (P0F.G6).
- **Validate patient name search parity before production.** Dev MariaDB 10.4 uses plain FULLTEXT
  while MySQL 8 CI/prod uses `WITH PARSER ngram` - patient name search tokenizes differently
  across environments (P0B.G3).
- **Drug interaction / allergy class / dose / CDS engines are medical-device territory.**
  Drug-interaction checking, allergy class inference, dose calculation, and clinical decision
  support require a partner-first licensed drug database and a funded regulatory track; do not
  build these in-house as CareOS deterministic clinical-list logic (P0D.G3).

## Parked — build when a real user/customer creates the need

Demand-driven backlog. These are deliberately NOT built yet: building them speculatively would add
surface, cost, and risk before anyone needs them. Each carries a TRIGGER — the concrete signal that
should pull it forward. When a trigger fires, the item graduates from parked to planned; until then,
it stays here so it is neither forgotten nor pre-built (P0P.G5).

- **Phase H agents (full RAG front-desk, ops-analyst, onboarding agent).** The Phase C/D/E/F/G agents
  are deliberately narrow (KB-only front-desk, extractive clinical summary, draft-only inbox, etc.);
  the fuller agents are a distinct phase, not an extension of these.
  **TRIGGER:** design partners ask for one, or a repeated manual pain a specific agent would remove.
- **AI-credits metering & billing for AI usage.** `ai_interactions` already ledgers every call and the
  budget gate already caps spend; turning that into metered, invoiced credits is a separate product
  decision, not wired in.
  **TRIGGER:** a paying customer PLUS a decision to charge for AI.
- **Real routing for nurse travel (replace the straight-line estimate).** E.3 uses deterministic
  straight-line distance / configurable average speed only; road-network routing and traffic-aware
  feasibility are not built.
  **TRIGGER:** a nurse reports the straight-line estimate is wrong in practice.
- **Statutory market packs (DE / CH / FR billing specifics).** EU-Generic is live and reconciles to the
  unit; per-country tariff/VAT/export specifics (e.g. DATEV columns) are packs added on demand.
  **TRIGGER:** a signed or serious prospect in that country.
- **Cross-tenant CareOS referrals (explicit share objects, never scope-widening).** D.5 records external
  referrals by provider name only and same-tenant internal referrals by `to_branch_id`. Never widen
  tenant scope to reach another CareOS tenant; design explicit share objects first.
  **TRIGGER:** two customer tenants that need to refer to each other.
- **Telehealth recording + transcripts.** D-G2: recording is disabled at the provider level, no
  media/recording columns exist, and grants pin `recorder=false`. Enabling recording or transcripts
  requires a funded consent + retention design first — never switch it on without one.
  **TRIGGER:** a customer requirement AND a completed consent/retention design (do NOT enable without
  both).
- **Realtime (Laravel Reverb) for inbox / day-board.** The unified inbox (G.3), reception day-board
  (C.6), and telehealth presence indicators (D-G2 writes join/leave rows already) all poll or refresh
  on demand today; websocket push joins one future realtime work item.
  **TRIGGER:** polling latency becomes a real complaint.
- **Multi-language content (fill i18n beyond English).** The i18n scaffolding exists; the clinical/UI
  copy is English only.
  **TRIGGER:** a customer/market in that language PLUS a native reviewer for clinical copy — do it
  AFTER the design pass so the strings are stable.
- **Payment processing in the portal (PSP).** G.5 shows invoices and open balances read-only; taking
  payments online needs a PSP integration and reconciliation wiring into the F.5 payment ledger.
  **TRIGGER:** customers want patients to pay online PLUS a chosen PSP.
- **Playwright transport-layer offline test.** The airplane-mode exit proof is a Laravel API
  end-to-end test plus the PWA Vitest encryption/offline suite; browser `context.setOffline(true)` is
  not installed in this repo.
  **TRIGGER:** pull forward when prepping the sales demo.
