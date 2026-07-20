# CareOS — Dental Vertical: Reconciliation + Build-Sequence Map

**Status:** READ-ONLY plan. No code / `.vue` / controller / route / test / migration was changed to
produce this document. It is a **build plan**, produced before writing the dental module, for a paying
**general/family dentist** customer (charting, common procedures, treatment plans, billing) — **not** a
specialist. Dental is a from-scratch clinical vertical (its own `Modules\Dental`, absent today), so this
plans before building.

**Top commit at time of writing:** `fed27f5 docs: full feature/module inventory + classified gap map`.

**How to read this:** the 13 prototype dental screens (`resources/prototype/*`, bucketed B3-dental in
`docs/CLINIC-DELIVERY-MAP.md`) are a **guide, not a spec** — each is verified against a real
general-dentist workflow, and the prototype's AI-diagnosis / AI-overlay / auto-grade features are treated
as **electric-fence concerns to build WITHOUT**, not requirements. Backend wins on behaviour; the fence is
absolute (AGENTS.md).

---

## Section 0 — What CareOS already provides that dental REUSES (the head start)

Dental is a new clinical vertical, but it is **not** greenfield — it sits on the same tested foundation as
the clinic vertical. Reused as-is:

| Existing capability | How dental uses it |
|---|---|
| **Multi-tenancy** (Platform `TenantContext`/`BelongsToTenant`) | Dental practice = a tenant; every dental row is tenant-owned, fail-closed. |
| **Patients** (Patients module) | A dental patient IS a `Patient` (MRN, contacts, coverages, consent, portal). No new patient model. |
| **Scheduling + chairs** (Scheduling + **W8c resources — `chair` is already a resource type**) + day-board | **Chair Scheduling is mostly reuse** — chairs/operatories are `Resource`s under a branch; booking runs through the no-double-book `BookingService`; the day-board already renders resource lanes. |
| **Billing engine** (TariffCatalog/Item, Charge, IssueService, Payment, ReconciliationEngine) | Dental **procedures become chargeable items** via the existing tariff → charge → invoice path; a **fee schedule is a tariff catalog**; a **treatment-plan estimate is a sum of charges**; reconciliation/dunning/PDF all apply unchanged. |
| **Encounters + SOAP notes** (Clinical) | A dental visit is an `Encounter`; procedures/findings are documented in signed notes (sign-and-lock, amendments). |
| **Documents / imaging storage** (Clinical documents — private, controller-streamed, audited) | Radiographs and intraoral scans reuse this private storage + read-logging; dental adds only asset **metadata**. |
| **Structured Orders** (Clinical `Order` + manual result, append-only) | Lab work sent to a dental lab (crown, denture) can reuse the order pattern. |
| **Audit** (append-only, hash-chained) + read-logging | Odontogram/perio changes, procedure records, and the sterilization log are audited/append-only. |
| **RBAC** (custom, tenant-owned) | New dental starter roles (dentist, hygienist, dental assistant) + permissions, same `Gate::before` machinery. |
| **Governed AI + electric fence** (AiCore: draft/suggest-only, grounded, human-approved; P.4 evals) | Dental agent assistance (perio reference, treatment-plan draft, inventory reorder) reuses the **draft-only, human-owned** pattern — never diagnosing. |
| **Design system** (Eucalyptus Glow) + **route smoke / MySQL parity / immutability guards** | The chart/plan/perio UIs re-use the tokens + primitives; new routes ride the existing smoke + parity guards. |
| **Payment plans** | Noted as an existing **(E) gap** in `FEATURE-INVENTORY.md` — the treatment-plan "on a payment plan" needs the installment model, buildable when dental needs it. |

**Consequence:** dental's *new* surface area is the **clinical dental domain** (teeth, perio, procedures,
diagnosis, treatment plans) — not tenancy, patients, scheduling, billing, storage, audit, RBAC, or AI
governance, all of which it inherits.

---

## Section 1 — The 13 dental screens, mapped (reuse vs net-new)

Each screen: what it shows / the workflow it implies → **REUSE** (existing infra) vs **NET-NEW dental
domain** → any **⚠ fence** concern (detailed in Section 2).

| # | Screen | Shows / workflow | REUSE | NET-NEW dental domain | ⚠ Fence |
|---|---|---|---|---|---|
| 1 | **Odontogram** | Per-tooth full-mouth chart; dentition (permanent/mixed/primary); per-tooth surfaces; charted conditions (sound / restoration / crown / implant / missing / fracture / root-canal / planned); tooth history; "add finding / mark restored / plan treatment"; tabs to Perio/Endo/Imaging. **The foundation.** | patients, encounters, audit, design system | **Tooth + surface state model** (FDI/Universal numbering, dentition types, per-surface condition), append-only tooth-history | Record state only; **no auto-caries-detection / no diagnosis** |
| 2 | **Perio Charting** | Full-mouth probing; per-tooth per-site pocket depths (buccal / palatal-lingual), bleeding-on-probing, plaque score, mobility; mean pocket depth; over-time compare; "one site to watch". Agent "pre-loads last chart, flags the site that deepened, **never enters/alters a reading**". | odontogram (tooth model), audit, AiCore draft pattern | **Perio measurement model** (6 sites/tooth: pocket depth, recession, BOP, plaque, mobility, furcation), full-mouth chart, raw time-series | ⚠ **No perio staging/severity grade**; "site to watch"/"flagged" → build as a **raw delta** (3→5), not a judgment |
| 3 | **Crown Prep** | Prosthodontic procedure: material (zirconia), shade, ferrule, anaesthesia, core build-up, reduction (target vs achieved), digital scan, temporary. "Agent reads scan quality"; **"Agent's restoration Rx: proposed monolithic zirconia…"**. | procedures/charges, scans (storage), notes | **Procedure record** (crown) with procedure-specific fields, tooth link, status lifecycle | ⚠ **Agent "restoration Rx" = draft/suggest-only, human-approved**; scan-quality "margin captured" auto-read → human-attested, not auto-graded |
| 4 | **RCT Procedure** | Endodontic workflow: diagnosis carried in; canals (per-canal working length, apex-locator, MB/DB/ML), isolation (rubber dam), access, shaping, obturation, restore; irrigation protocol. "**Agent flag**". | procedures/charges, notes | **Procedure record** (RCT) + per-canal detail (specialist depth — trim for general dentist) | ⚠ Steps/measurements are facts; **"agent flag" built WITHOUT auto-flagging** (human note) |
| 5 | **Endo Diagnosis** | Pain/duration/temp; pulp-sensibility tests (EPT), percussion, palpation, mobility (grade), probing, radiograph findings (radiolucency, widened PDL); **a differential list marked "Confirmed / Ruled out / Less likely"** + **"Agent's proposed diagnosis"**. **The biggest fence concern.** | patients, encounters, notes, audit | **Diagnosis record** (tests as facts + clinician's OWN diagnosis) | ⚠⚠ **DIAGNOSIS = electric fence.** Record tests + **clinician-selected** diagnosis; **NO AI-proposed diagnosis, NO system-ranked differential** ("confirmed/ruled-out/less-likely" is clinician-set, never computed) |
| 6 | **Ortho Progress** | Clear-aligner progress: aligner journey, movement tracking (actual vs planned), tray #, wear time, next tray; actions continue / refine / refer / sign. "What the agent brought / flagged"; "wear time: **Good**". | treatment plan, notes | **Ortho tracking** (aligner series, tray, movement) — **specialist; later/optional** | ⚠ Tray/wear = facts; **"wear time: Good" is a GRADE → record as reported, no grade**; "on/behind track" → raw deviation |
| 7 | **Chair Scheduling** | Chair/operatory day+week grid; book a chair; procedure types (X-ray, sedation, hygiene, ultrasonic); status (booked / in-chair / done); dental-assistant assignment. | **Scheduling + chairs (W8c resources) + day-board — mostly REUSE** | Optional chair-oriented view + **dental-assistant assignment**; procedure-type tags | none (operational) |
| 8 | **Treatment Plan** | **The dental treatment plan:** phased; per-procedure; fee-scheduled estimate (CHF, point-value/Tarifpunkte); insurance cover estimate; billed-progress ("104 of 1,240 billed"); "on a payment plan"; **clinician owns + approves**; agent may draft. | **billing (fee schedule = tariff, estimate = charges)**, patients, portal (acceptance), payment-plan (E-gap) | **Phased treatment-plan model** (per-procedure, per-phase, estimate, acceptance, billed-progress) — **distinct from clinical Care Plans** | ⚠ Agent **draft-only**; plan is **clinician-owned/approved** |
| 9 | **Imaging Viewer** | Radiograph viewer: series, study, dose, prior; **"AI overlay / AI findings — accept / dismiss"; "the model flags, the clinician reads"**; save reading / add to note. | documents/imaging storage, notes | **Imaging asset metadata** (view, tooth, dose) + a **basic 2D viewer** + clinician reading | ⚠⚠ **NO AI overlay / AI findings on images** (radiology interpretation = fence). Show image + **clinician records their reading**; dose is a fact |
| 10 | **Scan Library** | Intraoral 3D-scan library: upper/lower/bite; new capture; compare; newest-first. | documents storage | **Scan asset type** (arch, purpose) + list UI | none (storage/list) |
| 11 | **Scan Upload** | Capture/upload a scan; upper/lower arch + bite registration; per-tooth coverage (well-covered/thin); "uploading to record"; purpose (planned vs actual). | document upload/storage | **Scan capture metadata** (arch, coverage); **live capture from scanner = partner-gated** | Coverage "well/thin" is a scanner metric (fact) not a clinical grade |
| 12 | **Scan Comparison Viewer** | Overlay two scans (ortho progress): deviation per tooth (rotation, buccal translation, vertical), arch tolerance, planned setup, scan history; "rotation **behind plan**". | scans (storage) | **Scan overlay/compare** — **3D compute is scanner-vendor software (long pole)** | ⚠ Deviation numbers = facts; **"behind plan" judgment omitted** or clinician-set threshold |
| 13 | **Inventory & Sterilization** | Sterilization cycle log (autoclave loads, chemical/biological indicators, released / quarantined, "biological indicator positive → quarantine / re-process / **traceability**"); inventory (materials) + "inventory agent drafted a reorder". **Operations, not clinical.** | audit (append-only log), AiCore draft (reorder), recall pattern (traceability) | **Sterilization cycle model** (loads, indicators, released-by, instrument traceability) + **inventory model** — **ops sub-domain, later** | Indicator-positive → quarantine + **who-received-instruments recall** = fact/workflow, not interpretation |

---

## Section 2 — Electric-fence analysis (dental)

The dental chart must **RECORD clinical facts and never interpret, diagnose, or grade** — identical
posture to vitals (D-D3), lab/order results (D-076), and the reporting facts layer (D-080). Dental is
**higher-risk** than the clinic vertical because the prototype leans hard into AI diagnosis/overlays.

**Record-not-judge applies to:**
- **Odontogram** — the system stores the tooth/surface **conditions the clinician charts**. No automated
  caries detection, no "decay severity", no AI reading of the chart.
- **Perio** — pocket depths, recession, BOP, plaque, mobility, furcation are **raw numbers/flags the
  clinician enters**. **No periodontal staging/grading** (no auto AAP/AASD stage, no "severity score").
  A *mean* pocket depth is a permissible neutral aggregate (like averaging a vitals series) but must never
  be presented as a severity grade. Over-time change is a **raw delta** (site went 3 mm → 5 mm), shown as
  a fact — never a "site to watch"/"deteriorating" judgment.
- **Endo Diagnosis** — record the **test results** (EPT, percussion, palpation, mobility, radiograph
  findings) as facts and the **clinician's own diagnosis** as a human attestation. **No AI-proposed
  diagnosis, no system-computed differential ranking.** "Confirmed / ruled-out / less-likely" are
  **clinician-selected labels**, never computed by CareOS.
- **Imaging Viewer** — **no AI overlay, no AI findings** on radiographs/scans. CareOS displays the image
  (dose is a factual field) and records the **clinician's reading**. Machine-vision interpretation of
  medical images is a licensed medical-device product + regulatory track — never homemade, and even then
  behind this fence.
- **Crown / RCT / Ortho agent features** — "agent's restoration Rx", "agent flag", "what the agent
  brought" are permissible **only** as AiCore **draft/suggest-only, grounded, human-approved** proposals
  (the established governed-agent pattern); they **never auto-decide** and **never grade** ("wear time:
  Good" → record the reported wear time, no Good/Bad grade). Scan-quality reads that come from the
  *scanner* (coverage well/thin) are facts; an AI judgment on the scan is out.
- **Scan comparison** — show the **raw deviation measurements**; the "behind plan / on-track" verdict is
  interpretation → omit or make it a clinician-set threshold.

**Prototype features to build WITHOUT the interpretation (explicit):**
1. Endo Diagnosis "**Agent's proposed diagnosis**" + auto-ranked differential — **build the record only.**
2. Imaging Viewer "**AI overlay / AI findings**" — **build the viewer + human reading only.**
3. Perio "**flagged the site that deepened / one site to watch**" — **raw delta, no flag.**
4. Ortho "**wear time: Good**" grade + "behind plan" — **record raw, no grade.**
5. Crown "**Agent's restoration Rx**", RCT "**Agent flag**" — **draft-only + human-approved, never auto.**

This is the same rule that already passes the P.4 eval harness for the clinic agents; the dental agents
must ship with their own `tests/Evals/` locks (fence / suggest-ceiling / grounding).

---

## Section 3 — Dependency-ordered build sequence (proposed gates)

Foundational-first, each gate buildable + testable on its own (the clinic-phase discipline). **The
odontogram data model is the foundation** — the chart UI, procedures, treatment plans, and perio all
depend on the tooth/surface model existing first.

| Gate | Deliverable | Depends on | Notes |
|---|---|---|---|
| **DENTAL.G1** | **Module + tooth/odontogram DATA MODEL (foundation)** — register `Modules\Dental`; per-tooth (FDI numbering, dentition permanent/primary/mixed) + per-surface condition state; **append-only tooth-history**; dental RBAC roles (dentist / hygienist / dental-assistant) + permissions; audit wiring. Backend + tests, minimal/no UI. | Platform, Patients, Audit, RBAC | Everything below depends on this. Fence: record-only. |
| **DENTAL.G2** | **Odontogram chart UI** — interactive full-mouth chart to record tooth/surface conditions + tooth history; reads through G1. | G1, design system | Record facts only; no interpretation. |
| **DENTAL.G3** | **Dental procedure CATALOG + billing integration** — tenant-authored procedure catalog (a **specialized tariff catalog**; **no bundled licensed CDT**); procedures resolve to chargeable `TariffItem`s; the fee-schedule editor (the existing (D) gap) lands here. | Billing (tariff/charge), G1 | Reuses the tariff → charge engine; long-pole: licensed code sets (§5). |
| **DENTAL.G4** | **Dental procedures + workflow** — record the **common general-dentist set** (exam, cleaning, filling, crown, extraction, simple RCT) linked to teeth/surfaces, with a status lifecycle, documented in encounter notes; completion → charge capture via the existing `ChargeCaptureService`. | G1–G3, Clinical (encounters/notes) | Trim specialist depth (advanced endo/prostho) to later. |
| **DENTAL.G5** | **Dental treatment plan** — phased, per-procedure, fee-scheduled **estimate**, patient **acceptance**, **billed-progress**, optional insurance-cover estimate; clinician-owned/approved; distinct from clinical Care Plans. | G1, G3, Billing, Patients/portal | Agent draft = suggest-only (fence). Payment-plan link = the (E) installment gap. |
| **DENTAL.G6** | **Perio charting** — per-tooth/6-site pocket depth / recession / BOP / plaque / mobility / furcation; full-mouth chart; **raw over-time comparison**; hygienist workflow. | G1 (tooth model) | Fence: no staging/grade; delta shown raw. Perio-assist agent = draft-only reference. |
| **DENTAL.G7** | **Dental diagnosis record** (the Endo-Diagnosis screen **minus AI**) — record tests + **clinician-entered** diagnosis; per-tooth/condition. | G1, Clinical | Fence-critical: no AI diagnosis / no computed differential. (Can fold into G4/G6 if lean.) |
| **DENTAL.G8** | **Dental imaging / scans** — radiograph + intraoral-scan asset type with dental metadata (tooth/view/dose), reusing private document storage; **basic 2D viewer + clinician reading**; scan library/upload. **NO AI overlay.** | Clinical documents, G1 | Live device capture + 3D overlay = long-pole/later (§5). |
| **DENTAL.G9** | **Chair scheduling view** — a chair-oriented scheduling view + dental-assistant assignment over the **existing** resource/day-board (chairs = W8c resources). | Scheduling (W8c), day-board | Reuse-heavy; small gate. |
| **DENTAL.G10** *(later)* | **Sterilization + inventory (ops)** — cycle log + indicators + instrument traceability; inventory + agent-drafted reorder. | Audit, AiCore draft | Ops sub-domain; valuable but not clinical-core. |
| **DENTAL.G11** *(later/specialist)* | **Ortho aligner tracking + scan comparison** — aligner series/tray/movement; scan overlay (**3D compute = partner-gated**). | G5, G8 | Specialist; a general dentist often refers ortho out. |

**Rough gate count:** **~8 core gates (G1–G8)** for a credible general-dentist MVP+, foundational-first,
each testable alone; **+3 later/optional** (G9 chair-view is small reuse; G10 sterilization/inventory;
G11 ortho/scan-comparison). The critical path is **G1 → G2 → G3 → G4 → G5** (chart the mouth → catalog →
do procedures → plan + bill); **G6 (perio)** and **G8 (imaging)** parallel off G1.

---

## Section 4 — Day-one MVP vs later (build the core, not all 13 at once)

**Day-one MVP — what a general/family dentist genuinely needs first** (G1–G9 core):
- **Odontogram** (chart the mouth) — the non-negotiable foundation.
- **Common procedures** (exam, cleaning, filling, crown, extraction, simple RCT) + **charge capture** →
  the practice can *do work and bill for it*.
- **Dental treatment plan** (phased, fee-scheduled estimate, patient acceptance, billed-progress) — how a
  dentist sells and tracks a course of care.
- **Perio charting** — a hygienist runs this constantly in general practice.
- **Basic imaging** (upload + view radiographs/scans + clinician reading).
- **Diagnosis record** (clinician-entered).
- **Chair scheduling** (mostly reuse of existing resources/day-board).

**Later / lower-priority / specialist:**
- **Advanced endo** (per-canal RCT detail), **ortho aligner tracking + scan comparison** — specialist
  workflows a general dentist typically refers out or does simply; defer to G11.
- **Sterilization + inventory** (G10) — real compliance value, but an operations sub-domain, not clinical
  core; build when the practice asks for traceability/reorder.
- **Live imaging-device capture + 3D scan overlay** — partner-gated (§5); day-one is manual upload + a
  basic viewer.
- **AI overlays / AI diagnosis** — **non-goal** (electric fence), not "later".

---

## Section 5 — Dental long poles / external needs (known up front)

Honest dependency list — the dental analogues of the clinic long poles:

- **Dental imaging device / scanner integration (PARTNER-GATED).** CareOS can **store + display uploaded**
  radiographs and intraoral scans day-one (reuses private document storage). **Live capture from the X-ray
  sensor / intraoral scanner** (Dexis, Sirona/Dentsply, iTero, 3Shape…) and **3D scan overlay/comparison**
  need the **vendor's device SDK or a DICOM/PACS bridge** — not buildable by code alone. Trigger: the
  customer's specific device + a funded integration.
- **Licensed procedure code set (LICENSING).** The US **ADA CDT** codes are **licensed — do NOT bundle**.
  Swiss dental bills on the **SSO/Dentotar tariff (point-value / Tarifpunkte)**; UK NHS/other markets have
  their own. → the procedure catalog is **tenant-authored** (the customer imports their fee schedule),
  exactly the tariff/orderable-item pattern (no bundled licensed catalog). If the customer needs the
  official coded set, that is a **licensing arrangement**, not a build.
- **Insurance / claims (PARTNER-GATED, overlaps the clinic long poles).** Dental insurance
  eligibility/claims (the B3 insurance bucket) needs a **clearinghouse** — same long pole as the medical
  claims vertical, deferred until an insurance-billing customer + a clearinghouse partner.
- **3D scan overlay compute (PARTNER-GATED).** The ortho scan-comparison deviation (rotation/translation)
  is typically **scanner-vendor software** — CareOS stores/links the scans and records the clinician's
  reading; it does not compute the overlay.
- **AI radiology / caries detection (NON-GOAL, not a long pole).** The prototype's AI overlays/findings/
  proposed diagnosis are **out by the electric fence**. If a customer ever wants machine caries detection,
  that is a **licensed, regulated medical-device product** — a partner + a regulatory track, never a
  homemade CareOS feature, and even then it would sit behind the fence discipline (advisory, human-owned).

---

## Section 6 — Bottom line

Dental is a **from-scratch clinical vertical** (its own `Modules\Dental`), but it inherits CareOS's whole
tested foundation — tenancy, patients, scheduling/chairs, the billing engine, documents/imaging storage,
audit, RBAC, governed AI, and the design system. The genuinely **net-new** work is the **dental clinical
domain**: the **odontogram/tooth model (foundational)**, **perio charting**, **procedures**, a
**clinician-entered diagnosis record**, and the **phased fee-scheduled treatment plan** — planned here as
**~8 core gates (G1–G8), foundational-first**, plus ~3 later/specialist gates. The **electric fence is the
defining constraint**: the prototype's AI diagnosis, AI image overlays, perio "watch" flags, and wear-time
grades are **built WITHOUT the interpretation** — CareOS records the facts and the clinician's own
judgment, never the machine's. The real **long poles are imaging-device/scanner integration and licensed
code sets** (partner/licensing, not code), plus the shared insurance/clearinghouse pole. **Day-one MVP =
chart the mouth, do + bill common procedures, plan treatment, chart perio, view imaging, schedule chairs;**
advanced endo/ortho, sterilization/inventory, and live imaging capture follow when the practice pulls them.
