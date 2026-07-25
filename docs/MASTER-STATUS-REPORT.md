# CareOS — Master Status + Gap Audit

**Single authoritative snapshot of the whole codebase: what's DONE, what's genuinely left to BUILD,
what needs FIXING, what to IMPROVE — checked deeply against the repo and CLASSIFIED as a decision tool,
not a flat to-do list.** Analysis only; no application code, route, controller, prop, test, or migration
was changed to produce it.

- **HEAD:** `765ddb6` — *UI.F2: visual-fidelity — per-page residuals (heading scale, date input, screen-by-screen match)*
- **Working tree:** clean (`main` up to date with `origin/main`).
- **CI:** GitHub check-run **`success`** for `765ddb6` (verified via the GitHub API against the commit's check-runs).
- **Migrations:** `migrate:status` → **0 pending** (140 migrations, all `Ran`).
- **Method:** repo ground-truth (git, migrate, PSR-4 autoload, route/page/nav/trigger greps) + three
  parallel deep-audit passes (routes↔pages↔nav dead-wiring · pattern-drift/security · test-coverage/demo-tenants)
  + the two most recent live artifacts (`docs/DEEP-AUDIT-REPORT.md`, `docs/DEPLOY-RUNBOOK.md`).
- **This document supersedes** the counts and dental classification in `docs/FEATURE-INVENTORY.md` (written
  at `12e0386`, pre-Dental) and the page count in `docs/SCREENS.md` (frozen at "22 pages", now 57). See §Doc-drift.

> **Headline:** the CLINIC, ADMIN, and DENTAL verticals are built, wired, green, and safe. No Critical/High/Medium
> functional, safety, fence, billing, tenancy, RBAC, or data-integrity defect exists. **There is no must-fix
> blocker before delivery.** What remains is overwhelmingly *deployment*, *another vertical's phase*,
> *partner/discovery-gated*, *unvalidated-until-asked*, or *intentional non-goals* — plus a short list of cheap
> navigability/test/doc polish. It is a **map of what's deploy-now vs. pull-forward-on-a-trigger**, not a build
> backlog to burn down.

---

## PART 1 — WHAT EXISTS (ground truth)

### 1.1 Modules (14 registered in `composer.json` PSR-4) + app-layer composition

| # | Module | What it provides (one line) |
|---|---|---|
| 1 | **Platform** | Tenancy + identity: `Tenant`/`User`/`Branch`/`Department`, RBAC (`Role`/`Permission`/`RoleAssignment`, `PermissionService`, `RbacProvisioner`), `Plan`/`FeatureFlag`/`Setting`, `BreakGlassGrant`, `IntegrityCheck`; `TenantContext` + `BelongsToTenant` (no-context ⇒ throw); MFA + tenant-identify middleware. |
| 2 | **Audit** | Append-only, hash-chained, monthly-partitioned `audit_events` + `AuditService` (`record`/`recordRead`/`verifyChain`) + read-logging concern. Depends on no other module (glue lives in `app/`). |
| 3 | **People** | `StaffProfile` (+ `user_id` link) and `credentials` with derived expiry status (`credentials:refresh-status`). |
| 4 | **Patients** | Patient CRM: per-tenant MRN, contacts/identifiers/coverages, demographic duplicate detection + audited merge/unmerge, versioned consent engine (`ConsentService`), portal identity (`PortalAccount` + `patient` guard). |
| 5 | **Scheduling** | `Service`/`ServiceCatalog`, bookable `Resource` + `ResourceAvailability`, no-double-book `BookingService` (resource-lock, hammer-proven), appointment lifecycle, waitlist + time-boxed `WaitlistOffer`, recurring `AppointmentSeries`, reminders, public online booking. |
| 6 | **Clinical** | `Encounter`, sign-and-lock SOAP `ClinicalNote` (+ amendments), raw problems/allergies/vitals/medications (exact-match allergy hard-stop only), private controller-streamed documents, referrals, `RecallEngine`, care plans + tasks, structured `Order` + manual `OrderResult` (append-only), dot-phrase `TextSnippet`, unified `VitalsHistory`. |
| 7 | **Nursing** | Home-care: `ServiceAgreement`, RRULE `VisitPlan`→`PlannedVisit` (DST-safe), deterministic `AssignmentValidator` + concurrency-safe `VisitAssignmentService`, executed `Visit` + append-only GPS proof, offline sync, timesheets from proof events, incidents, tenant-authored `Competency` matrix. |
| 8 | **Billing** | Effective-dated `TariffCatalog`/`TariffItem` + `TariffResolver`, snapshotted `Charge` + `ChargeValidator`, frozen `Invoice`/`InvoiceLine` + gapless `IssueService`, `CN` credit notes, append-only `Payment`/`PaymentAllocation`/`Refund` + `PaymentService`, `DunningService`, six-invariant `ReconciliationEngine` + `ReconciliationRun`, `AccountingExportService`, `InvoicePdfRenderer`. |
| 9 | **Comms** | Secure threads (append-only messages), one notification engine (versioned templates, consent matrix, append-only deliveries), unified inbox (derived unread), telehealth (metadata-only sessions, recording-disabled adapter, short-lived staff+patient tokens). |
| 10 | **AiCore** | Governed agent runtime: provider-agnostic `LlmManager`, append-only `ai_interactions`, `BudgetGate`/`CircuitBreaker`/`KillSwitch`, hash-pinned `PromptRegistry`, `ToolRegistry`, `AutonomyPolicy` (clinical+financial capped at approve), `ApprovalQueue`/`AgentRuntime`, KB + `KbRetriever`, Front-Desk agent. |
| 11 | **FrontDesk** | Self check-in: `CheckInService`, kiosk (identity-verified, PHI-safe, ephemeral) + portal paths, kiosk device tokens. |
| 12 | **Import** | CSV patient onboarding: mandatory dry-run (`ImportValidator`, writes nothing) → audited `ImportCommitter` through the real `PatientService`. |
| 13 | **Reporting** | Read-only facts layer (`MetricsService`/`ReportingService`): universal operational/financial/throughput aggregates; owns no tables, emits no judgments. |
| 14 | **Dental** | FDI odontogram (`tooth_records`, append-only) + `ToothChartService`; dental procedure catalog (dental `TariffCatalog` overlay); atomic `PerformProcedureService` (charge + clinical + tooth-state in one txn); phased fee-scheduled `TreatmentPlan`; perio charting (append-only); dentist-authored `Diagnosis` (append-only); imaging upload + 2D viewer + dentist reading (append-only). Record-not-judge throughout. |

**App-layer composition (`app/*`)** — where two modules must be composed (modules never depend on each
other): AiCore agents/tools (`app/AiCore`: Scheduler, Clinical Summary/Follow-up, Dispatch, Billing, Inbox),
audit glue (`app/Audit`), Comms bridges (`app/Comms`), Clinical↔Nursing vitals reader (`app/Clinical`),
`BreakGlassService` (`app/Services`), and the cross-module controllers (`app/Http/Controllers`: AppLanding,
Branch, Resource, KbArticle, GovernanceDashboard, AiApprovalQueue, ClinicalSummary, Portal).

> **Module-map drift:** `AGENTS.md` lists `Platform · Audit · People · Patients · Scheduling · Clinical ·
> Nursing · Billing · Comms · AiCore · Dental · Interop`. **`Dental` now exists** (built G1–G9). **`Interop`
> does NOT exist on disk** — it remains a planned placeholder (Interop = the deferred lab HL7/FHIR & claims lane).

### 1.2 Routes + Inertia pages by vertical (57 pages · 175 web routes · 5 nurse-PWA API routes)

Guard model: staff web routes run `auth` + the web group (`HandleInertiaRequests`, `IdentifyTenantFromUser`,
`EnsureTwoFactorEnabled`); the per-screen RBAC permission is enforced by `Gate::authorize(...)` **inside** each
controller (or its service). Nav links are hidden by the same permission (`AppLayout.vue` + `NAV_PERMISSIONS`) as
a UX hint — the server Gate is authoritative.

| Vertical | Pages | Delivery style |
|---|---|---|
| **Auth / shell** | `Auth/Login`, `TwoFactorChallenge`, `TwoFactorEnroll`; `App/Landing`, `Admin/Landing`; `Error` | re-skin (W1) · landing wired (FIX.2) · error page (FIX.4) |
| **Clinic — Patients** | `Patients/Index`, `Show` (360 + access-log tabs), `Register` | **wired-over** (re-skin W2) |
| **Clinic — Clinical** | `Clinical/Chart`, `NoteEditor`, `OrdersReview`, `OrderableItems`, `Snippets` | **wired-over** (re-skin W5) + P0P |
| **Clinic — Scheduling/Comms/Nursing** | `Scheduling/DayBoard`, `Comms/Inbox`, `Nursing/Dispatch`, `Nursing/Competencies` | **wired-over** (re-skin W4) + P0P |
| **Clinic — Billing (staff)** | `Billing/Invoices/{Index,Show,New}`, `Aging`, `CreditNotes/{Index,Show}`, `Payments/{Index,Record,Show}`, `Dunning/Index` | **built-over** engine (W6–W7) |
| **Clinic — Reporting / Telehealth** | `Reporting/Dashboard`, `Telehealth/Sessions` | **built-over** (W7 / W10) |
| **Admin** | `Admin/{Settings,Roles,Branches,Kiosks}` | **built-over** (W8/W8b/W8c) |
| **Governance / AI-ops** | `Governance/{Dashboard,ApprovalQueue,KnowledgeBase}` | **built-over** (W9–W10) |
| **Import** | `Import/Index`, `Import/Upload` | **built-over** (P0P.G6) |
| **Dental** | `Dental/{Index,Odontogram,PerioChart,Diagnoses,TreatmentPlans,Imaging,FeeSchedule}` | **built-over** engine (DENTAL.G1–G9) |
| **Portal (patient)** | `Portal/{Login,Home,Appointments,Documents,Messages,Invoices,TreatmentPlan,Consents,Telehealth}` — all 8 authed pages in portal nav, on the dedicated `PortalLayout` | **wired-over** (re-skin W3) + G5/G9 |
| **Public / Kiosk** | `Public/Book` (`/book/{slug}`, throttled), `Kiosk/CheckIn` (`/kiosk/{token}`, device-token) | **wired-over** (W4) |
| **Nurse-PWA** (separate `nurse-pwa/` SPA) | `POST/GET /api/nurse/{login,logout,day-pack,sync}` (+ `attachments/{id}/download`) | separate Vite/Vue app (Phase E) |

- **Wired-over-backend** (re-skin only, P0D.GU, routes/controllers/props/tests frozen): W1–W5 = shell/auth/landings ·
  patients · portal · staff boards · clinical.
- **Built-over-backend** (presentation over frozen, tested engines, zero domain-logic change): W6–W10 = staff billing ·
  reporting · admin · governance/KB · staff telehealth; P0P.G6 import; DENTAL.G1–G9.

### 1.3 Test / CI state — 4 testsuites, ~115 test files, Pest 707/5741, all green

**Testsuites** (`phpunit.xml`): `Unit` · `Feature` (bulk) · `Architecture` (`ModuleBoundariesTest` enforces the
cross-module boundary) · `Evals` (agent electric-fence harness). Suite runs against real MySQL/MariaDB (SQLite lines
commented out — the trigger/immutability/parity tests are only meaningful on the real engine).
**Composer scripts:** `check` = `lint` (Pint) → `analyse` (PHPStan L5) → `test` (Pest); plus `eval`, `test:smoke`,
`test:mysql` (fresh-migrate + full run on MySQL 8). *(No `test:pwa` script — PWA/offline behaviour is covered by
Feature tests: `NursePwaDayPackTest`, `AirplaneModeDemoTest`, `VisitExecutionSyncTest`.)*

| Safety suite | Where | What it LOCKS |
|---|---|---|
| **Reconcile-to-the-unit** (LAUNCH BLOCKER) | `tests/Feature/Billing/ReconciliationTest.php` + `ReconciliationEngine` | Six invariants I1–I6, each with a clean + a raw-SQL-corruption case; `delta_minor === 0` exactly on clean; export refuses unless the period's latest reconciliation passed. |
| **Electric fence + agent safety** | `tests/Evals/*` (7 files) + `Support/EvalHarness.php` | `evNoNetwork()` (no live LLM); grounding/source-linking, "agent's numbers ignored" (price via `TariffResolver`, violations via `ChargeValidator`, fuzzed 0 disagreements), autonomy ceiling ≤ approve, budget/kill-switch degrade-to-manual, clinical-appropriateness refusal + handoff. |
| **Immutability + audit chain** | `tests/Feature/Security/{ImmutabilitySweepTest,AuditChainAlarmTest}.php` + `Audit/*` | Raw SQL past Eloquent rejected at 14 append-only tables; drafts stay editable; `verifyChain` detects a tampered/ dropped trigger (`broken_at`, exit 1, ERROR log); `integrity_checks` itself append-only. |
| **No-double-book PARALLEL HAMMER** | `Scheduling/BookingParallelHammerTest`, `Nursing/VisitAssignmentParallelHammerTest`, `Billing/{InvoiceParallelHammer,PaymentAllocationHammer}`, `Scheduling/WaitlistOfferHammer` | 8 real OS processes with a synchronized start against one slot/nurse/number → exactly 1 winner, 1 DB row. |
| **Route-reachability smoke** | `tests/Feature/Smoke/RouteSmokeTest.php` | ~45 major GET routes through the REAL middleware stack with tenant context forgotten (the C-1 condition) → 200-not-500; per-role RBAC 200/403 matrix; all portal pages; public `/book/{slug}`. A new page = one line. |
| **MySQL 8 parity** | `tests/Feature/Platform/MutableMomentParityTest.php` + `composer test:mysql` | DATETIME-vs-TIMESTAMP implicit-`ON UPDATE` divergence; consent `granted_at` / token `expires_at` preserved; `information_schema` sweep; CI asserts 0 pending migrations after a from-scratch MySQL 8 migrate. |
| **Cross-tenant security** | `tests/Feature/Security/{CrossTenantIsolationTest,RbacNegativeSweepTest}.php` | A tenant-B `org_admin` attacks the demo tenant across 23 routes → all fail closed (403/404), no victim secret in body; RBAC negative sweep crafts service calls directly so a UI-only permission fails. |

CI is green on **MySQL 8 + Redis 7 + Node 22** for HEAD.

### 1.4 Demo tenants — three, each reconcile-to-the-unit + chain-verify + idempotent

All three seeders exist under `database/seeders/`, each with a paired test:

| Seeder | Test | Reconcile | Chain | Idempotent |
|---|---|---|---|---|
| `DemoClinicSeeder` (Praxis Lindenhof, CHF) | `Demo/DemoClinicSeederTest` | 6 invariants, `delta_minor===0`, `rows===[]` | `verifyChain()['ok']===true` | whole-schema `SHOW TABLES` count map identical on 2nd run; vacuous-pass guard |
| `DemoSpitexSeeder` (Spitex Sonnengarten, EU-Generic) | `Demo/DemoSpitexSeederTest` | same 6-invariant loop; 6 gapless invoices | ✅ | ✅ (+ `nurse_competencies>0` guard) |
| `DemoDentalSeeder` (Zahnarztpraxis Morgenstern, CHF) | `Demo/DemoDentalSeederTest` | same loop; 3 gapless invoices | ✅ | ✅ (+ asserts the dental fence: no interpretation columns) |

Each seeds through the **real services**, and the clinic tenant is the shared fixture the security/immutability/
audit-chain/route-smoke suites all attack — one dataset probed from every safety angle.

---

## PART 2 — CODE-LEVEL DEEP CHECK (real remaining work, with file:line)

### 2.5 TODO / FIXME / stub scan — essentially clean

- **TODO/FIXME/HACK/XXX:** one hit, a false positive — `tests/Feature/Platform/AdminSettingsRolesTest.php:100` uses
  `'XXX'` as an *invalid currency code* in an assertion. **No real TODO/FIXME/HACK anywhere** in `app/`, `Modules/`,
  `resources/js/`.
- **`throw new …NotImplemented` / empty bodies / placeholder returns:** none found.
- **The one "meant-to-be-real-later" stub:** `Modules/Clinical/src/Services/ManualLabConnectivity.php` (bound in
  `ClinicalServiceProvider.php:15`) — a deliberate no-op `LabConnectivity` implementation (electronic HL7/FHIR lab
  transmission is a documented DEFERRED partner item, and `Clinical/OrderTest.php:170` *asserts* it is a no-op). This
  is intentional, not a loose end.
- Every other `no-op`/`placeholder` hit is an **intentional idempotency guard** (`ImportCommitter`, `CheckInService`,
  `DunningService`, `UserRoleController`), the **whitelisted snippet placeholder** substitution (a fence feature), or
  a test name. **Verdict: no genuine stub debt.**

### 2.6 Dead / incomplete wiring (all file:line-cited)

**No orphan pages** — all 57 `.vue` pages resolve to a render source (55 via `Inertia::render`, `Auth/Login` +
`TwoFactorChallenge` via Fortify, `Error` via `bootstrap/app.php:75`). **No route targets a missing controller/action.**
But three classes of dead wiring exist:

**(a) 4 registered endpoints with no in-app caller (only tests):**
1. `POST /scheduling/series/end` (`routes/web.php:132`) → `AppointmentSeriesController@end` — `DayBoardController.php:83-94`
   never passes a series-end URL and `Scheduling/DayBoard.vue` never posts to it. **"End an active series" is
   unreachable from the UI** (the one with a real user-facing consequence).
2. `GET /api/nurse/attachments/{attachment}/download` (`routes/api.php:33`) — the PWA only *uploads* attachments
   (`nurse-pwa/src/api.ts`); nothing downloads. Test-only.
3. `GET /clinical/encounters/{encounter}` (`web.php:162`, returns JSON) — no frontend caller. Test-only.
4. `GET /clinical/notes/{note}` (`web.php:164`, returns JSON) — no frontend caller. Test-only.

**(b) 6 built + tested pages that are undiscoverable (no nav entry AND no inbound link — reachable only by typing the URL):**
| Page | Route | Note |
|---|---|---|
| `Clinical/OrdersReview.vue` | `clinical.orders.worklist` (`web.php:188`) | An "orders to review" worklist clinicians would expect in nav. |
| `Clinical/OrderableItems.vue` | `web.php:189` | Order-catalog admin; undiscoverable. |
| `Clinical/Snippets.vue` | `web.php:194` | Dot-phrase admin (NoteEditor has an inline insert widget, not a link here). |
| `Nursing/Competencies.vue` | `web.php:149` | Competency-matrix admin; undiscoverable. |
| `Admin/Kiosks.vue` | `admin.kiosks.index` (`web.php:345`) | The only admin screen not cross-linked from `Admin/Settings.vue` (Roles & Branches are). |
| `Import/Index.vue` | `import.index` (`web.php:276`) | **The CSV-import wizard has no entry point into the app** — sole inbound reference is a back-link *from* `Import/Upload.vue:221`. |

> This is the **same "built but unreachable" class as the pre-G9 dental gap** (which G9 fixed for dental). These six
> clinic/admin pages still have it. All are cheap to close (add a nav item / cross-link); none is a safety issue.

**(c) 3 domain-built services with no production caller (tests/seeders only):**
1. `Modules/Patients/src/Services/PatientDuplicateReviewService.php` — no duplicate-review route/queue exists (the
   register wizard uses `DuplicateDetector`; merge uses `PatientMergeService`). Domain built, no entry point.
2. `app/Services/BreakGlassService.php` — no controller/middleware invokes it (operator-mode/break-glass is the
   deferred admin bucket).
3. `Modules/Nursing/src/Services/TimesheetService.php` — only demo seeders + a test call it; **nurse timesheets have
   no staff HTTP surface** (a coordinator can't view/approve timesheets in the UI).

### 2.7 Test-coverage gaps (risk-noted)

| # | Gap | Risk | Verdict |
|---|---|---|---|
| 1 | **Invoice PDF content is smoke-only** — `InvoicePdfRenderer` is hit for a 200 but no assertion on the rendered totals/VAT/credit-note sign. | MONEY, customer-facing: a renderer bug that misstates a total on the printed document would pass (reconciliation checks the DB, not the PDF). | **Genuine — worth a content test.** |
| 2 | **AiCore `CircuitBreaker` state coverage is indirect** — asserted only via `AiCoreFoundationTest`; no dedicated open/half-open/close test. | FENCE: a stuck breaker would let a degraded LLM keep serving. | Genuine-minor (AiCore is otherwise the most-tested area — 12 files). |
| 3 | **Reporting beyond `summary()`** + any report export path untested. | Low (read-only, fence-locked). | Low. |
| 4 | People staff-profile **lifecycle** (hire/offboard/deactivate) untested. | — | **Not a gap in built code:** People has *no* StaffProfile create/deactivate service/controller/UI (only `CredentialService` + `RefreshCredentialStatuses`). "No test" = "no feature." Category F, with a safety note: *when* staff offboarding is built it needs the branch/resource-style deactivation guard. |
| 5 | Import exercises only `type='patients'`. | — | **Not a gap:** `ImportBatch` defines only `TYPE_PATIENTS`; there is no second importer. Forward-looking column, nothing untested today. |

*Well-covered (not gaps):* the fence "raw-facts-only" rule is locked in ≥3 independent places (demo-seeder
no-interpretation-column asserts, `MetricsServiceTest` numeric-leaves-only, agent evals numbers-ignored); kiosk/
device-token security and the offline Nurse PWA sync are fully covered despite being single files.

### 2.8 Consistency / pattern drift — one minor actionable item; everything else clean

| Check | Verdict (evidence) |
|---|---|
| **Billing math outside `Modules/Billing`** | **CLEAN.** All VAT/`qty*price`/negation/balance math is in Billing services only. Dental is sum-of-snapshots + reads (`TreatmentPlanService.php:156-159`, `:125`); Reporting/Portal are SQL `->sum('*_minor')` reads. Borderline-but-fine: `Billing/Aging.vue:38` computes a % *share* for a bar (presentation ratio, never a stored currency value); `AiCore/BudgetGate.php` sums `cost_minor` (AI-spend governance, a deliberately separate domain, in a service). |
| **Implicit route-model binding of tenant models (C-1 class)** | **CLEAN.** Every tenant-owned target resolves `string $id` via `Model::query()->whereKey($id)->firstOrFail()` (e.g. `PatientShowController.php:17`, `InvoiceController.php:94`, `PaymentController.php:161`). The only Eloquent binding is `PublicBookingController.php:24 index(Tenant $tenant)` on `book/{tenant:slug}` — the tenant ROOT resolved from the slug, not a tenant-owned row. Intentional. |
| **`env()` in app code** | **CLEAN.** Zero `env(` under `app/` or `Modules/` (config-cache-safe). |
| **Hardcoded hex / i18n bypass** | **One drift:** `resources/js/pages/Dental/Odontogram.vue:55-68` hardcodes a 13-entry `CONDITION_COLOUR` palette (+ fallbacks `:73`, `:392`) — the *only* page with hardcoded hex. Intentional (a factual categorical charted-condition legend, not a severity ramp) but a genuine deviation from "design tokens only" → **should be tokenized.** i18n is CLEAN (all `:label="t(...)"`, no hardcoded text nodes). |

### 2.9 Security / safety spot-check — no gaps

| Check | Verdict |
|---|---|
| **RBAC gate on every endpoint** | **CLEAN.** Layered: controller-level `Gate::authorize` (e.g. `DayBoardController.php:22`, `DentalLandingController.php:27`), service-level (`OrderService`), or AI-runtime (`ApprovalQueue::propose` re-authorizes `tool->permission`; approve/reject re-authorize). Portal behind `portal-tenant`+`portal-auth`+`portal-consent`. |
| **PHI via public URL** | **CLEAN.** Zero `Storage::url`/`asset()`/`temporaryUrl`/`'public'`-disk on PHI. Documents + dental images store on the private `local` disk (`DocumentService.php:74,207`) and stream through authed, Gate-checked controllers (`DocumentDownloadController`, `PortalDocumentController`, `DentalImageController` with `nosniff`/`no-store`). |
| **Append-only DB triggers** | **CLEAN.** All 22 append-only tables carry BOTH `BEFORE UPDATE` and `BEFORE DELETE` triggers (48 triggers / 24 migration files), including the finalization-conditional variants (`clinical_notes_signed_*`, `invoices_issued_*`, `timesheet_lines_approved_*`). |
| **AI autonomy caps** | **CLEAN.** `AgentRuntime.php:71` enforces the `AutonomyPolicy` ceiling; clinical + financial tools hard-capped at `approve`; no create/propose route lets a human inject an un-fenced action or raise autonomy. |
| **Fence surfaces (vitals/labs/odontogram/perio/diagnosis/imaging)** | **CLEAN.** No severity/score/grade/risk/abnormal/flag computed or rendered anywhere (heavily asserted in code + confirmed live in `DEEP-AUDIT-REPORT.md`). Odontogram colour is categorical-with-disclaimer; perio renders raw `9/2•`; diagnosis auto-populates zero; imaging analyses nothing. (`severity` at `NoteEditor.vue:35`/`Chart.vue:37` is a recorded **allergy** data-entry field, not a computed judgment — within bounds.) |

---

## PART 3 — REMAINING WORK, CLASSIFIED (the decision tool)

Every remaining item tagged with exactly one category. Items **not** already parked in `DEFERRED.md` /
`docs/FEATURE-INVENTORY.md` are marked **⊕ NEW (this audit)**.

### (A) DEPLOYMENT / OPS — not code (the real next step; full runbook in `docs/DEPLOY-RUNBOOK.md`)
- Provision a Linux host (PHP 8.2 + `pdo_mysql`/`redis`/`mbstring`/`openssl`, MySQL 8, Redis 7, Nginx, Supervisor, Certbot, Node 22).
- **MySQL 8 cutover** (parity proven `docs/DB-PARITY.md`; MariaDB 10.4 is EOL) with `utf8mb4_unicode_ci`.
- Production `.env`: `APP_DEBUG=false`, `QUEUE_CONNECTION=redis` (load-bearing), `CACHE_STORE=redis`, `LIVEKIT_HOST` (**not** `LIVEKIT_URL`), `APP_KEY` (the only encryption key), real SMTP.
- `key:generate` → `migrate --force` → `config/route/view/event:cache`; **BOTH** `npm run build` **and** `npm run build:pwa`.
- **Horizon under Supervisor** + the **scheduler cron** (`* * * * * schedule:run`) driving all 8 jobs — without these, reminders/reconcile-alarm/audit-chain/dunning never run.
- Nginx + HTTPS; **private-disk PHI verification**; **nightly off-box `mysqldump` backups + monitoring** *(⊕ NEW: backups/monitoring are called out in the runbook but not otherwise tracked — do before real patient data lands)*.
- Per-customer onboarding: tenant → branches/opening-hours/timezone → resources (**seed availability programmatically** — no admin screen yet) → roles (`doctor` for a dentist; **no `dentist` template**) → dental fee schedule → CSV import (dry-run→commit) → KB.

### (B) ANOTHER VERTICAL'S PHASE — another customer, later
- **Insurance / claims vertical** (eligibility · submission · adjudication states) — distinct from the built cash/patient-pay billing; also partner-gated (C).
- **Dental specialist gates** beyond the general-dentist set: chair-scheduling view (reuse day-board), sterilization/inventory, ortho/aligner tracking.

### (C) PARTNER-GATED — no code alone delivers it (the real long poles)
- **Online payment capture (PSP)** — manual recording is BUILT (`Payments/Record`); card capture needs a PSP + reconciliation wiring.
- **e-Prescribing** — pharmacy network + licensed drug DB + per-market e-Rx rails.
- **Real lab connectivity (HL7/FHIR)** — structured orders + manual results BUILT; electronic transmission is the `ManualLabConnectivity` stub only.
- **Claims clearinghouse (US X12 / payer submission)** — the insurance vertical's transport.
- **Live dental imaging capture** (X-ray sensor / intraoral scanner) + **DICOM/PACS** + **3D scan overlay/comparison**.

### (D) DISCOVERY / MARKET-GATED — needs a market/customer answer
- **CH / KVG statutory billing pack** (+ camt.053 bank reconciliation) — *the single riskiest open assumption* and the likely real first *new build*, but only after coordinator calls confirm the Spitex reimbursement model (`docs/DISCOVERY.md`).
- **DE / FR statutory packs** (DATEV columns, per-country VAT/tariff).
- **eMAR** (medication administration record).
- **Telehealth recording + transcripts** (needs a funded consent/retention design first).
- **Cross-tenant owner-approval / share objects** (explicit share objects, never scope-widening).
- **Phase-H agents** (fuller RAG front-desk / ops-analyst / onboarding).

### (E) SAFE-TO-BUILD-NOW — UI over an already-tested backend (W6/W7 pattern; still pull from a real need)
- **Wire the 6 undiscoverable pages into nav/links** ⊕ NEW — cheapest of all; the Import-wizard entry point and the OrdersReview worklist are the highest-value.
- **Timesheet view/approval UI** ⊕ NEW (`TimesheetService` built; no HTTP surface — a coordinator can't approve timesheets in-app).
- **Duplicate-review queue** ⊕ NEW (`PatientDuplicateReviewService` built; no entry point).
- **Fee Schedule Editor** — already delivered (DENTAL.G3); the stale FEATURE-INVENTORY "(D)" entry is resolved.
- **Statement-of-Account PDF**, **AR account detail (per-patient)**, **Governance ledger-export UI**, **Recall due-list worklist**, **Service catalog page**, **Care-plan-review / Referral-out pages**, **My Account (staff 2FA/password)**, **Portal invite/reset pages** — backend exists; only a screen is absent.

### (F) BUILDABLE-BUT-UNVALIDATED — no dependency, nobody has asked
- **Resource-availability admin screen** (flagged W8c follow-up — until a resource has availability rows the slot finder returns nothing; onboarding seeds it programmatically today).
- **Full per-widget timezone display** (W8b stores/normalizes tz; per-widget rendering pending).
- **Staff-profile lifecycle / offboarding** ⊕ NEW (with a deactivation guard) · **rostering** · **bulk ops** · **family comms** · **route optimization (OR-tools)** · **Notification Center** · **No-Show follow-up workflow** · **Payment-Plan / installments domain** · **Super-Admin platform UI** · **operator-mode/break-glass UI** (`BreakGlassService` exists) · **Reverb realtime** · **richer dashboards** · **i18n content beyond English**.

### (G) FIX / POLISH — genuine loose ends worth doing regardless
- **MUST-FIX (safety / correctness / data-integrity): NONE.**
- **Nice-to-have** (all ⊕ NEW unless noted):
  - Tokenize the `Odontogram.vue:55-68` hardcoded palette (the one pattern-drift item).
  - Add an **Invoice PDF content assertion** (money-adjacent coverage gap).
  - Add a **CircuitBreaker state test**.
  - Wire the **6 undiscoverable pages** + decide the fate of the **4 uncalled endpoints** (the series-end UI action is the notable one — either surface it on the DayBoard or drop the route).
  - **Refresh stale docs:** `SCREENS.md` (22→57 pages), `FEATURE-INVENTORY.md` (13→14 modules, dental now built), the two delivery maps.
  - `L-E` org_admin nav density (12 items; "Knowledge base" wraps — group admin/governance under a menu). *(from DEEP-AUDIT)*
  - Trim/code-split the ~334 KB main JS bundle. *(from DEEP-AUDIT; acceptable today)*

### (H) INTENTIONAL NON-GOAL — fence breaches never to build
- Homemade **drug-interaction / allergy-class / dose / CDS** checker (medical-device territory; the built allergy rule is exact-match only).
- **AI abnormal-flagging** on labs/vitals; **AI caries/pathology detection** or **auto-annotation/overlay** on dental imaging.
- **Auto-diagnosis / differential ranking**; **perio staging/grading**; **odontogram severity heatmap**.
- **AI listening to / transcribing / triaging** a telehealth call or patient message.
- Any **symptom/triage free-text** on public booking or kiosk.

**Cross-check vs `DEFERRED.md` / `FEATURE-INVENTORY.md`:** the B/C/D long poles, PSP, lab, Reverb, i18n, eMAR,
and dental partner gates are already parked with triggers. **NOT yet parked anywhere (new from this audit):** the
6 nav/discoverability gaps, the 4 uncalled endpoints, the Timesheet-approval + Duplicate-review UIs, staff-profile
lifecycle, the Odontogram tokenization, the Invoice-PDF + CircuitBreaker coverage tests, backups/monitoring, and
the doc-drift refresh. `FEATURE-INVENTORY.md` is itself stale (pre-Dental) and is superseded by this document.

---

## PART 3.11 — Performance · UX · Design · A11y (prioritized, worst/most-impactful first)

From static analysis + the live `docs/DEEP-AUDIT-REPORT.md` (Playwright, 2026-07-21). *(That audit's G-1, G-2,
L-A, L-B, L-C, L-D findings were subsequently RESOLVED by DENTAL.G9; the items below are what remains.)*

1. **UX / navigability (highest, ⊕ NEW):** 6 built pages are undiscoverable — the **CSV-import wizard has no
   entry point**, and the **clinical OrdersReview worklist** isn't in nav. Same class as the pre-G9 dental gap;
   cheap to close.
2. **UX:** the **"end a series" action is unreachable** from the DayBoard (route exists, no UI affordance).
3. **Design/drift:** the **Odontogram hardcoded colour palette** should move to design tokens.
4. **UX/responsive (`L-E`):** org_admin's **12-item top nav** is dense ("Knowledge base" wraps) — a narrow viewport
   could overflow; group admin/governance under a menu.
5. **Performance:** main JS bundle **≈334 KB** (shared Vue/Inertia runtime, cached once; per-page chunks tiny,
   e.g. Odontogram 16 KB). **No slow page, no N+1, tiny payloads (3–7 KB), zero console errors** observed. Trim later; not a problem.
6. **Accessibility:** observational pass is good (real `<label>`/`aria-label`, individually-labelled 2FA boxes,
   working focus/keyboard, adequate eucalyptus contrast). **Not yet done:** a dedicated **contrast/AT sweep on the
   dense odontogram & perio grids** (tap-target size, screen-reader order) — **needs a runtime/browser audit to confirm.**
7. **Docs:** `SCREENS.md` / `FEATURE-INVENTORY.md` / delivery maps are stale vs. current code.

---

## PART 4 — THE VERDICT

**Is the product (clinic + dental) functionally complete, safe, and deliverable? — YES.**
Every clinic + admin + dental operational surface is built, wired, and green; the electric fence,
reconcile-to-the-unit, append-only immutability, hash-chained audit, fail-closed tenancy, and RBAC guarantees hold
in the test suite, in CI on MySQL 8 + Redis 7, and (per the live Playwright audit) in a real browser across all
roles and both demo tenants. No Critical/High/Medium functional, safety, fence, billing, tenancy, RBAC, or
data-integrity defect was found.

**Must-do before delivery (category G — safety / correctness / data-integrity only):**
> **NONE.** There is no blocker.

**Strongly recommended before a customer demo (cheap G-polish, not blockers):**
1. Wire the **CSV-import wizard entry point** + the **OrdersReview worklist** (and the other 4 pages) into nav.
2. Surface or retire the **"end a series"** DayBoard action.
3. Refresh the **stale docs** (SCREENS/FEATURE-INVENTORY/maps) so the next reader trusts them.
   *(The dental navigability + seeder items the prior audit flagged are already done — DENTAL.G9.)*

**Honest bottom line.** The remaining work is **not a build backlog to burn down.** It sorts almost entirely into
**(A) deployment/ops**, **(B) another vertical's phase**, **(C) partner-gated**, **(D) discovery/market-gated**,
**(F) buildable-but-unvalidated**, and **(H) intentional non-goals** — with only a short **(G)** list of genuinely
worth-doing polish (nav links, two coverage tests, one palette tokenization, doc refresh), **none of it blocking.**
The correct next move is unchanged: **deploy the built verticals** and run the **CH/KVG billing discovery** with
Spitex coordinators — the one answer that unlocks the likely real first *new* build — while the partner-gated lanes
proceed as partnerships, not speculative code.

---

*Produced read-only. No application code, route, controller, prop contract, test, or migration was changed.
Counts and classifications verified against the repo at `765ddb6`; they supersede the pre-Dental snapshot in
`docs/FEATURE-INVENTORY.md`.*
