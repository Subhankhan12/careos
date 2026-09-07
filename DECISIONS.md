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
- **D-061 / D-G1 - Telehealth is an EMBEDDED third-party WebRTC provider (LiveKit default) behind a
  swappable adapter.** `Modules\Comms\Contracts\TelehealthProvider` (createRoom/issueToken/endRoom) is
  the seam; media NEVER passes through or rests on CareOS servers. CareOS stores ONLY the room
  reference, participants, and join/leave timestamps — the schema has no media columns and a test
  asserts none appear. Join tokens are short-lived (TTL hard-capped at <= 600s), scoped to exactly one
  room + one identity + one role (staff and patient may publish/subscribe; nobody may record or
  administer), issued on demand, never stored, never logged. Provider credentials come from
  config/env only and a test proves they never reach logs or the audit trail (P0G.G4).
- **D-062 / D-G2 - Telehealth recording is DISABLED at the provider level.** Rooms are created with an
  explicit `recording_disabled => true` option that adapters REFUSE to create a room without; tokens
  carry `roomRecord/roomAdmin/recorder = false`, so recording cannot be initiated by any participant —
  not merely "we don't call the record API". Recording and transcripts are DEFERRED behind a funded
  consent + retention design and are never enabled without one (P0G.G4).
- **D-063 / D-G3 - The telehealth room is NOT the clinical record.** What is said in a call is
  documented in a Phase D SOAP note like any other encounter. No transcript, no audio capture, no AI
  listening to the call, ever — ELECTRIC FENCE. Join/leave proof rows are append-only (a leave fills
  `left_at` exactly once; the DB trigger forbids every other change and all deletes) (P0G.G4).
- **D-064 - Telehealth invitations are TRANSACTIONAL (D-G4 classification).** The invitation delivers a
  service the patient already booked — contract performance, not marketing and not a statutory notice —
  so it uses the transactional template category with the same consent posture as appointment
  reminders: consent-gated fail-closed on `comms.email` (skip + no_consent), while staff can always
  convey the join link directly. It is deliberately NOT classified legal because nothing legally
  compels its delivery, and consent-exemption is reserved for dunning-class communications (D-F7)
  (P0G.G4).
- **D-065 / D-G5 - The Inbox agent DRAFTS ONLY, grounded and electric-fenced.** Both tools
  (`comms.draft_reply`, `comms.classify_document`) carry explicit `suggest` ceilings — an attempted
  `auto`/`approve` degrades to `suggest` — and the agent NEVER sends: a pending draft posts nothing, and
  only an explicit human send runs `execute()` with the HUMAN as actor, posting through
  `ThreadService::postStaffMessage(..., aiAssisted: true)` so staff always see the origin while the
  patient simply receives a message from their care team. ELECTRIC FENCE: a patient message containing a
  clinical question (symptoms, medication, "should I come in?", "is this normal?", "is this rash getting
  worse?") is refused BEFORE any tool runs — NO draft is produced at all, a handoff note is returned, the
  thread is flagged for clinician attention (`threads.clinician_attention_at/reason`, audited), and the
  refusal is ledgered. Drafts are GROUNDED in exactly three sources — the thread's own message history,
  the tenant's ACTIVE KB articles, and the patient's own administrative facts (next appointment, invoice
  open balance) recomputed live and compared exactly — and an unsourced or unresolvable claim throws in
  code before any approval-queue item exists; anything ungroundable is a handoff, never a guess.
  Document classification is a suggestion only: a human confirms, the deterministic
  `DocumentService::reclassify` files the CATEGORY, and the patient match is NEVER auto-applied — a
  document is never moved between patients by this path (P0G.G6).
- **D-066 - Demo/seed data is anchored with explicit dates and never moves `now()` backwards.**
  `AuditService::verifyChain` replays a tenant's chain ordered by `occurred_at ASC, id ASC`, but
  `prev_hash` is linked at INSERT time. A seeder that rewinds `Carbon::setTestNow()` mid-run
  therefore writes rows whose verification order differs from their hash-link order, and the chain
  fails. Seeders that need historical data pass business dates as explicit arguments (service
  dates, issue/due dates, `received_on`, check-in/out device times) and leave `now()` alone; a
  seeder that must freeze time freezes it ONCE, to a constant, for the whole run (the P0F
  `SimulatedBillingMonthSeeder` pattern). Consequence for `DemoClinicSeeder` (P0P.G1): billing is
  anchored to the PREVIOUS full calendar month — every date is real and in the past, the
  tariff-version boundary sits mid-month, and the month reconciles at delta 0 — while scheduling,
  dispatch, and the live clinical surface are anchored to the CURRENT week. `IssueService::creditNote()`
  stamps `issue_date = now()` and takes no date argument, so the demo's partial credit note is dated
  at seed time against the previous month's invoice — which is exactly how a clinic credits a closed
  month, and it leaves the reconciled period's I5 legitimately empty rather than faking it (P0P.G1).
- **D-067 - The automation layer runs unattended sweeps as a RESOLVED tenant actor, never as a
  super-admin and never as nobody.** Dunning and reconciliation require an authorized actor by
  design (`Gate::allows('billing.manage')`) — there is no "no actor" path through them, and there
  should not be: the work is accountable. A scheduler has no logged-in user, so
  `SystemActorResolver::forPermission()` picks the LOWEST-ID user in the tenant who ALREADY holds
  the permission TENANT-WIDE (`PermissionService::has()` with no branch counts only all-branches
  assignments, so a branch-scoped role is never conscripted into a tenant-wide job). A platform
  super-admin is never chosen: super-admin bypasses every gate via `Gate::before`, so scheduling as
  one would silently run unattended work with more authority than any tenant user has. When nobody
  qualifies the tenant is SKIPPED, loudly, rather than escalated — a tenant with no billing manager
  gets no dunning run, not a dunning run executed by someone who was never granted the permission.
  Every sweep iterates `status = 'active'` tenants only; an unattended job has no business writing
  to a suspended tenant. Recall evaluation is the exception that proves the rule: `RecallEngine`
  takes `?User $actor` and writes its per-recall clinical audit event only when there is a real
  human to attribute it to, so the nightly sweep passes null — putting a clinician's name on a cron
  job would be a false entry in a clinical audit trail, which is worse than its absence. Recall
  rows still carry their own timestamps and every later lifecycle change is audited against the
  real person who made it (P0P.G2).
- **D-068 - The scheduled `billing:reconcile` IS the launch-blocker monitor, and a failure leaves
  three marks.** AGENTS.md blocks real invoicing until a period reconciles to the unit; a daily
  all-tenant reconcile of the CURRENT period turns that from a one-off gate into a standing signal.
  A failing run leaves: (1) the append-only `reconciliation_runs` row with `passed = false` and the
  full report — the evidence, written by the engine itself; (2) an `error`-level log line — what a
  log drain alerts on; (3) the `billing.reconciliation.alarm` tenant setting naming the period,
  run id, and failing invariants — a persisted flag an admin surface can read later WITHOUT
  scanning run history. No UI is built for it here (P.2 is below-waterline); only the signal. The
  alarm clears ONLY when a later run for the SAME period passes — a passing August never clears a
  broken June, because that drift is still there and still unfixed. A failing tenant never aborts
  the sweep: every tenant is reconciled, each failure alarms independently, and the command exits
  non-zero so the runner sees it too. `reconciliation_runs` is deliberately NOT row-idempotent —
  it is append-only and every run adds a row, which is the point: the history shows when drift
  appeared. It is nonetheless safe under repeated runs, because `check()` mutates no billing state
  and `AccountingExportService` gates on the LATEST run for a period (P0P.G2).
- **D-069 - Audit chain verification is a SCHEDULED alarm with its own append-only evidence.** The audit
  chain is hash-linked at insert and verified by replay (`AuditService::verifyChain`), but a break is
  invisible until somebody looks — and a break is the strongest tampering signal the system has, because
  reaching it means going around BOTH the model guards and the DB triggers. `audit:verify-chains` (daily
  01:30, `withoutOverlapping`, `onOneServer`) replays every ACTIVE tenant's chain and appends one
  `integrity_checks` row per tenant per run, pass OR fail. Recording the passes matters as much as the
  failures: it makes "the check ran and was clean on date X" provable later, and it turns a check that
  silently stopped running into a visible ABSENCE rather than a silent nothing. A failure additionally logs
  at ERROR level with the offending row id and exits non-zero. `integrity_checks` is itself append-only at
  model + DB-trigger level — the result of an integrity check is evidence, and evidence that can be
  rewritten afterwards is not evidence, least of all by whoever had a reason to rewrite it. The command
  lives in the APPLICATION layer, not the Audit module, because it needs Platform's Tenant/TenantContext
  and Audit may not depend on Platform (the `App\Audit\PlatformAuditContext` precedent); the
  `IntegrityCheck` model lives in Platform because it is tenant-owned and therefore needs
  `BelongsToTenant`, which Audit may not import (P0P.G3).
- **D-070 — Demand-driven parked backlog: build when a real user/customer creates the need.**
  Work that is neither in-flight nor required by anything shipped is PARKED in DEFERRED.md's
  "Parked" section rather than built speculatively — building ahead of need adds surface, cost, and
  risk before anyone benefits. Every parked item records a TRIGGER: the concrete signal (a design
  partner asking, a paying customer, a nurse reporting the estimate is wrong, a country prospect,
  a completed consent/retention design, a polling-latency complaint) that graduates it from parked
  to planned. The list keeps the item from being forgotten without letting it be pre-built. Phase H
  agents, AI-credits metering/billing, real nurse-travel routing, DE/CH/FR statutory packs,
  cross-tenant referral share objects, telehealth recording+transcripts, Reverb realtime, i18n
  content, portal PSP payment, and the Playwright offline test are all parked this way (P0P.G5).
- **D-071 — Agent safety properties are locked by a dedicated eval suite.** `tests/Evals/` is a
  first-class, named regression suite (`Evals` phpunit testsuite; `composer eval`; also inside
  `composer check`) whose sole job is to fail loudly if any agent's electric fence, autonomy cap,
  grounding, or "never trust the agent's numbers" rule is ever weakened. Evals are deterministic,
  mock the LLM with fixed inputs, make no real API call (`evNoNetwork()`), and assert BEHAVIOR — not
  model quality. An eval encodes CURRENT proven behavior; it LOCKS, it never changes. If authoring an
  eval reveals the behavior is actually wrong, STOP and report rather than editing the eval to pass.
  Every new agent/tool must ship with matching evals and a `docs/AGENT-EVALS.md` entry (P0P.G4).
- **D-072 — CSV patient import: new `Modules\Import`, mandatory dry-run, real services only.** The
  onboarding/migration importer is its own module named **Import** (chosen over `Migration` to avoid
  confusion with database migrations). It maps arbitrary CSV columns to CareOS patient fields and
  imports ONLY through the existing `PatientService`/`PatientMergeService` (never raw inserts), so
  MRN generation, fail-closed tenancy, validation, and audit all apply unchanged. A dry-run
  (`ImportValidator`) is MANDATORY and writes nothing — it validates every row, parses dates via a
  user-selected explicit format, and runs the existing `DuplicateDetector`; a separate `commit`
  (`ImportCommitter`) performs the import, is idempotent (batch + row status guards), audited
  (`patient.import.committed`), and defaults the duplicate policy to SKIP (`import_as_new`/`merge`
  opt-in; `merge` uses the audited merge path). Uploads land on the private disk, tenant-prefixed,
  no public URL. New permission `data.import` (org_admin only by default). CSV parsing uses
  `league/csv` — never hand-rolled (P0P.G6).
- **D-073 — Waitlist auto-fill offers are time-boxed and always book through BookingService.** A freed
  slot is offered to a matching waitlist patient via a persisted `waitlist_offers` row with a lifecycle
  (offered→accepted/declined/expired) and a SHORT TTL (`scheduling.waitlist.offer_ttl_minutes`, default
  30 min) so an unresponsive patient never holds a slot indefinitely. `WaitlistOfferService::accept`
  books exclusively through the existing `BookingService::book` (the no-double-book resource-lock path),
  so two concurrent accepts of the same freed slot resolve to exactly one appointment (hammer-proven);
  decline/expire release the hold and the entry stays `waiting` for the next candidate. The offer
  notification is TRANSACTIONAL and consent-gated (`comms.email`, D-G4) and is composed in the APP LAYER
  (a listener on `WaitlistOfferLifecycleChanged` calling the Comms `NotificationService`) because
  Scheduling may not depend on Comms — mirroring the D-017 reminder/dunning bridges. The reception UI is
  additive on the day-board (net-new panel + props), presentational per P0D.GU (P0P.G9).
- **D-074 — Self check-in: new `Modules\FrontDesk`, one CheckInService, two identity-verified paths,
  check-in stored on the appointment.** Patients confirm arrival + self-update ONLY their own contact
  fields via a shared kiosk (no login) or the authenticated portal. Check-in data lives ON the appointment
  (`checked_in_at`/`check_in_source`/`check_in_code`) rather than a separate table — it is a 1:1 attribute
  next to the lifecycle. Arrival always goes through the existing `AppointmentService` (a patient-actor
  `arriveForPatient`, no staff gate — identity is verified upstream); contact edits go through the existing
  `PatientService` (no demographic field writable); both are patient-scoped audited and idempotent. KIOSK
  SAFETY is absolute: it shows only "confirm your appointment" + own contact fields after an EXACT
  name+DOB+code match to exactly one today/this-branch booked appointment; an ambiguous/failed match returns
  a generic not-found with zero PHI (never a candidate list); no clinical data and no patient browsing are
  reachable; a successful resolve mints a short-lived `Crypt` verification handle so the branch-scoped,
  revocable kiosk device token can never act on an arbitrary patient; the kiosk page is ephemeral
  (in-memory only, no localStorage, idle auto-reset); code entry is rate-limited. The portal path runs
  behind portal-tenant/auth/consent and is own-appointment-only. FrontDesk may use Patients/Scheduling +
  Audit services, never Audit/AiCore models (P0P.G7).
- **D-075 — Recurring appointment series: expand once, book each occurrence through BookingService,
  never silently skip a conflict.** A repeating clinic appointment ("every Tuesday 09:00 for 6 weeks") is
  a new `appointment_series` (in `Modules\Scheduling` — chosen over FrontDesk because it owns appointments +
  BookingService + the RRULE lane) whose occurrences are ORDINARY appointments linked by `series_id`. The
  RRULE is expanded with `recurr` in the series timezone (the E.2/DST-safe approach — the local `start_time`
  is re-anchored per occurrence so wall-clock is preserved across DST), and EVERY occurrence is booked
  through the existing no-double-book `BookingService::book`. Conflict policy: book all free occurrences and
  return a failure report `{date, reason}` for the rest — NEVER silently skip and NEVER partially corrupt.
  A read-only `BookingService::checkAvailability` powers the pre-confirm free/conflict preview. Per-occurrence
  exceptions reuse the existing lifecycle (cancel/reschedule one appointment leaves the series + rule
  intact); `end()` stops future generation without touching booked occurrences. Net-new day-board panel,
  presentational per P0D.GU (P0P.G8).
- **D-076 — Structured clinical orders record fact, never interpretation; lab connectivity is a stub.**
  A clinician places a structured order (`Modules\Clinical`), tracks a status lifecycle, records a MANUAL
  result, and marks it reviewed. The electric fence is absolute: results are stored/shown RAW with NO
  range/flag/abnormal/colour/score anywhere (same as vitals, D-D3), and "reviewed" is a HUMAN attestation,
  never a system judgment. `order_results` is APPEND-ONLY (DB triggers block UPDATE/DELETE; corrections are
  new rows). The orderable list is TENANT-AUTHORED — no licensed/proprietary test catalog is bundled (a
  small generic starter template is seedable/editable). Electronic transmission + automated result
  ingestion (HL7/FHIR) are an INTERFACE (`LabConnectivity`) with ONLY a `ManualLabConnectivity` no-op —
  no real client is built; that is partner-and-market work, deferred (DEFERRED.md). RBAC `order.manage`
  (org_admin/doctor/nurse). Audited + patient-scoped read-logged; net-new additive chart tab + review
  worklist + catalog admin, presentational per P0D.GU (P0P.G11).
- **D-077 — Clinical dot-phrases expand only a fixed non-clinical placeholder whitelist.** Reusable text
  snippets (`Modules\Clinical`, `text_snippets`) — PERSONAL (private to the author) or SHARED (tenant-wide,
  `snippet.manage.shared` = org_admin + doctor). PERSONAL wins over SHARED on the same trigger.
  `SnippetService::expand` substitutes ONLY the FIXED whitelist (date, patient_first_name, patient_dob,
  clinician_name, branch_name) — it iterates the whitelist keys, never the caller's context, so a
  diagnosis/medication/allergy/vital/any clinical field is STRUCTURALLY impossible to substitute; unknown
  tokens are left literal, never guessed. No interpretation, no AI. Snippets are NOT patient data (no
  patient-scoped read-logging; shared changes audited because they affect everyone, personal lightly
  logged). Editor integration is ADDITIVE — a new OPTIONAL `snippets` prop on NoteEditor (pre-expanded
  server-side) + an insert control; no existing prop/behavior changed (P0P.G10, the previously-skipped
  gate).
- **D-078 — Nurse competencies are tenant-authored; the AGENCY sets each one's enforcement (hard/soft);
  the validator obeys.** Finer-grained than the RN/LPN/care-assistant qualification. TWO tenant-owned
  tables: `competencies` (tenant's own code/name/description, `enforcement` hard|soft, active; unique
  tenant+code; NO bundled licensed set — `CompetencyService::seedStarter()` seeds a generic EDITABLE
  template wound_care/catheter_care/injection/dementia_care/palliative with default enforcement the
  agency can change) and `nurse_competencies` (grant of a competency to a practitioner resource with
  `granted_at` + nullable `expires_at`; a competency is HELD only if the grant is active AND not expired —
  mirrors the credential-vault expiry, D-020 lineage). A visit's required competencies reuse the existing
  requirement path: `required_competencies` JSON (codes) on `agreement_services`, copied onto each
  generated `planned_visit` by `VisitPlanGenerator` (like `required_qualification`); the planned_visit's
  own list is the per-occurrence authority. `AssignmentValidator::evaluate()` returns a new
  `AssignmentValidation` value object that CLEANLY SEPARATES blocking violations from non-blocking
  warnings; the legacy `validate()` returns only the blocking list (existing reason codes + hard-competency
  misses intact, so the dispatch agent's "no reasons" contract is unchanged). Per required competency the
  nurse does not hold: enforcement HARD → a BLOCKING reason `competency_missing_hard:<code>` (assignment
  REFUSED, exactly like a qualification miss); SOFT → a NON-BLOCKING advisory `competency_missing_soft:<code>`
  (allowed, dispatcher sees it and proceeds). A required code with NO active tenant competency definition is
  advisory-only, never a hard block — the system never blocks on a rule the agency has not configured as
  hard (same electric-fence posture: humans own the clinical judgment). The rule composes with
  qualification/window/travel/hour-cap; the concurrency-safe path (`VisitAssignmentService`, FOR UPDATE,
  parallel-hammer) is UNCHANGED — competency is just another rule inside `evaluate()`. Soft warnings are
  surfaced to the dispatcher (transient `PlannedVisit::$assignmentWarnings`, flashed to the board) and the
  override is recorded in the `planned_visit.assigned` audit context (`soft_competency_warnings`). New RBAC
  `competency.manage` (org_admin + coordinator, the dispatch-owning roles; reception/nurse/doctor/billing
  refused); definition/enforcement changes and grant/revoke audited via app-layer `CompetencyChanged` /
  `NurseCompetencyChanged` events (patient_id null — this is agency dispatch policy, not patient data).
  Net-new additive `Nursing/Competencies.vue` admin page + dispatch soft-warning banner; no existing
  dispatch page contract changed (P0P.G12).
- **D-079 — Vitals history is one UNIFIED per-metric series merged from BOTH stores; raw values only.**
  `vitals` (Clinical, staff/encounter-captured) and `visit_vitals` (Nursing, PWA-captured) are separate
  tables with the same D.3 shape; a history that showed only one would silently hide half a patient's
  readings. `VitalsSeries` (pure, `Modules\Clinical\Support`, no model deps) merges a flat list of
  readings into a per-metric, time-ordered (most-recent-first), source-tagged (`clinic`|`visit`) series;
  a metric null/absent in a reading is simply absent from that metric's series, NEVER zero-filled.
  `VitalsHistoryService` (Clinical) combines the Clinical `Vital` model with Nursing visit vitals read
  through the `VisitVitalsReader` CONTRACT — because the module boundary forbids Clinical→Nursing, the
  implementation (`App\Clinical\NursingVisitVitalsReader`, reads `Nursing\VisitVital`) lives in the app
  layer, which may depend on both; bound in `AppServiceProvider`. The Nursing `DayPackService` (Nursing
  MAY use Clinical) calls the same service for a SMALL recent history (5 per metric) so it stays the single
  source of truth. ELECTRIC FENCE is absolute: the output carries ONLY `{recorded_at, value, source}` per
  point — no band/range/flag/normal/abnormal/score/arrow/delta/min/max anywhere (asserted in PHP + PWA
  tests). Chart: additive companion prop `vitalsHistory` (the existing flat `vitals` prop is untouched);
  the vitals tab renders a neutral per-metric table (value+time+source). Nurse PWA: the day-pack patient
  payload gains `vitals_history`, shown above the capture form as raw values over time; it rides the D-E2
  encrypted store (encrypted at rest, wiped on logout/401/idle — asserted) and the existing per-patient
  read-audit is extended with `includes_vitals_history=true`. No new storage schema (data already exists
  per-reading with timestamps). Both stores are tenant-owned so the merge is fail-closed + patient-scoped
  (P0P.G13).
- **D-080 — Reporting is a read-only facts layer: universal aggregates, no judgments, no UI until
  discovery.** New `Modules\Reporting` (owns NO tables, runs NO migrations, performs NO writes — proven by
  an audit-row-count-unchanged test). `MetricsService` exposes the UNIVERSAL set every clinic wants
  regardless of market: OPERATIONAL appointments-in-range (total + zero-filled per-status breakdown),
  no-shows ({no_show, scheduled, rate} — denominator = ALL appointments in range, documented),
  checked-in count (P0P.G7 `checked_in_at` moment), nursing visits completed (by `scheduled_start_at`),
  active patients (distinct patients with any appointment/encounter/visit in range — a count, never a
  list); FINANCIAL in integer minor units reusing the F.7 definitions VERBATIM so numbers reconcile with
  billing (invoiced total = I4's series=INV + frozen statuses + `issue_date` in range; payments by
  `received_on`; outstanding = point-in-time sum of the I2 `invoice_balances` projection; aging buckets
  current/1-30/31-60/61-90/90+ by factual days past `due_date` — no "bad debt" labeling); THROUGHPUT
  counts only (encounters, signed notes, orders placed). Facts, not judgments: results carry ONLY
  numbers — a recursive shape test asserts no good/bad/high/low/status/grade/score/label keys and every
  leaf is int|float; the electric fence excludes any clinically-interpretive aggregate (no "sickest
  patients", no risk scores). Aggregates are NOT patient records → NO patient-scoped read-audit rows
  (a metric that could resolve to a single patient must be treated as a patient read — none in this set).
  RBAC: NEW `reporting.view` (org_admin + coordinator) gates operational + throughput; existing
  `billing.view` gates financial; `ReportingService::summary` requires `reporting.view` and includes the
  financial section only when the actor also holds `billing.view` (omitted otherwise). Branch filtering
  only where the table has branch_id (appointments/visits/encounters); invoices/payments/notes/orders
  have no branch dimension. `reporting:summary {tenant} {from} {to}` prints the bundle as JSON with a
  D-067-resolved actor — a command, NOT a UI; dashboards are deliberately deferred until discovery says
  which metrics matter (P0P.G14).
- **D-081 — Mutable moment columns are DATETIME everywhere; MySQL 8 parity is CI-asserted, and CI-only
  failures are treated as env-divergence first.** The P0P.G15 sweep queried `information_schema` on the
  dev engine and found MariaDB 10.4's implicit `ON UPDATE CURRENT_TIMESTAMP` (first non-nullable
  TIMESTAMP column; `explicit_defaults_for_timestamp=OFF`) on NINE columns — six harmless (append-only
  ledgers whose UPDATE is trigger-blocked: ai_interactions, integrity_checks, messages,
  payment_allocations, reconciliation_runs, refunds) and THREE reachable divergences fixed to DATETIME:
  `patient_consents.granted_at` (consent WITHDRAWAL silently rewrote the legally meaningful grant moment
  on MariaDB, preserved on MySQL 8 — a real cross-engine data bug, regression-tested fail-first),
  `portal_login_tokens.expires_at` (rewritten on consumption), `thread_reads.read_at` (masked trap).
  `MutableMomentParityTest` locks it engine-independently: an information_schema guard fails on ANY
  engine if a non-append-only table carries an `on update` column. Separately, the CI red streak since
  P0P.G7 (8 commits, unnoticed because gates ran local checks) was NOT an engine bug: CI's job-level
  `CACHE_STORE=redis` beats phpunit `<env>` (same class as the P0G.G2 queue incident), so kiosk throttle
  counters persisted across tests in real Redis → 429s only in CI. Fix: flush the cache store per test in
  CheckInTest (a config pin is insufficient — Fortify resolves the RateLimiter singleton at boot).
  Verification is now explicit: CI asserts ZERO pending migrations after the from-scratch MySQL 8
  migrate, and `composer test:mysql` (migrate:fresh + status + full suite against the env-configured,
  THROWAWAY database) is the documented one-step manual re-verification. All divergences + commands live
  in `docs/DB-PARITY.md` (P0P.G15).
- **D-082 — The Spitex demo is a COMPANION tenant, honest to what's built.** `DemoSpitexSeeder` seeds
  "Spitex Sonnengarten" (slug `spitex-sonnengarten`, Zürich Wipkingen, EUR) as a SECOND demo tenant
  rather than extending Praxis Lindenhof: a coordinator sees an agency shaped like their own operation
  (nurse roster with P.12 competencies incl. one expired grant, recurring RRULE home-care plans — daily
  insulin / 3×-weekly wound care / weekly bath assist / weekly catheter care / 2×-weekly palliative — a
  fully assigned current week, executed previous-month visits with GPS proof + tasks done/not-done +
  multi-visit vitals trends + notes + one factual incident + timesheets from actuals, a signed+amended
  assessment, severe allergy, care plan, P.11 manual-result orders in both worklist states, an
  EU-Generic billing month that reconciles to the unit with 6 gapless invoices / full+partial+over
  payments / a partial credit note / dunning L1, threads incl. one flagged-clinical, 2 KB articles, and
  2 pending do-nothing AI approvals), while the clinic demo stays intact; both coexist. Same P.1/D-066
  discipline: idempotent by slug, seeded through the REAL services as tenant actors (audit chain
  verifies), `now()` never rewound, business dates explicit. HONESTY boundary: bills EU-GENERIC — the
  CH/KVG pack is deferred pending discovery; no claims/eRx/electronic-lab data is implied, lab results
  are manual. Stand up the demo: `php artisan db:seed --class=DemoSpitexSeeder` (P0P.G16).
- **D-083 — Eucalyptus Glow is wired as the design foundation; re-skin ONLY, the @theme tokens are the
  single source of truth.** CLINIC.W1 (first wiring gate of the clinic-vertical delivery) establishes the
  Eucalyptus Glow palette in `resources/css/app.css`: euca-50..900 ramp (#F7FAF5→#35462F; 400 = brand,
  700 = interactive, 800 = hover, 900 = deep accent tile), ink #2A332A / #5A665A / #6C776C, warm surfaces
  #F4EFE6 + #FCFAF5, hairline #DCE8D7, workflow semantics danger #B4552D / warning #C99B3F / success
  #4E7A47 / info #5B7A8C (+ softs), plus reusable utilities `.euca-wash`, `.glass-card` (white .82→.5,
  blur 24px, radius 20px, eucalyptus glow shadow), `.euca-tile-dark` (exactly ONE deep tile per screen),
  `.btn-glow`, `.nav-pill-active`. Legacy `brand-*` tokens are REPOINTED onto the euca ramp so every
  un-re-skinned screen inherits the new palette with no per-screen edits. Per P0D.GU the wiring touches
  `.vue` / `.css` / `.json` ONLY — routes, controllers, props, guards, and TESTS are untouched, and the
  frozen `AppShellTest` assertInertia checks (component names + props) pass unchanged. Landings are
  PROPLESS: they render the full frame with "—" placeholders + empty states and bind ONLY to
  already-shared Inertia props (appName / locale / auth / flash) — never inventing a backend prop; a live
  date chip legitimately uses the client clock (presentational, not fabricated business data). Design
  fidelity comes from RENDERING the compiled prototype bundles (`resources/prototype/*.html`, now
  gitignored — 52 MB) with a browser and rebuilding cleanly as Vue, never lifting compiled markup. Two
  reusable conventions land here for later gates: the segmented `CodeInput` composes one form value, and
  vue-i18n messages must escape a literal `@` as `{'@'}` (it is the linked-message metacharacter — a raw
  `@` throws at compile time). Wire order for the remaining clinic+shared screens follows
  `docs/CLINIC-DELIVERY-MAP.md`. (CLINIC.W1)
- **D-084 — "Client Record" ≠ "Patient 360"; the front-desk household layer is a separate, unbuilt
  screen.** Wiring the patient screens in CLINIC.W2, rendering the prototype resolved a mapping
  ambiguity from the delivery map: the prototype **"Patient 360"** is `Patients/Show` (five fixed tabs;
  deep-eucalyptus header band + dormant AllergyBanner) and was wired. The prototype **"Client Record"**
  is a DISTINCT screen — a front-desk contact/consent/relationship layer keyed on a HOUSEHOLD/guarantor
  (route `GET /clients/{client}`, gate `client.view`; "Keller household", 3 patients, guarantor,
  preferred channel / quiet hours / tone, balance, upcoming recall) — for which NO backend exists
  (no `clients` route/gate/model; CareOS patients are individuals, not households). It is therefore NOT
  a re-skin target and belongs in the delivery map's Bucket 2 (needs backend); it is flagged, not faked.
  Corollary re-skin gaps in CLINIC.W2 were handled the same honest way, per P0D.GU (bind to existing
  props; never invent a backend prop): the Patient-360 header **Edit** + **Portal-invite** actions are
  OMITTED because `Patients/Show`'s `actions` prop exposes no URLs for them (no patient-edit route
  exists at all; `portal.invitations.store` exists but is not on the Show payload), and the
  **AllergyBanner** stays dormant behind an optional `allergies?` prop the backend does not yet send
  (render-when-present, exactly as the design prescribes). (CLINIC.W2)
- **D-085 — The patient portal is the SOFTER variant on its OWN layout; balances are display-only, no
  PSP.** CLINIC.W3 re-skins the seven authenticated portal pages to Eucalyptus Glow's patient-facing
  variant — 16px base, roomier glass cards, reassuring plain-language copy, bigger touch targets — on
  the portal's OWN `PortalLayout` (glass pill nav + the `portal-tenant` + `portal-auth` +
  `portal-consent` guard chain), NEVER the staff `AppLayout`. Per P0D.GU the wiring is `.vue`/`.json`
  ONLY; routes/controllers/props/actions/guards/TESTS are frozen and `PortalUiTest` passes unchanged
  (own-data-only, consent-lock, cross-tenant, self-book via `BookingService`, server-enforced cancel
  window, gated + read-logged telehealth token, staff/patient shell separation). NO payment processing
  is added anywhere — the PSP is deferred: the Home balance renders `minor/100` with NO currency symbol
  and the Invoices open balance renders `minor/100` + currency, both display-only with no pay button.
  Patient-facing invariants honored: no AI provenance crosses into the portal (Messages shows plain
  practice replies, no `ai_assisted` surfaces); the consent withdrawal gets a serious two-step confirm
  (presentational — the server call is unchanged) spelling out that withdrawing `portal.access` signs
  the patient out immediately; the telehealth "this call is not recorded" notice and in-memory-only
  token are kept. Honest gaps flagged not faked: the portal payload carries no patient NAME (generic
  time-based greeting) and no telehealth practitioner/time (generic "Video visit" title); unbacked
  prototype extras (Add-to-calendar, Directions, a live camera/mic checklist) are omitted. (CLINIC.W3)
- **D-086 — The staff-boards re-skin keeps the electric fence and kiosk safety exactly; prototype-only
  richness is flagged, not faked.** CLINIC.W4 re-skins the four staff operational surfaces (Reception
  Day-Board, Unified Inbox, Kiosk Check-in, Public Booking) to Eucalyptus Glow. Per P0D.GU the wiring is
  `.vue`/`.json` ONLY; routes/controllers/props/actions/guards/TESTS are frozen and `SchedulingUiTest` /
  `InboxUiTest` / `CheckInTest` pass unchanged. Load-bearing safety preserved verbatim: appointment
  status colours are WORKFLOW status only (booked/arrived/in-progress/completed/cancelled — never
  clinical, rendered as left-edge tints); the Inbox AI-draft box never auto-sends (explicit human Send,
  source chips, `ai_assisted` provenance pill, clinician-attention handoff banner) and now correctly
  HIDES "Request AI draft" on flagged threads; the Public-Booking non-emergency notice persists on every
  step and no symptom/triage free-text exists (D-031); the Kiosk shows NO clinical data / NO patient
  browsing, returns a generic not-found (no PHI), stays ephemeral in-memory, idle-auto-resets, and its
  verify step still submits the built name+dob+code contract. The prototypes show more than the backend
  provides; rather than invent props, those are flagged and OMITTED: the Day-Board glance band /
  waiting-room strip / booking-conflict resolver (need aggregates + a conflict endpoint that do not
  exist), the Inbox rich context pane (patient MRN/DOB/next-appointment/chart links + Edit/Discard draft
  endpoints not on the inbox payload — a minimal context pane + a client-only "Edit as reply" are used),
  and the Kiosk prototype's DOB-only keypad + masked-identity + insurance/consent/queue steps (which the
  backend cannot serve and which would breach the kiosk's own privacy posture). (CLINIC.W4)
- **D-087 — The clinical screens hold the electric fence in the UI; two prototypes are richer/unbuilt
  and are flagged, not adopted.** CLINIC.W5 (the final re-skin gate) re-skins Patient Chart, SOAP Note
  Editor, and the orders "to review" worklist, and wires Care Plans in the chart's care tab. Per P0D.GU
  the wiring is `.vue`/`.json` ONLY; routes/controllers/props/actions/guards/TESTS are frozen and
  `ClinicalUiTest` / `VitalsHistoryTest` / `ClinicalNoteTest` / `OrderTest` pass unchanged. The electric
  fence is preserved verbatim in the UI: vitals render RAW in neutral ink with NO ranges/bands/flags/
  colours/arrows/sparklines/scores (the P.13 trend view stays a neutral per-metric table — a sparkline
  would itself be interpretation); `dose_text` + order results are raw/as-documented; the AllergyBanner
  is prominent amber-soft (warning), never red; signed notes are read-only with a quiet lock line +
  plain-text wells + always-reachable version history + no edit/delete affordance and no red near the
  sign action; the AI chart-summary stays badged/dashed/source-linked with an explicit human Insert
  (never auto-inserted). Two prototype screens differ from what is built and are flagged, not faked:
  **"Treatment Plan"** is a dental, fee-schedule-priced, phased, billed-per-phase plan (route
  `Clinical/Treatment-plans`) with no backend — our built Care Plans (CarePlan + goals) render in the
  chart care tab; **"Lab Result Review"** is a single-result view with AI abnormal-flagging +
  electronic-lab integration (route `Clinical/Results`) that is unbuilt AND whose interpretation would
  breach the electric fence — the built raw/manual mark-reviewed OrdersReview worklist is wired instead
  and the AI-flagging is deliberately NOT adopted. Minor gaps also flagged: the Chart Brief-10
  find-in-chart well (omitted), rich encounter cards with note-preview/version-chains (not in the
  encounters prop → type/status/date only), and a dormant NoteEditor allergies mini behind an optional
  prop the backend doesn't send. In passing, W5 fixed a real PRE-EXISTING data-loss footgun the verify
  pass surfaced: the note editor bound `v-model` on a `const reactive` (Vue 3.5 rewrites it to a `let`
  reassignment), so editing 2+ SOAP sections silently discarded all but the last and stalled autosave —
  changed to mutate-in-place (`:model-value` + `@update:model-value="Object.assign(sections,$event)"`),
  no prop/emit/test change. With this, all five CLINIC re-skin gates (W1 foundation → W2 patients →
  W3 portal → W4 staff boards → W5 clinical) are landed and green — the Eucalyptus Glow clinic vertical
  is fully wired. (CLINIC.W5)
- **D-088 — The staff billing UI is a pure presentation layer over the frozen billing engine; the one
  aggregate it needed lives in the reporting service, never a controller.** CLINIC.W6 is the FIRST build
  gate after the W1–W5 re-skins: it adds NEW controllers (Invoice/Aging/CreditNote), 8 routes, 5 Inertia
  pages (Invoices Index/Show · AR-Aging · CreditNotes Index/Show), and a `billing` nav entry — all
  reading from / dispatching to the EXISTING tested engine. Hard rule held: NO billing math (invoicing,
  numbering, VAT, reconciliation, aging) is computed in any controller or view. Writes go ONLY through
  `IssueService::issue` / `::creditNote` (credit note = a `series=CN` Invoice row, reason required,
  original left byte-for-byte untouched); reads route through `invoice_balances` (live lifecycle status)
  + `MetricsService`; money stays integer minor units and views only format (`/100`). RBAC: reads gate
  `billing.view`, writes `billing.manage` — reception (no billing perms) 403s; a view-only role sees the
  data with `can_manage=false` and cannot issue/credit-note; cross-tenant `{invoice}` binding 404s.
  PHPStan L5 forced the codebase's sanctioned typed-query idiom (as in `PortalInvoiceController`): NO
  relation-property traversal (`$invoice->patient->x`, `$invoice->lines->map(...)`) because an untyped
  `BelongsTo`/`HasMany` resolves to base `Model` under larastan — instead concretely-typed queries /
  keyed lookups (`Patient::query`, `InvoiceBalance::query`, `InvoiceLine::query`), and explicit
  `$x !== null ? … : …` rather than `?->  ??` (which trips `nullsafe.neverNull`). The adversarial verify
  pass caught and fixed two self-inflicted rule breaches: (1) a client-side `isOverdue()` in the invoice
  list reimplemented aging with a timezone-buggy date-only compare that DISAGREED with the server's
  calendar-day buckets on any invoice due "today" east of UTC — removed; rows now show the real lifecycle
  status only, and "overdue" is a reporting figure, not a per-invoice state; (2) the overdue counter was
  summed from aging buckets with raw arithmetic IN the controller — the past-due roll-up moved into
  `MetricsService::overdueBalanceMinor()` (Reporting owns aging aggregation; the controller just calls
  it, and the test now asserts its VALUE: 0 when not yet due, = outstanding when wholly past due). Also
  aligned `download()` to serve `series=INV` only (a credit note 404s on the invoice-PDF route). NEW
  tests only (`tests/Feature/Billing/BillingUiTest.php`, 7 tests / 135 assertions); the frozen
  reconciliation / invariant / hammer / `InvoiceTest` suite is UNCHANGED and green. Deferred to billing
  part 2 and flagged (not faked): New-invoice / Record-payment / Send-reminder actions; practice
  letterhead / QR-reference / lifecycle-timeline / agent-provenance (not in backend); and the admin
  "Billing & AR" DSO / net-collection / roll-forward / write-off / bad-debt metrics (beyond backend, and
  bad-debt is deliberately excluded — only the factual aging-bucket table is built). (CLINIC.W6)
- **D-089 — The billing-part-2 + reporting UI is a pure presentation layer over the frozen engines; the
  CLINIC delivery is complete.** CLINIC.W7 (the FINAL clinic gate) adds NEW `PaymentController`
  (record / allocate / reverse via PaymentService — every money movement an APPEND-ONLY row; over-
  allocation and reversal rules enforced IN the service and surfaced as validation errors, never
  re-implemented), `InvoiceDraftController` (new-invoice-from-a-patient's-validated-charges via
  IssueService — the gapless-number + PDF path; the view never prices or sums, charges are pre-priced by
  TariffResolver and the total is whatever IssueService computes on issue), `DunningController` (overdue
  worklist + a "send reminders" action that dispatches the ONE idempotent, settings-policy-driven
  `DunningService::evaluate` — a fee is a NEW charge, the original invoice is untouched, and dunning is
  legal-comms so it is NOT consent-gated per D-F7), and `ReportingDashboardController` (the thin
  facts-only dashboard over `ReportingService::summary`). Plus 11 routes, 6 Inertia pages (Payments
  Index / Record / Show · Invoices/New · Dunning/Index · Reporting/Dashboard), a `reporting` nav entry,
  and billing-hub cross-links. HARD RULE held and adversarially verified: NO financial math (money sums,
  balances, remainders, aging, VAT, totals) is computed in ANY controller or view — a grep confirms every
  `_minor` is a service call (`PaymentService::unallocated` / `::openBalance`) or a model-attribute
  passthrough; the only view arithmetic is money formatting (`/100`), rate formatting (`*100` on a
  service-returned ratio), and major→minor INPUT normalisation on submit (the service validates the
  integer and owns all math). RBAC: payments/dunning read `billing.view`, reporting reads
  `reporting.view`, all writes `billing.manage`; the reporting `financial` section is omitted without
  `billing.view` (fail-closed — coordinator sees operational-only, the billing role 403s on reporting);
  cross-tenant `{payment}`/`{invoice}` 404. FACTS-ONLY reporting: the dashboard renders only the
  `summary` leaves in neutral styling with NO judgment/target/trend/grade fields, and a recursive test
  asserts no judgment key leaks. Prototype fidelity with omissions FLAGGED not faked: TWINT / QR-bill
  map to the backend's four methods (bank_transfer/card/cash/other), and PSP/card-capture /
  terminal-approval / receipt-email (Take Payment), camt.053 bank-import + auto-match (Payment
  Reconciliation), AI-drafted reminders + approval-escalation ladders (Invoice Overdue Reminder),
  per-patient running-balance ledger (AR Account Detail — would be view/controller money math), and
  every Practice-Reporting-Hub judgment metric (DSO / collection-rate / case-acceptance / recall-
  compliance / targets / trends / provider ranking / sparklines) are all OMITTED. NEW
  `tests/Feature/Billing/BillingUiPart2Test.php` (8 tests) only; the frozen payment / dunning /
  reconciliation / hammer / metrics suites are UNCHANGED. An adversarial 5-dimension review → skeptic-
  verify workflow returned 0 confirmed defects. With W7 the Eucalyptus Glow **CLINIC DELIVERY is
  COMPLETE** (W1 foundation → W2 patients → W3 portal → W4 staff boards → W5 clinical → W6 billing p1 →
  W7 billing p2 + reporting). (CLINIC.W7)
- **D-090 — Tenant-scoped route params are STRING ids resolved in-controller, never implicit
  route-model binding.** The QA audit's C-1 delivery-blocker: billing detail + all write actions (and
  the CSV-import preview) 500'd in the real browser with `TenantContextMissingException`. Root cause:
  `IdentifyTenantFromUser` is *appended* to the web group in `bootstrap/app.php`, so it runs AFTER
  Laravel's `SubstituteBindings`; a controller that IMPLICITLY binds a `BelongsToTenant` model
  (`show(Invoice $invoice)`) resolves it during `SubstituteBindings` — before the tenant context exists
  — and the fail-closed global scope throws. The whole rest of the app already dodges this by taking a
  **string id** and querying inside the action after the middleware runs (`PatientShowController(string
  $patient)`, `ClinicalChartController(string $patient)`). FIX.1 converts every affected action to that
  convention: InvoiceController `show/issue/creditNote/download`, CreditNoteController `show`,
  PaymentController `show/allocate/reverse`, and the pre-existing ImportBatchController
  `show/mapping/validateBatch/commit` — 12 actions, each now `string $id` →
  `Model::query()->whereKey($id)->firstOrFail()`. A missing/cross-tenant id 404s (fail-closed
  preserved); routes/URLs and all downstream service calls are byte-identical; no billing/payment/dunning
  LOGIC changed. An app-wide grep confirmed billing + import were the ONLY implicit-bound tenant models.
  **Test-gap lesson:** the W6/W7 tests stayed green because their fixtures pre-set the `TenantContext`
  singleton BEFORE the request, masking the middleware ordering. The new
  `tests/Feature/RouteBindingTenantContextTest.php` calls `TenantContext::forget()` after seeding so the
  request establishes context via the middleware like a real browser — it FAILS (500) on the old code
  and PASSES on the fix, and asserts 404 for missing/cross-tenant ids. (FIX.1)
- **D-091 — Date-only values render through one shared `formatDateOnly` helper (local-midnight parse);
  never `new Date(dateOnly)`.** The QA audit's M-2: `Intl.DateTimeFormat(...).format(new Date("1954-03-12"))`
  parses a date-only string as **UTC** midnight, so a viewer behind UTC sees the day BEFORE (Erika's DOB
  rendered `03/11/1954` on the patients index vs the stored `1954-03-12`; the AR "as of" was a day early).
  For a Swiss (UTC+1) deployment it's invisible; it is wrong for any behind-UTC viewer. FIX.3 adds
  `resources/js/lib/date.ts` — `formatDateOnly()` / `ageFromDateOnly()` — which parse a `^\d{4}-\d{2}-\d{2}$`
  string as **local** midnight (`` `${value}T00:00:00` ``) so the calendar day never shifts by timezone; a
  value carrying a time component is passed through unchanged. **Only date-only renders were converted**
  (Patients/Index DOB+age, Clinical/Chart age, and the six billing pages Invoices/Index+Show, Payments/Index,
  Dunning/Index, CreditNotes/Index, Aging) — **timestamped (datetime) rendering was deliberately NOT touched**
  (an encounter/message/access-log time is a real instant and must localise). Rule for new date-only UI:
  reach for `formatDateOnly`, never `new Date(dateOnly)`. Guarded by `resources/js/lib/date.test.ts` (new root
  Vitest config, `npm run test:unit`, TZ pinned `America/Los_Angeles`) — a self-validating test asserting the
  naive parse yields `03/11` in that zone while the helper yields `03/12`. Browser-re-confirmed in an
  America/Los_Angeles session (DOB shows `03/12/1954`). Same class as the W6 `isOverdue` date-only fix (D-088).
  (FIX.3)
- **D-092 — Delivery polish is presentation/demo-data only; it never moves stored data or an authorization
  decision.** FIX.4 cleared the QA audit's remaining Mediums/Lows under P0D.GU with three load-bearing rules that
  future work must keep: (a) **Vitals display in clinical units, storage stays base units.** Weight is stored in
  grams and height in millimetres; a display-only helper `resources/js/lib/units.ts` (`vitalDisplayValue`) rescales
  them to kg/cm AT RENDER (weight ÷1000 1dp, height ÷10 0dp). It is a pure rescale — the electric fence holds:
  still raw numbers, no ranges/flags/colours/scores; only `weight_g`/`height_mm` convert, every other metric
  (mmHg/bpm/°C/%) is already conventional and passes through untouched. Never scale vitals in storage or the
  services — convert only in the view. (b) **Client-side nav gating is a UX hint; the server Gate stays
  authoritative.** `HandleInertiaRequests` shares `auth.user.permissions` (the nav-relevant keys resolved via
  `$user->can()`, super-admins all-true via `Gate::before`); `AppLayout` hides links a role can't use. Hiding a
  link never grants access and never blocks it — the route's `Gate::authorize` still 403s on a typed URL, proven
  by an existing-behaviour test kept green alongside the new render test. (c) **Styled error screens are
  presentation only.** `bootstrap/app.php` renders an in-shell Inertia `Error` page for 403/404/419/503 (and the
  portal consent-withdrawal lockout, a 403 on a `portal.*` route → its own "access withdrawn" message) instead of
  the bare Symfony page; the status code — and therefore the authorization decision — is preserved. The renderer
  no-ops under `testing` so the suite's ~75 `assertForbidden`/`assertNotFound` assertions stay exact (the new
  render test forces a runtime env via `detectEnvironment`). Demo-data items in the same gate (M-6 realistic
  vitals, L-2 clinic rooms/chairs not vehicles, L-3 clinic currency CHF) touch only `DemoClinicSeeder`; amounts
  stay integer minor so the P.16 reconcile (`delta_minor === 0`) + audit chain stay green. (FIX.4)
- **D-093 — CI carries a route-reachability smoke that drives every major route through the REAL middleware
  stack, so a request-time 500 (the C-1 class) can never ship green again.** `tests/Feature/Smoke/RouteSmokeTest.php`
  hits every major GET route (all six staff roles + a portal patient: landings, patients index/show/register,
  day-board, dispatch, competencies, inbox, clinical chart/encounter/note/note-edit/orders/snippets, billing
  index+detail (invoices/CN/payments) + aging/dunning/new-invoice/PDF, reporting, CSV import index/create/show,
  admin/kiosks, public booking, all portal pages) and asserts each returns 200 — never a 500/419 — plus per-role
  RBAC (e.g. reception → 403 on `/billing/invoices` by URL). **The load-bearing detail:** it calls
  `TenantContext::forget()` BEFORE each request, so `IdentifyTenantFromUser` must (re)establish context via the
  middleware exactly as an independent browser request does. That is precisely the condition C-1 exploited and the
  pre-seeded W6/W7 feature tests masked (they `set()` the context singleton before the request) — so this smoke
  WOULD have caught C-1 (proven: it's the generalisation of the FIX.1 regression test that failed 500 on the old
  implicit-binding controllers). **Chose request-level Pest over a headless browser in CI:** it runs in the
  existing MySQL-8 Pest job on every push, is deterministic and fast (~46s), and exercises the identical
  middleware pipeline C-1 broke — with none of the artisan-serve / browser-install / TOTP-timing flakiness a
  browser-in-CI would add (reliability was the explicit requirement; the C-1 class is a server-side 500 fully
  covered here). Wired as a dedicated fast-fail CI step (`composer test:smoke`, before `composer check`) AND it
  runs inside the full suite; local run via `composer test:smoke` / `npm run test:smoke`. Maintainability: a single
  route list in the test — a new page is one line. NO app logic changed (test infra + CI only). (FIX.5)
- **D-094 — Settings + Roles/access admin are a presentation layer over EXISTING backends; they wire what round-trips
  and flag the rest, and role assignment stays on the sanctioned audited path.** CLINIC.W8 built the two admin
  screens a paying clinic needs day-one (the QA audit's not-wired "settings" + "RBAC-UI" gaps), UI-over-tested-backend
  like W6/W7 billing, no domain logic. **Settings** (`Modules/Platform/.../SettingsController`, `/settings`,
  admin.manage): the ONLY editable values are those that genuinely round-trip through `SettingsService` AND have a
  runtime consumer — settlement `currency` (read by landing/reporting/billing) and the invoice-issuer identity the PDF
  renderer reads (`billing.seller_name` / `billing.seller_vat_id`); writes go through `SettingsService::set()` (the
  existing path — not new storage) with a currency allow-list. Tenant profile (name/region/plan) and branches are shown
  READ-ONLY because they have no write backend; everything else a clinic would want is listed as a **GAP, not faked**
  (profile edit, branch CRUD, opening hours, locale wiring, tenant timezone, feature flags, plan selection, operational
  tuning keys). **Roles** (`UserRoleController`, `/admin/roles`, admin.manage): lists tenant users + current role and
  assigns ONE of the 6 seeded system templates — NOT a role builder, NO per-permission toggles. **Safety:** assignment
  is the sanctioned raw `RoleAssignment::create(['user_id','role_id','branch_id'=>null])` (no service exists — this IS
  the path), which the server Gate reads live, so a user's effective permissions are EXACTLY the template's (a test
  asserts an assigned doctor gains note.write but NOT billing.manage/admin.manage); it is AUTO-AUDITED via the
  `RoleAssignment::created` → `role.assigned` hook (replace = revoke old + assign new = `role.revoked`+`role.assigned`,
  chain stays valid) — the controller never calls audit code, and must never bypass Eloquent events or run in system
  mode. Assign REPLACES the user's role (dedupes — `role_user` has no unique constraint). **Self-lockout guard** (none
  existed in the RBAC layer): the controller refuses to demote the tenant's last org_admin (a presentation-layer count
  check; a test proves it blocks the last admin but allows demotion when another admin remains). Both pages tenant
  scoped + cross-tenant user/role → 404. One existing-test update (tracking, not weakening): `NavAndErrorPageTest`'s
  exact nav-permissions map gained `admin.manage` because the new `/settings` nav link is gated on it (shared via
  `HandleInertiaRequests::NAV_PERMISSIONS`) — same category as FIX.4's L-2 seeder-count update. (CLINIC.W8)
- **D-095 — Settings backends (profile, branch CRUD, opening hours, timezone) are real domain work, but branch
  deactivation + opening-hours changes must never orphan or silently break scheduling.** CLINIC.W8b built the
  write backends the W8 discovery found missing. **Profile:** new nullable `tenants` columns (contact_email/phone,
  address_*), editable via `SettingsController::updateProfile`; slug/region/status/plan stay READ-ONLY (slug is the
  public `/book/{slug}` key, region is immutable, status/plan are platform/billing). locale + timezone persist via
  SettingsService and are APPLIED per request by a new `ApplyTenantLocaleTimezone` middleware
  (`date_default_timezone_set` for server `now()`, `app()->setLocale()`; NEVER touches `config('app.timezone')`, so
  Eloquent keeps serialising UTC — stored data unchanged) + surfaced lazily on Inertia's `locale`/`timezone` (lazy
  closures because Inertia evaluates `share()` before the middleware runs). Full per-widget datetime→tz display is a
  documented follow-up. **Branch CRUD:** a new `branch_hours` table + `BranchHours` model (per-weekday, validated
  like ResourceAvailability), and an APP-LAYER `App\Http\Controllers\BranchController` + `App\Services\BranchService`
  (app layer because the deactivation guard spans Platform's Branch + Scheduling's appointments/resources, and
  `arch('Platform does not depend on Scheduling')` forbids doing it inside Platform). **SCHEDULING SAFETY — two
  guards, both tested:** (1) **Deactivation is soft (`active=false`, never a hard delete** — appointments/encounters/
  charges/visits `restrictOnDelete` a branch) and is **BLOCKED when the branch still has future active appointments**
  (blockingStatuses, starts_at ≥ now) so scheduled care is never stranded; the day-board/portal now filter
  `active=true` (public booking already did), so a deactivated branch disappears from every booking surface while its
  rows persist. (2) **Opening hours feed the slot engine:** `AvailableSlotFinder` bounds its scan to the branch's
  configured [open, close] for the weekday (a closed day offers nothing), and `BookingService::createBooking` — the
  authoritative funnel for book/bookOnline/series/waitlist — rejects a start outside hours (new
  `BookingUnavailableException::outsideBranchHours`). **Backward-compatible by design:** a branch with NO configured
  hours keeps the engine's default 07:00–19:00 window and imposes no booking constraint, so every existing
  scheduling test (none set hours) stays green. All writes admin.manage-gated, tenant-scoped (cross-tenant → 404),
  and audited via app-layer model hooks (branch.created/updated/activated/deactivated, branch.hours_changed,
  tenant.profile_updated) — Platform never imports Audit. GAPS still flagged: adding resources (rooms/chairs) to a
  branch has no backend, so a brand-new branch is created but not yet bookable until resources are seeded. (CLINIC.W8b)

- **D-096 — Bookable-resource CRUD closes the W8b gap; resource deactivation carries the same scheduling-safety
  guard as branch deactivation.** CLINIC.W8c built the resource (room/chair/vehicle) write path that W8b flagged
  missing, so a self-service branch can now be made bookable. Resource is a Scheduling model, so — mirroring the
  branch controller — the CRUD lives in the APP LAYER (`App\Http\Controllers\ResourceController` +
  `App\Services\ResourceService`) because the deactivation guard queries Scheduling's Appointment and
  `arch('Platform does not depend on Scheduling')` forbids the guard inside Platform. Resources are created UNDER a
  branch (`POST /admin/branches/{branch}/resources`), edited/(de)activated by id; all admin.manage-gated,
  tenant+branch scoped (cross-tenant → 404), audited via app-layer hooks
  (resource.created/updated/activated/deactivated) — Scheduling never imports Audit. **Only room/chair/vehicle are
  admin-creatable; practitioner resources stay staff-profile driven (People), excluded from this screen.**
  **SCHEDULING SAFETY:** deactivation is soft (`active=false`, never a hard delete — `appointment_resources`
  `restrictOnDelete`s a resource) and is **BLOCKED when the resource still has future active appointments** (via the
  appointment_resources pivot, blockingStatuses, starts_at ≥ now) so scheduled care is never orphaned — the exact
  branch guard. **No booking LOGIC changed:** the day-board (`DayBoardController`) and `AvailableSlotFinder::
  resourcesByType` ALREADY filtered `Resource ... active=true`, so a new active resource is picked up and a
  deactivated one drops out of every booking surface automatically — W8c only added the CRUD that flows through the
  existing engine (proven end-to-end: create branch+resource → day-board-selectable + slot-finder offers it;
  deactivate → gone from both). **Follow-up flagged:** a CRUD'd resource is immediately day-board-selectable but is
  only OFFERED AS SLOTS once its per-resource availability windows are set (the existing `ResourceAvailability`
  mechanism, unchanged); a resource-availability admin screen is the natural next step. (CLINIC.W8c)
- **D-097 — Governance dashboard + AI approval-queue are READ/ACT WINDOWS onto tested backends; they add no
  autonomy, no audit-mutation, and no fence bypass — the hardest safety line in the admin vertical.** CLINIC.W9
  built the two most safety-sensitive admin screens as app-layer controllers (`App\Http\Controllers\
  GovernanceDashboardController` + `AiApprovalQueueController`), app layer because they compose Audit + Platform +
  Billing + AiCore, which no single module may do. **PART A — Governance (STRICTLY READ-ONLY, `audit.view`):** it
  DISPLAYS posture assembled entirely from existing data — a live `AuditService::verifyChain()` replay (a pure read
  that writes nothing) plus the latest scheduled `IntegrityCheck` (D-069); the latest `ReconciliationRun` (the D-068
  launch-blocker monitor) plus the persisted `billing.reconciliation.alarm`; AI-usage outcome counts + integer-minor
  cost over the append-only `ai_interactions` ledger; the pending-`AgentAction` depth; kill-switch state (via
  `KillSwitch::enabled()`); and recent + security-relevant audit events. There is NO mutation path: every source is
  append-only at model + DB-trigger level and the controller only reads. The single POST ("verify now") RE-RUNS the
  existing verification and shows the result — it appends nothing (proven: audit-event count unchanged). **CRITICAL:
  `AuditEvent` has no `BelongsToTenant` scope (Audit may not depend on Platform), so the controller filters
  `tenant_id` EXPLICITLY — the isolation guarantee the whole surface rests on (tested).** **PART B — AI approval
  queue (READ + ACT-THROUGH-EXISTING-PATH, `ai.manage`):** it lists PENDING agent actions and approves/rejects them
  ONLY through `AiCore\Services\ApprovalQueue::approve/reject` — the same service the backend tests and the P.4 eval
  harness lock. The screen introduces NO new execution path, NO create/propose route (so a human cannot inject an
  un-fenced action — the fence refuses clinical asks at propose time, before any `agent_action` exists), and NEVER
  sets an autonomy level (the request body cannot raise it — tested). The queue only ever holds items the
  `AutonomyPolicy` already routed to human approval; clinical/financial tools are hard-capped at `approve` and the UI
  cannot lift that. **THE CAP THAT BINDS:** `ApprovalQueue::approve/reject` re-authorizes the reviewer against the
  TOOL's OWN permission on every call (`authorize()` before `execute()`), so a reviewer who reaches the queue
  (`ai.manage`) but lacks a tool's permission (e.g. `appointment.manage`) is DENIED by the service — the controller
  lets that `AuthorizationException` propagate as 403 and catches only `AiCoreException` (domain errors). Reject
  executes nothing; approve runs only `tool->execute()` with tenancy/audit/fence intact; every approve/reject is
  audited by the EXISTING app-layer glue (`agent_action.*` / `ai_interaction.*`) — the controller adds no audit of
  its own. Actions resolve by STRING id (FIX.1/D-090), so cross-tenant/missing ids fail closed as 404. Both surfaces
  are RE-SKIN-style presentation over frozen engines (P0D.GU): no route/controller/prop of an existing surface, no
  eval or audit/immutability test touched. NEW `Governance/Dashboard.vue` + `Governance/ApprovalQueue.vue` (Eucalyptus
  Glow), two nav entries (`audit.view` / `ai.manage`, added to `HandleInertiaRequests::NAV_PERMISSIONS`), `governance.*`
  + `aiQueue.*` i18n. 8 feature tests (read-only/no-mutation/tenant-scoped/gated; approve-through-existing-path +
  audited + autonomy-not-raisable; reject-does-nothing + reason-required + audited; cap-binds-via-UI + cross-tenant
  404) + the route smoke gains both GET routes. Closes two of the founder-scope admin gaps; the remaining unwired
  admin surfaces (KB admin, staff-telehealth join) stay a scope decision. (CLINIC.W9)
- **D-098 — KB admin + staff telehealth join surface existing backends with no new agent/telehealth logic; the
  admin vertical is complete.** CLINIC.W10 built the last two (lowest-risk) admin screens over frozen backends
  (P0D.GU). **PART A — KB admin (`/governance/kb`, `ai.manage`):** CRUD over the tenant's `KbArticle` rows (the
  Front-Desk agent's grounding source). App-layer `App\Http\Controllers\KbArticleController` because KB curation
  writes an AUDIT trail (a KB change changes what the agent can say) and AiCore may not depend on Audit. Writes go
  through the existing `KbArticle` model + `KbEmbeddingService::syncArticle` (the existing embedding path, kept warm
  on save); deactivate is a soft `is_active=false` toggle. **The agent's grounding + electric fence are UNCHANGED:
  `KbRetriever` already filters `where('is_active', true)`, so a deactivated article immediately stops being grounded
  on — proven by a test that drives the retriever before/after deactivation — and the P.4 front-desk evals are not
  touched.** Gated on `ai.manage` (curating what the governed AI grounds on is governed-AI management, consistent
  with the W9 governance area; delivery map: governance/KB); audited (`kb.article.created/updated/activated/
  deactivated`); tenant-scoped (BelongsToTenant + string ids → cross-tenant 404). **PART B — staff telehealth join
  (`/telehealth`, `encounter.manage`):** the CLINICIAN side of the SAME sessions the portal patient joins (W3).
  `Modules\Comms\Http\Controllers\StaffTelehealthController` (beside `PortalTelehealthController`, Comms already uses
  People/Patients/Scheduling) lists the clinician's OWN created/active sessions (filtered by their StaffProfile
  `practitioner_id`) and issues the EXISTING staff token via `TelehealthService::joinTokenForStaff`. **No new
  telehealth logic:** media never touches CareOS servers, recording stays disabled at the provider (grants pin
  roomRecord/roomAdmin/recorder=false — asserted through the staff path), the token is short-lived + never stored/
  logged, and the "not recorded" discipline is displayed. The service re-authorizes per session (encounter.manage /
  appointment.manage), asserts tenant, audits (`telehealth.token_issued`) and read-logs; the token is returned
  transiently only (mirroring the portal's in-memory fetch). Two nav entries added (`app.nav.knowledge` on
  `ai.manage`; `app.nav.telehealth` on `encounter.manage`, the latter added to `NAV_PERMISSIONS`); `kb.*` +
  `staffTelehealth.*` i18n. 4 feature tests (KB CRUD+gate+audit+tenant-scope + deactivated-not-grounded; staff join
  issues-existing-token+not-recorded+audited + gated+tenant-scoped+own-sessions-only) + route smoke gains both GET
  routes. Completeness/tracking edits only: `NavAndErrorPageTest`'s exact nav map gained `encounter.manage`. The P.4
  eval harness + audit/immutability suites are UNCHANGED and green. **With W10, the ADMIN VERTICAL is complete
  (W8 settings/roles · W8b settings backends · W8c resource CRUD · W9 governance/approval-queue · W10 KB/telehealth);
  the CLINIC + ADMIN verticals are both fully delivered.** (CLINIC.W10)
- **D-099 — The dental vertical begins with the tooth/odontogram data model as its foundation; it is
  RECORD-NOT-JUDGE and append-only, and dental inherits the whole existing platform.** DENTAL.G1 registers
  `Modules\Dental` (plain internal module, D-012; provider in bootstrap/providers + composer autoload; arch
  boundary: Dental may use Patients/Scheduling/Clinical/Billing + Audit SERVICES but never Audit models,
  AiCore, Nursing, or Comms — cross-module guards live in `app/`). **Tooth notation = FDI / ISO 3950
  two-digit** (`Support\ToothNotation`, the international standard), supporting BOTH permanent (11–48, 32)
  and primary (51–85, 20) because a family dentist charts children; dentition is DERIVED from the id, never
  stored, and a patient's tooth set is whatever teeth have records (missing = a charted state; mixed
  dentition = both) — no hardcoded 32-tooth assumption. **The odontogram data model is `tooth_records`
  (BelongsToTenant, APPEND-ONLY at model + DB-trigger level, SIGNAL 45000, portable):** one immutable row
  per tooth-or-surface charting moment, carrying `charted_condition` (a fact the clinician SELECTED from an
  allowed vocabulary per scope — whole-tooth statuses vs surface conditions), `surface` (null = whole-tooth),
  `note`, `reason` (a correction is a NEW row + reason — prior states never destroyed). **The current
  odontogram = latest row per (tooth, surface); history = every row.** **ELECTRIC FENCE (record-not-judge,
  same posture as vitals D-D3 / order results D-076):** there is DELIBERATELY no severity/score/risk/grade/
  abnormal/flag/priority/recommendation column anywhere — the system records what the dentist charts, it
  never detects caries, grades decay, assesses risk, or diagnoses (asserted by a schema + recursive-output
  fence test). `ToothChartService` is pure record + retrieve: `chart()` (Gate `dental.chart`, actor+patient
  same-tenant, audited `dental.tooth_charted`), `currentChart()`/`history()` (Gate `patient.view`,
  patient-scoped `read` audit). RBAC adds `dental.chart` to the catalog, granted to `org_admin` + `doctor`
  (the treating-clinician role — in a dental tenant this is the general dentist; a dentist/hygienist/
  assistant split is a later gate; reception/nurse refused, tested). No UI this gate (chart UI is G2). No
  existing behavior changed; the P.4 eval / reconciliation / immutability / audit suites stay green
  unchanged. New module memory `memory/modules/Dental.md`; plan `docs/DENTAL-DELIVERY-MAP.md`. (DENTAL.G1)
- **D-100 — The odontogram chart UI is PRESENTATIONAL over the G1 service and RENDER-NOT-JUDGE.** DENTAL.G2
  builds the interactive tooth chart (`Modules\Dental\Http\Controllers\OdontogramController` — a module
  controller, Dental may use Patients; `resources/js/pages/Dental/Odontogram.vue`). It surfaces the patient's
  CHARTED tooth conditions + history and dispatches the charting action; it computes nothing. **All logic
  stays in the G1 `ToothChartService`** (append-only charting, deterministic FDI/surface/condition
  validation, tenant scoping, audit, patient-scoped read-logging) — the controller only calls it; the tooth
  universe, surfaces, and condition vocabulary are passed as PROPS from the domain so NO
  tooth/surface/condition logic lives in the component (P0D.GU). Routes: GET `/dental/chart/{patient}` (show,
  `patient.view`) + POST `/dental/chart/{patient}` (store/charting, `dental.chart`), STRING-id `{patient}`
  (FIX.1/D-090; cross-tenant/missing → 404). **FENCE CARRIED INTO THE UI (render-not-judge):** the rendered
  payload carries charted FACTS only — `condition` (the value the dentist selected), never severity / score /
  grade / risk / priority / flag (asserted by a recursive payload fence test). The chart's colours are a
  FACTUAL charted-condition LEGEND (categorical — each discrete condition has a distinct hue, with a "Chart
  key" that states "colour marks the condition charted, not its severity"), NOT a severity heatmap / risk
  colour / auto-flag; nothing is scored, graded, or flagged, and no number is rendered — the visual analogue
  of raw vitals with no bands (D-D3). Charting goes ONLY through the append-only service: a correction via the
  UI creates a NEW record (prior state preserved — proven end-to-end via the store action + a fresh render).
  The odontogram is patient-scoped read-logged (inside the service) and RBAC-gated (view = patient.view,
  record = dental.chart — reception can view but not record; billing, lacking patient.view, cannot view).
  Reached by URL for now (a patient/chart cross-link is a later, non-breaking addition — no existing page or
  test was touched). 4 feature tests + the route smoke gains the dental chart route (doctor 200 / billing 403).
  (DENTAL.G2)
- **D-101 — A dental procedure IS a tariff item; the dental catalog is authored over the EXISTING billing
  engine with NO new pricing logic.** DENTAL.G3 wires the dentist's procedure list + fees to the tested
  billing engine. **The mapping:** the dental fee schedule is a dedicated dental `TariffCatalog` (key
  'dental') of `TariffItem`s — each tariff item holds the code / name / FEE (`unit_price_minor`) / VAT — and
  a thin `dental_procedures` overlay (BelongsToTenant) adds ONLY the dental-specific `tooth_scoped` flag,
  keyed 1:1 to the tariff item. So PRICING lives entirely in the billing store; NO fee column is duplicated
  in dental. **Charging** a dental procedure calls the EXISTING `ChargeCaptureService::captureManual(...,
  $procedure->tariffItem->code, ...)`, which resolves the tariff via `TariffResolver` and SNAPSHOTS the fee
  onto the `Charge` (D-F1/D-F2) — so the charge flows into the existing invoice → reconciliation → dunning →
  PDF pipeline UNCHANGED, and a dental charge reconciles-to-the-unit exactly like any other (tested: capture
  → validate → issue → `ReconciliationEngine::check` passes with delta 0). **A later fee edit never changes
  a past charge** (the snapshot discipline — tested). **NO new billing logic / no money math in dental
  code:** `DentalCatalogService` only AUTHORS the catalog (writes the tariff item's name + the fee the
  dentist entered — data entry, not computation) and `DentalChargeService` only calls `captureManual` — an
  adversarial grep confirms zero pricing/charge/VAT/line-total math in `Modules\Dental` (every `_minor`/
  `vat_rate_bp` reference is a pass-through). **NO licensed code set bundled:** the catalog is
  TENANT-AUTHORED (the dentist enters their own codes/fees); `DentalCatalogService::seedStarter` lays down a
  small GENERIC editable template (D-EXAM, D-PROPHY, D-XRAY, D-RESTOR, D-CROWN, D-EXTRACT, D-RCT — plain
  names, the tenant's own codes, placeholder fees), NOT ADA CDT or Swiss SSO point values (tested: codes are
  the generic set, not the CDT Dnnnn format; tenant-isolated). **The fee-schedule editor**
  (`FeeScheduleController` + `Dental/FeeSchedule.vue`, `/dental/fee-schedule`) is PRESENTATIONAL over
  `DentalCatalogService` (add/edit/deactivate + seed), gated on **`billing.manage`** (the "manage billing
  tariffs and billable items" permission — the fee schedule IS a tariff catalog; the same permission that
  gates charge capture, so the whole dental-billing surface is consistent; the dentist-owner typically holds
  org_admin). Major↔minor / %↔bp conversions are display-only in the Vue (like the vitals unit helper).
  **The light tooth link** (`dental_procedure_charges`): when a tooth-scoped procedure is charged, a thin row
  ties the resulting `charge` to the odontogram tooth/surface (no money stored) — a filling on tooth 16 is
  chargeable and tied to the tooth; the full perform-a-procedure workflow is DENTAL.G4. Fence: a procedure
  catalog is administrative/financial, not clinical interpretation — the payload carries no severity/
  recommendation (tested). String-id routes (FIX.1). 7 feature tests + the route smoke gains the fee-schedule
  route (billing 200 / reception 403). No existing behavior changed; the reconciliation/immutability/fence/
  eval suites stay green. (DENTAL.G3)
- **D-102 — Performing a procedure is ONE ATOMIC action: clinical record + charge + tooth-state, together
  or not at all, reusing G1/G3 with no new logic.** DENTAL.G4 wires the vertical together.
  `PerformProcedureService::perform` writes THREE things inside ONE `DB::transaction`: (1) captures the
  charge via the EXISTING `DentalChargeService::capture` → `ChargeCaptureService` (G3 — tariff snapshot →
  the invoice/reconciliation pipeline; NO new billing math, adversarial-grep clean); (2) records a
  `performed_procedures` row (the clinical fact, APPEND-ONLY at model + DB-trigger level, tied to the
  charge via `charge_id` NOT NULL); (3) charts the resulting tooth-state change via the EXISTING
  `ToothChartService::chart` (G1 — append-only). **CONSISTENCY GUARANTEE (tested):** a performed procedure
  never leaves a charge without its clinical record or vice-versa — a failure in ANY step rolls back ALL
  three (proven: an invalid resulting tooth-state makes step 3 throw AFTER the charge + clinical record
  were written → the whole transaction rolls back → zero charges, zero performed rows, zero tooth records).
  Nested audit writes (charge.captured, dental.tooth_charted, dental.procedure.performed) become savepoints
  and roll back with the outer transaction. **TOOTH-STATE MAPPING = factual consequence, not judgment:** the
  DENTIST states the resulting condition per perform (e.g. extraction → `missing`, filling → `restoration`
  on the surface); the service charts exactly that value (validated against G1's vocabulary — a whole-tooth
  condition charts whole-tooth, a surface condition charts on the performed surface), it INFERS nothing and
  GRADES nothing. `performed_procedures` records fact only — no severity/score/grade/recommendation (fence,
  tested). **RBAC (the permission model):** perform authorizes `dental.chart` (clinical) up front AND the
  charge enforces `billing.manage` inside — so performing-and-charging needs BOTH; a doctor (dental.chart,
  no billing.manage) is denied at the charge step and everything rolls back; the dentist-owner holds both
  via org_admin (tested). A charge from a performed procedure reconciles-to-the-unit like any other
  (tested). Append-only: a correction is a NEW performed record; the prior is preserved (tested).
  **UI:** the odontogram (G2) is extended additively — a "Perform a procedure" side-panel form (procedure +
  branch + optional resulting tooth-state + note, shown only when `can_perform` = dental.chart && billing.
  manage) + a per-tooth performed-procedure history; `OdontogramController::perform` (POST
  `/dental/chart/{patient}/perform`, string-id FIX.1). PRESENTATIONAL (P0D.GU) — the service owns the logic.
  5 feature tests + the route smoke gains the perform route (reception 403 at the clinical gate). No G3
  code was touched (the mapping is a perform-time input, so the catalog needed no change); no existing
  behavior changed; the reconciliation/immutability/fence/eval + G1–G3 suites stay green. (DENTAL.G4)
- **D-103 — The dental treatment plan is DENTIST-AUTHORED, its estimate reuses G3 pricing (snapshot at
  proposal), and it ESTIMATES without billing (G4 charges).** DENTAL.G5 completes the core dental spine.
  Domain: `treatment_plans` (BelongsToTenant, LogsReads; lifecycle draft→proposed→accepted/declined→
  in_progress→completed) group `treatment_plan_phases` which hold `treatment_plan_items` (a planned
  procedure = a dental_procedure + tooth/surface + estimated_fee_minor). **ESTIMATE:** each item's estimate
  is the G3 tariff fee (`DentalProcedure`→`TariffItem.unit_price_minor`) READ through the existing store,
  SNAPSHOTTED (into `estimated_fee_minor`) when the plan is PROPOSED — so a later fee-schedule edit never
  changes an accepted plan's agreed estimate (tested; the same snapshot discipline as charges). Phase/plan
  totals are `->sum(itemEstimate)` — the ONLY arithmetic; there is NO VAT/discount math (an adversarial grep
  finds no pricing/charge math in `Modules\Dental`; VAT is applied by the billing engine only when a
  procedure is actually charged). **NO DOUBLE-CHARGE (documented + tested): the plan ESTIMATES; proposing/
  accepting posts NO charge** — a charge is created only when the procedure is PERFORMED (G4). **Link to
  G4:** `performed_procedures` gains a nullable `treatment_plan_item_id`; `PerformProcedureService::perform`
  gains an optional `?TreatmentPlanItem $planItem` (default null — G4's atomic workflow unchanged) that, when
  set, ties the performed procedure to the plan item so the plan tracks completion (an item is "done" when a
  performed procedure references it — derived, no stored flag). **LIFECYCLE legal-only** (a state machine
  mirroring `ServiceAgreementService`; illegal transitions throw; completed/declined terminal), audited,
  tenant + patient scoped, read-logged. **ELECTRIC FENCE: the DENTIST authors the plan** — no
  auto-suggestion of procedures, no severity-driven prioritisation, no AI-recommended treatment; the service
  only records what the dentist adds and sums the fees (the prototype's "the agent drafted it" is built
  WITHOUT the AI; the payload carries no auto-suggested/severity/AI field — tested). **RBAC:** managing =
  `dental.chart` (clinical authorship); reading = `patient.view`; performing a planned item = dental.chart +
  billing.manage (via the G4 service). UI: `TreatmentPlanController` + `Dental/TreatmentPlans.vue`
  (`/dental/plans/{patient}`) presentational — build phases/items, per-phase + total estimates, lifecycle,
  and perform-a-planned-item; the patient portal shows their own proposed-onward plans READ-ONLY
  (`PortalTreatmentPlanController` + `Portal/TreatmentPlan.vue`, `/portal/treatment-plan` — no actions, no
  PSP payment). String-id (FIX.1). 5 feature tests + the route smoke gains the staff plan route (doctor 200
  / billing 403) + the portal plan route. No existing behavior changed; reconciliation/immutability/fence/
  eval + G1–G4 suites green. **With G5 the CORE DENTAL SPINE (G1→G5) is complete:** a general dentist can
  chart the mouth (G1/G2), record + bill procedures (G3/G4), and build + present + track a phased,
  fee-scheduled treatment plan (G5). Remaining: G6 perio · G7 diagnosis record · G8 imaging (+ later:
  sterilization/inventory, ortho/scan-compare, live imaging capture, licensed code sets). (DENTAL.G5)
- **D-104 — Perio charting records RAW per-site measurements only; the dentist interprets, the
  system never stages/grades/flags (record-not-judge, the vitals discipline applied to perio).**
  DENTAL.G6. Domain: `perio_exams` (BelongsToTenant, LogsReads, **APPEND-ONLY** at model +
  DB-trigger SIGNAL-45000 — a re-exam is a NEW exam, corrections are new records; historical exams
  preserved) is a point-in-time full/partial probing (patient, examined_by, exam_date, note); it
  groups `perio_measurements` (BelongsToTenant, **APPEND-ONLY** model + triggers) — one row per
  tooth × SITE. **Six sites per tooth** (`PerioMeasurement::SITES` = mesio_buccal, buccal,
  disto_buccal, mesio_lingual, lingual, disto_lingual — the standard 6-point probing; distinct from
  the odontogram's 5 anatomical SURFACES). Per site the RAW probed values: `pocket_depth_mm`,
  `recession_mm` (signed — negative = gingival overgrowth), `bleeding_on_probing` (bool), plus
  optional per-tooth `mobility` (Miller 0–3) and `furcation` (Glickman/Hamp 0–4). Tooth = FDI
  (reuses G1 `ToothNotation`). **CRITICAL ELECTRIC FENCE (perio's core risk): the schema, service,
  and UI store/render RAW NUMBERS ONLY** — there is DELIBERATELY NO periodontal stage (I–IV), NO
  grade (A–C), NO severity, NO risk score, NO "disease detected", NO auto-flag of a deepening site,
  NO computed attachment-loss "finding". Attachment level (depth+recession) is left for the clinician
  to read — not stored or labelled. `PerioMeasurement::assertValid()` is pure DATA-ENTRY validation
  (valid FDI id, valid site, physically-plausible number — e.g. depth 0–15mm) exactly like the
  odontogram rejecting an unknown surface; bounds reject impossible input, they never grade. A
  per-site **trend over time is RAW CONTEXT** (raw numbers in sequence via `siteHistory`, oldest
  first) — NO band/flag/arrow/"worsening" label (same rule as the unified vitals trends, P.13). The
  fence is proven by a recursive payload assertion (`ppAssertNoJudgment` forbids stage/staging/grade/
  severity/risk/flag/classification/worsening/… keys) over both the page props and the siteHistory
  output. **Service** `PerioChartService`: `recordExam` (dental.chart, tenant+patient scoped,
  DB::transaction of exam + its site rows — an invalid value throws and the whole exam rolls back,
  audited `dental.perio_charted`); `examsFor` + `siteHistory` (patient.view, patient-scoped `read`
  audit). **RBAC:** record = `dental.chart`; read = `patient.view` (reception views but can't record;
  billing lacking patient.view can't view). **UI:** `PerioChartController` + `Dental/PerioChart.vue`
  (`/dental/perio/{patient}`, string-id FIX.1) — the classic perio grid (teeth × 6 sites, enter
  depth/recession/BOP + per-tooth mobility/furcation; prior exams as raw grids). PRESENTATIONAL
  (P0D.GU) — NO severity colouring, NO flagged sites, NO stage/grade badge, NO auto-watch; a dot
  marks BOP (data entry), not severity. Route smoke gains `/dental/perio/{patient}` (doctor 200 /
  billing 403). Money/clinical/existing behavior unchanged; no existing test modified;
  reconciliation/fence/immutability/eval + G1–G5 suites green. (DENTAL.G6)
- **D-105 — A dental diagnosis is DENTIST-AUTHORED and merely RECORDED; there is NO AI, NO suggested/
  proposed diagnosis, NO auto-ranked differential, and NOTHING auto-populates a diagnosis. The
  SHARPEST fence in the vertical.** DENTAL.G7. Domain: `diagnoses` (BelongsToTenant, LogsReads,
  **APPEND-ONLY** model + DB-trigger SIGNAL-45000 — a change [provisional→confirmed, or a correction]
  is a NEW record + `reason`; history preserved) stores what the DENTIST decided: `label` (the
  diagnosis text they wrote OR picked), optional `tooth`/`surface` (FDI, reuses G1), `findings` (their
  supporting notes), and `status` ∈ {provisional, confirmed, ruled_out} that the DENTIST sets;
  `diagnosis_term_id` is PROVENANCE ONLY (which pick-list term was chosen, null = free text). A
  separate `diagnosis_terms` (BelongsToTenant, plain catalog — NOT append-only) is the tenant's OWN
  pick-list: a flat `{label, is_active}` list, TENANT-AUTHORED like the procedure catalog — **NO
  licensed diagnostic code set (ICD/SNODENT) bundled**. **ELECTRIC FENCE (do not compromise): the
  system NEVER proposes, ranks, suggests, auto-populates, or computes a likelihood for a diagnosis —
  there is NO AI in this path at all this gate** (the prototype's "agent's proposed diagnosis /
  auto-ranked differential" is built WITHOUT it — purely dentist-authored; a governed-AI diagnosis
  draft was DELIBERATELY not added: a diagnosis is the one place we want no AI in the loop for now).
  `status` is the dentist's determination — recorded, never decided/suggested by the system. The
  schema/service/UI carry NO suggested/proposed/differential/likelihood/confidence/ranked/ai/
  recommended field; `Diagnosis::assertValid` is pure data-entry validation (non-empty label, valid
  FDI/surface, known status) — it never infers or ranks. The pick-list is a plain alphabetical list,
  never sorted/filtered by a computed judgment. **Proven by the STRICTEST fence test yet**: a recursive
  `dxAssertNoSuggestion` over the page props AND terms, PLUS an explicit no-auto-populate proof —
  charting caries (G2) + probing 9mm perio pockets (G6) yields ZERO diagnoses (nothing derived one from
  the clinical data); only what the dentist explicitly recorded exists. **Service** `DiagnosisService`:
  `record` (gate `dental.chart`, tenant+patient fail-closed, term-id must be this tenant's, audited
  `dental.diagnosis_recorded`); `diagnosesFor` (gate `patient.view`, patient-scoped `read` audit,
  history = every row newest-first); `terms`/`addTerm` (the tenant's pick-list; addTerm audited
  `dental.diagnosis_term.created`). **RBAC:** record = `dental.chart`; read = `patient.view` (reception
  views but can't record; billing lacking patient.view can't view). **UI:** `DiagnosisController` +
  `Dental/Diagnoses.vue` (`/dental/diagnoses/{patient}`, string-id FIX.1) — the dentist writes/picks a
  diagnosis, sets the status THEY determine, ties an optional tooth, references findings, and manages
  their own term list; diagnosis history newest-first. PRESENTATIONAL (P0D.GU): NO "suggested
  diagnosis" UI, NO differential ranking, NO AI panel, NO auto-fill from charting. Route smoke gains
  `/dental/diagnoses/{patient}` (doctor 200 / billing 403). Money/clinical/existing behavior unchanged;
  no existing test modified; reconciliation/fence/immutability/eval + G1–G6 suites green. (DENTAL.G7)
- **D-106 — Dental imaging is UPLOAD + a basic 2D VIEWER + a DENTIST-authored reading, REUSING the
  existing clinical document storage; the system NEVER analyses an image (no AI/CV), and live capture/
  DICOM/3D overlay are PARTNER-GATED.** DENTAL.G8 — completes the general-dentist feature set.
  **Storage reuse (no new file storage):** a dental image is stored through the EXISTING Clinical
  `DocumentService::upload` (private `local` disk, tenant-prefixed path `tenants/{tenant}/clinical-
  documents/{patient}/{ulid}.{ext}`, MIME/size validated, category `image`, NO public URL — Dental MAY
  use Clinical per the arch test). NEW `dental_images` (BelongsToTenant, LogsReads, **APPEND-ONLY/
  immutable** model + DB-trigger SIGNAL-45000 — a captured image is never edited) adds the dental
  metadata over it: `document_id` (the stored asset), `image_type` ∈ {bitewing, periapical, panoramic,
  photo, scan} (a plain tenant-meaningful label), optional `tooth` (FDI, reuses G1), `region`,
  `captured_at`, `uploaded_by`. The dentist's READING is NEW `dental_image_readings` (BelongsToTenant,
  **APPEND-ONLY** model + triggers) — free text the DENTIST wrote (`reading` + `reason`); a change is a
  new reading, history preserved. **ELECTRIC FENCE (imaging's risk): the viewer DISPLAYS the image and
  lets the DENTIST write a reading — the system does NOT detect caries, flag pathology, overlay AI
  findings, auto-annotate, or compute anything about the pixels. There is NO AI/CV analysis anywhere**
  — no method looks at the image bytes except to stream them. The schema/service/UI carry no
  ai/finding/detected/overlay/annotation/confidence field; `assertValid` is pure data-entry validation
  (known type, valid FDI). Proven by a recursive `diAssertNoAnalysis` over the payload + a no-auto-read
  proof (an upload creates ZERO readings — nothing is generated). **PARTNER-GATED / NON-GOAL (flagged,
  NOT built — see DEFERRED):** live capture from an X-ray sensor / intraoral scanner (needs vendor
  SDK/driver), DICOM/PACS, 3D scan overlay/comparison (needs 3D compute + scanner pipeline), and AI
  radiology / caries detection (electric fence + regulated device — never build the homemade version).
  Day-one = upload + 2D view (client-side zoom/pan on raw pixels) + dentist reading. **Service**
  `DentalImagingService`: `upload` (gate `dental.chart`; the file store additionally enforces the
  document-write permission `note.write` — the dentist/org_admin holds both), `recordReading` (gate
  `dental.chart`, append-only, audited `dental.image_read`), `imagesFor`/`fileContents` (gate
  `patient.view`, patient-scoped `read` audit). The private bytes stream ONLY through an authed route
  (`/dental/image-file/{image}`, nosniff, `private, no-store` — no public URL). **RBAC:** upload/annotate
  = dental.chart; view/file = patient.view. **UI:** `DentalImageController` + `Dental/Imaging.vue`
  (`/dental/images/{patient}`, string-id FIX.1) — upload, a gallery, the 2D viewer with metadata + the
  dentist's readings; NO AI panel / auto-findings / overlay. Route smoke gains `/dental/images/{patient}`
  (doctor 200 / billing 403). Money/clinical/existing behavior unchanged; no existing test modified;
  reconciliation/fence/immutability/eval + G1–G7 suites green. **With G8 the GENERAL-DENTIST feature set
  (G1–G8) is COMPLETE.** (DENTAL.G8)

- **D-107 — Dental demo-readiness is presentation + seed, not new domain: make the (already-correct)
  vertical REACHABLE and DEMONSTRABLE.** The deep-audit report (docs/DEEP-AUDIT-REPORT.md) found the dental
  functionality done and safety-verified, but the odontogram was UNREACHABLE from the product (no nav, no
  patient cross-link) and every surface started EMPTY (no dental seeder). DENTAL.G9 closes that with
  presentational/routing + seed only (P0D.GU): no fence/billing/clinical/tenancy/RBAC logic changed, no
  existing behavior test modified. **Navigability:** a role-gated top-nav "Dental" entry (`dental.chart`
  added to `NAV_PERMISSIONS` so the client can gate it — a non-dental role never sees it) → a NEW `/dental`
  patient-picker landing (`DentalLandingController`, `dental.chart`-gated, presentational — there is no
  patient-independent clinical dental route, so the landing is a picker into each patient's odontogram); a
  shared `DentalSectionNav` sub-nav on all five patient dental pages (the whole vertical navigable by
  clicking); a patient→dental cross-link on Patient 360 + the clinical chart, gated client-side on the
  shared `dental.chart` permission (no dead link for non-dental staff). A portal "Treatment plan" nav link
  surfaces the EXISTING read-only `/portal/treatment-plan` (own-data, no PSP; always shown, page owns its
  empty state). **Demo seeder:** `DemoDentalSeeder` (a companion to DemoClinicSeeder/DemoSpitexSeeder) seeds
  a realistic general-dental practice through the REAL services — idempotent by slug, D-066 discipline
  (never rewinds `now()`). Dental BILLING reconciles-to-the-unit in the previous month by CAPTURING charges
  through the existing engine (`DentalChargeService::capture`) and `forceFill`-ing `service_date` into the
  closed month — capture() not perform(), because perform() also writes an APPEND-ONLY tooth record that
  cannot be back-dated; the mutable Charge can. Draft charges (a live performed procedure) stay unbilled and
  are invisible to reconciliation (I4 counts only INVOICED charges) — the same discipline as the clinic
  demo's draft dunning fee. **Audit-cosmetic disambiguation:** the audit flagged a "Governance" eyebrow on
  the admin-config pages (Settings/Roles/Branches) as a MISLABEL — the brief read it as "missing"; verified
  from the repo (all six admin/governance pages already carried it) and flagged the drift (AGENTS.md rule).
  Fixed by disambiguating the three admin-config pages to "Administration", leaving the true
  governance/oversight pages as "Governance". Also: the CSV import dry-run now saves-then-validates (does
  what the button says), and a portal credit note reads "Credit" and is excluded from the "open balance"
  aggregate (display-only; the ledger math is untouched). NEW `DentalLandingTest` (4) + `DemoDentalSeederTest`
  (3, incl. reconcile δ=0 + chain-verify + idempotent); the FIX.5 route smoke gains `/dental` (dentist 200 /
  reception 403). VERIFIED: npm build green; PHPStan L5 `[OK]`; Pint passed; composer check green. With G9
  the dental vertical is REACHABLE + DEMO-READY. (DENTAL.G9) See [[Dental]], [[D-106]], [[D-090]].

- **D-108 — Visual fidelity is a ROOT-token/shared-component concern; the "everything feels off" was one
  root cause: the app never delivered its own webfont.** The Eucalyptus Glow design tokens name
  `--font-sans: 'Inter'`, but nothing loaded Inter — no `@font-face`, no `<link>`, no font package, zero
  woff2 in the repo. So the app rendered Inter only where it was system-installed and fell back to
  `ui-sans-serif, system-ui` (Segoe UI / San Francisco / Roboto) on every other machine, shifting type
  metrics and vertical rhythm on every page vs the prototype (which loads Inter via Google Fonts). UI.F1
  fixes this at the source by SELF-HOSTING Inter via `@fontsource/inter` (weights 400/500/600/700, imported
  in `resources/js/app.ts`; Vite bundles the subset woff2 with `font-display: swap`) — CSP-safe, no external
  CDN, guaranteed on every machine and offline. Chose self-hosting over a Google-Fonts `<link>` (the
  prototype's approach) because a healthcare SaaS may deploy behind strict CSP / restricted egress; the
  fonts ship in the build. Two smaller shared drifts were aligned to the prototype's actual values in the
  same pass: `.glass-card` shadow `0 14px 40px` → `0 16px 44px` and border white-hairline opacity `0.6` →
  `0.8`; `.euca-wash` top glow `rgba(198,218,191,0.5)` → `0.55`. The euca colour ramp, ink, surfaces, radii
  (2xl = 20px), and card blur (24px) were verified to ALREADY match the prototype — colours were never
  drifted. Because all three shells (app/auth/portal) use the shared `.euca-wash`/`.glass-card` classes and
  one JS entry, these root fixes correct every page at once. PURELY VISUAL (P0D.GU): no data/props/logic/
  route/fence/RBAC/billing change; no omitted behaviour reintroduced; no `.vue` template/prop change, so
  every assertInertia/behaviour test passes UNCHANGED. Per-page residuals (heading sizes, the native date
  input, the prototype's nav tenant-chip) are page-specific and go to UI.F2. VERIFIED: npm build green (28
  woff2 emitted + the app fetches its own Inter); composer check green (Pest 707/5741 unchanged); smoke
  green. (UI.F1) See [[D-107]], [[D-083]].

- **D-109 — Per-page visual fidelity: align the heading TYPE SCALE and style the native date input at the
  shared/token level, and recognise the fence-omitted clinical scores as CORRECT behaviour, not drift.**
  UI.F2 completes the visual match F1 (D-108) began. The residuals F1 flagged were per-page, but the fix is
  still shared: (a) the heading scale — the app's page titles were `text-2xl` (24px) and landing hero
  `text-5xl` (44px) vs the prototype's 22px / 40px (section headings `text-lg`=18px already matched), so the
  TOKENS were retuned (`--text-2xl` → 22px, `--text-5xl` → 40px) rather than editing 77 headings by hand;
  one genuine per-page outlier (the portal Home greeting) was reduced from 36 → 30px in place. (b) The
  native date input rendered as raw browser chrome; a shared `@layer base` rule styles `input[type='date']`
  into the design system (light color-scheme, ink text, a euca-toned calendar button) while keeping it a
  real date input — value and behaviour unchanged — so every date field (registration, filters, reporting)
  is fixed at once. The empty `mm/dd/yyyy` state is intrinsic to a native date input; replicating the
  prototype's "Date of birth" placeholder would require changing the input type, which would alter
  behaviour, so it stays. (c) The screen-by-screen re-compare confirmed the shared fixes propagate to every
  area (auth/landing/patients/clinical/dental/billing/portal/scheduling all match). The differences that
  REMAIN are correct behavioural content, deliberately left: RBAC-gated nav density, the multi-tenant
  nav chip, real data/empty states — and, sharpest, the prototype odontogram's **"DMFT" caries-index score
  and "finding" count**, which are exactly the computed clinical JUDGMENT the electric fence forbids; the
  live app correctly omits them (record-not-judge) and they stay omitted (as does the portal's absent pay
  button — PSP deferred). PURELY VISUAL (P0D.GU): no data/props/logic/route/fence/RBAC/billing change; the
  only `.vue` edit is one heading class; every behaviour test passes UNCHANGED (Pest 707/5741). VERIFIED:
  npm build green; composer check green; smoke green. (UI.F2) See [[D-108]], [[D-083]].

- **D-110 — POLISH.1: category-G loose-end cleanup (nav wiring + series-end surface + odontogram
  tokens + 2 coverage tests + doc refresh).** From `docs/MASTER-STATUS-REPORT.md` §PART 2/4. Presentation,
  nav, test, and docs ONLY — NO domain/fence/billing/clinical/RBAC logic changed. (a) **Navigability:** the
  6 built-but-unreachable pages are now reachable — Import + OrdersReview as top-nav items, and
  OrderableItems/Snippets/Competencies/Kiosks as inbound links from their logical parent pages — each
  gated by the SAME permission the server Gate already enforces (the `AppLayout` + `NAV_PERMISSIONS`
  pattern, which gained `order.manage`/`competency.manage`/`data.import`; the server Gate stays
  authoritative, nav is a UX hint). The `NavAndErrorPageTest` permissions map was synced (a tracking
  update, the W10 precedent): reception=false, org_admin=true for the 3 new keys (verified against
  `RbacProvisioner`). (b) **Series-end SURFACED, not removed** (the report's A2 choice): the
  `scheduling.series.end` route was UI-unreachable; rather than delete a real capability, the day-board
  now lists a branch's active recurring series with an End action (`window.confirm`) through the EXISTING
  route — chosen because `SchedulingUiTest` uses lenient prop assertions and `AppointmentSeries` has a
  clean status/branch query, so the added `activeSeries` prop is safe/additive. (c) **The one pattern-drift
  item:** the Odontogram condition palette (the app's only hardcoded hex) is now `@theme` tokens
  (`--color-dental-*`) referenced by `var()` — identical colours, the "charted-condition not severity"
  meaning + disclaimer kept; no visual/fence change. (d) **Two coverage tests** (money + fence): a
  rendered-invoice-PDF CONTENT assertion (printed totals/VAT/per-line + a credit note's negative Total
  equal the stored minor values — guards the customer document, which DB-only reconciliation does not)
  and a `CircuitBreaker` open→half-open→closed state test. (e) **Stale docs**
  (SCREENS/FEATURE-INVENTORY/both delivery maps/AGENTS module map) re-bannered to point at
  `docs/MASTER-STATUS-REPORT.md`. No must-fix safety item existed; this is demo-readiness polish.
  VERIFIED: npm build green; composer check green (Pint · PHPStan L5 · **Pest 707 passed / 2 skipped
  [Redis + reminder infra, green in CI on Redis 7] / 5747 assertions**, 0 failed); smoke green. (POLISH.1)
  See [[D-093]] (route-smoke / C-1 nav-gating), [[D-107]] (dental-navigability precedent).

- **D-111 — POLISH.2: group the admin/governance nav under one "Admin" dropdown (nav-density cosmetic).**
  Presentational nav reorganization ONLY (`resources/js/layouts/AppLayout.vue` + one i18n key
  `app.nav.admin`) — NO route/permission/controller/page/domain-logic/fence/billing/RBAC change. Fixes the
  standing L-A/L-E finding (org_admin's 15 flat top-nav items + "Knowledge base" wrapping to two lines at
  1440px, worsened by POLISH.1's +2), flagged by all three audits. **Split:** day-to-day items stay
  top-level (Dashboard/Patients/Orders/Scheduling/Nursing/Inbox/Telehealth/Dental/Billing/Reporting = 10);
  the admin/oversight/config cluster (Governance/Approvals/Knowledge base/Import/Settings = 5) collapses
  under the "Admin" menu. **Gating is identical:** `NAV_PERMISSIONS` is UNCHANGED, each item keeps its exact
  permission, the menu renders only when the user has ≥1 item, and the server Gate stays authoritative — so
  a role with no admin permissions (reception) sees NO menu and a menu route is still 403 by URL for it
  (200 for an admin). Browser-verified live: org_admin single-row nav + Admin menu with its 5 items;
  reception no menu + `/settings` 403; org_admin `/settings` 200; nav height 40px = single row (no wrap).
  **Accessible menu:** aria-haspopup/aria-expanded, role=menu/menuitem, arrow-key focus, Escape-to-close +
  refocus, outside-click close, active-trigger highlight. `NavAndErrorPageTest` (asserts the shared
  permissions map, not markup) passes UNCHANGED; no test modified. VERIFIED: npm build green; composer check
  green (Pest 707 / 2 skipped / 5747 — unchanged); test:smoke green. (POLISH.2) See [[D-110]] (POLISH.1 nav
  additions), [[D-093]] (nav-gating pattern).

- **D-112 — POLISH.3: guided first-run + warm empty states + dashboard-hero polish (presentational).**
  PURELY presentational (P0D.GU) — NO domain logic, no new metric, no data write, no new query, no
  fence/billing/clinical/RBAC change; tests are ADDITIONS only (no existing behaviour test modified). Makes a
  brand-new tenant's first impression feel designed and self-guiding instead of bare. **(A) First-run
  checklist** (`resources/js/Components/FirstRunPanel.vue` on `App/Landing`): shown ONLY for an empty/new
  tenant, decided purely from the EXISTING landing props — the new pure module `resources/js/lib/firstRun.ts`
  `isNewTenant()` = operational present (reporting.view) with `appointments`+`active_patients` both 0 AND
  `outstanding_minor` 0, which deliberately distinguishes an *empty tenant* from a *quiet day that still has
  an outstanding balance*. No new backend query — it reuses FIX.2's landing figures. Dismissible per-tenant
  (`localStorage` `careos.firstRun.dismissed.<tenantId>`). Its 5 step links (practice→/settings,
  team→/admin/roles, resources→/admin/branches, patients→/imports, appointment→/scheduling/day-board) are
  each **permission-gated** by `visibleSetupSteps(permissions)` so a role only sees steps it can reach — the
  same UX-hint pattern as the nav; the server Gate stays authoritative. Hidden on a populated tenant and when
  the actor has 0 steps. **(B) Shared `EmptyState.vue`** (icon slot + one-liner + OPTIONAL next-action Link —
  the Link renders only when both href+label are passed, so a screen with no self-serve action shows honest
  message-only copy) applied to the bare screens a new tenant hits: the **day-board no-availability gap** (a
  resource-less board looks broken → "set up resources" link gated on `admin.manage`, else message-only),
  **patients index** (Import link gated on `data.import`), **billing invoices**, **comms inbox**. Two screens
  were CONSCIOUSLY KEPT (documented, not silently skipped): **Reporting/Dashboard** shows genuine zeros for an
  empty tenant — the FIX.2 principle — and wrapping it in an EmptyState would suppress honest facts (fence);
  **Dental/Index** already has a warm centered empty state with a `clearSearch` reset button (good existing
  copy, and the reset is a button not a navigable Link). **(C) Shell polish:** a branded **favicon** (inline
  deep-eucalyptus leaf SVG data-URI in `app.blade.php`, no external asset), a branded pre-mount **splash**
  (`#app-splash`, design tokens only, removed in `app.ts` immediately after `.mount()` → no blank flash), tab
  title + shell BrandMark confirmed; the landing hero was already facts-only/on-brand so NO new metric or
  trend-grade was invented (fence: facts only). Tokens only (no hardcoded hex); all 17 new i18n keys present
  (en.json is the single locale). **Tests (additions only):** NEW Vitest `firstRun.test.ts` (isNewTenant
  empty→true / appointments>0→false / outstanding>0 quiet-day→false / null-operational→false;
  visibleSetupSteps org_admin→all, single-perm→one, none→[]) and a NEW `AppLandingTest` case (an empty
  tenant's landing = all-zero operational + zero outstanding = the panel-show signal; a billed tenant ≠ new =
  panel-hidden). VERIFIED: npm run build green; npm run test:unit green (**21 passed**); composer check FULLY
  green (Pint `passed` · PHPStan L5 `[OK] No errors` · **Pest 708 passed / 2 skipped / 5771 assertions**, 0
  failed — +1 test/+24 assertions vs POLISH.2's 707/5747); composer test:smoke green (3 passed). (POLISH.3)
  See [[D-110]] (POLISH.1 nav-reachability), [[D-111]] (POLISH.2 nav grouping), and FIX.2 (the landing
  figures + genuine-zeros principle this builds on).

- **D-113 — HOSPITAL.G1: bed/ward/unit model + concurrency-safe bed-claim + inpatient RBAC (Phase 1
  foundation).** The FIRST hospital-vertical gate (inpatient/ADT), built per `docs/HOSPITAL-PHASE1-ADT-MAP.md`
  — the domain foundation everything else (ADT stay, ward board, bedside charting, bed-to-billing, discharge)
  depends on. **NEW module `Modules\Hospital`** (PSR-4 + provider registered; arch rule mirrors Dental — may
  use care modules + Audit **services**, never Audit models/AiCore/Nursing/Comms; in G1 it depends only on the
  Platform foundation). **KEY DECISION 1 — Bed is NET-NEW, not a Scheduling `Resource`** (the map's §2.1
  finding): a bed is occupied CONTINUOUSLY for a multi-day stay (no `starts_at`/`ends_at`), so it reuses
  Resource's *pattern* (tenant + one branch + typed enum + `active`) and the `BookingService` lock idiom, NOT
  its table — forcing beds onto Appointment/Resource was the abstraction trap the map warned against.
  **KEY DECISION 2 — Ward is a Hospital-owned model, not Platform's unwired `Department` stub** (both were
  map-endorsed; documented): a Hospital `Ward` gives a clean bidirectional `Ward hasMany Bed` (a
  `Department::beds()` back-relation is impossible — Platform must not depend on Hospital), lets ward
  attributes grow inpatient-specific, and mirrors the `Nursing\Visit → Branch` pattern; `Department` is left
  for its generic clinic-department purpose (swapping the FK later is localized). **Housekeeping status**
  {free, occupied, cleaning, blocked} is a legal-only state machine (`Bed::TRANSITIONS`: free→{occupied,
  blocked}, occupied→{cleaning}, cleaning→{free,blocked}, blocked→{free}); **free→occupied is reached ONLY via
  the concurrency-safe `BedService::claim()`** (`DB::transaction` + `SELECT status … FOR UPDATE` + assert
  still-free under the lock), proven by `BedClaimParallelHammerTest` (8 OS processes race one free bed →
  exactly 1 winner, 7 conflicts — the `BookingParallelHammer`/`VisitAssignmentParallelHammer` sibling; the
  map's §6.3 concurrent-bed-move risk). Each transition fires `BedStatusChanged` → an **app-layer listener** →
  one append-only `bed.status_changed` audit row; ward/bed CRUD via app-layer model hooks — so Hospital stays
  free of Audit (the Branch/Resource + AppointmentTransitioned pattern). **Inpatient RBAC additive** (the
  `dental.chart` precedent; `Gate::before`/`PermissionService` unchanged): +4 permissions (`ward.manage`,
  `bed.manage`, `admission.manage`, `document.view`) + 6 role templates (ward_nurse ← nurse; charge_nurse ←
  coordinator+nurse+bed.manage; hospitalist ← doctor−dental.chart+admission.manage; bed_manager; admissions_
  clerk ← reception+patient.edit+admission.manage; him_records); `org_admin` gains all four; management gated
  on `bed`/`ward.manage`, the bed claim on `admission.manage` (placing occupancy is an admission act);
  ward-level scope is branch-level for Phase 1 (deeper scoping = a later `abac_conditions` gate). **ELECTRIC
  FENCE (operational, not clinical):** a bed/ward has NO patient/acuity/severity/score/risk/grade/flag column
  (schema fence test) — status is housekeeping, never a patient judgment; a NEWS2-style deterioration score is
  a non-goal / certified-partner integration, never homemade (map §3). NO ADT workflow (G2), NO UI (G3). No
  existing behavior test modified; the relative permission-count assertion (`RbacTest`) + the hardcoded RBAC
  negative-sweep stay green. VERIFIED: composer check FULLY green (Pint `passed` · PHPStan L5 `[OK] No errors`
  · **Pest 717 passed / 2 skipped / 5841 assertions**, 0 failed — +9 tests/+70 assertions vs POLISH.3's
  708/5771: `WardBedManagementTest` (7) + `BedClaimParallelHammerTest` (1) + the Hospital arch rule (1)); no
  frontend this gate. (HOSPITAL.G1) See [[D-096]] (the beds-vs-Resource reuse precedent), the ADT map, and
  [[Hospital]].

- **D-114 — HOSPITAL.G2: ADT stay + admit/transfer/discharge state machine (atomic, bed-safe).** The core of
  the inpatient vertical, built per `docs/HOSPITAL-PHASE1-ADT-MAP.md`. **KEY DECISION — a `Stay` is a NET-NEW
  entity ABOVE an UNMODIFIED `Encounter`** (the map's §2.2 recommendation, the `VisitPlan→Visit` analogue): G2
  does NOT touch Clinical, so `Encounter`'s one-open-per-practitioner invariant stays intact for every vertical
  (stretching Encounter to span a stay would have broken it for all consumers); bedside charting reuses
  Encounter per ward-round in G4. `stays` (BelongsToTenant, `LogsReads`) is the MUTABLE current state
  (patient, admitting clinician, current bed/ward, dates, status, admission_type/reason, disposition); the
  immutable admit/transfer/discharge history is **append-only `stay_events`** (model + DB triggers). **State
  machine (legal-only):** admitted → discharged; a **transfer is a bed-move WITHIN admitted, not a status
  change** (the map's endorsed clean set — pre-admit/scheduled admission is the optional later G8; documented).
  `admission_type` {elective, emergency, transfer} is a human-recorded operational ROUTE. **`AdmissionService`
  — each of admit/transfer/discharge is ATOMIC** (the dental-perform G4 discipline): the stay change + the bed
  claim/release (**via G1's proven concurrency-safe `BedService::claim`/`release` — reused, NOT reimplemented,
  per the gate**) + the append-only `StayEvent` in ONE `DB::transaction`; a forced failure rolls back
  everything — no orphan stay, no stuck bed, and the bed's audit row too (proven: an invalid admission_type,
  validated at Stay creation AFTER the claim, rolls the claim back — the sibling of dental's invalid-tooth-state
  rollback). admit `claim`s (free→occupied); transfer `claim`s new + `release`s old (occupied→cleaning);
  discharge `release`s + sets disposition/discharged_at. **`BedService::release()`** was ADDED (occupied→
  cleaning, `admission.manage`, the same lock idiom; additive, no existing method/test changed). **One-active-
  stay guard** (patient row-lock + `lockForUpdate()->exists()`, the one-open-encounter analogue). Bed
  concurrency is G1's hammer-proven `claim` (a second admit to the same bed → `BedNotAvailableException`,
  tested). Every transition → `StayTransitioned` → an app-layer listener → one append-only
  `admission.<eventType>` audit row (keyed by event type since a transfer keeps status=admitted; the
  AppointmentTransitioned pattern, so Hospital stays free of Audit). **NO charge posted (bed-to-billing is
  G6).** Minimal action surface: `AdmissionController` (string-id FIX.1) — show (`patient.view`, read-logged)
  + store/transfer/discharge (`admission.manage`); FIX.5 route smoke extended (the rich ward board is G3).
  **ELECTRIC FENCE (operational, not clinical):** no acuity/severity/score/triage/deterioration column
  (schema fence test); bed/ward/route/disposition are facts a human sets. No existing behavior test modified
  (the smoke was EXTENDED with the ADT fixture+routes — the ImportBatch precedent); Encounter untouched
  (asserted: an admit creates zero Encounters). VERIFIED: npm run build green; composer check FULLY green
  (Pint `passed` · PHPStan L5 `[OK] No errors` · **Pest 729 passed / 2 skipped / 5921 assertions**, 0 failed —
  +12 tests/+80 assertions vs G1's 717/5841: `HospitalAdmissionTest`); composer test:smoke green (3).
  (HOSPITAL.G2) See [[D-113]] (G1 bed/ward foundation), the ADT map, [[Clinical]] (the Encounter left intact),
  and [[Hospital]].

- **D-115 — HOSPITAL.G3: ward board (live bed-occupancy cockpit) over the ADT domain.** The first inpatient
  UI, built per `docs/HOSPITAL-PHASE1-ADT-MAP.md`. **PRESENTATIONAL over G1/G2 (P0D.GU)** — the board READS a
  ward's beds + status + the current patient per occupied bed, and SURFACES the existing actions; it computes
  no ADT/occupancy logic and never bypasses the G2 atomicity/concurrency guarantees. `WardBoardController::show`
  (GET `/hospital/wards`, gate `patient.view`) renders `Hospital/WardBoard.vue` from `WardService::activeWards()`
  + `BedService::forWard` + a Stay query keyed by `current_bed_id` (the occupant): each ward → beds (label,
  bed_type, housekeeping status), occupant name + `admitted_at` per occupied bed, and a plain occupancy count.
  **KEY: reuses the day-board TILE/STATUS idiom for LAYOUT, but the data is beds/stays (continuous occupancy) —
  the board NEVER routes through the scheduling slot engine** (the map's §6.1 abstraction warning). **Actions
  surfaced from the board go through the EXISTING G1/G2 services:** admit/transfer/discharge POST to the G2
  admission routes (**admit-from-the-board uses the proven `AdmissionService::admit` → concurrency-safe
  `BedService::claim`**, tested atomic), and NEW `setBedStatus` (POST `/hospital/beds/{bed}/status`, gate
  `bed.manage`, string-id FIX.1) → `BedService::setStatus` (legal-only). **Read gate = `patient.view`** — the
  only permission ALL inpatient clinical staff (incl. ward nurses) share, so ward nurses can view; billing
  (no patient.view) is denied. The write actions keep their own gates (admit/transfer/discharge =
  admission.manage, bed status = bed.manage); the payload's `can_admit`/`can_manage_beds` + the admit pickers
  reflect the actor (server Gate authoritative). The board is NOT per-occupant read-logged — it is an
  operational overview (the day-board posture); deep read-logging is the G2 admission `show`. **ELECTRIC FENCE
  (operational only):** housekeeping status + occupant name + `admitted_at` (LOS-so-far = plain elapsed time
  the client renders) + a plain occupancy count. NO acuity/severity/risk/priority/deterioration field; the
  status COLOUR is the housekeeping state, never a clinical judgment (asserted by a recursive `wbAssertNoJudgment`
  over the payload). No charge posted (billing is G6). No existing behavior test modified (the FIX.5 route
  smoke was EXTENDED with the board route — doctor 200 / billing 403). VERIFIED: npm run build green; composer
  check FULLY green (Pint `passed` · PHPStan L5 `[OK] No errors` · **Pest 734 passed / 2 skipped / 6023
  assertions**, 0 failed — +5 tests/+102 assertions vs G2's 729/5921: `WardBoardTest`); composer test:smoke
  green (3). (HOSPITAL.G3) See [[D-113]] (bed model), [[D-114]] (ADT domain), the ADT map, and [[Hospital]].

- **D-116 — HOSPITAL.G4: bedside charting for an inpatient stay (reuses Clinical; Encounter unmodified;
  fence holds).** Clinical documentation for a stay by REUSING the existing tested Clinical module —
  REUSE-heavy, NOT new clinical domain — per `docs/HOSPITAL-PHASE1-ADT-MAP.md`. **KEY DECISION — the Stay↔
  Encounter link is HOSPITAL-SIDE so Clinical stays UNTOUCHED** (the map's §2.2 preference): a ward round IS
  a reused Clinical `Encounter`, and a Hospital `ward_rounds` table (`WardRound` belongsTo `Stay` +
  `Encounter`) records the association — NO `stay_id` column on Encounter, NO change to Encounter's schema or
  its one-open-per-practitioner invariant. (Hospital MAY use Clinical — not forbidden by the module boundary,
  the allowed dep Dental also uses for documents/encounters.) **`BedsideChartService` composes Clinical and
  reimplements nothing:** `startRound` opens a reused Encounter via `EncounterService::open` (type `other` —
  no inpatient type added to Clinical; **the invariant is enforced UNCHANGED**, a second concurrent round for
  the stay is refused — tested) + the `WardRound` link + a sign-and-lock note draft (`ClinicalNoteService::
  saveDraft`), atomically, then redirects into the EXISTING note editor (the write→sign→lock→amend→version
  flow is reused, not rebuilt). Vitals reuse `ClinicalListService::recordVital` (note.write) tied to the
  round; **the ONLY new affordance is `vitalsForStay`** — a stay-scoped READ that filters the existing Vital
  store to the stay's round Encounters and builds the RAW series via the existing `VitalsSeries::build` (NO
  schema change). Orders reuse `OrderService::place` (order.manage). Required-FK models are resolved via typed
  model queries (`Patient/Branch/StaffProfile::findOrFail`) for the reused services. **RBAC = the EXISTING
  clinical permissions the inpatient roles already hold** (ward_nurse + hospitalist: encounter.manage /
  note.write / note.sign / order.manage; read = patient.view) — no new permission. `BedsideChartController`
  (string-id FIX.1): `show` (patient.view, read-logged) renders `Hospital/StayChart.vue`; writes carry the
  clinical gates. **ELECTRIC FENCE carries through:** raw vitals (VitalsSeries — no bands/scores), sign-and-
  lock notes unchanged, append-only order results — NO computed acuity/deterioration/early-warning score
  (NEWS2 = certified-partner/non-goal, NOT built); the stay-chart payload carries no judgment field (recursive
  scan). No charge posted (billing is G6). No existing behavior test modified (the FIX.5 smoke was EXTENDED
  with the stay-chart route); **Clinical's suite + Encounter's invariant tests stay green** (reuse, not
  modify). VERIFIED: npm run build green; composer check FULLY green (Pint `passed` · PHPStan L5 `[OK] No
  errors` · **Pest 741 passed / 2 skipped / 6104 assertions**, 0 failed — +7 tests/+81 assertions vs G3's
  734/6023: `BedsideChartTest`); composer test:smoke green (3). (HOSPITAL.G4) See [[D-114]] (the Stay), the
  ADT map, [[Clinical]] (reused unmodified), and [[Hospital]].

- **D-117 — HOSPITAL.G5: nursing shift handover (SBAR, nurse-authored, record-not-judge).** The structured
  artifact carrying a stay's key information across nursing shifts, per `docs/HOSPITAL-PHASE1-ADT-MAP.md`.
  **DISCOVERY DECISION — a handover is a NET-NEW structured SBAR artifact, NOT a reuse of `ClinicalNote`:**
  SBAR (Situation/Background/Assessment/Recommendation) ≠ the note's SOAP; a handover carries shift metadata
  (shift, outgoing nurse, handover time) a note lacks; and it is **STAY-scoped**, whereas `ClinicalNote` is
  Encounter-scoped (mandatory encounter_id — a round encounter, which a handover is not). It REUSES the
  platform PATTERNS (append-only + DB triggers, audit, `LogsReads`, the note/chart UI idioms) — the same
  "reuse the pattern, own the domain" as G2's `stay_events` and dental's append-only records. `handovers`
  (BelongsToTenant, LogsReads, **APPEND-ONLY** model guards + DB triggers): `stay_id`, `authored_by` (the
  outgoing nurse), `shift` ∈ {day, evening, night}, the SBAR text (situation [required] / background /
  assessment / recommendation — all nurse-authored), reason (a correction), handed_over_at. **RBAC reuses the
  existing nursing permission** — record = `note.write` (ward_nurse + charge_nurse hold it), read =
  `patient.view`; NO new permission. `HandoverService` (record + history) has no interpretation logic;
  `HandoverController` (string-id FIX.1): show (patient.view, read-logged) renders `Hospital/Handover.vue`
  (SBAR form + shift trail), store (note.write); the write is audited via an app-layer `Handover::created`
  hook so Hospital stays free of Audit. **ELECTRIC FENCE (record-not-judge):** the SBAR fields capture what
  the OUTGOING NURSE WRITES — `assessment` is the nurse's OWN written assessment (a SBAR section, like a
  note's SOAP assessment), NEVER a computed acuity/score; there is deliberately no severity/acuity/score/
  risk/priority/flag column, and NOTHING auto-populates any field (proven: a fresh stay has 0 handovers, a
  recorded handover's assessment is verbatim, and the payload carries no judgment key). No AI-drafted handover
  this gate. No charge posted (billing is G6). No existing behavior test modified (the FIX.5 smoke was EXTENDED
  with the handover route); G1–G4 + the fence/immutability suites stay green. VERIFIED: npm run build green;
  composer check FULLY green (Pint `passed` · PHPStan L5 `[OK] No errors` · **Pest 747 passed / 2 skipped /
  6161 assertions**, 0 failed — +6 tests/+57 assertions vs G4's 741/6104: `HandoverTest`); composer test:smoke
  green (3). (HOSPITAL.G5) See [[D-114]] (the Stay), [[D-116]] (bedside charting — the reuse posture), the
  ADT map, and [[Hospital]].
- **D-118 — HOSPITAL.G6: bed-to-billing (inpatient accrual + discharge invoice via the EXISTING engine;
  reconciles-to-the-unit).** An inpatient stay accrues charges (bed-days + services) through the existing
  billing engine, and discharge produces an invoice that reconciles-to-the-unit, per
  `docs/HOSPITAL-PHASE1-ADT-MAP.md` §2.3/§5. **KEY DECISION — NET-NEW is STRICTLY ORCHESTRATION, zero new
  billing/pricing/VAT/line-total math:** a bed-day is a tenant-authored `TariffItem`; accrual is the
  existing `ChargeCaptureService::captureManual` (the engine resolves + **snapshots** the fee and computes
  `line_total`); the discharge invoice is the existing `ChargeValidator::validateForPatientPeriod` →
  `IssueService::createDraftFromCharges` → `issue` flow (gapless number + PDF); reconciliation is the
  existing `ReconciliationEngine` (I4 "N charges → 1 invoice" natively). Hospital added NO money math —
  proven by an adversarial-grep test (`Modules\Hospital` contains none of `line_total_minor`/
  `vat_total_minor`/`subtotal_minor`/`vatMinor`/`intdiv(`; the only money it names is the authored per-diem
  RATE). **`BedBillingService`** (orchestration only): `CATALOG_KEY='hospital'`; `STARTER` = 3 GENERIC
  bed-day items (BED-DAY-GENERAL/ICU/ISOLATION, placeholder minor-unit rates, **NO licensed code set** — the
  tenant edits them like any tariff); `seedStarter` (gate `billing.manage`, idempotent by code, unit
  'bed-day', vat 0); `accrueBedDays` loops each occupied calendar day and captures one bed-day Charge via
  the engine, **idempotent** via the NEW `bed_day_accruals` ledger `unique(tenant_id, stay_id, service_date)`
  (the `nursing:materialize-visits` discipline — fast-check then capture+ledger in one `DB::transaction`, a
  race rolls back on the unique key); `invoiceStay` (gate `billing.manage`, cross-tenant fail-closed) accrues
  the final bed-days then runs the existing validate→draft→issue flow filtered to the stay window. The bed
  resolves via a typed `Bed::findOrFail` (fail-closed, no mis-price fallback). **`hospital:accrue-bed-days`**
  — the unattended sweep, shaped exactly like `nursing:materialize-visits`: iterate ACTIVE tenants, resolve
  the tenant's `org_admin` as the automated billing actor (holds `billing.manage`; skip+warn if none),
  accrue every ADMITTED stay; registered in `HospitalServiceProvider`, scheduled `->dailyAt('05:30')
  ->withoutOverlapping(30)->onOneServer()` (before the 06:00 dunning / 06:30 reconcile sweeps).
  `BedBillingController::invoice` (POST `/hospital/admissions/{stay}/invoice`, `billing.manage`, string-id
  FIX.1) → redirect to the EXISTING `billing.invoices.show`; light ADT wiring adds `can_invoice`/`invoice_url`
  + an "Invoice stay" button on a discharged stay. **RECONCILES-TO-THE-UNIT (THE key proof, tested):** a
  discharged stay's bed-day + service charges assemble into one gapless invoice and
  `ReconciliationEngine::check(period)` passes with **I4 `delta_minor === 0`**. Fee-snapshot proven. **One
  existing test updated by necessity, NOT weakened:** `ScheduleRegistrationTest` is the canonical
  scheduled-command inventory ("guards against a future gate quietly scheduling something unattended") — the
  new sweep was added to its expected cadence map + exact-set list, exactly as prior gates did for the
  nursing/billing sweeps (the two Pest suites otherwise unchanged; the FIX.5 smoke was EXTENDED with the
  invoice route, reception POST 403). Added tests: `BedBillingTest` (7 — per-diem tenant-authored, accrue via
  the engine + snapshot, idempotent command [twice ≠ double-charge], discharge invoice + **reconcile δ=0**,
  fee snapshot, no-money-math grep, RBAC + tenant fail-closed). VERIFIED: npm run build green; composer check
  FULLY green (Pint `passed` · PHPStan L5 `[OK] No errors` · **Pest 754 passed / 2 skipped / 6340 assertions**,
  0 failed — +7 tests vs G5's 747); composer test:smoke green (3). (HOSPITAL.G6) See [[D-114]] (the Stay),
  [[D-117]] (G5 — the reuse-vs-net-new posture), the ADT map §2.3/§5, [[Billing]], and [[Hospital]].
- **D-119 — HOSPITAL.G7: discharge summary + LOS + episode close-out (Phase-1 COMPLETE).** The final Phase-1
  gate ties off the inpatient episode, per `docs/HOSPITAL-PHASE1-ADT-MAP.md` §6. Mostly REUSE. **(1) LOS is a
  DERIVED fact, never a judgment:** `Stay::lengthOfStayMinutes(): ?int` = `discharged_at − admitted_at` in
  whole minutes, computed on read (null while admitted) — a read affordance on the Stay, NOT a stored column.
  ELECTRIC FENCE: the map flags an LOS-outlier flag as a clinician-/ops-set threshold, NOT a system grade, so
  there is deliberately NO outlier/rating/expected-vs-actual anywhere (asserted by a schema fence + a payload
  no-judgment scan); the UI renders raw days/hours reusing the ward board's LOS idiom. **(2) DISCOVERY — the
  discharge summary is a NET-NEW stay-scoped SIGN-AND-LOCK record, NOT a `ClinicalNote` or an uploaded
  `Document` (the G5 handover posture):** `ClinicalNote` is SOAP + encounter-scoped (mandatory encounter_id);
  `Document` is file/path-shaped (no authored content) — a discharge summary is a stay-scoped clinician
  NARRATIVE, so it OWNS `discharge_summaries` while REUSING the patterns: the `ClinicalNote` sign-and-lock
  discipline + the **`clinical_notes_signed_*` CONDITIONAL immutability trigger** (`IF OLD.status =
  'finalized'` → draft editable, finalized immutable; the invoices/timesheet_lines precedent too),
  `BelongsToTenant`, `LogsReads`, app-layer audit hooks. Columns: stay_id + patient_id (denormalized for
  read-logging) + authored_by + `summary` (required to finalize) + `instructions` (nullable) + status
  {draft, finalized} + finalized_at/by; `unique(tenant, stay)` (one per episode). `DischargeSummaryService`:
  `saveDraft` (**`note.write`**, updateOrCreate, refuses once finalized) + `finalize` (**`note.sign`**,
  row-locked + idempotent + requires a narrative — the `ClinicalNoteService::sign` discipline). **RBAC reuses
  the existing clinical permissions — NO new permission** (every inpatient clinical role holds note.write +
  note.sign; read = patient.view). **FENCE: no acuity/severity/score/risk/rating/outlier/readmission column;
  the narrative is the clinician's own words, nothing is computed or auto-populated** (proven: a fresh stay
  has 0 summaries, a saved summary is verbatim, and the payload carries no judgment key). **(3) Episode
  close-out:** `DischargeSummaryController::show` (`patient.view`, read-logged) renders the closed episode —
  LOS + disposition + the summary (draft editor OR finalized read-only) + the stay's EXISTING records
  read-only (ADT journey [G2], ward rounds [G4], handovers [G5], invoice[s] [G6] via the new ADDITIVE pure
  read `BedBillingService::invoicesForStay`). `AdmissionController::show` gained `los_minutes` + a
  `summary_url`; `Admission.vue` shows LOS + a "Discharge summary" link. **No change to G2's discharge
  state-change or G6's billing (additive).** No existing behavior test modified; the FIX.5 smoke was EXTENDED
  (discharge-summary GET 200 + reception write 403); a partial `@property` block on the G4 `WardRound` gained
  the timestamp annotations (docblock only). Added tests: `DischargeSummaryTest` (6 — LOS derived + no-flag
  fence, sign-and-lock [draft editable, finalize locks, model + DB-trigger immutable, no delete], audited +
  read-logged, closed-episode read-only, RBAC [note.write / note.sign / patient.view], tenant fail-closed).
  VERIFIED: npm run build green; composer check FULLY green (Pint `passed` · PHPStan L5 `[OK] No errors` ·
  **Pest 760 passed / 2 skipped / 6479 assertions**, 0 failed — +6 tests vs G6's 754); composer test:smoke
  green (3). **With G7, HOSPITAL PHASE 1 (inpatient / ADT) is COMPLETE (G1 beds → G2 ADT → G3 board → G4
  charting → G5 handover → G6 billing → G7 discharge):** a hospital can admit to a bed, run the ward board,
  chart bedside, hand over shifts (SBAR), bill the stay, and discharge with LOS + a signed discharge summary +
  a coherent closed episode. Phases 2–7 (pharmacy/eMAR, lab, radiology, OR, ED) remain the phased roadmap.
  (HOSPITAL.G7) See [[D-117]] (G5 — the reuse-vs-net-new posture it mirrors), [[D-118]] (G6 — the invoice it
  composes), the ADT map §6, [[Clinical]] (sign-and-lock), and [[Hospital]].
- **D-120 — PHARMACY.G1: pharmacy module + tenant-authored formulary + the medication-safety SEAM
  (null-object) + RBAC (Phase-2 foundation).** The foundation of the pharmacy / medication-management
  vertical (Phase 2 of the phased hospital build), per `docs/HOSPITAL-PHASE2-PHARMACY-MAP.md`. **(1) A new
  peer `Modules\Pharmacy`** (psr-4 + `bootstrap/providers` + `PharmacyServiceProvider`) with its own arch
  rule — it may use Platform + care modules (Patients/Clinical/Billing) + Audit SERVICES, but NOT Audit
  models, AiCore, Comms, or the peer verticals Nursing/Dental/Hospital (the inpatient stay-link for G2 is
  app-layer, not a direct Hospital dep); Pharmacy stays free of Audit (app-layer `FormularyItem` hooks).
  **(2) The tenant-authored FORMULARY** — `formulary_items` (BelongsToTenant): `code` (the tenant's OWN
  code, NOT a licensed identifier), `name`, `form` ∈ {tablet/capsule/liquid/injection/topical/other},
  `strength` (free text), `active`; `unique(tenant, code)`. `FormularyService::seedStarter` lays a SMALL
  GENERIC starter (5 common meds, the tenant's own `MED-*` codes) — the `DentalCatalogService`/
  `BedBillingService` discipline. **NO licensed drug data bundled** (no First Databank / Medi-Span / RxNorm
  / ATC / NDC) and **NO computed-safety column** (no interaction/dose/contraindication/severity/score) —
  asserted by a schema fence. A licensed drug DB would later ENRICH a row at a partner seam (not attached).
  **(3) THE MEDICATION-SAFETY SEAM, built EMPTY (the crux) —** drug-interaction / allergy-class /
  dose-range / duplicate-therapy checking each COMPUTE a clinical-safety JUDGMENT (the fence refuses "dosing
  logic … Ever"; medical-device territory, DEFERRED.md D-P0D.G3), so G1 builds the SEAM not the logic,
  EXACTLY mirroring `LabConnectivity → ManualLabConnectivity`: a `MedicationSafetyProvider` interface
  (`checkOrder`/`checkAdministration`) bound to a **`NullMedicationSafetyProvider`** returning
  `SafetyResult::none()`. **A homemade checker is a PERMANENT non-goal** (it would contradict the
  clinical-safety eval `ClinicalAgentsEvalTest`). `none()` = "CareOS asserts NOTHING about safety" (not
  "safe") — a human, and when licensed a certified partner bound in place of the null-object, owns that
  judgment; findings are ADVISORY + human-owned by design (surfaced, never auto-blocking). Proven by tests:
  the bound provider IS the null-object + returns no alerts (even for warfarin+aspirin), AND a partner test
  double is resolvable in its place (the seam is real + swappable). **(4) Pharmacy RBAC (additive)** —
  `formulary.manage` + `dispense.manage` permissions + `pharmacist` + `pharmacy_technician` role templates
  (RbacProvisioner consts + a `syncPermissionCatalog`/`provisionTenant`-all backfill migration, the
  `add_billing_manage_permission` pattern; new tenants via the Tenant `created` hook; `RbacTest` count is
  relative → green). A minimal formulary admin surface (`FormularyController` + `Pharmacy/Formulary.vue`,
  gated `formulary.manage`). NO orders/eMAR/dispensing this gate. No existing behavior test modified (the
  FIX.5 smoke was EXTENDED — formulary GET 200 + reception write 403); all vertical + arch + eval + fence
  suites stay green. Added tests: `FormularyTest` (4 — tenant-authored [own codes, no licensed/computed
  column, tenant-isolated], RBAC-gated + audited, admin surface gated, cross-tenant fail-closed) +
  `MedicationSafetySeamTest` (3 — bound to the null-object + asserts no judgment, `none()` empty, real +
  swappable partner double) + the `Pharmacy` arch rule. VERIFIED: npm run build green; composer check FULLY
  green (Pint `passed` · PHPStan L5 `[OK] No errors` · **Pest 768 passed / 2 skipped / 6548
  assertions**, 0 failed — +8 tests vs G7's 760); composer test:smoke green (3). **Phase 2 begun; G2 orders
  → G3 eMAR → G4 dispensing/inventory → G5 pharmacy billing remain, with the safety seam invoked-but-no-op
  throughout.** (PHARMACY.G1) See [[D-119]] (Phase-1 complete, the gate before), the pharmacy map, [[Clinical]]
  (the `LabConnectivity` seam + exact-match `AllergyGuard` precedents), and [[Pharmacy]].
- **D-121 — PHARMACY.G2: medication orders (net-new prescribing entity, safety-seam call-site,
  clinician-authored).** The prescribing entity of the pharmacy vertical, per the map §2.2/§5. **(1) A
  NET-NEW `MedicationOrder`, NOT the generic `Order`:** the clinical `Order` is a code+status orderable with
  ZERO dose/route/frequency/PRN — forcing meds onto it would break the lab/imaging worklists for every
  consumer (the Stay-above-Encounter reasoning again). So a med order OWNS its tables while REUSING the
  proven `Stay`/`StayEvent` shape: `medication_orders` (BelongsToTenant, LogsReads) is the MUTABLE current
  state (patient, prescribed_by, formulary_item_id [the G1 formulary], `dose_amount`+`dose_unit`, `route`
  [plain enum PO/IV/IM/SC/…], `frequency` [descriptor QID/BID/PRN], starts/stops, prn+prn_reason, note,
  status ∈ {active, held, discontinued, completed} + status_reason) with a legal-only clinician-driven state
  machine; `medication_order_events` is the **APPEND-ONLY** history (model guards + DB triggers — the
  `stay_events` recipe: placed/held/resumed/discontinued/completed + reason + who + when). **`stay_id` is a
  SOFT nullable reference** to a Phase-1 inpatient stay (NO FK/relation — Pharmacy stays arch-independent of
  Hospital; null for outpatient). **(2) THE SAFETY SEAM THREADED (the crux):** `MedicationOrderService::prescribe`
  (gate `medication.prescribe`; tenant+patient fail-closed) **CALLS `MedicationSafetyProvider::checkOrder`**
  at placement, and `safetyReview` calls it for the display surface — today the G1 null-object returns
  none(), so no alerts and the order is NEVER blocked. The result is ADVISORY + HUMAN-OWNED: a future
  certified partner's findings are SURFACED (an alerts area wired to `SafetyResult`, empty today), NEVER
  auto-blocking, NEVER auto-acting. **NO homemade interaction/dose/contraindication/duplicate checking** —
  proven by a grep test (no `new SafetyAlert(` anywhere in `Modules\Pharmacy\src`: CareOS never manufactures
  a finding) + a spy test (the seam IS called at placement and the order proceeds despite a returned alert).
  The homemade judgment stays a permanent non-goal. **(3) RBAC:** a NEW `medication.prescribe` permission —
  prescribing is a physician act (doctor / hospitalist / org_admin, NOT nurse), distinct from lab/imaging
  `order.manage`; read = `patient.view`. **(4) FENCE (record-not-judge):** every field is the CLINICIAN'S
  entry — the system computes no dose, suggests no med, ranks nothing; no computed-dose/suggested/recommended/
  verdict/severity/score column (schema fence); nothing auto-populates; the payload carries no judgment key
  and the alerts area is empty. `MedicationOrderController` (string-id FIX.1): `index` (`patient.view`,
  read-logged) renders `Pharmacy/MedicationOrders.vue` (place form + active + history + the empty alerts
  area), `store` + `transition` (`medication.prescribe`); events audited via an app-layer
  `MedicationOrderEvent::created` hook (`medication_order.<type>`). **No charge (pharmacy billing is G5); no
  eMAR/dispensing.** *(The exact-match `AllergyGuard` hard-stop is NOT wired this gate — the gate's safety
  requirement was the MedicationSafetyProvider seam; AllergyGuard would need a formulary `substance_key`, a
  later step.)* No existing behavior test modified (the FIX.5 smoke was EXTENDED — med-order GET 200 +
  reception prescribe 403); all vertical + arch + eval + fence suites stay green. Added `MedicationOrderTest`
  (7 — place [net-new, dose/route/frequency/PRN, patient + soft stay, audited]; the seam is called at
  placement + advisory + NEVER blocks [spy]; NO homemade finding [grep]; append-only + legal-only
  transitions; fence [no computed column, nothing auto-populates, empty alerts]; RBAC + read-logged; tenant/
  patient fail-closed). VERIFIED: npm run build green; composer check FULLY green (Pint `passed` · PHPStan L5
  `[OK] No errors` · **Pest 775 passed / 2 skipped / 6646 assertions**, 0 failed — +7 tests vs G1's
  768); composer test:smoke green (3). **Next: G3 eMAR** (scheduled doses + given/held/refused administration
  events, calling the seam's `checkAdministration`). (PHARMACY.G2) See [[D-120]] (G1 — the seam it threads),
  the pharmacy map §2.2, and [[Pharmacy]].
- **D-122 — PHARMACY.G3: eMAR — medication administration record (append-only, safety-seam at
  administration, record-not-judge).** The electronic MAR, per the map §2.4. **(1) A NET-NEW APPEND-ONLY
  administration domain** (not a note/order reuse): `medication_administrations` (BelongsToTenant, LogsReads)
  — one immutable row per administration against a G2 `medication_order`: `outcome` ∈ {given, held, refused}
  (the nurse's FACT), `administered_by`, `administered_at`, `scheduled_at` (nullable — the due time; null for
  PRN), `dose_amount`/`dose_unit` (the dose GIVEN — defaults from the order for 'given', null for held/
  refused), `reason` (held/refused), soft `stay_id`. Model guards + DB triggers
  (`medication_administrations_no_update`/`_no_delete`, the `medication_order_events` recipe) — a correction
  is a NEW row. **The due worklist is FACTUAL** — the patient's ACTIVE orders (`dueForPatient`), NOT a
  computed priority/acuity; a discontinued order drops off. **(2) THE SAFETY SEAM AT THE ADMINISTRATION
  POINT:** `MedicationAdministrationService::record` (gate `note.write`; tenant fail-closed) **CALLS
  `MedicationSafetyProvider::checkAdministration`** (already defined in the G1 interface + null-object — G3
  wires the CALL-SITE), and `safetyReview` calls it for the display surface — today none(), so no alerts and
  the administration is NEVER blocked. ADVISORY + HUMAN-OWNED: a future partner's findings are SURFACED (an
  alerts area wired to `SafetyResult`, empty today), never auto-blocking, never auto-acting. **NO homemade
  checking** — the module-wide `new SafetyAlert(` grep stays clean; a spy proves the seam is called at
  administration and the record proceeds despite a returned alert. **(3) FENCE (record-not-judge):** the
  outcome is the nurse's fact — the system computes no safety verdict and no "late/missed" grade; no
  computed-safety/verdict/severity/score/late/missed/flag column (schema fence); **late/missed is a RAW
  scheduled_at-vs-administered_at time comparison the UI renders, never a graded flag**; nothing
  auto-populates the outcome; the payload carries no judgment key and the alerts area is empty. **(4) RBAC:**
  administration **reuses `note.write`** — the nursing clinical-write permission the ward nurse holds (the G5
  handover precedent; NO new permission); read = `patient.view`, read-logged.
  `MedicationAdministrationController` (string-id FIX.1): `index` (GET `/pharmacy/patients/{patient}/emar`)
  renders `Pharmacy/Emar.vue` (due worklist + MAR + empty alerts), `record` (POST
  `/pharmacy/medication-orders/{order}/administer`); audited via an app-layer
  `MedicationAdministration::created` hook (`medication.administered`). **No charge (billing is G5); no
  dispensing.** *(Scope: a full frequency→times-of-day schedule materialization à la `VisitPlan→PlannedVisit`
  was kept out — the due list is the active-orders worklist, factual; `scheduled_at` is a per-administration
  recorded time.)* No existing behavior test modified (the FIX.5 smoke was EXTENDED — eMAR GET 200 +
  reception administer 403); all vertical + arch + eval + fence suites stay green. Added
  `MedicationAdministrationTest` (7 — record given/held/refused [dose defaults, scoped, audited + chain]; the
  seam is called at administration + advisory + NEVER blocks [spy]; NO homemade finding [grep]; append-only
  [model + raw-DB]; fence [no computed column, nothing auto-populates, late/missed a raw time pair, empty
  alerts]; due worklist factual [active orders, discontinued drops off]; RBAC + read-logged + tenant/patient
  fail-closed). VERIFIED: npm run build green; composer check FULLY green (Pint `passed` · PHPStan L5
  `[OK] No errors` · **Pest 782 passed / 2 skipped / 6753 assertions**, 0 failed — +7 tests vs G2's
  775); composer test:smoke green (3). **Next: G4 dispensing + inventory.** (PHARMACY.G3) See [[D-121]] (G2 —
  the order it administers), the pharmacy map §2.4, and [[Pharmacy]].
- **D-123 — PHARMACY.G4: dispensing + inventory (safe/concurrency-safe stock decrement, append-only).** The
  operational dispensing + stock domain, per the map §1. **(1) NET-NEW inventory:** `medication_stocks`
  (BelongsToTenant) is the MUTABLE current on-hand per formulary item (the Bed-status analogue) — `on_hand`,
  `unit`, `reorder_threshold` (a plain number); mutated ONLY under a FOR UPDATE row lock
  (`MedicationStock::lockOnHand` — the `BedService::lockBedStatus` idiom). `stock_movements` (append-only,
  model guards + DB triggers) is the immutable ledger (type received/dispensed/adjusted, signed
  `quantity_change`, `resulting_on_hand`) — the current on_hand stays consistent with the latest movement.
  `StockService` = `receive` (+qty) + `adjust` (stock-take to an absolute count) + reads, each locking then
  writing a movement. **(2) DISPENSING:** `dispenses` (append-only, LogsReads) records a pharmacist
  dispensing a quantity against a G2 order. `DispensingService::dispense` (gate `dispense.manage`; tenant +
  patient fail-closed) does a **factual state check** (the order must be active — can't dispense a
  discontinued order) then, in ONE transaction, **locks the stock FOR UPDATE → asserts on_hand ≥ qty (else
  `insufficientStock`) → creates the Dispense → decrements on_hand → appends the 'dispensed' movement** —
  ATOMIC (a forced failure rolls back all) and CONCURRENCY-SAFE. **(3) THE SAFE + CONCURRENCY-SAFE DECREMENT
  (the crux):** no oversell, no negative on-hand; proven by `DispenseParallelHammerTest` — 8 OS processes
  race to dispense the last unit, exactly ONE wins + 7 get `INSUFFICIENT` + on_hand=0 (the
  `BedClaimParallelHammer`/`pharmacy:attempt-dispense` sibling). **(4) FENCE (operational sanity):** stock +
  dispensing are operational FACTS — a reorder threshold is a plain number, "below stock" is a factual
  `on_hand <= reorder_threshold` comparison (`isBelowThreshold`), NEVER a graded/severity/alert judgment (the
  eMAR late/missed rule); **NO safety checking in dispensing** (the medication-safety seam is
  orders/administration, not dispensing); no computed-judgment/safety column on any of the three tables
  (schema fence). **(5) RBAC:** dispensing/inventory **reuse `dispense.manage`** (the G1
  pharmacist/pharmacy_technician permission — NO new permission); the per-patient dispensing view reads
  `patient.view`, read-logged. `InventoryController` (GET `/pharmacy/inventory`, `dispense.manage`) →
  `Inventory.vue`; `DispensingController` (GET `/pharmacy/patients/{patient}/dispensing`) → `Dispensing.vue`;
  audited via app-layer `Dispense::created` (`medication.dispensed`, patient) + `StockMovement::created`
  (`stock.<type>`, tenant) hooks. **No charge (pharmacy billing is G5).** No existing behavior test modified
  (the FIX.5 smoke was EXTENDED — inventory + dispensing GET 200 + reception dispense 403); all vertical +
  arch + eval + fence suites stay green. Added `DispensingTest` (6 — receive/adjust [append-only, on-hand
  consistent, audited]; dispense [decrements, atomic, tied to order, audited + read-logged]; the stock guard
  [no oversell, no negative]; discontinued-order refused [factual state]; fence [below-threshold factual, no
  graded/safety column]; RBAC + tenant/patient) + `DispenseParallelHammerTest` (1 — the concurrency proof).
  VERIFIED: npm run build green; composer check FULLY green (Pint `passed` · PHPStan L5 `[OK] No errors` ·
  **Pest 789 passed / 2 skipped / 6846 assertions**, 0 failed — +7 tests vs G3's 782); composer
  test:smoke green (3). **Next: G5 pharmacy billing** (a formulary item's TariffItem → captureManual →
  invoice → reconcile-to-the-unit, the bed-day precedent, no new math). (PHARMACY.G4) See [[D-122]] (G3 — the
  administration it complements), [[D-114]]/bed-claim (the lock idiom), the pharmacy map §1, and [[Pharmacy]].

- **D-124 — PHARMACY.G5: pharmacy billing — a dispensed med accrues a charge through the EXISTING engine
  (reconciles-to-the-unit); Phase 2 core COMPLETE.** The final buildable pharmacy gate, per the map §2.1/§5.
  **KEY POSTURE — NET-NEW is STRICTLY ORCHESTRATION, zero new billing/pricing/VAT/line-total math** (the
  bed-day HOSPITAL.G6 [[D-118]] pattern, mirrored). **(1) MEDICATION PRICING — a formulary item maps to a
  tenant-authored `TariffItem`:** a soft nullable `tariff_item_id` on `formulary_items` (no FK — Pharmacy
  stays decoupled, the `stay_id` shape); `PharmacyBillingService::priceItem` (gate `billing.manage`, tenant
  fail-closed) `updateOrCreate`s a `TariffItem` in a get-or-created `pharmacy` `TariffCatalog` (the tenant's
  OWN `MED-*` code, integer minor units, **zero VAT**, `requires_service_documentation=false`) and links it —
  pricing lives in the EXISTING billing/tariff store, **NOT duplicated in pharmacy**, and **NO licensed drug
  pricing bundled** (the tenant authors placeholder rates it edits, the `BedBillingService::seedStarter`
  discipline). **(2) CHARGE ON DISPENSE via the EXISTING engine:** `chargeForDispense(actor, Dispense)` →
  `ChargeCaptureService::captureManual($patient, $branch, $dispensed_at, $item->code, $quantity, $actor)` —
  **the engine resolves the tariff by code, SNAPSHOTS the fee, and computes `line_total` (qty × price)**;
  Pharmacy does NO money math. IDEMPOTENT via a new `dispense_charges` link `unique(tenant, dispense_id)` (a
  dispense is charged once; re-charging returns the same Charge). An UNPRICED med returns null (no charge, no
  gate) so the G4 dispensing tests stay green. Wired BEST-EFFORT + DECOUPLED in `DispensingController` AFTER
  the dispense commits (`try { … } catch (Throwable)`), so a billing hiccup NEVER blocks the
  concurrency-critical dispense (reconcilable later). **(3) INVOICE + RECONCILE-TO-THE-UNIT (THE key proof):**
  `invoicePatient(actor, patient, from, to)` runs the EXISTING `validateForPatientPeriod` →
  `createDraftFromCharges(SELF_PAY)` → `issue` flow (gapless number + PDF) over the patient's validated,
  uninvoiced charges in the window; a patient invoice INCLUDING a dispensed-med charge assembles into one
  gapless invoice and `ReconciliationEngine::check(period)` passes with **I4 `delta_minor === 0`** (for
  INPATIENT, pharmacy charges join the stay's discharge invoice via the existing G6 `invoiceStay` — same
  gather-by-patient+period, no new invoice logic). **(4) RBAC:** the `pharmacist` role gains the EXISTING
  `billing.manage` (the pharmacist bills dispensed meds through the engine) — RbacProvisioner const + a
  `provisionTenant`-all backfill migration; **NO new permission** (billing.manage already exists), so the
  `RbacTest` permission-count stays green. A `PricingController` (GET `/pharmacy/pricing` +
  POST `/pharmacy/pricing/{item}`, `billing.manage`, string-id FIX.1) → `Pharmacy/Pricing.vue` (set a med's
  price like any tariff item) + i18n. **(5) ELECTRIC FENCE / no-money-math (adversarial grep, tested):** a med
  price is a RATE (financial), never a safety/appropriateness/substitution verdict — no cost-based
  substitution suggestion (substitution/safety is the certified-partner seam, not billing); `Modules\Pharmacy`
  contains none of `line_total_minor`/`vat_total_minor`/`subtotal_minor`/`vatMinor`/`intdiv(` (the only money
  it names is the authored per-unit RATE; all charge/VAT/line-total math lives in Billing); no
  cost/clinical-judgment column on `formulary_items`. No existing behavior test modified (the FIX.5 smoke was
  EXTENDED — pricing GET 200 + reception price-set 403). Added `PharmacyBillingTest` (6 — med priced as a
  tenant-authored TariffItem [own code, integer minor units, zero VAT, no licensed pricing]; dispense captures
  a Charge via the engine + snapshot + idempotent [twice ≠ double-charge]; **patient invoice WITH a med charge
  reconciles δ=0** [2 × 800 = 1600, I4 green]; no-money-math grep + no-judgment-column fence; fee snapshot
  [re-pricing never changes a past charge]; RBAC billing.manage + cross-tenant fail-closed). VERIFIED: npm run
  build green; composer check FULLY green (Pint `passed` · PHPStan L5 `[OK] No errors` · **Pest 795
  passed / 2 skipped / 7056 assertions**, 0 failed — +6 tests vs G4's 789); composer test:smoke green
  (3). **PHARMACY CORE COMPLETE: G1 formulary + safety-seam → G2 orders → G3 eMAR → G4 dispensing/inventory →
  G5 billing.** The ONE deliberate gap is the medication-safety JUDGMENT (drug-interaction / allergy-class /
  dose-range / duplicate-therapy) — an EMPTY `NullMedicationSafetyProvider` seam invoked-but-no-op throughout
  (G2 placement, G3 administration), the certified-partner binding point; a homemade checker is a PERMANENT
  non-goal (medical-device territory, contradicts the clinical-safety eval). Phases 3–7 (lab / radiology / OR
  / ED) remain. (PHARMACY.G5) See [[D-123]] (G4 — the dispense it charges), [[D-118]]/bed-day (the
  reconciles-to-the-unit orchestration precedent it mirrors), [[D-120]] (G1 — the safety seam it leaves
  empty), the pharmacy map §2.1/§5, and [[Pharmacy]].

- **D-125 — SURGERY.G1: surgery module + theatre + theatre-scheduling (overlap-safe) + surgical-case model +
  OR RBAC — the OR/surgery FOUNDATION (Phase 5).** Per `docs/HOSPITAL-PHASE5-SURGERY-MAP.md`. **(1) NEW peer
  `Modules\Surgery`** (composer psr-4 + `bootstrap/providers.php` + `SurgeryServiceProvider` + an
  `arch('Surgery …')` rule: may use Platform + care modules Patients/People/Clinical/Billing/Scheduling +
  Audit SERVICES; NOT Audit models, AiCore, Comms, or peer verticals Nursing/Dental/Hospital/Pharmacy). **(2)
  THE THEATRE-SCHEDULING CALL (the crux, map §2.1 — the Bed/Stay "don't force the wrong abstraction"
  precedent):** the Scheduling `Appointment` is a fixed, service-derived clinic slot with NO per-booking
  duration and NO planned-vs-actual occupancy (`ends_at` never updated → an overrun reads *free*), so a
  **theatre is a Surgery-OWNED entity** (`theatres`; NOT Scheduling's `Resource`) and a surgical block is a
  **NET-NEW `theatre_slots`** — a BOUNDED pre-planned block (`starts_at`+`ends_at`, status
  booked/in_progress/completed/cancelled, soft nullable `surgical_case_id`) that **REUSES the
  `BookingService::lockResource`→`assertNoOverlap` INVARIANT** but NOT the day-board model.
  `TheatreSchedulingService`: `createTheatre` (gate `theatre.manage`) + `bookSlot` (gate `surgery.schedule`) —
  in ONE `DB::transaction`, `lockTheatre` (`select … for update` on the theatre row, the `lockResource`
  idiom) → `assertNoOverlap` (blocking slots where `starts_at < ?end AND ends_at > ?start`, `for update`) →
  insert. **THE OVERLAP-LOCK PROOF:** `TheatreBookingParallelHammerTest` — 8 OS processes race for the same
  contested block; exactly ONE `BOOKED:` + 7 `CONFLICT:`, one slot (the `surgery:attempt-book-slot` hammer,
  the `pharmacy:attempt-dispense` / `hospital:attempt-bed-claim` sibling); adjacent/other-theatre blocks
  allowed. **(3) NET-NEW `surgical_cases`** (BelongsToTenant, LogsReads) — patient, `primary_surgeon_id`
  (staff_profiles), soft nullable `stay_id` (inpatient link; null = day-surgery), `procedure_description`
  (free text in G1), `scheduled_at`, `status` default `scheduled`; `SurgicalCaseService::schedule` (gate
  `surgery.manage`, patient + surgeon fail-closed). The lifecycle machine (pre_op → in_progress → completed →
  post_op) + append-only case events are SURGERY.G2. **(4) OR RBAC (additive, the dental.chart/inpatient/
  pharmacy precedent):** `theatre.manage`/`surgery.schedule`/`surgery.manage` permissions +
  `surgeon`/`anesthetist`/`scrub_nurse`/`surgical_scheduler` roles; `org_admin` gains all three; RbacProvisioner
  consts + a `provisionTenant`-all backfill migration; new tenants via the Tenant `created` hook. `RbacTest`'s
  permission-count is self-referential (no edit); `RbacNegativeSweepTest`'s withheld-map is untouched. **(5)
  ELECTRIC FENCE (operational/scheduling):** a theatre/slot/case is a human-recorded fact — no computed
  acuity/priority/risk/severity/triage/score/urgency/grade column (schema fence, tested); a surgical-risk
  score is the fence line (map §3), a certified-partner / non-goal, NEVER here; the ASA class
  (anesthetist-ASSIGNED) + the intra-op device-data / surgical-risk seam (the `LabConnectivity` /
  `MedicationSafetyProvider` precedent) arrive with the anesthesia record (a later gate — nothing invokes the
  seam in G1). Audited app-layer (`theatre.created`/`theatre_slot.booked` tenant-level +
  `surgical_case.scheduled` patient-scoped) so Surgery stays free of Audit; the case is read-logged. No case
  lifecycle/checklist/consumables/billing/UI this gate. No existing behavior test modified (RBAC additive);
  all vertical + arch + eval + fence suites stay green. Added `TheatreSchedulingTest` (7) +
  `TheatreBookingParallelHammerTest` (1) + the `Surgery` arch rule. VERIFIED: composer check FULLY green (Pint
  `passed` · PHPStan L5 `[OK] No errors` · **Pest 804 passed / 2 skipped / 7132 assertions**, 0
  failed — +9 tests vs G5's 795). **Phase 5 begun: G2 case lifecycle → G3 op notes → G4 WHO checklist → G5
  consumables → G6 billing remain, each on the platform.** (SURGERY.G1) See [[D-124]] (PHARMACY.G5 — the
  vertical it follows), [[D-118]]/bed-day + the Bed/Stay "wrong abstraction" call (the theatre-scheduling
  precedent), [[verify-ci-directly-github-api]], the surgery map §2.1/§4/§5, and [[Surgery]].

- **D-126 — SURGERY.G2: surgical case lifecycle + op documentation (reuses Clinical; ASA-assigned; no computed
  risk).** Per `docs/HOSPITAL-PHASE5-SURGERY-MAP.md` §2.2/§2.3 — **this gate spans the map's lifecycle (§2.2)
  AND op-notes/ASA (§2.3)**, so the map's "G3 op-notes" is folded into G2. **(1) THE LEGAL-ONLY LIFECYCLE:**
  `SurgicalCase::TRANSITIONS` (scheduled → {pre_op, cancelled}; pre_op → {in_progress, cancelled}; in_progress
  → completed; completed → post_op; post_op/cancelled terminal) + `canTransition`; `SurgicalCaseService::transition`
  (gate `surgery.manage`, tenant fail-closed) asserts legal (else `invalidTransition`) → in ONE
  `DB::transaction`, `forceFill` status + status_reason + the per-phase FACTUAL timestamp
  (`pre_op_at`/`in_progress_at`[incision]/`completed_at`/`post_op_at`/`cancelled_at`) + append a
  `SurgicalCaseEvent`. The `MedicationOrder`/`Stay` shape (model-hook audit). **(2)** `surgical_case_events`
  (BelongsToTenant, LogsReads, **APPEND-ONLY** — model guards + DB triggers, the `medication_order_events`
  recipe); audited app-layer `surgical_case.<event_type>` (patient-scoped). **(3)** the surgical TEAM
  (`surgical_case_team_members`, surgeon/anesthetist/scrub_nurse/other, `unique(tenant, case, staff)`,
  `addTeamMember` updateOrCreate). **(4) OP DOCUMENTATION — REUSE Clinical, `Encounter` UNMODIFIED (the
  `ward_rounds`/`BedsideChartService` precedent):** `surgical_case_encounters` (Surgery-side link:
  `surgical_case_id`, `encounter_id` FK, `phase`; `unique(tenant, encounter_id)`). `startNote(actor, case,
  phase)` opens a `TYPE_PROCEDURE` `Encounter` via the EXISTING `EncounterService::open`, links it, drafts a
  `ClinicalNote` via `ClinicalNoteService::saveDraft`, then **CLOSES the encounter** so no lingering open
  encounter breaks the one-open-per-practitioner invariant for other verticals (tested — a fresh encounter
  opens cleanly afterward, 2 notes on one case don't collide). The surgeon writes → signs → amends via the
  EXISTING `clinical.notes.edit` editor, unchanged; Surgery MAY `use Modules\Clinical` (its arch rule allows
  care modules); the note trail is audited by Clinical's existing Encounter/ClinicalNote listeners (no bespoke
  hook). **`encounters` schema UNTOUCHED** (no surgical_case_id/stay_id — asserted). **(5) ASA/Mallampati —
  ANESTHETIST-ASSIGNED (recorded facts, NEVER computed):** `recordAnesthesiaAssessment` (gate `surgery.manage`)
  validates the closed sets (`ASA_CLASSES` I–VI / `MALLAMPATI_CLASSES` I–IV) + records the assigned class +
  provenance (`asa_assessed_by`/`asa_assessed_at`) on the case. **ELECTRIC FENCE: CareOS records the ASSIGNED
  value; it computes NO surgical-risk score/prediction** — a computed risk score is medical-device territory
  (map §3), certified-partner/non-goal; proven by a schema fence (no risk/score/prediction/acuity/severity/
  triage/grade column on any surgical table) + a `Modules\Surgery\src` grep (no `computeRisk`/`riskScore`/
  `predictRisk`). **The anesthesia DEVICE-DATA feed stays DEFERRED (partner-gated)** — documentation is
  buildable; the intra-op device feed (anesthesia machine / monitor) is noted-not-built (a grep asserts no
  `DeviceFeed`/`AnesthesiaMachine`/`hl7` code). **(6) UI (P0D.GU):** `SurgicalCaseController` (index board +
  store + show [read-logged] + transition + team + anesthesia + startNote→redirect to the note editor) +
  `Surgery/CaseBoard.vue` + `Surgery/Case.vue` + i18n (the case Inertia prop is `surgicalCase`, NOT the
  reserved `case`). No charge (billing is a later gate). No existing behavior test modified; **Encounter's
  invariant + Clinical's suite + the clinical-safety eval + G1 + reconciliation/fence/immutability suites stay
  green**; the FIX.5 smoke was EXTENDED (case board + detail GET 200 + reception transition 403). Added
  `SurgicalCaseLifecycleTest` (10). VERIFIED: npm run build green; composer check FULLY green (Pint `passed` ·
  PHPStan L5 `[OK] No errors` · **Pest 814 passed / 2 skipped / 7332 assertions**, 0 failed — +10
  tests vs G1's 804); composer test:smoke green (3). **Next: the WHO Surgical Safety Checklist**
  (record-not-judge; then consumables → billing). (SURGERY.G2) See [[D-125]] (G1 — the case it extends),
  [[D-119]]/HOSPITAL.G4 + the `WardRound`/`BedsideChartService` bedside-charting reuse (the op-doc precedent),
  [[D-121]]/PHARMACY.G2 (the transition + append-only-event shape it copies), the surgery map §2.2/§2.3, and
  [[Surgery]].

- **D-127 — SURGERY.G3: WHO Surgical Safety Checklist — RECORDED, NOT ENFORCED (no case-gating).** Per
  `docs/HOSPITAL-PHASE5-SURGERY-MAP.md` §2.4. **THE CRUX FENCE LINE:** the three-phase WHO checklist (sign_in /
  time_out / sign_out) the team COMPLETES is a RECORD; it NEVER blocks/gates the case or any G2 transition — a
  blocking checklist would be a safety-enforcement medical device (a fence violation). CareOS records
  completion; the human team owns the safety decision. **(1)** `surgical_checklist_template_items` (BelongsToTenant,
  MUTABLE) — the tenant-authored WHO template, seeded with the standard freely-published WHO items as an
  EDITABLE starter (NOT a licensed set — the formulary discipline; auto-seeded idempotently). **(2)**
  `surgical_checklists` (BelongsToTenant, LogsReads) — the per-case container (`unique(tenant, case)`). **(3)**
  `surgical_checklist_items` (BelongsToTenant, LogsReads, **APPEND-ONLY** — model guards + DB triggers, the
  `surgical_case_events` recipe) — one immutable row per confirmation (`phase`+`label` snapshot, `checked`, who,
  when, note); a correction is a NEW row; current state = latest. **`SurgicalChecklistService`**: `seedTemplate`
  (gate `surgery.manage`), `openChecklist` + `confirmItem` (gate `note.write`), `forCase` (a FACTUAL read model —
  active items per phase + latest check state + a plain `checked_count`/`total`); **it NEVER touches the case
  status.** **(4) THE FENCE (proven):** the G2 case state machine is UNCHANGED by checklist state — a case
  transitions through the FULL lifecycle (incision included) REGARDLESS of checklist completeness (tested with
  a 0-item EMPTY checklist). NO computed safety verdict — no verdict/passed/safe/pass_fail/compliant/score
  column (schema fence), the read model is a count (never a verdict key), and a `Modules\Surgery\src` grep
  finds no `safeToProceed`/`checklistPassed`/`gateOnChecklist` method. A factual "checked / total" count is a
  FACT, not a judgment. **(5) RBAC:** read + confirm reuse **`note.write`** (the whole surgical team;
  reception has none); template seeding is `surgery.manage`. **(6) UI (P0D.GU):** `SurgicalChecklistController`
  (show [read-logged] + confirm) + `Surgery/Checklist.vue` (a checkbox per item + a factual count + an explicit
  "does not block the surgery" note) + a `Surgery/Case.vue` link + i18n (the Inertia prop is `surgicalCase`,
  not the reserved `case`). Audited app-layer (`surgical_checklist.opened` + `surgical_checklist.item_confirmed`,
  patient-scoped). No charge (billing is a later gate). No existing behavior test modified; **the clinical-safety
  eval + G1/G2 + reconciliation/fence/immutability suites stay green**; the FIX.5 smoke was EXTENDED (checklist
  GET 200 + reception confirm 403). Added `SurgicalChecklistTest` (7). VERIFIED: npm run build green; composer
  check FULLY green (Pint `passed` · PHPStan L5 `[OK] No errors` · **Pest 821 passed / 2 skipped /
  7571 assertions**, 0 failed — +7 tests vs G2's 814); composer test:smoke green (3). **Next:
  consumables / implant tracking** (reuse the pharmacy inventory recipe + lot/serial/UDI), then surgical
  billing. (SURGERY.G3) See [[D-126]] (G2 — the case it documents), [[D-122]]/PHARMACY.G3 (the append-only
  record-not-judge posture), the surgery map §2.4, and [[Surgery]].

- **D-128 — SURGERY.G4: consumables + implant tracking (reuses/mirrors the pharmacy inventory recipe;
  lot/serial/UDI traceability).** Per `docs/HOSPITAL-PHASE5-SURGERY-MAP.md` §2.5. **MIRRORS the pharmacy G4
  inventory + concurrency-safe decrement recipe** — Surgery cannot import the peer Pharmacy vertical (arch
  rule), so the recipe is COPIED with Surgery-owned tables — plus a **NET-NEW implant lot/serial/UDI
  traceability extension** (a device-recall / regulatory requirement). **(1)** the mirrored inventory:
  `surgical_items` (tenant-authored catalog + `is_implant` flag; the `FormularyItem` shape) →
  `surgical_item_stocks` (on-hand mutated ONLY under `lockOnHand` FOR UPDATE; `isBelowThreshold` factual; the
  `MedicationStock` shape) → `surgical_stock_movements` (APPEND-ONLY ledger; the `StockMovement` recipe).
  `SurgicalStockService` (createItem/receive/adjust, gate `surgery.manage`). **(2)** consumable USAGE:
  `case_item_usages` (APPEND-ONLY, LogsReads); `SurgicalUsageService::recordUsage` (gate `note.write`) does the
  ATOMIC decrement (mirror `DispensingService::dispense`): `DB::transaction { lockOnHand → assert on_hand ≥ qty
  (else `insufficientStock`) → create usage → decrement → append 'used' movement }`. **SAFE +
  CONCURRENCY-SAFE (no oversell, no negative) — proven by `SurgicalItemUsageParallelHammerTest`** (8 processes
  race for the last unit; 1 `USED:` + 7 `INSUFFICIENT:`, on_hand=0; the `surgery:attempt-use-item` hammer).
  **(3)** IMPLANT lot/serial/UDI TRACEABILITY (net-new): `implant_placements` (APPEND-ONLY, LogsReads) —
  which implant (lot/serial/UDI) → which patient, indexed by lot + UDI; `placeImplant` (gate `note.write`,
  asserts `is_implant` + a lot) decrements 1 unit AND records the placement atomically. **THE RECALL LOOKUP**
  `patientsForLot(lot|udi|serial)` returns the placements/patients — a FACTUAL traceability query (tested: the
  same lot in 2 patients → both returned), NEVER a device-safety verdict; `implantsForPatient` = the patient's
  implant history. **(4) RBAC:** stock admin = `surgery.manage`; usage/implant = `note.write` (the surgical
  team). **(5) ELECTRIC FENCE (operational / traceability):** stock/usage/implant are FACTS —
  `isBelowThreshold` a factual count; implant traceability is RECORD-KEEPING (which implant → which patient),
  NOT a device-safety judgment (the system records the identifiers, it does NOT verify/grade/compute a
  recall verdict) — schema fence (no verdict/safe/recall_status/grade/severity/risk/score column on any of the
  5 tables) + a `Modules\Surgery\src` grep (no `verifyDevice`/`recallStatus`/`deviceSafe`/`gradeImplant`).
  `SurgicalInventoryController` + `Surgery/Inventory.vue` (catalog + stock + receive/adjust + recall lookup) +
  `CaseSuppliesController` + `Surgery/CaseSupplies.vue` (usage/implant + patient implant history, read-logged)
  + `Surgery/Case.vue` links + i18n; audited app-layer (`surgical_item.created`/`surgical_stock.<type>`
  tenant-level + `surgical_item.used`/`implant.placed` patient-scoped). No charge (surgical billing is G5). No
  existing behavior test modified; **the clinical-safety eval + G1–G3 + reconciliation/fence/immutability
  suites stay green**; the FIX.5 smoke was EXTENDED (inventory + supplies GET 200 + reception use 403). Added
  `SurgicalInventoryTest` (8) + `SurgicalItemUsageParallelHammerTest` (1). VERIFIED: npm run build green;
  composer check FULLY green (Pint `passed` · PHPStan L5 `[OK] No errors` · **Pest 830 passed / 2 skipped
  / 8002 assertions**, 0 failed — +9 tests vs G3's 821); composer test:smoke green (3). **Next:
  surgical billing** (reuse the engine → reconcile-to-the-unit) — the last Phase-5 core gate. (SURGERY.G4) See
  [[D-123]]/PHARMACY.G4 (the inventory + concurrency-safe decrement recipe it mirrors), [[D-126]] (G2 — the
  case it supplies), the surgery map §2.5, and [[Surgery]].
- **D-129 — SURGERY.G5: surgical billing — case charges via the EXISTING engine (reconciles-to-the-unit);
  Phase 5 (OR) COMPLETE.** Per `docs/HOSPITAL-PHASE5-SURGERY-MAP.md` §2.6. A surgical case accrues charges
  (procedure + theatre-time + consumables/implants) through the EXISTING billing engine and they invoice +
  RECONCILE-TO-THE-UNIT. **STRICTLY ORCHESTRATION — NO new billing/pricing/VAT/line-total math** (the pharmacy
  G5 [[D-124]] / bed-day HOSPITAL.G6 [[D-118]] shape, COPIED because Surgery cannot import the peer verticals
  but MAY use Billing). **(1) Tenant-authored pricing:** a `surgery` `TariffCatalog` holds each billable as a
  `TariffItem` (integer minor units, `vat_rate_bp` 0, NO licensed pricing) — a procedure (`priceProcedure`,
  own code), theatre-time (the fixed `THEATRE-TIME` code, unit `theatre-minute`), and each consumable/implant
  (`priceItem`, authored against the G4 `surgical_items.code`, linking the new soft `surgical_items.tariff_item_id`;
  `SurgicalItem::isPriced()`); all via `authorTariff` = `TariffItem::updateOrCreate` keyed `(catalog, code)`.
  **(2) Charge capture via the EXISTING `ChargeCaptureService::captureManual`** — `chargeCase(actor, case,
  ?procedureCode, ?theatreMinutes)` (gate `billing.manage`, tenant fail-closed) pushes a capture per billable
  (procedure ×1, theatre-time ×minutes, each priced consumable/implant ×total-used from the G4
  `case_item_usages` via `pricedUsageTotals` which SKIPS unpriced items); **the ENGINE resolves the tariff by
  code, SNAPSHOTS the fee, and computes the line total** — Surgery does no money math. IDEMPOTENT via
  `surgical_case_charges` (`unique(tenant, charge_id)` = the `dispense_charges` bridge; stores NO money).
  **(3) RECONCILES-TO-THE-UNIT:** `invoiceCase` (`validateForPatientPeriod` → gather the patient's VALIDATED,
  uninvoiced charges on the service day → `createDraftFromCharges(SELF_PAY)` → `issue`) — the existing
  `ReconciliationEngine::check` ties out **I4 `delta_minor === 0` WITH surgical charges present** (THE key
  proof). **INPATIENT path:** a surgical case's charges are patient charges with a service_date in the stay
  window, so Hospital's `BedBillingService::invoiceStay` sweeps them onto the stay discharge invoice (same
  gather-by-patient+period) **without Surgery importing Hospital** — reconciles δ=0 too (tested, importing
  Hospital test-side only). **(4) RBAC:** pricing + charge + invoice reuse `billing.manage` (**NO new
  permission, NO RBAC migration** — the billing office bills; the surgeon [`surgery.manage`, not
  `billing.manage`] and reception are REFUSED). **(5) ELECTRIC FENCE (financial):** a price is a RATE, never a
  clinical/appropriateness verdict — `surgical_case_charges` stores no money; `surgical_items` carries no
  verdict/appropriateness/medical_necessity column; the `line_total_minor`/`vat_total_minor`/`subtotal_minor`/
  `vatMinor`/`intdiv(` grep over `Modules\Surgery\src` is CLEAN (the controller reads the issued invoice's
  `total_minor` [a Billing figure] but never a charge's `line_total_minor` — the per-line + pre-invoice estimate
  math is done presentationally in the Vue, the same class as minor→major formatting). No app-layer audit hook
  for the link table (the `Charge`/`Invoice` are audited by Billing — the `DispenseCharge` precedent).
  `SurgicalPricingController` + `Surgery/SurgicalPricing.vue` + `SurgicalBillingController` +
  `Surgery/CaseBilling.vue` + a `Surgery/Case.vue` billing link + i18n; the FIX.5 smoke was EXTENDED (pricing +
  case-billing GET 200 + reception charge 403). No existing behavior test modified; added `SurgicalBillingTest`
  (7). VERIFIED: npm run build green; composer check FULLY green (Pint `passed` · PHPStan L5 `[OK] No errors` ·
  **Pest `837` passed / `2` skipped / `8346` assertions**, 0 failed); composer test:smoke
  green (3). **PHASE 5 (OR / SURGERY) COMPLETE** — G1 theatre/scheduling+case → G2 lifecycle+op-docs+ASA → G3
  WHO checklist → G4 consumables/implants → G5 billing; an OR runs end-to-end. Deliberate seams stay open: the
  intra-op anesthesia device-data feed (partner) + a computed surgical-risk score (non-goal). **Next verticals:
  Phases 3 (lab), 4 (radiology), 6 (ED).** (SURGERY.G5) See [[D-124]]/PHARMACY.G5 (the billing pattern it
  mirrors), [[D-118]]/HOSPITAL.G6 (bed-to-billing + `invoiceStay`), [[D-128]] (G4 — the consumables it bills),
  the surgery map §2.6, and [[Surgery]].

- **D-130 — ED.G1: ED module + `EdVisit` flow entity + the triage-acuity seam (null-object) + ED RBAC — the
  Phase-6 (Emergency Department) FOUNDATION.** Per `docs/HOSPITAL-PHASE6-ED-MAP.md`. The ED's value is
  patient-FLOW, buildable in isolation (unlike lab/radiology, whose value is an external feed). **THE
  NET-NEW `EdVisit` DECISION (the crux):** an ED presentation is NEITHER a Clinical `Encounter` (single-sitting,
  one-open-per-practitioner — an ED presentation has an arrival→triage→treatment→disposition FLOW) NOR an
  inpatient `Stay` (an inpatient episode with a bed — MOST ED visits discharge home and never become one), so
  it is a **NET-NEW `EdVisit` flow entity** — the Bed/`Stay`/`SurgicalCase` "own flow-entity above a reused
  primitive" discipline ([[D-115]]/[[Hospital]] Stay, [[Surgery]] SurgicalCase). `ed_visits` (BelongsToTenant,
  LogsReads, tenant+branch scoped): patient, `arrived_at`, `arrival_mode` (walk_in/ambulance/referral),
  `chief_complaint` (free text), `status`, nullable `disposition` (admit/discharge/transfer — the G5 handoff
  detail) + `dispositioned_at`; **`status` out of `$fillable`** (moves only via the legal-only machine). **The
  legal-only lifecycle:** `arrived → triaged → in_treatment → awaiting_disposition → dispositioned` (+
  `left_without_being_seen` from arrived/triaged); illegal moves throw. `ed_visit_events` (**APPEND-ONLY** —
  model guards + `SIGNAL '45000'` DB triggers, the `surgical_case_events` recipe) — one immutable row for
  ARRIVAL + one per transition. **THE TRIAGE-ACUITY SEAM (empty — the FENCE crux):**
  `Contracts\TriageAcuityProvider` (`suggestAcuity(AcuityContext): AcuityResult`) bound in
  `EDServiceProvider::register()` to `NullTriageAcuityProvider` (returns `AcuityResult::none()`), MIRRORING
  Pharmacy's `MedicationSafetyProvider` → `Null*` ([[D-120]]) and Clinical's `LabConnectivity` → `Manual*`
  (referenced by NAME — ED imports NO peer vertical). **CareOS builds the SEAM, NOT the logic.** A COMPUTED
  triage acuity (vitals+complaint → the ESI/Manchester/CTAS level) IS performing triage — a regulated MEDICAL
  DEVICE, the electric-fence line (`AGENTS.md:36-39`), literally eval-locked (`ClinicalAgentsEvalTest.php:273`
  refuses `triage`), and a **PERMANENT homemade non-goal**; the nurse-ASSIGNED acuity (a recorded fact, the
  `Stay::admission_type`/ASA precedent) is the buildable version, recorded in a separate triage record in G2 —
  NOT on the `EdVisit` (no acuity/triage/priority/severity/score column). The seam swap is proven resolvable (a
  future certified partner binds in place, no consumer change; advisory + human-owned, never auto-assigning/
  auto-prioritising). **`EdVisitService`:** `register` + `transition`, gate `ed.manage`, tenant fail-closed,
  DB::transaction, append-only events. **RBAC (additive):** perms `ed.manage` + `triage.record` (used G2);
  roles `ed_physician` (+ `admission.manage` for the G5 ED→ADT admit), `triage_nurse`, `ed_charge_nurse`;
  org_admin gains both; reprovision migration `add_ed_permissions` (the `add_surgery_permissions` precedent);
  `RbacNegativeSweepTest` untouched. **Audit app-layer** (`EdVisit.created`→`ed_visit.registered`;
  `EdVisitEvent.created`→`ed_visit.<event_type>`; patient-scoped), so ED stays free of Audit. **Arch:**
  `arch('ED …')` — ED may use Platform+care modules+Audit SERVICES, never Audit models/AiCore/Comms/peer
  verticals; the ED→ADT link (G5) is a soft app-layer `stay_id`. No triage/board/docs/disposition-handoff/
  billing this gate (G2–G6). No existing behavior test modified; arch/RBAC/clinical-safety-eval stay green.
  `tests/Feature/ED/EdVisitTest.php` (10). See `docs/HOSPITAL-PHASE6-ED-MAP.md`, [[ED]], and the Phase-6 map
  in [[LOG]].

- **D-131 — ED.G2: triage — the nurse-ASSIGNED acuity + presenting complaint + raw vitals (assigned-not-
  computed; the seam stays empty).** Per `docs/HOSPITAL-PHASE6-ED-MAP.md` §2.4/§3. `ed_triages`
  (BelongsToTenant, LogsReads, **APPEND-ONLY** — model guards + DB triggers, the `ed_visit_events` recipe): the
  triage assessment for an `EdVisit` — `triaged_by` (the nurse's StaffProfile, provenance), `triaged_at`,
  `presenting_complaint`, `acuity_scale` (ESI/MANCHESTER/CTAS, provenance) + `acuity_level` (**the value the
  NURSE ASSIGNED**). A re-triage is a new row (history preserved). **THE FENCE (the vertical's crux —
  assigned-not-computed):** `acuity_level` is a value the nurse SELECTS applying a protocol with their own
  judgment — a RECORDED FACT, exactly like `SurgicalCase::asa_class` ([[D-127]]/[[Surgery]]),
  `Stay::admission_type` ([[Hospital]]), and the `Incident.severity` reporter-selected precedent. The system
  does NOT compute/suggest/rank it; `isValidAssignment(scale,level)` is pure data-entry validation (a valid
  level for the scale), never a grade. NO suggested/computed/score/severity/priority/deterioration column.
  **The seam, threaded + empty:** `TriageService::acuitySuggestion()` calls the G1 [[ED]] `TriageAcuityProvider`
  → `AcuityResult::none()` today (the null-object, [[D-130]]); read-side advisory only (the UI's "no automated
  suggestion" area), NEVER auto-assigning. Recording never touches the seam — the nurse's value is stored. No
  homemade acuity-computation (the grep over `Modules\ED\src` is clean). **Raw vitals reuse:** optional RAW
  vitals via the EXISTING `ClinicalListService::recordVital` (patient-scoped, encounter-less at triage; no
  bands/flags/scores — the electric fence, unchanged; needs `note.write`). **Flow:** recording moves the visit
  `arrived → triaged` (the G1 legal transition, only from `arrived`; a re-triage keeps the status) in one
  `DB::transaction`. **RBAC:** recording gated `triage.record` (the triage nurse; an ED physician with
  `ed.manage` but not `triage.record` is refused); viewing gated `patient.view` (read-logged). **Audit
  app-layer:** `EdTriage.created`→`ed_triage.recorded` (patient-scoped). **UI (P0D.GU):** `EdTriageController`
  (`/ed/visits/{visit}/triage`, string-id FIX.1) + `ED/Triage.vue` (the form + the empty seam suggestion area +
  the append-only history + raw vitals); `ed.*` i18n; FIX.5 smoke extended. No existing behavior test modified;
  the clinical-safety/triage eval + G1 + reconciliation/fence/immutability + all vertical suites stay green; no
  charge. `tests/Feature/ED/EdTriageTest.php` (7). See `docs/HOSPITAL-PHASE6-ED-MAP.md`, [[ED]], [[LOG]].

- **D-132 — ED.G3: the ED tracking board — operational flow facts + the RECORDED acuity; NO computed priority
  ranking.** Per `docs/HOSPITAL-PHASE6-ED-MAP.md` §2.2/§3. The live cockpit of active ED visits, **reusing the
  ward-board idiom** ([[Hospital]] HOSPITAL.G3 — a status board over a flow entity) over the `EdVisit` flow
  state. `EdVisitService::activeVisits()` reads the non-terminal visits (arrived/triaged/in_treatment/
  awaiting_disposition) with `patient`+`branch`+`latestTriage` (new `EdVisit::latestTriage()` HasOne
  `latestOfMany('triaged_at')`), ordered by ARRIVAL (a fact). `EdBoardController::index` (gate `ed.manage`)
  renders `ED/Board`: per visit the patient, flow `status`, the RECORDED acuity (the G2 nurse-assigned value +
  scale + provenance; null until triaged), presenting complaint, `arrived_at` (elapsed = client-side plain
  fact), `available_transitions` (the FIXED legal-state map, not a suggestion); plain department counts.
  **THE FENCE (the crux):** the board shows OPERATIONAL FACTS + the RECORDED acuity ONLY. Staff MAY **sort by
  the recorded acuity** (client-side, on the nurse's assigned value — ordering by a recorded FIELD, a fact) or
  by arrival — but there is NO computed priority ranking, NO acuity-driven "who to see next" judgment, NO
  wait-time-risk, NO deterioration. This is the map's §2.2 line: sorting-by-a-recorded-fact is fine; a
  system-COMPUTED priority is a judgment (not fine). Proven: the payload carries `acuity`+provenance but
  `->missing` priority/rank/score/severity/deterioration/wait_risk; the server orders by `arrived_at`; the
  grep over `EdBoardController` finds no priority/ranking computation. **Actions through the EXISTING service:**
  the flow action POSTs to `EdBoardController::transition` → `EdVisitService::transition` (the G1 legal-only
  machine; `dispositioned` excluded — G5); NO new flow logic. UI (P0D.GU): `ED/Board.vue` (reuses the
  ward-board tile/status idiom; flow-colour = operational state, never severity); `ed.board.*` i18n; FIX.5
  smoke extended (`/ed/board` — org_admin 200, reception 403). No existing behavior test modified; the
  clinical-safety/triage eval + G1/G2 + reconciliation/fence/immutability + all vertical suites stay green; no
  charge. `tests/Feature/ED/EdBoardTest.php` (5). See `docs/HOSPITAL-PHASE6-ED-MAP.md`, [[ED]], [[LOG]].

- **D-133 — ED.G4: ED clinical documentation — reuses Clinical; Encounter UNMODIFIED; the fence carries
  through.** Per `docs/HOSPITAL-PHASE6-ED-MAP.md` §2. REUSE-heavy, not new clinical domain — the inpatient
  bedside-chart ([[Hospital]] HOSPITAL.G4) / surgery op-note ([[Surgery]] SURGERY.G3) pattern. **The linkage:**
  an ED treatment encounter is a **reused Clinical `Encounter`** (`TYPE_CONSULTATION` — NO ED type added to
  Clinical), tied to the visit by an ED-side **`ed_visit_encounters`** link (`EdVisitEncounter`, the
  `ward_rounds`/`surgical_case_encounters` precedent), so Clinical's schema + its one-open-per-practitioner
  invariant stay untouched (proven: `encounters` has no `ed_visit_id` column). `EdDocumentationService` mirrors
  `BedsideChartService`: `startEncounter` (open via `EncounterService::open` — invariant enforced UNCHANGED;
  link ED-side; draft a sign-and-lock `ClinicalNote`), `recordVital` (raw `Vital` via
  `ClinicalListService::recordVital`, tied to the visit's latest encounter), `placeOrder` (structured `Order`
  via `OrderService::place`), and `vitalsForVisit` (the ONLY new affordance — raw vitals by the visit's
  encounter_ids via `VitalsSeries`, no bands/flags/scores; the `forStay`/`forCase` precedent). **The invariant
  HOLDS (tested):** the treatment encounter is kept OPEN; a SECOND concurrent `startEncounter` for the same
  patient+practitioner is REFUSED (`InvalidArgumentException`). The note runs the EXISTING
  write→sign→read-only→amend→version flow unchanged. **FENCE (carries through):** raw vitals only, sign-and-lock
  notes unchanged, NO computed acuity/severity/deterioration/early-warning score — the record payload carries
  raw vitals + note status but `->missing` acuity/severity/score/deterioration/early_warning; the recorded
  triage acuity is G2's nurse-ASSIGNED value (on the triage page), nothing computed here. **RBAC (reused
  clinical gates):** `encounter.manage`/`note.write`/`note.sign`/`order.manage` (the ED physician holds all;
  reception with patient.view but no encounter.manage is refused by the reused EncounterService); read =
  `patient.view` (read-logged). Tenant+branch scoped; cross-tenant fail-closed. UI (P0D.GU):
  `EdDocumentationController` (`/ed/visits/{visit}/record`; start-encounter → redirects into the EXISTING note
  editor) + `ED/Documentation.vue` (reuses the bedside-chart idiom); `ed.record.*` i18n; FIX.5 smoke extended.
  No existing behavior test modified; Clinical's suite + the Encounter invariant + the clinical-safety/triage
  eval + G1–G3 + all vertical suites stay green; no charge. `tests/Feature/ED/EdDocumentationTest.php` (6). See
  `docs/HOSPITAL-PHASE6-ED-MAP.md`, [[ED]], [[LOG]].

- **D-134 — ED.G5: disposition + the ED→ADT handoff — admit reuses AdmissionService → an inpatient Stay;
  atomic.** Per `docs/HOSPITAL-PHASE6-ED-MAP.md` §2.3 — THE SIGNATURE REUSE of Phase 6. Closing an ED visit is
  the clinician's recorded DECISION (admit / discharge / transfer) via the G1 legal transition
  `awaiting_disposition → dispositioned` (append-only, audited, gated `ed.manage`). Additive: a SOFT nullable
  `stay_id` on `ed_visits` (no FK/relation — the [[Surgery]] `stay_id` precedent) + `EdVisitService::transition`
  gained an optional `?stayId`. **THE HANDOFF (admit REUSES the EXISTING AdmissionService):** an APP-LAYER
  composer `app/Services/EdDispositionService` (in app/, NOT Modules\ED — it composes TWO verticals: the ED
  flow + Hospital admission; the arch boundary keeps Modules\ED independent of [[Hospital]]; the app-layer
  composition precedent, AGENTS.md:93-96). `admit()`: gate `admission.manage`, then in ONE `DB::transaction` →
  `AdmissionService::admit($patient, $bed, $clinician, Stay::TYPE_EMERGENCY, …)` → `EdVisitService::transition
  (…, dispositioned, admit, $stay->id)`. **Admission is REUSED, never reimplemented or modified** — the proven
  concurrency-safe bed claim + one-active-stay guard + atomic admit all apply UNCHANGED; the ED visit links to
  the resulting Stay (episode traceable ED→inpatient). discharge/transfer close the visit with the disposition,
  NO Stay. **ATOMIC (tested):** the admit + the disposition are ONE transaction — a forced failure rolls back
  BOTH (admitting an `arrived` visit creates the Stay then FAILS at the illegal transition → the whole tx rolls
  back → no Stay, bed free, visit unchanged). **Reused bed-safety (tested):** admitting a 2nd patient to an
  occupied bed throws `BedNotAvailableException` → rolls back (only 1 Stay) — the G1/G2 guarantee, reused.
  **RBAC:** ADMIT requires `admission.manage` (an ed.manage-only user is refused before any write);
  discharge/transfer = `ed.manage`. **THE FENCE:** the disposition is the clinician's RECORDED decision — the
  system computes/suggests NOTHING (no admit-probability/discharge-risk/suggested-disposition; nothing
  auto-decides — a fresh awaiting_disposition visit has `disposition=null`; the `ed_visits` schema carries no
  such column). UI (P0D.GU): app-layer `EdDispositionController` (`/ed/visits/{visit}/disposition`; ADMIT
  bed/clinician picker → the existing admission flow) + `ED/Disposition.vue` (shows the decision + the linked
  Stay); the board links to it; `ed.disposition.*` i18n; FIX.5 smoke extended. Also caught: the controller must
  catch `BedNotAvailableException` (a `RuntimeException`, not an `AdmissionException`) so an occupied-bed admit
  returns a clean error, not a 500 (the Hospital AdmissionController precedent). No existing behavior test
  modified; the AdmissionService's own tests + Encounter invariant + the clinical-safety/triage eval + G1–G4 +
  all vertical (incl. Phase-1 Hospital) suites stay green; no charge. `tests/Feature/ED/EdDispositionTest.php`
  (7). See `docs/HOSPITAL-PHASE6-ED-MAP.md`, [[ED]], [[LOG]].

- **D-135 — ED.G6: ED billing — visit/service charges via the existing engine (reconciles-to-the-unit; the
  composite emergency→inpatient episode); Phase 6 (ED) COMPLETE.** Per `docs/HOSPITAL-PHASE6-ED-MAP.md` §2.5.
  STRICTLY ORCHESTRATION — NO new billing/pricing/VAT/line-total math (the [[Surgery]] G5 [[D-129]] / bed-day
  [[Hospital]] G6 [[D-118]] shape, mirrored). An `ed` `TariffCatalog` holds each billable as a `TariffItem`
  (integer minor units, NO licensed pricing) — the ED attendance fee (`ED-ATTENDANCE`) + each ED service.
  Charge capture = the EXISTING `ChargeCaptureService::captureManual` (`chargeVisit`, gate `billing.manage`) —
  the ENGINE resolves the tariff by code, SNAPSHOTS the fee, computes the line total; ED does no money math;
  idempotent via `ed_visit_charges`. **RECONCILES-TO-THE-UNIT (the key proofs):** (a) a DISCHARGED patient's
  `invoiceVisit` (validate → gather → draft → issue) → `ReconciliationEngine::check` passes + I4 δ=0; (b) THE
  COMPOSITE EPISODE — an ADMITTED patient's ED charges are patient charges with a service_date in the stay
  window, so Hospital's `BedBillingService::invoiceStay` sweeps them onto the stay's discharge invoice
  ALONGSIDE the bed-days (ED never imports Hospital — the shared engine) → I4 δ=0 with ED-attendance +
  BED-DAY-GENERAL on ONE invoice (the whole emergency→inpatient episode bills as one reconciling invoice).
  **RBAC:** `billing.manage` (the billing office, NOT the ED team — an ed_physician is refused). **FENCE:** a
  price is a RATE, never a clinical verdict — the ED-attendance fee is a plain tariff, NOT acuity-driven
  (proven: ESI 1 vs ESI 5 → the same fee); fees snapshotted; the money-math grep over `Modules\ED\src` + the
  app-layer composer is CLEAN; `ed_visit_charges` carries no money/acuity column. UI (P0D.GU):
  `EdBillingController` (`/ed/visits/{visit}/billing`) + `ED/Billing.vue`; the disposition page links to it;
  `ed.billing.*` i18n; FIX.5 smoke extended. No existing behavior test modified; the reconciliation/fence/
  immutability/clinical-safety/triage-eval + G1–G5 + Phase-1/2/5 suites stay green; the engine is REUSED, not
  changed. `tests/Feature/ED/EdBillingTest.php` (7). **PHASE 6 (EMERGENCY DEPARTMENT) COMPLETE** — G1 EdVisit
  → G2 triage → G3 board → G4 docs → G5 disposition/ADT-handoff → G6 billing; an ED runs end-to-end incl. the
  emergency→inpatient episode on one reconciling invoice. Computed triage acuity stays a certified-partner /
  permanent non-goal (the empty `TriageAcuityProvider`). Remaining hospital phases: Lab (Phase 3) + Radiology
  (Phase 4) — mostly integration, pending HL7/FHIR + PACS/DICOM partners. See
  `docs/HOSPITAL-PHASE6-ED-MAP.md`, [[ED]], [[LOG]].

- **D-136 — LAB.G1: lab module + tenant-authored test catalog + the formalized `LabConnectivity` seam + lab
  RBAC — the Phase-3 (Laboratory / LIS) FOUNDATION.** Per `docs/HOSPITAL-PHASE3-LAB-MAP.md`. **THE BIG REUSE
  (the map's core):** Lab is ~85% reuse — a lab order IS a Clinical `Order` (lifecycle already
  `ordered→collected→in_progress→resulted→reviewed`), a lab result IS an append-only raw `OrderResult` (NO
  interpretation column — the fence already built; `source` splits manual/imported), and the `LabConnectivity`
  seam already lives in Clinical + is already wired into `OrderService::place`. **Lab REUSES these, it does NOT
  duplicate them** (the map's sharpest risk). Peer `Modules\Lab` (mirrors ED/Surgery/Pharmacy; `arch('Lab …')`
  excludes Audit models/AiCore/Comms/peer verticals; MAY use Clinical heavily). **The test catalog is an
  overlay:** `lab_tests` (`unique(orderable_item_id)`) overlays the EXISTING Clinical `OrderableItem` (the
  `DentalProcedure`/`SurgicalItem` precedent) — a lab test IS a tenant-authored `OrderableItem` (`category=lab`)
  + the overlay adding ONLY the DISPLAY reference data `unit` + `reference_range`. `LabCatalogService`
  (`authorTest`/`deactivate`/`seedStarter`[a SMALL GENERIC editable template, **NO licensed LOINC/test set
  bundled**]/`catalog`), gate `lab.catalog`. **The seam is FORMALIZED, not filled (docblocks only — no behavior
  change):** documented the MANUAL path (a human enters `OrderResult` source=manual) + the IMPORTED path (a
  future CERTIFIED HL7/analyzer partner implements `ingestResult` → appends `OrderResult` source=imported,
  **never interpreted** — P0P.G11). **Lab CONSUMES the Clinical seam; binds no new one; builds NO homemade HL7
  client** (the defining LIS value is partner-gated — LAB.G7, SEAM-STUBBED, NOT built; the `MedicationSafety`/
  `TriageAcuity` precedent). **FENCE:** `reference_range` is RECORDED reference data (displayed) — the system
  computes NO abnormal/high/low/critical flag or grade (the vitals-bands line); `lab_tests` carries no judgment
  column; the grep over `Modules\Lab\src` finds no grade/flag logic. **RBAC (additive):** perms `lab.catalog` +
  `lab.result` (ordering reuses `order.manage`); roles `lab_tech`/`pathologist`(lab lead)/`phlebotomist`;
  org_admin gains both; reprovision migration `add_lab_permissions`. **Audit app-layer:** `LabTest.created`→
  `lab.test_authored` (tenant-level). UI (P0D.GU): `LabCatalogController` (`/lab/catalog`) + `Lab/Catalog.vue`;
  `lab.catalog.*` i18n; FIX.5 smoke extended. No order/specimen/result this gate (G2–G6). No existing behavior
  test modified; the clinical `OrderTest` + reconciliation/fence/immutability/clinical-safety/triage-eval + all
  vertical suites stay green (Clinical's Order/OrderResult/seam reused, only docblocks touched).
  `tests/Feature/Lab/LabCatalogTest.php` (5). **HONEST NOTE:** without the HL7/analyzer partner the lab
  vertical is a manual record-keeping shell — the defining value is partner-gated. See
  `docs/HOSPITAL-PHASE3-LAB-MAP.md`, [[Lab]], [[LOG]].

- **D-137 — LAB.G2: lab order entry — reuses the Clinical `Order`; a thin specimen+priority overlay; priority
  is recorded, not computed.** Per `docs/HOSPITAL-PHASE3-LAB-MAP.md` §2.4. REUSE-first — **a lab order IS a
  Clinical `Order` (~85% reuse); Clinical is NOT duplicated or modified.** `LabOrderService::place` REUSES the
  EXISTING `OrderService::place` (authorizes `order.manage`, runs the `ordered→collected→in_progress→resulted→
  reviewed` lifecycle, calls the `LabConnectivity` seam's `transmit()` — manual no-op) with the LAB.G1
  `LabTest`'s `OrderableItem`, then appends the thin **`lab_orders`** overlay (the only net-new): `specimen_type`
  (defaults from the catalog) + `priority` (routine/urgent/**STAT**), in one `DB::transaction`. Ties to the
  patient + an optional `Encounter` (the existing linkage — inpatient round / ED-visit encounter). `lab_orders`
  is APPEND-ONLY (model guards + DB triggers, `unique(order_id)`; the `order_results` recipe). **FENCE:** the
  priority is a RECORDED flag the clinician SETS (the ED-acuity / `Stay.admission_type` precedent) — the system
  computes NO priority, ranks NOTHING by urgency, auto-escalates NOTHING (no urgency-score/computed-priority/
  rank column; grep clean). **STAT is overlay-only — Clinical's `Order` is UNTOUCHED** (its priority stays the
  default routine; `orders` schema unchanged). The seam's `transmit()` stays the manual no-op (no homemade
  HL7). **RBAC:** placing reuses the EXISTING `order.manage` (reception refused by the reused service); viewing
  = `patient.view` (read-logged). **Audit app-layer:** `LabOrder.created`→`lab.order_placed` (patient-scoped);
  the Order audited by Clinical's `order.placed`. UI (P0D.GU): `LabOrderController` (`/lab/patients/{patient}/
  orders`) + `Lab/Orders.vue`; `lab.orders.*` i18n; FIX.5 smoke extended. No specimen/result this gate (G3/G4).
  No existing behavior test modified; the clinical `OrderTest` + reconciliation/fence/immutability/
  clinical-safety/triage-eval + LAB.G1 + all vertical suites stay green (Clinical reused, untouched); no charge.
  `tests/Feature/Lab/LabOrderTest.php` (6). See `docs/HOSPITAL-PHASE3-LAB-MAP.md`, [[Lab]], [[LOG]].

- **D-138 — LAB.G3: specimen tracking — the one genuine net-new lab entity (accession + legal-only state
  machine; append-only).** Per `docs/HOSPITAL-PHASE3-LAB-MAP.md` §2.2 (a Clinical `Order.status=collected` is a
  status, NOT a specimen). `specimens` (BelongsToTenant, LogsReads): collected against a LAB.G2 `LabOrder`
  (`lab_order_id` → the reused Clinical Order), `accession_number` (unique-per-tenant, `unique(tenant,
  accession)`), `specimen_type` (from the order overlay), collected_by/at, `status` (out of `$fillable`).
  **Legal-only state machine** collected → in_lab → resulted (+ rejected, reason required); illegal throws.
  `specimen_events` (**APPEND-ONLY** — model guards + DB triggers, the `ed_visit_events` recipe). `SpecimenService`:
  `collect` (gate `lab.result`; generate accession + specimen + `collected` event, atomically) + `transition`
  (legal-only) + reads. **Accession generation** mirrors `MrnGenerator` ([[Patients]]) — tenant-row lock,
  `sprintf('ACC-%06d')`, unique (proven sequential + per-tenant). **THE CLINICAL ORDER IS REUSED + UNTOUCHED:**
  collection records the SPECIMEN; it does NOT advance the Order (that stays Clinical's — the result step G4
  moves it via `OrderService`, which is `order.manage`-gated; the **phlebotomist holds only `lab.result`** so
  collection is decoupled — the Order stays `ordered` after collection). **FENCE (operational):** state +
  accession are FACTS — no computed priority/urgency/routing (no such column on `specimens`; grep clean); the
  STAT flag stays the LAB.G2 recorded flag. **RBAC:** collect + transition = `lab.result` (phlebotomist/lab
  tech; reception refused); viewing = `patient.view` (read-logged). **Audit app-layer:** `Specimen.created`→
  `specimen.accessioned`; `SpecimenEvent.created`→`specimen.<event_type>` (patient-scoped). UI (P0D.GU):
  `SpecimenController` (`/lab/orders/{labOrder}/specimens`; `/lab/specimens/{specimen}/transition`) +
  `Lab/Specimens.vue`; `lab.specimens.*` i18n; FIX.5 smoke extended. No result/routing/billing this gate
  (G4–G6). No existing behavior test modified; the reconciliation/fence/immutability/clinical-safety/triage-eval
  + LAB.G1/G2 + all vertical suites stay green (Clinical reused/untouched); no charge.
  `tests/Feature/Lab/SpecimenTest.php` (7). See `docs/HOSPITAL-PHASE3-LAB-MAP.md`, [[Lab]], [[LOG]].
- **D-139 — LAB.G4: manual result entry + reference-range display — THE FENCE GATE (reuses `OrderResult`;
  the range is a displayed fact, no computed abnormal flag).** Per `docs/HOSPITAL-PHASE3-LAB-MAP.md` §2.5/§4.
  A lab result IS a Clinical `OrderResult` — `LabResultService::record` (gate `lab.result`; the reused path
  also re-checks `order.manage`) REUSES `OrderService::recordResult` (append-only, RAW, `source=manual`;
  advances the reused `Order` → resulted), appends the thin **`lab_results`** overlay (append-only,
  `unique(order_result_id)` — the ONLY net-new; links the reused result to the LAB.G3 specimen that produced
  it, carries NO value), and walks the specimen → resulted through the G3 legal machine (collected → in_lab →
  resulted). **THE FENCE (the sharpest in lab):** the reference range (`unit`+`reference_range` from the LAB.G1
  catalog) is DISPLAYED reference data beside the raw value — the system computes NO abnormal/high/low/critical
  flag, NO delta-check, NO interpretation (the vitals-bands line; `OrderResult` already has no interpretation
  column, `lab_results` adds none). Proven: an out-of-range value carries no flag; the Inertia payload key-sweep
  finds no computed-judgment key; no such column on `order_results`/`lab_results`; no grade/flag logic in
  `Modules\Lab\src`. The `LabConnectivity` seam stays the manual no-op (no homemade HL7 — that is LAB.G7). RBAC:
  the `lab_tech`/`pathologist`/`org_admin` (both perms) record; a `phlebotomist` (lab.result only) is refused at
  the reused Clinical path; reception refused; cross-tenant fail-closed. AUDIT (app-layer):
  `LabResult.created`→`lab.result_recorded` (patient-scoped); the OrderResult audited by Clinical's
  `order.resulted`. UI (P0D.GU): `LabResultController` (`/lab/orders/{labOrder}/results` view ·
  `/lab/specimens/{specimen}/results` store) + `Lab/Results.vue`; `lab.results.*` i18n; FIX.5 smoke extended
  (GET 200 + store 403). No result routing/billing this gate (G5/G6). No existing behavior test modified; the
  reconciliation/fence/immutability/clinical-safety/triage-eval + LAB.G1–G3 + all vertical suites stay green
  (Clinical's OrderResult/OrderService/seam reused/untouched); no charge.
  `tests/Feature/Lab/LabResultTest.php` (7). See `docs/HOSPITAL-PHASE3-LAB-MAP.md`, [[Lab]], [[LOG]].
- **D-140 — LAB.G5: result routing + the "results to review" worklist (reuses the OrderService review flow;
  facts, not computed prioritization).** Per `docs/HOSPITAL-PHASE3-LAB-MAP.md`. Closes the order → result →
  review loop by SURFACING the EXISTING `resulted → reviewed` step for lab orders — the review flow is REUSED,
  NOT reinvented. `LabResultService::reviewWorklist(actor)` (gate `order.manage` — the review permission)
  returns the ORDERING clinician's own resulted-but-not-reviewed lab orders (the reused Clinical `Order` at
  `resulted`, `ordered_by`=actor), ordered by resulted-time (the latest `OrderResult.entered_at` — a FACT,
  newest first). `LabReviewController` (invokable, `/lab/results/review`, the `OrdersReviewController` analogue
  lab-scoped) renders each row with the raw result value + the DISPLAYED reference range (LAB.G4), the recorded
  STAT flag, and the resulted-time; the review action POSTs `order_id` to the EXISTING
  `clinical.orders.review` endpoint (`OrderController::review` → `OrderService::markReviewed`) — **NO new
  review endpoint/service/model/migration**. **THE FENCE:** the worklist shows facts + the LAB.G2 recorded STAT
  flag (staff MAY sort by flag/time client-side — a recorded fact, the ED-board precedent); the system computes
  NO priority/urgency ranking, NO critical-result flag, NO review-first judgment (proven: a later-resulted
  routine order outranks an earlier-resulted STAT one — the server orders by resulted-time, not STAT; the
  Inertia payload key-sweep finds no computed-judgment key; no rank/priority-score/flag-critical logic in
  `Modules\Lab\src`); the result stays raw value + displayed range (G4's fence carried, no computed abnormal
  flag). RBAC: `order.manage` (the doctor reaches it; reception refused — service + HTTP); tenant+patient
  scoped (a resulted order in one tenant is invisible in another's worklist). No audit hook added (the review
  is audited by Clinical's `order.reviewed`). UI (P0D.GU): `Lab/Review.vue`; `lab.review.*` i18n; FIX.5 smoke
  extended (`/lab/results/review` GET 200 doctor / 403 reception). No billing this gate (G6). No existing
  behavior test modified; the reconciliation/fence/immutability/clinical-safety/triage-eval + LAB.G1–G4 + all
  vertical suites stay green (Clinical's review flow reused/untouched); no charge.
  `tests/Feature/Lab/LabReviewTest.php` (6). See `docs/HOSPITAL-PHASE3-LAB-MAP.md`, [[Lab]], [[LOG]].
- **D-141 — LAB.G6: lab billing — a lab order accrues its test fee through the EXISTING engine, reconciling-to-
  the-unit. The FINAL buildable Phase-3 gate; the LAB core is COMPLETE.** Per `docs/HOSPITAL-PHASE3-LAB-MAP.md`.
  STRICTLY ORCHESTRATION — Lab adds NO pricing/charge/VAT/line-total math (the ED.G6 / surgery-G5 / bed-day
  pattern; the adversarial grep over `Modules\Lab\src` finds zero money math). A lab test is a tenant-authored
  `TariffItem` in the `lab` `TariffCatalog` (keyed by the LAB.G1 catalog code, integer minor units, NO licensed
  pricing). `LabBillingService` (`priceTest`/`chargeOrder`/`invoiceOrder`/`catalogTariffs`, gate
  `billing.manage`) captures ONE charge per lab order via the EXISTING `ChargeCaptureService::captureManual`
  (the engine resolves + SNAPSHOTS the fee + computes `line_total = qty × unit_price`); idempotent via the
  `lab_order_charges` link (soft `charge_id` ref, NO money stored). Outpatient issues via the existing
  `validateForPatientPeriod` → `createDraftFromCharges` → `issue`; an inpatient/ED patient's lab charges instead
  join the stay/episode's discharge invoice via the existing `BedBillingService::invoiceStay` (same
  gather-by-patient+period — no lab code). Service date = the resulted-time (a fact) else the order date.
  `LabBillingController` (`/lab/orders/{labOrder}/billing` + price-test/charge/invoice) + `Lab/Billing.vue` +
  `lab.billing.*` i18n; FIX.5 smoke extended (GET 200 + charge 403). No audit hook (the Charge/Invoice are
  audited by Billing — the `EdVisitCharge`/`SurgicalCaseCharge`/`DispenseCharge` precedent). **RECONCILES-TO-
  THE-UNIT proven BOTH ways:** an outpatient invoice with a lab charge (δ=0, six invariants); AND a composite
  inpatient episode — the lab charge + bed-days swept onto ONE stay invoice by `invoiceStay` (δ=0). **THE
  FENCE:** the lab fee is a plain tariff, NOT driven by the result value/abnormality (two opposite result values
  → the SAME fee); the result is a clinical record, the fee is a rate — kept separate; `lab_order_charges`
  carries no money/result/severity column. RBAC: `billing.manage` (the billing office, NOT the lab bench — a
  `lab_tech` with order.manage+lab.result is refused); tenant scoped, fail-closed. No existing behavior test
  modified; the reconciliation/fence/immutability/clinical-safety/triage-eval + LAB.G1–G5 + all vertical suites
  stay green (the billing engine is REUSED, not changed). `tests/Feature/Lab/LabBillingTest.php` (7). **LAB CORE
  COMPLETE (Phase 3):** G1 catalog+seam → G2 order → G3 specimen → G4 result+range → G5 review → G6 billing — a
  lab runs end-to-end as a manual record-keeping shell. THE ONE DELIBERATE GAP: LAB.G7 (the HL7/FHIR/analyzer
  feed) stays the CERTIFIED-PARTNER `LabConnectivity` seam (manual today; imported-via-partner later, never
  interpreted); homemade HL7 = not built. Radiology (Phase 4) remains, also partner-gated (PACS/DICOM). See
  `docs/HOSPITAL-PHASE3-LAB-MAP.md`, [[Lab]], [[LOG]].
- **D-142 — RAD.G1: radiology module + tenant-authored exam catalog + the CREATED `ImagingConnectivity` seam
  (null-object) + radiology RBAC (Phase 4 foundation).** Per `docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md`. A peer
  `Modules\Radiology` (mirrors Lab/ED), registered in `bootstrap/providers.php` + composer autoload; arch rule
  `arch('Radiology …')` — may use Platform + care modules (Clinical/Patients/Billing) + Audit SERVICES, never
  Audit models/AiCore/Comms/peer verticals. **Radiology REUSES Clinical's `Order`/`ClinicalNote`/`Document` — it
  does NOT duplicate them** (the map's sharpest risk). **Exam catalog (overlay):** `radiology_exams`
  (BelongsToTenant, `unique(orderable_item_id)`) overlays the EXISTING Clinical `OrderableItem` — an imaging
  exam IS a tenant-authored `OrderableItem` (`category='imaging'` + the `specimen_or_modality` field **already
  existed**; code/name/modality live there) + the overlay adding ONLY `body_part` + `contrast`.
  `RadiologyCatalogService::authorExam` (gate `radiology.catalog`, one tx: `OrderableItem::updateOrCreate`
  [category=imaging] + `RadiologyExam::updateOrCreate`) / `deactivate` (soft) / `seedStarter` (a SMALL GENERIC
  editable template — RAD-CXR/AXR/CT-HEAD/CT-ABDO/MRI-BRAIN/US-ABDO, plain names; **NO licensed CPT/RadLex set
  bundled**, tenant-isolated) / `catalog`. `RadiologyCatalogController` (`/radiology/catalog`) +
  `Radiology/Catalog.vue` + `radiology.catalog.*` i18n; audited (app-layer `RadiologyExam.created` →
  `radiology.exam_authored`, tenant-level). **CREATED the `ImagingConnectivity` (PACS/DICOM) seam:** unlike Lab
  (whose `LabConnectivity` already existed), NO imaging seam existed — this gate CREATES
  `Modules\Radiology\Contracts\ImagingConnectivity` (`transmitOrder(Order)` = the future DICOM MWL push /
  `ingestStudy(array)` = a future imported study) + the ONLY impl `NullImagingConnectivity` (transmit no-op;
  ingest THROWS "not available; recorded manually / images uploaded"), bound in
  `RadiologyServiceProvider::register()` — the `LabConnectivity`/`TriageAcuityProvider`/`MedicationSafetyProvider`
  precedent. **NO DICOM/PACS integration, NO diagnostic viewer, NO image storage built** (RAD.G6 is
  SEAM-STUBBED, partner-gated; a homemade DICOM/PACS stack is a PERMANENT non-goal); the seam is swappable for a
  certified partner WITHOUT touching consumers (proven — a partner double resolves via `app()->instance`); the
  imported path is append-never-interpret. **THE FENCE (AI-imaging):** the radiologist AUTHORS the report (G4);
  the system computes NO image finding/CAD/abnormality flag/confidence/auto-read — a HARD medical-device non-goal
  (the DENTAL.G8 "AI radiology = NON-GOAL" line); the seam never interprets; `radiology_exams` carries no
  finding/cad/abnormal/ai/confidence column; the grep over `Modules\Radiology\src` finds no
  computeFinding/cadRead/interpretImage/aiRead logic + no homemade DICOM/PACS client. **RBAC (additive):** perms
  `radiology.catalog` + `radiology.study`; roles `radiographer` (patient.view+order.manage+radiology.study) +
  `radiologist` (+ radiology.catalog + note.write/sign + encounter.manage); ordering reuses `order.manage`, the
  report reuses `note.write`/`note.sign`; `org_admin` gains both; reprovision migration
  `add_radiology_permissions`. Tenant scoped, fail-closed. NO order/study/report/billing UI this gate (G2–G5).
  No existing behavior test modified; the reconciliation/fence/immutability/clinical-safety/triage-eval + all
  vertical (clinic/dental/home-care/inpatient/pharmacy/surgery/ED/lab) suites stay green (additive: a new module
  + the overlay + new perms + the created seam; reusing Clinical). `tests/Feature/Radiology/RadiologyCatalogTest.php`
  (5) + the arch rule. Module memory `memory/modules/Radiology.md`. See `docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md`,
  [[Radiology]], [[LOG]].
- **D-143 — RAD.G2: imaging order entry — reuses the Clinical `Order`; a thin modality/priority overlay;
  priority is recorded not computed.** Per `docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md` §2.4 (the near-exact LAB.G2
  analog). An imaging order IS a Clinical `Order` (~95% reuse) — `RadiologyOrderService::place` REUSES the
  EXISTING `OrderService::place` (authorizes `order.manage`, runs the `ordered→collected→in_progress→resulted→
  reviewed` lifecycle) with the RAD.G1 exam's `OrderableItem`, then appends the thin **`radiology_orders`**
  overlay (the ONLY net-new: `modality` + `body_part` [default from the exam, overridable] + `priority`
  [routine/urgent/**STAT**]) in one `DB::transaction`, then calls the `ImagingConnectivity` seam's
  **`transmitOrder()`** (the future DICOM Modality-Worklist push — the null no-op today; a certified partner
  fills it later WITHOUT any change here). Ties to the patient + an OPTIONAL `Encounter` (the existing linkage —
  inpatient round / ED-visit encounter). `radiology_orders` (BelongsToTenant, LogsReads, **APPEND-ONLY** —
  model guards + `SIGNAL '45000'` DB triggers `radiology_orders_no_update`/`_no_delete`; `unique(order_id)`).
  **FENCE:** the priority is a RECORDED flag the ordering clinician SETS — the system computes NO priority,
  ranks NOTHING by a computed urgency, auto-escalates NOTHING (no urgency-score/computed-priority/rank column;
  the grep over `Modules\Radiology\src` finds no compute/rank-priority logic). **STAT is overlay-only —
  Clinical's `Order` is UNTOUCHED** (its priority stays default routine; Clinical accepts routine/urgent only;
  proven: `orders` schema has no modality/body_part/imaging_priority column). No image yet → NO computed image
  finding/CAD column. The `transmitOrder()` seam stays the null no-op (no homemade DICOM/MWL — no
  DicomClient/PacsClient/parseDicom in the module). **RBAC:** placing reuses the EXISTING `order.manage` (an
  imaging order IS an Order — the clinician orders; reception [no order.manage] is REFUSED by the reused
  `OrderService::place`; gated in the controller too); viewing = `patient.view` (read-logged). **AUDIT
  (app-layer):** `RadiologyOrder.created`→`radiology.order_placed` (patient-scoped); the Order itself audited by
  Clinical's `order.placed`. **UI (P0D.GU):** `RadiologyOrderController` (`/radiology/patients/{patient}/orders`,
  string-id FIX.1 — place + list the patient's imaging orders with the reused-Order lifecycle status) +
  `Radiology/Orders.vue` (exam picker + modality/body-part + priority); `radiology.orders.*` i18n. FIX.5 smoke
  extended (`/radiology/patients/{id}/orders` GET 200; reception place 403). NO study/report/billing UI this
  gate (G3–G5). No existing behavior test modified; the reconciliation/fence/immutability/clinical-safety/
  triage-eval + RAD.G1 + all vertical suites stay green (Clinical's Order/OrderService REUSED, untouched). No
  charge (radiology billing is G5). `tests/Feature/Radiology/RadiologyOrderTest.php` (6). See
  `docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md`, [[Radiology]], [[LOG]].
- **D-144 — RAD.G3: the net-new `ImagingStudy` record (accession + legal-only state machine) + the modality
  worklist (DICOM image path seam-stubbed).** Per `docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md` §2.2 — the one
  genuine net-new radiology build (the lab-`Specimen` analog). **`imaging_studies`** (BelongsToTenant,
  LogsReads): registered against a RAD.G2 `RadiologyOrder` (→ the reused Clinical Order), `accession_number`
  (unique-per-tenant — the `Specimen` recipe: tenant-row lock + `sprintf('IMG-%06d')` + `unique(tenant,
  accession_number)`), `modality` (from the order), `acquired_by`/`acquired_at`, `status` (out of `$fillable`).
  **Legal-only** `ordered → acquired → reported` (+ `cancelled` from a pre-report state, reason required);
  illegal moves throw. **`imaging_study_events`** APPEND-ONLY (model guards + `SIGNAL '45000'` DB triggers, the
  `specimen_events` recipe). `ImagingStudyService`: `register` (create at ordered + accession + `ordered` event)
  / `acquire` (register-if-missing → ordered→acquired, records acquired_by/at) / `transition` (legal-only;
  `reported` reached by the RAD.G4 report step) / `worklist` (imaging orders awaiting acquisition — study null
  or `ordered` — ordered by ordered-time, a fact) / `forOrder` / `forPatient`; gate `radiology.study`. **The
  Clinical Order is REUSED + UNTOUCHED** — acquiring records the study; it does NOT advance the Order (the
  report step G4 does; proven: the Order stays `ordered` after acquire). **THE DICOM IMAGE PATH IS
  SEAM-STUBBED** (RAD.G6, partner-gated) — the study is METADATA; NO DICOM storage/diagnostic viewer/PACS/
  modality integration is built (a homemade DICOM/PACS stack is a permanent non-goal). **The optional uploaded
  still (dental `DocumentService`) is DEFERRED** to a later gate (explicitly permitted — G3's core is the study
  record + worklist). The modality worklist reuses the board/LAB.G5-review idiom (operational facts, no computed
  ranking). **FENCE:** the state + accession + worklist are FACTS — no computed image finding/CAD/abnormality/
  confidence, no computed priority (proven: no such column on `imaging_studies`; no
  computeFinding/detectAbnormality/cadRead/interpretImage/aiRead/computePriority/rankByUrgency logic + no
  DicomClient/PacsClient/parseDicom/DicomViewer in `Modules\Radiology\src`; the worklist is ordered by
  ordered-time, a later STAT order sorts AFTER an earlier routine one). **RBAC:** acquire/transition/worklist =
  `radiology.study` (the radiographer; reception refused); viewing = `patient.view` (read-logged). Tenant +
  patient scoped, cross-tenant fail-closed. **AUDIT (app-layer):** `ImagingStudy.created`→
  `radiology.study_accessioned`; `ImagingStudyEvent.created`→`radiology.study.<event_type>` (patient-scoped).
  **UI (P0D.GU):** `RadiologyWorklistController` (`/radiology/worklist`) + `ImagingStudyController`
  (`/radiology/orders/{radiologyOrder}/study` show/acquire; `/radiology/studies/{study}/transition`) +
  `Radiology/Worklist.vue` + `Radiology/Study.vue` (a labelled image-seam note, NOT a diagnostic viewer);
  `radiology.worklist.*`/`radiology.study.*` i18n. FIX.5 smoke extended (worklist + study GET 200; acquire 403).
  NO report/billing UI this gate (G4/G5). No existing behavior test modified; the reconciliation/fence/
  immutability/clinical-safety/triage-eval + RAD.G1/G2 + all vertical suites stay green (Clinical's Order
  reused/untouched). No charge. `tests/Feature/Radiology/RadiologyStudyTest.php` (8). See
  `docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md`, [[Radiology]], [[LOG]].
- **D-145 — RAD.G4: the radiologist report (reuses sign-and-lock; authored not computed) + report routing —
  THE FENCE GATE.** Per `docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md` §2.5. A report IS a REUSED sign-and-lock Clinical
  `ClinicalNote` (write → sign → read-only → amend → version — the ED op-note / lab-result precedent), authored
  by the radiologist (findings → objective, impression → assessment, as PROSE) on a reused `TYPE_CONSULTATION`
  `Encounter`, tied to the RAD.G3 `ImagingStudy` by a radiology-side **`imaging_study_reports`** link (one
  report-encounter per study; `unique(imaging_study_id)` + `unique(tenant, encounter_id)`; the
  `ed_visit_encounters`/`ward_rounds` precedent). **Clinical is UNMODIFIED** — the `Encounter`/`ClinicalNote`
  schema + sign-and-lock + one-open-per-practitioner invariants are untouched (proven: `clinical_notes`/
  `encounters` carry no radiology column). `RadiologyReportService` (`saveDraft`/`sign`/`amend`/`reportFor`/
  `versionsFor`) composes `EncounterService`+`ClinicalNoteService`+`OrderService`+`ImagingStudyService`:
  **signing files the report** — sign the note (immutable, note.sign) → advance the study → reported (the G3
  legal transition; requires acquired) → advance the reused Clinical `Order` → resulted
  (`OrderService::recordResult` — the report IS the result; the impression as the raw result value,
  source=manual) — atomically. **Report routing REUSES the existing order → review flow** (the resulted Order
  appears in `OrderService::toReview`; `markReviewed` → reviewed) — NOT reinvented (the LAB.G5 posture, via the
  EXISTING `clinical.orders.worklist`/`clinical.orders.review`; proven). **THE FENCE (the sharpest in
  radiology):** the radiologist AUTHORS the report — findings + impression are PROSE the human writes; the
  system computes NO image finding, runs NO CAD, flags NO abnormality, does NO auto-read, computes NO
  confidence, suggests NO diagnosis — a HARD medical-device non-goal (the AGENTS.md / dental-imaging line).
  Proven: nothing auto-populates (an empty draft stays empty); no computed-image-read column on
  `imaging_study_reports`; no computeFinding/detectAbnormality/cadRead/autoRead/interpretImage/suggestDiagnosis/
  confidenceScore/aiRead logic in `Modules\Radiology\src`; the clinical-safety eval stays green. Sign-and-lock
  is reused, not weakened: a signed report is immutable (LogicException on edit); an amendment is a NEW version
  (`supersedes_id` + reason; the original stays immutable). **RBAC:** authoring = `note.write`, signing =
  `note.sign` (the radiologist holds both + `order.manage` + `radiology.study` + `encounter.manage`; reception
  refused); viewing = `patient.view` (read-logged). Tenant + patient scoped, cross-tenant fail-closed. **AUDIT
  (app-layer):** `ImagingStudyReport.created`→`radiology.report_started`; the note (note.signed/amended), the
  Order (order.resulted), and the study (radiology.study.reported) are audited by existing hooks. **UI
  (P0D.GU):** `ImagingReportController` (`/radiology/orders/{radiologyOrder}/report` show/save/sign/amend,
  string-id FIX.1; the acting radiologist's StaffProfile resolved from the user) + `Radiology/Report.vue`
  (findings/impression prose editor; sign → lock; amend → new version; a "route to the review worklist" link) +
  `radiology.report.*` i18n. FIX.5 smoke extended (`/radiology/orders/{id}/report` GET 200; reception save 403).
  NO billing UI this gate (G5). No existing behavior test modified; ClinicalNote/Encounter's invariant + the
  clinical-safety/triage-eval + RAD.G1–G3 + all vertical suites stay green (Clinical REUSED, untouched). No
  charge. `tests/Feature/Radiology/RadiologyReportTest.php` (7). See `docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md`,
  [[Radiology]], [[LOG]].
- **D-146 — RAD.G5: radiology billing — an imaging order accrues its exam fee through the EXISTING engine,
  reconciling-to-the-unit. The LAST buildable Phase-4 gate; the RADIOLOGY core is COMPLETE.** Per
  `docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md`. STRICTLY ORCHESTRATION — Radiology adds NO pricing/charge/VAT/
  line-total math (the LAB.G6 / ED.G6 pattern; the adversarial grep over `Modules\Radiology\src` finds zero
  money math). An imaging exam is a tenant-authored `TariffItem` in the `radiology` `TariffCatalog` (keyed by
  the RAD.G1 exam code, integer minor units, NO licensed pricing). `RadiologyBillingService`
  (`priceExam`/`chargeOrder`/`invoiceOrder`/`catalogTariffs`, gate `billing.manage`) captures ONE charge per
  imaging order via the EXISTING `ChargeCaptureService::captureManual` (the engine resolves + SNAPSHOTS the fee
  + computes `line_total = qty × unit_price`); idempotent via the `radiology_order_charges` link (soft
  `charge_id` ref, NO money stored). Outpatient issues via `validateForPatientPeriod`→`createDraftFromCharges`→
  `issue`; an inpatient/ED patient's imaging charges instead join the stay/episode's discharge invoice via the
  existing `BedBillingService::invoiceStay` (same gather-by-patient+period — no radiology code). Service date =
  the exam's order date. **RECONCILES-TO-THE-UNIT proven BOTH ways:** an outpatient invoice with an imaging
  charge (δ=0, six invariants); AND a composite inpatient episode — the imaging charge + bed-days on ONE stay
  invoice (δ=0). **THE FENCE:** the exam fee is a plain tariff, NOT driven by the report/finding or any
  modality-severity (two orders for the same exam → the SAME fee; STAT priority doesn't change it); fees
  snapshotted (re-pricing never changes a past charge); `radiology_order_charges` carries no
  money/report/finding/severity column. RBAC: `billing.manage` (the billing office, NOT the radiology bench — a
  `radiographer` with order.manage+radiology.study is refused); tenant scoped, fail-closed. No audit hook (the
  Charge/Invoice audited by Billing — the `LabOrderCharge`/`EdVisitCharge` precedent). UI (P0D.GU):
  `RadiologyBillingController` (`/radiology/orders/{radiologyOrder}/billing` + price-exam/charge/invoice) +
  `Radiology/Billing.vue` + `radiology.billing.*` i18n; FIX.5 smoke extended (GET 200 + charge 403). No existing
  behavior test modified; the reconciliation/fence/immutability/clinical-safety/triage-eval + RAD.G1–G4 + all
  vertical suites stay green (the billing engine REUSED, not changed). `tests/Feature/Radiology/RadiologyBillingTest.php`
  (7). **RADIOLOGY CORE COMPLETE (Phase 4):** G1 catalog+seam → G2 order → G3 study+worklist → G4 report+routing
  → G5 billing — a radiology dept runs end-to-end as an order-form-with-no-image shell. THE ONE DELIBERATE GAP:
  RAD.G6 (the DICOM/PACS feed + diagnostic viewer) stays the CERTIFIED-PARTNER `ImagingConnectivity` seam (null
  today; a partner fills it); AI radiology/CAD = hard non-goal; the optional uploaded still deferred. **After
  Phase 4, EVERY hospital vertical is built** (inpatient/pharmacy/lab/radiology/surgery/ED). See
  `docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md`, [[Radiology]], [[LOG]].
- **D-147 — DemoHospitalSeeder: a coherent, reconciling demo dataset for the six hospital verticals (seed data,
  not a feature).** Resolves the standing "no inpatient/pharmacy/surgery/ED/lab/radiology demo seeder" follow-up
  so the hospital build is runtime-demonstrable + auditable + demoable. SEED DATA ONLY — no new app logic, no
  raw-row inserts (everything built through the REAL services so it reconciles + chain-verifies), no existing
  behavior changed (P0D.GU). **Klinik Bergblick** (CHF, de), idempotent-by-slug, on the `DemoDentalSeeder`
  convention (tenant→`RbacProvisioner` roles→`TenantContext`→`SettingsService`→branch). **20 users — one per
  hospital role**, each `twoFactorEnabled()` (fixed factory secret → Playwright login). **Actor model:**
  org_admin performs the calls (holds every hospital gate — the dental owner-does-everything precedent), while
  role-specific `StaffProfile`s carry the clinical provenance. **THE COMPOSITE EPISODE (showpiece):** one
  patient ED→admit(emergency `Stay`)→bed-days→meds(order+eMAR+dispense)→surgery(theatre+lifecycle+WHO+ASA+
  consumable)→labs→radiology(report)→discharge, whose WHOLE episode bills onto **ONE invoice via `invoiceStay`**
  (13 charges across all six verticals, CHF 5187.20) and **reconciles δ=0**. Plus a 2nd elective inpatient
  (partial pay), a still-admitted transferred patient (live occupancy; DRAFT bed-days excluded from
  reconciliation), an ED-discharged-home (own invoice), standalone outpatient lab + radiology (own invoices) +
  live pending states — 5 gapless invoices. **Period = the CURRENT calendar month** (services stamp `now()`;
  `billing:reconcile` reconciles the current period); admissions back-dated via `forceFill(admitted_at)` (data,
  not the clock — the composite-test pattern) so bed-days accrue in-month. **PROVEN for the tenant:**
  `billing:reconcile`=PASS · `audit:verify-chains`=OK · `ReconciliationEngine::run` all 6 invariants δ=0 (incl.
  I4). **FENCE (proven at schema level):** ed_triages/medication_administrations/order_results/imaging_studies
  carry no severity/score/grade/stage/flag/abnormal/risk column; acuity ASSIGNED, ASA ASSIGNED, report AUTHORED,
  lab values raw beside displayed ranges, WHO checklist RECORDED not enforced; the partner seams stay
  null-objects. NOT wired into `DatabaseSeeder` (matches the three siblings) — run via
  `php artisan db:seed --class=DemoHospitalSeeder`. `tests/Feature/Demo/DemoHospitalSeederTest.php` (3 /
  85 assertions). See [[Hospital]], [[LOG]].
- **D-148 — A11Y.1: patient-360 heading hierarchy + dental chart `<select>` accessible names (presentational
  a11y markup only).** Fixes the two Low findings (U-1, U-2) from the QA re-audit — purely presentational,
  no logic/fence/billing/clinical/RBAC/data/behaviour change, no visual change (P0D.GU). **U-1**
  (`Patients/Show.vue`): the outline had only the `h1` name; added a visually-hidden `sr-only <h2>` per tab
  section (reusing `patients.show.tabs.*`) + promoted two visible sub-titles from `<p>` to `<h3>` (identical
  classes → identical visual under Tailwind's heading reset) → a navigable h1→h2→h3 outline; browser-verified
  no visible heading added. **U-2** (`Dental/Odontogram.vue`): the 6 selects each gained `:aria-label` reusing
  the same existing i18n key as their visible `<span>` label — explicit accessible name, no new strings, no
  visual change. Guard: `resources/js/a11y-markup.test.ts` (source-level Vitest assertions — chosen because
  there is no `@vue/test-utils` mount harness and the Pest UI rule forbids markup assertions; the gate's
  permitted fallback). Scope was EXACTLY U-1+U-2; a broader grid/keyboard/ARIA a11y sweep remains a separate
  noted pass. See [[LOG]].
- **D-149 — WIREFRAME-PARITY PASS: match the visual, never weaken a gate, surface-don't-fabricate (per-page,
  per-part gates).** With the eight verticals built + 3 audits clean + A11Y done + at the DEPLOY stage, a
  page-by-page **wireframe-parity pass** brings each app page up to its designed wireframe. **The loop:** decode
  the self-unpacking "bundler-shell" wireframe HTML → readable HTML in the **gitignored** `resources/prototype/`
  (no app-code change, no commit) → **AUDIT** the live page against it on every axis into a classified diff report
  `docs/wireframe-parity/<PAGE>-DIFF.md` (report-only, one "docs: … (audit only)" commit) → **FIX** per-part as
  `P0D.GU` gates (presentational Vue + server-side rules + behavior tests), **one part = one commit + STOP**, each
  ending with a GATE REPORT + CI-green. **THE HARD DISCIPLINE (the crux):**
  1. **Match the LOCKED/CAPPED visual, but NEVER weaken a real, enforced server gate.** The wireframe often shows a
     softer control than the enforced rule (a suggest-cap, mandatory 2FA, a required reject-reason, an
     AutonomyPolicy ceiling, approve = re-authorise + re-ground + still-pending). The visual is brought to parity;
     the gate is untouched (proven by a behavior test each part).
  2. **Never regress a "correctly-more-real" item.** Where the live page is already MORE real than the wireframe
     (e.g. the full inspectable payload well, a real ceiling label), it stays.
  3. **RBAC is reflect-only** — a control the reviewer's permission doesn't allow is a UX hint (hidden/disabled);
     the server Gate stays authoritative and denies regardless (the cap binds server-side).
  4. **Surface-don't-fabricate / honest-copy / no-faked-control.** Every governance element the page surfaces must
     be REAL — the tool's real declared permission, the real `AutonomyPolicy::effectiveCeiling`, the action's real
     recorded grounding sources, the real enforced approve contract — or an **honest absence** ("No linked sources
     on this action."). No invented permission/ceiling/source, no premature "edit"/stat claim, no control the
     backend can't honour. Copy states only what the server actually does (verified before it's written).
  **Applied (updated at the ARDETAIL.P2 reconciliation):** SIX pages COMPLETE — Admin Settings (SETTINGS.P1–P6,
  `e7cabf0`) · Approval Queue (APPROVAL.P1–P7, `ea0e9b3`) · Branches (BRANCH.P1–P5, `a865a31`) · Agent & Tool Config
  (AGENT.P1–P6, `d0199e3`) · Allergy Alert safe-part (ALLERGY.P1, `46e45d1` — record-display only; computed
  drug-allergy checking is a certified-partner medical-device NON-GOAL, not built) · Billing & AR (BILLAR.P1–P7,
  `aa82ea0`). **AR Account Detail IN PROGRESS** — the Billing & AR drill-in: ARDETAIL.P1 per-account running-balance
  ledger → P2 dunning timeline → P3 hero/Swiss-format/links done (`9c95246`); remaining P4 record-payment · P5
  payment-plan · P6 Betreibung escalation. Remaining decoded pages after it: Appointment Detail · Auth Screens. See
  [[D-150]] (billing reconcile extension + engine reporting) + [[D-151]] (AR account detail + the agent-never-commits-
  money / never-escalates-Betreibung line). `docs/wireframe-parity/*.md`, [[AiCore]], [[Platform]], [[LOG]].

- **D-150 — Billing & AR reconcile-to-the-unit EXTENDED + reporting is engine-computed-and-displayed (BILLAR.P1–P7).**
  The billing parity chain extended the reconcile invariant WITHOUT weakening it: (1) **write-offs + contractual
  adjustments** are now first-class, operator-gated (`billing.manage`), **append-only** signed-minor ledger movements
  (`InvoiceAdjustment` via `AdjustmentService`; a correction is a reversal row, never a mutation) that REDUCE the open
  balance — the `ReconciliationEngine`'s I2 (balance derivation) + I6 (no-orphan) were extended to include them,
  invariant count unchanged at 6, tie-out δ=0 (the agent has NO path to write one). (2) **All AR reporting is an
  engine method that TIES and is DISPLAYED, never page-computed:** `arRollForward` (opening+charges−collections−
  adjustments−write-offs=closing, δ=0 two ways) · `daysSalesOutstanding`/`netCollectionRate`/honest collectible ·
  `arByPayer` (groups tie δ=0 over the real `payer_type`; the finer Swiss taxonomy is a flagged gap, not fabricated) ·
  `chargedVsCollectedTrend` (buckets partition the range δ=0, shared helpers) · `topOverdueAccounts` (rollup ties to
  its invoices + `overdueBalanceMinor`, δ=0) — all `billing.view`, tenant-scoped, integer-minor. **The management-report
  grid + AR detail DISPLAY these; the Vue computes NO money (no client sum/ratio/bucket); the period switcher
  re-parameterizes the ENGINE (server recompute), and the CSV export is of engine figures.** The money fence now reads:
  every displayed figure is a `MetricsService` return (proven by assertInertia: props === the service), all money math
  is in the engine, and it ties δ=0. See [[Billing]], [[Reporting]], `docs/wireframe-parity/BILLING-AR-DIFF.md`, [[LOG]].

- **D-151 — AR Account Detail: read-only over the engine + the real state machine; money movements + Betreibung are
  human-owned, agent-excluded, audited (ARDETAIL.P1–P2 + the remaining plan).** The Billing & AR drill-in
  `/billing/accounts/{account}` DISPLAYS the account over the engine: **P1** a per-account running-balance ledger
  (`MetricsService::accountLedger` — per-invoice amount/paid/balance from the reconciled projection + a running balance
  computed IN THE ENGINE; Σ rows === the account's `outstandingBalanceMinor`, final running === total, δ=0). **P2** the
  dunning timeline (`accountDunning`) — a READ-ONLY display of the REAL state machine: the stage is the persisted
  `max(DunningEvent.level)` (NOT an "if age>N" page label), and per-event fees are the REAL captured fee Charges
  (matched by the policy's level⇒fee_code + the event date), Σ tying to the recorded charges. **THE GOVERNANCE LINE
  (binding for the later gates):** the **agent NEVER commits money** and **NEVER initiates Betreibung/debt-enforcement**
  — record-payment (P4) must go through the guarded `PaymentService` (over-allocation refused; append-only; reconciles);
  the **Betreibung escalation (P6) must be human-operator-only, agent-EXCLUDED by construction, and audited (never an
  auto-escalation path)** — the agent may only DRAFT a reminder through the existing cap/ApprovalQueue path. Payment
  plan (P5) is a wireframe-new model; the real Swiss QR-bill (IBAN/reference) is a flagged backend gap (no homemade
  QR-bill fabricated — the existing invoice PDF is surfaced honestly). See [[Billing]], [[Reporting]],
  `docs/wireframe-parity/AR-ACCOUNT-DETAIL-DIFF.md`, [[LOG]].

- **D-152 — AR Account Detail record-payment: the page WIRES the guarded engine, it does not become a second
  payment path (ARDETAIL.P4).** The first consequential write on `/billing/accounts/{account}` posts through the
  EXISTING `PaymentService` (`record` → `allocate` per operator-chosen invoice) and adds NO money logic of its own:
  the controller validates shapes, resolves each target tenant-scoped AND account-scoped (a forged foreign or
  other-account invoice id 404s **before** any money is written), and then hands over. **THE GUARD IS REUSED, NOT
  RESTATED** — the service refuses an allocation exceeding the invoice open balance or the payment's unallocated
  remainder, accepts only issued/partially-paid invoices, locks the payment + `invoice_balances` rows `FOR UPDATE`,
  appends every movement and audits it; the page merely surfaces the service's own refusal message. **The existing
  overpayment / partial-failure semantics are kept deliberately** (the `PaymentController::store` precedent): a
  refused allocation leaves the RECEIPT standing with a larger unallocated remainder (I3 allows exactly this)
  rather than losing money that was actually received, and a correction is a reversal row, never a mutation.
  Displayed inputs stay engine-sourced: `open_invoices` is a SELECTION of the ARDETAIL.P1 ledger rows, never a
  recomputation, and the allocation prefill is the engine's own open balance (editable, re-checked server-side).
  **Proven:** I1–I6 tie δ=0 after the payment and the P1 ledger / P7 rollup / `outstandingBalanceMinor` all move by
  exactly the payment; the adversarial grep finds no money math and no direct ledger write in the controller or the
  Vue. **GOVERNANCE (D-151 upheld):** the write is `billing.manage`-only and the agent has **no path** to it — no
  registered `AiTool` is a payment capability (the only financial tools are advisory drafts, both capped at
  APPROVE) and neither `Modules/AiCore/src` nor `app/AiCore` references `PaymentService`/`PaymentAllocation`/the
  write route. The agent never commits money. Remaining on this page: P5 payment plan (a wireframe-new model) and
  P6 Betreibung escalation (human-operator only, agent-EXCLUDED by construction, audited). See [[Billing]],
  `docs/wireframe-parity/AR-ACCOUNT-DETAIL-DIFF.md`, [[LOG]].

- **D-153 — Payment plans SCHEDULE money against a real balance; they never create or move it (ARDETAIL.P5).**
  The wireframe's payment plan is a real model (`payment_plans` + `payment_plan_installments`, integer minor,
  additive migrations) built on two invariants that together make phantom money impossible: (1) **THE TIE** — a
  plan's `total_minor` may not exceed the account's REAL outstanding, measured by `PaymentPlanService::
  accountOutstandingMinor()` over the SAME population the ARDETAIL.P1 ledger sums (series INV + frozen statuses,
  Σ of the reconciled `invoice_balances` open balances), so plan and ledger agree by construction; and a second
  ACTIVE plan per account is refused, so two plans can never together schedule more than the balance. (2) **THE
  PARTITION** — the schedule is computed in the ENGINE in integer minor units, every installment taking
  `intdiv(total, n)` with the LAST absorbing the remainder, so `Σ installments === total_minor` EXACTLY (δ=0);
  it is re-asserted against the persisted rows inside the creating transaction, so a schedule that did not
  partition its total could never reach the database. The page posts the operator's agreed total/count/start
  date and splits nothing.
  **THE PLAN WRITES NO MONEY.** Settling an installment goes through the ARDETAIL.P4 guarded `PaymentService`
  (`record` then `allocate`, oldest invoice first, each allocation CAPPED by that invoice's own open balance so
  the over-allocation guard is respected rather than probed); anything the account cannot absorb stays an honest
  unallocated remainder (I3 allows it), and the installment merely records WHICH payment settled it. Proven:
  I1–I6 tie δ=0 after each installment, the P1 ledger and outstanding move by exactly the installment, and
  paying every installment settles the balance to zero and completes the plan.
  **GOVERNANCE (D-151/D-152 upheld):** create, settle, cancel and default are all `billing.manage` operator
  actions; the agent has no path — no registered `AiTool` is a payment/installment/allocation capability (the
  financial tools remain advisory drafts capped at APPROVE) and no AiCore code references `PaymentPlan`,
  `PaymentService` or the plan routes. Every lifecycle step is audited, and cancel/default require a reason.
  `overdue` is DERIVED from the due date rather than stored, so it can never drift from the schedule. Remaining
  on this page: P6 Betreibung escalation (human-operator only, agent-EXCLUDED by construction, audited). See
  [[Billing]], `docs/wireframe-parity/AR-ACCOUNT-DETAIL-DIFF.md`, [[LOG]].

- **D-154 — Betreibung (debt enforcement) is a human legal act: operator-only, agent-excluded BY CONSTRUCTION,
  eligibility-gated, append-only (ARDETAIL.P6 — the AR Account Detail finale).** A Betreibung is a real legal
  proceeding, so none of its safeguards is a setting or a label:
  1. **A DEDICATED, NARROWER PERMISSION.** `billing.escalate` is new and is granted ONLY to `org_admin` and
     `billing`. It is deliberately narrower than `billing.manage`, which pharmacist / surgical-scheduler / ED /
     lab / radiology roles hold so they can capture charges through the engine — a clinical role that may
     capture a charge must not be able to start a legal proceeding (proven: a pharmacist holds `billing.manage`
     and is still refused).
  2. **ELIGIBILITY IS A REAL PRECONDITION.** Only an account whose dunning process is EXHAUSTED may be
     escalated — it must have reached the TERMINAL configured dunning level (the real state machine's last
     level) on at least one invoice and still owe money. Re-checked inside the service's own transaction, so the
     page cannot talk it into an early escalation. With no dunning policy configured NOTHING is eligible
     (fail-closed: you cannot exhaust a process that does not exist).
  3. **EXPLICIT HUMAN CONFIRMATION.** The service refuses without an explicit operator confirmation and a
     recorded reason; the route validates the confirmation as `accepted` so it can never be defaulted, and the
     confirmation moment is stored.
  4. **AGENT-EXCLUDED BY CONSTRUCTION — the point of the gate.** "0 auto-escalated" is not a displayed number:
     no registered `AiTool` is an escalation capability; no AiCore code references the service, model,
     permission or routes; the ONLY files in `Modules/` + `app/` that reference them are the service, the model
     and the two operator-gated controller actions (asserted as an EXACT list, so no job, console command,
     listener or schedule can reach it); `routes/console.php` automates nothing enforcement-related; and
     `initiated_by` is a NOT-NULL foreign key to `users`, so no system or agent actor can even be recorded as an
     initiator. The agent may still DRAFT dunning reminders through the existing cap/ApprovalQueue path — it
     simply has no path to a legal action.
  5. **APPEND-ONLY PROVENANCE.** Escalations are never edited or deleted (ORM guards + DB triggers); a
     withdrawal APPENDS a superseding record, and both acts audit with `actor_type = user`. The history of a
     legal action can never be rewritten.
  The page's copy states exactly this and nothing more. **With P6 the AR Account Detail page's wireframe parity
  is COMPLETE (P1 ledger → P2 dunning timeline → P3 visual → P4 record-payment → P5 payment plan → P6
  Betreibung)** — the seventh page of the parity pass; Appointment Detail and Auth Screens remain. See
  [[Billing]], `docs/wireframe-parity/AR-ACCOUNT-DETAIL-DIFF.md`, [[LOG]].

- **D-155 — The Appointment Detail page DISPLAYS the real record; it never fabricates a backend (APPT.P1).**
  The net-new staff page `GET /scheduling/appointments/{appointment}` is a pure read surface over the
  already-complete scheduling backend, and three choices define it:
  1. **APP-LAYER PLACEMENT.** It composes Scheduling + Patients + Clinical + Audit, so the controller lives in
     `app/Http/Controllers/` (D-017). Scheduling stays free of Clinical and Audit; the module-boundary arch
     tests keep it that way.
  2. **THE TRUE STATUS, ALL EIGHT STATES.** The pill renders `Appointment.status` as recorded and labels every
     state the machine defines (booked · confirmed · arrived · in_progress · completed · cancelled · no_show ·
     rescheduled) — not the four the wireframe happened to draw. This is the honest half of the audit's
     `booked → arrived` reconciliation: the page tells the truth about where the appointment IS; whether an
     action composes two legal edges is APPT.P2's decision, and `LEGAL_TRANSITIONS` is untouched here.
  3. **OMIT WHAT HAS NO BACKEND — surface-don't-fabricate.** The wireframe's room capability chips
     ("scanner · X-ray") are NOT rendered, because `Resource` has no capability field; inventing them would be
     fabrication. A test pins the resource payload to exactly `{id, name, type}` so they cannot creep in. The
     same rule governs the timeline: a reminder's channel is labelled exactly as recorded (only email exists —
     SMS/WhatsApp drivers are deferred, so the page can never claim one), and the confirmation line carries the
     REAL recorded actor — a portal action is attributed to the patient, an unattributed one to the system —
     rather than the wireframe's illustrative "patient replied 'JA'", which no inbound path produces. A test
     asserts no 'sms'/'replied'/'whatsapp' string can appear.
  The page shows **no computed judgment** (no no-show risk, no priority, no predicted duration — the duration is
  the service's configured length), **no money**, and **no actions** (the action row is APPT.P2, the reschedule
  modal APPT.P3). `appointment.manage`, branch-scoped; the appointment is resolved from a string id in-controller
  (FIX.1) so an unknown or cross-tenant id 404s. See [[Scheduling]],
  `docs/wireframe-parity/APPOINTMENT-DETAIL-DIFF.md`, [[LOG]].

- **D-156 — The action row renders the machine's own legal set; the server stays the only authority (APPT.P2).**
  The Appointment Detail action row is built from `AppointmentService::LEGAL_TRANSITIONS` for the appointment's
  ACTUAL status, not from a hardcoded list:
  1. **A READ ACCESSOR, NOT A COPY.** `LEGAL_TRANSITIONS` was private, so the gate added
     `AppointmentService::legalTransitionsFrom(string $status): array` — a read-only view of the SAME map
     `assertLegal()` enforces. It grants nothing (every move still goes through `transition()`, which re-asserts
     legality inside the row lock), and it means the UI and the guard cannot drift. Duplicating the map in the
     controller would have been precisely the page-side allow the fence forbids.
  2. **THE (a) RECONCILIATION — true status → its legal actions.** Because the page shows the TRUE status
     (D-155), it offers exactly that status's legal moves: booked → {Confirm, Cancel, No-show}; confirmed →
     {Mark arrived, Cancel, No-show}; arrived → {Start, Cancel}; in_progress → {Complete}; terminal → none. A
     genuinely booked appointment therefore offers **Confirm, never "Mark arrived"** — `booked → arrived` is not
     an edge, and no shortcut is composed here. **No edge was added to the machine.**
  3. **A DELIBERATE, RECORDED CROSS-SURFACE DIVERGENCE.** The day-board's `DayBoardActionController` continues
     to compose `confirm() → arrive()` for a booked appointment — two legal steps, each audited (the audit's
     option (c)). This page instead reflects the machine literally (option (a)). Both are legal, neither weakens
     `LEGAL_TRANSITIONS`, and they differ only in how many clicks reception needs; the divergence is recorded
     rather than silently reconciled, and either surface can be aligned later as a product decision.
  4. **THE REASON RULE COMES FROM THE SERVICE, NOT THE PAGE.** Cancelling validates a required reason because
     `cancel()` itself throws without one. A no-show reason stays **optional**, because `noShow()` permits null —
     the page does not invent a stricter rule at one surface (P0D.GU: rules are enforced server-side, not
     page-side). `rescheduled` is filtered out of the row entirely: reaching it needs the slot finder and the
     overlap guard, and belongs to APPT.P3.
  Proven: a forged illegal POST (arrive/complete/start from booked, or any move on a terminal appointment) is
  refused by `assertLegal` with the record untouched, and every accepted move writes the real
  `appointment.<status>` audit row attributed to the operator.
  **A CROSS-ENGINE TEST LESSON BELONGS TO THIS DECISION (the gate shipped RED and was fixed in `27fa22c`, which
  is the real APPT.P2 tip — `8874313` is the pre-fix commit).** A test asserted the audit context by matching the
  raw JSON substring `'"from_status":"booked"'`. That passes on dev MariaDB 10.4, which stores the JSON text
  as written, and FAILS on CI MySQL 8, which normalises a JSON column and re-serialises it — space after the
  colon, keys reordered. **The rule now: assert the MEANING of an audit context by `json_decode`-ing it, never
  the serialised text.** More generally: local-green is not CI-green, and every gate must be verified against the
  CI check-run, not the local suite alone. See [[Scheduling]],
  `docs/wireframe-parity/APPOINTMENT-DETAIL-DIFF.md`, [[LOG]].

- **D-157 — The reschedule modal is a caller of the real slot finder and the real reschedule(); it computes no
  availability and books nothing itself (APPT.P3).** The wireframe's reschedule flow is wired to the engine so
  that its promises are literally true rather than decorative:
  1. **THE SLOTS ARE THE FINDER'S.** The panel MERGES `AvailableSlotFinder::forServiceBranchDate()` answers
     across the next fortnight (the finder is per-date by design), excluding the appointment's own current slot.
     A merge of engine results is not a computation: the page never decides what is free. Each offered slot is
     tested to appear in the finder's own answer for its date. The constraint chips simply describe what the
     finder already applies (the same service, hence the same duration and required resource types).
  2. **THE PAGE NEVER PICKS RESOURCES.** Confirming submits only the chosen START TIME and the reason. The
     controller re-runs the finder at confirm, requires the slot to still be conflict-free, and uses **its**
     `resource_ids`.
  3. **THE GUARD, TWICE OVER — a reschedule cannot double-book.** After the re-check, `reschedule()` performs the
     move: reason-required, `assertLegal(→ rescheduled)`, one transaction with the old row `lockForUpdate`,
     re-booked via `BookingService::book` → `lockResource` → `assertNoOverlap`. A slot taken between display and
     confirm is refused by the re-check, and any race that slips past it is refused by the overlap guard
     (`BookingConflictException`) — both proven. "Availability is re-checked server-side at confirm" is therefore
     a statement of fact.
  4. **OMITTED, NOT FAKED.** The wireframe's "Dr. Weber only" toggle is absent: `AvailableSlotFinder` takes no
     preferred-resource parameter (it picks the first free resource of each required type), so rendering the
     control would fabricate a filter the engine cannot honour. It is queued as its own backend gate.
  Note for readers of the browser evidence: two appointments may legitimately share a start time on **disjoint**
  resources — that is not a double-book. The invariant is per-resource, and it holds (no resource ever carries two
  overlapping blocking appointments).
  **With P3 the Appointment Detail page's CORE wireframe parity is complete** (P1 display → P2 action row → P3
  reschedule); only the two optional backend follow-ons remain (a room-capability field for the chips; a
  preferred-practitioner filter for the toggle). See [[Scheduling]],
  `docs/wireframe-parity/APPOINTMENT-DETAIL-DIFF.md`, [[LOG]].

- **D-158 — A remembered browser still has to prove the second factor (AUTH-SEC.1).** The auth audit
  reproduced a standing 2FA bypass: after a password + 2FA login with "Remember me", the ~400-day
  `remember_web_*` cookie ALONE re-opened the app with no password and no challenge, because
  `EnsureTwoFactorEnabled` asked whether the user had ENROLLED, never whether *this session* had passed a
  challenge. The recaller legitimately remembers the PASSWORD factor; it must never stand in for the SECOND
  one. The middleware now turns a recaller-restored session back into a PENDING two-factor login — signing the
  guard out (dropping the authenticated session and the recaller), seeding `login.id`/`login.remember`, and
  redirecting to the challenge — so the remembered browser enters a code and leaves with a fresh recaller.
  **The proof is written in exactly two places, both requiring a valid code in that session:** the app-layer
  `TwoFactorPassedResponse` (bound to Fortify's `TwoFactorLoginResponse`, so it runs only after a TOTP or
  recovery code validates) and a `TwoFactorAuthenticationConfirmed` listener (enrollment confirmation, which
  also requires a code — without it a freshly enrolled user would be bounced straight to a challenge). Being
  authenticated, enrolled or remembered never writes it.
  **Two scoping decisions, both deliberate.** (1) The re-challenge is conditioned on the session having been
  restored FROM THE RECALLER, not on "every session must carry the flag": every other path to being
  authenticated either already involved the second factor or is caught by the enrollment check, and a universal
  rule would reject test-authenticated sessions — papering over that in the harness would have hidden the very
  bypass being closed. (2) The recaller check asks the WEB guard behind a `hasSession()` test, because
  `Auth::viaRemember()` proxies to the *default* guard — which for Sanctum token requests is a `RequestGuard`
  with no such method. A first attempt did exactly that and broke all 17 Nurse PWA API tests; the recaller is a
  session-guard concept and a token request has neither. Nothing is weakened: 2FA stays mandatory with no
  skip/disable path, interactive login, enrollment enforcement, the login throttle and the suspended-tenant
  rejection are untouched, and `SESSION_SECURE_COOKIE=true` was verified already present in the production env
  template. See [[Platform]], `docs/wireframe-parity/AUTH-SCREENS-DIFF.md` §4.1, [[LOG]].

- **D-159 — Public pages are smoked, because an unauthenticated 500 is the worst kind (AUTH-SEC.2).** Fortify's
  `resetPasswords()` feature was enabled — so `/forgot-password` and `/reset-password/{token}` were registered —
  but no view was ever bound, and both GET pages threw `BindingResolutionException` (HTTP 500). A locked-out
  user therefore had no self-service recovery at all. Binding the two Inertia views fixes the symptom and
  changes no auth rule: the POST flow, the signed-token check and the application password policy
  (`ResetUserPassword` → `PasswordValidationRules`) are untouched, and a reset leaves mandatory 2FA intact.
  **The disease was the coverage gap:** every existing route smoke authenticates first, so no PUBLIC page had
  ever been requested — which is exactly why a 500 on the pages an anonymous visitor meets could ship green.
  The FIX.5 smoke now drives the guest routes (`/login`, `/forgot-password`, `/reset-password/{token}`,
  `/invite/{token}`) as a real anonymous visitor. This was verified by temporarily removing the new bindings:
  the guest smoke fails with precisely `guest.forgot-password -> 500` and `guest.reset-password -> 500`.
  **FLAGGED FOR AN EXPLICIT DECISION, deliberately NOT changed here:** the effective password policy is
  `Password::default()` — a minimum of 8 characters, with no `Password::defaults()` configured anywhere, so no
  mixed case, digit, symbol or breach check. The reset correctly enforces whatever is configured; choosing what
  that should be is a product decision, not a security fix to slip in. See [[Platform]],
  `docs/wireframe-parity/AUTH-SCREENS-DIFF.md` §4.2/§7, [[LOG]].

- **D-160 — The manual-secret fallback shows the user's OWN real secret, or it shows nothing (AUTH-VIS).**
  The wireframe's 2FA enrolment step offers *"Can't scan? Enter the secret: `JBSW·Y3DP·EHPK·3PXP`"* — the
  accessibility escape hatch for someone with no camera, or authenticating on the same device they are
  enrolling from. The live screen had only the QR. Adding it raised the one question worth deciding: **where
  does the string come from?** Three answers were possible and only one is acceptable. Printing the
  wireframe's literal would put a fixed demo secret on a real enrolment screen — a fabricated credential, the
  exact class of thing the parity rule forbids. Generating or deriving anything page-side would mean the
  browser inventing key material the server never agreed to. **The value therefore comes from the server, from
  Fortify's already-existing `GET /user/two-factor-secret-key`, which decrypts `$request->user()->
  two_factor_secret`** — the authenticated user's own key by construction, with no id parameter that could
  point at anyone else's. The only page-side transform is chunking the string into four-character blocks for
  readability: **display formatting, never a new value.**
  **This opens no new exposure path.** It is the user's own secret, on their own enrolment screen, in the same
  authenticated context that is already rendering them the QR *that encodes the very same key* — the endpoint
  was already in `EnsureTwoFactorEnabled`'s exemption list precisely because enrolment must be reachable
  before the gate is satisfied. It is kept behind a reveal so it is not sitting on screen by default.
  **Nothing was weakened.** 2FA remains mandatory and locked (SETTINGS.P4): no skip, postpone or disable route
  was added, and a test asserts the route table contains none. AUTH-SEC.1's re-challenge and AUTH-SEC.2's
  reset bindings + guest smoke are untouched and green. The fallback is an alternative way to *complete*
  enrolment, never a way to avoid it.
  **The verification worth recording:** matching the displayed string against the database would only prove
  the page echoes a column. Enrolment was instead completed in a browser using a TOTP derived from the
  **displayed** secret — which proves the fallback IS the real provisioning key, since a wrong one cannot
  produce an accepted code. It was also confirmed to match what the QR's provisioning URI encodes, so the two
  enrolment routes cannot drift. Locked by `tests/Feature/Auth/TwoFactorSecretFallbackTest.php` (5).
  **This gate closes the nine-page wireframe-parity pass.** See [[Platform]],
  `docs/wireframe-parity/AUTH-SCREENS-DIFF.md` §9, [[LOG]].

- **D-161 — A super-admin's tenant-data access is an EXPLICIT, grant-gated decision, not an emergent side
  effect (OPMODE.G1).** The Operator Mode map found a live containment gap that had nothing to do with the
  feature: `Gate::before` returned `true` **unconditionally** for any super-admin (`tenant_id === null`), and
  `PermissionService::has()` did the same for `hasPermission()`. The only thing keeping a super-admin out of a
  clinic's data was that `IdentifyTenantFromUser` never gave them a tenant context, so `TenantScope` threw.
  **That is containment by accident.** Operator Mode's core action is precisely to give an operator a tenant
  context, so on the old code the first line of G2 would have converted the accident into unlimited, unscoped,
  untimed, unaudited access to every record in that clinic. The security core therefore lands FIRST, before any
  request flow, any approval and any screen.
  **THE INVARIANT:** a platform operator may reach a tenant's data ONLY through an `OperatorGrant` for THAT
  tenant that is **ACTIVE, UNEXPIRED, IN-TIER and IN-SCOPE**. Without one they have nothing. Enforced
  server-side at BOTH bypass points — `Gate::before` and `PermissionService::has()` — because `hasPermission()`
  reaches the latter directly and leaving it would have made the whole thing trivially sidesteppable.
  **THE SHAPE OF THE FIX — context-sensitive, not a removal.** With **no tenant context** the super-admin
  bypass stands: that is genuine PLATFORM-level work (the `/admin` console, the tenant list, cron and system
  jobs), and no tenant row is reachable there anyway because `TenantScope` throws. With a **tenant context
  set** the blanket bypass is gone and only a grant permits anything. This is what makes the change surgical:
  every legitimate super-admin path keeps working, and the gap closes exactly where it was.
  **FAIL-CLOSED BY CONSTRUCTION.** Tiers are an **allow-list**, never a deny-list — an ability no tier names is
  denied, so a permission added to the catalog tomorrow is outside every operator tier until someone
  deliberately places it. `read_only` (billing/reporting/audit **view**) and `configuration` (+ admin/ai/comms
  **manage**) can never reach PHI at all; the six PHI abilities require `full_support` **and** the specific
  record id in the grant's scope, re-checked **at access time** rather than once at session start. A grant with
  no expiry is invalid, not eternal. Status, expiry and revocation are re-read on **every** check, so an
  expired or revoked grant stops working on the very next call — no cache to bust, no session to invalidate.
  **NOT THE BREAKGLASS MODEL.** `BreakGlassService::request()` creates its grant with `activated => true`:
  requesting IS granting. That is the one property Operator Mode must not have, so this is a separate model,
  not an extension. What IS reused is BreakGlass's *audit* discipline — a required reason and an append-only
  hash-chained row per transition — and its layering: `OperatorGrantService` lives in `app/` so it may compose
  the Platform model with the Audit write path without either module depending on the other (D-017).
  **STRUCTURAL EXCLUSIONS.** An operator can never approve their own grant, and the approver must be a user of
  the target tenant (T6) — enforced in the issuing service, not in a UI. The agent can never hold or use a
  grant (T9): a test asserts the EXACT list of files in `Modules/` + `app/` that reference the grant, and that
  no AiCore/AiTool code and nothing scheduled touches it — the ARDETAIL.P6 Betreibung pattern. The grant FACTS
  (operator, tenant, tier, scope, expiry, reason) are immutable once written; a wider grant is a new decision.
  Every transition is written to the **TARGET TENANT's** ledger under a new `actor_type = 'operator'`, so the
  clinic can single out platform activity in its own audit view.
  **A FLAGGED TEST CORRECTION, NOT A WEAKENING.** `RbacTest`'s *"a super-admin bypasses all checks via
  Gate::before"* documented the old blanket behaviour. Its assertions were left **unchanged** (they always ran
  at platform level, which is still a bypass); it was renamed to say so, and a second test pins the in-tenant
  denial. Nothing else was modified.
  **A CONSEQUENCE WORTH RECORDING — the empty context is now ENFORCED, not assumed.** `TenantContext` is a
  request/job singleton, and `IdentifyTenantFromUser` previously achieved "super-admin -> no context" merely by
  declining to set one. That was fine while a super-admin's abilities did not depend on it; after this gate they
  do, so an INHERITED context would silently decide them. `composer check` caught exactly that: the Horizon guard
  went red in a test that drives a staff request and then a super-admin request through the same container. The
  middleware now explicitly `forget()`s the context for a non-tenant-staff user. Note the direction of the
  failure — a stale context can only ever DENY a super-admin, never widen them — so this was fail-closed working
  correctly, and the fix went into the middleware rather than into the test.
  **STILL OPEN (the map's §7 product decisions), untouched here:** whether Operator Mode ships at all, and
  whether the self-granted `configuration` WRITE tier stands. G1 deliberately builds no request flow, no owner
  approval and no route — there is no HTTP path to any of this yet. See [[Platform]],
  `docs/features/OPERATOR-MODE-MAP.md`, [[LOG]].

- **D-162 — Requesting is not granting, and `configuration` joins the owner-gated tiers (OPMODE.G2).**
  G1 shipped a grant that was already decided. G2 adds the entry point where an operator ASKS — and the whole
  point of the gate is what that ask does NOT do.
  **TWO PRODUCT DECISIONS, NOW SETTLED (they were the map's §7 blockers):**
  **(1) `configuration` REQUIRES the tenant owner's approval.** The wireframes drew it as self-granted, and the
  map flagged that as the weakest point in the design: it is a WRITE tier that changes a live clinic's settings
  and agent configuration. `OperatorGrant::TIERS_REQUIRING_APPROVAL` is now `[configuration, full_support]`.
  **(2) `read_only` self-grants**, because it is non-PHI reads only — the tier allow-list gives it exactly
  billing/reporting/audit **view** and refuses every PHI ability outright, so self-granting it cannot expose a
  patient record. That is what makes the exception defensible rather than a hole.
  **THE CORE PROPERTY — this is why BreakGlass was the wrong model to extend.** There,
  `BreakGlassService::request()` sets `activated => true`: asking IS receiving. Here, an approval-tier request
  creates a **PENDING** row with **no `granted_at` and no `expires_at`** — there is no session to be active
  with — so G1's invariant (which requires `status === active`) denies **every** ability, including the very
  records the request names. Proven by driving all twelve tier abilities against a pending full_support request
  and getting `false` from each.
  **NO SELF-APPROVAL PATH EXISTS.** Nothing an operator can call moves their own pending request to active. The
  only method producing an active approval-tier grant is `issue()`, which demands an approver who belongs to
  the target tenant and is not the operator (T6, from G1). A test also asserts the service has no
  `approve`/`selfApprove`/`activate`/`grant` verb at all — the absence is pinned, not just currently true.
  **THE TWO CLOCKS ARE SEPARATE COLUMNS, deliberately.** `request_expires_at` is how long the ASK stays open
  and **grants nothing** when it lapses; `expires_at` (G1) is how long an approved session lives and **ends
  access** when it lapses. Conflating them is the classic bug in this shape of flow. An expired request can
  never be approved — `isAwaitingDecisionAt()` refuses an out-of-time row and `assertActivatable()` (the guard
  G3's approve() must call, written here so the rule cannot be re-invented later) throws. The sweeper
  `expireDueRequests()` is housekeeping that makes the lapse visible and auditable; it is never what keeps
  access closed.
  **SCOPE MINIMISATION.** A `full_support` request must NAME its records — the map's "3 records tied to a
  ticket, not your whole database". There is deliberately **no wildcard**: `*`, `all`, `ALL`, `any` and `%` are
  all refused, as are empty lists and blank ids. Whether an "all patient records" grant should exist is still an
  open product decision, so until it is answered the only way to reach a record is to have named it — fail-closed
  by omission rather than by a flag someone could flip.
  **ONE NARROWING OF A G1 RULE, recorded honestly.** G1 made `granted_at`/`expires_at` absolutely immutable,
  which the pending→active transition cannot satisfy: a pending request has no clock, and approval must start
  one. They are now **set-once** — fillable from null exactly once, never rewritten. This is stricter where it
  matters (an existing session can never be silently re-clocked or extended by re-pointing the column) and is
  the minimum needed for G3. The request facts themselves (`requested_at`, `request_expires_at`,
  `requested_ttl_minutes`) join the strictly-immutable set.
  **ONE FLAGGED CONTRACT CHANGE.** G1's *"a configuration grant adds config writes but still refuses PHI"* now
  passes an approver, because decision (1) made `configuration` owner-gated. Its **assertions are unchanged** —
  what the tier permits and refuses is identical; only how it comes into existence got stricter. No other
  existing test was modified.
  **AUDIT.** A request writes `operator.access_requested` (or `operator.self_granted` for read_only) into the
  TARGET TENANT's append-only hash-chained ledger as `actor_type = 'operator'`, carrying `grants_access_now` and
  `awaiting_owner_decision` so the row states plainly that it is an audit of a REQUEST, not of an access. A
  lapse writes `operator.request_expired` with `granted_access: false`.
  **NOT IN THIS GATE:** no owner approval, no notification, no session mechanics beyond G1, no route and no UI —
  the request flow is service-level only, so there is still no HTTP path to Operator Mode. The remaining open
  decisions (is Operator Mode in scope for the first deployment; who counts as an "owner"; whether an
  "all patient records" scope is permitted) are untouched. See [[Platform]],
  `docs/features/OPERATOR-MODE-MAP.md`, [[LOG]].

- **D-163 — The owner is the tenant's org_admin, and approval is the only way in (OPMODE.G3).**
  G2 left pending requests with nobody able to decide them. G3 adds the decision, and with it the two-party
  model the map describes: the platform asks, **the clinic decides**.
  **SETTLED PRODUCT DECISION: the "owner" IS the tenant's `org_admin`.** No new role was invented. `org_admin`
  already means "runs this clinic", and a parallel owner concept would have created a second, weaker path to the
  same authority — two things to keep in sync, and a new way to get it wrong. The wireframes' "+1 other owner can
  approve" falls out for free, because a tenant may hold several org_admins and all of them are notified and all
  of them may decide.
  **ONLY A TARGET-TENANT org_admin MAY DECIDE — fail-closed, server-side.** `isOwnerOf()` refuses a super-admin
  outright (so the operator can never decide their own request — the G2 rule, now enforced from the other side
  too), refuses an org_admin of a DIFFERENT tenant, and refuses a tenant user without the role. Every refusal
  leaves the request pending and opens nothing.
  **APPROVAL IS THE ONLY pending→active PATH**, asserted structurally: exactly two files in `Modules/` + `app/`
  may even name `OperatorGrant::STATUS_ACTIVE`, and within the grant service there are exactly four writes, each
  accounted for (the self-granted `read_only` request, `approve()` in place, `approve()`'s downgraded grant, and
  `issue()`). No controller, job, command, agent or model callback can activate a grant.
  **A DOWNGRADE SUPERSEDES; IT NEVER MUTATES.** G1/G2 make a grant's facts permanently immutable, and that rule
  is not bent here. When an owner grants LESS than was asked, the request row is closed as `declined` and a NEW
  active grant is created at the narrower tier/scope with `supersedes_id` pointing back at it — the ARDETAIL.P6
  withdrawal recipe. Both facts survive permanently, which is also exactly what the wireframe shows the operator:
  *"YOU REQUESTED Full support / INSTEAD OWNER GRANTED Read-only"*.
  **AN OWNER MAY ONLY EVER NARROW.** `isNarrowerOrEqual()` enforces both axes: the granted tier's rank must be
  ≤ the requested tier's (`read_only` 0 < `configuration` 1 < `full_support` 2), and every granted id must
  already appear in the request, per kind — a kind the request never mentioned cannot be introduced by the
  decision. A "downgrade" that tried to widen is refused outright, and the request stays pending.
  **DECLINE AND EXPIRY ACTIVATE NOTHING.** A decline is terminal, so `assertActivatable()` (written in G2 for
  exactly this moment) refuses it from then on — no second bite in either direction, and an already-approved
  request cannot be re-decided or silently re-clocked. An expired request cannot be approved at all.
  **THE OWNER IS NOTIFIED, AND CANNOT BE MUTED.** The request routes to every org_admin of the target tenant via
  the existing `NotificationService`, carrying the operator, tier, the named records, the justification and the
  request expiry — the two-party transparency the map describes. The template is deliberately absent from
  `NotificationPreferenceService::MANAGEABLE`, and since only MANAGEABLE keys are ever written as preferences, a
  governance request is always ON and no admin screen can switch it off.
  **HONEST ABOUT THE CHANNEL:** the map draws in-app + email + push. **Only email exists** (the standing
  SETTINGS.P5 seam), so only email is sent and only email is claimed. In-app and push remain unbuilt.
  **NO OWNER, FAIL-CLOSED:** a tenant with no org_admin gets `operator.owner_unreachable` in its ledger and the
  request simply waits and lapses. It never self-approves for want of someone to ask.
  **TWO-SIDED AUDIT:** the decision is recorded with the **OWNER as actor** (`actor_type = 'user'`), not the
  operator — the clinic's own ledger showing its own admin deciding, beside the operator's request.
  **ONE FLAGGED CONTRACT CHANGE.** G2's no-self-approval test asserted that NO `approve` verb existed, which was
  true while nothing could decide. G3 adds `approve()`, so the assertion became the stronger one it was always
  reaching for: the operator still cannot activate their own request, now because the decision demands a
  target-tenant org_admin. `selfApprove`/`activate`/`grant` still do not exist. No other existing test was
  modified.
  **NOT IN THIS GATE:** no elevated-session mechanics beyond G1's invariant (G4), no route and no UI (G6+) — so
  there is still no HTTP path to Operator Mode. **Still open:** whether Operator Mode ships in the first
  deployment at all, and whether an "all patient records" scope is ever permitted. See [[Platform]],
  `docs/features/OPERATOR-MODE-MAP.md`, [[LOG]].

- **D-164 — Operator Mode is PAUSED after its security core, deliberately, with no HTTP surface.**
  The Operator Mode chain (`docs/features/OPERATOR-MODE-MAP.md`, G1–G11) stops after **G3**. This is an
  explicit product decision, recorded so no future session mistakes it for unfinished work.
  **WHAT IS DONE AND WHY IT WAS WORTH DOING FIRST.** The MAP found a **live containment gap that existed
  whether or not the feature ever shipped**: `Gate::before` returned `true` unconditionally for any super-admin
  and `PermissionService::has()` did the same, so the only thing keeping a platform operator out of a clinic's
  PHI was never being handed a tenant context — containment by accident, not by decision. **G1 closed it**
  (D-161): a super-admin now reaches tenant data only through an ACTIVE, UNEXPIRED, IN-SCOPE, IN-TIER,
  owner-approved grant, fail-closed at both former bypass points and regression-guarded. **G2** (D-162) then
  pinned *requesting is not granting*, and **G3** (D-163) pinned *the owner is the gate* — the two properties
  most easily got wrong once screens exist, fixed while the design was fresh. That work is COMPLETE.
  **WHAT IS DEFERRED, AND WHAT IT IS.** G4 (elevated-session mechanics), G5 (mid-session revoke + expiry +
  the session receipt) and G6–G11 (the ~7 operator/owner screens) are **operator-facing convenience UI**, to be
  built **after the first customer is live**. They add no safety property that G1–G3 do not already enforce —
  the invariant, the request flow and the owner decision are what make the feature safe, and they are done.
  **THE RESTING STATE IS SAFE, NOT HALF-BUILT.** There is **no HTTP route, no controller and no UI**; nothing
  can reach Operator Mode over the wire. The backend is inert but correct and tested (40 tests). Nothing
  operator-related is scheduled either, which is deliberate: with no surface there are no live requests to
  sweep. A feature that cannot be invoked cannot be exploited, so pausing here costs nothing in risk.
  **WHY PAUSE RATHER THAN FINISH.** The single highest-value track is DEPLOYMENT to the paying customers. The
  part of Operator Mode that was genuinely urgent was the part that fixed a live defect; the rest is workflow
  convenience for a platform team that does not yet have a live customer to support. Finishing it first would
  have spent security-critical attention on screens.
  **HOW TO RESUME:** read the MAP, then start at **G4**. Answer the one open scope question first if the answer
  might be "yes": **is an "all patient records" scope ever permitted?** It is currently FAIL-CLOSED — no
  wildcard exists in any form, so the only way to reach a record is to have named it. The other settled
  decisions (configuration requires owner approval; owner = the tenant's `org_admin`) already hold.
  See [[OperatorMode]], `docs/features/OPERATOR-MODE-MAP.md`, [[LOG]].

- **D-165 — First-customer provisioning is a real, refusing, repeatable command path (DEPLOY.PROV).**
  The pre-deploy readiness check found that the thing blocking the first paying customer was not the
  application but the **absence of any way to create one**: `Tenant::create` existed only inside the three demo
  seeders — no route, no controller, no command — so the runbook's "Create their tenant" step had nothing
  behind it, and there was **no `User::create` in production code at all** outside `StaffInviteService`, which
  requires an already-authenticated admin to send an invite. The first administrator of a new tenant therefore
  could not be invited by anyone. Both gaps were closable only by undocumented Tinker, which is not a
  provisioning process.
  **THREE COMMANDS, and deliberately no HTTP surface.** `tenant:create` makes a REAL, MINIMAL tenant — name,
  slug, region, plan, and the locale/currency/timezone settings — and seeds no patients, staff or money; it is
  not a demo seeder. `tenant:add-admin` creates the FIRST org_admin. `plans:seed` seeds the real subscription
  plans. Provisioning is an operator action on the box, not a public endpoint, so none of them is reachable
  over the wire.
  **THE ROLE TEMPLATES ARE NOT RE-IMPLEMENTED.** `tenant:create` relies on the existing `Tenant::created` hook
  firing `RbacProvisioner::provisionTenant()`, which syncs the permission catalog and seeds every starter role.
  The command then **verifies the hook actually fired and reports the count**, rather than assuming it — a
  tenant with no roles cannot be administered, and that is exactly the kind of thing discovered expensively and
  late.
  **REFUSAL IS THE DESIGN.** A duplicate slug is refused *before* anything is written, so a re-run can never
  half-create a tenant or silently attach to an existing customer. An unknown plan is refused with the list of
  real ones (and, when the table is empty, with the command to fix it) — again before any write. And
  `tenant:add-admin` **refuses when the tenant already has an org_admin**: once one exists there is somebody who
  can invite the next, so the bootstrap steps aside rather than becoming a permanent second path to user
  creation. It is the ONE bootstrap that needs no existing user, and it stays that way.
  **NOTHING IS WEAKENED. Mandatory 2FA still applies to the bootstrapped admin** — the command creates no
  `two_factor_secret`, so `EnsureTwoFactorEnabled` sends them to enrolment on first login and they cannot reach
  the app until they enrol; there is no skip path and this creates none (asserted by test). Tenancy stays
  fail-closed: the tenant-scoped writes run inside an explicitly set context that is **restored afterwards**, so
  a caller's context is never clobbered. The RBAC grant goes through the real `RoleAssignment::create` (which
  fires the audited `role.assigned`) with `branch_id = null`, because a branch-scoped assignment does not answer
  gate checks that pass no branch.
  **THE SILENT FAILURE M3 FIXED AT THE ROOT.** `tenants.plan_id` is nullable and `FeatureService` falls through
  to `false` for every feature when a tenant has no plan — so on a fresh production database, where the runbook
  never ran `db:seed`, telehealth/EVV/ai_drafting were all quietly OFF with nothing to indicate why. The
  release sequence now seeds the catalogs, `plans:seed` makes the step explicit and idempotent, and
  `tenant:create` takes a plan and warns loudly when asked for none.
  **THE PRODUCTION SEED PATH STAYS DEMO-FREE**, and that is now pinned by a test rather than left to
  convention: `DatabaseSeeder` calls only the permission and plan catalogs, so `db:seed --force` is both safe
  and REQUIRED in production, and a demo tenant can only appear if someone explicitly types `--class=Demo…`.
  **A DOCUMENTATION DRIFT CAUGHT BY RUNNING THE THING.** The live run reported **26** starter role templates,
  not the **17** claimed by the runbook — and by the readiness check, which had inherited the figure from the
  runbook instead of counting. Nine hospital roles had landed since. Both documents are corrected, and the test
  asserts `count(RbacProvisioner::ROLE_TEMPLATES)` so the number can never drift again.
  **The pre-deploy verdict moves from 🟡 CONDITIONAL GO to 🟢 GO.** M1–M4 are resolved; the two SHOULD-FIXes
  (S1 unscheduled audit partitions — which degrade rather than fail, and S2 unguarded demo seeders) remain open
  and non-blocking. See [[Platform]], `docs/DEPLOY-READINESS-CHECK.md`, `docs/DEPLOY-RUNBOOK.md`, [[LOG]].

- **D-166 — A shared clinical component's job is to make the fence CHEAPER TO KEEP than to breach; the stat
  tile is therefore CLOSED, not flexible (DENTAL-B.P1).** The dental batch audit found that the wireframes'
  clinical intelligence lands, visually, in one repeated element: the 3–4 stat tiles under the patient header.
  The mock fills that exact tile with BOP %, DMFT, mean pocket depth, a plaque score, "1 finding", "one site to
  watch" and trend arrows ("▼ from 3.1", "plateau") — every one a computed clinical judgment ruled
  MUST-NOT-BUILD. A conventional shared tile (a `label`, a `value`, a free slot, a `tone` prop) would have made
  every one of those a two-line change on any of eight screens.
  **So `ClinicalStatTile.vue` has no slot, no tone/colour/status/trend/direction prop, and no arithmetic.** It
  takes a label, a caller-supplied value STRING, and an optional unit and caption; the value is rendered as
  received. It deliberately does NOT reuse the generic `Components/StatCard.vue`, whose icon slot would reopen
  exactly the hole this component exists to close. The constraint IS the feature: the tile can only ever state
  a recorded fact, and a future author who wants a trend arrow has to add the affordance in the open, where a
  reviewer sees it.
  The same principle set the line on the S1 allergy chip: **displaying a recorded severity is record-not-judge;
  letting it drive colour or ordering is not.** The chip's styling is constant and a test asserts that no
  `:class`/`:style` binding references `severity` — that one narrow, documented allowance is the only place the
  word may appear.
  **The fence tests scan comment-stripped source, on purpose.** These components' comments NAME what they
  refuse to build ("DMFT", "BOP %", "trend arrows"), and that documentation must not be what trips the scan.
  The assertions were mutation-checked rather than trusted: a `trend` prop, a `Number(value) / 100`, and a
  `:class` keyed to `allergy.severity` each turn the suite red. Relatedly, `baseline` was REMOVED from the
  forbidden-token list once it turned out to match the Tailwind `items-baseline` utility — banning a CSS class
  teaches the next author to weaken the scan instead of respecting it. See [[Dental]],
  `docs/wireframe-parity/DENTAL-BATCH-DIFF.md` §5.1, [[LOG]].

- **D-167 — S3 already existed; the audit was corrected rather than the component duplicated, and its
  "role-gating" was deliberately NOT built (DENTAL-B.P1).** The batch audit listed a specialty tab strip as one
  of six shared components to build. It already exists: `Components/DentalSectionNav.vue`, shipped at DENTAL.G9
  with all five tabs on the real patient-scoped routes and already translated. P1 did not rebuild it.
  The gate also asked for the strip to be role-gated so a user sees only the tabs they may open. **Examined and
  declined:** all five targets (`dental.chart`, `dental.perio`, `dental.diagnoses`, `dental.plans`,
  `dental.imaging`) authorise `patient.view` on their show action — the same permission the user already spent
  to be on any dental page. Per-tab gating would encode a distinction the routes do not make, and would rot the
  moment one route's gate changed. (`/dental/fee-schedule` is `billing.manage`-gated and is correctly absent
  from the strip already.) The audit's "six components" was likewise an overcount — S6, the scan tile, was
  never in P1's scope. **Four new components were delivered (S1/S2/S4/S5); S3 pre-existing.** Recorded in
  `DENTAL-BATCH-DIFF.md` §7 under "P1 outcome" so the chain's later gates inherit the corrected picture rather
  than the original claim. See [[Dental]], [[LOG]].

- **D-168 — Read mode is a UI affordance, never a permission; and a shared-component fence scan must survive
  camelCase (DENTAL-B.P2).** The odontogram wireframe draws a Read/Chart toggle. It is built as exactly that:
  read mode hides the charting affordances so a dentist reviewing a chart cannot record by accident, and it
  changes nothing on the server. `dental.chart` still authorises every write, the client sends no mode the
  server reads, and the toggle is only offered to someone who can already chart — for anyone else the page was
  read-only already, by the gate. A test asserts the invariant from both sides: a forged `mode=read` does not
  block a permitted write, and a forged `mode=chart` does not grant reception one. **A UI mode that the server
  honoured would be a permission wearing a toggle's clothes** — the two must never be confused.
  **The gate offered a stat tile and it was declined.** P1's `ClinicalStatTile` was available and the scope
  allowed it "if any factual count is shown". The only counts this page could produce are counts over clinical
  findings — precisely the "1 finding" chip §5.1 rules MUST-NOT-BUILD. An empty space beats a fence breach, so
  the odontogram ships with no tile.
  **A mutation exposed a real hole in P1's fence scan.** Injecting a camelCase `siteToWatch` computed into
  `ToothArch.vue` passed BOTH fence suites: `\bwatch\b` does not match inside `sitetowatch`, and the phrase
  "site to watch" never matches an identifier — which is how the breach would actually be spelled. Both suites
  now additionally scan a **non-alphanumeric-stripped** copy of the source for compound §5.1 phrases
  (`sitetowatch`, `cariesindex`, `findingcount`, `severityramp`, `trendarrow`). Bare `watch` is deliberately
  still permitted: it is Vue's own reactive primitive, and banning a framework API teaches the next author to
  weaken the scan rather than respect it — the same reasoning that removed `baseline` at P1 (D-166).
  Corollary worth keeping: **the Pest suite shares the dev database**, so `RefreshDatabase` wipes the demo
  tenants. Browser verification must be re-seeded and must run AFTER the last test run — the gate's "verify by
  querying, not by trusting the seeder's exit code" instruction is what caught it. See [[Dental]],
  `docs/wireframe-parity/DENTAL-BATCH-DIFF.md` §5.1, [[LOG]].

- **D-169 — A severity ramp needs no judgment word, so the fence assertion has to live in the STYLING, not the
  vocabulary (DENTAL-B.P3).** Perio is the most computed screen in the dental pack after Endo: the mock's entire
  right rail is BOP %, "sites ≥ 4 mm", mean pocket depth, a plaque score, trend arrows, "one site to watch", a
  depth-keyed colour band and a bitewing "bone loss confirmed". All of it stays unbuilt, and this gate re-asserted
  that. The interesting part is HOW.
  Every fence test up to here scanned for VOCABULARY — `severity`, `trend`, `sitetowatch`. A mutation written as
  `function cellTint(mm) { return mm >= 6 ? 'bg-danger' : mm >= 4 ? 'bg-warning' : '' }` passed all of them: it
  contains no banned token, and the threshold regex was keyed to `pocket_depth_mm`, which misses a parameter
  simply named `mm`. **A colour ramp is the one fence breach that can be written entirely in neutral words** —
  and it is the single most likely one on a perio chart, because every clinical UI in the world tints deep
  pockets red.
  So the rule was moved to where the breach MUST surface — the styling itself. **No `:class`/`:style` binding in
  the perio surfaces may reference a measurement (`depth`, `_mm`, `tint`, `band`, `colour`, `shade`, `heat`) or
  compare against a number**, and inside `PerioSiteGrid.vue` the tone classes
  (`bg-`/`text-`/`border-`/`ring-`/`fill-` × `danger`/`warning`/`success`/`critical`/`alarm`) are **banned
  outright**: every cell in that grid is drawn identically whatever number it holds, so a tone there could only
  ever be a ramp. The ban is scoped to the grid rather than the page, because the page's flash message is
  legitimately a success tone — the same "don't ban a legitimate primitive" reasoning that kept `baseline` (D-166)
  and Vue's `watch` (D-168) permitted. Both a tone-class ramp and a neutral-palette ramp wired through a binding
  now turn the suite red, and the browser confirms the property directly: **all 96 depth cells report exactly one
  distinct computed style.**
  Corollary on what a test can and cannot prove: `v-for="site in group.value"` rendered ZERO inputs (template
  refs are auto-unwrapped, so `.value` was undefined) while the whole suite stayed green — the payload was
  correct and the source scans passed, but the grid was empty on screen. **Source and payload assertions cannot
  see an empty UI; only the browser check caught it.** Likewise a helper named `ppCtx()` collided with
  `PerformProcedureTest`'s and was invisible until the whole-directory run. Run the directory, then open the
  page. See [[Dental]], `docs/wireframe-parity/DENTAL-BATCH-DIFF.md` §5.1, [[LOG]].

- **D-170 — When the mock shows money the engine cannot source, omit it; when it shows an agent that does not
  exist, do not invent one (DENTAL-B.P4).** The Treatment Plan wireframe carries two things the live system
  cannot honestly produce, and both were declined rather than faked.
  **The payment plan.** The mock offers "4 × CHF 310" against the plan estimate. The existing `PaymentPlan`
  (ARDETAIL.P5) covers *an account's REAL outstanding balance*, and its own invariant forbids a total exceeding
  what is actually outstanding. A treatment plan is an ESTIMATE — nothing is outstanding until procedures are
  performed and invoiced — so a payment plan against it would require inventing outstanding money, or a second,
  dental-specific money model running alongside the real one. **Neither was built.** The gap is recorded instead.
  **The agent-drafted sequence.** The repo has ten agent tools and none touches dental; `Modules\Dental` has no
  ApprovalQueue coupling at all. The affordance is therefore absent BY CONSTRUCTION, and a test now pins that
  absence at both ends — no tool file may mention dental/treatment_plan, and the page may offer no agent
  affordance — so a later gate cannot slip in one that auto-applies. Had a tool existed, the rule would have
  been the standing one: the agent DRAFTS into the ApprovalQueue at an APPROVE ceiling and a human commits.
  **The money that IS real got more real.** "N of M billed" previously had no source; it now reads the actual
  charges captured when a planned item is performed (`PerformedProcedure.charge_id` → `Charge.line_total_minor`,
  cancelled excluded) — money in the ledger, never the estimate re-labelled. An un-performed item carries NO
  billed figure rather than a fabricated zero.
  **The page now does no money arithmetic whatsoever**: the controller emits formatted strings and the page's
  own `money()` helper was deleted, satisfying the S5 contract that a card receives an already-formatted,
  engine-supplied string. The cost is a **deliberate duplication** — the PHP formatter mirrors
  `resources/js/lib/money.ts` (`formatSwissMoney`, ARDETAIL.P3), same Swiss apostrophe grouping. Two
  implementations in two languages can drift, so the tests pin the exact output strings. Other Billing pages
  keep the client helper; converting them was out of scope. See [[Dental]], [[Billing]],
  `docs/wireframe-parity/DENTAL-BATCH-DIFF.md` §5.2/§5.3, [[LOG]].
- **D-171 — The fee schedule's fence is LICENSING, and it is enforced by a repo-wide scan, not by good intentions
  (DENTAL-B.P5).** The dental fee-schedule wireframe is drawn on a Swiss tax-point tariff: positions priced as
  tax points times a Taxpunktwert, with effective-dated versions and a version diff. That pricing data is
  **licensed**, as are the ADA CDT procedure codes the mock's code column implies. CareOS bundles neither, and
  P5 made that a test rather than a convention: a structural scan across `Modules`, `app`, `database`, `config`
  and `resources/js` forbids any CDT-shaped code (`D` + exactly four digits) and any licensed tariff term
  (taxpunkt / tarmed / uv-go / dentaltarif / ada cdt / cdt code / sso tarif). The shipped starter template is
  asserted generic — every code matches `^D-[A-Z]+$` — so **a genuine CDT code is distinguishable from ours by
  shape alone**, which is what makes the scan cheap and precise. Adding `D0120` or a `TAXPUNKTWERT_MINOR`
  constant now turns the suite red.
  **The scan reads comment-stripped source, for the third time in this chain.** `DentalCatalogService`'s docblock
  says "NO licensed code set (ADA CDT / Swiss SSO point values) is bundled" — the sentence declaring the policy
  would otherwise be the thing that fails the test enforcing it. Forbid the affordance, never the documentation
  of its absence (cf. D-166, D-169).
  **What could not be sourced was flagged on the page, not invented.** The mock groups positions by a category
  taxonomy that has no backend field; P5 grouped by a REAL attribute instead (`tooth_scoped` → "charged per
  tooth" / "charged once"). The tax-point column and B2's effective-dated versioning were left unbuilt and are
  now stated in an "About this schedule" note — a user reading the screen learns what it deliberately does not
  carry, instead of wondering why it is missing.
  **Money stayed displayed, not computed.** The controller emits the fee string AND the edit-form value, so the
  page divides nothing; the only arithmetic left converts what the dentist TYPED on its way to the unchanged
  endpoint, and a test pins it to the two form transforms. No average fee, no total catalog value, no price
  banding — and, per D-169, no row is tinted by its price. See [[Dental]], [[Billing]],
  `docs/wireframe-parity/DENTAL-BATCH-DIFF.md` §4 (B2), [[LOG]].
- **D-172 — On a clinical image, the fence is "the system may not DRAW"; and the DENTAL-B core chain closes
  (DENTAL-B.P6).** The dental wireframe's imaging intelligence is CADe/CADx — a regulated medical device — and
  it is the sharpest fence in the batch: AI radiograph findings, "bone loss confirmed on today's bitewing",
  scan analysis, per-tooth coverage flagging, "beyond 0.5 mm". None of it exists and none may be added.
  The useful new formulation is that on an image surface, **the breach is drawing, not vocabulary**. A finding
  does not need the word "finding" — it needs a box, a highlight, a marker or a measurement rendered over the
  pixels. So the component-level rule is now: the viewer may not draw. `<canvas>`, `getContext`, `fillRect`,
  `strokeRect`, `drawImage`, `<svg>`, `marker`, `boundingBox` and `heatmap` are forbidden in `Imaging.vue`, and
  a mutation adding an `<svg><rect>` over the image turns the suite red. That sits alongside the payload-key
  scan (which the pre-existing DENTAL.G8 test already enforces, and which the AI-findings mutation confirmed
  still bites) and the D-169 styling rule. **Zoom and pan remain fine — they are optics: they change which
  stored pixels you see and record nothing.** Any annotation that ever ships must be the CLINICIAN'S OWN
  authored record, which today is the free-text reading.
  **The upload form is a smuggling route, so it was tested as one.** A forged `ai_finding` field posted to the
  unchanged endpoint must reach nothing — asserted explicitly, because "the page has no AI affordance" is not
  the same claim as "no AI value can enter through the page".
  **With P6 the DENTAL-B core chain (P1–P6) is COMPLETE** — nine of the thirteen dental screens addressed. The
  four no-live-page screens (Scan Comparison, Ortho Progress, Chair Scheduling, Inventory & Sterilization) stay
  DEFERRED as net-new subsystems: three by prior decision, and sterilisation/reprocessing has no model anywhere
  in the repo. Two optional gates remain open: B3 structured procedure records, and B4 the `Resource`
  capability field that also closes the recorded APPT.P4 gap. Nothing in the six gates changed the fence; the
  batch's honest headline still stands — **most of what the dental mocks show must continue to be refused**, and
  the live build was already refusing it. See [[Dental]], `docs/wireframe-parity/DENTAL-BATCH-DIFF.md` §4 (B5)
  and §5.1, [[LOG]].
- **D-173 — A cross-module clinical read moves the CONTROLLER to the app layer, not the dependency into the
  module; and a fence scan must follow a file that moves (PC.P1).** Patient 360 needed the patient's recorded
  allergies, which live in Clinical. `Modules\Patients` may not use `Modules\Clinical` and an arch test enforces
  it, so the answer was not to relax the boundary but to move `PatientShowController` into `app/` — the same
  reasoning that already placed `AppointmentDetailController` there (D-017). The move changed a namespace and
  nothing else: same route, same gate, same payload, and the **same single read-audit row**, asserted by
  counting `auditRead(` occurrences so the new read cannot quietly grow a second audit path.
  **The smallest change that closes a gap is usually the one the page already anticipated.** `Patients/Show.vue`
  had carried a dormant `allergies` prop and a hidden banner since it was built — "rendered when present, absent
  silently until the prop lands". Landing the prop lit the banner with no page rewrite. The chips show the
  RECORDED substance, reaction and clinician-recorded severity as facts, styled identically, ordered by
  SUBSTANCE — ordering by severity would be the system asserting a priority (D-169). And the empty case says
  "No allergies recorded", because *none recorded* is a different claim from *we did not look*.
  **Patient 360's hero was deliberately NOT replaced with the shared header.** The hero carries a status pill, a
  flag chip and a dental cross-link the shared component has no props for; swapping it to "adopt S1" would have
  regressed the page to satisfy a tidiness goal. That is PC.P3's gate, with the props to do it properly.
  **A FENCE SCAN MUST FOLLOW A FILE THAT MOVES.** Promoting the header out of `Components/Dental/` would have
  dropped it from `SharedComponentsTest`'s glob — a fence weakened not by editing an assertion but by relocating
  its subject, which no reviewer would see in the diff. Both the path helper and the recursive scan now resolve
  either namespace, and the value was immediate: the severity-tint mutation was caught by that very test.
  Corollary, third time in two chains: scans must not ban legitimate primitives. The arithmetic check matched
  Tailwind's `border-line/60` opacity syntax, so it now reads the `<script>` block only; and `fall` as a
  substring matched "fallback", so the token is `fallrisk` — the judgment is a fall-RISK SCORE, not the word.
  See [[Patients]], [[Clinical]], `docs/wireframe-parity/PATIENTS-CLINICAL-BATCH-DIFF.md` §4 (B1), [[LOG]].
- **D-174 — A count rendered in the page is a claim about the record, so it must be counted where the record
  lives; and an absence assertion over an empty collection proves nothing (PC.P2).** The Patient Chart's band and
  tab chips were `array.length` over the loaded payload. That looks harmless and is not: the chart's lists are
  deliberately PARTIAL — `notes` carries head versions only (superseded ones are reachable through the version
  chain) and `orders` is empty for an actor who may not see them — so a Vue length silently UNDER-REPORTS the
  record to a clinician. The counts are now computed server-side from real rows, and the two lists that are
  filtered have counts that deliberately MIRROR their lists, so a chip can never disagree with what sits under
  it. The superseded client-side `openRecalls` computed was deleted rather than left in place: a second,
  divergent source of the same number is exactly the defect being removed.
  **The more important lesson is about the test.** The vitals fence assertion — "no band, flag, score, delta or
  trend key" — passed a mutation that added `'band' => 'high'`, because the fixture recorded NO VITALS. An
  absence assertion over an empty collection is VACUOUSLY TRUE, and it had been sitting there looking like
  protection. The test now records real vitals, asserts the collection is non-empty BEFORE scanning it, and
  deliberately includes a frankly abnormal reading (176/104, SpO2 91) so the fence is proven against exactly the
  data that would tempt someone to annotate it. **Every absence assertion needs a positive control: prove the
  thing you are scanning actually contains rows.**
  Corollary on find-in-chart: a client-side substring filter over already-loaded content is a TEXT FILTER, not
  clinical computation — it fetches nothing, ranks nothing and reorders nothing, and the page says so. Ranking
  by relevance would be a different thing entirely, and a test forbids it. Likewise recall proximity is a plain
  calendar interval ("due in 66 days"), not urgency: nothing is tinted by it (D-169). See [[Clinical]],
  `docs/wireframe-parity/PATIENTS-CLINICAL-BATCH-DIFF.md` §7, [[LOG]].
- **D-175 — The fence audit: 85 absence assertions inventoried, measured EMPIRICALLY, and 6 hardened. No real
  breach was hiding behind any of them (FENCE-AUDIT).** D-174 found one vacuous guard by accident. This gate
  went looking for the rest rather than guessing which were at risk.
  **Method.** Static reading cannot tell you whether a scan ever inspected anything, so the suite was
  temporarily instrumented: every recursive absence-helper logged the SIZE of what it was handed, and every
  glob logged how many files it resolved to. Then the suites were run and the log read. A first pass looked
  damning — three helpers appeared to have empty calls — but the probe was counting RECURSIVE DESCENTS into
  empty sub-arrays, not top-level invocations. A second, depth-aware pass (log only depth 0) corrected it, and
  **two of those three findings evaporated**. Measure the right thing before reporting a problem.
  **Result. Of 15 recursive payload scans, 14 bite; ONE had a vacuous call site** — `DiagnosisTest`'s scan of
  the diagnosis term pick-list, which ran over an EMPTY list because the fixture never authored a term. Of the
  file/source scans, **four `*SourceContains` helpers returned `false` for a MISSING DIRECTORY**, so
  `expect(...)->toBeFalse()` passed having scanned nothing — the guard would go silent the moment its target
  moved (the D-173 shape). They protect ALLERGY.P1 (no homemade drug-conflict logic) and three ARDETAIL money
  fences. Two globs were unguarded or under-guarded: `Components/Clinical/*.vue` had no control at all, and the
  merged dental+clinical glob asserted only "not empty" — which still passed if ONE namespace stopped
  resolving, precisely the move it was written to survive.
  **THE KEY QUESTION — did anything forbidden slip in behind a dead guard? NO.** Checked directly:
  `new SafetyAlert(` appears nowhere in `Modules/Pharmacy/src` or `Modules/Clinical/src`, so the ALLERGY.P1
  fence holds in reality; and the terms payload emits only `id` and `label`, no suggestion or ranking key. The
  guards were incapable of proving the fence, but the fence itself was never breached.
  **Fixes, all strictly stronger.** The source-scan helpers now THROW when their target is missing rather than
  reporting "not found". The term pick-list fixture now authors a real term — and deliberately one
  (`Caries profunda`) that names the very finding just charted, i.e. exactly the case where a system that
  WANTED to suggest would surface it. The glob scans now assert their subjects resolved and NAME the
  components that must be in the scan. Every change was mutation-checked: a `suggested` key on the terms list,
  and simulated moves of `Modules/Pharmacy/src`, `Components/Clinical/` and one of the two component
  namespaces — all four now fail loudly where they previously passed in silence.
  **The rule is now in AGENTS.md** so future gates write positive controls by default: prove the subject is
  non-empty, make the fixture representative of what would tempt the breach, and mutation-check. **A guard that
  has never been seen to fail is not yet a guard.** See [[LOG]], D-173, D-174.

- **D-176 — A chip that asserts a recorded fact must be BOUND to one; an unbound chip is a fabrication, and
  the fix is to DELETE it, not to invent the column (PC.P3).** Patient 360's hero carried `⚑ Flag` as a
  hardcoded, unbound `<span>` — rendered for **every** patient, on a model with **no flag column, no
  attribute and no migration anywhere in CareOS**. It looked like parity with the wireframe; it was the one
  genuinely faked thing on the screen. Every other omission in this programme has been an absence the user
  can see; this was worse — **a presence the user cannot distinguish from a real one**. Three ways to close
  it, and only one is honest: (a) derive a flag from the record — that is a **computed risk marker**, exactly
  what the fence forbids; (b) add a boolean column and default it — a fact with no author, no reason and no
  timestamp is not a clinical record; (c) **remove it and record the gap** — chosen. A flag is meaningful
  only as a CLINICIAN-RECORDED fact: who flagged, why, and when. Closing the gap properly needs its own
  gate. The i18n string `patients.show.headerFlag` was deleted with it — **a live string is an invitation to
  render it again** — and the shared header deliberately grew **no** `flag` prop, since a prop is an
  affordance and the next author would fill it. A test now pins all four: no column, no glyph, no key, no
  prop. **Generalises past chips:** any element asserting something about a patient must trace to a stored,
  authored value; if it cannot, it does not ship. See [[LOG]], D-170 (money the engine cannot source is
  OMITTED, and an agent that does not exist is NOT invented) — this is the same rule applied to a FACT.

- **D-177 — When the MOCK draws no agent either, the absence is not a gap to fill: an assist panel is
  OMITTED, and a guard that counts CALL SITES does not protect a WRITE SURFACE (PC.P4).** The Note Editor
  gate arrived expecting an assist panel bounded to rephrasing the clinician's own text. Two independent
  checks said build nothing: **no rephrase or note-authoring capability exists** (ten AiCore tools, none
  touching note prose; the only clinical one is extractive at a SUGGEST ceiling and belongs on the Chart),
  and **the decoded wireframe contains no assist affordance whatsoever** — zero mentions of assist,
  rephrase, AI, agent, suggest or summarize. D-170 says an agent that does not exist is not invented; this
  adds the sharper case: **when the mock does not draw one either, adding one is not parity at all** — it is
  putting a content-producing affordance beside a legal clinical record on nobody's request. The existing
  extractive summary was deliberately NOT duplicated here for the same reason. **The second half is a
  testing lesson paid for by a mutation that PASSED:** the auto-insert guard counted `insertSnippet` call
  sites, so a NEW WATCHER assigning straight into a SOAP section slipped through green — and that is
  precisely the shape auto-authored text would take. Counting the *known* entry points cannot prove the
  absence of an *unknown* one. The guard now pins the **write surface**: exactly one watcher on the page,
  and exactly one assignment into a SOAP section anywhere in it. **Generalises:** to prove nothing writes
  X, enumerate and bound the writes to X — never the callers you happen to know about. See [[LOG]], D-170,
  D-174.

- **D-178 — On a transparency surface, COMPLETENESS is the property and a silent omission is the bug: the
  access log filters on patient + action ONLY, and what it cannot show it says on screen (PC.P5).** A
  patient exercising a subject-access right is entitled to every read of their record. A log that quietly
  drops a category of reader is not merely incomplete — it is a **false assurance**, and worse than no
  screen at all, because it produces confident belief in a wrong answer. So completeness here is
  **structural, not a list**: the query applies no actor-type, surface, role or recency whitelist, and the
  filter chips are built from the actor types ACTUALLY PRESENT rather than a hardcoded taxonomy that a
  future reader type would silently fall outside of. **The gap that was found is reported, not faked:**
  platform-support (operator) events are written against the TENANT — action `operator.access`, no
  `patient_id` — so they cannot be attributed to a patient without inventing a link. The log does not try;
  the screen states the limitation in words. **Two corollaries paid for in this gate.** (1) A MULTI-ACTOR
  fixture is not optional here: `COUNT(DISTINCT actor_type, actor_id)` silently dropped the system reader
  (MySQL discards the row when either value is NULL) — the reader a patient is least likely to know about,
  missing from the headline count, and invisible to any single-actor test. (2) The export must share the
  screen's QUERY, not merely its intent: one method, one filter set, so the file a patient receives cannot
  disagree with what they were shown — and exporting is itself a disclosure, so it audits itself and
  appears in its own report. **Generalises:** when a surface's purpose is to prove nothing is hidden, every
  narrowing must be either impossible or visible. See [[LOG]], D-174, D-176.

- **D-179 — A verb the system does not perform must not be implied by the UI: "sent" means RECORDED AS
  SENT, and the screen says so (PC.P6).** `ReferralService::send()` sets a status and a timestamp. It
  transmits nothing — no channel, no message, no document, no integration exists anywhere in the repo. A
  button labelled "Send referral" beside a "Sent" pill would therefore assert an action CareOS never
  took, and a clinician could reasonably believe the specialist had received it. That is the same class of
  defect as D-176's unbacked flag chip — **a presence the user cannot distinguish from a real one** — but
  about a VERB rather than a fact. The button says "Mark as sent", the bar says CareOS does not transmit,
  and the rail tells the clinician to send it through their usual secure channel. **The rule: when the
  backend records an intention rather than performing an action, the UI must name the recording, not the
  action.** Wiring a real channel is a new capability and needs its own gate. The same gate omitted four
  further drawn-but-unbacked things (urgency, a document packet, a provider directory, a referral agent)
  under D-170 — and note that **urgency was the one that mattered most to omit**: had it been invented,
  the UI would immediately have had a value to rank and tint referrals by, which is precisely the
  clinical judgment the fence forbids. See [[LOG]], D-170, D-176, D-178.

- **D-180 — Sorting a recorded DATE is not ranking patients; and a capped ceiling is what makes wiring an
  agent safe rather than a leap of faith (PC.P7, closing the PC batch core).** The recall worklist puts the
  longest-overdue row first, which looks like triage and is not: it is `ORDER BY due_on ASC` on a date a
  human recorded. The distinction is the whole fence on that screen, so it is stated in the controller, on
  the page, and proven by a test that shows the sequence is explained by the date alone. What stays
  forbidden is everything that would turn the order into a JUDGMENT: a priority or urgency score, an
  overdue severity band, a likelihood-of-non-attendance, or — the one that matters most in practice —
  **any `:class` keyed to how overdue a row is.** Painting the overdue rows red is the system telling a
  clinician which patient matters more, using a number it derived. Every row across a -200…+120 day spread
  renders with one class string, verified in a browser.
  **The second half is about wiring an agent at all.** The mock promised routine invites "sent
  automatically at Level 1". The real `clinical.draft_recall_message` tool is capped at **SUGGEST**, so
  `AgentRuntime::runTool()` can only reach its `propose()` branch — auto-send is not a switch someone
  forgot to flip, it is **structurally unreachable** without raising the ceiling. That is precisely why the
  tool could be wired in a parity gate with confidence: **the ceiling, not the UI, is the guarantee.** The
  clinician writes the wording; the tool fills in recorded facts, refuses medical advice, blocks on missing
  comms consent, and the draft waits in the capped queue for a human to send. **Corollary on auditing a
  MULTI-PATIENT surface:** the one-row-per-render rule (D-174-era, PC.P1/P5/P6) assumes one patient per
  screen. A worklist showing many must write one row PER PATIENT DISCLOSED, or most of those patients'
  access logs stay silent about a real disclosure — same mechanism, correct granularity.
  See [[LOG]], D-169, D-170, D-177, D-178, D-179.

- **D-181 — A patient-facing surface is not a staff component with the labels changed; and one figure means
  ONE SOURCE, on the patient's side of the glass too (PT.P2).** Two rules, learned on the same gate.
  **(1) The portal header is a NEW component, not a stretched S1.** `PatientClinicalHeader` renders MRN,
  date of birth, sex and recorded allergies on a dark clinical tile — correct for a clinician identifying a
  patient, wrong for a patient reading their own record. They know who they are; their allergy list is not
  a page banner; and the portal's whole visual language is the lighter one. PC.P3 extended S1 rather than
  forking it and that was right **because both callers were staff surfaces**. Here the caller is the
  patient, so the honest move is a separate component in a portal namespace and S1 untouched. **The test
  asserts the absence** — the portal header may not render MRN/DOB/sex/allergies, and S1 may not learn
  about the portal.
  **(2) The reconcile-to-the-unit rule applies patient-side.** Portal Home took the open balance from the
  server while Portal Invoices `.reduce()`d its own from the rows it had been sent — and excluded credit
  notes, so with a credit note on the account **the two screens disagreed about what the patient owed**.
  Both now read one server reader that applies the ENGINE's rule (Σ the projection's open balances — the
  tie target `MetricsService::accountLedger()` asserts at δ=0), and the figure is formatted server-side so
  no portal template divides by 100. **The subtle part is WHY it must not be a sum of the rows:** the page
  filters the list, and the wireframe promises the balance stays the full total — a promise only keepable
  if the total was never derived from the rows in the first place. See [[LOG]], D-170, D-176.

- **D-182 — A guard test must fail for the RIGHT reason: give the refused path everything it needs except
  the thing under test (PT.P3).** The BRANCH.P1 assertion — "a soft-suspended branch offers no slots" —
  passed while the guard was DELETED. The suspended branch in my fixture had no resources, so the finder
  returned an empty list either way: the test proved nothing and would have kept proving nothing forever.
  Once the suspended branch was given fully bookable practitioner and room resources with availability, the
  ONLY thing standing between the patient and a slot was `accepts_online_bookings`, and removing the guard
  turned the suite red. **The rule generalises past this gate:** when asserting that X refuses something,
  build the fixture so that WITHOUT X it would succeed — otherwise the assertion is satisfied by the
  scenery. This is D-174's positive control pointed at a REFUSAL rather than at a scan: not "is there data
  to look at" but "would this have worked if the guard were gone".
  **A second, smaller line from the same gate:** the D-169 styling ban must distinguish IDENTITY from
  PROXIMITY. `selectedSlot?.starts_at === slot.starts_at` is selection state — which chip the patient
  clicked — and is ordinary UI. What stays forbidden is chrome keyed to HOW SOON something is: a time
  compared relationally, or against `now()`, or against a duration threshold. That is the shape every
  "turn it amber as it approaches" implementation must take, and it is what a patient must not be shown.
  See [[LOG]], D-169, D-174.

- **D-183 — Defence in depth is only real if each layer is pinned SEPARATELY; and an earlier guard can hide
  a later one from its own test (PT.P4).** D-182 said build a refusal fixture so that WITHOUT the guard it
  would succeed. This gate found two ways that is harder than it sounds, and both passed a mutation before
  being fixed.
  **(1) An EARLIER check short-circuits the one under test.** `assertPatientAccess()` refuses a foreign
  thread on the `patient_id` comparison before it ever reaches the `ThreadParticipant` membership check —
  so deleting membership entirely changed nothing, and the test kept passing. Pinning membership required a
  thread that IS the patient's own, with their participation row removed: only then is membership the last
  thing standing.
  **(2) The MIDDLEWARE hides the SERVICE's own re-check.** Over HTTP the `portal-consent` middleware refuses
  a withdrawn-consent send first, so deleting the service's identical check left the response unchanged.
  Proving the service re-checks required calling it DIRECTLY, with no middleware in front. **Both layers are
  wanted** — the middleware locks the portal, the service refuses even a caller that bypassed it — but a
  test that only exercises the outer one silently permits deleting the inner.
  **The rule: when a guard sits behind another guard, test it where nothing else can answer first** — a
  direct service call, or a fixture that satisfies every earlier condition. See [[LOG]], D-174, D-182.


- **D-184 — A stated consequence is a PROMISE the code must keep, carve-outs included; and where the
  product cannot state one, it must say so rather than borrow a reassurance (PT.P5).** D-176 banned the
  unbacked control. This is the next case along: the control is real, but the sentence describing what it
  does is itself a claim, and a claim can fail in two directions.
  **Over-claiming.** The patient-facing copy for the email consent nearly read *"we will never email you"* —
  which `NotificationService` does not honour: the LEGAL category is never consent-gated (D-F7), so
  statutory notices and dunning still go out by design. The copy therefore names the carve-out — *"Notices
  the practice must send you by law — such as an invoice reminder — are not affected"* — and a test asserts
  the dunning email STILL SENDS after the withdrawal. **The assertion that keeps copy honest is the one that
  proves the exception, not the rule.**
  **Under-claiming, or worse, guessing.** A consent the product has no copy for gets `copy_key = null` and
  the page prints *"We cannot describe here exactly what withdrawing this would change."* Reusing a generic
  reassurance would have been a fabrication wearing a helpful tone. Admitting the limit is the honest
  output, and the test proves it with a template whose scope nothing enforces.
  **The structural half:** a described consent must be an ENFORCED one. `PortalConsentsParityTest` fails if
  any scope named in the patient-facing copy is checked nowhere outside the tests — so copy and enforcement
  cannot drift apart silently. Note what this decided about the wireframe: three of the mock's five scopes
  (`documents.read`, `messages.write`, `research.share`) exist nowhere in CareOS, so they were NOT built
  (D-170). See [[LOG]], D-170, D-176, D-179.

- **D-185 — On a guest surface the refusals must be identical in SHAPE, not just in wording; the leak is
  usually a different exception, not a different sentence (PT.P6).** The invite landing page has one
  generic "this invitation is no longer valid" for four different states — unknown token, expired,
  already used, and bound to an account outside its own tenant — and writing that one sentence is the
  easy part. The two ways it nearly leaked were both structural:
  **(1) A different exception is a different answer.** `acceptInvite()` resolved the account with a bare
  `firstOrFail()`, so a cross-tenant token produced a **404** while every other dead token produced a
  validation refusal. Same page copy, entirely different response — and a prober measures responses, not
  prose. Making the binding raise the same "invalid invitation" refusal is what let all four cases become
  one.
  **(2) Echoing the input back distinguishes the cases for free.** The staff invite page passes the token
  into its invalid branch (`['token' => $token, 'valid' => false]`), so two refusals differ by exactly the
  token. It is a natural thing to write — which is why the mutation that adds it here is a realistic one,
  and why the test compares the four refusal BODIES for equality rather than checking that each says the
  right thing.
  **The test shape that catches both:** collect the four responses, strip only what the visitor supplied
  themselves (the URL) and what is per-session by nature (the CSRF token), and assert `array_unique()` has
  ONE element — for the status and the body alike. Asserting each case "shows the generic message" would
  have passed throughout.
  **The corollary for guest routes generally:** a route that can 404, 403, 422 or 200 depending on WHY it
  refused has an enumeration channel regardless of what it renders. See [[LOG]], D-174, D-182.

- **D-186 — `RefreshDatabase` resets the database and NOTHING resets the cache: any test asserting on
  a rate limit, lock or dedupe key is environment-dependent until it clears its own store
  (PT.P6-FIX).** PT.P6 shipped green locally and red on CI, and the feature was not at fault — the
  tests were.
  **The mechanism, which is easy to miss:** `phpunit.xml` sets `CACHE_STORE=array`, but a
  **non-forced `<env>` does not override a real environment variable**, and the CI workflow exports
  `CACHE_STORE: redis`. Locally the limiter bucket is an array store rebuilt per test; on CI it is one
  Redis key living for the whole run. A file making a dozen requests to a `throttle:10,1` route
  poisons its own later tests — on CI only. The same trap waits for cache-backed locks, dedupe keys
  and any cached read.
  **And the bucket is wider than the route:** Laravel's guest throttle signature is `sha1(domain|ip)`,
  **not the path**, so every `throttle:10,1` guest route shares ONE bucket per visitor. Requests made
  by a test for a completely different route count against yours.
  **The rule:** clear the store you assert on (`Cache::store(config('cache.limiter'))->flush()` for the
  limiter) in a `beforeEach`, or assert something order-independent. Clearing changes the STARTING
  POINT, not the assertion — the mutation that deletes the throttle must still turn the suite red, and
  here it does.
  **The meta-rule, now with two instances (see PT.P1-FIX):** when local is green and CI is red,
  suspect the ENVIRONMENT the suite runs in before suspecting the code — the JSON serialiser last
  time, the cache store this time — and REPRODUCE the CI condition locally before changing anything.
  Both times, reproducing it took one command. See [[LOG]], D-174.


- **D-187 — A mutation that changes nothing is not a passing test, and `TenantContext::system()` is one of
  them: it is a NO-OP while a tenant is in context (PT.P7).** Two tenant-binding mutations "survived" this
  gate. Only one was a real gap.
  **The genuine gap.** The first tenant-binding test asserted that a beta token resolves as beta even when
  the session says alpha — and an UNSCOPED account lookup passed it, because an account id is globally
  unique and resolves either way. The case it never exercised is the one where the token's tenant and its
  account's tenant DISAGREE; only a lookup scoped to the token's own tenant refuses that. A second test now
  forces exactly that row (the model's own guard refuses to create it, so it goes in at the DB level — the
  second layer is the one under test, D-183), and the mutation turns red.
  **The false alarm, which cost two runs.** Mutating the redemption lookup to `system()` also left the suite
  green — but that mutation removes NOTHING: `TenantScope::apply()` checks `$context->has()` FIRST and only
  falls through to system mode when no tenant is set. Since the redemption path sets the tenant FROM the
  token before the lookup, wrapping it in `system()` changes no SQL. (In `previewInvite`, where a guest
  arrives with no context at all, the same edit IS a real mutation — which is why PT.P6's equivalent went
  red. Same edit, opposite meaning, decided by whether a tenant is in context.)
  **The rule:** before concluding a guard is unpinned, prove the mutation actually mutated — log the branch,
  print the resolved row, or diff the SQL. A green suite under a no-op edit says nothing about the test.
  **And for tenant binding specifically:** mutate the SOURCE of the tenant (session instead of token), not
  the scoping of the query. That one turns red immediately, because it is the thing the binding actually is.
  See [[LOG]], D-182, D-183.

- **D-188 — A wireframe that assumes a different ARCHITECTURE is not a parity gap, and must not be
  reduced into one.** (Comms batch audit, 2026-08-25.)
  Three of the eight Comms wireframes (Consent-Blocked Draft · Opt-in Confirmed · Request Consent
  Update) rest on **per-topic, per-channel, per-household contact consent** and a **campaign/outreach
  send path**. Verified by query: CareOS has **two** consent templates mapping to **two** scopes
  (`portal.access`, `comms.email`); there is no household, client or campaign table; and only
  `EmailNotificationDriver` is wired, so the SMS shown on four screens fails closed twice. Outbound
  consent here is **one flag per patient, all-or-nothing for non-legal mail**, with the LEGAL
  carve-out never gated (D-F7, D-184).
  **The decision: those three screens are marked not-for-build against the current model** rather
  than entering the COMMS chain. The tempting move — ship a reduced version — is the worse one: a
  topic list that only ever holds one topic, or a channel matrix with one live column, is an
  **unbacked presence** (D-176) that teaches staff the practice can make promises to a patient that
  it cannot keep. The right form is a product decision with its own design, fence review and
  autonomy ceiling, not a chrome gate.
  **The general rule:** when a mock's premise is a different data model rather than missing pixels,
  say so and stop. D-170 forbids fabricating a backend; this is its companion — **do not fabricate a
  SHRUNKEN backend either**, because a half-built model reads as a whole one.
  See [[LOG]], D-170, D-176, D-184, `docs/wireframe-parity/COMMS-BATCH-DIFF.md`.

- **D-189 — A test whose fixture makes two different implementations agree is not testing the
  difference.** (COMMS.P1, 2026-08-25.)
  Twelve mutations, ten caught. The two that escaped were both defects in **my own fixture**, and
  both had the same shape: the data made the correct implementation and the wrong one produce
  *identical output*, so the assertion had nothing to discriminate on.
  1. **"Allergies are ordered by substance, never by severity."** The fixture recorded Aspirin as
     *mild* and Penicillin as *severe*. Alphabetically `mild` < `severe`, so ordering by substance
     and ordering by severity both yielded `[Aspirin, Penicillin]`. The mutation swapping the
     `orderBy` column changed nothing observable. Aspirin is now recorded *unknown*, which sorts
     after `severe` alphabetically and before it by any clinical reading — the two orderings now
     genuinely disagree.
  2. **"The count is the record's, not the visible page's."** The fixture held ONE thread, which was
     also the flagged one, so the capped list and the record-wide count were both `1`. A second,
     unflagged thread now makes the list longer than the count.
  **This is D-174 (the vacuous scan) in a sharper form.** D-174 was about a guard that scanned an
  empty subject. This is about a guard whose subject is non-empty but *degenerate*: it contains no
  case on which the two implementations differ. The positive control does not catch it either —
  the assertion passes, the data is real, and the test reads as thorough.
  **The rule:** when a test pins a CHOICE (this column not that one, this source not that one),
  the fixture must contain a case where the two choices give different answers — and the way to
  find out is to make the wrong choice and watch the test fail. **A green suite under a mutation is
  a statement about the fixture, not about the code.**
  See [[LOG]], D-174, D-182, D-183, D-187.

- **D-190 — A REQUEST to do something is not a record that it was done; and a gap you cannot close
  honestly is reported, not approximated.** (COMMS.P2, 2026-08-25.)
  Telehealth has a `telehealth_participants` table with an append-only join/leave proof row, and
  `TelehealthService::recordJoin()`/`recordLeave()` to write it. **Nothing in the live application
  calls either** — only tests, and now the demo seeder. So in production the table stays empty and
  the sessions list can never show a join.
  **The tempting fix was to call `recordJoin()` from the token endpoint.** It is one line, it makes
  the table fill up, and it is wrong: a join token is minted *before* anyone connects. A person can
  request one and never join — the network fails, they change their mind, they close the tab.
  Recording a join at that moment writes a fact that may never have happened (D-179), into an
  **append-only** table that by design can never be corrected.
  **So the gap stays open and is stated instead.** The surface shows what IS recorded (joins, where
  they exist; token issuance, in the audit trail), says plainly what is NOT (live presence, and that
  a dropped connection records no leave), and the missing callback is written down as the real fix:
  the client reporting that it actually connected — a new capability, not a parity gate.
  **The general rule:** when a store is empty because nothing writes it, find out WHY before filling
  it. If the only available write point observes a *request* rather than the *event*, filling the
  store makes the record worse, not better — an empty table is honestly empty, whereas a table full
  of inferred events is a record that lies. This is the append-only corollary of D-179 and the same
  instinct as the "seeding a state must drive its real path" rule: **if you cannot observe the event,
  do not write a row about it.**
  See [[LOG]], [[Comms]], D-176, D-179, `docs/wireframe-parity/COMMS-BATCH-DIFF.md` §15.

- **D-191 — An undocumented ordering column is a fence hole waiting for a label.** (Scheduling batch
  audit, 2026-08-29.)
  `WaitlistEntry.priority` is a bare `int`, default 0, with no docblock and no stated semantics, and
  `WaitlistService::matchingForSlot()` orders by it **first**, ahead of wait time. Nothing in the
  code says what it means. Nothing enforces that it is operational.
  **That is safe only while no screen exists.** The Waitlist wireframe wants to label it
  `Priority: Normal / Urgent · clinical` and to route "urgent or clinical" cases differently — and
  the moment a UI writes that label, a clinical judgment has entered a scheduling ranking through a
  column that was never designed to carry one. The electric fence would be breached not by new code
  but by a caption over old code.
  **The rule:** before any UI writes to an ordering or classification column, that column must have
  a **written, enforced meaning** — a docblock stating what it ranks and, where the distinction
  matters, a constrained set of values rather than a free integer. "Operational by convention" is
  not a fence; it is an absence of one.
  **The general shape:** a fence is usually thought of as a guard that refuses something. This is the
  other kind — a *definition* that makes the wrong use unsayable. An unnamed number invites whatever
  meaning the next screen needs, and screens are written by people who did not read the migration.
  See [[LOG]], [[Scheduling]], D-169, D-188, `docs/wireframe-parity/SCHEDULING-BATCH-DIFF.md` §4.2.

- **D-192 — Storage is UTC from every path; tenant-local time is a DISPLAY concern resolved at the
  presentation boundary, never a process-wide mutation (QA-FIX.1a, closing P1-C1).**
  `ApplyTenantLocaleTimezone` called `date_default_timezone_set($tenantZone)` on every authenticated
  web request. `now()` then returned a Carbon in the practice's zone and Eloquent serialised that
  **wall clock** verbatim, so every datetime written during a web request was stored as local time in
  a column every other consumer reads as UTC — while CLI, queue and scheduler writes stayed true UTC.
  One column, two time bases. Measured on a Europe/Zurich tenant: `appointments.created_at`,
  `messages.created_at` and the append-only hash-chained `audit_events.occurred_at` all **+2 h**.
  **THE DOCBLOCK IS HOW IT SURVIVED REVIEW.** Lines 16-21 asserted the opposite of what the code did —
  *"it does NOT touch `config('app.timezone')`, so Eloquent keeps serialising timestamps in UTC (stored
  data is unchanged)"*. That sentence is false: `config('app.timezone')` governs the framework default,
  but `now()` reads PHP's **process** default, which the middleware had just changed. D-095 records the
  same wrong reasoning, and both are corrected here. A comment asserting a safety property is a claim
  like any other — it needs a test, or it is just a confident sentence next to a defect.
  **THE FIX — two separable concerns, separated.** (1) The mutation is **removed**; the middleware now
  applies the tenant LOCALE only. Storage is UTC from web, CLI, queue and scheduler alike. (2) The
  display contract is preserved by resolving the tenant's zone **explicitly** at the presentation
  boundary: the new `App\Services\DisplayTimezone::forCurrentTenant()` reads the tenant setting (falling
  back to `config('app.timezone')` for guests, unset tenants and unknown identifiers), and
  `HandleInertiaRequests` shares it as the same `timezone` prop it always shared. The prop's VALUE is
  byte-identical; only its provenance changed — it used to be `date_default_timezone_get()`, which only
  had a tenant value because something had mutated the process.
  **WHAT THE MUTATION WAS ACTUALLY BUYING: almost nothing.** A repo-wide sweep found exactly TWO sites
  touching the process zone — the mutation itself and that one prop read. No model overrides
  `serializeDate`/`$dateFormat`; server-side datetime formatting is essentially absent; and
  `VisitPlanGenerator` / `AppointmentSeriesService` carry their own explicit zones for RRULE/DST work,
  so they never depended on the default. **No frontend component consumes the shared `timezone` prop
  for rendering today** — per-widget tenant-local display remains the standing deferred item. The
  mutation therefore cost a CRITICAL data defect and fed a prop nothing reads yet.
  **IT ALSO CORRUPTED READS, and a prior gate had already met that half.**
  `DayBoardController::appointmentPayload()` reads `getRawOriginal('checked_in_at')` and parses it as
  UTC precisely because the middleware "RE-LABELS the UTC-stored string as Zurich, shifting it two
  hours" (its own words). SCHED.P1 diagnosed the read symptom and worked around it locally without
  seeing that the same mechanism was corrupting writes. That workaround is left in place: reading the
  raw column and parsing it as UTC is correct either way, and removing it is not this gate's business.
  **NOTHING WAS WEAKENED.** The append-only guards, the DB triggers, the hash chain, `AuditService`'s
  strictly-monotonic `occurred_at` (the GOV.P4 `prevTime + 1µs` clamp) and the P0P.G15 `dateTime()`
  convention are all untouched. `audit:verify-chains` returns `CHAIN:OK` for all four demo tenants
  after web writes.
  **THE MONOTONIC CLAMP IS WHY THIS FIX IS SAFE TO DEPLOY ONTO SKEWED DATA.** On a tenant that already
  holds +2 h rows, correcting `now()` makes the next web-written audit row EARLIER than the last stored
  one. `AuditService::record()` clamps it to `prevTime + 1µs` rather than writing it out of order, so
  the chain's stored order keeps matching its hash-link order and `verifyChain()` keeps passing. The
  cost is that for a window equal to the tenant's offset, new `occurred_at` values are monotonic but
  not true — they trail real UTC until the wall clock passes the highest skewed value, then self-heal.
  Integrity is preserved in preference to timestamp accuracy, which is the right trade for a ledger.
  **Guarded by** `tests/Feature/Platform/TimezoneStorageParityTest.php` (9). Every test uses a
  **Europe/Zurich** tenant and asserts the offset is non-zero first — on a UTC tenant every assertion
  would pass regardless of the code, the vacuity D-174 warns about. Mutation-checked: reintroducing
  `date_default_timezone_set()` turns **4** of them red (web-row-is-true-UTC, web-vs-CLI-same-base,
  no-process-mutation, and the TTL agreement). The other five are deliberate "do not weaken" invariants
  that hold in both worlds. The TTL test mints its token **over HTTP** rather than by calling the
  service — a direct service call never meets request middleware, so the first draft passed under the
  mutation and proved nothing.
  **⚠️ ONE KNOWN CONSEQUENCE, STATED RATHER THAN SHIPPED SILENTLY — `starts_at` IS A SECOND, SEPARATE
  TIME BASE.** `appointments.starts_at` holds the practice's **naive local wall clock** (it is derived
  from a date plus an opening-hour offset, never from `now()`, so its digits are zone-invariant). Six
  comparisons of the shape `where('starts_at', '>=', now())` therefore compared local digits against
  whatever base `now()` had: on the old WEB path `now()` was also local, so they lined up **by
  accident**; on CLI they were already wrong. Making `now()` UTC everywhere makes those six *uniformly*
  wrong instead of *inconsistently* wrong — `now()` reads one offset EARLIER than the practice's clock,
  so "upcoming" over-includes by up to the offset. Sites: `app/Services/BranchService.php:132`,
  `app/Services/ResourceService.php:46`, `app/Http/Controllers/Portal/PortalHomeController.php:31,69`,
  `Modules/Comms/src/Services/InboxPatientContextReader.php:145`,
  `app/AiCore/Support/InboxDraftEngine.php:207`, and the cancel window at
  `Modules/Scheduling/src/Http/Controllers/PortalAppointmentController.php:159`. **Direction of the
  error:** the two deactivation guards become MORE conservative (they block while a just-past
  appointment still counts as future), the "next appointment" readers may name an appointment that
  started up to an offset ago, and the portal cancel window becomes correspondingly more permissive —
  the only one that loosens a rule. **NOT fixed here, deliberately:** the honest repair is to give
  `starts_at` a single declared base (a data migration with its own risk profile), not to sprinkle
  zone conversions across six untested call sites inside a gate scoped to the storage base. No test
  covers any of the six. Recorded in `DEFERRED.md` with its trigger so it is decidable rather than
  discovered. See [[Platform]], [[Scheduling]], `docs/qa/ROLE-AUDIT.md` (P1-C1), D-095 (corrected),
  D-066, D-091, D-191 (an undocumented column invites the wrong meaning — the same shape), [[LOG]].

- **D-193 — The historical +2 h skew is RECORDED, not rewritten (QA-FIX.1a).**
  Rows written by web requests before D-192 carry the tenant's local wall clock in UTC columns. They are
  **deliberately left as they are.** A bulk correction is a data migration over append-only, DB-trigger-
  protected, hash-chained tables, and it cannot be done honestly: the offset that applied to any given
  row depends on the tenant's zone AND the DST state on that date, the rows carry no marker saying which
  base they used, and rewriting `audit_events.occurred_at` would either break `verifyChain()` or require
  re-hashing the chain — which is precisely the capability an append-only ledger exists to deny.
  **THE SCOPE, so it is decidable rather than silent — and it is NARROWER than "every web write".**
  Affected: any tenant whose `timezone` setting is not UTC (of the demo set, all four are Europe/Zurich),
  and only rows written by an authenticated **STAFF** web request. `ApplyTenantLocaleTimezone` is
  appended to the **web group only** (`bootstrap/app.php:43-48`); the **api group never had it**
  (`:50-53`); and **PORTAL requests self-skipped**, because portal tenant context comes from the
  route-level `portal-tenant` alias (`routes/web.php:917`) which runs AFTER group middleware, so
  `TenantContext` was still unset when the mutation ran and its `has()` guard declined. A remediation
  that assumes "every web-era row is +2h" would therefore OVER-CORRECT portal- and API-written rows.
  Affected columns: notably `audit_events.occurred_at`, `messages.created_at`,
  `appointments.created_at`/`status_changed_at`, and any `expires_at` minted on a staff web path.
  Window: from whenever `ApplyTenantLocaleTimezone` began setting the zone (CLINIC.W8b, D-095) until
  QA-FIX.1a.
  **PRACTICAL CONSEQUENCES that remain true of the old rows:** they sort late relative to CLI-written
  rows; a web-minted TTL read by a CLI sweeper looks longer-lived by the offset (the waitlist-offer
  expiry sweep is the concrete instance); and any report bucketing by hour or day boundary may place an
  old row in the adjacent bucket.
  **AND ONE DISPLAY CONSEQUENCE, worth stating because it will be noticed first:** a legacy staff-web
  row now HYDRATES as UTC (correctly, per D-192) but its digits are still local, so any surface that
  emits `toIso8601String()` re-dates it by the offset — a pre-fix audit row will render an offset LATE
  in the browser. New rows are correct; only history reads late. This is the visible face of the same
  decision and is not a new defect.
  **TWO SHORT, SELF-HEALING TRANSIENTS AT DEPLOY, so nobody reads them as tampering:** (1) for up to
  one offset after the change, `AuditService`'s monotonic clamp stamps new rows `prevTime + 1µs`
  instead of real UTC, producing a dense microsecond cluster around the cutover that `verifyChain()`
  correctly certifies; (2) a legacy waitlist offer stays acceptable for roughly one offset longer than
  its TTL, because its `expires_at` is still local while `now()` is now UTC. Both drain on their own.
  **NO CUSTOMER IS LIVE**, so the affected data is demo and pilot data only — which is exactly why this
  is cheap to decide now and expensive to decide later. **OPEN DECISION for the product owner:** leave
  history as-is (recommended — it is internally consistent per-path and the ledger stays untouched), or
  fund a scoped, per-tenant, per-table correction with its own gate, its own audit trail and a marker
  column recording which base each row used. Do not let a future session "tidy" these rows silently.
  See [[Platform]], `docs/qa/ROLE-AUDIT.md` (P1-C1), D-192, [[LOG]].

- **D-194 — A booking may not start in the past; the FINDER not offering a slot and the SERVER refusing
  it are two different guarantees, and both are now built (QA-FIX.1b, closing P1-H3).**
  The audit reproduced this in a browser: at 22:21 local the reschedule panel offered TODAY at 08:00
  — labelled **"soonest"** — and confirming it created a real appointment `starts_at 08:00`, status
  `booked`, **742 minutes in the past**, with no warning. `AvailableSlotFinder` walked from the
  branch's opening time to its closing time with **no reference to the clock at all**, and
  `BookingService` had **no past-start guard**, so nothing anywhere refused it.
  **TWO LAYERS, because they answer different questions.** (1) The FINDER no longer offers a slot
  that has already started, so every consumer inherits it — day-board quick-book, staff reschedule,
  portal self-booking and the public form. (2) The BOOKING FUNNEL (`createBooking`, which
  `book()` and `bookOnline()` both delegate to) refuses one anyway. The second is not redundant: the
  finder not offering something is a UI fact, while a stale tab or a forged POST reaches the service
  directly. Pinned in the funnel means a direct service call meets it with nothing else answering
  first (D-183). Proven by mutation: neutralising the finder turns 2 tests red and leaves the 8
  booking tests green; neutralising the guard turns 5 red — the layers fail independently.
  **THE BOUNDARY IS STRICTLY "HAS ALREADY STARTED", NOT "starts within N minutes."** SCHED.P2
  established there is no min-notice setting anywhere in the product, so a notice window would be an
  invented policy the backend cannot honour (D-170). A slot at `now()` exactly is refused; anything
  later is offered and bookable.
  **BOOKING vs RECORDING — the distinction the guard had to make.** Recording an appointment that
  already happened is legitimate: both demo seeders build a real historical week through these same
  methods, and a practice may enter a visit it forgot. So `$allowPastStart` is a **CALL-SITE
  CONSTANT** — never read from a request, so nothing a client sends can relax it. Its DEFAULT
  DIFFERS BY METHOD, deliberately: `bookOnline()` defaults to **refusing** (every request reaching
  it is a person choosing a slot, and neither the portal nor the public controller passes the
  argument, so they are strict without having to remember), while `book()` defaults to **allowing**
  because it is also the repo's historical-recording path — both seeders and a large number of
  fixtures book at fixed dates that have since elapsed. The four interactive callers pass `false`
  explicitly: day-board quick-book, `AppointmentService::reschedule()` (via
  `AppointmentDetailController` — the path the audit reproduced), and the portal and public forms by
  inheriting `bookOnline()`'s default.
  **THE RESIDUAL, STATED:** `book()` being permissive by default means a FUTURE interactive caller
  could forget the flag. The alternative was to break 13 existing behaviour tests and both seeders,
  which the gate forbade. Mitigations: the finder means a legitimate UI cannot produce a past slot in
  the first place, and the test file enumerates the interactive entry points and asserts each refuses.
  **WAITLIST-ACCEPT AND RECURRING SERIES ARE DELIBERATELY NOT STRICT** — they are outside the four
  paths in scope, and both have fixtures that legitimately book historical slots. A waitlist offer
  has a ~30-minute TTL, so the exposure is narrow; making them strict is a follow-up decision, not a
  silent omission.
  **A DESIGN THAT LOOKED RIGHT AND WAS WRONG, recorded because the next person will try it.** The
  first implementation anchored the scan date in the branch's zone so the cursor became a real
  instant. It type-checked, read well, and **silently moved the first offered slot from 07:00 to
  09:00 on a Europe/Zurich branch** — because `resource_availability` windows are naive local times
  compared in the process zone, so a zone-anchored cursor de-synchronised from them. Twenty-four
  tests went red and an empirical probe of the finder's actual output found it. The correct shape is
  the opposite: leave the cursor naive and convert the CLOCK into the branch's zone, re-read as naive
  digits (`nowInBranchClock()`), so both sides of the comparison sit in the practice's clock. This is
  the same `starts_at`-is-a-naive-local-wall-clock fact D-192 recorded, met from the other side.
  **REFUSALS ARE ANSWERS, NOT CRASHES.** `DayBoardActionController`, `PortalAppointmentController`
  and `PublicBookingController` did not catch `BookingUnavailableException`, so the new refusal
  surfaced as **HTTP 500** — the C-1 class the FIX.5 route smoke exists to prevent, and on the public
  form it would have met an anonymous visitor. All three now redirect back with a field error. On the
  public path the whole attempt rolls back, so a refused booking also leaves no half-created patient.
  **Guarded by** `tests/Feature/Scheduling/PastSlotGuardTest.php` (10), clock-frozen LATE IN THE DAY
  so each refusal test would SUCCEED without its guard (D-182) and with an explicit positive control
  that the afternoon IS still offered (D-174) — a finder returning nothing would otherwise pass every
  absence assertion. One test pins that the comparison uses the BRANCH clock, not the server's: at
  06:00 UTC a Zurich branch has lost its 07:00 slot while a UTC branch has not.
  See [[Scheduling]], `docs/qa/ROLE-AUDIT.md` (P1-H3), D-192 (the same naive-local fact),
  D-170 (no invented policy), D-182/D-183 (the test shapes), D-031 (online rows keep
  `booked_by = null`), [[LOG]].

- **D-195 — A clinical note is authored by the clinician who WROTE it; the encounter keeps the
  clinician the visit is WITH; and the rendered signature names the SIGNATORY (QA-FIX.2a, closing
  P2-C1).**
  Phase 2 measured this in a browser: logged in as Dr. Brunner, clicking **Document** on an
  appointment booked with Dr. Keller and signing the note produced `author_id` = **Keller**,
  `signed_by` = **Brunner**, audit `actor_id` = **Brunner** — and the screen said
  **"Signed · Dr. med. Sofia Keller"**. Brunner wrote every word. The medico-legal record named
  someone else, and the truth survived only in the audit chain, which is not what a clinician reads.
  **THE CAUSE WAS ONE ARGUMENT.** `OpenEncounterFromAppointmentController` resolved the
  appointment's practitioner once and passed it to BOTH calls: to `EncounterService::open()` (right)
  and to `ClinicalNoteService::saveDraft()` (wrong), where it becomes `author_id`
  (`ClinicalNoteService:69`).
  **TWO DIFFERENT QUESTIONS, NOW ANSWERED SEPARATELY.** *"Whose visit is this?"* is the ENCOUNTER,
  and the answer is legitimately the booked clinician — an encounter is the appointment made real,
  so **the encounter is deliberately UNCHANGED** and a test asserts it still equals the
  appointment's practitioner, proving the fix stayed surgical. *"Who wrote this down?"* is the NOTE,
  and the answer is the authenticated user. Only the note moved.
  **THE SAME PRINCIPLE FIXED THE AMENDMENT PATH.** `NoteEditorController::amend()` passed
  `authorFor($record)` — the SUPERSEDED version's author — so a correction written by Dr. B was
  recorded as Dr. A's work. An amendment is a new version, and its author is whoever wrote that
  version; the original keeps its own author, which is what the chain is for.
  **REFUSE, DO NOT GUESS.** `StaffProfile::forUser()` is the one place that answers "who is acting?",
  and it returns **null** rather than falling back to an arbitrary profile. A caller that cannot
  identify the actor refuses to write the note. Guessing an author is the defect itself, and the
  repo already contained a fallback of exactly that shape (`ImagingReportController:160` falls back
  to the first profile by display name) — it was not copied.
  **THE SIGNATURE NAMES THE SIGNATORY.** `NoteEditor.vue` rendered `author_name` under a
  "Signed ·" label. It now renders `signed_by_name`, resolved from `signed_by`. **Author and
  signatory can legitimately differ** — one clinician drafts, another signs — and that is not
  hypothetical: the seeded radiology reports are authored by Dr. Lang and signed by Dr. Berg. So
  when they differ the view names **both, distinctly** ("Written by X · Signed by Y · date") rather
  than letting one stand in for the other. Comparing them crosses the namespace split (D-196):
  the author's `staff_profiles.user_id` against the note's `signed_by`.
  **WHAT THIS ALSO REPAIRED, unnoticed until the study:** two features filter notes by `author_id`
  meaning "mine". `UnsignedNotesWorklist:24-29` builds "my unsigned notes" from the actor's staff
  profiles — so a note Brunner wrote sat in **Keller's** worklist. And
  `ClinicalSummaryInsertController:55-65` looks for "the draft authored by the current clinician",
  which for a Document-created note **could never match**, silently breaking summary-insert on that
  path. Both become correct as a consequence, neither was touched.
  **HISTORICAL ROWS ARE NOT REWRITTEN.** `ClinicalNote::updating` throws
  *"Signed clinical notes are immutable."* and `deleting` blocks signed notes, so a correction to a
  signed historical note cannot go through the model at all — it would need raw SQL against a
  versioned, append-only clinical record. Measured scope at the time of the fix: **24 notes across
  four demo tenants**, earliest `2026-08-25`, of which **2** carry an author ≠ signatory (both the
  legitimate radiology shape). No customer is live. See D-197 for the recorded decision.
  **PATHS DELIBERATELY NOT CHANGED, and why.** `BedsideChartService:66,77` (author =
  `stay->admitting_clinician_id`) and `SurgicalCaseService:166,178` (author =
  `case->primary_surgeon_id`) derive the author from domain data the same way, so they share the
  shape. They are OUTSIDE the surface Phase 2 audited, belong to phases 6 and 9, and could not be
  browser-verified in this gate — changing them unverified would be the opposite of what this audit
  is for. Recorded in `DEFERRED.md` with a trigger. `EdDocumentationService` is different: its
  practitioner is a **user-chosen** `practitioner_id` from the request, an explicit assignment, not
  a silent inference. `RadiologyReportService` via `ImagingReportController:160` was **already
  actor-derived** and is the pattern this fix follows.
  **Guarded by** `tests/Feature/Clinical/NoteAuthorshipTest.php` (8). Every fixture makes the actor
  and the appointment's practitioner **different people and asserts that they are** (D-174) —
  the pre-existing `ClinicalUiTest` fixtures use `d7Practitioner($branch, $doctor)`, so A == B and
  they passed either way, which is exactly why this defect survived. Mutation-checked: restoring the
  old authorship turns **4** red; restoring the old amendment inheritance turns the amendment test
  red on its own.
  See [[Clinical]], `docs/qa/ROLE-AUDIT.md` (P2-C1), D-196 (the namespace split), D-197 (history not
  rewritten), D-174 (positive controls), D-179 (an asserted action never taken), [[LOG]].

- **D-196 — `author_id` and `signed_by` name people in two different namespaces, and this gate did
  not unify them (QA-FIX.2a).**
  On one `clinical_notes` row: `author_id` is a **ULID** foreign key to `staff_profiles.id`
  (`2026_07_09_000003_create_clinical_notes_table.php:17,34`, `restrictOnDelete`), while `signed_by`
  is an **integer** `users.id` (`ClinicalNoteService:100`). Elsewhere in the clinical tables,
  `tooth_records.charted_by` and `orders.ordered_by` are also `users.id`. So "who" is asked in two
  languages, and `author_id` is the odd one out.
  **NOT UNIFIED HERE, DELIBERATELY.** Making them agree is a schema change: a migration over a
  versioned, append-only clinical table with a live FK, plus every reader and writer of both columns.
  That is its own gate with its own risk, and doing it inside a defect fix would have made the fix
  unreviewable.
  **WHAT THIS GATE DID INSTEAD:** it did not deepen the split. No new column and no third namespace
  were introduced. The one place that must compare the two — "is the signatory the same person as
  the author?" — crosses the split explicitly and in one method
  (`NoteEditorController::signatoryIsAuthor()`), comparing the author's `staff_profiles.user_id`
  against `signed_by`, with the reason written down at the call site.
  **THE RISK IF LEFT:** every future consumer must know which column speaks which language, and the
  Phase-2 audit already produced one wrong answer from exactly this confusion — an early query
  compared a ULID against the integer `users.id` column, and MySQL's non-strict coercion silently
  resolved `'01m1s7me…'` to user 1, "Test User". A reader who trusts the column names gets a
  plausible, wrong person.
  See [[Clinical]], D-195, `docs/qa/ROLE-AUDIT.md` (P2-C1 sub-finding), [[DEFERRED]], [[LOG]].

- **D-197 — Notes already written under the old attribution are RECORDED, NOT REWRITTEN
  (QA-FIX.2a).**
  Every note created through the day-board **Document** button before `QA-FIX.2a` carries the
  APPOINTMENT'S practitioner as its author rather than the clinician who typed it. Amendments made
  before the fix carry the superseded version's author rather than the amender's.
  **SCOPE, MEASURED:** 24 notes across the four demo tenants, earliest `2026-08-25`; 2 of them show
  author ≠ signatory, and both are the legitimate radiology shape rather than this defect. The
  Document path is the only one that produced the wrong attribution, and only for appointments whose
  practitioner is not the person documenting — where they are the same person, which is the common
  single-handed-practice case, the stored value was already right.
  **WHY A BULK CORRECTION IS NOT SAFE:** `ClinicalNote::updating` refuses any change to a signed
  note ("Signed clinical notes are immutable") and `deleting` refuses to remove one, so a correction
  cannot go through the model. Raw SQL would bypass the immutability guard on a versioned clinical
  record, and — worse — **the true author is not recoverable from the note row itself**. It is
  recoverable only by joining the audit chain (`encounter.opened` / `note.signed` carry the real
  `actor_id`), which is a reconstruction, not a fact the record holds. Rewriting a signature-bearing
  clinical row from a reconstruction is not something to do quietly.
  **NO CUSTOMER IS LIVE**, so this is cheap to decide now and expensive to decide later.
  **OPEN DECISION for the product owner:** leave history as it stands (recommended — it is
  internally consistent, the audit chain holds the truth, and the immutability guard stays intact),
  or fund a scoped, per-tenant correction with its own gate, its own audit trail, and a column
  recording that the row was corrected and from what evidence. Do not let a future session quietly
  "tidy" these rows.
  See [[Clinical]], D-195, D-193 (the same shape for the clock skew), `docs/qa/ROLE-AUDIT.md`
  (P2-C1), [[DEFERRED]], [[LOG]].

- **D-198 — Documentation is not attendance: opening a note starts the visit only when the patient is
  already recorded as having arrived (QA-FIX.2b, closing P2-H1).**
  The Phase-2 audit clicked **Document** once, to write a note, and the audit trail showed
  **`appointment.confirmed` → `appointment.arrived` → `appointment.in_progress`** firing at the same
  instant, moving the appointment `booked → in_progress` — while `checked_in_at` and
  `check_in_source` stayed **NULL**. The record asserted that a patient had attended on the strength
  of a clinician opening an editor. No confirmation, no warning, no undo. **D-179: an asserted action
  never taken.**
  **THE COMPOSE WAS REAL AND DELIBERATE, WHICH IS WHY IT SURVIVED.** `EncounterService`'s
  `moveAppointmentToInProgress()` walked every intermediate state so that an encounter could always
  be opened, and each hop was a legal edge through `AppointmentService::transition()`. Nothing was
  bypassed; the machine was used exactly as designed. The defect was in the MEANING, not the
  mechanics — the code answered "how do I get this appointment to in_progress?" when the question
  should have been "has anyone actually said this patient is here?"
  **THE DESIGN CHOSEN — the gate's option (c), transition only where it is unambiguous.**
  `arrived → in_progress` is still composed and is the one honest case: the patient is already
  recorded present, so a clinician opening their note IS the visit starting. From `booked` or
  `confirmed`, **nothing is transitioned at all** — the encounter and the note are still created, so
  documentation is never blocked, and the appointment keeps the status it earned.
  **WHY NO CONFIRMATION PROMPT WAS ADDED.** The gate allowed one, but a flag no surface sends would
  be an unbacked presence (D-176), and building a modal would add a control that duplicates one
  already sitting **directly beside Document on the same row**: the day-board's **Arrive** button,
  which exists precisely to say "the patient is here". Reception or the clinician presses it, and
  then Document starts the visit. The honest affordance already existed; the fix stopped talking over
  it.
  **THE D-156 COMPOSE IS UNTOUCHED, and the distinction is the whole point.**
  `DayBoardActionController:35-38` still walks `confirm() → arrive()` for a booked appointment. That
  compose is legitimate *because a human pressed a button whose meaning is the arrival*. The
  documentation path had no such meaning behind it. Two composes, one sanctioned and one not, and
  what separates them is what the click asserts — a test pins the day-board path still composing both
  edges.
  **`checked_in_at` IS UNTOUCHED and always was.** It is written only by a real check-in
  (`Modules\FrontDesk\Services\CheckInService:83`), which is why the audit found `arrived` with a
  NULL timestamp in the first place. This fix does not write it, and now nothing on the documentation
  path moves the status to one that implies it. **The separate `P1-M1` gap — the day-board's own
  Arrive button setting `status = arrived` without writing `checked_in_at`, so desk arrivals stay
  invisible to `MetricsService::checkedInCount` — is NOT addressed here and remains open**; it is a
  front-desk-surface decision, and this gate deliberately did not widen into it.
  **WHAT IT WAS INFLATING:** `AppLandingController:40` counts `status = arrived` as "waiting", and
  the day-board's "Checked in" tile does the same. Every Document click on a booked appointment added
  a patient to both. `MetricsService::checkedInCount` filters on `checked_in_at` and so was never
  fooled — which is exactly the two-screens-disagree shape `P1-M1` recorded.
  **Guarded by** `tests/Feature/Clinical/DocumentationAttendanceTest.php` (7), every test starting
  from a BOOKED appointment — the state that used to be swept away — so without the fix the old code
  **succeeds** at reaching `in_progress` and the assertions are measuring the guard (D-182).
  Mutation-checked: restoring the compose reddens the **3** guard tests while the **4** controls
  (note still created, arrived-case still starts, in-progress no-op, D-156 compose intact) stay green
  — proving the suite is not passing by everything-fails.
  **THREE EXISTING TESTS ASSERTED THE OLD BEHAVIOUR AND ARE CORRECTED, NOT WEAKENED** — see [[LOG]]
  for what each had been asserting.
  **THE DEMO SEEDER HAD TO LEARN THE SAME LESSON, WHICH IS EVIDENCE THE FIX BITES.**
  `DemoClinicSeeder::seedLiveConsult()` — whose docblock calls it "the honest consult loop, run for
  real" — booked an appointment and relied on this compose to reach `in_progress`. Afterwards it did
  not, and `DemoClinicSeederTest` failed with "Failed asserting that an array contains 'in_progress'"
  — the ONLY failure in a 1535-test run. The seeder now walks the patient to ARRIVED first, through
  `AppointmentService` exactly as reception does. The demo week became MORE honest, not less: the
  loop it claims to demonstrate now contains the arrival it always implied. `DemoSpitexSeeder` passes
  a null appointment and was unaffected.
  See [[Clinical]], [[Scheduling]], `docs/qa/ROLE-AUDIT.md` (P2-H1), D-156 (the sanctioned compose),
  D-179 (asserted action never taken), D-182 (the test shape), P1-M1 (the same shape, still open),
  [[LOG]].

- **D-199 — A refused money operation leaves nothing behind: record + allocate is ONE transaction
  (QA-FIX.3a, closing P3-C1).**
  The Phase-3 audit entered CHF 500.00 against an invoice with CHF 169.61 open, was told
  **"Cannot allocate more than the invoice open balance"**, watched the balance and the ledger stay
  untouched — and found a **CHF 500.00 payment on the account anyway**, visible on
  `/billing/payments` as received money. Two forged POSTs added two more: **CHF 1'010.00 that was
  never received** sat on one account, created by operations the product had reported as failures.
  **THE CAUSE WAS A MISSING TRANSACTION, NOT A MISSING GUARD.** The guard was never the problem —
  `PaymentService::allocate()` refused correctly every time, in its own `DB::transaction(..., 5)`.
  The controller composed two service calls with nothing around them:
  `AccountDetailController:182` committed the payment, `:195-204` allocated afterwards and returned
  the guard's message. The in-code comment — *"Nothing was posted for this line"* — was true of the
  **line** and false about the **operation**.
  **A ROLLBACK IS THE ONLY AVAILABLE FIX.** `Payment` is append-only at the model level
  (`static::updating` and `static::deleting` both throw `LogicException`), so a compensating delete
  would itself be refused. There is no way to tidy up after the fact; the write must never happen.
  **THE CORRECT SHAPE ALREADY EXISTED IN THE SAME FILE.** The payment-plan path refuses cleanly and
  leaves no orphan, because `PaymentPlanService::create()` wraps its **whole** operation in
  `DB::transaction` — the controller merely catches and redirects. The fix matches that discipline:
  one operation, one transaction, the guard's exception propagating out of it.
  **THE LEGITIMATE UNALLOCATED PAYMENT IS PRESERVED, AND THE DISTINCTION IS STRUCTURAL rather than a
  flag.** With no allocation lines, `$targets` is empty, `allocate()` is never called, nothing throws
  and the transaction commits — money received today and applied tomorrow, or an overpayment whose
  remainder stays unallocated, both still work exactly as before. Only an allocation that is
  **attempted and refused** unwinds the payment with it. Two positive-control tests pin each case.
  **THE AUDIT ROW ROLLS BACK WITH IT, DELIBERATELY.** `PaymentService::record()` writes
  `payment.recorded` inline and `AuditService::record()` runs on the **same connection**, so the audit
  append becomes a savepoint inside the outer transaction and vanishes on rollback. That is the
  outcome we want: the ledger cannot claim a payment that does not exist. **The hash chain stays
  gapless** because the append takes `FOR UPDATE` on the tenant's latest row and the next append
  re-reads the unchanged *committed* tail — verified in a test that asserts `verifyChain()->ok` after
  a rolled-back append. The only cost is that the audit's row lock is now held for the outer
  transaction's lifetime rather than its own; `allocate()` already holds row locks over that window.
  **A NESTED-TRANSACTION NOTE for the next reader:** `allocate()`'s own `DB::transaction(..., 5)`
  becomes a savepoint under the outer one. Its 5 attempts are Laravel's *deadlock* retry and do not
  re-run an `InvalidArgumentException`, so a refused guard propagates immediately rather than being
  retried five times.
  **THE SIBLING PATH WAS DELIBERATELY NOT CHANGED, and the reason is disclosure, not mechanics.**
  `PaymentController::store()` (`:134,150`) composes the same pair without a transaction, but it is a
  different intent: its comment states it plainly — *"The payment is already recorded (money WAS
  received) even if the allocation is refused, so a bad allocation surfaces as an error on the payment
  detail rather than losing the receipt"* — and it **redirects to `billing.payments.show`**, landing
  the operator on the payment it kept, with the allocation error beside it. That is a coherent desk
  workflow: cash arrived, record it, fix the allocation next. The AR-account path did the opposite,
  redirecting back to the account with only an error, so the payment was **hidden**. Same service,
  same mechanics, opposite honesty. Recorded rather than widened — see `DEFERRED.md` for the residual.
  **Guarded by** six ADDED tests in `tests/Feature/Billing/AccountRecordPaymentTest.php`, three of
  them D-182-shaped (they assert the ABSENCE of a row the pre-fix code SUCCEEDS at creating) and
  three positive controls proving the fix is a rollback and not a block. Mutation-checked: replacing
  `DB::transaction(...)` with a plain IIFE reddens **5** (the 3 new guards plus the 2 corrected
  tests) while all **3** positive controls stay green.
  **TWO EXISTING TESTS ASSERTED THE OLD BEHAVIOUR AND ARE CORRECTED, NOT WEAKENED** — see [[LOG]].
  See [[Billing]], `docs/qa/ROLE-AUDIT.md` (P3-C1), D-182 (the test shape), [[DEFERRED]], [[LOG]].

- **D-200 — Two legitimate COLLECTED figures are LABELLED, not unified: each money total states the
  basis the engine actually computes (QA-FIX.3b, closing P3-H1).**
  Phase 3 read `/billing/aging` showing **COLLECTED (MONTH TO DATE) 1066.53** and `/billing/report`
  showing **COLLECTED (PERIOD) 1114.56** on the same day for the same practice. Adjudicated against
  the ledger, **both were right**: `1066.53` is the sum of **payments** by `received_on`, `1114.56`
  the sum of **allocations** by `allocated_at`. Two surfaces in one module answered "how much did we
  collect?" with two numbers under the same word, and neither page said which question it answered.
  **THE FIX IS LABELLING. NEITHER ENGINE METHOD CHANGED.** `paymentsReceivedTotalMinor()`
  (`MetricsService:230-243`) and `netCollectionsMinor()` (`MetricsService:587`) keep their existing
  definitions verbatim; the gate's own instruction was that an engine change would be a STOP-and-report,
  and the study found no reason for one. Receipts-vs-applied is a real accounting distinction, not a
  bug: a practice legitimately needs both, and collapsing them into a single "collections" number
  would DESTROY information — the unallocated remainder would simply vanish from one of the two views.
  **THE WORDING IS TAKEN FROM THE ENGINE, NOT FROM THE LABEL.** Each caption was written by reading
  what the method computes and transcribing it, not by paraphrasing the word "collected":
  the aging card now reads **"Cash received (month to date)"** with *"Money received, by payment date.
  Refunds are separate rows and are not netted here…"* — because refunds are a separate table and the
  method does not net them, a fact no reader could have inferred from "Collected". The report's
  "Collected (period)" card (`Report.vue:227`, rendering `collection_rate.collections_minor`) names
  the applied basis (*"by allocation date. Reversals net out. This is the figure that reduces AR."*)
  and its "Cash received" line the received one (*"including anything not yet applied
  to an invoice"*).
  **A CAPTION IS A CLAIM, AND A CLAIM MUST BE CHECKABLE — this is the lesson of the gate.** The first
  version of `tests/Feature/Billing/CollectedBasisLabelsTest.php` asserted the engine methods AND the
  rendered captions, and a mutation swapping `AgingController:40` to the applied basis **passed all 18
  tests**. The captions were true only by coincidence: nothing asserted that the *page renders the
  basis its caption claims*. The added test drives the two bases apart (CHF 400.00 received against
  CHF 100.00 applied) and asserts the rendered prop on each page. The mutation now reddens on BOTH
  surfaces — `AgingController:40` and `BillingReportController:173`. **Generalised: a test that pins
  a label and a test that pins an engine do not, together, pin the wiring between them.** Any future
  "state the basis" fix must assert the number the page actually shows.
  **This is cross-phase pattern 2 (divergent presentations of the same underlying fact) in its money
  form**, and it is the counterpart to D-199: 3a removed the *phantom* component of the receipts
  figure, 3b explains the *legitimate* remainder.
  See [[Billing]], `docs/qa/ROLE-AUDIT.md` (P3-H1), D-199 (the sibling half of this gate),
  D-176 (an unbacked claim on screen), [[LOG]].

- **D-201 — The API is TOKEN-ONLY: `statefulApi()` is removed, not worked around
  (QA-FIX.4a, closing P4-C1).**
  Phase 4 could not log into the Nurse PWA at all from the origin it is served on: `POST
  /api/nurse/login` and `POST /api/nurse/sync` returned **419 CSRF token mismatch** while `GET
  /api/nurse/day-pack` returned **200**. **The asymmetry was the danger, not the outage** — CSRF
  applies only to state-changing verbs, so patient data flowed onto the field device and no recorded
  care could ever come back.
  **THE CAUSE WAS A SPECULATIVE LINE THAT CONTRADICTED ITSELF.** `bootstrap/app.php` carried
  `$middleware->statefulApi()` under the comment *"Sanctum stateful API for the future PWA / SPA
  token auth"*. `statefulApi()` enables **cookie-session** auth for first-party SPAs — precisely what
  **token** auth does not need. It was added before the PWA existed, and the PWA that arrived is a
  token client. Sanctum treats any request whose `Origin` is a stateful domain as a first-party SPA
  and CSRF-checks it, and `SANCTUM_STATEFUL_DOMAINS` **derives from `APP_URL`** — the very host
  `public/nurse-pwa/` is served from. **This was therefore never local-only: it breaks in production
  too**, wherever the PWA shares the app's hostname.
  **THE STUDY FOUND NO COOKIE CLIENT TO PROTECT.** The entire `api` surface is six routes, all
  Bearer-token: `NurseAuthController` mints a Sanctum personal access token with the
  `nurse:day-pack` ability and a 12-hour expiry, and `NurseSyncController` re-checks it with
  `tokenCan`. The Nurse PWA is the **only** consumer (four `fetch` sites in `nurse-pwa/src/api.ts`);
  the Inertia app, the patient portal and the kiosk all use **web** routes. `routes/api.php`'s own
  docblock already said *"Token-authenticated (Sanctum) endpoints for the Nurse PWA live here."*
  **OPTION (b) WAS CONSIDERED AND REJECTED ON ARCHITECTURE, NOT EFFORT.** Making the client perform
  the Sanctum CSRF handshake (`/sanctum/csrf-cookie` + `credentials` + `X-XSRF-TOKEN`) would convert
  a token client into a **cookie** client. A cookie session is the wrong primitive for a field device
  that must work offline across reloads, it would make the `tokenCan` ability model redundant, and it
  would put a session cookie on a phone. Option (a) — exclude the API from the stateful middleware —
  matches what every one of those routes already does.
  **WHICH ROUTES CHANGED POSTURE, AND WHY THAT IS SAFE.** All six `api/*` routes stop being treated
  as stateful: they no longer accept cookie-session auth and are no longer CSRF-checked. Five of six
  require `auth:sanctum` **plus** the `nurse:day-pack` ability, and **a browser cannot silently
  attach a Bearer token cross-origin**, so CSRF is structurally inapplicable — there is no ambient
  authority to abuse. The sixth, `POST /api/nurse/login`, is public and takes credentials: an
  attacker who can make a victim's browser POST cannot supply the victim's password and cannot read
  the JSON token response. **CSRF for the Inertia app, the portal and the kiosk is untouched** — it
  lives in the `web` group, which this does not modify, and a test asserts that directly.
  **THE DEFECT WAS INVISIBLE TO CI BECAUSE OF A TEST-POSTURE GAP, AND THAT IS THE TRANSFERABLE
  LESSON.** Every pre-existing nurse API test calls `postJson()`/`getJson()` with **no `Origin`
  header**, so the whole suite exercised the API in a posture no browser ever uses. Sanctum keys off
  `Origin`/`Referer`, so a fully green CI sat on top of a completely unusable product. The new tests
  always send a browser `Origin`. **Measured honestly by mutation:** restoring `statefulApi()`
  reddens **only** the middleware-composition assertion — the request-level tests keep passing,
  because Laravel's `ValidateCsrfToken` self-skips under `runningUnitTests()` and no feature test can
  ever observe the 419 a real browser gets. The structural assertion is the guard; the **browser** is
  the proof. See [[Nursing]], `docs/qa/ROLE-AUDIT.md` (P4-C1), D-182 (the test shape), [[LOG]].

- **D-202 — Device times are parsed to UTC at ONE sync boundary; the server never trusts a client
  convention for correctness (QA-FIX.4b, closing P4-C4).**
  Phase 4 sent a `check_in` with `device_timestamp = 2026-09-06T07:35:00+02:00` (the instant
  `05:35:00Z`) and the row stored **`07:35:00`** — the device's local wall clock in a UTC column.
  Eleven sites wrote `(string) $action['device_timestamp']` straight into datetime columns:
  `occurred_at` (EVV check-in/out), `recorded_at` (vitals, notes, observations), `completed_at` (task
  completion), `captured_at` (attachments) and the ledger's own `device_timestamp`.
  **THE MECHANISM, MEASURED RATHER THAN ASSUMED.** These columns are cast `'datetime'`, so Eloquent
  parses the string with Carbon and serialises it with `format('Y-m-d H:i:s')` — **in the Carbon's
  own timezone**. So `+02:00` stored `07:35`, `-05:00` stored `00:35`, and `Z` stored `05:35`; only
  the last is the true instant. (A *raw* `DB::table()->insert()` of either ISO form throws
  `Incorrect datetime value` under strict mode — the corruption is specifically an Eloquent-cast
  path, which is why it looked like a successful write.)
  **A CORRECTION TO THE PHASE-4 FINDING, MADE HONESTLY.** `P4-C4` was written as though every stored
  EVV time were currently two hours out. It is not. The shipped PWA sends
  `new Date().toISOString()` (`nurse-pwa/src/storage/dayPackStore.ts:74`) — always a `Z` instant —
  so **today's rows are correct**, and the incident's `occurred_at` is likewise normalised
  client-side. My `+02:00` evidence came from a curl-crafted action, not the product's own client.
  The defect is therefore **latent, not active**: the server had no defence and was correct only by
  accident of what one client happens to send. That is still worth fixing — these are the EVV times
  that justify Spitex billing, and a second client, a native wrapper or a changed client default
  would corrupt them silently — but the severity claim in the audit is corrected rather than left
  standing.
  **ONE BOUNDARY, NOT ELEVEN COPIES.** `normaliseDeviceTimes()` runs once in `process()`, inside the
  existing per-action transaction and before dispatch, and rewrites `device_timestamp` (and the
  incident payload's `occurred_at`) to a canonical UTC `Y-m-d H:i:s.u` string. All eleven call sites
  keep their `(string)` casts and are unchanged — they now receive a value with no offset left to
  misinterpret.
  **POLICY, STATED BEFORE THE CODE.** The device's stated instant is recorded as given and converted
  to UTC. There is **no trust window and no clock-skew correction** (D-170): the product has no basis
  on which to judge a device clock, and inventing one would fabricate a care time.
  `device_timestamp` is already validated `['required', 'date']` at the controller, so it is always
  present and parseable; `payload.occurred_at` is **not** validated (payload contents never are —
  P4-H1), so an unparseable value is **rejected** with `validation_failed` rather than silently
  replaced by `device_timestamp`, which would record an incident at a time the reporter never stated
  (D-176 / D-179). A parse failure returns a clean rejection rather than throwing, because an
  unhandled throw here becomes a 500 that takes the whole batch with it (the P4-H1 shape).
  **NO HISTORICAL REWRITE** (D-193 / D-197 precedent) — and in this case there is nothing to rewrite:
  every row written by the shipped client was already a correct UTC instant.
  **Guarded by** ten tests in `tests/Feature/Nursing/DeviceTimestampUtcTest.php`, every fixture using
  a **non-zero offset** because a `Z` fixture would pass with or without the fix and prove nothing
  (D-174). The sharpest is *the same instant sent three ways* — `+02:00`, `-05:00` and `Z` must all
  store one value. Mutation-checked: neutering the conversion reddens **8 of 10**, and the 2 that
  stay green are exactly the two positive controls (the `Z` instant, which must not move, and
  "ordinary sync still accepted"). See [[Nursing]], `docs/qa/ROLE-AUDIT.md` (P4-C4), D-192 (storage is
  UTC from every path), D-170, [[LOG]].

- **D-203 — Two keys, two lifetimes: the day-pack cache stays session-bound, the outbox becomes
  device-bound, because the two hold different data with different lifetimes (QA-FIX.4c, closing
  P4-C2 and P4-C3).**
  Phase 4 destroyed recorded care two ways in the Nurse PWA. **P4-C2:** the AES-GCM key was
  HKDF-derived from the session token and held only in module memory, so an ordinary page reload
  discarded it; a re-login minted a new token, derived a different key, and the outbox ciphertext
  became permanently undecryptable — no `POST /api/nurse/sync` was ever issued again. **P4-C3:** a
  `401`/`403` on reconnect called `wipeLocalStore()`, which cleared the whole store **including the
  outbox**, deleting care the server had never received; the UI then read *"Pending offline
  actions: 0"*.
  **THE THREAT MODEL FIRST, BECAUSE THIS FIX MUST NOT TRADE A DATA-LOSS BUG FOR A PHI-EXPOSURE BUG.**
  The encryption exists for a **lost or stolen field device**: ciphertext at rest must not hand an
  attacker the patient's allergies, medications, problems and vitals history. Tying the key to the
  session token bought that property elegantly and for free — close the tab and the cached PHI is
  unreadable, with no key to store anywhere.
  **THE DEFECT WAS A LIFETIME MISMATCH, NOT THE ENCRYPTION.** The cache is a *copy* of server data
  and may be destroyed freely. The outbox is the **only** copy of care the nurse has already
  recorded and the server has never seen — it must outlive the session by definition. One key was
  being asked to serve both, and the property that correctly protects the cache is exactly what
  destroys the queue.
  **SO THEY ARE SPLIT.** The day pack keeps the original D-E2 arrangement: session-derived key,
  memory-only, cleared on wipe. The outbox is encrypted under a **device-lifetime AES-GCM key
  generated `extractable: false` and stored as a `CryptoKey` in IndexedDB** — script may use it on
  this device but can never read its bytes out, so it cannot be exfiltrated even if the page is
  compromised. `wipeLocalStore()` now deletes the cached records and clears the session key while
  **preserving** the outbox; the security intent it was written for is unchanged.
  **RESIDUAL RISK, STATED PLAINLY RATHER THAN MINIMISED.** An attacker with the unlocked device who
  can run script in this origin can now read **queued** entries — what this nurse recorded on this
  round — where previously they could read nothing at rest. They still cannot read the day-pack
  cache, cannot extract the key, and the outbox drains to empty on a successful sync, so the
  exposure is small and transient. **That trade is accepted deliberately: silently destroying
  documented patient care is the worse failure**, and it is a patient-safety failure rather than a
  confidentiality one.
  **A CONSEQUENCE WORTH NAMING:** the idle-timeout wipe and explicit logout also stop destroying
  un-transmitted work. On a shared device that means queued care survives a logout — which is
  correct (it is still that nurse's unsent record, and it syncs on their next sign-in) but it is a
  behaviour change, not an accident.
  **LEGACY ROWS ARE SKIPPED, NOT DELETED.** A device upgrading from the broken build carries outbox
  rows encrypted under a session key that no longer exists; they were already unrecoverable.
  `loadOutboxForReplay()` now skips records it cannot decrypt instead of throwing — which also stops
  one unreadable row jamming every later action (the P4-H1 shape) — and never deletes them.
  **WHAT THIS DOES NOT FIX:** a nurse who reloads **while offline** still cannot re-enter the app,
  because login requires the network. Their recorded care is now safe and syncs on the next
  sign-in, but the app is not usable offline after a reload. That is a separate gap, recorded rather
  than quietly folded in.
  **Guarded by** ten tests in `nurse-pwa/tests/outboxSurvival.test.ts`. Mutation-checked twice:
  restoring the destructive wipe reddens the three P4-C3 tests; putting the outbox back on the
  session key reddens **eight**, including two pre-existing tests. Positive controls assert the cache
  IS still wiped, that **no plaintext PHI** reaches the device, that the device key is
  non-extractable and `exportKey` rejects, and that a successful sync still drains the queue —
  preserving work must not mean never clearing it. See [[Nursing]], `docs/qa/ROLE-AUDIT.md` (P4-C2,
  P4-C3), D-182, [[LOG]].

- **D-204 — One gesture, one note: the save is made idempotent for unchanged text rather than
  deleting either affordance (QA-FIX.4d, closing P4-C5).**
  Phase 4 typed one note once, pressed Save once, and got **two identical `visit_notes` rows** on the
  server. `App.vue:382` binds `@change` on the textarea and `:384` binds `@click` on the Save button,
  both to `saveNote()`. **Clicking the button blurs the textarea, so one gesture fires both
  handlers**, and each enqueued an action with its own `client_uuid` — which the server's
  `client_action_uuid` dedupe cannot collapse, because as far as it can tell they are two distinct
  notes. Duplicated recorded care, from the single most common action a field nurse performs.
  **THE STUDY SHOWED THE SHAPE IS UNIQUE TO THE NOTE.** Every other control has exactly one handler:
  vitals, incident, signature and the two task buttons are `@click` only, and the photo input's
  `@change` is its only binding (correct for a file input, which has no button). So the fix is narrow
  and nothing else needed changing — recorded rather than assumed, because "fix the others too" was
  the obvious next move and would have been wrong.
  **`@change` IS NOT A MISTAKE, SO IT IS NOT DELETED.** The handler it calls is named
  `autosaveVisitNote`: autosave-on-blur is deliberate for a field app, where a nurse who taps away
  mid-note should not lose it. Deleting `@change` would fix the duplicate by removing a real
  behaviour; deleting the button would remove the affordance a nurse expects to press. **Both are
  kept, and the save is made idempotent for unchanged text instead** — the second event of one
  gesture has nothing new to record, so it records nothing.
  **DELIBERATELY NOT A GENERAL "DEDUPE IDENTICAL CONSECUTIVE ACTIONS" RULE, and there is a test
  pinning why.** Two identical vitals readings minutes apart are legitimately **two observations**;
  suppressing the second would DROP recorded care — the very failure this gate exists to fix. The
  guard is scoped to the note draft, where the two events genuinely describe one gesture, and it is
  keyed by visit so the same sentence on a different patient is still a real, separate note.
  **THE GUARD LIVES WHERE IT CAN BE TESTED.** `saveVisitNoteOnce()` in `visitActions.ts` owns the
  decision and returns `null` when nothing was recorded; `App.vue` calls it. The PWA has no
  `@vue/test-utils`, and adding a dependency inside a fix gate would be scope creep — moving the
  logic into the action module means the tests exercise the **real enqueue path and assert the
  outbox itself**, which is stronger than mounting the component anyway.
  **THE FIRST VERSION OF THIS FIX WAS WRONG, AND ONLY THE BROWSER CAUGHT IT.** The guard originally
  recorded the memo AFTER `await autosaveVisitNote()`. Sequentially that is fine, and it passed
  every unit test — but `@change` and `@click` fire back-to-back, so **both calls are in flight at
  once**: the second read the stale memo and enqueued anyway. Driven in a real browser the fix
  produced **two notes on the server exactly as before**. The memo is now claimed BEFORE the await
  and rolled back if the enqueue throws, so a failure never leaves it claiming work that was not
  recorded. **This is precisely why RULE 1 exists**: a green suite described a fix that did not
  work, and the added concurrent test (`Promise.all` of both handlers) is the one that reddens
  under the old ordering.
  **Guarded by** nine tests in `nurse-pwa/tests/noteSaveOnce.test.ts`, including the exact gesture
  (blur then click → one entry), **the concurrent gesture**, a failed enqueue not poisoning the memo,
  autosave-on-blur alone still recording, editing after saving still recording, the same words on a
  different visit still recording, and a control asserting **identical vitals twice are never
  deduped**. Mutation-checked twice: removing the guard reddens the gesture tests, and restoring the
  after-await ordering reddens the concurrent one. See [[Nursing]], `docs/qa/ROLE-AUDIT.md` (P4-C5), [[LOG]].

- **D-205 — The PWA can check in and out: wiring an existing server capability to its missing client
  control, WITHOUT adding GPS (QA-FIX.4e, closing P4-H3).**
  The server has always implemented `check_in`/`check_out` — the visit state machine
  (`scheduled → in_progress → completed`), the EVV `visit_events` record with
  `location`/`accuracy_meters`/`location_source`/`manual_reason`/`distance_meters`, the
  cross-assignment guard and the ledger — and it is tested. **`App.vue` never imported either
  action.** So no visit could be started or closed from the field, EVV was unreachable, queued vitals
  and notes referenced a visit that did not exist (which is why Phase 4's queued vitals came back
  `visit_not_found`), and **no `in_progress` or `missed` visit could exist at all** — which is also
  why the demo seed had none.
  **THE FIX-OR-FEATURE CALL, MADE BEFORE WRITING ANY CODE, AS THE GATE REQUIRED: it is WIRING.** The
  client already produced the exact payload both handlers need — `baseVisitPayload()` emits
  `planned_visit_id`, `visit_id`, `client_visit_uuid` (`offline-${visit.id}`), `nurse_resource_id`
  and `patient_id` — and `enqueueOutboxAction(type, payload)` is generic, so the offline queue, sync,
  retry, replay idempotency and the device-key encryption from QA-FIX.4c all apply unchanged. Two new
  functions, two buttons, and some button-state handling. **What WOULD have made it a feature — and
  therefore a STOP — is GPS capture:** geolocation permission prompts, accuracy handling, a location
  UI, a distance threshold. None of that is added.
  **SO THE CHECK-IN SAYS IT HAS NO LOCATION, RATHER THAN PRETENDING OTHERWISE.** The server accepts
  **either** a `location` (whose GPS fields it then requires) **or** a `manual_reason`. This client
  captures no GPS, so it sends a stated reason and no coordinates — exactly the path Phase 4 verified
  as honest (`manual_reason` stored, `location` NULL). It fabricates no position (D-176 / D-179) and
  invents no accuracy or distance threshold the server does not define (D-170). The screen says so
  too: *"No location is captured on this device; the visit records the time and the stated reason
  only."* — so a nurse is never left believing EVV verified where they were.
  **ONLY THE ACTION THE VISIT'S STATE ALLOWS IS OFFERED.** `VisitService` throws *"Only scheduled
  visits can be checked in"* and *"A visit must be checked in before check-out"*, and `P4-H1` turns
  an escaped throw into a 500 that takes the whole batch with it. The UI therefore shows Check in, or
  Check out, or neither — driven by `execution_visit_id` (the server's view at day-pack time) **and**
  a local record of what this device has queued since, because offline a queued check-in has not
  reached the server yet. This does not FIX `P4-H1`, which remains open; it stops the client walking
  into it.
  **Guarded by** six server tests (`tests/Feature/Nursing/CheckInOutWiringTest.php`) including **the
  full field round** — check in, vitals, note, check out, all accepted, visit `completed` — plus the
  EVV honesty assertion (`manual_reason` set, `location`/`accuracy_meters`/`distance_meters` all
  NULL) and two positive controls: cross-assignment still refused with
  `schedule_changed_server_wins` and **no `Visit` created**, and the QA-FIX.4b UTC boundary still
  holding on a check-in. Six client tests (`nurse-pwa/tests/checkInOut.test.ts`) pin the queued
  payload shape, that both go through the same offline queue, and that the payload contains **no**
  `latitude`/`longitude`/`accuracy`/`distance`.
  **A NOTE ON THE FIRST CROSS-ASSIGNMENT TEST I WROTE, WHICH WAS WRONG.** It forged
  `nurse_resource_id` on the nurse's OWN planned visit and expected a rejection; the sync returned
  `accepted`, which is **correct** — the forged field is ignored because that resource is not in the
  nurse's set, and the nurse is entitled to their own visit. Genuine cross-assignment needs a second
  nurse **in the same tenant** (two tenants would only prove tenant isolation, a different guard).
  Corrected rather than argued with. See [[Nursing]], `docs/qa/ROLE-AUDIT.md` (P4-H3), D-170, D-176,
  [[LOG]].
