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
