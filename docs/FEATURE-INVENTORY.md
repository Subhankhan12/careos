# CareOS — Feature / Module Inventory & Classified Gap Map

**Status:** READ-ONLY inventory. No `.vue` / controller / route / test / migration was changed to
produce this document. It is a **decision tool**, not a build backlog: it records what is built, and for
everything not built it says **WHY** and **what would pull it forward** — so the founder can tell the
cheap-and-safe items from the real long poles that need a partner or a market answer.

**Top commit at time of writing:** `12e0386 CLINIC.W10: KB admin + staff telehealth join — admin vertical complete`.

**Supersedes the snapshot in `docs/CLINIC-DELIVERY-MAP.md`** (written at `aa4a04f`, *before* the W1–W10
delivery). That map's "❌ none / no built page" annotations for billing, reporting, settings, branches,
governance, KB, and staff-telehealth are now **out of date** — W6–W10 built exactly those. This document
is the post-W10 reconciliation.

---

## Section 1 — What's BUILT (the "done" map)

### 1a. Modules (`Modules/*`) — 13, each fail-closed tenant-owned

| Module | Domain it provides (services / models) |
|---|---|
| **Platform** | Tenancy + identity foundation: `Tenant`, `User`, `Branch`, `Department`, RBAC (`Role`/`Permission`/`RoleAssignment`, `PermissionService`, `RbacProvisioner`), `Plan`/`FeatureFlag`/`Setting` (+ `SettingsService`/`FeatureService`), `BreakGlassGrant`, `IntegrityCheck`; `TenantContext` + `BelongsToTenant` (no-context ⇒ throw), MFA + tenant-identify middleware. |
| **Audit** | Append-only, hash-chained, monthly-partitioned `audit_events` + `AuditService` (`record`/`recordRead`/`verifyChain`); read-logging concern. Does not depend on Platform (glue in `app/`). |
| **People** | `StaffProfile` (+ `user_id`) and `credentials` with expiry status (`credentials:refresh-status`). |
| **Patients** | Patient CRM: `Patient` (per-tenant MRN), contacts/identifiers/coverages, demographic duplicate detection + audited merge/unmerge, versioned consent engine (`ConsentService`), **portal identity** (`PortalAccount` + `patient` guard, invite/activation/login). |
| **Scheduling** | `Service`/`ServiceCatalog`, bookable `Resource` + `ResourceAvailability`, `AvailabilityService`/`AvailableSlotFinder`, no-double-book `BookingService` (resource-lock, hammer-proven), appointment lifecycle, waitlist + time-boxed `WaitlistOffer`, recurring `AppointmentSeries`, reminders, public online booking. |
| **Clinical** | `Encounter`, sign-and-lock SOAP `ClinicalNote` (+ amendments), problems/allergies/vitals/medications (raw, exact-match allergy hard-stop only), documents (private, controller-streamed), referrals, `RecallEngine`, care plans + tasks, structured `Order` + manual `OrderResult` (append-only), dot-phrase `TextSnippet`, unified `VitalsHistory`. |
| **Nursing** | Home-care: `ServiceAgreement`, RRULE `VisitPlan`→`PlannedVisit` (DST-safe), deterministic `AssignmentValidator` + concurrency-safe `VisitAssignmentService`, executed `Visit` + append-only GPS proof, offline sync (`nurse_sync_actions`), timesheets from proof events, incidents, tenant-authored `Competency` matrix. |
| **Billing** | Effective-dated `TariffCatalog`/`TariffItem` (+ `TariffResolver`), `Charge` (snapshotted) + `ChargeValidator`, frozen `Invoice`/`InvoiceLine` + `IssueService` (gapless), `CN` credit notes, append-only `Payment`/`PaymentAllocation`/`Refund` (+ `PaymentService`), `DunningService`, six-invariant `ReconciliationEngine` + `ReconciliationRun`, `AccountingExportService`, `InvoicePdfRenderer`. |
| **Comms** | Secure threads (patient/internal, append-only messages), one notification engine (versioned templates, consent matrix, append-only deliveries), unified inbox (derived unread), **telehealth** (metadata-only sessions, recording-disabled provider adapter, short-lived staff+patient tokens). |
| **AiCore** | Governed agent runtime: provider-agnostic `LlmManager`, append-only `ai_interactions`, `BudgetGate`/`CircuitBreaker`/`KillSwitch`, hash-pinned `PromptRegistry`, `ToolRegistry`, `AutonomyPolicy` (off/suggest/approve/auto; clinical+financial capped at approve), `ApprovalQueue`/`AgentRuntime`, KB (`KbArticle`+`KbEmbedding`, `KbRetriever` active-filter), Front-Desk agent. |
| **FrontDesk** | Self check-in: `CheckInService`, kiosk (identity-verified, PHI-safe, ephemeral) + portal paths; kiosk device tokens. |
| **Import** | CSV patient onboarding: mandatory dry-run (`ImportValidator`) → audited `ImportCommitter` through the real `PatientService`. |
| **Reporting** | Read-only facts layer (`MetricsService`/`ReportingService`) — universal operational/financial/throughput aggregates; owns no tables, no judgments. |

**App-layer composition (`app/*`)** — where two modules must be composed (modules never depend on each
other): AiCore **agents/tools** (`app/AiCore` — Scheduler, Clinical Summary/Follow-up, Dispatch, Billing,
Inbox agents), audit glue (`app/Audit`), Comms bridges (`app/Comms`), Clinical↔Nursing vitals reader
(`app/Clinical`), and the cross-module admin/governance controllers (`app/Http/Controllers`: AppLanding,
Branch, Resource, KbArticle, GovernanceDashboard, AiApprovalQueue, ClinicalSummary, Portal).

> **Module map note:** AGENTS.md lists `Dental` and `Interop` in the module map; **neither exists on
> disk** — they are planned placeholders, not built modules (see gap category A/B below).

### 1b. Wired screens (49 Inertia pages + Nurse PWA), by area

| Area | Built + wired pages | Delivery |
|---|---|---|
| **Auth / shell** | `Auth/Login`, `TwoFactorChallenge`, `TwoFactorEnroll`; `App/Landing`, `Admin/Landing`; `Error` | W1 (re-skin) · landing wired FIX.2 · error FIX.4 |
| **Patients** | `Patients/Index`, `Patients/Show` (360 + access log tabs), `Patients/Register` | W2 (re-skin) |
| **Portal (patient)** | `Portal/Login`, `Home`, `Appointments`, `Documents`, `Messages`, `Invoices`, `Consents`, `Telehealth` | W3 (re-skin) |
| **Staff boards** | `Scheduling/DayBoard`, `Comms/Inbox`, `Kiosk/CheckIn`, `Public/Book` | W4 (re-skin) |
| **Clinical** | `Clinical/Chart`, `NoteEditor`, `OrdersReview`, `OrderableItems`, `Snippets` | W5 (re-skin) + P0P |
| **Nursing** | `Nursing/Dispatch`, `Nursing/Competencies`; Nurse PWA (separate app) | re-skin + P0P |
| **Billing (staff)** | `Billing/Invoices/Index`+`Show`+`New`, `Aging`, `CreditNotes/Index`+`Show`, `Payments/Index`+`Record`+`Show`, `Dunning/Index` | **W6–W7 (BUILT over engine)** |
| **Reporting** | `Reporting/Dashboard` (facts-only) | **W7 (BUILT)** |
| **Admin** | `Admin/Settings`, `Admin/Roles`, `Admin/Branches`, `Admin/Kiosks` | **W8/W8b/W8c (BUILT)** |
| **Governance / AI-ops** | `Governance/Dashboard`, `Governance/ApprovalQueue`, `Governance/KnowledgeBase` | **W9–W10 (BUILT)** |
| **Telehealth (staff)** | `Telehealth/Sessions` | **W10 (BUILT)** |
| **Import** | `Import/Index`, `Import/Upload` | P0P.G6 |

**Re-skin-over-backend** (W1–W5): shell/auth/landings · patients · portal · staff boards · clinical —
routes/controllers/props/tests frozen. **Built-over-backend** (W6–W10): staff billing p1+p2 · reporting ·
settings/roles/branches/resources · governance/approval-queue · KB admin · staff telehealth — presentation
over frozen, tested engines with zero domain-logic change (P0D.GU).

### 1c. Tested guarantees (what is on-record solid) — 104 test files, 653 passing

- **Reconcile-to-the-unit** (LAUNCH BLOCKER): `ReconciliationEngine` six invariants, `delta_minor === 0`;
  the simulated-month + both demo tenants reconcile exactly; daily `billing:reconcile` monitor (D-068).
- **Electric fence + agent safety**: `tests/Evals/` — 6 agent eval files (Front-Desk, Clinical, Dispatch,
  Billing, Inbox, Cross-cutting) LOCK fence/autonomy-cap/grounding/"never trust the agent's numbers" (D-071);
  vitals/orders/labs render raw everywhere.
- **Immutability + audit chain**: DB-trigger + model guards on all append-only tables (audit_events,
  ai_interactions, financial ledgers, messages, integrity_checks, order_results); `audit:verify-chains`
  daily alarm (D-069); adversarial `tests/Feature/Security/` (30 tests, cross-tenant + RBAC + immutability).
- **No-double-book**: real-process parallel hammers for appointment booking, nurse assignment, invoice
  numbering, payment allocation, waitlist accept.
- **Route-reachability smoke**: `tests/Feature/Smoke/RouteSmokeTest` drives every major route through the
  REAL middleware stack (tenant context forgotten) — 200-not-500 + per-role RBAC by URL (D-093, guards the
  C-1 class); a new page = one line.
- **MySQL 8 parity**: from-scratch migrate asserts zero pending migrations; DATETIME-vs-TIMESTAMP,
  FULLTEXT ngram, spatial, CHECK, SIGNAL-45000 all handled and CI-green on MySQL 8 (D-081/P0P.G15).

---

## Section 2 — Classified GAP MAP (WHY each thing isn't built)

Every prototype screen / feature not built after W1–W10, tagged with exactly one category **A–F** plus a
one-line rationale and its **pull-forward trigger**. Grouped by category so it reads as *why*, not *to-do*.
The 4 meta screens (Foundation Styleguide, Design System Consistency Report, Flow Map, index.html) are
design-system reference, not product — excluded.

### (A) ANOTHER VERTICAL'S DELIVERY — belongs to a different customer's phase (18 screens)

| Feature / screens | Rationale | Trigger |
|---|---|---|
| **Dental (13):** Odontogram · Perio Charting · Crown Prep · RCT Procedure · Endo Diagnosis · Ortho Progress · Chair Scheduling · Treatment Plan · Imaging Viewer · Scan Library · Scan Upload · Scan Comparison Viewer · Inventory & Sterilization | An entire dental clinical vertical (charting, procedures, imaging, sterilization) — not the medical/home-care clinic. Needs its own `Dental` module (absent on disk). | A dental customer + a dental delivery phase. |
| **Insurance / claims (5):** Insurance Claim · Insurance Eligibility · Claim Fully/Partially Covered · Claim Rejected | A payer-claims vertical (eligibility, submission, adjudication states). Distinct from the built cash/patient-pay billing. Also **partner-gated** (see B: clearinghouse). | An insurance/claims customer (and, for real submission, a clearinghouse — B). |

### (B) PARTNER-GATED — cannot be built by code alone; needs an external integration (the real long poles)

| Feature | Rationale | Trigger |
|---|---|---|
| **Online payment capture** (Take Payment via PSP · Failed Payment) | Manual payment *recording* is BUILT (W7 `Payments/Record`); taking a card online needs a **PSP (Stripe/…)** + reconciliation wiring. Already parked (DEFERRED: portal PSP payment). | A chosen PSP **and** customers wanting patients to pay online. |
| **e-Prescribing** (Prescription & Refill) | Meds render in the chart, but prescribing needs a **pharmacy network + licensed drug database + per-market e-Rx rails** — none buildable in-house. Parked (DEFERRED: e-prescription rails). | An e-Rx partner + a market that mandates it. |
| **Real lab connectivity** (electronic Lab/Imaging Order transmission + result ingestion) | Structured orders + MANUAL results are BUILT (P0P.G11); electronic HL7/FHIR is a `LabConnectivity` stub only. Needs **a specific lab + a funded integration** (+ licensed coded catalog if required). Parked (DEFERRED). | A customer's lab + a funded build against its interface. |
| **Claims clearinghouse** (US X12 / EU payer submission) | The insurance vertical's *transport* — needs a **clearinghouse**. Parked (DEFERRED: US X12 via clearinghouse). | The insurance customer + a clearinghouse partner. |

### (C) DEFERRED PENDING DISCOVERY / MARKET — buildable, but shaped by an unanswered question

| Feature | Rationale | Trigger |
|---|---|---|
| **CH / KVG statutory billing pack** (+ Payment Reconciliation / camt.053 bank matching) | **The single riskiest open assumption.** Swiss Spitex is probably KVG/Krankenkassen + canton/co-pay reimbursement — a third billing model the EU-Generic pack doesn't cover. Its shape (and the bank-statement reconciliation format) depends on discovery. | Coordinator calls confirming the reimbursement mechanics (see PROJECT-STATE CURRENT FOCUS + DEFERRED). |
| **DE / FR statutory packs** (DATEV export columns, per-country VAT/tariff specifics) | EU-Generic reconciles to the unit; per-country specifics are packs added on demand. Parked (DEFERRED). | A signed/serious prospect in that country. |
| **eMAR** (medication administration record) | How meds must be *administered and documented* is care-setting- and jurisdiction-specific; the fence forbids a homemade dosing engine. Parked (DEFERRED: MAR). | A customer whose workflow needs eMAR + the documentation rules to model. |
| **Telehealth recording + transcripts** | Recording is disabled at the provider by design; enabling it needs a **funded consent + retention design** first (never without one). Parked (DEFERRED). | A customer requirement AND a completed consent/retention design (both). |
| **Owner-approval / cross-tenant read grants (5):** Owner Approval Request · Owner Granted Read-Only · Owner Notification · Owner Revoked Mid-Session · Waiting On Approval | An external owner granting time-boxed read access spans tenants; must use **explicit share objects, never scope-widening** (design not done). Break-glass backend exists but the cross-tenant owner flow does not. | A multi-owner/MSP customer + the share-object design (DEFERRED: cross-tenant share objects). |
| **New Agent Wizard** (add/configure fuller agents) | The narrow Phase C–G agents are deliberate; fuller agents are **Phase H**, a distinct phase, not an extension. Parked (DEFERRED: Phase H agents). | Design partners asking for a specific agent. |

### (D) DOMAIN-BUILT, UI MISSING — the cheap, safe ones (backend exists; only a screen is absent)

Buildable now with the W6/W7 pattern (presentation over a tested engine, P0D.GU) — but **still pull from a
real need**, not on spec.

| Screen | Backend that already exists |
|---|---|
| **Fee Schedule Editor** | `TariffCatalog`/`TariffItem` + `TariffResolver` (edit is seed-only today). |
| **Statement of Account** (per-patient PDF) | `InvoicePdfRenderer` + `AccountingExportService`. |
| **AR Account Detail** (per-patient ledger) | `InvoiceBalance` + `Charge` + `PaymentService` (tenant-level Aging is built; per-patient page absent). |
| **Governance Ledger Export** (accountant hand-off UI) | `AccountingExportService` + `billing:export` (command exists; no screen). |
| **Provider / Resource Availability admin** | `AvailabilityService`/`ResourceAvailability` (the flagged W8c follow-up — CRUD'd resources need an availability screen). |
| **Recall Due List** (worklist) | `RecallEngine`/`Recall` (recalls surface in the chart; no worklist page). |
| **Service Catalog / Service Create** | `ServiceCatalog`/`Service`. |
| **Care Plan Review** / **Referral Out** (dedicated pages) | `CarePlan`/`Referral` (both render in the chart today). |
| **Appointment Detail** (standalone) | `Appointment` (DayBoard has inline rows + actions). |
| **Waitlist Management** (dedicated page) | `WaitlistService` + `WaitlistOffer` (POST actions already wired headless on the day-board). |
| **Lab / Imaging Order entry** (dedicated page) | `OrderController::place`/`OrderService` (placing is contextual today). |
| **My Account** (staff profile + 2FA + password) | `StaffProfile` + Fortify 2FA endpoints. |
| **Portal Invite / accept + Portal Password Reset** pages | `PortalInvitationController`/portal auth (routes exist; pages absent/unlinked). |

### (E) BUILDABLE-BUT-UNVALIDATED — no external dependency, but nobody has asked

Genuinely optional; the trigger is a **customer or user creating the need**.

| Feature | Note |
|---|---|
| **Notification Center** (staff notification inbox) | `NotificationDelivery` + `NotificationService` built; no staff surface. |
| **No-Show Follow-Up** workflow · **Reminder Sent** confirmation | No-show transition + reminder dispatcher built; workflow/confirmation pages absent. |
| **Patient Flow** board · **Medical History Intake** questionnaire | The two thinnest-backend clinical screens (no flow-board / intake-questionnaire model). |
| **Consult Summary** (standalone encounter summary) | Encounter/note data + AI summary already live in the chart. |
| **Consent-request flow** (Request Consent Update / Declined / Expired / Opt-in Confirmed) | `ConsentService` built; the request/outcome flow pages absent. |
| **Payment Plan (installments)** | The one billing item whose *domain* is genuinely missing (no installment model). |
| **Super-Admin platform UI** (Tenants list / Tenant Detail / Create Tenant) | `Tenant` + `RbacProvisioner` built; tenants are created via seeder/code. Internal ops tool, not a customer feature. |
| **Operator-mode / break-glass UI (6)** | `BreakGlassGrant` domain exists; the elevated-session operator UI is scoped out. |
| **Governance drill-downs** (Draft Review Composer · Rejected / Resolved / Fence-Refused / Consent-Blocked Action Detail) | The W9 approval queue covers the core (pending + resolved inline); these are richer per-action views over already-audited data. |
| **Agent & Tool Config** (per-tool autonomy) | `AutonomyPolicy` is settings-backed; a config screen is buildable (distinct from the Phase-H New Agent Wizard, which is C). |
| **Reverb realtime · richer dashboards · i18n content** | Parked (DEFERRED) — realtime, dashboards beyond the facts layer, non-English clinical copy. |

### (F) INTENTIONAL NON-GOAL — designed in the prototype but a deliberate non-feature (never build the homemade version)

| Non-feature | Why it must not be built in-house |
|---|---|
| Homemade **drug-interaction / allergy-class / dose / CDS** checker | Medical-device territory — needs a licensed drug DB + regulatory track; the built allergy rule is exact-match only (D-036, DEFERRED). |
| **AI abnormal-flagging** on labs/vitals (the prototype's "Lab Result Review" AI-flag view) | Electric fence: results/vitals are shown RAW with no range/flag/score. The built OrdersReview is deliberately raw (D-087). |
| **AI listening to / transcribing / triaging** a telehealth call or patient message | Electric fence, absolute (D-G3). The room is not the clinical record; no AI on the call, ever. |
| Any **symptom/triage free-text** on public booking or kiosk | Electric fence (D-031/D-074) — deliberately absent. |

**Cross-check vs `DEFERRED.md`:** the B and C long poles and the Reverb/i18n/PSP/lab items are already
parked there with triggers. **Gaps NOT yet individually in DEFERRED** (tracked here instead, because they
are demand-driven UI over built domain, not "deferred work"): the category **(D)** screens; the category
**(E)** items **Payment Plan (installments)**, **Super-Admin platform UI**, **operator-mode/break-glass
UI**, **Notification Center**, **Medical History Intake**, **Patient Flow**, and the **governance
drill-down** views. None of these block a demo; they are a menu, and this document is their record.

---

## Section 3 — The two lists a founder actually needs

### 3a. Genuinely safe to build now — *only if a customer needs it* (categories D + E)

These need **no partner and no discovery answer** — just the W6/W7 presentation pattern over an
already-tested engine. **Do not build on spec; each should be pulled by a real user need.** Cheapest first:

1. **Provider/Resource Availability admin** (the flagged W8c follow-up — makes self-service scheduling complete).
2. **Fee Schedule Editor** (lets a clinic edit its own tariffs instead of a seeder).
3. **Recall Due List** / **Service Catalog** / **Care Plan Review** / **Referral Out** worklist pages (chart data → dedicated pages).
4. **My Account** (staff 2FA/password self-service) · **Portal Invite/Reset** pages (onboarding polish).
5. **Statement of Account** / **AR Account Detail** / **Governance Ledger Export UI** (billing presentation).
6. Then the (E) items (Notification Center, No-Show workflow, Super-Admin UI, break-glass UI, governance
   drill-downs, Payment-Plan domain) — each only when a user asks.

### 3b. The real long poles — blocked on a partner or a market answer (categories B + C)

These are **conversations and partnerships, not code**. They are the founder's actual dependency list:

- **CH / KVG billing pack (C)** — *the top one.* Blocked on discovery (how Spitex bills). The likely real
  first *new build*, but only after coordinator calls confirm the reimbursement model. Includes the
  camt.053 bank reconciliation shape.
- **Online payments / PSP (B)** — blocked on choosing a PSP + a customer wanting online capture.
- **e-Prescribing (B)** — blocked on a pharmacy/drug-DB partner + a market mandate.
- **Real lab connectivity (B)** — blocked on a specific lab + a funded HL7/FHIR build.
- **Insurance/claims + clearinghouse (A+B)** — a whole vertical + a clearinghouse partner.
- **Dental (A)** — a whole vertical (its own module), for a dental customer.
- **eMAR, DE/FR packs, telehealth recording, owner-approval share objects, Phase-H agents (C)** — each
  waits on a specific customer workflow, jurisdiction, or funded design decision.

---

## Section 4 — Honest bottom line

**The CLINIC and ADMIN verticals are delivered.** Every clinic operational surface (patients, scheduling,
clinical, portal, nursing, staff billing/AR/payments/dunning, reporting) and every admin surface
(settings, roles, branches, resources, governance dashboard, AI approval queue, KB admin, staff
telehealth) is built, wired, and green — each screen sits over a tested engine with no new domain logic
(P0D.GU), and the fence/reconciliation/immutability/RBAC guarantees hold in tests, in CI on MySQL 8, and
in the browser.

The remaining prototype screens are **not a build backlog to burn down.** They sort almost entirely into
*another vertical's delivery* (dental, insurance — need their own customer + module), *partner-gated*
(PSP, e-Rx, real labs, clearinghouse — need an integration, not code), *deferred pending discovery/market*
(CH/KVG billing is the top one, plus eMAR/DE/FR/owner-approval/Phase-H), *domain-built-UI-missing* (cheap
and safe, but pull from need), or *intentional non-goals* (anything that would breach the electric fence —
never build the homemade version). The correct next move remains **DISCOVERY** — the CH/KVG-vs-EU-generic
billing question with Spitex coordinators — not more building. This inventory is a **menu to pull from when
a customer, partner, or market answer justifies an item**, and a record so nothing is forgotten.
