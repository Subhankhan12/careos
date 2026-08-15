# DEFERRED.md

Deliberately deferred work. Not forgotten — parked until the right phase.

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

The phased hospital build (for a committed mid-size general-hospital buyer) has shipped **Phase 1
(inpatient/ADT, HOSPITAL.G1–G7)**, **Phase 2 (pharmacy, PHARMACY.G1–G5)**, and **Phase 5 (OR/surgery,
SURGERY.G1–G5)**. Each vertical was MAP-FIRST (a reconciliation/scope map before code:
`docs/HOSPITAL-PHASE1-ADT-MAP.md`, `-PHASE2-PHARMACY-MAP.md`, `-PHASE5-SURGERY-MAP.md`). What remains is
increasingly PARTNER/INTEGRATION-gated, not code-gated.

**Remaining hospital phases (parked — map-first when pulled forward):**
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
- **ED / Emergency Department (Phase 6).** A buildable ED board (arrivals, bays, disposition) over the
  existing scheduling/ADT/clinical spine. **The fence line is TRIAGE: acuity is a clinician-ASSIGNED value
  (record-not-judge), never a CareOS-computed triage score** (no homemade ESI/CTAS/MTS algorithm — a
  computed triage acuity is a medical-device non-goal). **TRIGGER:** a hospital customer whose ED workflow
  needs it (map-first).

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
- **Wireframe-parity pass — SIX pages COMPLETE.** Admin Settings (SETTINGS.P1–P6) · Approval Queue (APPROVAL.P1–P7,
  incl. P7 bulk-approve — low-risk only, clinical+financial excluded server-side) · Branches (BRANCH.P1–P5) · Agent &
  Tool Config (AGENT.P1–P6) · Allergy Alert **safe-part** (ALLERGY.P1 — record-display + display-only seam; the
  computed drug-allergy checking is a certified-partner medical-device NON-GOAL, not built) · **Billing & AR
  (BILLAR.P1–P7)**. Each has a resolved `docs/wireframe-parity/<PAGE>-DIFF.md`.
- **AR Account Detail — IN PROGRESS (the Billing & AR drill-in), remaining parts DEFERRED as their own gates.**
  ARDETAIL.P1 (per-account running-balance ledger) · P2 (dunning timeline, read-only) · P3 (hero + Swiss CHF format +
  status/dunning pills + patient-chart & invoice-PDF links) are DONE. **Remaining:**
  - **P4 — record payment on the account.** Must go through the guarded `PaymentService` (over-allocation refused;
    append-only; reconciles) — never a page-side balance write. **Trigger:** the parity pass reaches P4.
  - **P5 — payment plan.** Wireframe-new; NO installment-plan model exists. A modeled construct (its own gate); no
    page-side money math. **Trigger:** the parity pass reaches P5 (or a customer needs installment plans).
  - **P6 — Betreibung / debt-enforcement escalation.** NO escalation model/action exists. MUST be built
    human-operator-only, **agent-EXCLUDED by construction**, audited + append-only (never an auto-escalation path);
    the agent may only DRAFT a reminder through the existing cap/ApprovalQueue path. **Trigger:** the parity pass
    reaches P6 (a legally-sensitive gate — design the operator gate + agent exclusion + audit first).
  - **Real Swiss QR-bill (IBAN + structured reference payment part).** There is NO QR-bill/IBAN renderer today
    (`InvoicePdfRenderer` emits a stub invoice PDF); P3 surfaced the existing invoice PDF honestly. A real Swiss
    QR-bill is a backend build. **Trigger:** a Swiss customer needs real QR-bill payment slips.
- **Finer Swiss payer taxonomy (BILLAR.P4 gap).** `arByPayer` groups over the REAL modeled `payer_type` (self_pay /
  private_insurance). The wireframe's finer 4-way Swiss split (supplementary / accident SUVA-UVG / social-municipal)
  + an insurer entity are NOT modeled and NOT fabricated. **Trigger:** a customer needs the finer split (then model
  the payer dimension). Adjacent to the CH/KVG billing pack.
- **The remaining decoded wireframe pages (after AR Account Detail): Appointment Detail · Auth Screens.** Same
  decode → audit → per-part-fix loop. **Trigger:** the page-by-page parity pass reaches each page.

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
