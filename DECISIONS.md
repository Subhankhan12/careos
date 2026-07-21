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
