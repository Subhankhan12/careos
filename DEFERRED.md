# DEFERRED.md

Deliberately deferred work. Not forgotten — parked until the right phase.

> **READ THIS FIRST (state as of `c086de5`, OPMODE.G3, 2026-08-17).** The BUILD is complete — eight verticals,
> all six hospital phases, three clean QA audits — the **nine-page wireframe-parity pass is CLOSED**, and the
> **Operator Mode SECURITY CORE (G1–G3) is DONE**, which closed a live super-admin containment gap.
>
> **THE BUILDABLE WORK, IN PRIORITY ORDER:**
>
> | # | Track | State |
> |---|---|---|
> | **(a)** | **DEPLOYMENT to the paying customers** | **THE REAL NEXT VALUE.** Runbook + `.env` template + rehearsed onboarding ready, and **first-customer provisioning now exists** (`plans:seed` / `tenant:create` / `tenant:add-admin`, D-165) — the readiness verdict is **🟢 GO**. ⚠️ An **undiagnosed staging error** is still parked — expect to reproduce it first. |
> | **(b)** | **Waitlist Management** | AUDITED (`docs/wireframe-parity/WAITLIST-MANAGEMENT-DIFF.md`), **fix chain NOT started**. Blocker: nothing can add anyone to the waitlist today. |
> | **(c)** | **Operator Mode G4–G11** | **DELIBERATELY DEFERRED to post-first-customer (D-164)** — operator convenience UI. Backend inert, no HTTP surface. **NOT unfinished by accident.** |
> | **(d)** | Two optional Appointment follow-ons | APPT.P4 room-capability field · APPT.P5 preferred-practitioner filter. Real backend gaps the page honestly omits. |
> | **(e)** | ⚠️ The password-policy **decision** | Not a defect and not a build task — needs the product owner. |
> | **(f)** | The certified-partner seams | Drug-safety · HL7/FHIR · PACS/DICOM · anaesthesia device-data · triage acuity. Business conversations, not gates. |
> | **(g)** | The earlier parked items | Everything below, each with its TRIGGER. |
>
> **If asked "what's next?", the answer is DEPLOY.** The security-critical work that was worth doing ahead of
> deployment is done. Do not invent a vertical or a parity page.
>
> **Nothing else in this file is queued work.** Every remaining item is one of four things:
> 1. **A certified-partner seam** — wired as a null-object so a partner can drop in; never a homemade engine.
> 2. **A permanent medical-device NON-GOAL** — do not build the homemade version, ever, at any trigger.
> 3. **Demand-driven parked work** — build it when its stated TRIGGER fires, not before.
> 4. **⚠️ An open PRODUCT DECISION.** Two are outstanding, neither a defect nor a build task: **the password
>    policy** (see the wireframe-parity section) and **whether an "all patient records" operator scope is ever
>    permitted** (see the Operator Mode section at the end; currently fail-closed — no wildcard exists).


- Migrate/validate DEV database to **MySQL 8** before production (MariaDB 10.4 is EOL; prod
  target is MySQL 8).
- Upgrade to **Laravel 13 / PHP 8.3+** when convenient (PHP 8.2 security support ends
  ~Dec 2026).
- **Broader accessibility sweep (dense grids) — non-blocking, from the QA re-audit
  (`docs/FULL-SYSTEM-QA-REPORT.md`).** A11Y.1 (D-148) fixed the two named Low findings (patient-360
  heading outline; dental-chart select accessible names). A full keyboard/focus/contrast/ARIA audit of
  the dense grids (odontogram, perio, ward board, ED board, eMAR) remains a separate pass. **Trigger:** an
  accessibility requirement from a customer/procurement, or a dedicated a11y hardening pass before a
  public-sector deployment.
- **Realistic-volume load / performance test — non-blocking, from the QA re-audit.** The audits ran on
  modest demo volume (no N+1 symptom observable, but not a load assessment). **Trigger:** before onboarding
  a high-volume customer, or the first sign of a slow board/worklist under real data.
- **Full per-widget timezone *display*.** W8b stores and normalizes the tenant/branch timezone; per-widget
  rendering in that timezone is still pending (dates use the shared `formatDateOnly` local-midnight helper —
  FIX.3/D-091 — so no date-only shift, but times are not per-widget tz-rendered).
  **TRIGGER:** a customer operating across more than one timezone.
  *(Previously referenced by PROJECT-STATE as "in DEFERRED.md" without actually being listed here.)*
- **Resource-availability admin screen.** Flagged in CLINIC.W8c: rooms/chairs/resources have CRUD, but editing
  their availability windows has no admin UI (seeded/managed at the data layer).
  **TRIGGER:** a customer needs to self-manage resource availability.
  *(Previously referenced by PROJECT-STATE as "in DEFERRED.md" without actually being listed here.)*
- ~~**Inpatient/pharmacy/surgery demo seeders (a `DemoHospitalSeeder`).**~~ **BUILT** — `DemoHospitalSeeder`
  (Klinik Bergblick) ships a coherent, reconciling dataset for the six hospital verticals including the composite
  ED→inpatient episode. FOUR demo seeders now exist: Clinic, Spitex, Dental, Hospital. No longer deferred.
- **Staff rostering / shift planning.** Not built and not planned: CareOS schedules APPOINTMENTS and home-care
  VISITS, not staff shifts. `ResourceAvailability` + branch opening hours cover who/what is bookable when.
  **TRIGGER:** a customer needs real shift rostering — treat it as a new vertical and map it first.
- **Voice receptionist.**
- **Route optimization** (OR-tools).
- ~~**MAR** (medication administration record).~~ **BUILT** as the eMAR in **PHARMACY.G3** (append-only
  `medication_administrations`, given/held/refused, the safety seam at the administration point). No longer deferred.
- **Clinician countersigning for nurse observational visit notes.** E.7 stores nurse visit notes as
  visit execution documentation, not signed/locked SOAP clinical notes; countersign workflow comes later.
- **E-prescription rails** per market.
- **Lab HL7/FHIR feeds.**
- **US X12 claims** via clearinghouse.
- **WhatsApp channel.**
- **SMS / WhatsApp appointment reminder drivers.** C.5 adds the provider-free reminder channel
  interface and email implementation only; external SMS/WhatsApp providers come later.
- **Expanded AI prompt eval harness.** C.7 adds a minimal prompt registry eval-passed gate; richer
  offline/fixture evals come before real agent prompt rollout.
- **Production vector search for KB RAG.** C.8 stores portable vector-as-JSON embeddings and scores
  cosine similarity in PHP; ANN/vector indexes can replace this when the stack chooses a portable
  search backend.
- **Agent UI surfaces.** C.8 is backend + tests only; approval-queue and KB admin screens come in a
  later UI gate.
- **Qualified e-signature.**
- **Meilisearch** swap for FULLTEXT.
- **Silo tenancy tier.**
- **SSO / SAML.**
- **White-label.**
- **US EVV state aggregator exports.**
- **List-B AI** (partner-first).
- **Capacitor wrappers.**
- **Payroll connectors.**
- **Multi-tenant same-email membership** (one human belonging to several tenants).
  Users carry a single nullable `tenant_id` and email is globally unique for now
  (introduced in P0A.G2).
- **Least-privilege DB user for `audit_events`.** Production should run the app under
  a DB user with UPDATE/DELETE revoked on `audit_events` (defence in depth). Dev uses
  root, so the append-only BEFORE UPDATE/DELETE triggers are the active guard now
  (introduced in P0A.G6).
- **Schedule `audit:ensure-partitions`.** Wire it into the scheduler once the scheduler
  is set up, so upcoming monthly partitions are always provisioned (P0A.G6). *(Still the ONE
  scheduler item not yet wired — `audit:ensure-partitions` is absent from `routes/console.php`.)*
- ~~Schedule `credentials:refresh-status` / `nursing:materialize-visits` / `billing:dunning-run`.~~
  **DONE** — the scheduler is now wired in `routes/console.php`; **9 commands** run on their intended
  cadences (`audit:verify-chains`, `credentials:refresh-status`, `nursing:materialize-visits`,
  `clinical:evaluate-recalls`, `hospital:accrue-bed-days`, `billing:dunning-run`, `billing:reconcile`,
  `appointments:dispatch-reminders`, `scheduling:expire-waitlist-offers`), each overlap-guarded — asserted
  by `ScheduleRegistrationTest`. No longer deferred.
- **Validate patient name search parity before production.** Dev MariaDB 10.4 uses plain FULLTEXT
  while MySQL 8 CI/prod uses `WITH PARSER ngram` - patient name search tokenizes differently
  across environments (P0B.G3).
- **Drug interaction / allergy class / dose / CDS engines are medical-device territory.**
  Drug-interaction checking, allergy class inference, dose calculation, and clinical decision
  support require a partner-first licensed drug database and a funded regulatory track; do not
  build these in-house as CareOS deterministic clinical-list logic (P0D.G3).

## Parked — build when a real user/customer creates the need

Demand-driven backlog. These are deliberately NOT built yet: building them speculatively would add
surface, cost, and risk before anyone needs them. Each carries a TRIGGER — the concrete signal that
should pull it forward. When a trigger fires, the item graduates from parked to planned; until then,
it stays here so it is neither forgotten nor pre-built (P0P.G5).

- **Phase H agents (full RAG front-desk, ops-analyst, onboarding agent).** The Phase C/D/E/F/G agents
  are deliberately narrow (KB-only front-desk, extractive clinical summary, draft-only inbox, etc.);
  the fuller agents are a distinct phase, not an extension of these.
  **TRIGGER:** design partners ask for one, or a repeated manual pain a specific agent would remove.
- **AI-credits metering & billing for AI usage.** `ai_interactions` already ledgers every call and the
  budget gate already caps spend; turning that into metered, invoiced credits is a separate product
  decision, not wired in.
  **TRIGGER:** a paying customer PLUS a decision to charge for AI.
- **Real routing for nurse travel (replace the straight-line estimate).** E.3 uses deterministic
  straight-line distance / configurable average speed only; road-network routing and traffic-aware
  feasibility are not built.
  **TRIGGER:** a nurse reports the straight-line estimate is wrong in practice.
- **Statutory market packs (DE / CH / FR billing specifics).** EU-Generic is live and reconciles to the
  unit; per-country tariff/VAT/export specifics (e.g. DATEV columns) are packs added on demand.
  **TRIGGER:** a signed or serious prospect in that country.
  **CH pack — sharpened trigger (discovery):** Swiss Spitex reimbursement is probably NOT cash-pay but
  KVG/KLV insurance (Krankenkassen) + canton/municipal contributions + patient co-pays — a distinct
  third billing model the built EU-Generic pack does NOT cover. **TRIGGER:** discovery confirms (via
  coordinator calls) that Spitex billing runs through KVG/Krankenkassen + canton — then build the CH
  billing/reimbursement pack when a design partner needs real Spitex billing. This is currently a
  hypothesis (current KVG rules unverified), but it is the deferred item most likely to become the
  real first build. See `docs/DISCOVERY.md` + `PROJECT-STATE.md` CURRENT FOCUS.
- **Cross-tenant CareOS referrals (explicit share objects, never scope-widening).** D.5 records external
  referrals by provider name only and same-tenant internal referrals by `to_branch_id`. Never widen
  tenant scope to reach another CareOS tenant; design explicit share objects first.
  **TRIGGER:** two customer tenants that need to refer to each other.
- **Telehealth recording + transcripts.** D-G2: recording is disabled at the provider level, no
  media/recording columns exist, and grants pin `recorder=false`. Enabling recording or transcripts
  requires a funded consent + retention design first — never switch it on without one.
  **TRIGGER:** a customer requirement AND a completed consent/retention design (do NOT enable without
  both).
- **Realtime (Laravel Reverb) for inbox / day-board.** The unified inbox (G.3), reception day-board
  (C.6), and telehealth presence indicators (D-G2 writes join/leave rows already) all poll or refresh
  on demand today; websocket push joins one future realtime work item.
  **TRIGGER:** polling latency becomes a real complaint.
- **Multi-language content (fill i18n beyond English).** The i18n scaffolding exists; the clinical/UI
  copy is English only.
  **TRIGGER:** a customer/market in that language PLUS a native reviewer for clinical copy — do it
  AFTER the design pass so the strings are stable.
- **Payment processing in the portal (PSP).** G.5 shows invoices and open balances read-only; taking
  payments online needs a PSP integration and reconciliation wiring into the F.5 payment ledger.
  **TRIGGER:** customers want patients to pay online PLUS a chosen PSP.
- **Playwright transport-layer offline test.** The airplane-mode exit proof is a Laravel API
  end-to-end test plus the PWA Vitest encryption/offline suite; browser `context.setOffline(true)` is
  not installed in this repo.
  **TRIGGER:** pull forward when prepping the sales demo.
- **Real HL7/FHIR lab connectivity (electronic transmission + automated result ingestion).** P0P.G11
  ships structured clinical orders + MANUAL results only; `Clinical\Contracts\LabConnectivity` has a
  single `ManualLabConnectivity` (transmit is a no-op, no live ingestion). A real HL7/FHIR client is
  partner-and-market work — no lab is connected, no proprietary/licensed test catalog is bundled (the
  orderable list is tenant-authored). Never interpret a result even when auto-ingested (the electric
  fence holds).
  **TRIGGER:** a customer using a specific lab AND a funded integration build against that lab's
  interface (plus, if any coded catalog is required, a licensed source for it).

## Dental vertical — later gates + partner-gated long poles (post-G8)

The general-dentist feature set (DENTAL.G1–G8) is built. The following are parked:

- **Live dental imaging capture (X-ray sensor / intraoral scanner) + DICOM/PACS.** DENTAL.G8 ships
  MANUAL upload + a 2D viewer + a dentist-authored reading over the existing private document storage.
  Live capture needs the vendor device SDK/driver; DICOM/PACS is its own integration.
  **TRIGGER:** a customer with a specific sensor/scanner AND a funded integration against that vendor.
- **3D scan overlay / scan-comparison (ortho/aligner progress).** Needs a 3D compute pipeline + the
  scanner import path; out of scope for the 2D day-one viewer.
  **TRIGGER:** an ortho/aligner customer AND the scanner integration above.
- **AI radiology / caries detection on dental images. NON-GOAL (electric fence + regulated device).**
  The G8 viewer deliberately has no AI/CV: no caries detection, no pathology flagging, no auto-findings,
  no overlay. Never build the homemade version; a regulated CADe/CADx device is a partner product, not
  a CareOS feature. The dentist reads the image; the system records what they wrote.
  **TRIGGER:** none for a homemade version (do not build). A certified partner device is a separate
  commercial/regulatory decision.
- **Licensed dental code sets (ADA CDT procedures / ICD-10 / SNODENT diagnoses).** The dental procedure
  catalog (G3) and the diagnosis pick-list (G7) are TENANT-AUTHORED — no licensed coded set is bundled.
  **TRIGGER:** a customer requires a specific coded set AND a license for it (then load it as tenant
  data, still not bundled in the repo).
- **Later dental gates (renumbered — DENTAL.G9 was used for demo-readiness: navigability +
  `DemoDentalSeeder` + audit cosmetics, see D-107): chair-view (reuse of the resource/day-board),
  sterilization/inventory, ortho/aligner tracking.** Specialist/operational features beyond the
  general-dentist set. **TRIGGER:** a dental customer whose workflow needs them.

## Hospital verticals — remaining phases + certified-partner seams + medical-device non-goals

The phased hospital build (for a committed mid-size general-hospital buyer) is **COMPLETE — ALL SIX phases
have shipped**: **Phase 1** (inpatient/ADT, HOSPITAL.G1–G7) · **Phase 2** (pharmacy, PHARMACY.G1–G5) ·
**Phase 3** (lab/LIS, LAB.G1–G6) · **Phase 4** (radiology/RIS, RAD.G1–G5) · **Phase 5** (OR/surgery,
SURGERY.G1–G5) · **Phase 6** (ED, ED.G1–G6). Each vertical was MAP-FIRST (a reconciliation/scope map before
code: `docs/HOSPITAL-PHASE1-ADT-MAP.md`, `-PHASE2-PHARMACY-MAP.md`, `-PHASE3-LAB-MAP.md`,
`-PHASE4-RADIOLOGY-MAP.md`, `-PHASE5-SURGERY-MAP.md`, `-PHASE6-ED-MAP.md`).

**THERE ARE NO HOSPITAL PHASES LEFT TO BUILD, and no verticals left to build anywhere.** What remains below
is PARTNER/INTEGRATION-gated or a permanent non-goal — never code-gated.

**The phases, and the certified-partner feed each still lacks:**
- **Lab (Phase 3) — BUILT (LAB.G1–G6).** The manual LIS shell is built end-to-end: tenant-authored test
  catalog, lab order (reuses the Clinical `Order`), specimen tracking (net-new), manual result entry (reuses
  `OrderResult`; reference range DISPLAYED, no computed abnormal flag), review worklist, billing (reconciles).
  The ONLY remaining piece is **LAB.G7 — the HL7/FHIR/analyzer FEED**, the `LabConnectivity` →
  `ManualLabConnectivity` certified-partner seam (transmit is a no-op; automated ingestion throws today; a
  homemade HL7 client = not built). The electric fence holds: never interpret a result, even auto-ingested.
  **TRIGGER:** a customer using a specific lab/analyzer AND a funded HL7/FHIR integration.
- **Radiology (Phase 4) — BUILT (RAD.G1–G5).** The order-form-with-no-image shell is built end-to-end:
  tenant-authored exam catalog, imaging order (reuses the Clinical `Order`), the net-new `ImagingStudy`
  (accession + legal state machine) + modality worklist, the radiologist REPORT (reuses the sign-and-lock
  `ClinicalNote` — AUTHORED prose), report routing, billing (reconciles). The ONLY remaining piece is **RAD.G6
  — the DICOM/PACS/modality FEED + diagnostic viewer**, the CREATED `ImagingConnectivity` →
  `NullImagingConnectivity` certified-partner seam (null no-op today; image storage/streaming/a diagnostic
  viewer is a vendor product, not a CareOS feature). The radiologist authors the report; the system records it;
  AI radiology/CAD = HARD non-goal. Also deferred: the optional uploaded still (dental `DocumentService` — a
  limited manual export). **TRIGGER:** a customer with a PACS AND a funded DICOM integration.
- **ED / Emergency Department (Phase 6) — BUILT (ED.G1–G6).** The ED board is built end-to-end over the
  existing scheduling/ADT/clinical spine: the `EdVisit` flow entity + ED RBAC, triage (nurse-assigned acuity
  + raw vitals), the tracking board (flow facts), ED clinical documentation (reuses Clinical, Encounter
  unmodified), disposition + the ED→ADT handoff (admit reuses `AdmissionService` → an inpatient `Stay`,
  atomic), and ED billing (reconciles; the composite emergency→inpatient episode). **The fence line held at
  TRIAGE: acuity is a clinician-ASSIGNED value (record-not-judge), never a CareOS-computed triage score** —
  the triage-acuity seam stays empty and no homemade ESI/CTAS/MTS algorithm exists. Nothing remains here.

**Certified-partner SEAMS (null-object today; advisory + human-owned; incapable of auto-blocking by design).**
Each is threaded through the built code as a Null implementation so the wiring is proven and a certified
partner can drop in later — never a homemade clinical/safety engine:
- **Medication-safety engine (drug interaction / dose / contraindication) — pharmacy.** BUILT as a seam:
  `MedicationSafetyProvider` (null-object) is called at medication ordering (PHARMACY.G2) and administration
  (PHARMACY.G3); it surfaces a partner's findings, never auto-blocks. The certified drug-database engine is a
  partner product. **TRIGGER:** a licensed drug-database partner AND a funded regulatory track. (Supersedes /
  consolidates the earlier "Drug interaction / allergy class / dose / CDS engines are medical-device
  territory" note above — the seam now exists; only the certified engine is deferred.)
- **Lab HL7/FHIR connectivity — Lab Phase 3.** `ManualLabConnectivity` is the Null seam (see above).
- **PACS/DICOM imaging — Radiology Phase 4.** No image-storage/streaming integration exists; a partner product.
- **Anesthesia intra-op device-data feed — surgery.** Anesthesia DOCUMENTATION (the ASA record, op notes) is
  built; the intra-op device-data feed (anesthesia machine / patient monitor — HL7/device ingestion) is a
  partner seam, noted + stubbed, NOT built (a `Modules\Surgery\src` grep asserts no `DeviceFeed`/
  `AnesthesiaMachine`/`hl7` code). **TRIGGER:** a customer with specific anesthesia devices AND a funded
  integration.

**WIREFRAME-PARITY PASS — deferred items surfaced this pass (see D-149, `docs/wireframe-parity/*.md`).**
- **A real SMS notification-preference provider (Admin Settings P5 seam).** SETTINGS.P5 built the email
  notification-preference store + gate and rendered SMS as an HONEST SEAM (a disabled/"coming" control, no
  provider wired). A real SMS transport for notification preferences is deferred (distinct from, but adjacent to,
  the older "SMS/WhatsApp reminder drivers" item). **Trigger:** a customer needs SMS notifications AND a chosen
  provider/contract.
- **`fence-refused` as a countable `AgentAction` status — RESOLVED (APPROVAL.P5).** A fence refusal at approve
  time (a handed-off draft throwing) is now recorded as a terminal `AgentAction::STATUS_FENCE_REFUSED` (append-only
  `fence_refused` ledger row + audited event + the fence's reason), WITHOUT changing when the fence fires
  (`FenceRefusalException` is a subclass of `AiCoreException`; the eval is untouched). The stat strip's fence count +
  the resolved `fence_refused` category now count real records. (A pre-draft clinical refusal still writes a
  `refused` ledger row + handoff and creates no action — a different, already-ledgered path, not double-counted.)
- **🏁 THE WIREFRAME-PARITY PASS IS COMPLETE — ALL NINE pages, nothing queued.** Admin Settings (SETTINGS.P1–P6) ·
  Approval Queue (APPROVAL.P1–P7, incl. P7 bulk-approve — low-risk only, clinical+financial excluded server-side) ·
  Branches (BRANCH.P1–P5) · Agent & Tool Config (AGENT.P1–P6) · Allergy Alert **safe-part** (ALLERGY.P1 —
  record-display + display-only seam; the computed drug-allergy checking is a certified-partner medical-device
  NON-GOAL, not built) · Billing & AR (BILLAR.P1–P7) · AR Account Detail (ARDETAIL.P1–P6) · Appointment Detail core
  (APPT.P1–P3) · Auth Screens (AUTH-SEC.1 + AUTH-SEC.2 + AUTH-VIS). Each has a resolved
  `docs/wireframe-parity/<PAGE>-DIFF.md`. **Do NOT invent a further parity page.**
- **AR Account Detail — COMPLETE (ARDETAIL.P1–P6).** The three parts once parked here all shipped: **P4** record
  payment through the guarded `PaymentService` (over-allocation refused, append-only, reconciles δ=0 — never a
  page-side balance write) · **P5** the payment-plan model (installments tie to the real outstanding, δ=0;
  operator-created; paid through P4's guarded service) · **P6** Betreibung/debt-enforcement escalation
  (human-operator-only, **agent-EXCLUDED by construction**, eligibility-gated, audited + append-only; the
  `billing.escalate` permission is deliberately NARROWER than `billing.manage`). Nothing remains.
- **Real Swiss QR-bill (IBAN + structured reference payment part) — STILL DEFERRED.** There is NO QR-bill/IBAN
  renderer today (`InvoicePdfRenderer` emits a stub invoice PDF); ARDETAIL.P3 surfaced the existing invoice PDF
  honestly rather than faking a payment part. A real Swiss QR-bill is a backend build.
  **TRIGGER:** a Swiss customer needs real QR-bill payment slips. (Adjacent to the CH/KVG billing pack.)
- **Send-QR-bill / send-reminder FROM the AR account page — STILL DEFERRED (an honest gap, not a parity failure).**
  Sending stays inside the existing idempotent `DunningService` + the agent-cap/ApprovalQueue path; the page does
  not grow its own send button. **TRIGGER:** a customer workflow needs page-initiated sending — design the
  idempotency + the agent cap first.
- **Appointment Detail — CORE COMPLETE (APPT.P1–P3); TWO OPTIONAL BACKEND FOLLOW-ONS PARKED.** Both exist because
  the page honestly OMITTED something the wireframe drew rather than fabricating a backend; neither blocks anything:
  - **APPT.P4 — a room-capability field.** The wireframe drew "scanner · X-ray" capability chips; `Resource` has no
    capability field, so the page exposes only `{id,name,type}`. **TRIGGER:** a customer needs capability-based
    room selection (then model the capability dimension).
  - **APPT.P5 — a preferred-practitioner slot filter.** The wireframe drew a "Dr. Weber only" toggle;
    `AvailableSlotFinder` takes NO preferred-resource parameter, so offering it would fabricate a filter the engine
    cannot honour. **TRIGGER:** a customer needs to book against a preferred practitioner (then extend the finder).
- **Auth screens — the parity items are RESOLVED; the pass's two High LIVE SECURITY DEFECTS are FIXED.** The auth
  audit is the pass earning its keep: **AUTH-SEC.1** closed a standing second-factor bypass (a session restored
  from the remember-me recaller reached the app with no 2FA challenge — it is now re-challenged; D-158) and
  **AUTH-SEC.2** fixed `/forgot-password` + `/reset-password/{token}` returning HTTP 500 with no view bound,
  **plus the coverage gap that hid it** — every route smoke authenticated first, so no PUBLIC page had ever been
  requested; the smoke now drives guest routes (D-159). **AUTH-VIS** then added the enrolment manual-secret
  fallback rendering the user's OWN real secret (D-160). Nothing auth-related is queued.
- **⚠️ OPEN PRODUCT DECISION — the password policy (NOT a defect; awaiting an explicit decision).** The effective
  policy is `Password::default()`: a minimum of **8 characters**, with no `Password::defaults()` configured
  anywhere — so **no mixed-case, digit, symbol or breach check**. The reset flow correctly enforces whatever is
  configured; deciding what it *should* be is a product call and was deliberately NOT slipped into a security
  sprint. **TRIGGER: a decision from the product owner** (or a customer/procurement password requirement). This is
  the ONE item the nine-page pass deliberately left open.
- **The escalate-below-confidence THRESHOLD (AGENT.P6) — honestly deferred, deliberately not faked.** The
  uncertainty escalation itself is **ALWAYS-ON and un-removable** (no disable route; a forged
  `disable_escalation`/`confidence_threshold` body is dropped because the agent has no such attribute). A NUMERIC
  threshold is deferred because **the codebase has no confidence/uncertainty signal at all** (grep-confirmed) — a
  threshold control would be a phantom, so the UI shows it as "planned" rather than wiring a lie. A threshold could
  only ever tune WHEN the escalation fires, never remove the floor. **TRIGGER:** a real confidence/uncertainty
  signal exists to threshold against.
- **Finer Swiss payer taxonomy (BILLAR.P4 gap).** `arByPayer` groups over the REAL modeled `payer_type` (self_pay /
  private_insurance). The wireframe's finer 4-way Swiss split (supplementary / accident SUVA-UVG / social-municipal)
  + an insurer entity are NOT modeled and NOT fabricated. **Trigger:** a customer needs the finer split (then model
  the payer dimension). Adjacent to the CH/KVG billing pack.
- ~~**The remaining decoded wireframe pages (after AR Account Detail): Appointment Detail · Auth Screens.**~~
  **DONE — both shipped (APPT.P1–P3, AUTH-SEC.1/.2 + AUTH-VIS). No decoded page remains; the pass is closed.**

**Medical-device NON-GOALS (never build the homemade version — regulated-device territory, electric fence).**
These are permanent non-goals for CareOS-authored code; only a certified partner product may provide them:
- Homemade **drug-interaction / dose / contraindication checking** (pharmacy) — partner engine only.
- **AI radiology / caries / pathology detection** on any image (radiology, dental imaging) — CADe/CADx is a
  regulated device; the clinician reads, the system records what they wrote.
- **Computed perio staging/grading** (dental) — perio charting stores per-site raw measurements only.
- **Computed surgical-risk / early-warning scores** (surgery, inpatient — e.g. a homemade NEWS/MEWS/EWS or a
  surgical-risk predictor). ASA/Mallampati are clinician-ASSIGNED facts; no acuity/risk/score column, no
  auto-computation. A blocking safety checklist is likewise a non-goal — the WHO checklist RECORDS completion,
  it never gates the case.
- **Computed triage acuity** (ED Phase 6) — acuity is clinician-assigned, never algorithm-computed.
- **AI in the clinical-decision path anywhere** — a diagnosis/finding is clinician-authored; AI stays in the
  ops/admin lane (draft-until-approved, autonomy-capped). **TRIGGER for all of the above: none for a homemade
  version (do not build).** A certified partner device is a separate commercial/regulatory decision.


## (b) Waitlist Management — AUDITED, fix chain NOT STARTED

A genuine parity page, decoded and triaged **after** the nine-page pass closed (a tenth page, triaged
separately — it does not reopen that pass). Audit: `docs/wireframe-parity/WAITLIST-MANAGEMENT-DIFF.md`.

**~70% of it renders an already-rich backend** — `waitlist_entries` + `waitlist_offers`, ranking
(priority then longest-waiting, exactly the wireframe's sort), offer expiry + the scheduled sweeper, the
consent gate, the agent tool, and an accept path that goes through the real no-double-book
`lockResource`/`assertNoOverlap`.

**DECIDED SCOPE when a chain is issued:**
- **BUILD** the standing waitlist page (patient-first pool + the freed-slot focal card + the five states).
- **BUILD the add-to-waitlist WRITE PATH — 🔴 THE BLOCKER.** `WaitlistService::create()` has **exactly one
  caller in the entire repo: `DemoClinicSeeder`**. No route, no controller, no UI, no portal self-waitlist.
  Without this the page is a viewer for seeded rows.
- **OMIT the auto-send tier.** The wireframe claims *"routine offers can go automatically"*; the real tool
  ceiling is `AutonomyPolicy::APPROVE` — `AUTO` is unreachable. **The agent never auto-sends.**
- **OMIT / seam SMS + phone.** `waitlist.offer` is `CHANNEL_EMAIL` only (the standing SETTINGS.P5 seam).
- **RECORD AS A GAP:** the richer preference model — preferred days, time-of-day bands, earliest acceptable
  date, short-notice flag, per-entry channel selection, note. None are modelled; until they are, the table's
  "WILL ACCEPT" column can only show what the backend really holds.

**TRIGGER:** after deployment, or a customer whose reception workflow needs it. **Priority: below DEPLOY** — it
is workflow convenience, not a security or correctness gap.

## (c) Operator Mode — G4–G11 DELIBERATELY DEFERRED (post-first-customer)

**⏸️ PAUSED ON PURPOSE (D-164) — not unfinished by accident, and not blocking deployment.**
Per-feature detail: `memory/modules/OperatorMode.md`. Plan: `docs/features/OPERATOR-MODE-MAP.md`.

**DONE and LIVE-SAFE — G1–G3**, which shipped a **real security fix**: `Gate::before` and
`PermissionService::has()` both returned an unconditional `true` for any super-admin, and the only thing
containing them was never being given a tenant context — **containment by accident**. A super-admin now reaches
tenant data only through an ACTIVE, UNEXPIRED, IN-SCOPE, IN-TIER, owner-approved `OperatorGrant`, fail-closed at
both former bypass points and regression-guarded (D-161). **G2** pinned *requesting is not granting* (D-162);
**G3** pinned *the owner is the gate* (D-163). 40 tests.

**DEFERRED — operator-facing convenience UI, to be built after the first customer is live:**
- **G4** — elevated-session mechanics (grant-derived tenant context, per-record scope checks at access time,
  per-access audit rows, the banner's server-supplied countdown).
- **G5** — mid-session revoke (instant) + the expiry sweep + the session receipt (pages viewed, a real
  "0 changes" determination).
- **G6–G11** — the ~7 operator/owner screens (console · enter-confirm/active/ended · request + waiting-on-
  approval + owner notification · decision/granted-read-only/declined/expired · elevated/extension/revoked ·
  the tenant-side Patient Access Log operator rows).

**They add no safety property G1–G3 do not already enforce.** Consequences of the pause, stated plainly:
**no HTTP route, no controller, no UI** — the backend is inert but correct and tested; nothing operator-related
is scheduled (deliberate: with no surface there are no live requests to sweep). A feature that cannot be invoked
cannot be exploited.

**SETTLED:** `configuration` requires owner approval (D-162) · the owner IS the tenant's `org_admin` (D-163) ·
the chain pauses after G3 (D-164).
**⚠️ STILL OPEN — answer before G4 if it might be "yes": is an "all patient records" scope ever permitted?**
Currently **FAIL-CLOSED — no wildcard exists in any form** (`*`, `all`, `ALL`, `any`, `%`, empty lists and blank
ids are all refused), so the only way to reach a record is to have named it.
**Also open (non-blocking):** who may be an operator (today: any user with `tenant_id = null`) · the
request/session windows and any extension cap · an emergency no-owner-reachable path (and if there is to be
none, say so explicitly so nobody improvises one) · whether `BreakGlassGrant` keeps its own self-grant model.

**TRIGGER: the first customer is live** — then resume at **G4**, reading the MAP first.

## Waiting On Approval — NOT a parity page (triaged, folded in)

Decoded alongside Waitlist Management and determined **not** to be a parity page: it is **one screen of the
~13-screen Operator Mode family** (a platform operator waiting on a tenant owner's decision). It shares only the
word "approval" with the completed Approval Queue — zero overlap on every substantive marker (no `agent`, no
`✦` AI badge, no suggest/ceiling/re-authorise/re-ground/fence). **Folded into the Operator Mode MAP** as part of
G6–G11; it is not a separate backlog item.

## Pre-deploy SHOULD-FIXes (from DEPLOY-READINESS-CHECK.md — non-blocking)

The four BLOCKERS (M1–M4) were closed by DEPLOY.PROV (D-165). These two remain, deliberately:

- **S1 — `audit:ensure-partitions` is not scheduled.** `audit_events` is RANGE-partitioned monthly, but the
  maintenance command that extends the partitions is absent from `routes/console.php`. **It degrades rather
  than fails:** a `p_max VALUES LESS THAN (MAXVALUE)` catch-all absorbs every row past the last real partition,
  so inserts keep working — what is lost is the monthly partitioning benefit (pruning, retention by partition,
  query locality), which silently accumulates into one growing partition.
  **TRIGGER:** before go-live, or the first time audit retention/pruning matters. One scheduler line.
- **S2 — the four demo seeders carry no production guard.** No `App::environment()` check, no abort.
  **Mitigated:** they are NOT in `DatabaseSeeder` (pinned by a test in `ProvisioningCommandsTest`), so
  `db:seed --force` is safe and required in production; a demo tenant can only appear if someone explicitly
  types `--class=Demo…`. The residual risk is human error — copying the runbook's §10 demo line onto a
  customer host. **TRIGGER:** cheap hardening whenever convenient — add an
  `app()->environment('production')` refusal to each demo seeder.
