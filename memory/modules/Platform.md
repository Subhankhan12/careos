# Module: Platform (`Modules\Platform`)

## Purpose

The tenancy + identity + access foundation: tenants, org hierarchy, users/auth, RBAC, per-tenant
configuration (plans, feature flags, settings), and break-glass grants. Everything tenant-owned
enforces fail-closed tenancy.

## Key tables

- `tenants` — platform-level (NOT tenant-owned). ULID id, name, slug (unique), region
  (`eu`/`us`, immutable after create), status (`provisioning`/`active`/`suspended`), `plan_id`.
- `users` — platform-level; nullable `tenant_id` (NULL = super-admin). Fortify 2FA columns +
  Sanctum tokens. Email globally unique for now.
- `branches`, `departments` — tenant-owned org hierarchy (`BelongsToTenant`); code unique per
  tenant / per tenant+branch; department branch must be same tenant.
- `roles` (tenant-owned), `permissions` (platform catalog), `permission_role`,
  `role_user` (tenant-owned assignment: nullable `branch_id`, `abac_conditions` JSON reserved).
  Catalog includes destructive `patient.merge`, appointment/encounter management, deterministic
  allergy override, Nursing agreement/dispatch management, and governed AI management. Starter
  `org_admin` receives all; coordinator receives Nursing agreement/dispatch management;
  doctor/nurse receive `encounter.manage`, `note.write`, and `note.sign`; doctor/org_admin receive
  `allergy.override`; org_admin receives `note.supervise`; reception does not.
- `plans` (platform; `price_minor` integer minor units, `limits`/`features` JSON),
  `feature_flags` (tenant-owned), `settings` (tenant-owned, typed value JSON). Nursing proof-of-
  visit privacy notice defaults to `nursing.visit.gps_privacy_notice`.
- `break_glass_grants` — tenant-owned, time-boxed emergency access (reason required, `expires_at`).

## Key services / classes

- `Services\TenantContext` — singleton; `set/current/id/has/forget`, `system(Closure)` escape hatch.
- `Concerns\BelongsToTenant` + `Concerns\TenantScope` — fail-closed global scope + `tenant_id` stamp.
- `Services\PermissionService` — `has(user, key, ?branchId)`; branch-scoped resolution.
- `Services\RbacProvisioner` — seeds permission catalog + starter roles per tenant (system mode).
- `Services\FeatureService` (flag resolution: override → plan default → false),
  `Services\SettingsService` (typed get/set + platform defaults).
- Middleware: `IdentifyTenantFromUser`, `EnsureTwoFactorEnabled`, `EnsureSuperAdmin`.
- Patient portal auth is intentionally separate in Patients (`portal_accounts` + `patient`
  guard); staff `users`/Fortify/RBAC remain for staff/admin only.
- Models: `Tenant`, `User`, `Branch`, `Department`, `Role`, `Permission`, `RoleAssignment`,
  `Plan`, `FeatureFlag`, `Setting`, `BreakGlassGrant`.

## Invariants enforced

- No tenant context + not system mode ⇒ tenant-owned queries THROW (`TenantContextMissingException`).
- `tenant_id` auto-stamped on create for tenant-owned models.
- Tenant region immutable after creation; cross-tenant references rejected (`CrossTenantReferenceException`).
- Mandatory TOTP MFA for all users; suspended tenants denied at login and at request time.
- Super-admin (tenant_id null) is the ONLY RBAC bypass (`Gate::before`).
- `patient.merge` is a permissioned destructive action granted to org-admin starter roles.
- `encounter.manage` is granted to org-admin, doctor, and nurse starter roles; reception is denied.
- `note.write` and `note.sign` are granted to org-admin, doctor, and nurse starter roles;
  reception is denied.
- `allergy.override` is granted to org-admin and doctor starter roles; nurse/reception do not
  receive it by default.
- `note.supervise` is granted to org-admin starter roles for tenant-team unsigned-note worklists;
  clinicians without it see only their own drafts.
- `agreement.manage` is granted to org-admin and coordinator starter roles for nursing service
  agreements; reception is denied.
- `dispatch.manage` is granted to org-admin and coordinator starter roles for Nursing dispatch;
  reception is denied.
- RBAC applies to staff `users` only; patient portal accounts do not receive staff permissions.
- Money as integer minor units; plans store `price_minor`.

## Status

**Phase A COMPLETE** (through P0A.C). Tenancy, org hierarchy, auth+MFA, RBAC, config
(plans/flags/settings), break-glass, and the auth flow (login → 2FA → role redirect) + app/admin
shells are in place and green on MariaDB (dev) and MySQL 8 (CI).
P0D.G1 adds `encounter.manage` to the permission catalog and starter doctor/nurse/org-admin roles.
P0D.G2 adds `note.write` and `note.sign` to the catalog and starter clinician roles.
P0D.G3 adds `allergy.override` to the catalog and starter org-admin/doctor roles.
P0D.G6 adds `note.supervise` to the catalog and starter org-admin roles.
P0E.G1 adds `agreement.manage` and the `coordinator` starter role for nursing service agreements.
P0E.G3 adds `dispatch.manage` to org-admin and coordinator starter roles for Nursing dispatch.
P0E.G4 adds the default `nursing.visit.gps_privacy_notice` setting text for staff privacy notice.

## Demo tenant (P0P.G1)

`DemoClinicSeeder` provisions ONE demo tenant, **Praxis Lindenhof** (slug `praxis-lindenhof`,
branch "Zürich Oberstrass", currency EUR, plan `eu_pro`), for design/sales/design-partner use:

    php artisan db:seed --class=DemoClinicSeeder

- **Idempotent by tenant slug**: if `praxis-lindenhof` exists the seeder returns immediately, so a
  second run adds nothing anywhere in the schema (asserted table-by-table).
- **Real provisioning path**: `Tenant::create` fires `RbacProvisioner::provisionTenant()`, which
  seeds starter roles in Phase A system mode — provisioning stays out of the audit chain. Everything
  after that runs as normal tenant-scoped actors, so the demo has a real 300+ row audit chain that
  verifies.
- **Never moves `now()` backwards.** `AuditService::verifyChain` replays ordered by `occurred_at`,
  so a back-dated write mid-run would order rows differently from how they were hash-linked and
  break the chain. Business dates are passed as explicit arguments instead.
- **Time anchors**: billing sits in the PREVIOUS full calendar month (`DemoClinicSeeder::period()`),
  which reconciles with all six invariants at delta 0; scheduling/dispatch/live clinical sit in the
  CURRENT week. Staff role assignments use `branch_id = null` (all-branches) because a branch-scoped
  assignment does not answer gate checks that pass no branch (`PermissionService::has`).
- Demo logins: `<first>.<last>@praxis-lindenhof.test` / `demo-password` (MFA pre-enrolled);
  portal accounts use `demo-portal-password`.

## Automation layer (P0P.G2)

The unattended cadences live in `routes/console.php`. Six commands, all
`withoutOverlapping()` + `onOneServer()`, all iterating `status = 'active'` tenants only:

| Command | Cadence |
| --- | --- |
| `credentials:refresh-status` | daily 02:10 |
| `nursing:materialize-visits` | daily 02:20 (rolling 8-week horizon) |
| `clinical:evaluate-recalls` | daily 02:30 |
| `billing:dunning-run` | daily 06:00 |
| `billing:reconcile` | daily 06:30 (launch-blocker monitor) |
| `appointments:dispatch-reminders` | every 15 min (enqueue only) |

**PRODUCTION RUNNER — nothing fires without it:**

    cron:        * * * * * cd /srv/careos && php artisan schedule:run >> /dev/null 2>&1
    supervisor:  php artisan horizon

`schedule:run` every minute is what drives all of the above; **Horizon must be running** or the
queued side (appointment reminders, notification deliveries) never drains — the scheduler only
ENQUEUES reminders, it does not send them.

**Local Windows cannot keep Horizon alive** — this PHP has no `pcntl`, so `php artisan horizon`
exits right after startup. Known LOCAL-only limitation (CI and Linux prod install `pcntl`/`posix`).
Locally use `php artisan schedule:work` (or a Task Scheduler entry) plus
`php artisan queue:work redis --queue=reminders,notifications` in place of Horizon. Nothing was
installed for this gate.

`SystemActorResolver::forPermission()` resolves the actor an unattended run acts as — see D-067
(never a super-admin, never branch-scoped, skip rather than escalate). `SettingsService::forget()`
removes a tenant override outright; `set($key, null)` is NOT equivalent because a stored null still
coerces on read (`'array'` reads back as `[]`).

P0P.G3 adds `audit:verify-chains` (daily 01:30) — see D-069. It lives in `app/Console/Commands/`,
NOT the Audit module: it needs Platform's `Tenant`/`TenantContext` and **Audit may not depend on
Platform** (same reason `App\Audit\PlatformAuditContext` lives in the app layer). Its
`IntegrityCheck` model lives in **Platform** for the mirror-image reason: the row is tenant-owned so
it needs `BelongsToTenant`, which Audit may not import. `integrity_checks` is append-only at model +
DB-trigger level, and a PASS is recorded as well as a failure — so a check that silently stops
running shows up as an absence rather than as nothing at all.

- Frontend UX gating + error pages (M-4/M-5, FIX.4): `HandleInertiaRequests::authUser()` shares
  `auth.user.permissions` (the nav-relevant keys resolved via `$user->can()`, super-admins all-true via
  `Gate::before`) so `AppLayout` hides links a role can't use — a UX hint only; the route Gate stays
  authoritative (a typed URL still 403s). `bootstrap/app.php` `withExceptions` renders an in-shell Inertia
  `Error` page (`resources/js/pages/Error.vue`, GuestLayout) for 403/404/419/503 and the portal
  consent-withdrawal lockout (403 on a `portal.*` route → "access withdrawn" message); PRESENTATION ONLY, the
  status code is preserved, and the renderer no-ops under `testing` so existing status assertions stay exact.
  See [[D-092]].
- CI route smoke (FIX.5): `tests/Feature/Smoke/RouteSmokeTest.php` drives every major GET route through the
  REAL middleware stack with `TenantContext::forget()` before each request (so `IdentifyTenantFromUser` sets
  context via the middleware, like a browser), asserting 200-not-500 for all roles + portal. This is the
  systemic guard against a request-time 500 (the C-1 class the pre-seeded feature tests masked). Runs as a
  dedicated CI step + inside `composer check`; local: `composer test:smoke`. See [[D-093]].
- Admin screens (CLINIC.W8) — the FIRST Platform Http controllers (`Modules/Platform/src/Http/Controllers/`).
  **SettingsController** (`/settings`, admin.manage): reads/writes tenant settings ONLY through the existing
  `SettingsService` (get/set) — editable = settlement `currency` (+ allow-list) and invoice-issuer identity
  `billing.seller_name`/`billing.seller_vat_id` (the keys that round-trip AND have a runtime consumer); tenant
  profile + branches shown read-only (no write backend); other clinic-settings listed as gaps, not faked.
  **UserRoleController** (`/admin/roles`, admin.manage): assigns one of the 6 seeded `is_system` role templates via
  the sanctioned raw `RoleAssignment::create(['user_id','role_id','branch_id'=>null])` (NO service exists — that IS
  the path; auto-audited by the `RoleAssignment::created`→`role.assigned` hook, so never bypass Eloquent / run in
  system mode). Assign REPLACES the user's role (role_user has no unique constraint → dedupe). A last-org_admin
  self-lockout guard lives in the controller (none in the RBAC layer). Server Gate stays authoritative — a user's
  effective perms are exactly the template's. Nav link is gated on `admin.manage` (added to
  `HandleInertiaRequests::NAV_PERMISSIONS`). See [[D-094]].
- Settings backends (CLINIC.W8b): tenant PROFILE columns (contact_email/phone, address_*) editable via
  `SettingsController::updateProfile`; slug/region/status/plan stay read-only. locale + timezone persist in
  SettingsService and are APPLIED per request by `App\Http\Middleware\ApplyTenantLocaleTimezone`
  (`date_default_timezone_set` + `setLocale`; never touches config app.timezone) — surfaced via LAZY `locale`/
  `timezone` Inertia shares (Inertia evaluates `share()` before that middleware runs, so eager values would miss it).
  BRANCH CRUD lives in the APP layer (`App\Http\Controllers\BranchController` + `App\Services\BranchService`) because
  the deactivation guard spans Platform+Scheduling and `arch('Platform does not depend on Scheduling')` forbids it in
  Platform. New `BranchHours` model + `branch_hours` table (per-weekday, validated in booted like ResourceAvailability)
  + `BranchHoursService` (read by the scheduling engine). Branch deactivation is soft (`active=false`), BLOCKED while
  future appointments exist; audited via app-layer hooks (branch.*, tenant.profile_updated, branch.hours_changed).
  See [[D-095]].

- **BRANCH.P1 (wireframe-parity) — per-branch `accepts_online_bookings` (SOFT-SUSPEND) + `phone`.** Additive migration
  `2026_08_23_000001` (accepts_online_bookings bool default **true**, phone nullable). `Branch::scopeOnlineBookable()`
  = `active AND accepts_online_bookings`. **The SOFT-SUSPEND (distinct from hard deactivate, NOT a weakening):**
  setting `accepts_online_bookings=false` stops NEW online bookings but keeps the branch `active` — existing
  appointments + the internal day-board are untouched, staff `book()` still works. Enforced in the ONLINE write path
  (`BookingService::createBooking` refuses `SOURCE_ONLINE` when not online-bookable → `BookingUnavailableException::onlineBookingsSuspended`;
  see [[Scheduling]]); the public + portal branch lists + slots surfaces honor it (slots → `[]`). Admin toggle
  `POST /admin/branches/{branch}/online-bookings` (`BranchController::onlineBookings` → `BranchService::setOnlineBookings`),
  admin.manage-gated, validated, tenant-scoped, **audited distinctly** (`branch.online_bookings_enabled`/`_suspended`
  derived in the `Branch::updated` hook). `phone` added to the branch update validation + index payload. **The HARD
  deactivate guard is UNCHANGED** (active=false still blocked while future appointments exist). Minimal UI on the
  existing page (status pill + toggle + phone input; full master-detail visual is P2+). Locked by
  `tests/Feature/Scheduling/BranchOnlineBookingTest.php` (6). See `docs/wireframe-parity/BRANCHES-DIFF.md` §5.1 (option b).

- **BRANCH.P2 (wireframe-parity) — `is_primary` default-branch flag + exactly-one-primary invariant.** Additive
  migration `2026_08_24_000001` (is_primary bool default false) + backfill (every existing tenant → exactly one
  primary: earliest active branch, else earliest). **INVARIANT: exactly one primary per tenant, ALWAYS** — seeded by
  the `Branch::booted` `creating` hook (first branch is primary; covers direct `Branch::create` used by demo
  seeders). `BranchService::setPrimary` = atomic swap (never zero/two); `setActive(false)` on the primary reassigns
  to the earliest other active branch (or the sole branch keeps it — never zero); `ensurePrimary()` = idempotent
  backfill mirror. NO un-set (the flag only moves). `POST /admin/branches/{branch}/primary`
  (`BranchController::setPrimary`, admin.manage, target must be ACTIVE), audited `branch.primary_set` (derived in the
  `Branch::updated` hook). **The P1 hard deactivate guard is UNCHANGED** (runs first; reassign only after it passes).
  The implicit "first branch" default (billing `Branch::firstOrFail`) is NOT rewired (follow-up). Minimal "Primary"
  badge + "Set as primary" UI (full visual P4). Locked by `tests/Feature/Platform/BranchPrimaryTest.php` (8).

- **BRANCH.P4 (wireframe-parity) — master-detail restructure + Eucalyptus Glow, PURELY presentational.**
  `Admin/Branches.vue` re-skinned from a single-column stack to the wireframe's two-column master-detail (300px
  branch list | 4 stacked glass detail cards for the selected branch: Profile, Opening hours, Resources, terracotta
  Lifecycle). Honest suspend(soft: P1 accepts_online_bookings)-vs-deactivate(hard: W8b/P1) as DISTINCT controls in
  the Lifecycle card, neither mislabelled/merged. Reused existing Eucalyptus Glow utilities (`.glass-card`,
  `.euca-card-in`/`eucardIn`, `.settings-surface`, `--color-danger #b4552d` terracotta) — no new CSS. Header
  [+ Add branch] toggles a glass create panel over the existing `store` (the 3-step modal wizard is optional
  polish). NO backend/guard change — all P1–P3 + W8b/W8c re-skinned only; correctly-more-real (structured
  address/code/timezone, hours editor, resource CRUD + guards) KEPT. Locked by
  `tests/Feature/Platform/BranchMasterDetailTest.php` (3, props/behaviour not markup). **Branches parity COMPLETE
  (P1→P4).**

- **BRANCH.P5 (wireframe-parity) — create-branch 3-step wizard, PURELY UX over the EXISTING store.** `Admin/Branches.vue`
  replaced P4's flat create panel with a stepped flow (Step 1 Identity: name/Code/phone · Step 2 Address:
  address/city/postal/country/timezone · Step 3 Review → Create) over the SAME `admin.branches.store` route. Per-step
  client validation gates Continue; the final submit posts the SAME payload; the server's unique-Code + required
  validation are UNCHANGED + authoritative (onError jumps to the step with the first error; code-taken surfaces on
  step 1). The P2 primary invariant on create is intact (non-primary unless first). NO backend/validation/route
  change; correctly-more-real (structured address/Code/timezone) collected; hours/resources/online-booking are set
  after creation (honest — `store` doesn't take them). Locked by `tests/Feature/Platform/BranchCreateWizardTest.php`
  (5). **Branches wireframe-parity COMPLETE (P1→P5); three parity pages done: Admin Settings + Approval Queue +
  Branches.**

- Bookable-resource CRUD (CLINIC.W8c) closes the W8b "no resource backend" gap: rooms/chairs/vehicles are created
  under a branch on the same `/admin/branches` admin screen. The `Resource` model + guard are SCHEDULING (see
  [[Scheduling]] / [[D-096]]) — noted here because it is administered from the Platform branch-admin surface and its
  audit hooks + app-layer controller sit beside the branch ones. A new active resource makes a self-service branch
  bookable; deactivation is soft + BLOCKED when future appointments exist (the branch guard mirrored).
- Governance dashboard + AI approval-queue (CLINIC.W9) — READ/ACT windows over tested backends, no new autonomy or
  audit-mutation. App-layer `App\Http\Controllers\GovernanceDashboardController` (`/governance`, `audit.view`,
  STRICTLY READ-ONLY) reads Platform's `IntegrityCheck` (D-069) + `Setting` (kill-switch state) alongside Audit's
  `verifyChain`/`AuditEvent`, Billing's `ReconciliationRun` (D-068), and AiCore's `ai_interactions`/`agent_actions`;
  the only POST re-runs `verifyChain` and writes nothing. **`AuditEvent` has no `BelongsToTenant`, so the controller
  filters `tenant_id` EXPLICITLY** (Audit may not depend on Platform). `AiApprovalQueueController` (`/governance/
  approvals`, `ai.manage`) approves/rejects only through `AiCore\ApprovalQueue` (see [[AiCore]] / [[D-097]]).
  `audit.view` + `ai.manage` added to `HandleInertiaRequests::NAV_PERMISSIONS`. See [[D-097]].

## Settings UI — wireframe parity (SETTINGS.P*)

- `/settings` (`SettingsController` → `Admin/Settings.vue`) is being brought to visual + card parity with
  `resources/prototype/admin-settings.wireframe.html` (audit: `docs/wireframe-parity/ADMIN-SETTINGS-DIFF.md`).
  **SETTINGS.P1 (done):** glass visual-language parity — `eucardIn` entrance (`.euca-card-in` + reduced-motion
  guard; `Card` `animate` prop), pill+gradient Save (`Button` `pill` prop over `.btn-glow`), and an unlayered
  `.settings-surface :focus-visible` euca-700 ring. Presentational only; the glow (`.euca-wash`) + glass
  (`.glass-card`) already existed.
  **SETTINGS.P2 (done):** the "Agents & automation" card — a new app-layer `AgentAutonomyController`
  (`/admin/agents`, gated `ai.manage`, cross-linked from `/settings`) is presentation over AiCore's
  `AutonomyPolicy` (see [[AiCore]]): lists the real governed tools, LOWERS autonomy through `AutonomyPolicy::set()`
  (which clamps), never raises past a tool's ceiling (the fence), audits `ai.autonomy_changed`.
  **SETTINGS.P3 (done):** the "Scheduling" card — a new app-layer `SchedulingSettingsController`
  (`/admin/scheduling`, gated `admin.manage`, cross-linked from `/settings`) surfaces the settings the schedulers
  ALREADY read: `scheduling.portal.cancel_min_hours` (24) + `nursing.dispatch.average_speed_kmh` (40), written
  through `SettingsService::set()` at the exact reader keys (validated 0–168h / 1–200 km/h, audited
  `settings.scheduling_changed`). The "default buffer" is an HONEST per-service pointer (no global setting exists;
  buffers live on `Service`) — no fake global persisted. App-layer because the write is audited (Platform ↛ Audit).
  NOTE: its page i18n block is `schedulingSettings` (a top-level `scheduling` key already exists).
  **SETTINGS.P4 (done):** the "Security" card — a new app-layer `SecuritySettingsController` (`/admin/security`,
  GET-ONLY, gated `admin.manage`, cross-linked from `/settings`) renders the enforced controls READ-ONLY: 2FA
  "Mandatory · locked" (reflects `EnsureTwoFactorEnabled`; no setting disables it), session timeout =
  `config('session.lifetime')` (120), Nurse-PWA idle wipe = 15 (PWA client build constant, never server-read) —
  both read-only (decision (a)). NO POST/update action exists → structurally no path to disable/weaken 2FA; the
  middleware stays authoritative. Nothing written (no audit). i18n block `securitySettings` (unique).
  **SETTINGS.P5 (done):** the "Notifications" card — a new app-layer `NotificationSettingsController`
  (`/admin/notifications`, gated `admin.manage`, cross-linked from `/settings`) over a new per-event EMAIL
  preference store in Comms (`notification_preferences`; see [[Comms]]). `NotificationService::send()` now
  suppresses a non-legal email event whose pref is OFF (legal never suppressible). SMS is an inert seam (no
  provider); the clinician-attention flag is the Inbox agent's AI hand-off, locked-on with no disable path.
  i18n block `notificationSettings` (unique). Deferred: a real SMS provider.
  **SETTINGS.P6 (done) — parity COMPLETE:** a real STAFF-INVITE flow + the Settings sub-nav/IA. `staff_invites`
  (BelongsToTenant: email + role_id + sha256 token_hash + status + expiry) + `app/Services/StaffInviteService`
  (app-layer; invite/resend/revoke/accept, audited) + `StaffInviteController` (admin.manage) + a public throttled
  accept (`/invite/{token}`). Accept provisions the User in the invite's tenant with the invited TEMPLATE role via
  the REAL path (`User::create` + `RoleAssignment::create` → `role.assigned`); token is single-use/expiring/
  tenant-bound (resolved unscoped via `withoutTenantContext`+`system` — `TenantScope` constrains to the current
  tenant even in system mode, so a guest must forget context first). RBAC stays REFLECT-ONLY (no permission-edit
  route; a test asserts it); the last-admin guard + tenant isolation intact. A sticky `SettingsNav`/`SettingsLayout`
  ties the six Settings pages (Practice/Scheduling/Online booking/Agents/Notifications/Team & roles/Security).
  **The full arc P1→P6 makes the Admin Settings page wireframe-parity complete.** Deferred (non-blocking): a real
  SMS provider (P5's seam is inert).


## Auth surfaces — the AUTH sprint (wireframe-parity pass, final page)

- **AUTH-SEC.1 (D-158) — remember-me no longer bypasses 2FA.** `EnsureTwoFactorEnabled` used to assert only that
  the user had ENROLLED, so a session restored from the ~400-day `remember_web_*` recaller reached `/app` with no
  password and no challenge. It now turns a recaller-restored session back into a PENDING two-factor login (signs
  the guard out, seeds `login.id`/`login.remember`, redirects to the challenge). The challenge-passed proof
  (`EnsureTwoFactorEnabled::CHALLENGE_PASSED_KEY`) is written in exactly TWO places, both requiring a valid code:
  `App\Http\Responses\TwoFactorPassedResponse` (bound to Fortify’s `TwoFactorLoginResponse`) and a
  `TwoFactorAuthenticationConfirmed` listener. **The password factor stays remembered; the second factor never
  is.** The recaller check asks the WEB guard behind `hasSession()` + `instanceof SessionGuard` — `Auth::
  viaRemember()` proxies to the DEFAULT guard, which for Sanctum API requests is a `RequestGuard` without that
  method (a first attempt broke all 17 Nurse PWA tests). Locked by `tests/Feature/Auth/RememberMeTwoFactorTest.php`.

- **AUTH-SEC.2 (D-159) — the reset pages render, and GUEST routes are smoked.** `resetPasswords()` was enabled so
  `/forgot-password` + `/reset-password/{token}` were registered, but no Fortify view was bound: both GET pages
  were **HTTP 500** and a locked-out user had no self-service recovery. Views bound; no auth rule changed. **The
  real fix is the coverage** — every prior route smoke authenticated first, so no PUBLIC page had ever been
  requested. The FIX.5 smoke now drives the guest routes as a genuine anonymous visitor (proven by temporarily
  removing the bindings). Locked by `tests/Feature/Auth/PasswordResetTest.php` + the guest smoke.

- **AUTH-VIS (D-160) — the enrolment manual-secret fallback.** The 2FA enrolment screen offers "Can’t scan the
  code?"; revealing it prints **the user’s own real provisioning secret** as selectable text, from Fortify’s
  existing `GET /user/two-factor-secret-key` (which decrypts that user’s `two_factor_secret` — their own key by
  construction, no id parameter). The wireframe’s demo literal is NOT used and nothing is generated page-side; the
  only page-side transform is chunking into four-character blocks. No new exposure path (same auth context that
  already renders the QR encoding the same key; the endpoint was already in the middleware’s exemption list), kept
  behind a reveal. **2FA stays mandatory-locked — no skip/postpone/disable route, asserted against the route
  table.** Browser-proven by completing enrolment with a TOTP derived from the DISPLAYED secret. Locked by
  `tests/Feature/Auth/TwoFactorSecretFallbackTest.php`.

- **STILL OPEN, a PRODUCT decision (not a defect):** the effective password policy is `Password::default()` —
  min 8 characters, no `Password::defaults()` configured, so no mixed-case/digit/symbol/breach check.


## Operator Mode — the grant-gated super-admin access invariant (OPMODE.G1, D-161)

**THE GAP THAT WAS CLOSED.** `Gate::before` used to return `true` UNCONDITIONALLY for a super-admin
(`tenant_id === null`), and `PermissionService::has()` did the same for `hasPermission()`. The only thing
containing them was that `IdentifyTenantFromUser` never sets a tenant context for a super-admin, so
`TenantScope` throws. **Containment by accident, not by decision** — and Operator Mode's whole job is to give
an operator a tenant context, which would have turned the accident into unlimited, unscoped, untimed,
unaudited access to every record in that clinic.

**THE INVARIANT (fail-closed, server-side, at BOTH bypass points):** a platform operator reaches a tenant's
data ONLY through an `OperatorGrant` for THAT tenant that is **ACTIVE, UNEXPIRED, IN-TIER and IN-SCOPE**.
Both points matter — `hasPermission()` reaches `PermissionService` directly, so fixing only the Gate would
have left the invariant trivially sidesteppable.

- **Context-sensitive, not removed.** NO tenant context → platform level, the bypass stands (the `/admin`
  console, the tenant list, cron, system jobs; no tenant row is reachable there anyway because `TenantScope`
  throws). Tenant context SET → the blanket bypass is gone and only a grant permits anything. This is what
  makes the change surgical: every legitimate super-admin path keeps working, and the gap closes exactly
  where it was.
- **`OperatorAccessService`** (Platform) is the single decision point. Tiers are an **ALLOW-LIST**, never a
  deny-list: `read_only` = billing/reporting/audit **view**; `configuration` = + admin/ai/comms **manage**;
  neither can EVER reach PHI. The six PHI abilities require `full_support` **and** the specific record id
  enumerated in the grant's scope, re-checked **at access time**, not once at session start. An ability no
  tier names is denied — so a permission added to the catalog tomorrow is outside every operator tier until
  someone deliberately places it.
- **`OperatorGrant`** (Platform) is deliberately **NOT** `BelongsToTenant` — it is a platform row that
  REFERENCES a tenant, because the grant is what produces the context (scoping it would be circular). The
  grant FACTS (operator, tenant, tier, scope, expiry, reason) are immutable once written; rows are never
  deleted. `isActiveAt()` re-reads status, revocation and expiry on every call, so an expired or revoked
  grant stops working on the very NEXT check — no cache to bust, no session to invalidate. A grant with no
  expiry is invalid, not eternal.
- **`OperatorGrantService`** (`app/` — the BreakGlass layering, since Platform must not depend on Audit,
  D-017) issues, revokes and records accesses into the **TARGET TENANT's** append-only hash-chained ledger
  under a new `actor_type = 'operator'`, so the clinic can single out platform activity in its own audit
  view. **NOT the BreakGlass self-grant model:** `BreakGlassService::request()` sets `activated => true`, so
  requesting IS granting — the one property Operator Mode must not have. `full_support` cannot be issued
  without an approver who is a user of the target tenant and is not the operator (T6).
- **Agent exclusion (T9):** a test asserts the EXACT list of files in `Modules/` + `app/` that reference the
  grant, plus no AiCore/AiTool reference and nothing scheduled — the ARDETAIL.P6 Betreibung pattern.
- **The empty context is ENFORCED, not assumed.** `TenantContext` is a request/job SINGLETON, and
  `IdentifyTenantFromUser` used to achieve "super-admin -> no context" by merely declining to set one. Since a
  super-admin’s abilities now DEPEND on whether a context is present, an inherited one would silently decide
  them — so the middleware explicitly `forget()`s it for a non-tenant-staff user. (Caught by `composer check`:
  the Horizon guard went red in a test driving a staff request then a super-admin request through one
  container. A stale context can only ever DENY, never widen — fail-closed working correctly.)
- Locked by `tests/Feature/Platform/OperatorGrantAccessTest.php` (15) — the threat-model proofs
  T1/T2/T3/T4/T5/T6/T9 plus **the regression guard that the blanket bypass cannot return**. `RbacTest`'s old
  *"bypasses all checks"* test was RENAMED to say "at PLATFORM level" (its assertions unchanged — they always
  ran with no tenant context) and a second test pins the in-tenant denial.


### OPMODE.G2 — the request flow: requesting is NOT granting (D-162)

**TWO SETTLED PRODUCT DECISIONS.** `configuration` now **requires the tenant owner's approval** (it is a WRITE
tier — clinic settings + agent config — and the map flagged self-granting it as the design's weakest point), so
`TIERS_REQUIRING_APPROVAL = [configuration, full_support]`. Only `read_only` **self-grants**, and only because
it is non-PHI reads: the tier allow-list gives it exactly billing/reporting/audit **view** and refuses every PHI
ability, so self-granting it cannot expose a record.

**`OperatorGrantService::request()`** is the operator-facing entry point (tier · minimised scope · justification ·
session TTL · request TTL):

- **approval tiers → PENDING, opening NOTHING.** No `granted_at`, no `expires_at` — there is no session to be
  active with, so G1's invariant (which requires `status === active`) denies **every** ability, including the
  records the request names. This is precisely what BreakGlass does not do: its `request()` sets
  `activated => true`, so asking IS receiving.
- **`read_only` → ACTIVE at once**, session clock started, still non-PHI-only.
- **No self-approval path exists.** Only `issue()` produces an active approval-tier grant, and it demands an
  approver who belongs to the target tenant and is not the operator (T6). A test also pins the ABSENCE of any
  `approve`/`selfApprove`/`activate`/`grant` verb on the service.

**THE TWO CLOCKS ARE SEPARATE COLUMNS** (the classic bug in this shape of flow):
`request_expires_at` = how long the ASK stays open; lapsing **grants nothing**.
`expires_at` = how long an approved session lives; lapsing **ends access**.
`isAwaitingDecisionAt()` refuses an out-of-time row and `assertActivatable()` — the guard **G3's approve() must
call**, written here so the rule cannot be re-invented later — throws. `expireDueRequests()` is housekeeping that
makes a lapse visible and auditable; it is never what keeps access closed.

**SCOPE MINIMISATION.** `full_support` must NAME its records. No wildcard exists — `*`, `all`, `ALL`, `any`, `%`,
empty lists and blank ids are all refused. Whether an "all patient records" scope should exist is still an open
product decision, so until it is answered the only way to reach a record is to have named it: fail-closed by
omission, not by a flag someone could flip.

**ONE NARROWING OF A G1 RULE.** `granted_at`/`expires_at` were absolutely immutable, which the pending→active
transition cannot satisfy. They are now **SET-ONCE** — fillable from null exactly once, never rewritten — which is
stricter where it matters (an existing session can never be silently re-clocked or extended). The request facts
(`requested_at`, `request_expires_at`, `requested_ttl_minutes`) join the strictly-immutable set.

**AUDIT:** `operator.access_requested` / `operator.self_granted` / `operator.request_expired` into the target
tenant's hash-chained ledger as `actor_type = 'operator'`, carrying `grants_access_now` and
`awaiting_owner_decision` so a row states plainly whether it granted anything.

Locked by `tests/Feature/Platform/OperatorRequestFlowTest.php` (12). **Still NO owner approval, NO notification,
NO session mechanics beyond G1, NO route and NO UI** — the flow is service-level only, so there is still no HTTP
path to Operator Mode.

**Where the chain stands after G2:** the request flow exists (G2) but **owner approval does not** (G3), and there
is still **no route and no UI** — so there is no HTTP path to Operator Mode at all. Of the map's two blocking
product decisions, the `configuration` one is **SETTLED** (it requires owner approval — D-162); **whether
Operator Mode ships in the first deployment is still open**, as are who counts as an "owner" and whether an
"all patient records" scope is permitted. See `docs/features/OPERATOR-MODE-MAP.md`.


## Open items

- ABAC condition evaluation (`abac_conditions`) not yet implemented (Phase B, needs patients/audit).
- Multi-tenant same-email membership deferred (see DEFERRED.md).
- Redis/Horizon, silo tenancy tier, SSO/SAML deferred.
