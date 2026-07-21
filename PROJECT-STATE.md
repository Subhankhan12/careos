# PROJECT-STATE.md

Short, factual snapshot of where the project stands. Updated at consolidations and after gates
(per the MEMORY PROTOCOL in AGENTS.md).

## CURRENT FOCUS: DISCOVERY (not building)

**Read this before starting any work. The next unit of progress is NOT another gate.**

- **State:** the backend is feature-complete for MVP+ (~93 gates through Phase P; the P0P sequence
  G1–G11 is complete). The CLAUDE design pass (**Eucalyptus Glow**) is in progress across the 34
  screens (22 Inertia pages + 11 nurse-PWA screens; see `docs/SCREENS.md`). **The next action is
  CUSTOMER DISCOVERY — talking to Swiss Spitex coordinators and clinics — NOT a new module.** Do not
  start building a gate unless discovery produces evidence that pulls a specific feature forward.
- **The single riskiest assumption to test — Swiss Spitex billing is probably NOT clean cash-pay.**
  It is largely **KVG/KLV insurance reimbursement to Krankenkassen + canton/municipal contributions
  + patient co-pays** — a THIRD model, distinct from both US X12 claims and simple patient-pay. The
  **CH statutory billing pack is deferred/unbuilt** (see DEFERRED.md). Therefore "our EU-Generic
  billing fits Spitex" is **UNPROVEN and possibly false.** If coordinators confirm it, the **CH
  billing/reimbursement pack becomes the likely real first build**, and the offline nurse PWA is the
  wedge to get in the door — not the first thing monetized. (This is a HYPOTHESIS to confirm in the
  first 2–3 coordinator calls, not established fact — nobody has verified current KVG rules from here.)
- **The market-decision rule:** the **reimbursement mechanics**, heard consistently across the first
  ~3 coordinators, decide the market (EU-cash vs CH-insurance-funded vs US-claims). Everything else is
  prioritization. Full brief: `docs/DISCOVERY.md`; outreach: `docs/outreach-de.md`.

- **QA-audit remediation (post-clinic-delivery, `docs/QA-AUDIT-REPORT.md`) — COMPLETE.** The live-browser audit
  found one Critical (C-1) and a set of Medium/Low polish items; all are now cleared. **C-1** (FIX.1, string-id
  route resolution) is **browser-verified** end-to-end; **M-1** staff landing wired to MetricsService (FIX.2);
  **M-2** date-only timezone shift fixed with the shared `formatDateOnly` helper + TZ-robust Vitest (FIX.3);
  **FIX.4** cleared the rest as presentation/demo-data-only polish — **M-3** vitals shown in clinical units
  (kg/cm) via a display-only `@/lib/units` helper (storage stays g/mm; still raw, no interpretation); **M-4**
  client-side nav gating via `auth.user.permissions` shared from `HandleInertiaRequests` (server Gate stays
  authoritative); **M-5** an in-shell Eucalyptus Glow `Error` page for 403/404/419/503 + the portal
  consent-withdrawal lockout (wired in `bootstrap/app.php`, presentation only); **M-6** realistic/stable seeded
  vitals; **L-1** i18n pluralisation; **L-2** clinic day-board resources are rooms/chairs not vehicles; **L-3**
  the clinic demo tenant settles in CHF. Discovery remains the next real unit of progress — these fixes just
  make the clinic vertical demo-credible. **FIX.5** adds the systemic guard the audit recommended: a
  route-reachability smoke (`tests/Feature/Smoke/RouteSmokeTest.php`, `composer test:smoke`) that drives every
  major route through the REAL middleware stack — tenant context forgotten before each request — asserting
  200-not-500 for every role + portal, wired as a dedicated CI step so a request-time 500 like C-1 can never ship
  green again. **FINAL PRE-DEPLOYMENT PASS (QA gate):** re-drove the whole surface in a real browser (both demo
  tenants, all roles + portal) — every FIX.1–FIX.5 remediation holds in-browser, and the electric fence + kiosk
  PHI-safety hold. Only two NEW issues, both cosmetic + safe-fixed inline (a hard-coded "Amount (EUR)" label on
  the record-payment page → now the tenant currency; a doubled `comms.email` portal-consent scope chip → labelled).
  No fence/billing/RBAC defect open. **Verdict: the CLINIC vertical is deployment-ready** (see
  `docs/QA-AUDIT-REPORT.md` §9); the only remaining gaps are designed-but-unwired admin screens (governance / KB /
  AI-queue / settings / RBAC-UI / staff-telehealth join) — a founder scope decision, not blockers.

- **CLINIC.W8 built two of those admin gaps — Settings + Roles/access.** UI-over-existing-backend (like W6/W7):
  `/settings` (SettingsController) edits the settlement `currency` + invoice-issuer identity through the EXISTING
  `SettingsService` (tenant profile + branches read-only; the rest listed as honest gaps); `/admin/roles`
  (UserRoleController) assigns one of the 6 seeded role templates via the sanctioned auto-audited `RoleAssignment`
  path (no role builder, no per-permission toggles; server Gate authoritative; a last-org_admin self-lockout guard).
  Both admin.manage-gated + tenant-scoped, covered by 9 feature tests + the route smoke. **Still not wired** (the
  remaining founder-scope gaps): governance dashboard, KB admin, AI approval-queue, staff-telehealth join. See D-094.
  (UPDATE: CLINIC.W9 later wired the **governance dashboard + AI approval-queue** — see the W9 bullet + D-097; the
  still-unwired remainder is KB admin + staff-telehealth join.)

- **CLINIC.W8b built the settings BACKENDS** the W8 discovery found missing — genuine domain work, scheduling-safe:
  editable practice **profile** (new nullable `tenants` columns; slug/region/status/plan stay read-only), **branch
  CRUD** (app-layer `BranchController`/`BranchService`), per-branch **opening hours** (new `branch_hours` table wired
  into `AvailableSlotFinder` + the `BookingService` write guard — a branch with no hours keeps the default 07:00–19:00,
  so existing scheduling stays green), and **timezone/locale** applied per request via `ApplyTenantLocaleTimezone`.
  **Scheduling safety (tested):** branch deactivation is soft (`active=false`) and BLOCKED while future appointments
  exist (never orphaning care); the day-board/portal now hide inactive branches; opening hours bound bookable slots +
  reject out-of-hours bookings. All admin.manage-gated, tenant-scoped, audited. See D-095.

- **CLINIC.W8c built bookable-resource CRUD** — closes the W8b gap so a self-service branch is fully bookable.
  Rooms/chairs/vehicles are created under a branch on the `/admin/branches` screen via an app-layer
  `ResourceController`/`ResourceService` (app layer because the deactivation guard queries Scheduling's `Appointment`;
  practitioner resources stay staff-profile driven, excluded). **No booking logic changed:** the day-board +
  `AvailableSlotFinder` already filtered `active=true`, so a new active resource is picked up and a deactivated one
  drops out automatically (proven end-to-end). **Scheduling safety (tested):** resource deactivation is soft
  (`active=false`) and BLOCKED while future appointments exist — the branch guard mirrored. All admin.manage-gated,
  tenant+branch scoped, audited. Remaining follow-up: a CRUD'd resource is day-board-selectable but only offered as
  slots once its `ResourceAvailability` windows are set (existing per-resource mechanism, unchanged) — a
  resource-availability admin screen is the natural next step. See D-096.

- **CLINIC.W9 built the two MOST SAFETY-SENSITIVE admin screens — Governance dashboard + AI approval-queue** — as
  READ/ACT WINDOWS onto tested backends, no new autonomy/audit-mutation/fence-bypass (D-097). App-layer controllers
  (`GovernanceDashboardController` + `AiApprovalQueueController`) because they compose Audit + Platform + Billing +
  AiCore. **PART A — Governance (`audit.view`, STRICTLY READ-ONLY):** displays posture from EXISTING data — a live
  `AuditService::verifyChain()` replay (writes nothing) + latest scheduled `IntegrityCheck` (D-069); latest
  `ReconciliationRun` (the D-068 launch-blocker monitor) + the persisted alarm; AI-usage outcome counts + integer-minor
  cost over the append-only `ai_interactions` ledger; pending-`AgentAction` depth; kill-switch state; recent +
  security-relevant audit events. NO mutation path (all sources append-only at model+trigger; controller only reads);
  the one POST ("verify now") re-runs the existing verification and appends nothing (tested). **`AuditEvent` has no
  `BelongsToTenant`, so tenant_id is filtered EXPLICITLY — the isolation guarantee (tested).** **PART B — AI approval
  queue (`ai.manage`, READ + ACT-THROUGH-EXISTING-PATH):** lists PENDING agent actions; approve/reject go ONLY through
  `AiCore\ApprovalQueue::approve/reject` (the eval-harness-locked path). NO new execute path, NO create/propose route
  (a human can't inject an un-fenced action), NEVER sets autonomy (the body can't raise it — tested). THE CAP BINDS:
  `ApprovalQueue` re-authorizes the reviewer against the TOOL's own permission (before execute), so a reviewer with
  `ai.manage` but lacking a tool's permission is DENIED (403, left to propagate); only `AiCoreException` is caught.
  Reject executes nothing; approve runs only `tool->execute()`; every decision is audited by the EXISTING app-layer
  glue (`agent_action.*`/`ai_interaction.*`) — the controller adds no audit. Actions resolve by string id (FIX.1) →
  cross-tenant/missing = 404. NEW `Governance/Dashboard.vue` + `ApprovalQueue.vue` (Eucalyptus Glow), two nav entries
  (`audit.view`/`ai.manage`), `governance.*`/`aiQueue.*` i18n. 8 feature tests + route smoke gains both GET routes;
  the P.4 eval harness + audit/immutability suites stay UNCHANGED and green. Closes two of the founder-scope admin
  gaps (governance + AI approval-queue); remaining unwired: KB admin, staff-telehealth join. See D-097.

- **CLINIC.W10 built the LAST two admin screens — KB admin + staff telehealth join** — over existing backends, no
  new agent/telehealth logic (D-098). **PART A — KB admin (`/governance/kb`, `ai.manage`):** CRUD over the tenant's
  `KbArticle` rows (the Front-Desk agent's grounding source) via app-layer `KbArticleController` (app layer because
  KB curation is audited and AiCore may not depend on Audit). Writes go through the existing `KbArticle` model +
  `KbEmbeddingService::syncArticle`; deactivate is a soft `is_active=false` toggle. **The agent's grounding + fence
  are UNCHANGED: `KbRetriever` already filters `is_active=true`, so a deactivated article stops being grounded on
  (tested via the retriever before/after) — the P.4 front-desk evals are untouched.** Audited (`kb.article.*`),
  tenant-scoped (string ids → cross-tenant 404). **PART B — staff telehealth (`/telehealth`, `encounter.manage`):**
  the clinician side of the SAME sessions the portal patient joins (W3). Comms `StaffTelehealthController` lists the
  clinician's OWN created/active sessions and issues the EXISTING staff token via `TelehealthService::joinTokenForStaff`.
  **No new telehealth logic:** media never touches the server, recording stays disabled at the provider (grants pin
  recorder/roomRecord/roomAdmin=false — asserted through the staff path), the token is short-lived + never stored,
  the "not recorded" discipline is displayed; issue is audited (`telehealth.token_issued`) + read-logged. Two nav
  entries (`knowledge` on `ai.manage`; `telehealth` on `encounter.manage`, added to `NAV_PERMISSIONS`); `kb.*` +
  `staffTelehealth.*` i18n. 4 feature tests + route smoke gains both GET routes; `NavAndErrorPageTest` nav map gained
  `encounter.manage` (tracking). See D-098.

- **ADMIN VERTICAL COMPLETE (W8 → W10).** The full admin set is delivered: **W8** settings + roles/access ·
  **W8b** settings backends (profile / branch CRUD / opening hours / timezone) · **W8c** bookable-resource CRUD ·
  **W9** governance dashboard + AI approval-queue · **W10** KB admin + staff telehealth join. Combined with the
  earlier CLINIC delivery (W1 shell/auth/landings · W2 patients · W3 portal · W4 staff boards · W5 clinical ·
  W6–W7 billing + reporting), **the CLINIC and ADMIN verticals are both fully built, wired, and green** — every
  admin screen surfaces a tested backend with no new domain/agent/telehealth logic (P0D.GU). Standing focus returns
  to DISCOVERY (the CH/KVG-vs-EU-generic billing question with Spitex coordinators) — no admin gaps remain that block
  a demo; the only unwired designed screens left are non-clinic/dental (B3, out of scope).

- **DENTAL vertical STARTED — a paying general dentist bought it.** Plan: `docs/DENTAL-DELIVERY-MAP.md`
  (~8 core gates, foundational-first; dental inherits tenancy/patients/scheduling+chairs/billing/documents/
  audit/RBAC/AI/design-system — new surface is only the dental clinical domain). **DENTAL.G1 built the
  FOUNDATION:** `Modules\Dental` + the tooth/odontogram data model + dental RBAC (D-099). Tooth notation =
  **FDI/ISO 3950** (permanent 11–48 + primary 51–85 — family dentist charts children). `tooth_records`
  (BelongsToTenant, **APPEND-ONLY** at model + DB-trigger level) stores one immutable charting row per
  tooth/surface moment; **current odontogram = latest per (tooth, surface), history = every row** (a
  correction is a NEW row + reason, prior states never destroyed — tested at model + raw-DB level).
  **ELECTRIC FENCE (record-not-judge):** NO severity/score/risk/grade/abnormal/flag column anywhere —
  `charted_condition` is a fact the dentist selected, never computed (schema + recursive-output fence test).
  `ToothChartService` (chart = `dental.chart`-gated + audited; reads = `patient.view`-gated + patient-scoped
  read-logged). `dental.chart` granted to org_admin + doctor (the treating clinician = the general dentist).
  **No UI this gate** (chart UI is G2). 6 feature tests + arch boundary; no existing behavior changed; the
  eval/reconciliation/immutability/audit suites stay green. Module memory `memory/modules/Dental.md`.

- **DENTAL.G2 built the ODONTOGRAM CHART UI** — the interactive tooth chart the dentist works in, over the
  G1 data model (D-100). PRESENTATIONAL (P0D.GU): `OdontogramController` (`/dental/chart/{patient}`, string-id
  FIX.1) renders the current chart + history + the domain-owned tooth-universe/surfaces/condition vocabulary
  as props; `resources/js/pages/Dental/Odontogram.vue` lays teeth out anatomically (FDI, permanent/primary
  toggle), shows per-surface charted conditions + a per-tooth history panel, and records through the
  append-only `ToothChartService` (a correction = a new record, prior state preserved — proven via the UI).
  **All logic stays in the G1 service.** show = `patient.view`, store/charting = `dental.chart`.
  **FENCE IN THE UI (render-not-judge):** the payload carries charted FACTS only (no severity/score/grade/
  risk/flag — recursive fence test), and colour is a FACTUAL charted-condition legend (categorical, "colour =
  the condition charted, not its severity"), never a severity heatmap/auto-flag; no number/score rendered.
  4 feature tests + route smoke gains the dental chart route (doctor 200 / billing 403). No existing behavior
  changed; suites (incl. G1 + evals) green. Next: G3 procedure catalog + billing integration.

- **DENTAL.G3 built the PROCEDURE CATALOG + BILLING INTEGRATION** — the dentist's fee schedule wired to the
  EXISTING tested billing engine (D-101). **Mapping:** a dental procedure IS a `TariffItem` in a dedicated
  dental `TariffCatalog`; a thin `dental_procedures` overlay adds only `tooth_scoped`. Charging =
  `ChargeCaptureService::captureManual` (resolves + SNAPSHOTS the fee) → the charge flows into the existing
  invoice → reconciliation → dunning → PDF pipeline UNCHANGED; **a dental charge reconciles-to-the-unit**
  (tested) and **a later fee edit never changes a past charge** (snapshot, tested). **NO new billing logic /
  no money math in dental code** (adversarial grep clean — every `_minor` is a pass-through). **NO licensed
  CDT bundled** — the catalog is tenant-authored; `seedStarter` = a generic editable template (D-EXAM…D-RCT).
  Fee-schedule editor (`/dental/fee-schedule`, `billing.manage`) presentational over `DentalCatalogService`.
  Light tooth link (`dental_procedure_charges`) ties a tooth-scoped charge to the odontogram tooth (full
  perform workflow = G4). 7 feature tests + route smoke gains the fee-schedule route (billing 200 / reception
  403). No existing behavior changed; reconciliation/immutability/fence/eval suites green. Next: G4 procedures.

- **DENTAL.G4 built the PERFORM-A-PROCEDURE workflow** — one atomic action tying G1+G2+G3 together (D-102).
  `PerformProcedureService::perform` writes THREE things in ONE `DB::transaction`: (1) captures the charge via
  the EXISTING G3 `DentalChargeService` (no new billing math — grep clean); (2) records an APPEND-ONLY
  `performed_procedures` clinical row (tied to the charge); (3) charts the resulting tooth-state via the
  EXISTING G1 `ToothChartService` (append-only). **CONSISTENCY: a failure in any step rolls back ALL THREE —
  no orphan** (tested: an invalid tooth-state throws at step 3 → zero charges/performed/tooth-records). The
  tooth-state result is a perform-time input the DENTIST states (extraction→missing, filling→restoration),
  charted verbatim — factual consequence, not judgment (fence). **RBAC needs BOTH** dental.chart (clinical)
  AND billing.manage (charge) — the dentist-owner holds both via org_admin; a doctor-only is denied at the
  charge and rolls back. The charge reconciles-to-the-unit (tested). Odontogram (G2) extended additively with
  a "Perform a procedure" side-panel form + performed history (`can_perform` = both perms); POST
  `/dental/chart/{patient}/perform`. 5 feature tests + route smoke gains the perform route (reception 403).
  No G3 code touched; no existing behavior changed; reconciliation/immutability/fence/eval + G1–G3 suites
  green. Next: G5 treatment plan.

- **DENTAL.G5 built the PHASED, FEE-SCHEDULED TREATMENT PLAN — completing the CORE dental spine (D-103).**
  `treatment_plans` (lifecycle draft→proposed→accepted/declined→in_progress→completed; BelongsToTenant,
  LogsReads) group `treatment_plan_phases` holding `treatment_plan_items` (a planned procedure = a G3
  `dental_procedure` + tooth/surface + `estimated_fee_minor`). **ESTIMATE reuses G3 pricing, SNAPSHOTTED at
  proposal:** each item's estimate is the tariff fee READ through the existing store and frozen into
  `estimated_fee_minor` when the plan is proposed — a later fee-schedule edit never changes an accepted
  plan's agreed estimate (tested). Totals are `->sum(itemEstimate)` — the ONLY arithmetic; NO VAT/discount
  math (adversarial grep clean). **NO DOUBLE-CHARGE (tested): the plan ESTIMATES; accepting/starting posts
  NO charge** — a charge is created only when the procedure is PERFORMED (G4). **Link to G4:**
  `performed_procedures` gains a nullable `treatment_plan_item_id`; `PerformProcedureService::perform` gains
  an optional `?TreatmentPlanItem` (default null — G4 unchanged) so a planned item is marked "done" (derived,
  no stored flag) when performed. **Lifecycle legal-only** (state machine like ServiceAgreementService;
  illegal transitions throw; completed/declined terminal), audited, tenant+patient scoped, read-logged.
  **FENCE: the DENTIST authors the plan** — no auto-suggested procedures, no severity prioritisation, no AI;
  the service records what the dentist adds and sums fees (payload carries no severity/suggested/ai field —
  tested). **RBAC:** managing = dental.chart; reading = patient.view; performing = dental.chart +
  billing.manage (via G4). UI: `Dental/TreatmentPlans.vue` (`/dental/plans/{patient}`) staff editor
  (build/lifecycle/perform-a-planned-item) + `Portal/TreatmentPlan.vue` (`/portal/treatment-plan`) read-only
  patient view. 5 feature tests + route smoke gains the staff plan route (doctor 200 / billing 403) + portal
  plan route. No existing behavior changed; reconciliation/immutability/fence/eval + G1–G4 suites green.
  **CORE DENTAL SPINE (G1→G5) COMPLETE:** a general dentist can chart the mouth → record + bill procedures →
  build, present, and track a phased fee-scheduled plan. Remaining: G6 perio · G7 diagnosis record · G8
  imaging (+ later: sterilization/inventory, ortho/scan-compare, live imaging capture, licensed code sets).

- **DENTAL.G6 built PERIO CHARTING — per-tooth, per-site periodontal measurements as RAW recorded facts,
  record-not-judge (D-104).** `perio_exams` (BelongsToTenant, LogsReads, **APPEND-ONLY** model + DB-trigger —
  a re-exam is a NEW exam; history preserved) is a point-in-time 6-point probing; it groups
  `perio_measurements` (BelongsToTenant, APPEND-ONLY) — one row per tooth × SITE. **Six sites/tooth**
  (`PerioMeasurement::SITES`: mesio_buccal/buccal/disto_buccal/mesio_lingual/lingual/disto_lingual — the
  standard 6-point probing, distinct from the odontogram's 5 anatomical surfaces). Per site the RAW values:
  `pocket_depth_mm`, `recession_mm` (signed), `bleeding_on_probing` (bool), + optional per-tooth `mobility`
  (0–3) and `furcation` (0–4). Tooth = FDI (reuses G1 `ToothNotation`). **CRITICAL FENCE (perio's core
  risk): raw numbers ONLY — NO periodontal stage (I–IV), NO grade (A–C), NO severity, NO risk score, NO
  auto-flag of a deepening site, NO computed attachment-loss finding, in schema/service/UI.** `assertValid`
  is pure data-entry validation (valid FDI/site, physically-plausible number), never a grade; the per-site
  trend over time (`siteHistory`) is raw numbers in sequence, NO band/arrow/"worsening" label (same rule as
  the vitals trends). Proven by a recursive payload fence assertion over the page props AND the siteHistory
  output. `PerioChartService`: `recordExam` (dental.chart, DB::transaction, audited `dental.perio_charted`);
  `examsFor`/`siteHistory` (patient.view, patient-scoped read-log). UI: `Dental/PerioChart.vue`
  (`/dental/perio/{patient}`, string-id FIX.1) — the classic perio grid (teeth × 6 sites; prior exams as
  raw grids), NO severity colouring/flags/stage badge (a dot marks BOP = data entry, not severity). 7
  feature tests + route smoke gains the perio route (doctor 200 / billing 403). No existing behavior changed;
  reconciliation/immutability/fence/eval + G1–G5 suites green. Next: G7 diagnosis record · G8 imaging.

- **DENTAL.G7 built the DENTIST-AUTHORED DIAGNOSIS RECORD — the SHARPEST fence in the vertical (D-105).**
  `diagnoses` (BelongsToTenant, LogsReads, **APPEND-ONLY** model + DB-trigger — a change/correction is a NEW
  record + `reason`, history preserved) stores what the DENTIST decided: `label` (text they wrote OR picked),
  optional `tooth`/`surface` (FDI, reuses G1), `findings`, and `status` ∈ {provisional, confirmed, ruled_out}
  the DENTIST sets; `diagnosis_term_id` is provenance only (null = free text). A `diagnosis_terms`
  (BelongsToTenant, plain catalog) is the tenant's OWN pick-list — TENANT-AUTHORED like the procedure
  catalog, **NO licensed diagnostic code set bundled**. **CRITICAL FENCE (do not compromise): NO AI, NO
  suggested/proposed diagnosis, NO auto-ranked differential, NO computed likelihood, and NOTHING
  auto-populates a diagnosis** — the system only records what the dentist entered; `status` is the dentist's
  determination, recorded not decided. The schema/service/UI carry no suggested/proposed/differential/
  likelihood/confidence/ranked/ai/recommended field. **Proven by the strictest fence test yet**: a recursive
  no-suggestion assertion over the payload PLUS a no-auto-populate proof (charting caries + 9mm perio pockets
  yields ZERO diagnoses). `DiagnosisService`: `record` (dental.chart, tenant+patient fail-closed, audited);
  `diagnosesFor` (patient.view, read-log); `terms`/`addTerm` (the tenant's plain pick-list). UI:
  `Dental/Diagnoses.vue` (`/dental/diagnoses/{patient}`, string-id FIX.1) — dentist writes/picks + sets the
  status + manages their own term list; NO suggestion/differential/AI panel. 6 feature tests + route smoke
  gains the diagnosis route (doctor 200 / billing 403). No existing behavior changed;
  reconciliation/immutability/fence/eval + G1–G6 suites green. Next: G8 imaging/scans.

- **Current phase:** Phase G COMPLETE - Comms, telehealth & patient portal. Consolidated at P0G.C:
  the functional staff-facing surface is FROZEN for the design pass, and `docs/SCREENS.md` is the
  factual re-skin brief (22 Inertia pages + 11 nurse-PWA screens with routes/guards/props/actions).
  Next: CLAUDE DESIGN PASS across all screens, then Phase H per the master plan.
- **Commits:** 70 on `main` after P0G.C.
  Phase A = 11 (P0A.G1-G8, P0A.GM, P0A.GF, P0A.GF3), pushed to `origin/main`
  (https://github.com/Subhankhan12/careos).
- **Verified quality (from actual output):** `composer check` green - Pint `passed`,
  PHPStan level 5 `[OK] No errors`, Pest **418 passed / 2752 assertions** (P0P.G1); npm build green.
  CI-failure root cause (P0G.G2/G3 runs): ci.yml exports QUEUE_CONNECTION=redis at the job level
  and phpunit's <env> does NOT override OS env vars, so the G.2 queue-idempotency test parked its
  job on real Redis in CI and the delivery row never appeared. Fixed in P0G.G4 by pinning
  queue.default=sync inside that one test (queue infra itself is proven by C.0's Redis round-trip). `npm run build` green,
  `npm run test:pwa` green (**15 passed**), `npm run build:pwa` green. CI (MySQL 8 + Redis 7)
  check-run `success` for the latest pushed commit at consolidation time (P0F.G8 `e483d8e`); the
  P0F.C run is checked after push. Redis live (`PONG`); dev DB `careos` on MariaDB 10.4.32 at 3306. `composer.json` sets
  `config.process-timeout: 0` because the full suite (~390s) exceeds Composer's default 300s
  process-timeout that `composer check` runs under (CI runs `composer check`). Latest frontend/PWA
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
- **Proven in Phase F (COMPLETE):**
  - THE SIMULATED MONTH (exit criterion, CI-runnable): `SimulatedBillingMonthSeeder` + the test
    `simulated month: full billing cycle reconciles to the unit` generate June 2026 through the real
    F.1-F.6 services - 3 patients, 41 charges (26 encounter / 14 visit / 1 manual dunning fee),
    three VAT rates (0/810/1900 bp), a tariff-version boundary at 2026-06-15|16 (CONS 5000 -> 5500
    across it), a real REQUIRED_CODE_MISSING violation corrected before invoicing, six consecutive
    gapless invoices INV-1..6 (one multi-rate), full/partial/over payments plus an allocation
    reversal, a partial credit note CN-1 against INV-5, and a level-1 dunning fee - then prove all
    six reconciliation invariants ok with delta_minor === 0 exactly, and the export equal to the
    reconciled totals to the unit.
  - The LAUNCH BLOCKER reconciliation rule is now verbatim in AGENTS.md Hard rules.
  - ReconciliationEngine top-level delta_minor is a pure drift measure for every invariant: I3
    counts the legitimate non-negative payment remainder on the accounted side and I5 counts only
    credit BEYOND the original, so a clean month reports delta 0 exactly for all six (P0F.C
    refinement; ok/rows/violation behavior unchanged).
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
  - Dunning is staged, deterministic, and settings-driven (`billing.dunning`): `DunningService::
    evaluate(tenant, asOf, actor)` creates the append-only `dunning_events` that should exist at an
    as-of date and is idempotent on re-run; day-past-due thresholds are exact (+13 no, +14 yes).
  - Dunning targets `series=INV` invoices with `open_balance_minor > 0`, a `due_date`, and
    `dunning_paused = false`; levels fire ascending, once each (`unique(tenant, invoice, level)`). Paid
    and fully credit-noted (open 0) invoices never dun. Pause lives on `invoice_balances`, never the
    frozen invoice row.
  - An optional per-level dunning fee is a NEW draft charge via `ChargeCaptureService`; the original
    invoice is never mutated. `dunning_events` are append-only at model + DB-trigger level.
  - Dunning delivery reuses the notification-channel abstraction but is a legal communication, NOT
    gated on comms consent (D-F7); delivery is audited. `billing:dunning-run` wraps evaluate
    (scheduling deferred).
  - The reconciliation engine (`ReconciliationEngine::check/run`) checks six invariants (I1-I6) in
    exact integer arithmetic (VAT per D-F3); any single-minor-unit drift fails the run and reports the
    exact offending rows. I2 catches a drifted `invoice_balances` projection vs the derived open
    balance; I4 proves each invoiced charge is on exactly one non-CN invoice (no double/lost).
  - `reconciliation_runs` is the tenant-owned append-only monthly-close artifact (period, passed,
    report JSON); model + DB triggers block UPDATE/DELETE.
  - The accounting CSV export (`AccountingExportService::export`, `billing:export`) REFUSES to run
    unless the period's most recent reconciliation passed; export invoice-row totals equal the I4
    reconciled total to the unit. Generic ledger format; DATEV columns arrive with the DE pack.
  - Billing agent runs under C.7 AiCore governance: `billing.suggest_charge_codes` and
    `billing.preflight_invoice` are FINANCIAL-category tools requiring `billing.manage`, hard-capped
    at `approve` (requested `auto` degrades). Suggestions must be source-linked to a signed encounter
    note or completed-visit note text and resolve via `TariffResolver` at the service date; unsourced
    suggestions are rejected in code before the approval queue.
  - Agent prices are never trusted: approval captures through `ChargeCaptureService`, which
    re-resolves the tariff; preflight reports the deterministic F.3 `ChargeValidator` violations
    verbatim (fuzz-proven: 25 random charge sets, 0 disagreements) and never issues invoices.
  - The Billing agent refuses clinically framed questions (appropriateness, alternatives, patient
    condition) with human handoff, `refused` ledger rows, and no agent action; reads are
    patient-scoped read-logged; budget gate and kill switch degrade to manual.
- **Proven in Phase G so far:**
  - Comms module registered (autoload, provider, architecture boundary tests: Comms may use care
    modules but never Audit models or AiCore; no module may use Comms).
  - Secure threads: patient threads always carry and include their patient; internal threads can
    never reference or contain a patient (model guards + DB XOR check on participants).
  - Messages are append-only at model and DB-trigger level; corrections are new messages — what was
    communicated is evidence and is never rewritten.
  - Staff thread actions require `comms.manage` (org_admin + reception starter roles). Patient access
    is fail-closed on three checks: own thread + active participant + active portal account with
    `portal.access` consent.
  - Reading a patient thread writes a patient-scoped `read` audit row; posting/closing/participant
    changes are audited and the chain verifies.
  - One notification engine: versioned tenant templates (+ built-in platform defaults), category
    derived from the TEMPLATE (caller relabel rejected), consent matrix fail-closed
    (marketing/transactional-to-patient gated on `comms.email`; legal and staff exempt), append-only
    snapshot deliveries, sha256 dedupe with unique-index backstop, Horizon queue path.
  - Phase C appointment reminders and F.6 dunning now deliver through the engine via app-layer
    channel bridges (D-017); their suites pass unchanged (reminders skip without consent; dunning
    does not).
  - Unified inbox: derived (never stored) unread counts from `thread_reads` markers vs the
    append-only message stream; filters (type/status/mine); light assignment via
    `threads.assigned_to`; opening a patient thread read-logs; all rules server-side (P0D.GU).
  - Telehealth (D-G1/G2/G3): embedded provider behind a swappable adapter; metadata only (no media/
    recording columns — schema-asserted); recording disabled at the provider (adapters refuse rooms
    without the option; grants pin roomRecord/roomAdmin/recorder=false); tokens <= 600s, one room/
    identity/role, never stored/logged; patient tokens fail-closed on portal account + portal.access
    consent + being the session's patient; invitations transactional via the engine (D-064);
    join/leave rows append-only; keys proven absent from logs.
  - MariaDB-only integrity fix: UPDATE-able moment columns in Comms use DATETIME because MariaDB
    10.4 gives the first TIMESTAMP column implicit ON UPDATE CURRENT_TIMESTAMP (MySQL 8 unaffected).
  - The patient portal is complete: 8 Inertia pages on a dedicated PortalLayout (never the staff
    shell), all behind portal-tenant + portal-auth + portal-consent — withdrawing portal.access
    locks the portal on the very next request (tested). Self-booking runs through
    BookingService::bookOnline (the locked no-double-book path; identity only from the session's
    portal account); cancellation enforces scheduling.portal.cancel_min_hours (default 24)
    server-side via AppointmentService::cancelForPatient (ownership fail-closed, patient actor).
    Documents remain shared-only/controller-streamed/read-logged; invoices own-only read-only with
    invoice_balances and read-logged private PDF streaming; NO payment processing (PSP deferred);
    telehealth join tokens issued on demand through the three-way gate; staff/patient shell
    separation re-asserted.
  - The Inbox agent is DRAFT-ONLY (D-065/D-G5): suggest ceilings on both tools (auto degrades);
    clinical patient questions are refused before any tool runs — zero draft content, handoff note,
    thread flagged for clinician attention, refusal ledgered. Drafts ground in exactly three sources
    (thread history, active KB, live-recomputed patient admin facts) with in-code rejection of
    unsourced claims; an explicit human send posts through ThreadService with ai_assisted=true;
    document classification files only the category via DocumentService::reclassify and the patient
    match is never auto-applied.
  - P0G.C consolidation: `docs/SCREENS.md` complete (every page: route, guards, prop shapes,
    dispatched actions + what the server enforces — designed for controller-free re-skinning).
    P0D.GU review across all 26 Vue files: ONE genuine violation found and fixed in the P0G.C
    commit (the staff consent-withdraw route now requires status=granted, mirroring the portal
    path, so an already-withdrawn/expired consent can never be re-withdrawn); everything else is
    acceptable display logic with independent server enforcement.
- **Demo tenant (P0P.G1):** `DemoClinicSeeder` seeds ONE richly-populated demo tenant for design,
  sales, and design partners — **Praxis Lindenhof** (slug `praxis-lindenhof`, branch "Zürich
  Oberstrass", EUR, plan `eu_pro`). Run it with
  `php artisan db:seed --class=DemoClinicSeeder`.
  - Idempotent by tenant slug: if the tenant exists the seeder returns immediately, so a second run
    adds nothing anywhere in the schema (asserted table-by-table).
  - Tenant creation goes through the real provisioning path (`Tenant::created` →
    `RbacProvisioner::provisionTenant`, Phase A system mode, so provisioning stays out of the audit
    chain); everything after runs as normal tenant-scoped actors, giving a 308-row audit chain that
    verifies.
  - Billing sits in the PREVIOUS full calendar month (`DemoClinicSeeder::period()`) and reconciles
    with all six invariants at `delta_minor === 0`; scheduling/dispatch/live clinical sit in the
    CURRENT week. The seeder never moves `now()` backwards — `verifyChain` replays ordered by
    `occurred_at`, so back-dating mid-run would break the hash chain.
  - Below-waterline and additive only: 5 factories + 1 seeder + 1 test file. No existing page props,
    routes, or Inertia payloads were touched, so the design pass is unaffected.
  - Demo logins `<first>.<last>@praxis-lindenhof.test` / `demo-password` (MFA pre-enrolled).
- **Automation layer (P0P.G2):** the deferred commands now run unattended. Schedule lives in
  `routes/console.php`; every event is `withoutOverlapping()` + `onOneServer()` and iterates
  `status = 'active'` tenants only:
  `credentials:refresh-status` 02:10 · `nursing:materialize-visits` 02:20 (rolling 8-week horizon) ·
  `clinical:evaluate-recalls` 02:30 · `billing:dunning-run` 06:00 · `billing:reconcile` 06:30 ·
  `appointments:dispatch-reminders` every 15 min.
  - **PROD RUNNER (nothing fires without it):** cron
    `* * * * * cd /srv/careos && php artisan schedule:run >> /dev/null 2>&1`, and **Horizon under
    supervisor** or the queued reminders/notifications never drain (the sweep only ENQUEUES).
    Local Windows cannot keep Horizon alive (no `pcntl`) — use `php artisan schedule:work` +
    `php artisan queue:work redis --queue=reminders,notifications`. Nothing was installed.
  - `billing:reconcile` daily is the **launch-blocker monitor** (D-068): a failure leaves the
    append-only `reconciliation_runs` row + an error-level log + the `billing.reconciliation.alarm`
    tenant setting. No UI built for it (below-waterline); the alarm clears only when the SAME period
    later passes.
  - Unattended runs act as a resolved tenant actor (D-067): `SystemActorResolver` picks the
    lowest-id user holding the permission tenant-wide; never a super-admin, never a branch-scoped
    holder, and a tenant with nobody qualified is SKIPPED, not escalated. Recall evaluation passes a
    null actor deliberately — attributing a cron job to a clinician would be a false clinical audit
    entry.
  - Fixed in passing: `credentials:refresh-status` was iterating ALL tenants including suspended
    ones. `billing:dunning-run` / `billing:reconcile` required `tenantId`+`actorId` and so could not
    run unattended at all; both args are now optional (absent = sweep active tenants), and the
    explicit human-invoked form is unchanged.
- **Security & integrity (P0P.G3):** `tests/Feature/Security/` is the adversarial suite — 30 tests.
  **No isolation or immutability holes were found; nothing needed fixing.**
  - Cross-tenant: the attacker is a *separate tenant's org_admin* (max tenant privilege, every
    permission); the victim is the P.1 demo tenant with real service-created data. 23 crafted
    attempts across Patients/Clinical/Scheduling/Nursing/Comms/AiCore all fail closed (403/404),
    with no victim string in any body. Portal patient cannot reach staff routes or another
    patient's document; staff cannot reach portal routes.
  - RBAC negative: the starter-role catalogue withholds each sensitive permission, and crafted
    direct calls are refused server-side (note.sign, allergy.override, patient.merge,
    billing.manage, agreement.manage, comms.manage, dispatch.manage).
  - Immutability: raw `DB::update`/`DB::delete` (straight past Eloquent) is rejected by the DB
    trigger on **all 15** protected tables. The conditional triggers are also tested on their
    POSITIVE side — a draft invoice/note/timesheet line must still be editable, or the guard would
    have broken the product.
  - `audit:verify-chains` (daily 01:30) replays every active tenant's chain and appends an
    append-only `integrity_checks` row pass or fail (D-069); a break logs at ERROR and exits
    non-zero. Proven both ways: clean on the demo tenant, and detects a deliberately tampered chain.
    The command lives in `app/` because Audit may not depend on Platform; `IntegrityCheck` lives in
    Platform because it is tenant-owned.
- **Agent eval harness (P0P.G4):** `tests/Evals/` is a first-class named suite that LOCKS every
  agent's safety properties as regression tests — the electric fence, autonomy caps, grounding, and
  the "never trust the agent's numbers" rules — so a future change that weakens any of them fails
  loudly. `Evals` phpunit testsuite; run focused with `composer eval` (= `pest --testsuite=Evals`),
  and it also runs inside `composer check`'s full Pest run. **37 evals / 398 assertions**, all green;
  deterministic, LLM mocked with fixed inputs, `evNoNetwork()` guarantees no real API call, asserts
  BEHAVIOR not model quality. One file per agent + `CrossCuttingAgentEvalTest` + shared
  `Support/EvalHarness.php`. Front-Desk 6 · Clinical Summary 4 · Follow-up 3 · Dispatch 6 · Billing 7
  · Inbox 7 · Cross-cutting 5. The gate LOCKED existing behavior and changed NO agent behavior; no
  production code was touched (only `tests/`, `phpunit.xml` testsuite, `tests/Pest.php` binding, the
  `eval` composer script, and `docs/AGENT-EVALS.md`). PHPStan scans app/Modules/tests/Support only,
  so eval files are outside static analysis; Pint clean. `docs/AGENT-EVALS.md` maps every locked
  property to its enforcing eval. Recorded as D-071.
- **CSV patient import (P0P.G6):** new `Modules\Import` — onboarding/migration tooling that lets a
  clinic move patients off their old system from an arbitrary CSV. Column mapping → **mandatory
  dry-run** (`ImportValidator`, writes nothing: validates, parses dates via an explicit chosen
  format, runs the existing `DuplicateDetector`) → audited **commit** (`ImportCommitter`, only through
  the REAL `PatientService`/`PatientMergeService`, never raw inserts, so MRN/tenancy/validation/audit
  all apply). Idempotent (batch + row status guards); one `patient.import.committed` audit event.
  Tables `import_batches`/`import_rows` (tenant-owned, fail-closed). Duplicate policy default SKIP
  (import_as_new / merge opt-in; merge uses the audited merge path). Uploads on the private disk,
  tenant-prefixed, no public URL; CSV parsed with `league/csv` (never hand-rolled). New permission
  `data.import` (org_admin only) on every controller action. Net-new admin pages `Import/Index.vue` +
  `Import/Upload.vue` on the current shell/tokens — NOT part of the design pass, presentational only,
  no existing page contract changed. `league/csv ^9.28` added. 9 feature tests + 1 arch test; module
  memory in `memory/modules/Import.md`; recorded as D-072.
- **Waitlist auto-fill (P0P.G9):** the C.4 waitlist engine is now a reception loop. When a slot frees,
  `WaitlistOfferService::candidates` surfaces matching entries (reusing `WaitlistService::matchingForSlot`);
  `offer()` creates a time-boxed `waitlist_offers` hold (TTL `scheduling.waitlist.offer_ttl_minutes`,
  default 30 min) and notifies the patient; `accept()` books through the EXISTING `BookingService`
  (no-double-book) and marks the entry booked; decline/expire release the hold for the next candidate.
  Two concurrent accepts of one freed slot resolve to exactly one booking (hammer-proven). The offer
  notification is TRANSACTIONAL + consent-gated (`comms.email`) and composed in the APP LAYER (listener →
  Comms `NotificationService`) since Scheduling may not depend on Comms (D-073). `scheduling:expire-waitlist-offers`
  (every 5 min) sweeps timed-out offers. Reception UI is a net-new, additive day-board panel
  (presentational per P0D.GU); no existing page contract changed. 9 tests (incl. the concurrent hammer).
- **Self check-in (P0P.G7):** new `Modules\FrontDesk` — patients confirm arrival + self-update ONLY their
  own contact fields via a shared reception **kiosk** (no login, identity-verified) or the authenticated
  **portal**. One `CheckInService`, two entry paths. Check-in is stored on the appointment
  (`checked_in_at`/`check_in_source`/`check_in_code`; code generated at booking); arrival always goes
  through the existing `AppointmentService` (patient-actor `arriveForPatient`), contact edits through the
  existing `PatientService` — both idempotent and patient-scoped audited. Kiosk safety is absolute: exact
  name+DOB+code match to one today/this-branch appointment, generic no-PHI not-found on failure, NO clinical
  data or patient browsing, an ephemeral in-memory page (no localStorage, idle auto-reset), a branch-scoped
  revocable device token that (via a short-lived `Crypt` verification handle) can never act on an arbitrary
  patient, and rate-limited code entry. Portal path is auth+consent-gated and own-appointment-only. Recorded
  as D-074; module memory in `memory/modules/FrontDesk.md`. 11 feature tests + arch test. NOTE: gates
  **P0P.G7 fills the earlier skipped slot**; the prior `PROJECT-STATE` P0P sequence was G1→G6→G9.
- **Recurring / series appointments (P0P.G8):** reception books a repeating clinic appointment ("every
  Tuesday 09:00 for 6 weeks") in one action. New `appointment_series` (Scheduling); occurrences are ordinary
  appointments (`series_id` + `occurrence_date`) booked through the EXISTING no-double-book
  `BookingService::book`. The RRULE is expanded DST-safe (recurr + series timezone, `start_time` re-anchored
  per occurrence — proven across Europe/Zurich spring-forward). Conflict policy: free occurrences book, taken
  ones are RETURNED as a failure report `{date, reason}` — never silently skipped; a read-only
  `BookingService::checkAvailability` powers the pre-confirm free/conflict preview. Per-occurrence exceptions
  reuse the existing lifecycle (cancel/reschedule one); `end()` stops future generation without touching
  booked ones. Net-new day-board "make recurring" panel (presentational). Recorded as D-075; 8 feature tests.
  With this, the whole P0P sequence G1–G9 is complete (G7+G8 filled the earlier gaps).
- **Structured clinical orders (P0P.G11):** a clinician places a structured lab/imaging order
  (`Modules\Clinical`), tracks a status lifecycle (ordered→collected→in_progress→resulted→reviewed /
  cancelled), records a MANUAL result, and marks it reviewed. Electric fence absolute: results are stored
  and shown RAW — no range/flag/abnormal/colour/score anywhere (same as vitals D-D3) — and "reviewed" is a
  human attestation, never a system judgment. `order_results` is append-only (DB triggers). The orderable
  list is TENANT-AUTHORED (no licensed catalog; seedable generic template). Electronic transmission +
  automated ingestion (HL7/FHIR) are a STUB `LabConnectivity` interface with only a `ManualLabConnectivity`
  no-op — no real client built; real lab connectivity is DEFERRED partner work (trigger recorded). RBAC
  `order.manage` (org_admin/doctor/nurse). Net-new additive chart Orders tab + review worklist + catalog
  admin. Recorded as D-076; 7 feature tests. (The P0P sequence is now G1–G11 complete.)
- **Clinical dot-phrases / quick-text macros (P0P.G10):** reusable text snippets a clinician expands while
  writing SOAP notes — PERSONAL (private) or SHARED (tenant-wide, `snippet.manage.shared` = org_admin +
  doctor). `SnippetService`: `resolveFor` (PERSONAL wins over SHARED), `list` (own personal + active shared,
  never another clinician's personal), `expand` — substitutes ONLY a FIXED non-clinical placeholder
  whitelist (date, patient_first_name, patient_dob, clinician_name, branch_name), iterating the whitelist
  keys not the caller's context, so a diagnosis/medication/allergy/vital/any clinical field is STRUCTURALLY
  impossible to substitute; unknown tokens stay literal. No interpretation, no AI. Snippets are NOT patient
  data (shared changes audited; personal lightly logged). Editor integration is ADDITIVE — a new OPTIONAL
  `snippets` prop on NoteEditor (pre-expanded server-side) + insert control; no existing prop/behavior
  changed. Net-new `Clinical/Snippets.vue` management page. Recorded as D-077; 6 feature tests. This closes
  the last previously-skipped gate — **the P0P sequence G1–G11 is now complete**.
- **Parked backlog (P0P.G5, docs only):** DEFERRED.md now carries a "Parked — build when a real
  user/customer creates the need" section: 10 demand-driven items, each with a concrete TRIGGER that
  pulls it forward (Phase H agents, AI-credits metering/billing, real nurse-travel routing, DE/CH/FR
  statutory packs, cross-tenant referral share objects, telehealth recording+transcripts, Reverb
  realtime for inbox/day-board/telehealth presence, i18n content, portal PSP payment, Playwright
  offline test). Items that already existed as plain phase-parked bullets were MOVED into the section
  with triggers, not duplicated; the hard medical-device/countersigning deferrals stay put. Principle
  recorded as D-070 (build on need, never speculatively). No code/props/routes/payloads touched.
- **Nurse skills / competency matching (P0P.G12):** dispatch now matches finer-grained COMPETENCIES
  (below the RN/LPN qualification), each configurable HARD (blocks) or SOFT (warns) — the AGENCY sets
  enforcement per competency; the system never decides which are safety-critical (electric-fence posture).
  Extends `Modules\Nursing` (D-078). Two tenant-owned tables: `competencies` (tenant-authored code/name/
  enforcement/active; NO licensed set; `CompetencyService::seedStarter()` = editable generic template) +
  `nurse_competencies` (grant to a practitioner resource with optional expiry; HELD = active AND not
  expired, mirrors credential-vault). Visit requirements reuse the existing path: `required_competencies`
  JSON codes on `agreement_services` → copied onto each `planned_visit` by `VisitPlanGenerator`.
  `AssignmentValidator::evaluate()` returns a new `AssignmentValidation` (blocking vs warnings);
  legacy `validate()` = blocking only (existing reason codes + dispatch-agent contract intact). Per
  unheld required competency: HARD → blocking `competency_missing_hard:<code>` (refused like a
  qualification miss); SOFT → non-blocking `competency_missing_soft:<code>` (allowed + dispatcher warned);
  unconfigured/inactive code → advisory-only. Composes with qualification/window/travel/hour-cap; the
  concurrency-safe locked path (FOR UPDATE, parallel-hammer) is UNCHANGED. Soft warnings surface on the
  board (transient `PlannedVisit::$assignmentWarnings` → Inertia flash) and the override is recorded in
  the `planned_visit.assigned` audit context. New RBAC `competency.manage` (org_admin + coordinator);
  definition/enforcement + grant/revoke audited (patient_id null). Net-new additive `Competencies.vue`
  admin page + dispatch soft-warning banner; no existing dispatch page contract changed. 10 feature
  tests; existing assignment/parallel-hammer/dispatch-agent suites still green. composer check green;
  npm run build green.
- **Unified vitals trends (P0P.G13):** a patient's vitals are now a per-metric time series (not a text
  list), UNIFYING the two raw stores — Clinical `vitals` (staff/encounter) + Nursing `visit_vitals`
  (PWA) — so no reading is invisible. NO storage schema (data already per-reading with timestamps).
  `Clinical\Support\VitalsSeries` (pure) merges a reading list into per-metric, most-recent-first,
  source-tagged (`clinic`|`visit`) arrays; a null/absent metric is absent from that series, never
  zero-filled. `VitalsHistoryService` (Clinical) combines the `Vital` model with Nursing visit vitals
  read through the `VisitVitalsReader` CONTRACT — impl `App\Clinical\NursingVisitVitalsReader` lives in
  the app layer because the boundary forbids Clinical→Nursing (arch test still green). ELECTRIC FENCE:
  output is `{recorded_at,value,source}` only — no bands/ranges/flags/normal-abnormal/scores/arrows/
  deltas (asserted PHP + PWA). Chart gains an additive `vitalsHistory` companion prop (flat `vitals`
  prop untouched) rendered as neutral per-metric tables; the nurse PWA day-pack gains a small recent
  `vitals_history` (5/metric) shown above the capture form, riding the D-E2 encrypted store with the
  per-patient read-audit extended (`includes_vitals_history=true`). Tests: 5 Clinical (two-store merge,
  missing-metric-absent, raw-only, tenant isolation + patient scoping, chart prop + read-log) + 1
  day-pack + 3 PWA display + encrypted round-trip; composer check + build + build:pwa + test:pwa green.
- **Reporting/metrics service layer (P0P.G14):** NEW `Modules\Reporting` — a tenant-scoped, READ-ONLY
  aggregation layer (owns no tables, never writes) exposing the UNIVERSAL metric set so dashboards can be
  wired quickly AFTER discovery says which metrics matter. NO UI (service + `reporting:summary` command
  only; nothing frontend touched). `MetricsService`: operational (appointments total+per-status, no-shows
  {count, scheduled, rate}, checked-in count, nursing visits completed, active patients) · financial
  (integer minor units, F.7 definitions verbatim: invoiced total = I4, payments by received_on,
  outstanding = I2 projection sum, aging buckets current/1-30/31-60/61-90/90+ by days past due) ·
  throughput (encounters, signed notes, orders placed — counts only). Facts, not judgments: results are
  numbers only (recursive shape test: no judgment keys, every leaf int|float); electric fence excludes
  clinically-interpretive aggregates. Aggregates are not patient records → no patient read-audit (tested:
  zero audit rows from a full summary). RBAC: NEW `reporting.view` (org_admin + coordinator) for
  operational/throughput; existing `billing.view` for financial; summary omits the financial section
  without it. Proven against DemoClinicSeeder's reconciled month: invoicedTotal === I4 expected,
  outstanding === I2 projection, payments === ledger sum. 10 seeded exact-number tests + arch boundary
  (Reporting never uses Audit models/AiCore/Comms/Import/FrontDesk). Recorded as D-080; module memory in
  `memory/modules/Reporting.md`.
- **MySQL 8 full parity (P0P.G15):** deploy-readiness proven, and TWO real problems found + fixed.
  (1) **CI had been red since P0P.G7** (8 commits, unnoticed — gates ran local checks only). Cause was
  NOT MySQL 8: CI's job-level `CACHE_STORE=redis` beats phpunit `<env>` (P0G.G2 class), so kiosk throttle
  counters persisted across tests in real Redis → 429s only in CI. Reproduced locally on MariaDB with
  `CACHE_STORE=redis`; fixed by flushing the cache store per test in CheckInTest (config pin insufficient:
  Fortify resolves the RateLimiter singleton at boot). (2) **Real engine divergence:** MariaDB's implicit
  `ON UPDATE CURRENT_TIMESTAMP` on first non-nullable TIMESTAMP columns — 9 found; 6 harmless
  (append-only, trigger-blocked); 3 reachable fixed to DATETIME (`patient_consents.granted_at` — consent
  withdrawal was silently rewriting the grant moment on MariaDB only; `portal_login_tokens.expires_at`;
  `thread_reads.read_at`), fail-first regression tests + an engine-independent information_schema guard
  (`MutableMomentParityTest`). Sweep verified all divergence classes handled (FULLTEXT ngram fallback,
  spatial NOT-NULL mirror + ST_Distance_Sphere, 5 CHECKs, SIGNAL-45000 triggers incl. ImmutabilitySweep
  green ON MySQL 8, partitioning, no generated/enum cols, pinned utf8mb4/utf8mb4_unicode_ci, identical
  strict sql_mode). CI now asserts ZERO pending migrations after the from-scratch MySQL 8 migrate;
  `composer test:mysql` is the manual one-step re-verification (THROWAWAY DB). Full brief:
  `docs/DB-PARITY.md`. If a CI-only failure appears again, check the env-divergence class FIRST.
- **Spitex demo tenant (P0P.G16):** `DemoSpitexSeeder` seeds **Spitex Sonnengarten** (slug
  `spitex-sonnengarten`, Zürich Wipkingen, EUR) — a COMPANION home-care demo tenant next to the clinic
  (D-082), shaped like a real agency's operating week for the coordinator conversation. **Stand up the
  demo with ONE command: `php artisan db:seed --class=DemoSpitexSeeder`** (idempotent; logins
  `<first>.<last>@spitex-sonnengarten.test` / `demo-password`). Contains: a 5-nurse roster with P.12
  competencies (incl. one EXPIRED grant and one nurse with none), 5 recurring RRULE home-care plans
  (daily insulin, 3×-weekly wound care, weekly bath assist, weekly catheter care, 2×-weekly palliative)
  materialized + a FULLY ASSIGNED current week, ~37 executed previous-month visits with GPS proof, tasks
  done/not-done, a 12-reading BP trend for one patient (P.13 unified series shows clinic AND visit
  sources), notes, one factual incident, actual-based timesheets; a signed+amended assessment, severe
  allergy, care plan, P.11 manual-result orders in both worklist states; an EU-Generic billing month
  that RECONCILES TO THE UNIT (6 gapless invoices, full/partial/over payments, partial credit note,
  dunning L1) — the seeder notes CH/KVG is deferred pending discovery, so the demo bills honestly;
  threads (one flagged-clinical), 2 KB articles, 2 pending do-nothing AI approvals; P.14 reporting
  returns non-trivial numbers incl. a real no-show. Audit chain verifies; the paired test proves
  idempotency + reconciliation + the competency hard-block/expired-block/soft-warn demos on seeded data.
- **Design wiring BEGUN — CLINIC vertical first (CLINIC.W1):** delivery #1 is the CLINIC vertical to a
  paying customer, wiring the exact "Eucalyptus Glow" prototype design onto the built clinic backend
  (`docs/CLINIC-DELIVERY-MAP.md` is the wire order; 25 wire-ready clinic+shared screens). CLINIC.W1 wired
  the FOUNDATION every later screen inherits: the Eucalyptus Glow `@theme` tokens + utilities in
  `resources/css/app.css` (euca-50..900 #F7FAF5→#35462F, ink/surface/hairline/semantics, `.euca-wash`
  `.glass-card` `.euca-tile-dark` `.btn-glow` `.nav-pill-active`; legacy `brand-*` repointed onto the
  euca ramp), the shared shell (AppLayout glass top-bar + pill nav + avatar; GuestLayout warm wash +
  brand lockup), the shared primitives (Card/Button/Input uplift + new BrandMark/CodeInput/PageHeader/
  StatCard), the Auth pages (Login / TwoFactorChallenge segmented / TwoFactorEnroll), and both Landings
  (propless frames — "—" placeholders + empty states, bound only to shared props). RE-SKIN ONLY per
  P0D.GU: no route/controller/prop/guard/test changed; `AppShellTest` auth+landing assertInertia tests
  pass UNCHANGED. Prototype pack (`resources/prototype/`, 52 MB compiled bundles) is gitignored (the map
  is committed). Verified: npm run build green; composer check FULLY green (Pint passed · PHPStan L5 [OK]
  No errors · **Pest 582 passed / 4198 assertions**) with ZERO test edits; adversarial 4-dimension review
  workflow → 0 confirmed defects. Recorded as D-083 (CLINIC.W1).
- **CLINIC.W2 — patient screens wired:** `Patients/Index` (glass search + avatar-initials result rows +
  empty state), `Patients/Show` = Patient 360 (deep-eucalyptus header tile, dormant AllergyBanner, pill
  Tabs with count chips over the 5 fixed tabs, consent cards, quiet access-log timeline), and
  `Patients/Register` (StepNav pills, glass steps, the amber duplicate card with server reasons +
  confidence, enriched review). Shared `Tabs`/`StepNav`/`DataList` re-skinned to euca tokens (Tabs gains
  an optional `count`; Clinical/Chart unaffected). RE-SKIN ONLY per P0D.GU — no route/controller/prop/
  emit/action/test changed; `PatientUiTest` (5 assertInertia/RBAC/read-log/cross-tenant tests) pass
  UNCHANGED. **Gaps flagged, not faked:** prototype "Client Record" is a SEPARATE unbuilt front-desk
  household/guarantor screen (`/clients/{client}`, `client.view`) — NOT Patient 360 (D-084); Patient-360
  header Edit + Portal-invite omitted (no URLs on the Show `actions` payload; no patient-edit route
  exists); AllergyBanner dormant behind an optional `allergies?` prop the backend doesn't send. Verified:
  npm run build green; composer check FULLY green (Pint · PHPStan L5 · **Pest 582 passed / 4198
  assertions**), zero test edits; adversarial 4-dimension review → 0 confirmed defects. Recorded as D-084.
- **CLINIC.W3 — patient portal wired (softer variant):** all seven authenticated portal pages
  (`Portal/Home`, `Appointments`, `Documents`, `Messages`, `Invoices`, `Consents`, `Telehealth`) +
  `PortalLayout` re-skinned to Eucalyptus Glow's patient-facing variant — 16px base, roomier glass
  cards, reassuring copy, bigger touch targets — on the portal's OWN layout + patient guard, NEVER the
  staff shell. Home hero + quick-action tiles; Appointments upcoming-hero + clay cancel-confirm +
  within-window call-the-practice note + AM/PM booking; Documents category filters; Messages
  patient/staff chat bubbles (no AI surfaces); Invoices open-balance + status filters; Consents serious
  two-step withdraw-confirm; Telehealth Join + "not recorded". **NO payment processing anywhere** (PSP
  deferred; balances display-only). RE-SKIN ONLY per P0D.GU — no route/controller/prop/action/guard/test
  changed; `PortalUiTest` (9 tests) pass UNCHANGED. Gaps flagged not faked: no patient name in portal
  props (generic greeting); no telehealth practitioner/time (generic title); unbacked extras omitted.
  Verified: npm run build green; composer check FULLY green (Pint · PHPStan L5 · **Pest 582 passed /
  4198 assertions**), zero test edits; adversarial 4-dimension review → 0 confirmed defects. D-085.
- **CLINIC.W4 — staff operational boards wired:** Reception Day-Board (date pager + view-filter pills +
  Quick-book slide-over; `ScheduleGrid` with left-edge WORKFLOW-status tints + all lifecycle actions +
  waitlist/series panels), Unified Inbox (three panes; dark thread-header tile + patient/staff/system
  bubbles + amber AI-draft box that never auto-sends + ai_assisted pill + clinician-attention banner +
  internal "not visible to patient" chip; Request-AI-draft hidden on flagged threads), Kiosk Check-in
  (kiosk-scale glass; safety UNCHANGED — name+dob+code, generic not-found, no clinical data/browsing,
  ephemeral, idle-reset), Public Booking (single-column step wizard; non-emergency notice on every step,
  no symptom/triage field). RE-SKIN ONLY per P0D.GU — no route/controller/prop/action/guard/test changed;
  `SchedulingUiTest` + `InboxUiTest` + `CheckInTest` (incl. kiosk safety) pass UNCHANGED. Gaps flagged
  not faked: Day-Board glance/waiting/conflict-resolver, Inbox rich context pane, Kiosk DOB-keypad/
  masked-identity/queue — all omitted (need props/endpoints the backend lacks, or would breach kiosk
  privacy). Verified: npm run build green; composer check FULLY green (Pint · PHPStan L5 · **Pest 582
  passed / 4198 assertions**), zero test edits; adversarial 4-dimension review → 0 confirmed defects. D-086.
- **CLINIC.W5 — clinical screens wired (final re-skin gate):** Patient Chart (deep-eucalyptus patient
  tile + prominent amber AllergyBanner + dashed source-linked AI-summary + count-chip tabs +
  month-grouped encounter timeline + RAW neutral vitals + problems/meds/documents/orders(P.11)/care),
  SOAP Note Editor (rails + autosave chip + dark sign bar + type-SIGN confirm + signed read-only lock
  line + amend-with-reason), and the raw-value "orders to review" worklist. **Electric fence held in the
  UI:** vitals raw/neutral (no bands/flags/sparklines/scores), AllergyBanner amber-soft (never red),
  signed notes read-only with no edit/delete + visible amendment history, AI content badged/dashed/
  source-linked with explicit human Insert. RE-SKIN ONLY per P0D.GU — sign-lock/amend/read-logging are
  server-enforced + unchanged; `ClinicalUiTest`/`VitalsHistoryTest`/`ClinicalNoteTest`/`OrderTest` pass
  UNCHANGED. Gaps flagged not faked: prototype "Treatment Plan" (dental fee-scheduled/phased/billed) and
  "Lab Result Review" (AI abnormal-flagging single-result view) are DIFFERENT unbuilt screens — the
  built Care Plans (chart care tab) + raw OrdersReview worklist are wired instead, the AI-flagging
  deliberately not adopted. Also fixed a pre-existing SOAP-editor data-loss reactivity footgun in
  passing (mutate-in-place binding). Verified: build green; composer check FULLY green (Pint · PHPStan
  L5 · **Pest 582 / 4198**), zero test edits; adversarial review → 0 confirmed. D-087.
- **DESIGN WIRING COMPLETE — the Eucalyptus Glow clinic vertical is fully re-skinned (W1→W5):** shell +
  auth + landings (W1) · patient index/360/register (W2) · patient portal (W3) · staff boards —
  day-board/inbox/kiosk/public-booking (W4) · clinical chart/note-editor/care-plans/lab-review (W5).
  All landed and green; every gate re-skin only (P0D.GU), routes/controllers/props/guards/tests frozen.
- **CLINIC.W6 — billing staff UI part 1 BUILT (first non-re-skin gate):** NEW Invoice/Aging/CreditNote
  controllers + 8 routes + 5 Inertia pages (Invoice worklist + detail · AR/Aging · Credit-notes
  Index+Show) + a `billing` nav entry, all reading from / dispatching to the frozen billing engine. NO
  billing/VAT/numbering/aging math in any controller or view — writes go only through
  `IssueService::issue`/`::creditNote` (CN = a `series=CN` Invoice, reason required, original untouched);
  reads via `invoice_balances` (live status) + `MetricsService`; money stays integer minor units, views
  format only. RBAC: reads `billing.view`, writes `billing.manage` (reception 403s; a view-only role gets
  `can_manage=false`; cross-tenant `{invoice}` 404s). Reads use the typed-query idiom (no relation-property
  traversal under PHPStan L5). Adversarial verify fixed 2 self-inflicted breaches: a client-side aging
  recompute (`isOverdue`) removed, and the overdue roll-up moved into NEW `MetricsService::overdueBalanceMinor()`;
  `download()` now serves `series=INV` only. NEW `tests/Feature/Billing/BillingUiTest.php` (7 tests) only;
  the reconciliation/invariant/hammer/`InvoiceTest` suite is UNCHANGED. Bundled (user-approved) a 1-line
  fix to a PRE-EXISTING date-bomb in `PortalUiTest` (hardcoded `2026-07-20` cancel-window appt that began
  failing on 2026-07-19 → de-hardcoded to `now()->addDays(10)`). Verified: npm build green; composer check
  FULLY green (Pint · PHPStan L5 `[OK]` · **Pest 589 passed / 4333 assertions**, 0 failed). D-088.
- **CLINIC.W7 — billing staff UI part 2 + reporting BUILT (FINAL clinic gate):** NEW Payment/Dunning/
  InvoiceDraft controllers + a Reporting dashboard controller + 11 routes + 6 Inertia pages (Payments
  Index/Record/Show · Invoices/New · Dunning/Index · Reporting/Dashboard) + a `reporting` nav entry +
  billing-hub cross-links. Payments record/allocate/reverse go only through `PaymentService` (append-only;
  over-allocation + reversal guards enforced in the service, surfaced as validation errors); new-invoice
  through `IssueService` (gapless, the view never sums); dunning through the idempotent
  `DunningService::evaluate` (legal-comms, fee = new charge, original untouched); the reporting dashboard
  renders `ReportingService::summary` FACTS-ONLY (neutral styling, no judgment/target/trend fields,
  financial section fail-closed without `billing.view`). NO financial math in any controller/view
  (adversarial grep clean — every `_minor` is a service call or model-attr passthrough). RBAC:
  payments/dunning read `billing.view`, reporting reads `reporting.view`, writes `billing.manage`;
  cross-tenant `{payment}`/`{invoice}` 404. NEW `tests/Feature/Billing/BillingUiPart2Test.php` (9 tests)
  only; the frozen payment/dunning/reconciliation/hammer/metrics suite is UNCHANGED. An adversarial
  5-dimension review→verify workflow found + fixed 3 real defects (a silent record-then-allocate failure
  now shows an error banner; new-invoice preview currency from settings not hardcoded; manage-only buttons
  now gated). Verified: npm build green; composer check FULLY green (Pint · PHPStan L5 `[OK]` · **Pest 598
  passed / 4503 assertions**, 0 failed). D-089.
- **DELIVERY COMPLETE — the Eucalyptus Glow CLINIC vertical is fully built + wired (W1→W7):** shell/auth/
  landings (W1) · patients (W2) · portal (W3) · staff boards (W4) · clinical (W5) · billing p1 —
  invoices/AR/credit-notes (W6) · billing p2 + reporting — payments/dunning/new-invoice/reporting (W7).
  W1–W5 were re-skin-only; W6–W7 built the billing/reporting presentation over the frozen, tested engines
  with zero domain-logic change. All landed and green.
- **QA audit (docs/QA-AUDIT-REPORT.md):** full Playwright E2E/UI audit across roles. Kiosk PHI-safety +
  clinical/reporting electric fence + RBAC all hold. Found C-1 (CRITICAL): billing detail + all write
  actions (and the CSV-import preview) 500'd in the real browser — implicit route-model binding resolving
  before the tenant-context middleware. Plus Mediums (staff landing is an unwired placeholder;
  tz-fragile date rendering; vitals shown in g/mm not kg/cm; bare 403 screens).
- **FIX.1 — C-1 resolved (D-090):** converted the 12 billing + import detail/write actions from implicit
  route-model binding to `string $id` + in-controller `Model::query()->whereKey($id)->firstOrFail()`
  (missing/cross-tenant → 404, fail-closed preserved; no billing logic changed). NEW
  `tests/Feature/RouteBindingTenantContextTest.php` exercises real middleware ordering
  (`TenantContext::forget()` before the request) — failed on the old code, passes now. composer check
  FULLY green (**Pest 601 passed / 4519 assertions**, 0 failed). The other QA-audit Mediums (landing,
  date rendering, vitals units, 403 UX) remain OPEN for a follow-up gate.
- **FIX.2 — M-1 resolved:** the staff landing (`/app`) was an unwired placeholder; NEW
  `AppLandingController` wires it to the EXISTING `MetricsService` for today (appointments/waiting/
  no-shows/active patients only with `reporting.view`; outstanding balance only with `billing.view`;
  metrics called conditionally on `Gate::allows` so reception gets the shell, never a 500). Genuine
  zeros replace the "awaiting data" stub. NEW `tests/Feature/AppLandingTest.php` (3 tests). No new
  metric invented. composer check FULLY green (**Pest 604 passed / 4590 assertions**). QA-audit
  Mediums still OPEN: M-2 date-rendering tz-shift, M-3 vitals units (g/mm→kg/cm), M-5 bare-403 UX.
- **Next action:** the standing focus is again **DISCOVERY** — the CH/KVG-vs-EU-generic billing model must
  be confirmed with Spitex coordinators before the CH statutory pack (the likely real first NEW build) is
  committed. Remaining billing backend-only surfaces (camt.053 reconciliation, AI dunning drafts,
  accounting-export UI) wait on that. Then Phase H per the master plan.
