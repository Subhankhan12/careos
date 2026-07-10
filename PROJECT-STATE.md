# PROJECT-STATE.md

Short, factual snapshot of where the project stands. Updated at consolidations and after gates
(per the MEMORY PROTOCOL in AGENTS.md).

- **Current phase:** Phase F - Billing engine + EU-Generic market pack - in progress. Latest gate:
  P0F.G5 payments + append-only allocations. Next: Gate F.6.
- **Commits:** 59 on `main` after P0F.G5.
  Phase A = 11 (P0A.G1-G8, P0A.GM, P0A.GF, P0A.GF3), pushed to `origin/main`
  (https://github.com/Subhankhan12/careos).
- **Verified quality (from actual output):** `composer check` green - Pint `passed`,
  PHPStan level 5 `[OK] No errors`, Pest **319 passed / 1811 assertions**. Latest frontend/PWA
  verification remains Phase E consolidation: `cmd /c npm run build` green,
  `cmd /c npm run test:pwa` green (**15 passed**), and `cmd /c npm run build:pwa` green.
  Latest Phase E CI is checked after push; F.1 CI will run after push.
- **Stack (verified):** Laravel 12.63.0 on PHP 8.2.12; DEV DB = `careos` on XAMPP MariaDB
  10.4.32 (127.0.0.1:3306); Redis-compatible server on 127.0.0.1:6379 with Predis (`PONG`);
  queue/cache use Redis and Horizon is installed/guarded. Local Windows PHP lacks `pcntl`, so
  `php artisan horizon` exits after startup locally; CI Linux installs `pcntl`/`posix`. Sessions
  remain database; Fortify + Sanctum.
- **Proven in Phase A:**
  - Fail-closed multi-tenancy (TenantContext + BelongsToTenant; no-context queries throw).
  - Fortify auth + **mandatory TOTP MFA** + tenant identification (suspended tenants denied).
  - Org hierarchy (branches/departments) with cross-tenant FK guard.
  - Custom **RBAC** with branch-scoped assignments + `Gate::before` (super-admin sole bypass).
  - Plans (integer minor units) + feature flags + typed settings.
  - Append-only, hash-chained, monthly-partitioned `audit_events` + AuditService
    (`verifyChain`, DB UPDATE/DELETE triggers), portable on MariaDB 10.4 + MySQL 8.
  - Audit integration (auth/RBAC/config events) + read-logging + time-boxed break-glass.
  - Inertia/Vue3/TS/Tailwind v4 shell (login -> 2FA -> role redirect; app/admin landings).
  - Cross-agent memory system (AGENTS.md + memory/) as the single source of truth.
  - CI builds the frontend and runs the suite on MySQL 8 (Node 22).
- **Proven in Phase B:**
  - People module registered with fail-closed `staff_profiles` and `credentials`.
  - Credential expiry status is derived from `expires_on` with tenant setting
    `people.credentials.expiry_alert_days` (default 30 days); manual `revoked` is preserved.
  - `credentials:refresh-status` recomputes stored statuses idempotently; scheduling is deferred.
  - Credential create/update/revoke is audited from the app layer; staff-profile reads are not read-logged.
  - Patients module registered with fail-closed patient CRM tables: patients, contacts,
    identifiers, and coverages.
  - MRNs are generated per tenant as `MRN-000001` style values under a tenant-row `FOR UPDATE`
    lock and skip existing/soft-deleted MRNs.
  - Patient reads use the Phase A read-logging mechanism with `patient_id`; `PatientAccessReport`
    can list read audit rows for a tenant-scoped patient.
  - Patient identifiers are optional attributes, not unique dedupe/match keys (D-021).
  - Duplicate detection is demographic, tenant-scoped, explainable, and combines deterministic
    name/DOB/address/identifier scoring with FULLTEXT only as supporting evidence.
  - Patient merge requires `patient.merge`, a reason, and same-tenant source/target; it writes
    `patient.merged`, moves captured child rows, soft-deletes the source, and `patient.unmerged`
    restores only the rows moved by that merge (D-022).
  - Consent engine stores versioned tenant templates and patient consent captures with immutable
    signed template snapshots; `ConsentService::has()` is fail-closed and respects scopes,
    expiry, and withdrawal (D-023).
  - Patient portal identity uses separate tenant-owned `portal_accounts` with a dedicated
    `patient` guard/session; portal invite/activation/login is gated by `portal.access` consent
    and audited with patient-scoped events (D-024).
  - First staff-facing patient UI is in place: RBAC-gated patient index/search, registration
    wizard with live duplicate warnings, and patient 360 view with consents + access log.
  - CI is green on MySQL 8 for the latest pushed Phase B work.
- **Proven in Phase C so far:**
  - Redis-compatible server reachable on 127.0.0.1:6379 (`PING` => `PONG`).
  - `predis/predis` and `laravel/horizon` are installed; Horizon dashboard is guarded by
    `auth` + `super-admin`.
  - Queue/cache use Redis; sessions intentionally stay on the database.
  - CI workflow includes a Redis 7 service alongside MySQL 8 and installs `pcntl`/`posix` for
    Horizon on Linux.
  - A real Redis queue round-trip sanity job passes locally.
  - Scheduling module registered with fail-closed `services` and tenant-owned `service_branch`
    availability links.
  - `ServiceCatalog` validates duration, buffers, resource requirements, per-tenant code
    uniqueness, and same-tenant branch availability.
  - Bookable resources and resource availability are tenant-owned and fail closed.
  - `AvailabilityService::windowsFor()` combines recurring weekly hours with date-specific
    overrides and blocks/time-off deterministically.
  - Booking engine stores tenant-owned appointments and appointment resource consumption rows.
  - `BookingService` enforces `appointment.manage`, availability, buffers, same-tenant references,
    and no double-booking by locking resource rows then checking overlapping held windows inside
    the transaction before insert.
  - Parallel hammer test runs eight independent PHP processes against the same slot and proves
    exactly one appointment/resource row is created.
  - Appointment lifecycle is service-enforced: legal transitions only, terminal states closed,
    cancellation frees resources, and reschedule is atomic cancel-and-rebook through
    `BookingService`.
  - Waitlist entries are tenant-owned; matching respects service, branch, waiting status, and
    flexible/covering desired windows; offer/accept books through the no-double-book path.
  - Appointment reminders are tenant policy-driven, queued on Redis/Horizon, idempotent via
    `appointment_reminders`, fail-closed on `comms.email` consent, and audited on delivery state.
  - Reception day-board is RBAC-gated for `appointment.manage`, tenant-scoped, and supports
    lifecycle actions plus quick-book through the safe booking path.
  - Public online booking is tenant-slug scoped, rate-limited, exposes only active
    `bookable_online` services, runs duplicate detection before creating/reusing a patient, and
    books with `source=online` through the same locked booking path.
  - AiCore is active as the governed runtime foundation: provider-agnostic `LlmManager`,
    append-only `ai_interactions`, budget gate, circuit breaker, hash-pinned prompt registry,
    declared tool registry, autonomy dial, approval queue, kill switch, visible AI labels, and
    audit-chain integration.
  - The demo echo/no-op tool exercises the full pipeline; real agent behavior remains for later
    gates and must run through AiCore.
  - Scheduler Agent is live under AiCore governance: fill-from-waitlist and suggest-slots tools are
    capped at approve, write `ai_interactions` + audit, and waitlist booking only happens after
    human approval through the safe Scheduling path.
  - Front-Desk Agent is live under AiCore governance: answers only from current-tenant active KB
    articles with source citation, escalates unknowns, and refuses medical/symptom/triage/dosing
    questions with human handoff.
  - Public booking carries a static non-emergency notice and collects only service/branch/date/slot
    plus minimal patient identity/contact fields; no symptom/triage free-text field is present.
  - Phase C decisions D-025..D-033 are logged: Redis/Horizon, service_branch, availability override
    semantics, booking locks, atomic reschedule, reminder idempotency, public booking tenant slug,
    AiCore governance/autonomy caps, and KB-only/approval-first agents.
  - Standing UI rule is documented in AGENTS.md: Vue components are presentational; server-side
    code owns authorization/validation/state transitions; feature tests assert behavior, not markup.
- **Proven in Phase D so far:**
  - Clinical module registered with fail-closed tenant-owned `encounters`.
  - `encounter.manage` is in the RBAC catalog; starter doctor/nurse/org-admin roles receive it,
    reception does not.
  - `EncounterService` opens/closes encounters, rejects cross-tenant references, and allows only
    one open encounter per patient/practitioner at a time.
  - Opening from an appointment transitions the appointment to `in_progress` through Scheduling
    `AppointmentService`, not direct model mutation.
  - Encounter read logging writes patient-scoped `read` audit rows; open/close write
    `encounter.opened` / `encounter.closed` and the audit chain verifies.
  - Structured SOAP clinical notes are tenant-owned and read-logged with denormalized
    `patient_id` for patient-scoped access reports.
  - Draft notes remain editable; signed notes are immutable at both model level and DB-trigger
    level. The trigger keys off `OLD.status = 'signed'` so draft updates and draft-to-signed
    transitions remain allowed.
  - Amendments create new superseding note rows with mandatory reasons; originals are never
    modified, and `versionsFor()` returns the ordered original-to-amendments chain.
  - Note templates provide SOAP prefills and required sections; `note.write` / `note.sign` are
    clinician-gated (org-admin/doctor/nurse, not reception).
  - `note.signed` and `note.amended` audit events are written and chain-verified.
  - Problems, allergies, vitals, and medications are tenant-owned clinical lists with
    patient-scoped audit/read logging.
  - Allergy hard-stop is deterministic exact-match only: active documented allergy
    `substance_key` equals requested medication `substance_key` after lowercase/trim
    normalization. No fuzzy/class/interaction/dose/CDS logic exists.
  - `MedicationService::record()` rejects active allergy conflicts before writing; override
    requires `allergy.override`, a non-empty reason, and writes `allergy.override` audit context.
  - Vitals and medications store documented raw/free-text values only; no interpretation, score,
    flag, or derived fields are present.
  - Clinical documents are tenant-owned metadata rows with private per-tenant storage paths;
    file bytes are never public and are streamed only through checked controllers.
  - Document upload/share/unshare/delete are audited; staff and portal downloads write
    patient-scoped `read` audit rows naming the document.
  - Portal sharing is fail-closed on `portal.access` consent and portal users can see only
    explicitly shared documents for their own patient account.
  - Referrals are tenant-owned, patient-scoped, audited through created/sent/responded/completed
    lifecycle, and either same-tenant internal `to_branch_id` records or external provider-name
    records; cross-tenant CareOS referral exchange is deferred to explicit share objects.
  - Recall rules are tenant-owned deterministic JSON criteria. `RecallEngine` evaluates exact
    active problem-code membership plus exact missing encounter-type criteria over an explicit
    tenant and generates idempotent due recall rows; no AI or inference selects recipients.
  - Recall lifecycle changes are audited; chart reads of referrals/recalls are patient-scoped
    read-logged.
  - Care plans and care-plan goals are tenant-owned, clinician-authored, RBAC-gated by
    `note.write`, audited on lifecycle changes, and read-logged when returned in the chart.
  - Clinical tasks are tenant-owned, assigned only to same-tenant staff, optionally linked to a
    patient/care plan/encounter, lifecycle-enforced, and audited.
  - `note.supervise` is in the RBAC catalog for org-admin starter roles; unsigned-note worklists
    show clinicians only their own aged drafts and supervisors the tenant team's aged drafts.
  - Clinical UI is in place for SOAP note editing/signing, visible amendment history, patient
    chart sections, and day-board-to-document flow.
  - `NoteEditorController` enforces `note.write`/`note.sign` server-side; signed notes are
    returned read-only and server updates to signed notes are rejected.
  - The patient chart is `patient.view` gated, read-logged, returns full note version history,
    real care plans with goals, real referrals/recalls, allergies prominently, and raw vitals
    without interpretation flags/scores.
  - The day-board Document action opens the encounter and draft note through server services,
    then redirects to the note editor; the honest open -> document -> sign path is 3 clicks.
  - Clinical Summary Agent runs under AiCore at an explicit `suggest` ceiling. It is extractive
    only, reads the requested patient's signed notes/problems/medications/vitals, validates every
    line against a real source row/field, refuses interpretive/diagnostic requests, and never
    writes to the record.
  - Clinical Follow-up Agent runs under AiCore at an explicit `suggest` ceiling. It drafts recall
    outreach wording only from deterministic D.5 recall rows plus clinician-authored templates;
    it never selects recipients, gives advice, or marks delivery-ready without `comms.email`
    consent.
  - Full consult loop is covered end to end: day-board -> open encounter -> SOAP draft -> sign ->
    chart shows signed note -> amend with reason -> chart shows both versions -> audit chain
    verifies.
- **Proven in Phase E:**
  - Nursing module registered with fail-closed tenant-owned `service_agreements` and
    `agreement_services`.
  - Service agreements link patient, branch, funding/authorization metadata, authorized hours,
    start/end dates, lifecycle status, and creating staff user.
  - Agreement services link to the Scheduling service catalog and store documented planned
    frequency, required qualification, and duration without computing care plans.
  - `ServiceAgreementService` enforces `agreement.manage`, same-tenant patient/branch/service
    guards, and legal transitions: draft -> active/ended; active -> suspended/ended;
    suspended -> active/ended; ended terminal.
  - `agreement.manage` is in the RBAC catalog for org-admin and the new coordinator starter role;
    reception does not receive it.
  - Agreement lifecycle changes are audited patient-scoped; reading an agreement writes a
    patient-scoped `read` audit row.
  - Planned visit generation uses `simshaun/recurr` for RFC 5545 RRULE expansion, not hand-rolled
    parsing. PHP 8.2 pins Recurr `^5.0` because v6 requires PHP 8.4.
  - `visit_plans` define agreement-service recurrence, timezone, local arrival window, duration,
    date bounds, and active flag.
  - `planned_visits` are concrete tenant-owned occurrences with local scheduled date, UTC window
    timestamps, qualification, lifecycle status, optional assigned Scheduling resource,
    assignment metadata, optional straight-line travel coordinates, and cancellation reason.
  - `VisitPlanGenerator::materialize()` computes in the plan timezone, stores UTC, and is
    idempotent via unique `(tenant_id, visit_plan_id, scheduled_date)` plus upsert.
  - DST correctness is tested across Europe/Zurich spring-forward and fall-back: local 09:00 stays
    09:00 while the stored UTC hour shifts.
  - Single-occurrence cancellation keeps the RRULE unchanged and is not resurrected by
    re-materialization; materialization/cancellation are audited.
  - `nursing:materialize-visits` exists and is tested; scheduling the command is deferred.
  - Nurse assignment constraints are tenant-owned and attach to practitioner resources:
    qualification, weekly hour cap, and max travel minutes between visits.
  - `AssignmentValidator` is deterministic and returns distinct reasons for qualification mismatch,
    half-open window overlap, missing travel coordinates, infeasible straight-line travel, weekly
    hour-cap excess, and missing nurse constraints.
  - `VisitAssignmentService` requires `dispatch.manage`, locks the planned visit, nurse resource,
    and candidate assigned visits with `FOR UPDATE`, then assigns/unassigns only after validation.
  - Parallel hammer assignment test runs eight independent PHP processes against overlapping visits
    for one nurse and proves exactly one assignment wins.
  - Dispatcher board UI is Inertia/Vue presentational only; routes are RBAC-gated, tenant-scoped,
    patient read-logged, and server validation failures surface as explainable reasons.
  - Executed `visits` are tenant-owned and may be created from assigned planned visits or ad hoc
    later; `client_visit_uuid` is unique per tenant for offline idempotency.
  - `visit_events` are append-only check-in/check-out proof rows with DB UPDATE/DELETE triggers,
    device/server timestamps, GPS/manual source, optional nullable GPS `location`, accuracy,
    computed geofence distance, and patient-scoped audit.
  - GPS privacy posture D-E3 is bound in code: location is captured only at check-in and check-out;
    there is no continuous/background tracking or route capture; manual fallback requires a reason.
  - Geofence distance uses `ST_Distance_Sphere` against the planned visit target coordinate and
    flags distant events for review in audit context without blocking the visit transition.
- Nurse PWA scaffold exists as a separate `nurse-pwa/` Vite/Vue/TS app with Dexie encrypted storage,
  Workbox service worker generation, its own `build:pwa` and `test:pwa` scripts, and CI steps.
- Nurse device auth issues Sanctum bearer tokens through `/api/nurse/login` only for tenant staff
  who have completed MFA; `/api/nurse/logout` revokes the bearer token.
- `/api/nurse/day-pack` returns only the authenticated nurse's assigned visits for the requested
  date, plus address, allergies, active medications, active problems, active care-plan goals, and
  same-day task data for those patients.
- Day-pack sync writes one patient-scoped `read` audit row per included patient; other nurse and
  other tenant data are unreachable.
- PWA storage encrypts the day-pack with AES-GCM; the key is HKDF-derived from the session token and
  held only in memory. The local store is wiped on logout, 401/403 sync responses, and idle timeout.
- Offline nurse actions replay through `/api/nurse/sync` with tenant-scoped `client_action_uuid`
  idempotency recorded in `nurse_sync_actions`.
- D-E1 conflict policy is enforced: server schedule changes reject schedule-affecting actions;
  client note content is preserved and flagged when schedule changed; ambiguous actions create
  `sync_conflicts` rows for human review.
- `visit_observations` stores nurse-authored offline notes with client UUID, visit/patient/resource,
  device timestamp, and flagged review reason when applicable.
- The PWA encrypted outbox persists in Dexie, replays entries in sequence order, clears only
  server-acknowledged entries, and retries sync with exponential backoff.
- Visit execution sync now supports idempotent offline task completion/not-done with required
  reasons, raw visit vitals, nurse observational notes, private photo uploads, and private patient
  signatures through `/api/nurse/sync`.
- Visit attachments are stored on the private local disk under generated
  `tenants/{tenant}/nursing-attachments/{patient}/{visit}/...` paths and streamed only through an
  authorized controller; no public URLs are exposed.
- Visit vitals use the D.3 raw column shape (`systolic`, `diastolic`, `heart_rate`,
  `temperature_c`, `spo2`, `weight_g`, `height_mm`, `extra`) with no flags, ranges, scores, or
  derived interpretation fields.
- The Nurse PWA now queues task actions, raw vitals, note autosaves, photos, and signatures offline
  into the encrypted outbox; Vitest asserts no plaintext note/photo/signature content is stored in
  IndexedDB and reloads preserve queued actions.
- Incidents are tenant-owned factual reports, can be queued offline through the encrypted outbox,
  replay idempotently by `client_action_uuid`, and write patient-scoped `incident.reported` audit
  rows. Severity is reporter-selected; the system never assesses severity or advises action.
- Timesheet lines are generated from actual proof-of-visit check-in/check-out event times, never
  planned duration. Missing checkout, manual-location proof, and duration deviations are flagged
  for approver review rather than guessed or auto-corrected.
- Approved timesheet lines are immutable at both model level and DB-trigger level while drafts
  remain editable. Approval requires `timesheet.approve` (org-admin/coordinator starter roles).
- Dispatch agent runs under AiCore governance with `nursing.propose_assignments` and
  `nursing.replan_day` tools capped at `approve`; pending proposals assign nothing, invalid
  proposals are rejected before the approval queue, and approval executes through
  `VisitAssignmentService::assign()`.
- Dispatch agent is logistics-only and refuses clinically framed prioritization requests such as
  "which patient is sickest?" with handoff and no `agent_action`.
- Phase E exit criterion is covered by the CI-runnable test
  `airplane mode: full offline visit syncs and produces a timesheet line`: nurse logs in, syncs
  a one-patient day-pack with read audit, replays offline check-in/task/vitals/note/photo/signature/
  check-out in sequence through `/api/nurse/sync`, verifies exactly one set of server rows, verifies
  audit chain, generates a timesheet line from actual check-in/out times, and replays the same batch
  again with no duplicates.
- Honest local harness note: Playwright/browser `context.setOffline(true)` is not installed in this
  repo. The airplane-mode consolidation proof is a Laravel API end-to-end test plus the existing
  PWA Vitest encryption/offline-persistence suite. Local Windows PHP also lacks `pcntl`, so
  `php artisan horizon` exits after startup; Redis itself is live (`PONG`) and the Redis queue
  round-trip plus Horizon dashboard guard pass in the suite.
- **Proven in Phase F so far:**
  - Billing module registered with fail-closed tenant-owned `tariff_catalogs` and `tariff_items`.
  - Tariff catalog versions are effective-dated, unique by `(tenant_id, key, version)`, and
    guarded against overlapping date ranges for the same tenant/key.
  - Tariff items store money as integer minor units (`unit_price_minor`) and VAT rates as integer
    basis points (`vat_rate_bp`), never floats.
  - `TariffResolver::resolve(tenant, code, serviceDate)` returns the active catalog item valid on
    the service date, preserving historical prices across version boundaries and throwing a
    distinct no-coverage exception when no active version applies.
  - EU-Generic starter catalog seeding is tenant-scoped/idempotent and uses tenant currency
    settings (default `EUR`).
  - `billing.manage` is in the RBAC catalog for org-admin and billing starter roles; reception
    does not receive it.
  - Charge capture stores tenant-owned `charges` from encounters, visits, or manual capture, with
    patient/branch/service date, one source at most, tariff pointers, and immutable price snapshot
    columns copied from the tariff item at capture.
  - `ChargeCaptureService` resolves tariffs at the service date, snapshots code/description/
    unit price/VAT basis points, computes `line_total_minor = quantity * unit_price_minor`, and
    never re-resolves existing charges after tariff edits (D-F2).
  - Documentation-required tariff items are captured only from an encounter with a signed clinical
    note or a completed visit; the check is deterministic and does not use AI.
  - Draft/validated charges can be cancelled only with a reason; invoiced charges are not directly
    cancelled and must be corrected later through credit-note mechanics.
  - Charge capture/cancellation are audited patient-scoped and tenant chain verification remains
    valid.
  - `ChargeValidator` validates draft/validated charges against the resolved catalog version's
    deterministic JSON rules before invoicing.
  - Validation rule types are explicit and explainable: max quantity per period, incompatible
    same-date codes, required same-date base code, and documentation-required rechecks.
  - Violations are persisted in tenant-owned `charge_violations` rows with distinct reason codes;
    clean charges transition from `draft` to `validated`, failed charges stay `draft`, and
    validation is idempotent/re-runnable.
  - Validation writes patient-scoped `charge.validated` and `charge.violation` audit events only
    for new state changes.
  - Golden files under `tests/Fixtures/billing/golden/` freeze exact behavior for catalog versions;
    the runner loads every JSON fixture and asserts exact expected validated/violation output.
  - Invoices are tenant-owned VAT documents generated from validated charges; invoice lines copy
    charge snapshot economics so issued invoices are self-contained.
  - `IssueService` assigns numbers only at issue time under `SELECT ... FOR UPDATE` on
    `invoice_sequences`, with transaction retry for deadlocks. The parallel hammer issues 6
    invoices concurrently and proves numbers 1..6 with no gaps or duplicates.
  - Issued invoices and invoice lines are immutable at both model and DB-trigger levels; drafts
    remain editable and the draft-to-issued transition is allowed.
  - Mutable payment/balance state is separated into `invoice_balances`; the legal `invoices` row
    remains fully frozen after issue.
  - Credit notes use series `CN`, are new independently numbered invoice documents with negative
    lines referencing original invoice lines, and leave the original invoice document untouched.
  - Invoice artifacts are written to private tenant-prefixed local storage under
    `tenants/{tenant}/billing/invoices/...`; no public URL is exposed.
  - Payments, refunds, and payment allocations are tenant-owned and append-only at model and
    DB-trigger level; raw UPDATE/DELETE on all three throw. De-allocation is a reversal ROW (exact
    negative of the allocation), never a delete; refunds are separate rows, never negative payments.
  - `PaymentService::unallocated(payment)` and `openBalance(invoice)` are derived by exact integer
    arithmetic over the append-only rows (net of reversals and refunds); never stored-and-drifting.
  - Allocation cannot exceed the invoice open balance OR the payment unallocated remainder (both
    enforced); allocations serialize on `FOR UPDATE` locks (payment row then `invoice_balances` row)
    so concurrent allocations never overshoot. The parallel hammer (6 real processes, one invoice,
    one payable slot) yields exactly one winner and a never-negative open balance.
  - Allocation updates the invoice open balance/status (issued/partially_paid/paid) only through the
    `invoice_balances` projection; the frozen legal `invoices` row is never touched.
  - Refunds may draw only on a payment's unallocated remainder (D-F6); refunding allocated money
    requires reversing the allocation first. Overpayment remainders stay visibly unallocated.
- **Next action:** Gate F.6.
