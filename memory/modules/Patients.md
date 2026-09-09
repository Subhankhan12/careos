# Module: Patients (`Modules\Patients`)

## Purpose

Tenant-owned patient CRM core: demographics, contacts, optional identifiers, coverages, MRN
generation, patient read-logging for the "who accessed my record" audit report, and patient
consent capture/scope checks, plus separate patient portal login identity.
Staff-facing patient index/search, registration wizard, and 360 view are now exposed through
Inertia pages.

## Key tables

- `patients` - tenant-owned (`BelongsToTenant`). ULID id, `tenant_id`, per-tenant `mrn`,
  demographics, nullable `deceased_at`, `status`, nullable `merged_into_id`, timestamps,
  soft deletes. Unique `(tenant_id, mrn)`, lookup `(tenant_id, last_name, date_of_birth)`,
  FULLTEXT `patients_name_fulltext` on `(first_name, last_name)`.
- `patient_contacts` - tenant-owned. `patient_id`, `type`, optional scalar `value`, address
  fields (`line1`, `line2`, `city`, `postal`, `country`), `is_primary`, timestamps.
- `patient_identifiers` - tenant-owned optional attributes. `patient_id`, `system`, `value`,
  nullable `valid_from`/`valid_to`. Not unique and not a dedupe key (D-021).
- `patient_coverages` - tenant-owned. `patient_id`, `payer_name`, `member_id`, nullable `plan`,
  EU-generic `coverage_type`, integer `priority`, nullable validity dates.
- `consent_templates` - tenant-owned versioned consent text. `key`, `title`, `body`, integer
  `version`, JSON `scope_keys`, `is_active`; unique `(tenant_id, key, version)`.
- `patient_consents` - tenant-owned captured consent. `patient_id`, `template_id`,
  `template_version`, immutable signed template key/title/body/scope snapshot, `status`,
  `granted_at`, nullable `withdrawn_at`/`expires_at`, JSON `signature`, and `captured_by`.
  Index `(tenant_id, patient_id, status)`.
- `portal_accounts` - tenant-owned patient login identity separate from staff `users`.
  `patient_id`, globally unique `email`, nullable hashed `password` until activation, `status`,
  invite/activation/last-login timestamps, remember token. Unique `(tenant_id, patient_id)`.
- `portal_login_tokens` - tenant-owned short-lived magic-link + OTP verifiers for portal
  invite/activation. Stores token hash, OTP hash, purpose, expiry, and consumed timestamp.

## Key services / classes

- `Models\Patient` - `BelongsToTenant`, `SoftDeletes`, `LogsReads`; has contacts, identifiers,
  coverages; `auditRead()` writes resource `patient` with `patient_id`.
- `Models\PatientContact`, `PatientIdentifier`, `PatientCoverage` - tenant-owned children;
  reject patient FKs invisible in the current tenant context.
- `Services\MrnGenerator` - tenant-row `FOR UPDATE` lock, fixed-width `MRN-000001` sequence,
  checks existing and soft-deleted MRNs before returning.
- `Services\PatientService` - create/update patient and child contacts/identifiers/coverages
  in one transaction.
- `Services\PatientAccessReport` - tenant-scoped stub listing read audit rows for a patient.
- `Services\DuplicateDetector` - tenant-scoped demographic duplicate scoring using deterministic
  name/DOB/address/identifier rules plus FULLTEXT support; returns reasons and confidence.
- `Services\PatientDuplicateReviewService` - review-list query wrapper for likely duplicates.
- `Services\PatientMergeService` - permissioned, reason-required merge/unmerge with audit snapshots.
- `Models\ConsentTemplate` and `PatientConsent` - tenant-owned consent templates and captures;
  patient/template/capturer references are same-tenant guarded.
- `Services\ConsentService` - grants current active template versions, withdraws with reason,
  writes patient-scoped audit events, and resolves `has(patient, scopeKey)` fail-closed.
- `Models\PortalAccount` and `PortalLoginToken` - tenant-owned portal identity and invite token
  rows; patient/account references are same-tenant guarded.
- `Services\PortalAccessService` - creates portal invites, sends magic-link/OTP notification,
  activates accounts with first password, logs in via the `patient` guard, and audits portal
  invite/first-login/login.
- `Http\Middleware\IdentifyTenantFromPortalSession`, `EnsurePatientPortalAuthenticated`, and
  `EnsurePortalConsent` - re-establish tenant context from the portal session, authenticate the
  patient guard, and enforce `portal.access` consent.
- Clinical document portal endpoints use the existing patient guard/session and portal consent
  middleware; portal accounts can list/download only Clinical documents explicitly shared for
  their own `patient_id`.
- `Http\Controllers\PatientIndexController` - RBAC-gated patient index/search using FULLTEXT
  name matching with deterministic fallback and optional DOB filter.
- `Http\Controllers\PatientRegistrationController` - RBAC-gated registration wizard endpoints;
  duplicate-check JSON endpoint calls `DuplicateDetector` before create.
- `Http\Controllers\PatientShowController` - RBAC-gated patient 360; calls `auditRead()`,
  returns demographics/contacts/coverages/consents and access log.
- Vue pages under lowercase `resources/js/pages/Patients`: `Index.vue`, `Register.vue`,
  `Show.vue`; components `StepNav`, `Tabs`, `DataList`.

## Invariants enforced

- Mutable moment columns are DATETIME, never TIMESTAMP (D-081/P0P.G15): MariaDB gives the first
  non-nullable TIMESTAMP an implicit ON UPDATE CURRENT_TIMESTAMP that MySQL 8 does not —
  `patient_consents.granted_at` and `portal_login_tokens.expires_at` were converted after
  withdrawal/consumption silently rewrote them on the dev engine. Locked by
  `MutableMomentParityTest`; full brief in `docs/DB-PARITY.md`.
- Patients and all child rows are tenant-owned and fail closed without `TenantContext`.
- Child rows must point to a patient visible in the same tenant context.
- MRN is unique per tenant and generated collision-safe under the tenant lock.
- Identifiers are optional attributes; duplicate `(system, value)` values are allowed.
- Patient reads produce append-only audit `read` events with `patient_id` set.
- Soft-deleted patients are excluded by default.
- Duplicate detection never crosses tenants and never treats identifiers as the sole match key.
- Merge requires `patient.merge`, a reason, and same-tenant source/target. Source becomes
  `status=merged`, points to target, and is soft-deleted.
- Unmerge restores the source and child rows moved by the merge snapshot only; target records
  created after merge remain on the target (D-022).
- Patient consents move with patient merge/unmerge snapshots.
- Consent checks fail closed: no non-expired granted consent carrying the requested signed scope
  means `false`; withdrawn/expired consents never grant access.
- Captured consents keep immutable template text/scope snapshots even if templates are edited or
  superseded later (D-023).
- Consent grant/withdraw writes patient-scoped audit actions `consent.granted` and
  `consent.withdrawn`; read-logging remains reserved for patient record reads.
- Portal access is fail-closed: invite, activation, password login, and `/portal` access require
  an active `portal.access` consent.
- Patient portal auth uses the `patient` guard only. Patient accounts cannot satisfy staff/admin
  `web` guard routes, and staff users cannot satisfy portal guard routes.
- Portal sessions are tenant-bound (`portal_tenant_id`) and cross-tenant session tampering is
  denied before consent can grant access.
- Portal document access is fail-closed: only `shared_with_patient=true` documents for the
  authenticated account's own patient are visible/downloadable, and every download is read-logged.
- Patient UI routes are staff `web` guard only and RBAC-gated: `patient.view` for index/show,
  `patient.edit` for register/create/duplicate-check and consent/portal actions.
- Patient 360 viewing writes the existing patient-scoped `read` audit row and surfaces the
  tenant-scoped `PatientAccessReport`.
- Registration duplicate warnings are live client calls to `/patients/duplicates`, using B.3
  scoring before the create POST.

## Status

**Phase B COMPLETE.** Patients module registered; CRM core tables/models, MRN generator,
transactional service, read-logging, access-report stub, demographic duplicate detection,
permissioned audited merge, snapshot-based unmerge, consent engine, portal accounts, and first
staff-facing patient UI are in place.

**P0G.G5 completed the patient portal UI**: `pages/Portal/{Login,Home,Appointments,Documents,
Messages,Invoices,Consents,Telehealth}.vue` on a dedicated `PortalLayout` (never the staff shell);
every page behind `portal-tenant` + `portal-auth` + `portal-consent`, so withdrawing
`portal.access` locks the portal on the next request (tested). `PortalConsentController` lists own
consents and withdraws via `ConsentService` (own rows only); `PortalAuthController` gained
`showLogin` (Inertia) and `logout`. Self-booking goes through `BookingService::bookOnline` (the
locked no-double-book path; identity from the session's portal account only); cancellation enforces
`scheduling.portal.cancel_min_hours` (default 24) server-side and runs through the new
`AppointmentService::cancelForPatient` (ownership fail-closed, patient actor audited). Documents
keep the D.4 posture (shared-only, controller-streamed, read-logged; content-negotiated
Inertia/JSON index); invoices are own-only read-only with balances from `invoice_balances` and
read-logged private-disk PDF streaming — NO payment processing (PSP deferred).

## Open items

- Dev MariaDB 10.4 uses plain FULLTEXT while MySQL 8 CI/prod uses `WITH PARSER ngram` - patient
  name search tokenizes differently across environments; validate search parity before production.
- Later gates must call `ConsentService::has()` before portal access or clinical data sharing.
- D.4 Clinical document sharing now calls `ConsentService::has(patient, 'portal.access')` before
  exposing documents to the portal.
- MySQL 8 CI should verify the `WITH PARSER ngram` path; local MariaDB 10.4 lacks the ngram parser
  and uses the migration fallback FULLTEXT index.

## PC.P1 — shared clinical components + B1, the recorded-allergy wiring (2026-08-21, `c5f4772`)

First gate of the **PC chain** (`docs/wireframe-parity/PATIENTS-CLINICAL-BATCH-DIFF.md` §7).
Presentational (P0D.GU) plus **one narrow read-only payload addition**.

**S1 PROMOTED.** `PatientClinicalHeader.vue` moved from `Components/Dental/` to
`Components/Clinical/` — it is a clinical component that dental merely happened to need first.
All four dental callers updated. **Behaviour-identical, proven the DENTAL-B.P1 way:** the rendered
dental header was captured from a real browser before and after the move and is **byte-for-byte
identical (381 normalised chars)**. The existing dental tests were not modified.

**B1 — THE ALLERGY WIRING, AND WHERE IT HAD TO LIVE.** `Modules\Patients` **may not use**
`Modules\Clinical` — an arch test enforces it. So `PatientShowController` **moved to the app
layer** (`app/Http/Controllers/`), which is exactly why `AppointmentDetailController` already
lives there: it composes Scheduling + Patients + Clinical + Audit (D-017). The move changed the
class's namespace and nothing else — same route, same `patient.view` gate, same payload, **the
same single `auditRead()` row** (asserted: `substr_count(... 'auditRead(') === 1`, so the allergy
read adds no second audit path).

**What `Patients/Show.vue` was already waiting for.** The page has carried a dormant top-level
`allergies` prop and a hidden banner since it was built, commented *"not part of Patients/Show's
payload today — rendered when present, absent silently until the prop lands"*. The wireframe names
the same gap. **Landing that prop lights the banner with no page rewrite** — the smallest possible
change that closes the gap.

**The banner now shows the RECORDED facts** — substance · reaction · the severity a CLINICIAN
recorded — each chip styled IDENTICALLY, plus an **honest empty state** ("No allergies recorded for
this patient"), because *none recorded* is a different statement from *we did not look*. Only
`active` rows appear, matching the composition `AppointmentDetailController` already uses so the
two surfaces cannot disagree. **Ordered by SUBSTANCE, never by severity** — ordering by badness
would be the system asserting a priority it has no business asserting.

**I did NOT swap Patient 360's hero for S1.** Its hero carries a status pill, a flag chip and the
dental cross-link that S1 has no props for; replacing it would have regressed the page. Patient 360
parity is **PC.P3's** gate.

**THE THREE NEW SHELLS** (`Components/Clinical/`): **N1 `ClinicalRailCard`** (titled box + slot +
honest empty state; `count` is a caller-supplied STRING so it cannot be handed a number to compute
on), **N3 `AccessLogRow`** (who · what · when · surface · basis — **basis is server-derived and
merely printed; inferring it here would make an audit worthless**), **N6 `SignOffBar`** (chrome
only: it renders the actions the caller passes and **performs no signing logic** — no router, no
form, no fetch; disabling a button hides an affordance, it is not a gate, per D-168).

**THE FENCE SCAN NOW FOLLOWS THE HEADER.** Moving S1 out of `Components/Dental/` would have
silently dropped it from `SharedComponentsTest`'s glob — a weakening by relocation. Both
`dscPath()` and the recursive scan now resolve **either** namespace. This mattered immediately: the
severity-tint mutation below was caught **by that test**, which would otherwise have gone quiet.

**Tests added** — `tests/Feature/Patients/PatientClinicalSharedTest.php` (7 tests, 276 assertions).
**Mutation-checked three ways:** tinting the allergy chip by `allergy.severity` (caught by the
followed-along P1 fence test), adding a computed `crossReacts` field to the payload (caught twice —
the exact-keys assertion and the fence token scan), and giving N6 a `router.post` + a `tone` prop.

**Three test-side bugs of my own:** `recorded_by` needs a **StaffProfile**, not a User (the model
asserts the reference); my arithmetic regex matched Tailwind's `border-line/60` opacity syntax, so
it now reads the `<script>` block only (banning a class utility would teach the next author to
weaken the scan — the D-166 `baseline` lesson); and `fall` as a squashed substring matched
"fallback", so the fence token is now `fallrisk` — the judgment is a fall-RISK SCORE, not the word.

## PC.P3 — Patient 360 parity (2026-08-21)

**S1 was EXTENDED, not forked.** Patient 360's hero and the dental band are now the SAME component. The
four new props (`status`, `links`, `variant`, `initials`) are **optional**, `compact` is the **default**,
and the hero's avatar + watermark are **absolutely-positioned decoration** — so the dental callers' markup
is untouched. A first attempt wrapped the band in an avatar row; that would have altered the compact DOM
and broken PC.P1's byte-identity, and it was rewritten **before** it was built. If you add a fifth prop,
keep it optional and keep the hero additive, or the dental surfaces silently change.

**THE FLAG CHIP IS GONE AND MUST STAY GONE (D-176).** The hero shipped `⚑ Flag` as a hardcoded, unbound
span on **every** patient. `Patient` has **no flag column, no fillable attribute and no migration** — it
asserted a documented fact that does not exist. Don't "fix" it by adding a boolean: a flag is meaningful
only as a CLINICIAN-RECORDED fact (who flagged, why, when), and deriving one from the record would be a
computed risk marker the fence forbids. The i18n key `patients.show.headerFlag` was deleted too, and the
shared header deliberately has **no `flag` prop** — a prop is an affordance and the next author fills it.
A test pins all four absences and was mutation-checked.

**Counts are server-computed** (`counts.{contacts,coverages,consents,identifiers,allergies,accessLog}`,
allergies counting ACTIVE only). `PatientAccessReport::forPatient()` is uncapped **today**, so the Vue
lengths were accurate — but that is a property of today's payload, not a guarantee, and it is exactly the
assumption that broke on the chart at PC.P2. Count where the record is.

**Helper-name collision, second time.** `p3Ctx()` clashed with `CrossTenantIsolationTest` — green in a
single-file run, **fatal across the suite**, and `composer check` still exited 0. Pest helpers are global:
prefix them uniquely (`p360*`) and grep the whole `tests/` tree before naming one.

## PC.P5 — the access log + subject-access export (2026-08-21)

**`PatientAccessReport` now has exactly ONE query, and it must stay that way.** `forPatient()` (the
360 tab, oldest-first) and `forPatientNewestFirst()` (the dedicated screen AND the export) both
funnel through the same private `query()`. The export sharing the screen's query is the whole point:
a subject-access file that disagrees with what the requester was shown is worse than none.

**COMPLETENESS IS THE PROPERTY (D-178).** The query filters on patient + `action = 'read'` and
NOTHING else — no actor-type, surface, role or recency whitelist. Do not add one. The filter chips
are built from the actor types actually present so a new kind of reader cannot fall outside a
hardcoded taxonomy. Reads come from ~65 `auditRead()` call sites across every module plus the AI
tools; agent reads are identified by their recorded SURFACE (`*_agent`), not by actor_type.

**THE KNOWN GAP — do not 'fix' it by inventing a link.** Operator/platform-support events are
written against the TENANT: action `operator.access`, `actor_type = 'operator'`, **no `patient_id`**.
They cannot be attributed to a patient. The screen states this. If a future gate wants operator
reads in a patient's log, the change belongs in the operator path (attribute the read to a patient
at the point it happens), never in this report.

**Two traps this gate hit, both worth remembering:** `COUNT(DISTINCT a, b)` in MySQL drops the row
when EITHER value is NULL — it silently uncounted system reads (no actor id); use a COALESCE'd
concat. And `OperatorGrantAccessTest` pins the exhaustive list of files that may mention an operator
grant: even naming `OperatorGrantService` in a DOCBLOCK trips it. Reword the comment; never add your
file to that list.

### PT.P5 — Portal Consents (2026-08-23)

The consent surface now states, per consent, what it ALLOWS and what withdrawing it ACTUALLY does.
`PortalConsentController::DESCRIBED_CONSENTS` is the list the product has copy for — `portal` and
`comms` — and it is not a style choice: **those are the only two scopes anything in CareOS enforces**
(`portal.access` · `comms.email`). A consent outside the list is still listed, but comes back with
`copy_key = null` and the page says plainly that we cannot describe the effect, rather than reusing a
reassurance no code delivers (D-176).

**If you add a consent template, add its copy AND its enforcement — or leave the fallback to fire.**
`PortalConsentsParityTest` fails if a described scope is checked nowhere outside the tests.

**The comms consequence deliberately admits a carve-out:** `NotificationService` never consent-gates
the LEGAL category (D-F7), so the copy says statutory notices are unaffected. Removing that clause
would turn true copy into an over-claim; the test asserts a dunning email still sends after withdrawal.

**Four layers enforce `portal.access` withdrawal and each is pinned separately** (D-183): the portal
middleware, `PortalAccessService::login()`, `DocumentService::shareWithPatient()` and — from PT.P4 —
`ThreadService`. Test the inner ones with DIRECT service calls; over HTTP the middleware answers first
and lets them be deleted in silence.

### PT.P6 — the portal invite landing page (2026-08-23)

`GET/POST /portal/invite/{token}` (`PortalInviteAcceptController` → `Portal/AcceptInvite.vue`) is the
patient-facing enrolment page. **Do not confuse it with `/invite/{token}`, which is the STAFF invite**
(`StaffInviteAcceptController`) — the parity inventory conflated the two for a whole batch.

**The token contract:** SHA-256 hash stored (never the token), bcrypt OTP, **30-minute** expiry,
single-use via `consumed_at`, tenant taken FROM the token. `PortalAccessService::previewInvite()` and
`tokenForInvite()` now share ONE definition of redeemable, so the page can never show an invitation the
POST would refuse.

**Every dead token renders `{"valid": false}` and nothing else** — no token echo, no address, no
reason. If you touch this page, keep it that way: `PortalInviteLandingTest` compares the four refusal
bodies for equality, and the staff page's habit of echoing the token back is exactly the mutation it
catches.

**Enrolment captures NO consent.** `portal.access` must already be granted by staff before an invite can
even be issued, and consent rows need a staff `captured_by` — there is no patient self-grant path.

### PT.P7 — the portal password-reset broker (2026-08-23) · PORTAL CORE COMPLETE

`/portal/forgot-password` + `/portal/reset/{token}` (`PortalPasswordResetController` →
`Portal/Password/{Forgot,Reset}.vue`). **There is no second broker and no second token table:** it is
`PortalLoginToken` with `PURPOSE_PASSWORD_RESET`, and `redeemableToken($token, $purpose)` is the ONE
definition of "alive" shared by both flows. Add a purpose there, not a table.

**Rules to keep if you touch this:**
- the request form answers IDENTICALLY to a live account, an unknown address, a never-invited patient
  and a disabled one — and every dead token renders `{"valid": false}` and nothing else;
- a reset changes ONLY the password: no activation, no consent, **no sign-in**. The patient goes through
  `login()` afterwards, where the `portal.access` re-check still refuses (PT.P5);
- a new request SUPERSEDES the account's older live reset links.

**Not built, deliberately:** "signed out everywhere else". Portal sessions are not tracked per account
(no `AuthenticateSession` in the portal stack), so the page does not claim it. Real follow-up.

**Testing trap:** `TenantContext::system()` is a NO-OP while a tenant is in context — `TenantScope`
checks `has()` first. To test tenant binding, change where the TENANT comes from (session vs token), or
force a token whose tenant disagrees with its account's; wrapping the lookup in `system()` proves
nothing.


## QA phase 9 (2026-09-08) — the disclosure fence, audit only

Phase 9's assigned fence was **disclosure**: who may release a record, to whom, is consent enforced, and
does the release appear in the patient's own access log. Driven as `him_records` (provisioned through
`/admin/roles`, restored afterwards) with `doctor` and `billing` used only for positive controls.

**THERE IS NO RECORDS-RELEASE CAPABILITY — that absence is the finding.** No model, migration, service,
controller, route or page has releasing a record as its subject. Every occurrence of "disclosure" in PHP
is a docblock describing an existing *read*. The only third-party-facing artifact, the Referral,
deliberately transmits nothing and says so. `audit_events` has no recipient, purpose or legal-basis
column, so even a correctly audited release could record only *"staff member X touched resource Y"*.

**CONSENT IS ENFORCED, NOT PROMPTED — proven live in both directions.** With `portal.access` granted, a
`doctor` released a document (200). The consent was then **withdrawn through the real screen** (Patient
360 → Consents → reason → Withdraw) and the identical POST returned **403 "Portal access consent is
required to share documents."** — `DocumentService.php:105`. Four hard consent gates exist
(`shareWithPatient`, `TelehealthService:110`, `ThreadService:408`, `NotificationService:177`); the two
display-only flags (`RecallWorklistController:131`, `InboxPatientContextReader:195`) are backed by
`NotificationService` downstream. `EnsurePortalConsent` guards the whole portal route group, so a
withdrawal fail-closes the portal on the next request. **Observation, not a finding:** withdrawal does not
retract `shared_with_patient` — the flag goes stale but is unreachable while the middleware holds.

**`P9-C2` (CRITICAL) — A RELEASE NEVER APPEARS IN THE PATIENT'S ACCESS LOG.** `PatientAccessReport::query()`
filters `action = 'read'`; a release is audited as **`document.shared`** by the `DocumentChanged` listener
(`AppServiceProvider:789-805`) — patient-scoped, hash-chained, and excluded. Driven twice: against the
seeded release (22:56:40) and against one I performed myself (23:20:44). Neither the dedicated PC.P5
screen nor the Patient-360 tab shows it. **The screen states the opposite in its own words** — *"No actor
type, surface or age of entry is filtered out"* — and names exactly one limitation (operator mode);
`PatientAccessReport`'s docblock says *"ONE KNOWN GAP, RECORDED RATHER THAN PAPERED OVER"*. The gap is
wider than documents: every non-`read` action carrying a `patient_id` is structurally invisible
(`document.uploaded`, `consent.granted`, `consent.withdrawn`, the ADT events).

**`P9-C1` (CRITICAL) — patient identifiers leave in a CSV with no audit row at all.**
`BillingReportController::export()` (gated `billing.view`) streams `top_overdue:<patient_id>` rows with
each patient's overdue balance, days overdue, dunning stage and invoice count. The audit table was
snapshotted before the drive and read after: the export produced **zero** rows. Missing from the ledger,
and — having no `patient_id` — unable to appear in any patient's access log even if it existed. A Phase-3
miss, found only because this phase's fence forces the completeness question.

**`P9-H4` — `document.view` gates nothing.** `DocumentDownloadController`'s only gate is
`Gate::authorize('patient.view')`; a whole-tree grep for `document.view` returns four hits and not one is
a gate (its catalog definition, two role templates, an operator PHI mapping). **25 of the 26 role
templates hold `patient.view`** — every role but `billing` — so there is no narrower door for record
access than "can see patients", and the permission created as the HIM fence controls nothing.

**`P9-H5` — the records role cannot do records work.** `him_records` is refused the only release action
(403 *"This user cannot manage clinical documents."* — `note.write`) and consent capture (403 —
`patient.edit`), while it CAN download any clinical document and export a patient's whole access log.
Authority to release sits with clinical authors; authority to consent with front-desk and clinicians.
There is also **no Documents tab** — Patient 360 is Demographics / Contacts / Coverages / Consents /
Access log, and no staff-facing document page exists at all.

**What is honest and worth keeping:** the access log is a **single query** shared by screen, tab and
export, so the CSV cannot disagree with the screen; viewing and exporting it are themselves audited as
patient-scoped reads; and `audit.export` is deliberately narrower than `audit.view`, putting
`him_records` on the read-only side of the one correctly built export fence. Lesser items: the Patient-360
tab renders raw actor ids where PC.P5 renders names (`P9-L3`); the portal consent screen advertises
`documents.read`, `messages.write` and `research.share`, none of which any template seeds or any code
enforces (`P9-L2`, the D-176 shape); `BreakGlassService` — the one component that demands a written reason
— has **no production consumer**, so no read anywhere records *why* it happened (`P9-L4`). See
[[Hospital]], [[Audit]], [[Billing]], [[LOG]].

## QA phase 10 (2026-09-09) — the portal as an outsider. Audit only.

**OWN-DATA-ONLY HOLDS ON EVERY ID-BEARING ROUTE, READ AND WRITE — the cleanest isolation result of the
programme.** Signed in as one portal patient, forging another's ids: `GET /portal/documents/{other}` **404**,
`GET /portal/invoices/{other}/pdf` **404**, `POST /portal/consents/withdraw` **404**,
`POST /portal/appointments/cancel` **404**, `POST /portal/check-in` **404**,
`POST /portal/telehealth/{other session}/token` **403** *"This patient is not part of this telehealth
session."*, `POST /portal/messages` (other's thread) **403** *"This patient cannot access this thread."*
Own ids return 200. **Not one cross-patient read or write succeeded.**

**PORTAL READS REACH THE PATIENT'S OWN LOG — for six of eight surfaces.** Recorded with
`actor_type = patient`, rendered as **"Patient (self)"**: `portal_home`, `portal_appointments`,
`portal_documents`, `portal_document_download`, `portal_invoices`, `portal_invoice_download`,
`portal_consents`, `portal_checkin`. **`P10-H5` (HIGH):** `PortalMessageController` and
`PortalTelehealthController` have **0** `auditRead` calls each, against 2–3 in every sibling, so the
patient's own conversations and video-visit schedule are disclosed with no row — inconsistent with a rule
the codebase states eight times in the same words. (`PortalTreatmentPlanController` audits per plan inside
the map, so an empty plan list writes nothing — correct by construction, not a gap.)

**SCREEN AND EXPORT AGREE, AND EACH EXPORT AUDITS ITSELF.** Screen: *"30 recorded accesses by 2 distinct
actors"* (chips `Patient (self) · 23` / `Staff user · 7`); CSV taken seconds earlier: 29 rows — the
difference is the screen's own self-audit. Two consecutive exports returned **31 then 32** rows: one row per
export, appearing in the next one, exactly as the page promises.

**`P9-C2` RESTATED FROM THE PATIENT'S SIDE AND CONFIRMED.** Erika Baumgartner has a document shared with her
(`shared_with_patient = 1`) and her subject-access export contains **no** row mentioning it — the report
filters `action = 'read'`, a release is `document.shared`. The nDSG Art. 25 / GDPR Art. 15 artifact remains
incomplete in exactly the category a subject-access request is about.

**ENROLMENT AND RECOVERY ARE WELL BUILT, driven.** Three different bad invite tokens each returned HTTP 200
with **byte-identical** copy (*"This invitation is no longer valid."*) — unknown, expired, used and
wrong-tenant are indistinguishable. Password reset answers a known and an unknown address identically
(*"If an account exists for that address…"*, single-use, 30 minutes). A wrong portal password is refused
visibly — and **my grep of `Portal/Login.vue` for `errors` returned zero while the browser showed the
message**, which is the method lesson of the phase.

**`P10-M2` (MEDIUM) — the portal shows the VIEWER's calendar day.** At one instant: staff app *"Mittwoch,
9. September 2026"*, portal *"TUESDAY, SEPTEMBER 8, 2026"*. An appointment I booked for 2026-09-09 08:00 —
today in `Europe/Zurich` — is labelled **"tomorrow"**. `P10-M3`: `/portal/documents`, `/portal/messages` and
`/portal/consents` print raw UTC (`2026-09-09 01:08:58`) beside that viewer-zone header.

**`P10-L1` (LOW):** with a staff session and a portal session live in one browser, portal reads were
attributed to the staff user in the access log. Mostly an artifact of my dual-session setup — a patient's
browser has no staff session — but a shared practice terminal is a real place where both could exist.

**PORTAL RESPONSIVE — THE FIRST PASS IN TEN PHASES.** At 390 px all eight portal nav links render at 36 px
height with real widths, the invoices table sits in an `overflow-x: auto` wrapper, and the document does not
scroll horizontally. The staff shell still fails the same check. See [[Scheduling]], [[Platform]], [[LOG]].

**WHAT REACHES A PATIENT'S ACCESS LOG IS `action = 'read' AND patient_id = ?` — NOTHING ELSE (D-221).**
`PatientAccessReport` has exactly one query and all three of its methods bind the literal `'read'`. Two
consequences worth knowing before writing any audit row that represents a disclosure:

- **A new action string is invisible here.** A well-formed, hash-chained, patient-scoped row with any other
  action reaches the tenant ledger and nobody's access log. QA-FIX.10a's first attempt did exactly that and
  the mutation test caught it: the ledger assertions stayed green while *"reaches the access log"* went red.
- **A row that names several patients in its `context` reaches none of them.** The query matches on the
  `patient_id` COLUMN, so a multi-patient disclosure needs one row per patient.

**`P9-C2` WAS THE OTHER HALF, AND IT IS NOW FIXED (QA-FIX.10b, D-222).** The set is
`PatientAccessReport::DISCLOSURE_ACTIONS = ['read', 'document.shared', 'document.unshared']`, named ONCE
and used by all three queries in the class. **Read that constant before writing any audit row you expect a
patient to see** — and prefer a `read` row with an export surface (D-221) over adding to the set.

**THE BOUNDARY, AND WHY IT IS NOT "EVERYTHING WITH A PATIENT_ID".** A row belongs when it records this
patient's record being made VISIBLE OR AVAILABLE to someone. 982 rows in the live ledger carry a patient id
and only 26 are disclosures; the rest are activity ON the record — `charge.captured` (163),
`charge.validated` (151), `planned_visit.materialized` (89). Twelve activity actions are asserted ABSENT by
test, including two borderline cases rejected with reasons: `referral.sent` (CareOS transmits nothing, so
listing it would assert a disclosure the product did not make) and `notification.sent`.

**THERE IS DELIBERATELY NO AUTOMATIC CLASSIFIER.** A scan over action literals was built and rejected: ~15
action strings are assembled by interpolation (`'admission.'.$status`), so it would look exhaustive without
being so — the shape of the finding itself. The guard is a two-directional test plus a contract test that
reads the PHP constant and fails if a member has no phrase in `en.json`.
