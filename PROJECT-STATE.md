# PROJECT-STATE.md

Short, factual snapshot of where the project stands. Updated at consolidations and after gates
(per the MEMORY PROTOCOL in AGENTS.md).

- **Current phase:** Phase E - Nursing wedge - **IN PROGRESS**. Latest gate: P0E.G1 service
  agreements. Next: Gate E.2.
- **Commits:** 44 on `main` after P0E.G1 (nursing service agreements).
  Phase A = 11 (P0A.G1-G8, P0A.GM, P0A.GF, P0A.GF3), pushed to `origin/main`
  (https://github.com/Subhankhan12/careos).
- **Verified quality (from actual output):** `composer check` green - Pint `passed`,
  PHPStan level 5 `[OK] No errors`, Pest **229 passed / 1247 assertions**; latest frontend build
  remains the Phase D `cmd /c npm run build` green result (E.1 has no frontend changes). CI was
  green on MySQL 8 + Redis for Phase D; P0E.G1 CI is checked after push.
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
- **Proven in Phase E so far:**
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
- **Next action:** Execute only Gate E.2 when pasted.
