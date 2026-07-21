# CareOS — Clinic-Vertical Delivery Map (screen reconciliation)

> **⚠️ HISTORICAL PLANNING ARTIFACT (written at `aa4a04f`, P0P.G16 — pre-delivery).** The "not yet
> built / ❌" and "to wire" marks below are STALE: the clinic vertical was subsequently DELIVERED
> (CLINIC.W1–W7), QA-fixed (FIX.1–FIX.5 + final pass), and the ADMIN vertical built
> (W8/W8b/W8c/W9/W10). For CURRENT status read **`PROJECT-STATE.md`** (authoritative snapshot) and
> **`docs/FEATURE-INVENTORY.md`** (classified gap map). Keep this file for the screen→backend mapping
> rationale only; do not treat its build-status marks as current.

**Status:** READ-ONLY reconciliation. No `.vue` / controller / route / test was changed to produce this
document. It maps the ~117-screen prototype design pack (`resources/prototype/*.html`) onto the
already-built clinic backend so that delivery #1 (the CLINIC vertical, to a paying customer) is a
pure **re-skin-and-wire** job, not a build.

**Top commit at time of writing:** `aa4a04f P0P.G16: richer Spitex-flavoured demo tenant`.

**Standing rule (P0D.GU / D-standing):** backend wins on BEHAVIOR, prototype wins on LOOKS. Components
are presentational; authorization, validation, and state transitions are enforced and tested
SERVER-SIDE. Wiring a prototype screen means re-skinning a built `.vue` page against its existing
route/props/actions — it can never change a rule.

---

## Inputs reconciled

- **Prototype pack:** `resources/prototype/` — **117 `.html` files** (116 named product/meta screens
  + `index.html`). Each is a compiled Claude Design bundle (base64/compiled React; not hand-editable
  markup). This map keys off each screen's **identity + purpose** (filename + extracted strings), not
  its markup.
- **Built-screen contract:** `docs/SCREENS.md` — documents **22 Inertia pages + the Nurse PWA** (frozen
  at P0G.C). Cross-cutting invariants a redesign must preserve are listed there (i18n keys, shared
  Inertia props, layouts, AI labels, allergy banners, raw vitals, non-emergency notice).
- **Actual Vue pages on disk:** `resources/js/pages/` — **30 Inertia pages** exist today (the 22 frozen
  in SCREENS.md **+ 8 built after the freeze**: `Clinical/OrderableItems`, `Clinical/OrdersReview`,
  `Clinical/Snippets`, `Import/Index`, `Import/Upload`, `Admin/Kiosks`, `Kiosk/CheckIn`,
  `Nursing/Competencies`). Plus the standalone `nurse-pwa/` app.
- **Backend domain layer:** `Modules/*` (Billing, Clinical, Comms, FrontDesk, Import, Nursing, Patients,
  Scheduling) + `app/*` platform code. Route inventory from `routes/web.php`.

> **Key reconciliation fact that shapes everything below:** the backend has a **rich domain layer**
> (models + services) for far more than it has **wired staff UI**. Whole feature areas — billing/AR,
> payments, reporting, referrals, recalls, tariffs, staff telehealth — exist as tested services with
> **no staff-facing controller + route + Inertia page**. Those screens are therefore *not wire-ready*
> even though the "backend" broadly exists. Bucket 2 annotations distinguish **"domain exists, needs
> presentation layer"** (cheap) from **"domain genuinely missing"** (expensive).

---

## Four-bucket categorization (all 117 screens, per-vertical tagged)

### Legend
- **B1** = clinic screen that maps to a BUILT + WIRED page (re-skin only). ✅ wire-ready.
- **B2** = clinic screen with no built staff page (domain may exist; presentation/route absent).
- **B3** = non-clinic (dental / insurance / e-Rx / operator-mode / owner-approval). OUT OF SCOPE.
- **B4** = shared/admin the clinic delivery also needs (auth, shell, admin, governance, KB, notifications,
  my-account, error states, meta/design-system).

---

### BUCKET 1 — CLINIC, built + wire-ready (23 screens)

| # | Prototype screen | Vertical | Built Vue page | Route |
|---|---|---|---|---|
| 1 | Patients Index + Register | clinic | `Patients/Index.vue` + `Patients/Register.vue` | `patients.index` / `patients.register` |
| 2 | Patient 360 | clinic | `Patients/Show.vue` | `patients.show` |
| 3 | Patient Access Log | clinic | `Patients/Show.vue` → "access" tab (component/state) | `patients.show` |
| 4 | Client Record | clinic* | `Patients/Show.vue` (home-care "client" variant of Patient 360) | `patients.show` |
| 5 | Patient Chart | clinic | `Clinical/Chart.vue` | `clinical.chart` |
| 6 | Allergy Alert | clinic | `AllergyBanner` component inside `Clinical/Chart.vue` + Nurse PWA (state) | `clinical.chart` |
| 7 | Note Editor | clinic | `Clinical/NoteEditor.vue` | `clinical.notes.edit` |
| 8 | Lab Result Review | clinic† | `Clinical/OrdersReview.vue` | `clinical.orders.worklist` |
| 9 | Reception Day-Board | clinic | `Scheduling/DayBoard.vue` | `scheduling.day-board` |
| 10 | Public Booking | clinic (patient) | `Public/Book.vue` | `public.booking.index` |
| 11 | Kiosk Check-in | clinic (front-desk) | `Kiosk/CheckIn.vue` | `kiosk.check-in.page` |
| 12 | Unified Inbox | clinic | `Comms/Inbox.vue` | `comms.inbox` |
| 13 | Nursing Dispatch | clinic (operational) | `Nursing/Dispatch.vue` | `nursing.dispatch` |
| 14 | Nurse PWA | clinic (operational) | `nurse-pwa/` standalone app | `POST /api/nurse/*` |
| 15 | Portal Login | clinic (portal) | `Portal/Login.vue` | `portal.login` |
| 16 | Portal Home | clinic (portal) | `Portal/Home.vue` | `portal.home` |
| 17 | Portal Appointments | clinic (portal) | `Portal/Appointments.vue` | `portal.appointments` |
| 18 | Portal Documents | clinic (portal) | `Portal/Documents.vue` | `portal.documents.index` |
| 19 | Portal Messages | clinic (portal) | `Portal/Messages.vue` | `portal.messages` |
| 20 | Portal Invoices | clinic (portal) | `Portal/Invoices.vue` | `portal.invoices` |
| 21 | Portal Consents | clinic (portal) | `Portal/Consents.vue` | `portal.consents` |
| 22 | Portal Telehealth | clinic (portal) | `Portal/Telehealth.vue` | `portal.telehealth` |
| 23 | Portal Sign Out | clinic (portal) | `PortalLayout` logout control (component/state) | `portal.logout` |

`*` **Client Record** is ambiguous — "client/patient/appointment/visit" strings suggest a home-care
"client" analog of Patient 360, not a distinct entity. Default: re-skin `Patients/Show`. Confirm with
the customer whether a separate home-care record is intended (would move to B2 if so).
`†` **Lab Result Review** maps to the built orders-review worklist (`OrderController` + `OrderService`
are real). The task hinted "lab" as out-of-scope; because a **built page exists**, it is listed
wire-ready here — but **confirm with the customer** whether lab result review is in delivery #1.

---

### BUCKET 4 — SHARED / ADMIN the clinic delivery also needs (26 screens)

Wire-ready shared screens (built pages exist) are marked ✅. The rest have **domain backend but no
built page** — needed by a full clinic delivery but *not day-1 re-skin work* (they require new
controllers/routes/pages).

| # | Prototype screen | Sub-area | Built page? | Backend domain |
|---|---|---|---|---|
| 1 | Auth Screens | auth | ✅ `Auth/Login.vue` + `Auth/TwoFactorChallenge.vue` + `Auth/TwoFactorEnroll.vue` | Fortify + 2FA (built) |
| 2 | Landings | shell/nav | ✅ `App/Landing.vue` + `Admin/Landing.vue` (placeholders) | shells (built) |
| 3 | System Error States | error states | ⚠️ none (Inertia error pages) | framework |
| 4 | My Account | my-account | ❌ none | `People/StaffProfile`, 2FA endpoints exist |
| 5 | Notification Center | notifications | ❌ none | `Comms/NotificationDelivery` + `NotificationService` |
| 6 | Admin Settings | admin | ❌ none | `Platform/Setting` + `SettingsService` |
| 7 | Admin Branches | admin | ❌ none | `Platform/Branch` |
| 8 | Branch Create | admin | ❌ none | `Platform/Branch` |
| 9 | Super-Admin Tenants | admin (super) | ❌ none (only `Admin/Landing` placeholder) | `Platform/Tenant` |
| 10 | Super-Admin Tenant Detail | admin (super) | ❌ none | `Platform/Tenant` |
| 11 | Create Tenant | admin (super) | ❌ none | `Platform/Tenant` + `RbacProvisioner` |
| 12 | KB Admin | governance/KB | ❌ none | `AiCore/KbArticle` + `KbEmbedding` |
| 13 | Governance Dashboard | governance | ❌ none | `Audit/AuditEvent`, `AiCore/AgentAction` |
| 14 | Governance Ledger Export | governance | ❌ none | `Audit` + `AccountingExport` |
| 15 | Admin Approval Queue | governance | ❌ none (inline in inbox only) | `AiCore/ApprovalQueue` + `AgentAction` |
| 16 | Draft Review Composer | governance | ❌ none | `AiCore/ApprovalQueue` |
| 17 | Rejected Action Detail | governance | ❌ none | `AiCore/AgentAction` |
| 18 | Resolved Action Detail | governance | ❌ none | `AiCore/AgentAction` |
| 19 | Fence-Refused Detail | governance/AI-safety | ❌ none | electric-fence audit/ledger |
| 20 | Consent-Blocked Draft | governance/AI-safety | ❌ none | consent + agent guardrails |
| 21 | Agent & Tool Config | governance/AI-ops | ❌ none | `AiCore/ToolRegistry` + `AgentRuntime` |
| 22 | New Agent Wizard | governance/AI-ops | ❌ none | `AiCore/AgentRuntime` |
| 23 | Foundation Styleguide | meta / design-system | n/a — reference, not a product screen | — |
| 24 | Design System Consistency Report | meta / design-system | n/a — reference | — |
| 25 | Flow Map | meta / design-system | n/a — screen-flow map | — |
| 26 | index.html | meta | n/a — prototype index | — |

Wire-ready in B4: **Auth Screens** and **Landings** (2 screens → 5 built pages). Everything else in
B4 is either governance/admin needing a presentation layer, or non-product meta/design-system
reference material (4 screens: Foundation Styleguide, Design System Consistency Report, Flow Map,
index.html — useful as the design token/source-of-truth reference, **not deliverable screens**).

---

### BUCKET 2 — CLINIC gaps: shown in prototype, no built staff page (38 screens)

Grouped by area. **"domain built"** = models + services exist and are tested; only the staff
controller + route + Inertia page is missing (cheaper). **"domain missing"** = genuinely new backend.

#### Billing / AR / payments — the single biggest clinic gap (15 screens)
Billing domain is **rich and built** (`Modules/Billing`: `Invoice`, `InvoiceLine`, `InvoiceBalance`,
`Charge`, `Payment`, `PaymentAllocation`, `Refund`, `DunningEvent`, `ReconciliationRun`,
`TariffCatalog`/`TariffItem`, `InvoiceSequence` + `IssueService`, `PaymentService`, `DunningService`,
`ReconciliationEngine`, `TariffResolver`, `InvoicePdfRenderer`, `AccountingExportService`). The ONLY
wired billing route today is patient-facing **`portal.invoices`** (read-only PDF, deliberately no pay
button). **No staff billing UI exists.**

| Prototype screen | Vertical | What's missing |
|---|---|---|
| Billing Invoices List | clinic-billing | domain built; needs staff invoice-index controller+route+page |
| Billing Invoice Detail | clinic-billing | domain built; needs invoice-show page |
| Billing & AR | clinic-billing | domain built (`InvoiceBalance`/`Charge`); needs AR-aging dashboard page |
| AR Account Detail | clinic-billing | domain built; needs per-patient AR ledger page |
| Statement of Account | clinic-billing | domain built (`InvoicePdfRenderer`/`AccountingExport`); needs statement page |
| Take Payment | clinic-billing | `PaymentService` built; needs capture UI **+ PSP/Stripe (deferred, D-decision)** |
| Payment Received | clinic-billing | `PaymentService` built; confirmation state, no page |
| Failed Payment | clinic-billing | needs PSP integration (deferred) — mostly a PSP-error state |
| Payment Reconciliation | clinic-billing | `ReconciliationEngine`/`ReconciliationRun` built; needs page |
| Payment Plan | clinic-billing | **domain likely missing** — no installment-plan model/service found |
| Refund Issued | clinic-billing | `Refund` model built; needs page |
| Credit Note Issued | clinic-billing | partial — `Refund` exists; **no explicit credit-note model** |
| Invoice Overdue Reminder | clinic-billing | `DunningService`/`DunningEvent` built; needs page/state |
| Fee Schedule Editor | clinic-billing | `TariffCatalog`/`TariffItem` built; needs tariff-editor page (shared w/ dental) |
| Financial Statement | clinic-billing/reporting | `AccountingExport` + `Reporting` built; needs report page |

#### Reporting (1 screen)
| Practice Reporting Hub | clinic-reporting | `MetricsService`/`ReportingService` built (P0P.G14 = **service layer, explicitly NO UI**); needs dashboard page |

#### Scheduling extras (8 screens)
| Appointment Detail | clinic | `Appointment` built; DayBoard has rows+actions but no standalone detail page |
| Provider Availability | clinic | `AvailabilityService`/`ResourceAvailability` built; no page |
| Waitlist Management | clinic | `WaitlistService` + POST actions wired (`scheduling.waitlist.*`); **no dedicated page** (headless actions) |
| Recall Due List | clinic | `RecallEngine`/`RecallService`/`Recall` built (recalls shown in chart); no worklist page |
| No-Show Follow-Up | clinic | `AppointmentService` no-show transition built; no follow-up workflow page |
| Reminder Sent Confirmation | clinic | `ReminderDispatcher`/`AppointmentReminder` built; confirmation state, no page |
| Service Catalog | clinic | `ServiceCatalog`/`Service` built; no staff catalog page |
| Service Create | clinic | `Service` built; no staff create page |

#### Clinical extras (6 screens)
| Consult Summary | clinic | `Encounter`/note data exist; no encounter-summary page (AI summary lives in Chart) |
| Care Plan Review | clinic | `CarePlan`/`CarePlanService` built (shown in Chart); no dedicated review page |
| Referral Out | clinic | `Referral`/`ReferralService` built (shown in Chart); no dedicated referral page |
| Medical History Intake | clinic | **no dedicated intake questionnaire** (register wizard covers demographics only) |
| Lab Imaging Order | clinic | `OrderController.place`/`OrderService` built; no dedicated order-entry page (placing is contextual). *Dental-imaging overlap — confirm scope.* |
| Patient Flow | clinic | reception patient-flow/status board; DayBoard is the agenda; no flow board page |

#### Comms / staff telehealth (2 screens)
| Telehealth Sessions | clinic | `TelehealthSession`/`TelehealthService` built; portal join wired; **no staff sessions page** |
| Telehealth Join | clinic | staff-side join; patient join built (`Portal/Telehealth`); no staff join page |

#### Portal onboarding / consent-request (6 screens)
| Portal Invite | clinic-portal | `PortalInvite`/`accept-invite` backend built (`portal.accept-invite`); no invite/accept page |
| Portal Password Reset | clinic-portal | reset routes exist server-side but **unlinked** (per SCREENS.md); no portal reset page |
| Request Consent Update | clinic | `ConsentService` built; no consent-request-update flow page |
| Request Declined | clinic* | request-outcome state; no page — *ambiguous (consent-request vs owner-approval)* |
| Request Expired | clinic* | request-outcome state; no page — *ambiguous* |
| Opt-in Confirmed | clinic* | comms/consent opt-in confirmation; no page — *ambiguous (could be portal)* |

`*` Request Declined / Request Expired / Opt-in Confirmed are read as the tail states of the
consent-request flow; if they instead belong to the owner-approval flow they move to B3.

---

### BUCKET 3 — NON-CLINIC, out of scope for clinic delivery #1 (30 screens)

Grouped by vertical.

**Dental (13):** Odontogram · Perio Charting · Crown Prep · RCT Procedure · Endo Diagnosis ·
Ortho Progress · Chair Scheduling · Treatment Plan* · Imaging Viewer · Scan Library · Scan Upload ·
Scan Comparison Viewer · Inventory & Sterilization.
`*` **Treatment Plan** is ambiguous (dental tx-plan vs clinical care-plan); clustered with dental and
distinct from the separate "Care Plan Review", so tagged dental. Confirm if a clinical treatment plan
is wanted (would move to B2).

**Insurance / claims (5):** Insurance Claim · Insurance Eligibility · Claim Fully Covered ·
Claim Partially Covered · Claim Rejected.

**e-Rx / prescribing (1):** Prescription & Refill. *(`Clinical/Medication` exists and meds render in
Chart, but there is no prescribing/e-Rx workflow — out of scope.)*

**Operator-mode / break-glass (6):** Operator Mode Banner · Operator Mode Hub ·
Enter Operator Mode Confirm · Operator Session Ended · Elevated Session Banner · Session Extended.
*(`Platform/BreakGlassGrant` domain exists; task scopes operator-mode out.)*

**Owner-approval (5):** Owner Approval Request · Owner Granted Read-Only · Owner Notification ·
Owner Revoked Mid-Session · Waiting On Approval.

---

## WIRE ORDER — delivery #1 (Bucket 1 + wire-ready Bucket 4)

25 prototype screens can be wired to already-built pages. Ordered simplest/safest first
(auth → landings → portal shell → lists → boards → detail → chart → note-editor last). Each row lists
the built target, route, key props/actions, and the design-vs-data divergence to watch.

| Step | Prototype screen | Built target | Route | Key props / actions | Divergence to watch |
|---|---|---|---|---|---|
| 1 | Auth Screens | `Auth/Login` (+ `TwoFactorChallenge`, `TwoFactorEnroll`) | `login` / `two-factor.login` / `two-factor.enrollment` | form `{email,password,remember}`; TOTP `code`/`recovery_code`; enroll `qrSvg`/`recoveryCodes`/`confirm` | **No self-register / no forgot-password link** on login (reset routes exist but unlinked). QR is server SVG via `v-html` — don't swap to `<img>`. Keep recovery codes copyable. |
| 2 | Landings | `App/Landing` + `Admin/Landing` | `app.landing` / `admin.landing` | shared `auth.user` only; header nav Links; sign-out | Both are **placeholder empty-state cards** — no dashboard data exists (Reporting has no UI). **Don't invent metrics.** Keep the two landings separate (different routes/guards/i18n). |
| 3 | Portal Login | `Portal/Login` | `portal.login` | `actions.loginUrl`; local `{email,password}` | Single **generic** error (no per-field errors); standalone (no `PortalLayout`). |
| 4 | Portal Sign Out | `PortalLayout` logout control | `portal.logout` | POST logout | Component/state, not a page — re-skin the layout control. |
| 5 | Patients Index + Register | `Patients/Index` + `Patients/Register` | `patients.index` / `patients.register` | Index: `filters`,`patients[]`. Register: `duplicateCheckUrl`,`storeUrl` | 25-row cap, **no pagination**. Register steps freely clickable; **duplicate panel must stay**; contact slots are index-bound (don't reorder). |
| 6 | Patient 360 | `Patients/Show` | `patients.show` | `patient{contacts,identifiers,coverages,consents}`,`accessLog`,`actions{can_edit,grant_consent_url}` | 5 fixed tabs; consent buttons gated by `can_edit`; single shared withdraw-reason input; **access log must stay visible**. |
| 7 | Patient Access Log | `Patients/Show` → access tab | `patients.show` | `accessLog[]` | Read-only privacy/compliance surface (component/state, not a new page). |
| 8 | Client Record | `Patients/Show` (variant) | `patients.show` | as Patient 360 | **Confirm** home-care variant vs re-skin of Patient 360 before wiring. |
| 9 | Reception Day-Board | `Scheduling/DayBoard` | `scheduling.day-board` | `filters`,`branches`,`resources`,`appointments`,`services`,`patients`,`slotPreview`,`actions{transition,quickBook,slots,openEncounter}` | **All lifecycle buttons always shown** — server decides legality; surface server validation errors. |
| 10 | Public Booking | `Public/Book` | `public.booking.index` | `tenant`,`services(bookable_online)`,`branches`,`slotsUrl`,`storeUrl` | **Non-emergency notice must stay prominent**; **no symptom/triage free-text field may be added** (electric-fence D-031). |
| 11 | Portal Home | `Portal/Home` | `portal.home` | `nextAppointment`,`unreadMessages`,`outstandingBalanceMinor` | Balance = `minor/100`, **no currency symbol**; purely presentational. |
| 12 | Portal Appointments | `Portal/Appointments` | `portal.appointments` | `upcoming`,`past`,`services`,`branches`,`cancelMinHours`,`actions{slots,store,cancel}` | Cancel-window enforced server-side; null service renders "—". |
| 13 | Portal Documents | `Portal/Documents` | `portal.documents.index` | `documents[]` (only shared) | **Only explicitly-shared docs** ever appear — never the full chart. |
| 14 | Portal Invoices | `Portal/Invoices` | `portal.invoices` | `invoices[]`; `<a>` PDF download | **NO pay button** (payment deferred). Prototype "Take Payment" is a separate *staff* screen (B2, unbuilt) — keep portal read-only. Keep `overflow-x-auto`. |
| 15 | Portal Messages | `Portal/Messages` | `portal.messages` | `threads`,`activeThread`,`actions.storeUrl` | Reply form renders **only when thread open**; author labels via dynamic i18n keys. |
| 16 | Portal Consents | `Portal/Consents` | `portal.consents` | `consents[]`,`actions.withdrawUrl` | Withdrawing `portal.access` **locks the whole portal next request** — surface this; withdraw reason mandatory. |
| 17 | Portal Telehealth | `Portal/Telehealth` | `portal.telehealth` | `sessions[]`; token POST | Token lives **only in memory** — never store/echo/log; no embedded video client; keep privacy notice. |
| 18 | Kiosk Check-in | `Kiosk/CheckIn` | `kiosk.check-in.page` | resolve / check-in / contact actions | Device-facing; throttled resolve endpoint. |
| 19 | Unified Inbox | `Comms/Inbox` | `comms.inbox` | `filters`,`threads`,`activeThread(+aiDraft)`,`staff`,`actions{reply,status,assign,aiDraft,sendDraft}` | **Amber AI-draft box + source chips + explicit Send must stay**; **red clinician-attention banner must stay**; `ai_assisted` badge; clinical question ⇒ no draft (electric fence). |
| 20 | Nursing Dispatch | `Nursing/Dispatch` | `nursing.dispatch` | `filters`,`branches`,`unassignedVisits`,`nurseLanes`,`actions{assign,unassign}` | **Assignment-error danger banner must stay**; Assign disabled until a nurse is chosen. |
| 21 | Patient Chart | `Clinical/Chart` | `clinical.chart` | `patient`,`encounters`,`notes(+versions)`,`problems`,`allergies`,`vitals`,`medications`,`documents`,`carePlans`,`referrals`,`recalls`,`aiSummary`,`actions` | **AllergyBanner prominent**; **vitals RAW only** (no flags/scores); **AI-summary label + per-line sources must stay**. |
| 22 | Allergy Alert | `AllergyBanner` in Chart/PWA | `clinical.chart` | `allergies[]` | Component/state; keep prominent; no interpretation. |
| 23 | Lab Result Review | `Clinical/OrdersReview` | `clinical.orders.worklist` | orders/results worklist | **Confirm scope** (task hinted lab out-of-scope; backend is built). |
| 24 | Note Editor | `Clinical/NoteEditor` | `clinical.notes.edit` | `note(SOAP)`,`encounter`,`patient`,`template`,`versions`,`actions{save,sign,amend}` | **Sign permanently locks** (keep confirm copy); signed notes **read-only**; amendment history visible. Highest-risk — do last. |
| 25 | Nurse PWA | `nurse-pwa/` app | `POST /api/nurse/*` | offline day-pack, outbox sync | **Separate offline-first app** (not Inertia) — own workstream; keep AES-GCM/idle-wipe model, AllergyBanner, raw vitals. |

**Notes on the order:** steps 1–4 are auth/shell/entry (safest). Steps 5–18 are lists/boards/portal
(read-heavy). Steps 19–24 are the interactive/clinical surfaces (AI, dispatch, chart, note editor) —
highest behavioral coupling, wired last. Step 25 (Nurse PWA) is a parallel, separate build and can run
independently of the Inertia re-skin.

---

## Bucket 2 summary — clinic backend gaps before those screens can be wired

- **Biggest gap: the entire STAFF billing / AR / payments / reporting UI (16 screens: 15 billing +
  Practice Reporting Hub).** The billing & reporting **domain is built and tested**; what's missing is
  the presentation layer (controllers + routes + Inertia pages), plus a deferred PSP/Stripe integration
  for actual payment capture (Take Payment / Failed Payment). This aligns with the current-focus note
  that the next build (CH billing) is the unlocked-next-step. **Genuinely-missing domain within
  billing: Payment Plan (installments) and an explicit Credit Note model** — the rest is presentation-only.
- **Scheduling (8):** Appointment Detail, Provider Availability, Waitlist Management, Recall Due List,
  No-Show Follow-Up, Reminder Sent Confirmation, Service Catalog, Service Create — all have domain/services;
  need pages (Waitlist even has its POST actions wired already).
- **Clinical (6):** Consult Summary, Care Plan Review, Referral Out, Lab Imaging Order all have domain
  (data already surfaces in Chart) and need dedicated pages; **Medical History Intake and Patient Flow
  are the two with the thinnest backend** (no dedicated intake questionnaire / flow-board service).
- **Staff telehealth (2):** domain built (patient join works); needs staff sessions/join pages.
- **Portal onboarding / consent-request (6):** invite/accept + password-reset + consent-request flows —
  backend present or partial, pages absent.

---

## Reverse gap — built pages with NO prototype design (design owed)

Six built Inertia pages have **no matching prototype screen** to re-skin (they either keep current UI or
need design before the pack is "complete"):

- `Clinical/Snippets.vue` (`clinical.snippets.index`) — text-snippet/template admin
- `Clinical/OrderableItems.vue` (`clinical.orderable-items.index`) — orderable-item catalog admin
- `Import/Index.vue` + `Import/Upload.vue` (`import.index` / `import.create`) — data-import wizard
- `Nursing/Competencies.vue` (`nursing.competencies.index`) — nurse competency matrix
- `Admin/Kiosks.vue` (`admin.kiosks.index`) — kiosk-device issuance/management (distinct from the
  patient-facing `Kiosk Check-in` screen, which *does* have a prototype)

---

## SCOPE OF DELIVERY #1 — explicit count

- **Prototype screens found:** **117** (116 named + `index.html`).
- **Wire-ready now (clinic + shared) = TRUE SCOPE OF DELIVERY #1: 25 prototype screens**
  = **23 Bucket-1 clinic** + **2 wire-ready Bucket-4 shared** (Auth Screens, Landings).
  These map onto **~30 built Vue pages** + the Nurse PWA and need only re-skin + prop/action binding.
- **Bucket totals:** B1 = 23 · B2 = 38 · B3 = 30 · B4 = 26 → **117**.
- **Per-vertical:**
  - **Clinic:** 23 wire-ready (B1) + 38 gaps (B2) = **61 clinic screens** total in the pack.
  - **Shared/admin (B4):** 26 (2 wire-ready, 4 meta/design-system reference, 20 needing pages).
  - **Non-clinic (B3, out of scope):** 30 = dental 13 · insurance 5 · e-Rx 1 · operator-mode 6 · owner-approval 5.
- **Clinic backend gaps before B2 screens can wire:** predominantly **presentation-layer only** (domain
  built) — the exceptions needing new *domain* are **Payment Plan (installments)**, an **explicit
  Credit-Note model**, **PSP/Stripe** capture (Take Payment / Failed Payment), and the thin-backend
  **Medical History Intake** and **Patient Flow** boards.

---

*Reconciliation notes / judgment calls made explicit:* (a) "Lab Result Review" and "Lab Imaging Order"
were listed by the task as out-of-scope "lab", but a **built** orders backend/page exists — surfaced
as wire-ready/gap with a **confirm-scope** flag rather than silently dropped (backend wins on behavior).
(b) "Client Record" and "Treatment Plan" are tagged by best-fit with an ambiguity flag. (c) Billing
screens are B2 (not "no backend") because the **domain is built and the presentation layer is not** —
this materially lowers their build cost and is called out so delivery estimates are honest.
