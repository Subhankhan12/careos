# DECISIONS.md — Architecture Decision Log (append-only)

Append new decisions; never edit or delete past ones. Supersede by adding a new entry that
references the old ID.

- **D-001 — Laravel 12 on PHP 8.2.** Use the existing XAMPP CLI PHP (`C:\xampp\php`); no
  Herd, no PHP install/switch.
- **D-002 — App-layer fail-closed tenancy instead of RLS.** Tenancy is enforced in the
  application layer (every tenant-owned row carries `tenant_id`; no tenant context ⇒ throw),
  not via database row-level security.
- **D-003 — DEV database = existing XAMPP MariaDB 10.4 on port 3306.** A separate `careos`
  database; existing projects on 3306 remain untouched.
- **D-004 — PRODUCTION target = MySQL 8; CI runs against MySQL 8 for parity.** Portable SQL
  required (must work on both MariaDB 10.4 and MySQL 8). Validate/migrate to MySQL 8 before
  production, because MariaDB 10.4 is EOL.
- **D-005 — Default DB cache/queue drivers in Phase 0.** Redis + Horizon come later; on
  Windows the Redis client will be `predis` to avoid phpredis PECL pain.
- **D-006 — Frontend = Inertia v2 + Vue 3 + TypeScript + Tailwind.**
- **D-007 — Separate offline-first Nurse PWA** (a distinct SPA from the main app).
- **D-008 — Agent layer = custom provider-agnostic LlmManager-style HTTP layer** (Anthropic
  primary) with cost ledger, budget gate, circuit breaker, and versioned prompt registry —
  no framework AI SDK.
- **D-009 — Autonomy dial: off / suggest / approve / auto**, with clinical and financial
  actions capped at `approve` (never `auto`).
- **D-010 — EU-Generic pack first, US second via the EVV lane.**
- **D-011 — EU region cell first; PHI never crosses cells.**
- **D-012 — Plain internal Modules** (no nwidart or other third-party module manager).
- **D-013 — Append-only + hash-chained audit with read-logging.**
- **D-014 — Custom tenant-aware RBAC, not spatie/laravel-permission.** Roles/permissions are
  tenant-owned with branch-scoped assignments and ABAC-condition slots; integrated via
  `Gate::before`, with platform super-admin (tenant_id null) as the ONLY bypass (P0A.G4).
- **D-015 — Users are global-email for now.** Single nullable `tenant_id` per user (null =
  super-admin); multi-tenant same-email membership is deferred (P0A.G2, see DEFERRED.md).
- **D-016 — DB triggers are the active append-only guard for `audit_events` in dev.** BEFORE
  UPDATE/DELETE triggers `SIGNAL SQLSTATE '45000'`; a least-privilege DB user (UPDATE/DELETE
  revoked) is the production defence-in-depth, deferred (P0A.G6).
- **D-017 — Cross-module composition lives in the app layer.** Modules never depend on each
  other (arch-test enforced); glue that needs two modules (e.g. Audit + Platform tenant context,
  break-glass) lives in `app/` via services/contracts (P0A.G7).
- **D-018 — AGENTS.md is the single source of truth across agents.** `CLAUDE.md` and `codex.md`
  are thin pointers; every task follows the MEMORY PROTOCOL (P0A.GM).
- **D-019 — Inertia pages live in `resources/js/pages` (lowercase).** Matches inertia-laravel's
  `pages.paths` for case-sensitive Linux/CI parity (P0A.GF3).
- **D-020 — CI builds the frontend and runs on MySQL 8.** `npm ci` + `npm run build` before
  tests (Vite manifest), Node 22; full suite runs against MySQL 8 for production parity
  (P0A.GF / P0A.GF3).
- **D-021 - Patient identifiers are optional attributes, not dedupe keys.** `patient_identifiers`
  may store external/national/insurance/member IDs for CRM context, but matching/dedupe must not
  treat them as unique patient identity keys (P0B.G2).
- **D-022 - Patient merge reversal restores only records moved by the merge.** `patient.unmerged`
  uses the `patient.merged` audit snapshot to restore the source patient and child rows moved
  during that merge; records created on the target afterward remain on the target (P0B.G3).
- **D-023 - Captured patient consents store immutable template snapshots.** `patient_consents`
  stores the signed template key/title/body/scope version alongside the template FK so consent
  proof and scope resolution are stable even after newer template versions supersede old text
  (P0B.G4).
- **D-024 - Patient portal identity is separate from staff users.** Use tenant-owned
  `portal_accounts` plus a dedicated `patient` session guard, not `users`, so patient logins are
  isolated from Fortify staff/admin MFA and RBAC. Portal sessions carry `portal_tenant_id` and
  re-establish tenant context before guard rehydration; portal access is gated by
  `ConsentService::has(patient, 'portal.access')` (P0B.G5).
- **D-025 - Queue infrastructure is Redis + Horizon for Phase C.** Use a Redis-compatible server
  on 127.0.0.1:6379 (Memurai on Windows), Predis as the PHP client, Redis for cache/queue, and
  Horizon for workers/visibility. Sessions remain database for now to avoid unnecessary auth
  churn. CI runs a Redis 7 service alongside MySQL 8 and installs Linux `pcntl`/`posix` for
  Horizon (P0C.G0).
- **D-026 - Service branch availability uses a tenant-owned link table.** Scheduling services
  use `service_branch` instead of JSON branch IDs so availability stays queryable, portable, and
  guarded by same-tenant checks. No link rows means the service is available at all tenant
  branches (P0C.G1).
- **D-027 - Date-specific resource availability overrides weekly recurrence.** For resource
  calendars, date-specific available rows replace the recurring windows for that date; date-
  specific unavailable rows subtract blocks from the chosen windows, and an unavailable date row
  without times is full-day time off (P0C.G2).
- **D-028 - Appointment booking serializes on resource rows.** To guarantee no double-booking on
  MariaDB 10.4 and MySQL 8, `BookingService` locks each requested `resources` row in deterministic
  ID order inside the transaction, then checks overlapping `appointment_resources`/`appointments`
  rows using the service-buffer-expanded half-open window before inserting appointment rows
  (P0C.G3).
- **D-029 - Reschedule is atomic cancel-and-rebook.** Appointment lifecycle transitions are
  enforced in `AppointmentService`; reschedule marks the old appointment `rescheduled`, frees its
  resource rows, and books the replacement through `BookingService` inside one transaction so the
  old slot and new slot change together or not at all (P0C.G4).
- **D-030 - Appointment reminders are ledger-idempotent and consent-gated at send time.** Reminder
  dispatch creates one `appointment_reminders` row per appointment/type/channel, queues Redis jobs,
  and the job locks the row before re-checking active appointment state and
  `ConsentService::has(patient, 'comms.email')`; no consent means skipped, not sent (P0C.G5).
- **D-031 - Public booking uses tenant slugs and the existing safe booking path.** Public online
  booking is mounted under `/book/{tenant:slug}` so tenant context can be established without staff
  auth; it exposes only active `bookable_online` services, rate-limits requests, runs demographic
  duplicate detection before patient creation, and calls the same locked `BookingService` path with
  `source=online` and `booked_by=null` (P0C.G6).
- **D-032 - Every future agent must enter through AiCore governance.** Real agent behavior is not
  added until after the safety runtime: `LlmManager` budget/circuit checks, append-only
  `ai_interactions`, hash-pinned prompts, declared tools with RBAC/autonomy, approval queue, kill
  switch, visible draft label, and app-layer audit events. Clinical and financial tool categories
  are hard-capped at `approve`, never `auto` (P0C.G7).
- **D-033 - Front-Desk Agent is KB-only and Scheduler Agent is approval-first.** Front-Desk answers
  only from active same-tenant KB articles with citation and lexical support after retrieval;
  unknowns escalate and medical/symptom/triage/dosing questions are refused with handoff. Scheduler
  tools are app-layer AiCore tools wrapping Scheduling services, capped at `approve`; waitlist
  booking is impossible before approval queue execution (P0C.G8).
- **D-034 - Vue components are presentational; behavior is enforced server-side.** Authorization,
  validation, and state-transition rules live in controllers/services/policies and are covered by
  behavior-focused feature tests. Vue may display available actions, but tests must assert HTTP
  status, redirects, DB state, audit rows, and Inertia component/props rather than markup, DOM
  structure, or CSS classes (P0D.GU).
- **D-035 - Clinical notes are structured SOAP and signed notes are immutable.** Clinical notes
  store subjective/objective/assessment/plan sections directly. Drafts are editable, but once a
  note is signed it is frozen at both the Eloquent and DB-trigger levels; later corrections are
  visible superseding note versions with mandatory amendment reasons, never destructive edits
  (P0D.G2).
- **D-036 - Allergy hard-stops are exact-match deterministic rules only.** Medication recording
  blocks only when the normalized requested `substance_key` exactly equals an active documented
  allergy `substance_key` for the same patient. CareOS does not perform fuzzy matching,
  drug-class inference, interaction checking, dose calculation, or clinical decision support in
  this rule; those remain deferred medical-device territory. A clinician with `allergy.override`
  may override only with a reason, and the override is audited (P0D.G3).
- **D-037 - Clinical document files are private and controller-streamed.** Document metadata is
  tenant-owned in `documents`, while file bytes live under a generated
  `tenants/{tenant}/clinical-documents/{patient}/{ulid}` private storage path. No user filename is
  used to derive storage paths, no public URL is exposed, and every staff or portal download must
  pass through RBAC/tenant/portal-share checks and write a patient-scoped read audit row (P0D.G4).
- **D-038 - Unsigned-note worklists are own-drafts by default, supervisor-wide by permission.**
  `UnsignedNotesWorklist` returns aged draft notes ordered oldest-first. Clinicians without
  `note.supervise` see only drafts authored by their own staff profile; `note.supervise` users
  see tenant-team drafts. The starter `org_admin` role receives `note.supervise`; doctor/nurse do
  not by default (P0D.G6).
- **D-039 - Recalls are deterministic rule output; cross-tenant referrals need share objects.**
  `RecallEngine` evaluates explicit tenant-owned JSON criteria against patient/problem/encounter
  data only. Current criteria are exact active problem-code membership plus exact missing
  encounter type inside the configured interval; no AI, inference, triage, or clinical judgement
  selects recipients. Referrals to another CareOS tenant are not implemented by widening tenant
  scope; external referrals are provider-name records until explicit cross-tenant share objects
  are designed (P0D.G5).
- **D-040 - Clinical agents are suggest-only, extractive/template-bound, and source-validated.**
  Summary output may contain only existing patient-record content and every line must resolve to
  that patient's signed note SOAP field or clinical-list row; unsourced lines are rejected and
  interpretive/diagnostic requests are refused. Follow-up drafts may use only deterministic
  recall rows selected by `RecallEngine` plus clinician-authored templates, never selecting
  recipients or adding medical advice. Both clinical tools have explicit `suggest` ceilings even
  beyond the clinical category cap (P0D.G8).
- **D-041 - Nursing service agreements are contract records, not generated schedules.**
  Service agreements store the authorized patient/branch/funding window and child
  `agreement_services` store documented planned frequency text, required qualification, and
  duration. Visit schedule generation remains for later Nursing gates. The lifecycle is service-
  enforced (`draft -> active/ended`, `active -> suspended/ended`, `suspended -> active/ended`,
  `ended` terminal), and `agreement.manage` belongs to org-admin plus a new coordinator starter
  role (P0E.G1).
- **D-042 - Planned nursing visits use Recurr for RRULE expansion and store UTC windows.**
  CareOS uses `simshaun/recurr` for RFC 5545 RRULE parsing instead of hand-rolled recurrence
  code. The current PHP 8.2 stack pins the compatible `^5.0` line because Recurr v6 requires PHP
  8.4. Visit generation expands local wall-clock occurrences in the plan timezone, stores
  arrival windows as UTC instants, and uses the unique `(tenant_id, visit_plan_id,
  scheduled_date)` key plus upsert so materialization is idempotent without resurrecting
  cancelled occurrences (P0E.G2).
- **D-043 - Nursing dispatch validates deterministically and serializes on nurse resources.**
  Visit assignment uses tenant-owned `nurse_constraints` for exact qualification, max weekly hours,
  and max travel minutes. Travel feasibility is deterministic straight-line distance divided by
  tenant setting `nursing.dispatch.average_speed_kmh` (default 40), not a routing API. Assignment
  locks the planned visit, nurse resource, and candidate assigned visits with `FOR UPDATE` before
  persisting, so overlapping concurrent contenders for one nurse serialize and only one wins
  (P0E.G3).
- **D-044 / D-E3 - GPS proof-of-visit is point-in-time, not surveillance.** Nursing captures GPS
  only at check-in and check-out. There is no continuous location tracking, background location
  collection, or route capture. If GPS is unavailable or denied, a manual fallback is allowed only
  with a non-empty reason. Geofence distance is computed for review and audit context but never
  auto-blocks a visit, because a nurse may legitimately meet a patient away from the planned
  address (P0E.G4).
- **D-045 / D-E2 - Nurse PWA day-packs are encrypted, session-bound, and one-day scoped.** The
  separate `nurse-pwa/` app stores only AES-GCM ciphertext in Dexie/IndexedDB. Its key is derived
  from the current device session token with HKDF and kept only in JavaScript memory; the token,
  salt, and key are never persisted. Logout, any 401/403 sync response, and the configurable idle
  timeout wipe the local store. The server day-pack endpoint returns only today's assigned visits
  for the authenticated nurse resource plus the minimum related patient data, and writes one
  patient-scoped `read` audit row per included patient (P0E.G5).
- **D-046 / D-E1 - Offline sync conflicts resolve by domain ownership.** Nurse PWA replay is
  idempotent through tenant-scoped client action UUIDs. The server owns schedule truth: cancelled
  or reassigned visits reject schedule-affecting actions with an explanatory code. The client owns
  nurse-authored note/observation content: notes are persisted even when schedule changed and are
  flagged for review. Ambiguous conflicts are never silently resolved; they create
  `sync_conflicts` rows for human review (P0E.G6).
- **D-047 - Visit execution notes are nurse observations, not signed clinical SOAP notes.** E.7
  stores offline nurse visit documentation in `visit_notes` and syncs it idempotently through the
  nurse outbox. It is patient/visit scoped and audited, but it is not a `clinical_note` and does
  not use D.2 sign-and-lock semantics. Clinician countersigning is deferred (P0E.G7).
- **D-048 - Nursing timesheets use actual proof events; incidents keep reporter-selected severity.**
  Timesheet minutes are derived from visit proof `check_in` / `check_out` event times only, never
  from planned or scheduled duration. Missing checkout, manual proof, and duration deviation are
  flagged for human review rather than guessed or auto-corrected; approved lines become immutable.
  Incident severity is stored exactly as selected by the reporter. CareOS does not assess incident
  severity, advise action, or escalate based on clinical judgment (P0E.G8).
- **D-049 - Dispatch agent proposals are validator-bound and approval-only.** The Nursing Dispatch
  agent is operational/logistics-only and may reason only about qualification, time windows,
  straight-line travel, and hour caps. Every proposed assignment/replan is re-run through the
  deterministic `AssignmentValidator` before an approval action exists; invalid proposals are
  logged and rejected before surfacing. Pending proposals assign nothing, and approval executes
  only through `VisitAssignmentService::assign()` under the E.3 locking discipline. Clinically
  framed prioritization requests are refused with handoff (P0E.G9).
- **D-050 / D-F1 - Tariff catalogs are effective-dated and money-safe.** Billable items live in
  tenant-owned versioned tariff catalogs. A service date resolves to the catalog version active on
  that date, so historical work bills at the historical price even if entered later. Catalog
  versions for the same tenant/key must not overlap. Prices are integer minor units and VAT rates
  are integer basis points; floats are not used for billing values (P0F.G1).
- **D-051 / D-F2 - Charges snapshot tariff values at capture.** A charge copies the tariff code,
  description, unit price, and VAT basis points from the resolved tariff item at capture time.
  Later tariff edits never mutate existing charge economics and existing charges are not
  re-resolved when read (P0F.G2).
- **D-052 / D-F3 - Billing arithmetic is integer line-first arithmetic.** Charge line totals are
  `quantity * unit_price_minor`. VAT is computed later per line from the snapshotted line total
  and `vat_rate_bp` using round-half-up; invoice code must never round a summed subtotal or use
  floats (P0F.G2).
- **D-053 / D-F4 - Billing validation behavior is catalog-versioned and golden-file locked.**
  Charge validation consumes the tariff catalog version's deterministic JSON rules and returns
  distinct reason codes for every violation. Existing catalog-version behavior is frozen by JSON
  golden files that assert exact validated/violation output; changing behavior for an existing
  catalog version must deliberately update the golden fixture (P0F.G3).
- **D-054 / D-F5 - Issued invoices are fully frozen; balances live separately and credit notes use
  `CN`.** Invoice numbers are assigned only at issue time by locking the per-tenant/per-series
  `invoice_sequences` row. After issue, the legal `invoices` row and `invoice_lines` are immutable
  at model and DB-trigger levels; F.5 payment/open-balance changes must use `invoice_balances`
  instead of trigger exceptions on invoice fields. Credit notes are separate `CN`-series issued
  documents with their own gapless numbers and negative lines referencing the original invoice
  lines; the original invoice document remains untouched (P0F.G4).
- **D-055 / D-F6 - Payments and their allocations are append-only; balances are derived, not stored.**
  `payments`, `refunds`, and `payment_allocations` are tenant-owned and append-only at model and
  DB-trigger levels (`SIGNAL SQLSTATE '45000'` on UPDATE/DELETE). Money movement is only ever a new
  row: de-allocation is a reversal row carrying the exact negative of the allocation it references
  (`reverses_allocation_id`), and a refund is a separate row referencing the payment, never a negative
  payment. `unallocated(payment) = amount - net allocations - refunds` and `openBalance(invoice) =
  total - net allocations` are derived by exact integer arithmetic, never stored-and-drifting; the
  mutable `invoice_balances` projection is refreshed to the derived value while the frozen `invoices`
  row is never touched. Allocation is guarded in BOTH directions (cannot exceed the invoice open
  balance or the payment remainder) and serializes concurrent contenders with `FOR UPDATE` locks on
  the payment row then the `invoice_balances` row, proven by a real-process parallel hammer.
  Refund rule: refunds may draw only on the payment's unallocated remainder; to refund already-applied
  money the allocation must be reversed first, so an invoice balance and a refund can never silently
  disagree. Overpayment is never absorbed or auto-applied; the remainder stays visibly unallocated
  (P0F.G5).
- **D-056 / D-F7 - Dunning is deterministic, append-only, pausable, and consent-exempt.** Overdue-invoice
  reminders are driven by the tenant setting `billing.dunning` (levels with `days_past_due` thresholds,
  per-level template text, and an optional per-level fee code). `DunningService::evaluate(tenant, asOf,
  actor)` is a pure function of invoice state at an as-of date: it creates the append-only
  `dunning_events` that should exist (levels whose threshold is met, in ascending order, never skipping
  a level, at most once per invoice via `unique(tenant, invoice, level)`), so re-running for the same
  date creates nothing. It targets only `series=INV` invoices with `invoice_balances.open_balance_minor
  > 0`, a `due_date`, and `dunning_paused = false`; paid and fully credit-noted invoices never dun.
  The per-invoice dispute pause is a `dunning_paused` flag on the mutable `invoice_balances` projection,
  never on the frozen `invoices` row. A dunning fee is a NEW draft charge captured through
  `ChargeCaptureService` (appearing on a future document) — never a mutation of the original invoice.
  `dunning_events` are append-only at model and DB-trigger levels; status (`created`/`sent`) is fixed at
  insert. CRITICAL LEGAL DISTINCTION: dunning is a contractual/legal communication, NOT marketing, so
  delivery is NOT gated on `comms.email` consent (unlike appointment reminders D-030 and recall outreach
  D-040); delivery reuses the notification-channel abstraction and is still audited. `billing:dunning-run`
  wraps evaluate; scheduling it is deferred. Also in this gate: `composer.json` sets
  `config.process-timeout: 0` because the full suite (~407s) exceeds Composer's default 300s
  process-timeout that `composer check` (run in CI) executes under (P0F.G6).
- **D-057 / D-F8 - Billing correctness is a set of integer invariants, checked and gated.** The
  reconciliation engine checks six invariants for a period in EXACT integer arithmetic (VAT always
  recomputed per D-F3, never rounding a sum): (I1) every issued invoice/CN total equals
  `sum(line_total) + sum(per-line VAT)`; (I2) every issued INV projection `invoice_balances.open`
  equals the derived open balance (`total − net allocations`, or 0 when cancelled by credit note) and
  lies in `[0, total]` — this catches a drifted projection; (I3) every payment amount equals
  `net allocated + refunded + remainder` with `remainder >= 0`; (I4) period issued non-CN invoice
  totals equal invoiced-charge totals and every invoiced charge is on exactly one non-CN invoice (none
  double-invoiced, none lost); (I5) every credit note references a real same-tenant original and never
  exceeds it; (I6) no orphan money — allocations/reversals/refunds all reference real same-tenant
  rows. A single minor unit of drift in any invariant fails the run and the report names the exact
  offending rows. Each run persists an append-only `reconciliation_runs` monthly-close artifact
  (model + DB triggers block UPDATE/DELETE). The accounting CSV export is GATED: it refuses to run
  unless the period's most recent reconciliation passed — you cannot hand an accountant unreconciled
  numbers. The export is a generic ledger CSV on the private disk; DATEV-style columns arrive with the
  DE statutory pack later. Both `run` and `export` require `billing.manage` and are audited
  (`billing.reconciled`, `billing.exported`) (P0F.G7).
- **D-058 / D-F9 - The Billing agent maps and flags; the deterministic engine decides.** The Billing
  agent runs entirely under C.7 AiCore governance with two FINANCIAL-category tools
  (`billing.suggest_charge_codes`, `billing.preflight_invoice`), both requiring `billing.manage` and
  hard-capped at `approve` — a requested `auto` degrades via `AutonomyPolicy::cap()`. Code-mapping
  suggestions exist only for a SIGNED-note encounter or a COMPLETED visit, must resolve through
  `TariffResolver` against the catalog version valid on the service date, and every rationale must be
  source-linked: its quoted text must literally resolve to real documented text of that patient
  (signed note SOAP sections or visit notes) or the suggestion is rejected in code before any
  approval-queue item exists. Agent-supplied prices are NEVER trusted — human approval captures
  through `ChargeCaptureService`, which re-resolves the tariff itself, so an agent-claimed price
  never reaches a charge row. Preflight EXPLAINS but the F.3 `ChargeValidator` DECIDES: reported
  violations are copied verbatim from the validator (LLM-claimed violations are discarded), proven by
  a seeded fuzz test (25 random charge sets, zero disagreements), and no invoice is ever issued by an
  agent — issuing stays a human action through `IssueService`. Clinically framed questions
  (treatment appropriateness, alternatives, patient condition) are refused with human handoff,
  `refused` ledger rows, and no agent action; all reads are patient-scoped read-logged with surface
  `billing_agent` (P0F.G8).
- **D-059 - Thread messages are append-only communications evidence.** What was communicated to a
  patient (or internally about care) can decide disputes about instructions, consent, and follow-up:
  `messages` rows are immutable at model and DB-trigger levels (`SIGNAL SQLSTATE '45000'` on
  UPDATE/DELETE) and corrections are NEW messages that leave the original standing — the same posture
  as `audit_events` and the financial ledgers. Threads themselves stay mutable (status,
  last_message_at); membership history is preserved via `removed_at`, never deletes. Internal staff
  threads are structurally patient-free: the thread guard rejects a `patient_id` on internal threads
  and the participant guard rejects patient participants on them, so internal clinical discussion can
  never leak into a patient-visible surface (P0G.G1).
- **D-060 / D-G4 - Consent gates patient-facing comms, EXCEPT legal/contractual communications, and the
  category lives on the TEMPLATE.** The notification engine derives the category (transactional | legal
  | marketing) from the versioned template — a caller-supplied category that mismatches is REJECTED, so
  a sender can never relabel marketing as legal to dodge the consent gate. Marketing and transactional
  messages to a patient require the channel's consent scope (`comms.email` for email), fail-closed with
  `skipped/no_consent` delivery records; legal messages (dunning per D-F7, statutory notices) are not
  consent-gated; staff recipients are internal and not consent-gated. Deliveries are append-only rows
  written once at attempt with the rendered SNAPSHOT (history is never re-rendered), and a sha256
  dedupe key with a unique index makes retries idempotent. The Phase C reminder and Phase F dunning
  senders were migrated onto the engine through app-layer channel bridges (D-017) with their suites
  passing unchanged (P0G.G2).
