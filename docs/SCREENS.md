# CareOS — Screen Inventory (docs/SCREENS.md)

**Purpose:** the factual foundation for the design pass. Every Inertia page and nurse-PWA screen in
the product is listed here with its route, guards, prop contract, and dispatched actions. A designer
can re-skin any page from this file without reading a controller — and because of the standing UI
rule (P0D.GU: components are presentational; authorization, validation, and state transitions are
enforced and tested SERVER-SIDE), replacing a `.vue` file cannot change a rule. Routes, controllers,
props, guards, and tests stay untouched by a redesign; CI fails instantly if a contract breaks.

**Frozen as of P0G.C** (Phases 0 + A–G complete). 22 Inertia pages + the nurse PWA (11 internal
screens/states). Grouped by area below.

**Cross-cutting invariants a redesign must preserve:**
- All user-facing text through vue-i18n keys (`resources/js/lang/en.json`) — no hardcoded strings.
- Shared Inertia props on every staff page: `appName`, `locale`, `auth.user { id, name, email, isSuperAdmin, tenantId } | null`, `flash.status`.
- Layouts: `GuestLayout` (auth + public booking), `AppLayout` (staff shell), `PortalLayout` (patient portal — never mix with the staff shell).
- AI surfaces keep their visible labels: the chart AI-summary label, the inbox amber "AI-assisted" draft box with source references and explicit send, the `ai_assisted` badge on messages, and the red clinician-attention banner.
- Allergy banners stay prominent; vitals stay raw and uninterpreted; the public-booking non-emergency notice stays visible.
- Buttons may be shown even when an action would be refused — the server is the sole judge; error states returned by the server must remain visible.


## Auth & shells

### Auth/Login — staff/super-admin sign-in form (email + password + remember)
- **Route:** GET /login (`login`) — rendered by Fortify's `AuthenticatedSessionController::create` via `Fortify::loginView()` in `app/Providers/FortifyServiceProvider.php`
- **Guards:** `web` group + `guest:web` (authenticated users are redirected away). The `web` group also appends `HandleInertiaRequests`, `IdentifyTenantFromUser`, `EnsureTwoFactorEnabled` — all three self-skip for guests.
- **Props:**
  - page declares no `defineProps`; the server passes `status: string | null` (session flash from `Fortify::loginView`) which the template does not currently render
  - shared Inertia props available everywhere: `appName: string`, `locale: string`, `auth.user: { id: number, name: string, email: string, isSuperAdmin: boolean, tenantId: number | null } | null` (null here), `flash.status: string | null`
  - local form state: `{ email: string, password: string, remember: boolean }`
- **Actions:**
  - Submit form → POST /login (`login.store`) → Fortify `AuthenticatedSessionController::store`; `guest:web` + `throttle:login` (5/min per lowercased email + IP); custom `Fortify::authenticateUsing` checks email + password hash and fail-closed rejects tenant staff whose tenant is missing or `suspended` (indistinguishable from bad credentials); users with confirmed 2FA are diverted to /two-factor-challenge (`RedirectIfTwoFactorAuthenticatable`); otherwise `RoleBasedLoginResponse` redirects super-admins → /admin, everyone else → /app (un-enrolled users are then bounced to /two-factor/enrollment by `EnsureTwoFactorEnabled`). `remember` is transformed to `'on'`/`''` before posting; password field resets on finish.
- **Notes:** i18n prefix `auth.login.*` (title, subtitle, email, password, remember, submit); uses `GuestLayout` (centered column on `bg-surface-muted`, brand mark "C" tile + `app.name`, content constrained to `max-w-md`) with shared `Card`, `Input`, `Button` components; field errors come from `form.errors.email` / `form.errors.password` and must stay visible; submit button disables while `form.processing`. There is no self-registration or "forgot password" link on this page (password reset routes exist server-side but are unlinked).

### Auth/TwoFactorChallenge — post-login TOTP / recovery-code verification
- **Route:** GET /two-factor-challenge (`two-factor.login`) — rendered by Fortify's `TwoFactorAuthenticatedSessionController::create` via `Fortify::twoFactorChallengeView()`
- **Guards:** `web` group + `guest:web`; only meaningful when the session holds `login.id` from a successful password step (otherwise Fortify redirects back to /login)
- **Props:**
  - none (no `defineProps`, no server-passed page props)
  - local state: `useRecovery: Ref<boolean>` (client-only toggle), form `{ code: string, recovery_code: string }`
- **Actions:**
  - Verify → POST /two-factor-challenge (`two-factor.login.store`) → Fortify `TwoFactorAuthenticatedSessionController::store`; `guest:web` + `throttle:two-factor` (5/min keyed by session `login.id`); validates the TOTP `code` or a one-time `recovery_code`; on success `RoleBasedLoginResponse` redirects super-admin → /admin else /app
  - "Use recovery code" / "use code" link → pure client toggle (`useRecovery`), swaps which single input is shown; no request
- **Notes:** i18n prefix `auth.twoFactor.*` (challengeTitle, challengeSubtitle, code, recoveryCode, verify, useCode, useRecovery); `GuestLayout` + `Card`/`Input`/`Button`; code input uses `autocomplete="one-time-code"`; errors render from `form.errors.code` / `form.errors.recovery_code`; exactly one input is visible at a time — keep the toggle link full-width under the submit button.

### Auth/TwoFactorEnroll — mandatory MFA enrollment (QR + recovery codes + confirm)
- **Route:** GET /two-factor/enrollment (`two-factor.enrollment`) — closure route in `routes/web.php`, renders the page directly (no controller)
- **Guards:** `auth` + `web` group. `IdentifyTenantFromUser` sets tenant context (403 if the staff user's tenant is missing/suspended). `EnsureTwoFactorEnabled` forces every authenticated, un-enrolled user (staff and super-admins alike) to this page and explicitly exempts it — plus the Fortify `user/two-factor-*` endpoints, `two-factor-challenge`, and `logout` — so enrollment cannot lock the user out. Fortify 2FA options: `confirm: true`, `confirmPassword: false` (no password re-confirmation step).
- **Props:**
  - none (no `defineProps`); everything is fetched client-side via axios
  - local state: `qrSvg: Ref<string>`, `recoveryCodes: Ref<string[]>`, `code: Ref<string>`, `error: Ref<string>`, `ready: Ref<boolean>`
- **Actions:**
  - On mount (automatic) → POST /user/two-factor-authentication (`two-factor.enable`, `auth:web`) → Fortify generates the pending TOTP secret; then in parallel GET /user/two-factor-qr-code (`two-factor.qr-code`) → `{ svg: string }` and GET /user/two-factor-recovery-codes (`two-factor.recovery-codes`) → `string[]`; only after both resolve does `ready` flip and the card content appear
  - Confirm → POST /user/confirmed-two-factor-authentication (`two-factor.confirm`, `auth:web`) with `{ code }` → Fortify verifies the TOTP code and marks 2FA confirmed; on success the client does `router.visit('/app')`; on failure the input shows `t('auth.login.invalid')`
- **Notes:** i18n prefix `auth.twoFactor.*` (enrollTitle, enrollSubtitle, enrollStep1, enrollStep2, recoveryCodesTitle, recoveryCodesHint, confirm) plus `auth.login.invalid` for the error; `GuestLayout` + `Card`. The QR code is server-generated SVG injected with `v-html` inside a bordered `bg-surface` tile — do not replace with an `<img>`. Recovery codes render as a 2-column monospace grid on `bg-surface-muted` and must remain copyable text. Until `ready` is true the card body is empty (no spinner exists — a designer adding one must not block the mounted fetch flow).

### App/Landing — tenant staff shell landing (placeholder dashboard)
- **Route:** GET /app (`app.landing`) — inline closure `Inertia::render('App/Landing')` in `routes/web.php` (no controller, no page-specific data)
- **Guards:** `auth` + `web` group appends: `IdentifyTenantFromUser` (loads the user's tenant into `TenantContext`; aborts 403 `platform::auth.tenant_suspended` if the tenant is missing or suspended; no-op for super-admins) then `EnsureTwoFactorEnabled` (redirects any user without confirmed TOTP to `two-factor.enrollment`). This is also the default post-login / post-2FA target for non-super-admins via `RoleBasedLoginResponse`.
- **Props:**
  - none (no `defineProps`); consumes only shared Inertia props — `AppLayout` reads `auth.user: { id: number, name: string, email: string, isSuperAdmin: boolean, tenantId: number | null } | null` to show the user's name
- **Actions:**
  - page itself dispatches nothing; via `AppLayout` header: nav `Link`s → GET /patients (`patients.index`), GET /patients/register (`patients.register`), GET /scheduling/day-board (`scheduling.day-board`); Sign out button → POST /logout (`logout`, Fortify `AuthenticatedSessionController::destroy`, `auth:web`) → session invalidated, back to /login
- **Notes:** i18n prefixes `shell.app.*` (title, welcome, empty) and `app.*` for the layout (name, nav.patients, nav.register, nav.schedule, signOut); uses `AppLayout` (top header: brand tile "C" + app name, center nav hidden below `md`, user name + sign-out right; `max-w-6xl` content column on `bg-surface-muted`). Body is a single empty-state `Card` — `shell.app.empty` copy is the deliberate placeholder until real dashboard widgets land; keep the header nav links working.

### Admin/Landing — platform super-admin shell landing (placeholder)
- **Route:** GET /admin (`admin.landing`) — inline closure `Inertia::render('Admin/Landing')` in `routes/web.php` (no controller, no page-specific data)
- **Guards:** `auth` + `super-admin` (`EnsureSuperAdmin`: aborts 403 unless `user->isSuperAdmin()`, i.e. `tenant_id` null) + `web` group appends `IdentifyTenantFromUser` (no-op for super-admins — tenant context stays empty / platform scope) and `EnsureTwoFactorEnabled` (MFA is mandatory for super-admins too). Default post-login target for super-admins via `RoleBasedLoginResponse`; `/` also redirects here for logged-in super-admins.
- **Props:**
  - none (no `defineProps`); same shared Inertia props as App/Landing (`auth.user`, `appName`, `locale`, `flash.status`)
- **Actions:**
  - page itself dispatches nothing; inherits `AppLayout` header actions: nav `Link`s to /patients, /patients/register, /scheduling/day-board and Sign out → POST /logout (`logout`) — note these tenant-app links are currently shown even in the admin shell because both shells share `AppLayout`
- **Notes:** i18n prefix `shell.admin.*` (title, welcome, empty); currently reuses tenant `AppLayout` verbatim — a redesign must not merge the two landings into one page (route names, guards, and i18n keys differ) and should expect an admin-specific nav later. Body is a single empty-state `Card` with `shell.admin.empty` placeholder copy.

## Patients (staff)

### Patients/Index — searchable patient directory (name + DOB) with links into the Patient 360

- **Route:** GET /patients (`patients.index`)
- **Guards:** `web` group (session, CSRF, `HandleInertiaRequests`, `IdentifyTenantFromUser`, `EnsureTwoFactorEnabled`) → `auth` middleware → `Gate::authorize('patient.view')` (tenant-scoped RBAC permission, resolved via the Platform RBAC gate).
- **Props:**
  - `filters: { q: string; date_of_birth: string }` — echo of the current query-string filters (both may be empty strings, never null)
  - `patients: Array<{ id: string; mrn: string; first_name: string; last_name: string; date_of_birth: string; sex: string; status: string; show_url: string }>` — max 25 rows, ordered last_name then first_name; `date_of_birth` is `YYYY-MM-DD`; `show_url` is the absolute `patients.show` URL
- **Actions:**
  - Submit the search form (name text + DOB date input) → GET /patients (`patients.index`) via Inertia `router.get` with `preserveState + replace` → server re-runs `patient.view` gate; name matched via MySQL full-text BOOLEAN MODE on first/last name plus LIKE fallback, DOB matched by exact date; result capped at 25.
  - Click "Register" header link → GET /patients/register (`patients.register`) → server enforces `patient.edit`.
  - Click a patient's name in the table → GET `patient.show_url` (`patients.show`) → server enforces `patient.view` and writes a read-audit entry.
- **Notes:** Layout is `AppLayout`; all strings via vue-i18n under the `patients.index.*` and `patients.fields.*` key prefixes (page `<Head>` title = `patients.index.title`). Results render as a 4-column table (Name, MRN, DOB, Status) inside a `Card`; keep the dedicated empty-state row (`patients.index.empty`, colspan 4, centered) when zero results. Search form is a 3-column grid (`1fr / 220px / 140px`) using shared `Input` and `Button` components. There is no pagination — the 25-row cap is server-side and silent.

### Patients/Register — 4-step patient registration wizard with live duplicate detection

- **Route:** GET /patients/register (`patients.register`)
- **Guards:** `web` group (session, CSRF, `HandleInertiaRequests`, `IdentifyTenantFromUser`, `EnsureTwoFactorEnabled`) → `auth` middleware → `Gate::authorize('patient.edit')` (RBAC "Create and edit patients").
- **Props:**
  - `duplicateCheckUrl: string` — URL of `patients.duplicates.check` (POST endpoint for live duplicate lookup)
  - `storeUrl: string` — URL of `patients.store` (POST endpoint that creates the patient)
- **Actions:**
  - Type in Step 1 identity fields or Step 2 address fields → debounced 300 ms `fetch` POST /patients/duplicates (`patients.duplicates.check`, JSON + CSRF header) → server enforces `patient.edit`, validates nullable demographics (first/last name, DOB, postal, city, line1, identifiers[]), runs `DuplicateDetector::findForDemographics`, returns top 5 candidates with `score > 0` as `{ id, name, mrn, date_of_birth, score, confidence, reasons, show_url }`. The client only fires when first name, last name AND DOB are all filled; otherwise it clears the candidate list.
  - Click "Open" on a duplicate candidate → GET `candidate.show_url` (`patients.show`) → server enforces `patient.view` (navigates away from the wizard; entered data is lost).
  - Click "Create" on Step 4 (review) → `form.post` POST /patients (`patients.store`) → server enforces `patient.edit`; validates first_name/last_name/date_of_birth/sex required, gender/preferred_language optional, plus contacts[], identifiers[], coverages[] arrays; `PatientService::create` runs in one DB transaction, auto-generates the MRN, creates child contact/identifier/coverage rows, then redirects to `patients.show`.
- **Notes:** Layout `AppLayout`; i18n prefixes `patients.register.*` and `patients.fields.*`. Steps are driven by the shared `StepNav` component with 4 labels (identity, contacts, optional, review) and are freely clickable — validation happens only server-side on final submit (field errors surface via `form.errors.*` on Step 1 inputs). The duplicate panel (brand-tinted callout, `patients.register.duplicatesTitle` / `duplicatesChecking` / `duplicatesHint` / `openDuplicate`) appears inside Step 1 only, whenever candidates exist or a check is in flight — do not remove it; it is the safety net against double registration. The form ships fixed slots: contacts[0]=phone, contacts[1]=email, contacts[2]=address (line1/line2/city/postal/country), one identifier pair, one coverage (defaults `coverage_type: 'self_pay'`, `priority: 1`) — the UI binds by index, so reordering the contacts array breaks bindings. Back/Next buttons flank the footer; the submit button is disabled while `form.processing`.

### Patients/Show — Patient 360: demographics, contacts, coverages, consent management, and access log

- **Route:** GET /patients/{patient} (`patients.show`)
- **Guards:** `web` group (session, CSRF, `HandleInertiaRequests`, `IdentifyTenantFromUser`, `EnsureTwoFactorEnabled`) → `auth` middleware → `Gate::authorize('patient.view')`; every render also records a read-audit event (`$record->auditRead(['surface' => 'patient_360'])`). Consent write actions additionally require `patient.edit` server-side; the UI hides them via `actions.can_edit`.
- **Props:**
  - `patient: {`
    - `id: string; mrn: string; first_name: string; last_name: string;`
    - `date_of_birth: string;` (`YYYY-MM-DD`) `age: number; sex: string;`
    - `gender: string | null; preferred_language: string | null; status: string;`
    - `contacts: Array<Record<string, string | boolean | null>>` — actual keys: `id, type, value, line1, line2, city, postal, country, is_primary`
    - `identifiers: Array<Record<string, string>>` — actual keys: `id, system, value`
    - `coverages: Array<Record<string, string | number | null>>` — actual keys: `id, payer_name, member_id, plan (nullable), coverage_type, priority` (ordered by priority)
    - `consents: Array<{ id: string; template_key: string; template_title: string; template_version: number; scope_keys: string[]; status: string; granted_at: string | null; withdrawn_at: string | null; expires_at: string | null; withdraw_url: string }>`
  - `}`
  - `accessLog: Array<{ actor_type: string; actor_id: string | null; occurred_at: string; resource_type: string }>`
  - `actions: { can_edit: boolean; grant_consent_url: string }` — `can_edit` mirrors `Gate::allows('patient.edit')`
- **Actions:**
  - Grant consent (Consents tab, visible only when `actions.can_edit`): fill template key (default `'portal'`) + signature, click grant → POST /patients/{patient}/consents (`patients.consents.grant`) → server enforces `patient.edit`; validates `template_key` and `signature` both required (max 255); `ConsentService::grant` locks the latest active `ConsentTemplate` version (404 if none), stores a signed payload with the capturing user, then redirects back to `patients.show`.
  - Withdraw consent (per consent card, only when `actions.can_edit` and `consent.status === 'granted'`): enter reason, click withdraw → POST `consent.withdraw_url` (`patients.consents.withdraw`) → server enforces `patient.edit`; validates `reason` required (max 500); `ConsentService::withdraw` sets status `withdrawn` + `withdrawn_at` and writes a `consent.withdrawn` audit event with the reason; redirects back to `patients.show`.
- **Notes:** Layout `AppLayout`; i18n prefixes `patients.show.*` and `patients.fields.*`. Browser title is the raw patient name (not translated). Header card shows MRN eyebrow, name, "DOB · age · status" line, and a bordered flag chip (`patients.show.headerFlag`). Body is a single `Card` containing the shared `Tabs` component with exactly five tabs keyed `demographics | contacts | coverages | consents | access` (default `demographics`); demographics render through the shared `DataList` component (gender/language values may be null — DataList must tolerate that). Coverages and consents tabs each have a text empty state (`patients.show.empty`); the access tab lists audit rows keyed on actor+timestamp. The withdraw-reason `Input` is one shared ref across all consent cards — typing fills every card's field, so keep it a single control per design. The access log is a privacy/compliance surface (who viewed this record); it must remain visible to any `patient.view` user and is read-only.

## Scheduling & public booking

### Scheduling/DayBoard — reception day-board: branch agenda by resource, lifecycle actions, quick-book
- **Route:** GET /scheduling/day-board (`scheduling.day-board`)
- **Guards:** `web` group (session/CSRF + `HandleInertiaRequests` + `IdentifyTenantFromUser` + `EnsureTwoFactorEnabled`) → `auth` → `Gate::authorize('appointment.manage', ['branch_id' => ?branch_id])` (branch-scoped RBAC)
- **Props:**
  - `filters: { date: string; branch_id: string }`
  - `branches: Array<{ id: string; name: string }>`
  - `resources: Array<{ id: string; name: string; type: string }>` (active resources of the selected branch)
  - `appointments: Array<{ id: string; patient_id: string | null; patient: string | null; service: string | null; starts_at: string; ends_at: string; status: string; resource_ids: string[] }>` (selected branch + date)
  - `services: Array<{ id: string; name: string; duration: number }>` (active)
  - `patients: Array<{ id: string; name: string; mrn: string }>` (first 20, for quick-book)
  - `slotPreview: Array<{ starts_at: string; ends_at: string; resource_ids: string[] }>`
  - `actions: { transitionUrl: string; quickBookUrl: string; slotsUrl: string; openEncounterUrl: string }`
- **Actions:**
  - Change date/branch filters → GET /scheduling/day-board (reload with query) → server re-authorizes per branch and re-derives the agenda
  - Lifecycle button on an appointment (arrive/start/complete/cancel/no-show) → POST /scheduling/day-board/transition (`scheduling.day-board.transition`) → `AppointmentService::transition` whitelists the action, re-authorizes `appointment.manage` per branch, and enforces the LEGAL_TRANSITIONS state machine under a row lock (illegal transitions throw); cancel/reschedule free resource rows
  - Document → POST /scheduling/day-board/open-encounter (`scheduling.day-board.open-encounter`) → opens the encounter + draft note through the Clinical services (RBAC `encounter.manage`) and redirects to the note editor
  - Quick-book: pick service/patient, fetch slots → POST /scheduling/day-board/slots (`scheduling.day-board.slots`) → server-side `AvailableSlotFinder`; book → POST /scheduling/day-board/quick-book (`scheduling.day-board.quick-book`) → `BookingService::book` (the locked no-double-book path; availability, buffers, same-tenant refs all server-enforced)
- **Notes:** i18n prefixes `scheduling.dayBoard.*`, `scheduling.fields.*`, `scheduling.actions.*`, `scheduling.slots.*`; layout `AppLayout`; renders `ScheduleGrid` (per-resource lanes) + `SlotPicker`. The grid deliberately shows ALL transition buttons regardless of status — the server decides legality; a redesign must keep the buttons dispatching and surface server validation errors. Empty slot state uses `scheduling.slots.empty`.

### Public/Book — unauthenticated tenant-slug online booking (patient-facing, rate-limited)
- **Route:** GET /book/{tenant:slug} (`public.booking.index`)
- **Guards:** NO auth. `throttle:20,1` on the whole prefix; tenant resolved from the slug route binding; only `active` + `bookable_online` services are ever exposed
- **Props:**
  - `tenant: { slug: string; name: string }`
  - `services: Array<{ id: string; name: string; duration: number }>` (active + bookable_online only)
  - `branches: Array<{ id: string; name: string }>`
  - `slotsUrl: string`, `storeUrl: string`
- **Actions:**
  - Pick service/branch/date → POST /book/{tenant:slug}/slots (`public.booking.slots`) → server-side `AvailableSlotFinder` (rate-limited)
  - Submit booking (slot + identity: `first_name`, `last_name`, `date_of_birth`, `sex`, `email`) → POST /book/{tenant:slug} (`public.booking.store`) → server validates, re-checks the service is active + bookable_online, runs demographic duplicate detection before creating/reusing the patient, and books through `BookingService::bookOnline` (locked no-double-book path, `source=online`, `booked_by=null`) inside one transaction
- **Notes:** i18n prefix `scheduling.public.*`; layout `GuestLayout`; carries the static NON-EMERGENCY notice (`scheduling.public.emergencyNotice`) which must remain prominent (D-031 / electric-fence posture: no symptom/triage free-text field exists and none may be added); collects only service/branch/date/slot + minimal identity/contact fields.


## Clinical

### Clinical/Chart — patient chart: timeline, notes with version history, clinical lists, documents, care, AI summary draft
- **Route:** GET /clinical/chart/{patient} (`clinical.chart`)
- **Guards:** `web` group → `auth` → `Gate::authorize('patient.view')`; viewing writes a patient-scoped `read` audit row (chart access is disclosure)
- **Props:**
  - `patient: { id: string; mrn: string; name: string; date_of_birth: string; sex: string; status: string }`
  - `encounters: Array<{ id: string; type: string; status: string; started_at: string; ended_at: string | null }>`
  - `notes: Array<{ id: string; status: string; version: number; author_name: string; created_at: string | null; signed_at: string | null; edit_url: string; versions: Array<{ id: string; version: number; status: string; author_name: string; created_at: string | null; signed_at: string | null; amendment_reason: string | null; edit_url: string }> }>` (full original→amendment chains)
  - `problems: Array<{ id: string; description: string; code: string | null; status: string; recorded_at: string; resolved_at: string | null }>`
  - `allergies: Array<{ id: string; substance: string; reaction: string | null; severity: string; status: string; verified_at: string | null }>` (rendered PROMINENTLY via `AllergyBanner`)
  - `vitals: Array<{ id: string; recorded_at: string; systolic: number | null; diastolic: number | null; heart_rate: number | null; temperature_c: string | null; spo2: number | null; weight_g: number | null; height_mm: number | null; extra: Record<string, unknown> | null }>` (RAW values only — no flags/scores/interpretation exist and none may be invented in the UI)
  - `medications: Array<{ id: string; name: string; dose_text: string | null; route: string | null; frequency_text: string | null; status: string; started_on: string; ended_on: string | null }>`
  - `documents: Array<{ id: string; category: string; title: string; original_filename: string; uploaded_at: string; shared_with_patient: boolean; download_url: string }>`
  - `carePlans: Array<{ id: string; title: string; status: string; started_on: string; ended_on: string | null; goals: Array<{ id: string; description: string; target_date: string | null; status: string }> }>`
  - `referrals: Array<{ id: string; direction: string; status: string; specialty: string | null; reason: string; to_provider_name: string | null; from_provider_name: string | null; to_branch_id: string | null; sent_at: string | null; responded_at: string | null; notes: string | null }>`
  - `recalls: Array<{ id: string; rule_id: string; rule_name: string; due_on: string; status: string }>`
  - `aiSummary: { status: string; label: string; human_handoff: boolean; action_id: string | null; insert_url: string; lines: Array<{ text: string; source: { type: string; id: string; section?: string; label: string; url: string | null } }> } | null` (extractive, source-linked D.8 draft)
  - `actions: { can_view: boolean; can_write_notes: boolean; can_sign_notes: boolean; summary_draft_url: string }`
- **Actions:**
  - Request AI summary → POST /clinical/chart/{patient}/summary-draft (`clinical.summary.draft`) → governed D.8 Summary agent (suggest-only ceiling; every line source-validated server-side; interpretive requests refused)
  - Insert AI summary into a draft note → POST /clinical/chart/{patient}/summary-insert (`clinical.summary.insert`) → server re-authorizes `note.write`, re-validates sources + patient match on the agent action, and inserts only into the clinician's own draft note
  - Download document → GET /clinical/documents/{document} (`clinical.documents.download`) → authorized controller streams from the private disk + patient-scoped read audit row (no public URLs)
  - Open a note/version → GET `edit_url` (note editor)
- **Notes:** i18n prefix `clinical.chart.*`; layout `AppLayout`; tabs (timeline/notes/problems/vitals/medications/documents/care) + `AllergyBanner` + `Timeline` + `VersionHistory` components. The AI summary block must keep the visible AI label (`aiSummary.label` — "AI draft - requires human review") and its per-line source references; vitals must stay uninterpreted raw values.

### Clinical/NoteEditor — SOAP note editor: draft autosave, sign-and-lock, amendments, version history
- **Route:** GET /clinical/notes/{note}/edit (`clinical.notes.edit`)
- **Guards:** `web` group → `auth` → `Gate::authorize('note.write')`; signed notes are returned READ-ONLY (`note.is_read_only`)
- **Props:**
  - `note: { id: string; encounter_id: string; patient_id: string; author_name: string; subjective: string | null; objective: string | null; assessment: string | null; plan: string | null; status: string; signed_at: string | null; version: number; amendment_reason: string | null; is_read_only: boolean }`
  - `encounter: { id: string; status: string; type: string; started_at: string }`
  - `patient: { id: string; mrn: string; name: string; chart_url: string }`
  - `template: { id: string; name: string; required_sections: string[] } | null` (SOAP prefill + required sections)
  - `versions: Array<{ id: string; version: number; status: string; author_name: string; created_at: string | null; signed_at: string | null; amendment_reason: string | null; edit_url: string }>`
  - `actions: { save_url: string; sign_url: string; amend_url: string; chart_url: string; can_write: boolean; can_sign: boolean }`
- **Actions:**
  - Edit SOAP sections (autosave + explicit save) → PATCH /clinical/notes/{note} (`clinical.notes.update`) → server requires `note.write` and rejects updates to non-draft notes (signed notes are immutable at model + DB-trigger level)
  - Sign → POST /clinical/notes/{note}/sign (`clinical.notes.sign`) → server requires `note.sign`, enforces required sections, locks permanently, audits `note.signed`
  - Amend → POST /clinical/notes/{note}/amend (`clinical.notes.amend`) → server requires `note.write` + a non-empty reason; creates a NEW superseding version (originals never modified), audits `note.amended`
  - Back to chart → GET `chart_url`
- **Notes:** i18n prefix `clinical.note.*`; layout `AppLayout`; uses `SoapEditor` + `VersionHistory`. The sign confirmation copy (`clinical.note.signConfirm` — "Signing permanently locks this note.") and the visible read-only state for signed notes are load-bearing; amendment history must remain visible.


## Comms & nursing (staff)

### Comms/Inbox — unified staff messaging inbox (patient + internal threads) with reply, triage, and AI-drafted replies
- **Route:** GET /comms/inbox (`comms.inbox`)
- **Guards:** `web` group (session/CSRF + `HandleInertiaRequests` + `IdentifyTenantFromUser` tenant context + `EnsureTwoFactorEnabled` mandatory MFA) → `auth` → `Gate::authorize('comms.manage')` in `InboxController`. Opening a patient thread is a patient-data read: `ThreadService::messagesForStaff` writes a patient-scoped read-audit row (`surface: comms_thread`) and `markRead` updates the staff read marker.
- **Props:**
  - `filters: { type: string | null; status: string; scope: string }` — server whitelists: type ∈ `patient`|`internal`|null, status ∈ `open`|`closed` (default `open`), scope ∈ `all`|`mine`
  - `threads: ThreadSummary[]` where `ThreadSummary = { id: string; subject: string; type: string; status: string; patient: string | null; assigned_to: number | null; last_message_at: string | null; unread: number }` (max 100 rows, newest message first; `unread` is derived server-side per user, never stored)
  - `activeThread: (ThreadSummary & { messages: Array<{ id: string; author_type: string; body: string; ai_assisted: boolean; sent_at: string }>; clinician_attention_at: string | null; clinician_attention_reason: string | null; aiDraft: { action_id: string; body: string; lines: Array<{ text: string; source: Record<string, string> }> } | null }) | null` — null when no `thread_id` query param
  - `staff: Array<{ id: number; name: string }>` — used to resolve the assignee name
  - `actions: { replyUrl: string; statusUrl: string; assignUrl: string; aiDraftUrl: string; sendDraftUrl: string }` — server-generated route URLs; the page never hardcodes POST paths
- **Actions:**
  - Change a filter select (type/status/scope) or click a thread row → GET /comms/inbox (`comms.inbox`) with `{ type, status, scope, thread_id }` → server re-validates filter values, `firstOrFail`s the thread, read-audits patient threads, marks the thread read
  - Type reply + submit → POST /comms/inbox/reply (`comms.inbox.reply`) `{ thread_id, body }` → validates `body` required/max:10000; `ThreadService::postStaffMessage` enforces `comms.manage`, same-tenant, thread must be OPEN (append-only, audited as `message.posted`), then marks read
  - Close / Reopen button → POST /comms/inbox/status (`comms.inbox.status`) `{ thread_id, action: 'close'|'reopen' }` → `ThreadService::close`/`reopen` enforce `comms.manage` + same-tenant, audited
  - "Assign to me" → POST /comms/inbox/assign (`comms.inbox.assign`) `{ thread_id, assigned_to: null, assign_self: true }` → `ThreadService::assign` enforces `comms.manage` + cross-tenant assignee ban, audited (`thread.assigned`)
  - "Request AI draft" → POST /comms/inbox/ai-draft (`comms.inbox.ai-draft`) `{ thread_id }` → `InboxAgent::draftReply`: ELECTRIC FENCE — if the latest patient message is a clinical question, NO draft is produced; the thread is flagged (`clinician_attention_at`/`reason`) and the refusal is audited + ledgered. Otherwise runs governed tool `comms.draft_reply` (permission `comms.manage`, autonomy ceiling `suggest`) which queues a pending AgentAction — it never posts
  - "Send" on the AI-draft box → POST /comms/inbox/send-draft (`comms.inbox.send-draft`) `{ action_id }` → `ApprovalQueue::approve` re-authorizes the reviewer against the tool permission, requires the action to still be pending, re-grounds the draft against current state, then the HUMAN posts via `ThreadService::postStaffMessage` with `ai_assisted: true`
- **Notes:**
  - i18n prefix `comms.inbox.*`; message author labels are dynamic keys `comms.inbox.author.<author_type>` — new author types need new keys. Layout: `AppLayout` with shared `Card`/`Button`.
  - **AI-draft box (must not be removed or de-emphasized):** amber container (`border-amber-300 bg-amber-50`), AI-assisted title label (`comms.inbox.aiDraft.title`), a source-references line rendering each `aiDraft.lines[].source` as `[type:key]` chips, and an explicit "Send" button. Sending is always this deliberate human click — never automatic. When no draft is pending, a "Request AI draft" button shows instead.
  - **AI-assisted message badge:** any message with `ai_assisted: true` carries an amber `comms.inbox.aiAssisted` pill in its meta row — required provenance labeling, must stay.
  - **Clinician-attention banner (must not be removed):** red strip (`bg-red-50 text-red-700`) rendered when `clinician_attention_at` is set, showing `clinician_attention_reason` — this is the agent's clinical-question handoff signal to staff.
  - Layout: 3-column responsive grid (`md:grid-cols-3`) — thread list (1 col, unread as blue pill) + thread pane (`md:col-span-2`). Empty states: `comms.inbox.empty` (no threads) and `comms.inbox.noSelection` (no thread open). Reply is a labeled textarea (`#inbox-reply`) with placeholder `comms.inbox.replyPlaceholder`.

### Nursing/Dispatch — daily nurse dispatch board: assign/unassign home-visit windows to nurses per branch and date
- **Route:** GET /nursing/dispatch (`nursing.dispatch`)
- **Guards:** `web` group (session/CSRF + `HandleInertiaRequests` + `IdentifyTenantFromUser` tenant context + `EnsureTwoFactorEnabled` mandatory MFA) → `auth` → branch-scoped `Gate::authorize('dispatch.manage', ['branch_id' => $branch->id])` in `DispatchBoardController`. Every planned visit shown is read-audit-logged (`surface: nursing.dispatch`, with date + branch).
- **Props:**
  - `filters: { date: string; branch_id: string }` — date defaults to today; branch defaults to first branch by name (`firstOrFail`)
  - `branches: Array<{ id: string; name: string }>`
  - `unassignedVisits: Visit[]` where `Visit = { id: string; patient: string; window_start_at: string; window_end_at: string; duration_minutes: number; required_qualification: string | null; status: string; assigned_resource_id: string | null }` (server also sends `patient_id` and `scheduled_date`, unused by the page)
  - `nurseLanes: NurseLane[]` where `NurseLane = { resource: { id: string; name: string; qualification: string | null; max_hours_per_week: string | null }; visits: Visit[] }` — one lane per active practitioner resource of the branch; qualification/hours come from the nurse's `NurseConstraint` (nullable if none exists)
  - `actions: { assignUrl: string; unassignUrl: string }` — server-generated route URLs
- **Actions:**
  - Change date/branch + "Refresh" → GET /nursing/dispatch (`nursing.dispatch`) with `{ date, branch_id }` (preserveState) → server re-runs the branch-scoped gate and read audits
  - Pick a nurse in an unassigned card's select + "Assign" (button disabled until a nurse is chosen) → POST /nursing/dispatch/assign (`nursing.dispatch.assign`) `{ planned_visit_id, resource_id }` → `VisitAssignmentService::assign`: branch-scoped `dispatch.manage` gate, row locks visit + resource, resource must be an active practitioner, visit must be planned/assigned, then `AssignmentValidator` checks qualification match, time-window overlap, travel feasibility (locations + speed setting + max travel minutes), weekly hour cap, and that a nurse constraint exists. Failures come back as `errors.assignment` (comma-joined reason codes) and render in the page's danger banner
  - "Unassign" on a lane card → POST /nursing/dispatch/unassign (`nursing.dispatch.unassign`) `{ planned_visit_id }` → `VisitAssignmentService::unassign` (same branch-scoped gate) resets the visit to planned, clears assignee, emits `planned_visit.unassigned`
- **Notes:**
  - i18n prefix `nursing.dispatch.*`. Layout: `AppLayout` with shared `Card`/`Button`/`Input`; design tokens `text-ink`, `text-ink-muted`, `border-line`, `bg-surface`, `brand-500`, `danger`.
  - Two-pane layout: header row (title + date/branch/refresh filter form), then `xl:grid-cols-[minmax(280px,360px)_1fr]` — left column is the unassigned queue, right side a `lg:grid-cols-2` grid of per-nurse lane cards (card title = nurse name, subtitle = qualification or `nursing.dispatch.noConstraint`).
  - **Assignment-error banner must stay:** a danger-styled strip driven by `page.props.errors.assignment` — the ONLY surface for server-side assignment rejections (qualification mismatch, overlap, travel, hour cap, missing constraint).
  - Empty states: `nursing.dispatch.empty` (no unassigned visits), `nursing.dispatch.emptyLane` (nurse has no visits); `nursing.dispatch.none` renders when a visit has no required qualification. The Assign button's disabled state (no nurse selected) is a deliberate affordance.

## Patient portal

All authenticated portal pages share the middleware chain `portal-tenant` + `portal-auth` + `portal-consent` (routes/web.php, `/portal` prefix, route-name prefix `portal.`):

- `portal-tenant` (`IdentifyTenantFromPortalSession`): resolves the tenant from session key `portal_tenant_id`; 403 if the tenant is missing or suspended.
- `portal-auth` (`EnsurePatientPortalAuthenticated`): requires the `patient` auth guard; browsers are redirected to `portal.login`, JSON requests get 401.
- `portal-consent` (`EnsurePortalConsent`): 401 without a `PortalAccount`; 403 if the account's tenant does not match the resolved tenant, the patient row is missing, or the patient lacks a granted `portal.access` consent. Fail-closed — withdrawing `portal.access` locks the whole portal on the very next request.

Every controller additionally re-asserts `$request->user('patient') instanceof PortalAccount` (abort 401) and scopes every query to `$account->patient_id` — identity never comes from client input. All pages except Login render inside `PortalLayout` (white header bar, brand text `portal.title`, 7 nav links Home/Appointments/Documents/Messages/Invoices/Consents/Telehealth, and a Sign out button that POSTs `/portal/logout`); content sits in a `max-w-5xl` centered main column on a `bg-gray-50` page. All copy comes from vue-i18n under the `portal.*` key prefix — no hardcoded strings.

### Portal/Login — patient sign-in to the portal
- **Route:** GET /portal/login (`portal.login`)
- **Guards:** none — public page (no portal middleware). The credential/consent checks happen inside `PortalAccessService` on the login POST.
- **Props:**
  - `actions: { loginUrl: string }` — URL of `portal.login.attempt`
- **Actions:**
  - Submit email + password → POST /portal/login (`portal.login.attempt`), raw `fetch` with JSON body and `X-XSRF-TOKEN` header → `PortalAccessService::login`: validates `email` (required, email) + `password` (required); cross-tenant lowercase email lookup, `Hash::check`, account must be `STATUS_ACTIVE`, patient must hold `portal.access` consent; on success logs into the `patient` guard, stores `portal_tenant_id` in the session, writes a `portal.login` audit row, returns JSON `{portal_account_id, patient_id}`. Any failure throws a 422 with the same generic "Invalid portal credentials." message.
  - On fetch success the client does `router.visit('/portal')`; on any non-OK response it shows one generic failure line (`portal.login.failed`) — there are deliberately no per-field errors.
- **Notes:** Standalone page — does NOT use `PortalLayout`; a single `Card` (max-w-sm) vertically/horizontally centered on `bg-gray-50`. i18n prefix `portal.login.*` (title, email, password, failed, submit). A sibling public endpoint POST /portal/accept-invite (`portal.accept-invite`, token + OTP + new password ≥ 8 chars) exists on the same controller but is not dispatched from this page.

### Portal/Home — patient dashboard with next appointment, unread messages, and outstanding balance
- **Route:** GET /portal (`portal.home`)
- **Guards:** `portal-tenant` + `portal-auth` + `portal-consent`; controller re-asserts the `patient`-guard `PortalAccount` (401)
- **Props:**
  - `nextAppointment: { id: string; service: string | null; starts_at: string; status: string } | null` — earliest future booked/confirmed appointment
  - `unreadMessages: number` — summed patient unread count across all of the patient's threads
  - `outstandingBalanceMinor: number` — sum of `open_balance_minor` over the patient's issued invoices
- **Actions:**
  - None on the page itself — purely presentational; every number is derived server-side (`PortalHomeController`, an app-layer composition across Scheduling/Comms/Billing for the authenticated patient only).
  - Sign out (layout header) → POST /portal/logout (`portal.logout`) → logs out the `patient` guard, forgets `portal_tenant_id`, invalidates the session, redirects to `portal.login`.
- **Notes:** `PortalLayout`; 3-card grid (`md:grid-cols-3`). i18n prefix `portal.home.*` plus `portal.nav.home` for the title. Empty state for the appointment card is `portal.home.none`; service name may be null and renders as "—". Balance renders as `(minor / 100).toFixed(2)` with no currency symbol — keep the minor-units division if re-skinning.

### Portal/Appointments — view upcoming/past appointments, self-book, and cancel
- **Route:** GET /portal/appointments (`portal.appointments`)
- **Guards:** `portal-tenant` + `portal-auth` + `portal-consent`; controller re-asserts the `patient`-guard `PortalAccount` (401)
- **Props:**
  - `upcoming: Array<{ id: string; service: string | null; starts_at: string; ends_at: string; status: string }>` — future booked/confirmed, sorted ascending
  - `past: Array<{ id: string; service: string | null; starts_at: string; ends_at: string; status: string }>` — everything else (last 100 appointments total)
  - `services: Array<{ id: string; name: string; duration: number }>` — only active + `bookable_online` services
  - `branches: Array<{ id: string; name: string }>`
  - `cancelMinHours: number` — tenant setting `scheduling.portal.cancel_min_hours` (default 24)
  - `actions: { slotsUrl: string; storeUrl: string; cancelUrl: string }`
- **Actions:**
  - Find slots (select service + branch + date, click) → POST /portal/appointments/slots (`portal.appointments.slots`), raw `fetch` with XSRF header → validates `service_id`/`branch_id`/`date`; service must be active + bookable_online; `AvailableSlotFinder` returns up to 12 slots as `{ starts_at, ends_at, resource_ids[] }`.
  - Book a slot → POST /portal/appointments (`portal.appointments.store`), Inertia post → validates service/branch/starts_at/resource_ids; service must be active + bookable_online; `BookingService::bookOnline` — the locked no-double-book path — with the patient always taken from the session account; redirects back to `portal.appointments`.
  - Cancel an upcoming appointment → POST /portal/appointments/cancel (`portal.appointments.cancel`), Inertia post with `appointment_id` + `reason` → own-appointment lookup only; cancel-window policy enforced server-side (appointment must start ≥ `cancelMinHours` from now, else `ValidationException`); `AppointmentService::cancelForPatient`.
- **Notes:** `PortalLayout`; two-card grid (`md:grid-cols-2`): left = upcoming (with per-row Cancel button) + past lists, right = booking form + slot results. i18n prefix `portal.appointments.*`; the cancel hint interpolates `{hours}` (`portal.appointments.cancelHint`) and the cancel reason sent to the server is the translated `portal.appointments.cancelReason` string. Empty states: `portal.appointments.empty` (both lists) and `portal.appointments.slotsEmpty`. Null service renders "—".

### Portal/Documents — list and download documents shared with the patient
- **Route:** GET /portal/documents (`portal.documents.index`)
- **Guards:** `portal-tenant` + `portal-auth` + `portal-consent`; controller re-asserts the `patient`-guard `PortalAccount` (401)
- **Props:**
  - `documents: Array<{ id: string; category: string; title: string; original_filename: string; mime_type: string; uploaded_at: string; shared_at: string | null; download_url: string }>` — the server payload also includes `size_bytes: number` (unused by the current template)
- **Actions:**
  - Download → GET /portal/documents/{document} (`portal.documents.show`), plain `<a :href>` → `DocumentService::portalDocument` resolves only documents explicitly shared with this patient (D.4 posture); writes a read-audit row (`surface: portal_document_download`) and streams the file as an attachment with `X-Content-Type-Options: nosniff`.
- **Notes:** `PortalLayout`, single `Card` list. Only explicitly shared documents ever appear — never the full chart. The index endpoint is content-negotiated: JSON consumers get `{documents: [...]}` with the identical shape (Phase B/D contract) — do not change field names. i18n prefix `portal.documents.*`; empty state `portal.documents.empty`.

### Portal/Messages — secure messaging threads between patient and practice
- **Route:** GET /portal/messages (`portal.messages`) — optional query param `thread_id` selects the active thread
- **Guards:** `portal-tenant` + `portal-auth` + `portal-consent`; controller re-asserts the `patient`-guard `PortalAccount` (401); all thread access goes through `ThreadService`'s fail-closed patient path (own-thread + participant + consent), and reads are patient-scoped read-logged there
- **Props:**
  - `threads: Array<{ id: string; subject: string; status: string; last_message_at: string | null; unread: number }>`
  - `activeThread: { id: string; subject: string; status: string; messages: Array<{ id: string; author_type: string; body: string; sent_at: string }> } | null`
  - `actions: { storeUrl: string }`
- **Actions:**
  - Open a thread (click row) → GET /portal/messages?thread_id=… (`portal.messages`, Inertia visit, `preserveState: false`, `replace: true`) → server loads messages via `ThreadService::messagesForPatient` (fail-closed) and calls `markPatientRead` — opening a thread clears its unread badge.
  - Send reply (textarea + submit; client blocks empty body) → POST /portal/messages (`portal.messages.store`) with `{thread_id, body}` → validates `body` (required, max 10000); `ThreadService::postPatientMessage` (fail-closed patient path) appends and marks read; redirects back to the thread.
- **Notes:** `PortalLayout`; 3-column grid — thread list (1 col) + conversation pane (2 cols). The reply form renders ONLY when `activeThread.status === 'open'`; closed threads are read-only. Author labels resolve via dynamic i18n keys `portal.messages.author.<author_type>` — every possible `author_type` needs a translation. Message bodies render with `whitespace-pre-line`. Unread badge shows `portal.messages.unread` with `{count}`. Empty states: `portal.messages.empty` (no threads) and `portal.messages.noSelection` (no thread open). i18n prefix `portal.messages.*`.

### Portal/Invoices — list of the patient's issued invoices with PDF download
- **Route:** GET /portal/invoices (`portal.invoices`)
- **Guards:** `portal-tenant` + `portal-auth` + `portal-consent`; controller re-asserts the `patient`-guard `PortalAccount` (401)
- **Props:**
  - `invoices: Array<{ id: string; number: string; issue_date: string | null; due_date: string | null; currency: string; total_minor: number; open_balance_minor: number; status: string; download_url: string }>` — `number` is the composed `series-number`; `open_balance_minor`/`status` come from the mutable `invoice_balances` projection (falling back to 0 / invoice status), never the frozen legal row
- **Actions:**
  - Download PDF → GET /portal/invoices/{invoice}/pdf (`portal.invoices.download`), plain `<a :href>` → own issued invoices only (`patient_id` match + `number` and `pdf_path` not null); writes a patient-scoped read-audit row (`surface: portal_invoice_download`); streams the PDF from the PRIVATE local disk as an attachment with nosniff — no public URLs.
- **Notes:** `PortalLayout`, single `Card` with a 7-column table wrapped in `overflow-x-auto` — keep the horizontal-scroll wrapper. Only issued invoices (number assigned) ever appear; drafts are invisible. Amounts render as `(minor / 100).toFixed(2)` followed by the currency code. There is NO pay button anywhere — payment processing (Stripe/PSP) is deliberately deferred; do not add one. i18n prefix `portal.invoices.*`; empty state `portal.invoices.empty`.

### Portal/Consents — view consent captures and withdraw granted ones
- **Route:** GET /portal/consents (`portal.consents`)
- **Guards:** `portal-tenant` + `portal-auth` + `portal-consent`; controller re-asserts the `patient`-guard `PortalAccount` (401)
- **Props:**
  - `consents: Array<{ id: string; template_key: string; title: string; scope_keys: string[]; status: string; granted_at: string | null; withdrawn_at: string | null }>` — ordered by `granted_at` desc
  - `actions: { withdrawUrl: string }`
- **Actions:**
  - Withdraw a consent (type reason + submit; client blocks empty/whitespace reason) → POST /portal/consents/withdraw (`portal.consents.withdraw`) with `{consent_id, reason}` → validates `reason` (required, max 500); own consent rows with status `granted` only (fail-closed 404 otherwise); `ConsentService::withdraw` — audited, immutable snapshots; redirects back.
- **Notes:** `PortalLayout`, single `Card` list. The withdraw form (reason input + button) renders only for rows with `status === 'granted'` — the required free-text reason is a compliance artifact, keep it mandatory. Status labels resolve via dynamic i18n keys `portal.consents.status.<status>`. CRITICAL UX consequence a designer must surface: withdrawing the `portal.access` consent locks the patient out of the entire portal on the very next request (enforced by `portal-consent` middleware). i18n prefix `portal.consents.*`; empty state `portal.consents.empty`.

### Portal/Telehealth — list joinable telehealth sessions and fetch a transient join token
- **Route:** GET /portal/telehealth (`portal.telehealth`)
- **Guards:** `portal-tenant` + `portal-auth` + `portal-consent`; controller re-asserts the `patient`-guard `PortalAccount` (401); the token endpoint additionally runs `TelehealthService::joinTokenForPatient`'s three-way fail-closed gate (active portal account + `portal.access` consent + requester is the session's patient) with audit + read log
- **Props:**
  - `sessions: Array<{ id: string; provider: string; status: string; created_at: string | null; token_url: string }>` — only sessions with status `created` or `active`, newest first
- **Actions:**
  - Join → POST /portal/telehealth/{session}/token (`portal.telehealth.token`), raw `fetch` with XSRF header → server issues the join token ON DEMAND through the three-way fail-closed patient path and returns JSON `{token, room, role, expires_at}`; the token exists only in that response — never stored or logged server-side.
- **Notes:** `PortalLayout`, single `Card` list. The received token lives only in in-page memory (`joined` reactive map) for the moment of joining — the page then shows a green "joined" confirmation (`portal.telehealth.joined`); it does NOT embed a video client. Never persist, echo, or log the token in any redesign. A privacy notice line (`portal.telehealth.notice`) sits under the heading — keep it. i18n prefix `portal.telehealth.*`; empty state `portal.telehealth.empty`; null `created_at` renders "—".

## Nurse PWA (separate offline-first app)

The Nurse PWA (`nurse-pwa/src/App.vue`, a single-file Vue app with internal `v-if` views — no router) is built airplane-mode-first. All local persistence goes through Dexie (IndexedDB database `careos-nurse-pwa`, one table `encryptedRecords`) which stores only `{ id, iv, ciphertext, updatedAt }` — never plaintext PHI. Records are encrypted with AES-GCM-256 using a non-extractable key derived via HKDF-SHA-256 from the nurse's bearer session token (salt = SHA-256 of `careos-nurse-session:<token>`, info = `careos-nurse-day-pack`); the token and CryptoKey live only in module memory, so nothing on disk can be decrypted without an active session. The local store is **wiped** (all records cleared + in-memory key dropped) on: explicit logout, any 401/403 from the server (token revoked), and an idle timeout (`VITE_NURSE_IDLE_TIMEOUT_MS`, default 15 min, re-armed by click/keydown/touchstart/pointerdown). Writes never hit the network directly: every capture becomes an encrypted **outbox** entry `{ client_uuid, type, payload, device_timestamp, sequence }`; sync replays the outbox as `POST /api/nurse/sync { actions: [...] }` — grouped by visit, ordered by per-device sequence, idempotent via `client_uuid` (plus a deterministic `client_visit_uuid = offline-<planned_visit_id>` so offline visit creation is replay-safe), retried up to 3 times with exponential backoff (1s base, 60s cap), and entries are deleted only after the server acknowledges their `client_uuid` in `results`.

### 1. Login

- **State/view name:** Login (`!authenticated` branch).
- **Entered when:** App opens with no in-memory session key (fresh load, after logout, after idle wipe, or after a 401/403 revocation wipe). If a session key exists on mount, the app skips login and loads the cached day pack instead.
- **Data shown:** Email and password fields only — no PHI.
- **Actions:** Submit → `POST /api/nurse/login` → response `{ token, token_type, expires_at, user: { id, name, tenant_id } }`; token becomes the bearer token and seeds HKDF key derivation (`setSessionToken`), the idle-wipe timer starts, then an immediate sync runs (outbox replay + day-pack fetch).
- **Offline behavior:** Login itself requires network (direct fetch, not queued). Nothing local can be decrypted until it succeeds.

### 2. Workspace top bar + sync status

- **State/view name:** Workspace header (topbar + status lines; always visible once authenticated).
- **Entered when:** `authenticated === true`.
- **Data shown:** App title, day-pack `date` (falls back to today), status line (`visits.offline` after a successful sync), pending outbox count, `lastSyncedAt` timestamp, and `sync.error` when a sync attempt failed.
- **Actions:**
  - **Sync** button → `syncOutboxWithRetry()` (replays queued actions to `POST /api/nurse/sync`) then `GET /api/nurse/day-pack?date=<today>` and re-encrypts the fresh pack locally.
  - **Logout** button → `POST /api/nurse/logout`, then full local wipe.
- **Offline behavior:** This is the offline/sync indicator surface: `pendingCount` shows how many encrypted actions are still queued; sync failure just sets an error key — queued work is preserved for the next attempt. A 401/403 during sync wipes the store and drops back to Login.

### 3. Day list (today's visits)

- **State/view name:** Visit list (`nav.visit-list`, left column; stacks on narrow screens).
- **Entered when:** Authenticated, after day-pack load/sync. First visit is auto-selected.
- **Data shown:** From decrypted `DayPack { date, nurse: { id, name }, visits: VisitSummary[] }` — per visit: `patient.name`, `window_start_at`–`window_end_at`; empty-state message when no visits.
- **Actions:** Tap a visit → sets `selectedVisitId` (pure local navigation, nothing queued).
- **Offline behavior:** Fully readable offline from the encrypted day pack (as long as the in-memory key survives); wiped on logout/idle/revocation.

### 4. Visit detail — patient context (read-only)

- **State/view name:** Visit detail (`article.detail`, shown when a visit is selected).
- **Entered when:** A visit is selected in the day list.
- **Data shown:** From the selected `VisitSummary`: patient name; `address { line1, line2, city, postal, country }`; a red **allergy banner** (`allergies[] { substance, reaction, severity }`, with explicit "no allergies" text); `medications[] { name, dose_text }` (shape also carries `route`, `frequency_text`); `problems[] { description, code }`; `care_plan_goals[] { description, target_date }` (shape also carries `care_plan_id`, `care_plan_title`).
- **Actions:** None — reference data only.
- **Offline behavior:** Entirely from the encrypted day pack; no network needed.

### 5. Task completion

- **State/view name:** Tasks panel (inside visit detail).
- **Entered when:** Visit detail is open; lists `tasks[] { id, title, due_at, source, visit_id }` (shape also has `description`, `priority`, `status`).
- **Actions:**
  - **Mark done** → outbox type `visit_task_done` with `{ planned_visit_id, visit_id, client_visit_uuid, nurse_resource_id, patient_id, visit_task_id | task_id }` (field chosen by `task.source`).
  - **Mark not done** (with free-text reason) → outbox type `visit_task_not_done`, same payload plus `not_done_reason`.
  - Both replay via `POST /api/nurse/sync`.
- **Offline behavior:** Queued encrypted in the outbox; pending count updates immediately; removed only after server ack; wiped with the store.

### 6. Vitals capture

- **State/view name:** Vitals entry panel (inside visit detail).
- **Entered when:** Visit detail is open.
- **Data shown:** Numeric inputs for `systolic`, `diastolic`, `heart_rate`, `temperature_c` (0.1 steps), `spo2`, `weight_g`, `height_mm`.
- **Actions:** **Queue vitals** → outbox type `visit_vitals` with base visit payload + the raw vitals fields → replayed via `POST /api/nurse/sync`.
- **Offline behavior:** Queued, encrypted, sequence-ordered per visit; wiped with the store.

### 7. Visit note

- **State/view name:** Note panel (inside visit detail).
- **Entered when:** Visit detail is open.
- **Data shown:** One free-text `body` textarea.
- **Actions:** Autosaves on textarea `change` and via explicit **Save note** button → outbox type `visit_note` with base visit payload + `body` → `POST /api/nurse/sync`.
- **Offline behavior:** Every save is a queued encrypted outbox entry (autosave included); wiped with the store.

### 8. Photo capture

- **State/view name:** Attachments panel — photo input (inside visit detail).
- **Entered when:** Visit detail is open.
- **Data shown:** File input (`image/png,image/jpeg,image/webp`, `capture="environment"` for the device camera).
- **Actions:** Selecting/taking a photo → read as data URL → outbox type `visit_photo` with base visit payload + `{ data, mime_type, size_bytes }` → `POST /api/nurse/sync`.
- **Offline behavior:** Full image bytes queued as AES-GCM ciphertext in the outbox; input cleared after queueing; wiped with the store.

### 9. Signature capture

- **State/view name:** Attachments panel — signature pad (320×120 canvas with pointer draw handlers, inside visit detail).
- **Entered when:** Visit detail is open.
- **Data shown:** Blank canvas the patient/nurse draws on.
- **Actions:** **Queue signature** → canvas exported as PNG data URL → outbox type `visit_signature` with base visit payload + `{ data, mime_type: 'image/png', size_bytes }` → `POST /api/nurse/sync`.
- **Offline behavior:** Queued and encrypted like photos; wiped with the store.

### 10. Incident report

- **State/view name:** Incident panel (inside visit detail).
- **Entered when:** Visit detail is open.
- **Data shown:** `occurred_at` (datetime-local, defaults to now), `category` select (`fall | medication | behaviour | safety | other`), `severity` select (`low | medium | high`), free-text `description`.
- **Actions:** **Queue incident** → outbox type `incident_report` with base visit payload + `{ occurred_at (ISO), category, severity, description }` → `POST /api/nurse/sync`; description clears after queueing.
- **Offline behavior:** Queued encrypted; wiped with the store.

### 11. Session end states (implicit)

- **State/view name:** Idle wipe / revocation / logout — all funnel back to Login.
- **Entered when:** (a) no user activity for the idle timeout (default 15 min), (b) server returns 401/403 on `GET /api/nurse/day-pack` or `POST /api/nurse/sync`, or (c) the nurse taps Logout.
- **Data shown:** None — day pack and selection are cleared from component state.
- **Actions:** None available; nurse must re-authenticate.
- **Offline behavior:** All encrypted records (day pack **and** any unsynced outbox entries) are deleted and the in-memory key is dropped — after a wipe, unsynced work is gone by design; PHI is never left decryptable at rest.


