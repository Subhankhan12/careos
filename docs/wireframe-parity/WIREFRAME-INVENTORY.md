# CareOS — COMPLETE WIREFRAME INVENTORY + BUILT-STATE MAP (audit only)

**Read-only audit. No wireframes were bulk-decoded, no app code changed, no parity gate opened.**

- **Date:** 2026-08-20 · **HEAD:** `b006d07` (DEPLOY.PROV) · **CI:** `check -> completed / success` · tree clean.
- **Purpose:** know exactly how many wireframe screens exist, which are at parity, which are audited-but-unbuilt,
  and which are untouched — so the remaining design-parity work can be scoped and prioritised.

---

## 1 — Inventory

**117 HTML bundles**, and the two folders hold **identical sets** (verified by set-difference, zero on either side):

| Folder | Files | Note |
|---|---|---|
| `resources/prototype/` | 117 bundles **+ 33 decoded** `*.wireframe*.html` | gitignored; the working copy |
| `~/Downloads/CareOS Eucalyptus Glow Design Pack/standalone/` | 117 bundles | the original pack; byte-identical names |

No other design-pack folder exists (searched `~/Downloads` and the repo).

**117 bundles ≈ 51 MB, averaging ~448 KB each** (each is a self-unpacking "bundler shell" — React + app bundle +
7 Inter woff2, base64+gzip inside).

**The distinct-screen count, stated precisely:**

| | Count |
|---|---|
| HTML bundles on disk | **117** |
| − the pack's own contents page (`index`) | −1 |
| − design-system meta artefacts (Foundation Styleguide · Flow Map · Design System Consistency Report) | −3 |
| **= PRODUCT SCREENS** | **113** |

*The pack's own index describes itself as **"112 screens + flow map"** — one off from my 113. The difference is a
counting convention (whether the Styleguide/Consistency Report count as "screens"), not a missing file. I report
what is on disk.*

**Decoded so far: 33 outputs covering ~26 distinct screens** (some have `.full`/`.v2`/`.new` variants). The other
~87 screens have **never been decoded** — this audit deliberately did not decode them; classification below comes
from filenames, the pack's own index, the nine `*-DIFF.md` audits, and the live route/Vue table.

---

## 2 — Groups

Grouped by role/area. Counts are of all 117 files (meta included, so the column totals to 117):

| Group | Screens |
|---|---|
| Billing / AR | 15 |
| Platform / operator mode | 16 |
| Dental (+1 shared with Surgery) | 13 |
| Patients & clinical | 12 |
| Patient portal | 11 |
| Governance & AI | 10 |
| Comms | 8 |
| Scheduling | 8 |
| Insurance / claims | 5 |
| Design-system meta | 4 |
| Admin & config | 3 |
| Entry & overview | 3 |
| Nursing / Spitex | 2 |
| Lab (+1 shared with Radiology) | 2 |
| Auth · Kiosk · Nurse PWA · Pharmacy · Radiology | 1 each |

**A gap worth naming:** the pack is an **outpatient/clinic + dental + portal** design. There are **no wireframes at
all** for the six hospital verticals — inpatient/ADT, pharmacy (beyond one refill screen), surgery/OR, ED, lab
(beyond result review) and radiology (beyond the viewer). Those ~30 live pages were built functional-plain to the
Eucalyptus Glow design system without a per-screen wireframe, so they cannot be "parity-audited" against anything.
That is a property of the design pack, not a gap in the build.

---

## 3–4 — Built-state map, with rough size

**States:** ✅ PARITY COMPLETE · 📋 AUDITED, NOT BUILT · 🗺️ MAPPED ONLY · 🔵 LIVE PAGE EXISTS, NEVER COMPARED ·
⚪ NO LIVE PAGE · ◆ design-system meta.

**Size heuristic** (from whether a live route/model/service exists for the domain — not from decoding):
**a** = visual-only over an existing backend · **b** = needs some backend · **c** = needs substantial new backend.

### Admin & config (3)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Admin Branches | ✅ PARITY COMPLETE | admin/branches · Admin/Branches.vue | — |
| Admin Settings | ✅ PARITY COMPLETE | settings · Admin/Settings.vue | — |
| Branch Create | ✅ PARITY COMPLETE | BRANCH.P5 wizard — inside Admin/Branches.vue | — |

### Auth (1)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Auth Screens | ✅ PARITY COMPLETE | /login, /two-factor/* · Auth/*.vue | — |

### Billing / AR (15)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| AR Account Detail | ✅ PARITY COMPLETE | billing/accounts/{account} · Billing/AccountDetail.vue | — |
| Billing & AR | ✅ PARITY COMPLETE | billing/aging, billing/report · Billing/Aging.vue | — |
| Billing Invoice Detail | 🔵 LIVE, NEVER COMPARED | billing/invoices/{invoice} · Billing/Invoices/Show.vue | a · visual-only |
| Billing Invoices List | 🔵 LIVE, NEVER COMPARED | billing/invoices · Billing/Invoices/Index.vue | a · visual-only |
| Credit Note Issued | 🔵 LIVE, NEVER COMPARED | billing/credit-notes/{invoice} · Billing/CreditNotes/Show.vue | a · visual-only |
| Failed Payment | ⚪ NO LIVE PAGE | no PSP — payments are recorded, not taken online | c · substantial backend |
| Financial Statement | 🔵 LIVE, NEVER COMPARED | billing/report · Billing/Report.vue | a · visual-only |
| Invoice Overdue Reminder | 🔵 LIVE, NEVER COMPARED | billing/dunning · Billing/Dunning/Index.vue | a · visual-only |
| Payment Plan | ✅ PARITY COMPLETE | ARDETAIL.P5 — inside Billing/AccountDetail.vue | — |
| Payment Received | 🔵 LIVE, NEVER COMPARED | billing/payments/{payment} · Billing/Payments/Show.vue | a · visual-only |
| Payment Reconciliation | ⚪ NO LIVE PAGE | reconcile is a CRON + alarm; no page | b · some backend |
| Practice Reporting Hub | 🔵 LIVE, NEVER COMPARED | reporting · Reporting/Dashboard.vue | a · visual-only |
| Refund Issued | 🔵 LIVE, NEVER COMPARED | refunds exist in the ledger; state screen not built | a · visual-only |
| Statement of Account | ⚪ NO LIVE PAGE | no statement page (AR detail covers the ledger) | b · some backend |
| Take Payment | 🔵 LIVE, NEVER COMPARED | billing/payments/record · Billing/Payments/Record.vue | a · visual-only |

### Comms (8)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Consent-Blocked Draft | 🔵 LIVE, NEVER COMPARED | consent gate is live in the send path; state screen not built | a · visual-only |
| Notification Center | ⚪ NO LIVE PAGE | admin/notifications is SETTINGS, not a centre | b · some backend |
| Opt-in Confirmed | ⚪ NO LIVE PAGE | no confirmation screen | a · visual-only |
| Reminder Sent Confirmation | ⚪ NO LIVE PAGE | reminders are queued; no confirmation screen | a · visual-only |
| Request Consent Update | ⚪ NO LIVE PAGE | consent engine exists; no request screen | b · some backend |
| Telehealth Join | 🔵 LIVE, NEVER COMPARED | portal/telehealth · Portal/Telehealth.vue | a · visual-only |
| Telehealth Sessions | 🔵 LIVE, NEVER COMPARED | telehealth · Telehealth/Sessions.vue | a · visual-only |
| Unified Inbox | 🔵 LIVE, NEVER COMPARED | comms/inbox · Comms/Inbox.vue | a · visual-only |

### Dental (12)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Chair Scheduling | ⚪ NO LIVE PAGE | chair-view is a DEFERRED later dental gate | b · some backend |
| Crown Prep | 🔵 LIVE, NEVER COMPARED | dental/plans/{patient} · Dental/TreatmentPlans.vue (procedure flow) | a · visual-only |
| Endo Diagnosis | 🔵 LIVE, NEVER COMPARED | dental/diagnoses/{patient} · Dental/Diagnoses.vue | a · visual-only |
| Fee Schedule Editor | 🔵 LIVE, NEVER COMPARED | dental/fee-schedule · Dental/FeeSchedule.vue | a · visual-only |
| Odontogram | 🔵 LIVE, NEVER COMPARED | dental/chart/{patient} · Dental/Odontogram.vue | a · visual-only |
| Ortho Progress | ⚪ NO LIVE PAGE | ortho/aligner tracking DEFERRED | c · substantial backend |
| Perio Charting | 🔵 LIVE, NEVER COMPARED | dental/perio/{patient} · Dental/PerioChart.vue | a · visual-only |
| RCT Procedure | 🔵 LIVE, NEVER COMPARED | dental/plans/{patient} · Dental/TreatmentPlans.vue (procedure flow) | a · visual-only |
| Scan Comparison Viewer | ⚪ NO LIVE PAGE | 3D scan-compare is a DEFERRED long pole | c · substantial backend |
| Scan Library | 🔵 LIVE, NEVER COMPARED | dental/images/{patient} · Dental/Imaging.vue | a · visual-only |
| Scan Upload | 🔵 LIVE, NEVER COMPARED | dental/images/{patient} · Dental/Imaging.vue | a · visual-only |
| Treatment Plan | 🔵 LIVE, NEVER COMPARED | dental/plans/{patient} · Dental/TreatmentPlans.vue | a · visual-only |

### Dental / Surgery (1)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Inventory & Sterilization | 🔵 LIVE, NEVER COMPARED | surgery/inventory, pharmacy/inventory (sterilisation DEFERRED) | b · some backend |

### Design-system meta (4)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Design System Consistency Report | ◆ design-system meta | design-system audit reference | — |
| Flow Map | ◆ design-system meta | cross-screen journeys reference | — |
| Foundation Styleguide | ◆ design-system meta | tokens + components reference | — |
| index | ◆ design-system meta | the pack's own contents page | — |

### Entry & overview (3)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Landings | 🔵 LIVE, NEVER COMPARED | app, admin · App/Landing.vue, Admin/Landing.vue | a · visual-only |
| My Account | ⚪ NO LIVE PAGE | no user-profile page | b · some backend |
| System Error States | 🔵 LIVE, NEVER COMPARED | Error.vue | a · visual-only |

### Governance & AI (10)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Admin Approval Queue | ✅ PARITY COMPLETE | governance/approvals · Governance/ApprovalQueue.vue | — |
| Agent & Tool Config | ✅ PARITY COMPLETE | admin/agents, governance/agents · Admin/Agents.vue | — |
| Draft Review Composer | ✅ PARITY COMPLETE | APPROVAL.P4 — inside Governance/ApprovalQueue.vue | — |
| Fence-Refused Detail | ✅ PARITY COMPLETE | APPROVAL.P5 — inside Governance/ApprovalQueue.vue | — |
| Governance Dashboard | 🔵 LIVE, NEVER COMPARED | governance · Governance/Dashboard.vue | a · visual-only |
| Governance Ledger Export | ⚪ NO LIVE PAGE | billing export exists; no governance-ledger export page | b · some backend |
| KB Admin | 🔵 LIVE, NEVER COMPARED | governance/kb · Governance/KnowledgeBase.vue | a · visual-only |
| New Agent Wizard | ✅ PARITY COMPLETE | AGENT.P6 — inside Admin/Agents.vue | — |
| Rejected Action Detail | ✅ PARITY COMPLETE | APPROVAL.P6 resolved view | — |
| Resolved Action Detail | ✅ PARITY COMPLETE | APPROVAL.P6 resolved view | — |

### Insurance / claims (5)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Claim Fully Covered | ⚪ NO LIVE PAGE | NOT BUILT | c · substantial backend |
| Claim Partially Covered | ⚪ NO LIVE PAGE | NOT BUILT | c · substantial backend |
| Claim Rejected | ⚪ NO LIVE PAGE | NOT BUILT | c · substantial backend |
| Insurance Claim | ⚪ NO LIVE PAGE | CLAIMS VERTICAL NOT BUILT (needs a clearinghouse partner) | c · substantial backend |
| Insurance Eligibility | ⚪ NO LIVE PAGE | NOT BUILT | c · substantial backend |

### Kiosk (1)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Kiosk Check-in | 🔵 LIVE, NEVER COMPARED | kiosk/{kioskToken} · Kiosk/CheckIn.vue | a · visual-only |

### Lab (1)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Lab Result Review | 🔵 LIVE, NEVER COMPARED | lab/results/review · Lab/Review.vue | a · visual-only |

### Lab / Radiology (1)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Lab Imaging Order | 🔵 LIVE, NEVER COMPARED | lab/patients/{p}/orders, radiology/patients/{p}/orders | a · visual-only |

### Nurse PWA (1)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Nurse PWA | 🔵 LIVE, NEVER COMPARED | separate SPA · nurse-pwa/ (build:pwa) | a · visual-only |

### Nursing / Spitex (2)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Client Record | 🔵 LIVE, NEVER COMPARED | patients/{patient} · Patients/Show.vue (Spitex client) | a · visual-only |
| Nursing Dispatch | 🔵 LIVE, NEVER COMPARED | nursing/dispatch · Nursing/Dispatch.vue | a · visual-only |

### Patient portal (11)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Portal Appointments | 🔵 LIVE, NEVER COMPARED | portal/appointments · Portal/Appointments.vue | a · visual-only |
| Portal Consents | 🔵 LIVE, NEVER COMPARED | portal/consents · Portal/Consents.vue | a · visual-only |
| Portal Documents | 🔵 LIVE, NEVER COMPARED | portal/documents · Portal/Documents.vue | a · visual-only |
| Portal Home | 🔵 LIVE, NEVER COMPARED | portal · Portal/Home.vue | a · visual-only |
| Portal Invite | 🔵 LIVE, NEVER COMPARED | invite/{token} · Auth/AcceptInvite.vue | a · visual-only |
| Portal Invoices | 🔵 LIVE, NEVER COMPARED | portal/invoices · Portal/Invoices.vue | a · visual-only |
| Portal Login | 🔵 LIVE, NEVER COMPARED | portal/login · Portal/Login.vue | a · visual-only |
| Portal Messages | 🔵 LIVE, NEVER COMPARED | portal/messages · Portal/Messages.vue | a · visual-only |
| Portal Password Reset | 🔵 LIVE, NEVER COMPARED | forgot-password, reset-password/{token} · Auth/* | a · visual-only |
| Portal Sign Out | 🔵 LIVE, NEVER COMPARED | POST logout (no page) | a · visual-only |
| Portal Telehealth | 🔵 LIVE, NEVER COMPARED | portal/telehealth · Portal/Telehealth.vue | a · visual-only |

### Patients & clinical (12)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Allergy Alert | ✅ PARITY COMPLETE | clinical/chart · Clinical/Chart.vue (safe part) | — |
| Care Plan Review | ⚪ NO LIVE PAGE | care plans backend (P0D.G6); no page | b · some backend |
| Consult Summary | ⚪ NO LIVE PAGE | summary agent exists; no page | b · some backend |
| Medical History Intake | ⚪ NO LIVE PAGE | no intake page | c · substantial backend |
| Note Editor | 🔵 LIVE, NEVER COMPARED | clinical/notes/{note}/edit · Clinical/NoteEditor.vue | a · visual-only |
| Patient 360 | 🔵 LIVE, NEVER COMPARED | patients/{patient} · Patients/Show.vue | a · visual-only |
| Patient Access Log | 🔵 LIVE, NEVER COMPARED | patients/{patient} access tab · Patients/Show.vue | a · visual-only |
| Patient Chart | 🔵 LIVE, NEVER COMPARED | clinical/chart/{patient} · Clinical/Chart.vue | a · visual-only |
| Patient Flow | ⚪ NO LIVE PAGE | no flow page | b · some backend |
| Patients Index + Register | 🔵 LIVE, NEVER COMPARED | patients, patients/register · Patients/Index.vue | a · visual-only |
| Recall Due List | ⚪ NO LIVE PAGE | recall engine + cron; no page | b · some backend |
| Referral Out | ⚪ NO LIVE PAGE | referrals backend (P0D.G5); no page | b · some backend |

### Pharmacy (1)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Prescription & Refill | ⚪ NO LIVE PAGE | medication orders exist; no prescribe/refill screen | b · some backend |

### Platform / operator (16)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Create Tenant | ⚪ NO LIVE PAGE | tenant:create CLI exists (DEPLOY.PROV) — no UI | b · some backend |
| Elevated Session Banner | 🗺️ MAPPED ONLY | OPMODE G10 — needs G4 session | b · some backend |
| Enter Operator Mode Confirm | 🗺️ MAPPED ONLY | OPMODE G7 — backend G1-G3 done, no UI | a · visual-only |
| Operator Mode Banner | 🗺️ MAPPED ONLY | OPMODE G7 — no UI | a · visual-only |
| Operator Mode Hub | 🗺️ MAPPED ONLY | the family index - a design artefact, not a product screen | — |
| Operator Session Ended | 🗺️ MAPPED ONLY | OPMODE G7 — needs G5 receipt | b · some backend |
| Owner Approval Request | 🗺️ MAPPED ONLY | OPMODE G8 — backend G3 done, no UI | a · visual-only |
| Owner Granted Read-Only | 🗺️ MAPPED ONLY | OPMODE G9 — downgrade backend done, no UI | a · visual-only |
| Owner Notification | 🗺️ MAPPED ONLY | OPMODE G8 — email exists; no in-app centre | b · some backend |
| Owner Revoked Mid-Session | 🗺️ MAPPED ONLY | OPMODE G10 — needs G5 revoke | b · some backend |
| Request Declined | 🗺️ MAPPED ONLY | OPMODE G9 — no UI | a · visual-only |
| Request Expired | 🗺️ MAPPED ONLY | OPMODE G9 — no UI | a · visual-only |
| Session Extended | 🗺️ MAPPED ONLY | OPMODE G10 — needs G4/G5 | b · some backend |
| Super-Admin Tenant Detail | 🗺️ MAPPED ONLY | OPMODE G6 — no UI | b · some backend |
| Super-Admin Tenants | 🗺️ MAPPED ONLY | OPMODE G6 — no UI | b · some backend |
| Waiting On Approval | 🗺️ MAPPED ONLY | OPMODE G8 — no UI | a · visual-only |

### Radiology (1)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Imaging Viewer | ⚪ NO LIVE PAGE | DICOM/PACS is a partner seam (RAD.G6) — NOT built | c · substantial backend |

### Scheduling (8)

| Screen | Built state | Live route · Vue (or why not) | Size |
|---|---|---|---|
| Appointment Detail | ✅ PARITY COMPLETE | scheduling/appointments/{a} · Scheduling/AppointmentDetail.vue | — |
| No-Show Follow-Up | ⚪ NO LIVE PAGE | no_show status exists; no follow-up screen | b · some backend |
| Provider Availability | ⚪ NO LIVE PAGE | NO availability admin UI (W8c deferred) — flagged in the readiness check | b · some backend |
| Public Booking | 🔵 LIVE, NEVER COMPARED | book/{tenant} · Public/Book.vue | a · visual-only |
| Reception Day-Board | 🔵 LIVE, NEVER COMPARED | scheduling/day-board · Scheduling/DayBoard.vue | a · visual-only |
| Service Catalog | ⚪ NO LIVE PAGE | services exist; no catalog page | b · some backend |
| Service Create | ⚪ NO LIVE PAGE | no service-create page | b · some backend |
| Waitlist Management | 📋 AUDITED, NOT BUILT | panel inside Scheduling/DayBoard.vue only | b · some backend |

---

## 5 — Bottom line

### The numbers

| Built state | Screens | Share of 117 |
|---|---|---|
| ✅ **PARITY COMPLETE** | **16** | 14% |
| 📋 **AUDITED, NOT BUILT** | **1** | 1% |
| 🗺️ **MAPPED ONLY** | **15** | 13% |
| 🔵 **LIVE PAGE EXISTS, NEVER COMPARED** | **51** | 44% |
| ⚪ **NO LIVE PAGE AT ALL** | **30** | 26% |
| ◆ design-system meta (nothing to build) | 4 | 3% |

**16 parity-complete, not 9.** The nine-page pass covered nine *audits*, but several of those pages absorbed
sibling wireframes as sub-parts — Branch Create (BRANCH.P5), Payment Plan (ARDETAIL.P5), New Agent Wizard
(AGENT.P6), Draft Review Composer (APPROVAL.P4), Fence-Refused / Rejected / Resolved Action Detail
(APPROVAL.P5–P6). Counted as screens rather than audits, **16 of the pack's screens are at parity.**

### The shape of what remains

Of the **97 screens that are neither parity-complete nor meta**:

| Likely size | Screens | What it means |
|---|---|---|
| **a — visual-only over an existing backend** | **59 (61%)** | A live page or a real backend already exists; the work is a re-skin/compare against the wireframe. |
| **b — needs some backend** | **27 (28%)** | A domain model exists but the specific screen needs a write path, a query, or a field (e.g. Waitlist's add-path, Provider Availability, Recall Due List). |
| **c — needs substantial new backend** | **10 (10%)** | Insurance/claims (5), Imaging Viewer (DICOM), Scan Comparison, Ortho Progress, Medical History Intake, Failed Payment (needs a PSP). |
| *(unsized)* | 1 | The Operator Mode Hub — the family's own index page, a design artefact rather than a screen to build. |

**The honest headline: the remaining work is mostly visual.** ~61% of what is left sits over a backend that
already exists. The genuinely expensive tail is small and already-known — it is almost entirely the **insurance/
claims vertical (5 screens, needs a clearinghouse partner)** and the **certified-partner seams** (DICOM viewer,
3D scan compare), both of which are recorded permanent non-goals or partner-gated items in `DEFERRED.md`, not
oversights.

### The three honest caveats

1. **"LIVE PAGE EXISTS, NEVER COMPARED" is not "at parity."** Those 51 pages were built functional-plain to the
   design system and have never been diffed against their wireframe. Some will match closely; some will not.
   **Only a decode + audit can tell**, and this inventory deliberately did not do that — treating "a page exists"
   as "the page matches" would be exactly the kind of unverified claim the parity pass exists to prevent.
2. **The size column is a heuristic, not an estimate.** It is derived from whether a route/model/service exists
   for the domain, not from reading each wireframe. Any individual screen could move a band once decoded.
3. **The 15 MAPPED-ONLY screens are one feature, not fifteen.** They are the Operator Mode family, whose
   security core (G1–G3) is built and whose UI (G4–G11) is **deliberately deferred** to post-first-customer
   (D-164). They should be scoped as one chain, not as fifteen independent parity pages.

### If parity work resumes, the cheapest high-value order

1. **Decode-and-audit the 51 never-compared live pages in domain batches** (portal 11 · billing 13 · dental 9 ·
   clinical 6). These are the pure-visual wins, and an audit is cheap relative to a build.
2. **Waitlist Management** — already audited, one blocker (the add-to-waitlist write path), scope decided.
3. **The 28 "some backend" screens**, prioritised by whoever actually asks for them.
4. **Leave the 10 substantial ones alone** until a customer or partner pulls them forward.

**None of this is queued work.** The wireframe-parity pass is closed (D-160) and the current track is DEPLOYMENT.
This inventory exists so that *if* parity work resumes, its shape and cost are already known.
