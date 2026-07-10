# DEFERRED.md

Deliberately deferred work. Not forgotten — parked until the right phase.

- Migrate/validate DEV database to **MySQL 8** before production (MariaDB 10.4 is EOL; prod
  target is MySQL 8).
- Upgrade to **Laravel 13 / PHP 8.3+** when convenient (PHP 8.2 security support ends
  ~Dec 2026).
- **Voice receptionist.**
- **Route optimization** (OR-tools).
- **Real routing/travel-time API for Nursing dispatch.** E.3 uses deterministic straight-line
  distance / configurable average speed only; road-network routing and traffic-aware feasibility
  are deferred.
- **MAR** (medication administration record).
- **Clinician countersigning for nurse observational visit notes.** E.7 stores nurse visit notes as
  visit execution documentation, not signed/locked SOAP clinical notes; countersign workflow comes later.
- **E-prescription rails** per market.
- **Lab HL7/FHIR feeds.**
- **Country statutory billing packs** (DE / CH / FR).
- **US X12 claims** via clearinghouse.
- **WhatsApp channel.**
- **SMS / WhatsApp appointment reminder drivers.** C.5 adds the provider-free reminder channel
  interface and email implementation only; external SMS/WhatsApp providers come later.
- **Realtime day-board refresh with Reverb.** C.6 uses normal request/slot refreshes; websocket
  refresh belongs later when realtime infrastructure is introduced.
- **Realtime inbox refresh with Reverb.** G.3's unified inbox polls/reloads on demand; websocket
  push for new messages joins the same future realtime work (P0G.G3).
- **Telehealth recording + transcripts.** D-G2: recording is disabled at the provider level and no
  media/recording columns exist. Enabling recording or transcripts requires a funded consent +
  retention design first; never switch it on without one (P0G.G4).
- **Reverb-based telehealth presence.** Join/leave proof rows are written by the server paths; live
  presence indicators join the future realtime work (P0G.G4).
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
- **Cross-tenant CareOS referrals require explicit share objects.** D.5 records external
  referrals by provider name only and same-tenant internal referrals by `to_branch_id`. Never
  widen tenant scope to send referrals to another CareOS tenant; design explicit share objects
  before building cross-tenant referral exchange.
