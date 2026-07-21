# CareOS — Deep Full-Product QA / UX / Performance / Design / Feature Audit

**Date:** 2026-07-21 · **Commit:** `397dc54` (memory + state reconciliation) · **CI:** green
(check-run `success`) · **Method:** live browser (Playwright MCP, Chromium) driven role-by-role
through the running app + payload/DB cross-checks · **Scope:** CLINIC + ADMIN + **DENTAL** verticals.
**Audit and report only — no application code was changed.** This extends the prior
`docs/QA-AUDIT-REPORT.md` with the new dental vertical and a deeper UX / performance / design /
feature-gap pass.

---

## 1. Environment & coverage

| Item | Value |
|---|---|
| App URL | `http://127.0.0.1:8000` (`php artisan serve`; Apache/`localhost` has no CareOS vhost) |
| Stack | Laravel 12.63.0 / PHP 8.2.12 · MariaDB 10.4.32 @3306 · assets pre-built |
| Migrations | `migrate:status` → **0 pending** (all dental migrations `Ran`) |
| Seed | `migrate:fresh --seed` → `DemoClinicSeeder` (Praxis Lindenhof, **CHF**) + `DemoSpitexSeeder` (Spitex Sonnengarten). **No dental seeder exists** — all dental data below was created live through the UI during the audit. |
| Staff login | `<first>.<last>@praxis-lindenhof.test` / `demo-password` + **mandatory TOTP 2FA** (fixed factory secret `JBSWY3DPEHPK3PXP`) |
| Portal login | `erika.baumgartner@example.test` / `demo-portal-password` (no 2FA) |
| Browser timezone | **America/Los_Angeles (UTC-7)** — the exact condition that surfaces the M-2 date-only bug (a stored `1954-03-12` renders `03/11` if broken, `03/12` if fixed) |

**Roles exercised (all in the clinic tenant, which also owns the dental routes):**
`org_admin` (Andrea Lindenhof — the dentist-owner; holds `dental.chart` + `billing.manage`),
`doctor`/dentist (Matthias Brunner — `dental.chart`, **not** `billing.manage`),
`reception` (Nadia Steiner — `patient.view`, no `dental.chart`/`billing`/`admin`),
`billing` (Thomas Ammann — `billing.manage`, **no** `patient.view`),
plus the `patient` portal (Erika Baumgartner).

### Coverage map

| Area | Coverage | Result |
|---|---|---|
| **Auth** (login ±, 2FA, logout, unauth bounce, role switching) | Full — 4 staff roles + portal | ✅ Pass |
| **Landings** (staff real figures) | org_admin | ✅ Real figures (M-1) |
| **Patients** (index, 360 tabs, register+live dup, consents, access log) | Full | ✅ Pass (M-2 holds) |
| **Scheduling** (day-board + status action, kiosk PHI, public booking) | Full | ✅ Pass |
| **Clinical** (chart, vitals fence, signed-note read-only + amend) | Full | ✅ Fence held |
| **Comms** (inbox, internal chip, reply posted) | Full | ✅ Pass |
| **Telehealth** (staff join W10, portal not-recorded) | Staff surface wired; **no seeded session to Join** | ⚠ coverage-limited |
| **Billing** (C-1 surface: detail / issue-adjacent / credit-note / payment / allocate / over-alloc / PDF / 404) | Full | ✅ Pass |
| **Reporting** (facts-only) | Full | ✅ Pass |
| **Admin** (settings W8, roles W8, branches W8b/c, governance W9, approvals W9, KB W10, kiosk, import dry-run) | Full | ✅ Pass |
| **DENTAL** (fee-schedule, odontogram+perform, plan, perio, diagnosis, imaging + every fence) | **Full** | ✅ **All fences held** |
| **Portal** (home, self-book, docs, invoices no-pay, telehealth, treatment-plan read-only, consent lockout) | Full | ✅ Pass |
| **Cross-cutting RBAC** (nav gating + RBAC-by-URL, all 4 staff roles + dental read/write split) | Full | ✅ Pass |

**Not reached (honest limitations):**
- The **offline Nurse PWA** (a separate Vite/Vue SPA) — not drivable in this browser harness.
- **Spitex tenant deep-drive** — the dental audit used the clinic tenant (which owns the dental
  routes); the Spitex tenant mirrors the same 6 roles and was not separately re-driven.
- **Staff telehealth "Join"** — the page + "not recorded" discipline are wired, but no telehealth
  session is seeded for the tested users, so the actual token-issue Join was not clicked.
- **Dental partner-gated surfaces** — live imaging capture (X-ray sensor / intraoral scanner),
  DICOM/PACS, 3D scan overlay, AI radiology — intentionally **not built** (see §8/§10).

---

## 2. In-browser confirmation the prior fixes + dental fences hold

### Clinic/admin prior fixes (FIX.1–FIX.5, W8–W10) — all re-confirmed in-browser

| Fix | In-browser confirmation |
|---|---|
| **C-1 / FIX.1** (billing string-id, no 500) | INV-1 detail opens at a **string ULID** URL (`/billing/invoices/01ky2ewq…`), HTTP 200; credit-note → **CN-2** (string-id, no 500); payment recorded → its **detail** (no dead-end); **PDF** = `200 application/pdf %PDF- "INV-1.pdf"`; CSV import batch page `/imports/{batch}` renders; a **bogus id → styled 404**, never 500. |
| **M-1 / FIX.2** (landing) | Real figures: 3 appointments · waiting · 0 no-shows, Active patients, **Outstanding 787.11 CHF** (→ **374.11 CHF** after my credit-note + payment — the landing tracked the mutation). |
| **M-2 / FIX.3** (dates) | Erika's DOB renders **12.03.1954** on the index and **1954-03-12** on the 360/chart — the correct stored day in a UTC-7 browser (the buggy `new Date` path would show March 11). |
| **M-3** (vitals units) | Chart Vitals show **68.0 kg / 163 cm** (not 68000 g / 1630 mm), raw per-metric series with timestamps + source — **zero interpretation** (no ranges/flags/arrows/colours/normal-abnormal). |
| **M-4** (nav gating) | org_admin **12** items; reception **4** (Dashboard/Patients/Scheduling/Inbox); billing **2** (Dashboard/Billing); doctor **4** (Dashboard/Patients/Scheduling/Telehealth). |
| **M-5** (styled denials) | reception→`/billing/invoices` etc. → **403**; bogus URL → styled **404** ("Page not found"); portal consent-withdrawal → styled **403** ("Your portal access has been withdrawn"). |
| **M-6** (plausible vitals) | Seeded vitals are realistic (BP 125–132/77–81, HR 70–73, temp 36.5–36.8 °C, weight ~68 kg, **stable** height 163 cm) — the old 155→170 cm / 51→101 kg swings are gone. |
| **L-2 / L-3** | Day-board resources are **Behandlungsstuhl 1/2 + Sprechzimmer 1/2/3** (chairs/rooms, no vehicles); currency is **CHF** everywhere (landing, invoices, portal, dental fees). |
| **Final-pass §9 fixes** | Record-payment label reads **"Amount (CHF)"** (was EUR); portal consent scope chip is a single `comms.email` (was doubled). |
| **W8** settings/roles | `/settings` profile edit (contact email) **persists** through `SettingsService`; `/admin/roles` shows the fixed-template assign UI. |
| **W8b/W8c** branches | Branch CRUD + opening hours + resources ("10 active resources"); **deactivation guard visible**: *"Cannot deactivate — this branch has 5 upcoming appointments."* |
| **W9** governance | `/governance` is **read-only**; **"Verify now" appends nothing** — audit-chain count stayed **341 → 341 events**, flash "verified". |
| **W9** approval queue | Reject an AI action → *"Action rejected. **Nothing was executed.**"*, queue dropped 2→1; **no create/propose control** exists (no new autonomy path). |
| **W10** KB / staff telehealth | KB article **created** (CRUD works, "created." flash); staff `/telehealth` shows the **"None of these calls are recorded…"** discipline banner. |
| **Import dry-run** | Upload → map → **dry-run validated 2 rows, wrote ZERO patients** (DB count 15 → 15) — the C-1-class `/imports/{batch}` route works. |

### Dental fences (the new vertical) — all held in-browser

Every dental Inertia payload was inspected (`window.history.state.page.props`) for fence-forbidden
**keys**, and the rendered DOM eyeballed for graded/flagged UI. **No fence break was found on any
surface.**

| Surface | Fence confirmation |
|---|---|
| **Odontogram** | Payload carries **zero** forbidden keys (no severity/score/grade/risk/flag/…). Chart-key legend is **categorical** with the explicit disclaimer *"Colour marks the condition the dentist charted — not its severity. Nothing here is scored, graded, or flagged."* Charting **caries** on 16-occlusal then correcting to **restoration** preserves the caries in history (append-only). |
| **Perform-a-procedure** | Atomic: one action wrote a **charge** (`D-EXTRACT` unit `18000` = **180.00 CHF**, = the fee schedule), a **performed_procedures** row whose `charge_id` **exactly matches** the charge (no orphan), and a **tooth-state** (16 → missing). Cross-checked in the DB. |
| **Fee schedule** | Editing `D-EXTRACT` 180 → **200** leaves the prior charge frozen at **180.00** (snapshot); flash even states *"Past charges keep the fee they were captured at."* |
| **Treatment plan** | Build → **Propose** (snapshots estimate) → **Accept posts NO charge** (patient charge count **13 → 13**). Editing `D-CROWN` 900 → 950 leaves the accepted-plan estimate **frozen at 900.00**. **Perform** the linked item creates exactly **one** charge at the current fee (`D-CROWN` `95000` = 950.00; count 13 → 14) and flips the item to **Done**. The portal shows the accepted plan **read-only, no Pay/PSP button**. |
| **Perio** | A **9 mm** pocket + BOP renders raw as **`9/2•`** — identical neutral style to shallow sites; **no stage/grade/severity/flag/colour**. Payload has zero forbidden keys. The only "staged/graded" text is the disclaimer saying they are absent. |
| **Diagnosis** (sharpest) | After charting caries **and** a 9 mm pocket, the Diagnoses page auto-populated **ZERO diagnoses**. Fence note verbatim: *"Nothing here suggests, proposes, ranks, or auto-fills a diagnosis, and no diagnosis is derived from the charting, perio, or imaging."* The dentist writes the label and **sets the status** (recorded a "confirmed" one); no suggestion/differential/AI keys. |
| **Imaging** | Uploading an X-ray created **ZERO readings** (no auto-analysis). Fence note: *"The system does not analyse images — no AI, no auto-findings, no overlay, no caries or pathology detection."* The dentist reading is stored **verbatim** (exact-match). Bytes stream only through the authed route with **`X-Content-Type-Options: nosniff`, `Cache-Control: no-store, private`** — no public URL. |

---

## 3. Bug list by severity

**Headline: no Critical, High, or Medium functional/safety/RBAC/billing/data-integrity defect was
found.** The clinic prior-fix surface, the entire dental vertical, and every fence held. The items
below are Low/cosmetic UX. Each is classified **(a)** safe cosmetic/presentational, **(b)**
flag-for-review (fence/billing/clinical/tenancy/RBAC or a deferred-feature-not-a-bug), or **(c)**
not-a-bug (intentional gap).

### 🔴 Critical / 🟠 High / 🟡 Medium
**None.**

### 🟢 Low

**L-A — CSV import "Save mapping" trap.** `/imports/{batch}`: mapping the column dropdowns and then
clicking **"Run dry-run (writes nothing)"** without first clicking **"Save mapping"** returns
*"Required fields are not mapped: first_name, last_name, date_of_birth"* — even though the dropdowns
visibly show the correct mappings. The dry-run validates against the *saved* mapping, not the
on-screen selection. **Repro:** upload CSV → set the 3 required dropdowns → Run dry-run (skip Save).
**Expected:** dry-run uses the current mapping (or the button is disabled/self-saves). **Actual:**
confusing "unmapped" error. No data risk (still writes nothing). *Classify **(a)** — presentational/UX;
make dry-run save-then-validate or disable until saved.*

**L-B — Portal treatment-plan page is not linked in the portal nav.** Erika has an **accepted**
treatment plan and the page (`/portal/treatment-plan`) renders correctly, but the portal nav
(Home / Appointments / Documents / Messages / Invoices / Consents / Video visit) has **no "Treatment
plan" link** — the patient can reach it only by typing the URL. *Classify **(a)** — add a nav link.*

**L-C — A credit note renders as an "Open" invoice with a negative balance in the portal.** After a
full credit note, the patient's `/portal/invoices` shows **CN-2** with status **"Open"** and open
balance **-313.00 CHF**, and the aggregate **"Open balance -313.00 CHF"**. A patient seeing a negative
"open balance" for a credit in their favour is confusing. (Partly induced by this audit crediting a
fully-issued invoice; still worth a status/label review for credit-note rows in the patient list.)
*Classify **(a)** — presentational.*

**L-D — Admin config pages carry a "GOVERNANCE" section eyebrow.** `/settings`, `/admin/roles`, and
`/admin/branches` all render under a **"GOVERNANCE"** kicker, conflating admin-settings with the
audit/oversight "Governance" area (which is a separate nav item). Minor information-architecture
inconsistency. *Classify **(a)** — cosmetic.*

**L-E — Dense top nav for org_admin.** The org_admin top nav has **12** items and "Knowledge base"
wraps to two lines at the default width; on a narrower viewport this cluster could overflow.
Consider grouping the admin/governance items under a menu. *Classify **(a)** — cosmetic/responsive.*

### Deferred-feature-not-a-bug (tracked here, classified **(c)**)

**G-1 — No patient → dental cross-link and no "Dental" top-nav entry.** The dental clinical surface
(odontogram, perio, diagnoses, imaging, plans) has **no link from the Patient 360, the clinical
chart, or the top nav** — a dentist must type `/dental/chart/{patient}` by hand. This is the single
biggest dental usability gap and is a **documented deferral** (`DEFERRED.md` / PROJECT-STATE list the
"patient/chart → dental cross-link" as a follow-up). *Classify **(c)** intentional/deferred — but it
is the highest-value **(a)**-class build (see §5 and §8).* 

**G-2 — No dental demo seeder.** On a fresh seed the entire dental surface is empty (I had to seed
the fee-schedule template and chart everything by hand). Documented follow-up. *Classify **(c)** —
but see §8, it materially hurts a sales demo.*

---

## 4. Critical / safety callouts (explicit)

Every one of these was specifically probed. **All passed.**

- **Fence break in the UI (graded vital / perio / odontogram, auto-diagnosis, AI imaging finding):**
  ✅ **NONE.** Vitals raw kg/cm; odontogram colour is a categorical charted-condition legend with an
  explicit "not severity" disclaimer; a **9 mm** perio pocket renders as raw `9/2•` with no
  stage/grade/flag; charting caries + a deep pocket auto-populates **zero** diagnoses; uploading an
  X-ray produces **zero** readings and no overlay. Payload-key scans on all five dental surfaces
  returned **no forbidden keys**.
- **PHI-safety (kiosk):** ✅ **PASS.** A real name + real DOB + a **wrong code** returns only
  *"We couldn't find your appointment. Please see reception."* — no PHI, no existence confirmation.
- **RBAC holes:** ✅ **NONE.** Server Gate is authoritative; nav-hiding is a cosmetic hint. Verified
  by URL for all four staff roles (see §2/M-4 and the RBAC matrix below), including the dental
  read/write split (reception can **read** dental pages but `POST /dental/chart` → **403**; billing
  has **no** patient.view so every dental clinical page → **403**; doctor can chart but
  `/dental/fee-schedule` → **403** and `can_perform=false`).
- **Billing UI vs backend:** ✅ **Consistent.** Credit note leaves the original invoice frozen
  (INV-1 number/lines/**total 313.00** unchanged; only derived balance→0 / status→"Credit-noted");
  over-allocation is **blocked** ("Cannot allocate more than the payment unallocated remainder");
  the reporting dashboard's Outstanding **374.11 CHF** = 787.11 − 313 (credit) − 100 (payment),
  reconciling exactly with my mutations.
- **Data-integrity / append-only:** ✅ **Held.** Odontogram/perio/diagnosis corrections create new
  rows and preserve history; a signed clinical note is read-only with a visible v1→v2 amendment
  chain; the dental **perform** wrote all three rows atomically with the performed row's `charge_id`
  matching the charge — **no orphan**.

**Dental RBAC matrix (verified by URL, HTTP status):**

| Route | org_admin | doctor (dentist) | reception | billing |
|---|:--:|:--:|:--:|:--:|
| `GET /dental/chart/{p}` (read) | 200 | 200 | **200** | **403** |
| `POST /dental/chart/{p}` (chart) | 200 | 200 | **403** | 403 |
| perform-a-procedure (`can_perform`) | ✅ true | **false** | false | n/a |
| `GET /dental/perio` / `/diagnoses` / `/plans` / `/images` | 200 | 200 | 200 | **403** |
| `GET /dental/fee-schedule` (`billing.manage`) | 200 | **403** | 403 | **200** |

---

## 5. UX findings (prioritized)

1. **(Highest) Dental is unreachable from the product UI** (G-1). For a dental tenant the odontogram
   *is* the daily workspace, yet there is no nav item and no link from the patient record. A dentist
   opening CareOS would not find their chart. Deferred, but it is the first thing to wire.
2. **Portal treatment-plan not linked** (L-B) — a patient with an accepted plan can't navigate to it.
3. **CSV import "Save mapping" step is a trap** (L-A) — the dry-run silently uses the last-saved
   mapping and reports the on-screen (unsaved) mapping as missing.
4. **Empty-state honesty is good, but the dental surfaces start blank** (G-2) — a first-run dentist
   sees an empty odontogram and an empty fee schedule; the "Add the generic starter template" button
   is the only onboarding affordance and is easy to miss.
5. **Minor IA/labels** — the "GOVERNANCE" eyebrow on Settings/Roles/Branches (L-D); org_admin's
   12-item nav density (L-E).
6. **Accessibility (observational):** forms use real `<label>`/`aria-label` associations, the 2FA
   boxes are individually labelled, and focus/keyboard worked throughout; the eucalyptus palette has
   adequate contrast on the surfaces reviewed. A dedicated contrast/AT sweep was not in scope — the
   dense odontogram/perio grids would be the place to check tap-target size and screen-reader order.

**What is notably good UX:** the live duplicate-detection card (match reasoning + confidence +
open/new actions); the two-step portal consent withdrawal with a clear consequence warning and a
required reason; the day-board's inline lifecycle actions; the billing hub's self-consistent
counters; the odontogram's per-tooth history panel; the "estimating is not billing" copy on the
treatment plan.

---

## 6. Performance findings (prioritized, worst first)

Observational browser-level perf (not a profiler). **No slow page, no N+1 symptom, no heavy payload
was observed** — even on the dense odontogram and perio grids the demo loaded instantly.

1. **Main JS bundle ≈ 334 KB** (`app-…js`) — the only "large" asset. It is the shared Vue 3 +
   Inertia + component runtime, downloaded **once and cached**; per-page chunks are tiny
   (Odontogram 16 KB). Acceptable; could be trimmed later but is not a problem.
2. **Inertia page payloads are tiny and fast:** dental chart ~4 KB, perio ~3 KB, patients ~5 KB,
   day-board ~7 KB; warm fetches 86–210 ms. The odontogram (32 teeth × 5 surfaces + history +
   performed) serialises to ~4 KB with no N+1 lag at demo volume.
3. **Zero JS console errors on any working page.** The only console entries were the intentional
   403/404 responses from the RBAC-by-URL and bogus-id probes.

---

## 7. Design findings (prioritized)

The **Eucalyptus Glow** system renders consistently across every surface: warm off-white wash, glass
top-bar with pill nav, deep-eucalyptus "tile" panels, soft white stat cards, `warning-soft` (amber,
never red) accents. No layout breakage, no visual glitch, no broken image.

1. **Odontogram / perio are dense but clean.** The FDI grid (upper/lower arches, per-tooth 5-cell
   surface mini-maps) and the perio 6-site grid are information-heavy yet legible; the categorical
   condition legend is clear. On small viewports these are the screens most likely to feel cramped —
   worth a responsive check.
2. **Consistent design tokens** — dental screens (a later build) match the same tokens as the clinic
   screens (dark patient tile, euca greens, glass cards); the dental vertical does **not** look
   bolted-on.
3. **Minor:** the "GOVERNANCE" eyebrow on admin-config pages (L-D) is the only labeling
   inconsistency spotted; nav density for org_admin (L-E) is the only spacing risk.

Overall the product looks polished and coherent for a paying customer.

---

## 8. Feature-gap recommendations (classified a–e)

Each gap is tagged **(a)** safe-to-build-now UI over existing backend · **(b)** needs a discovery
answer · **(c)** partner-gated · **(d)** buildable-but-unvalidated · **(e)** intentional
non-goal/fence — with whether a real customer would need it.

> **Inventory note:** `docs/FEATURE-INVENTORY.md` (written at `12e0386`) lists Dental as *"needs its
> own module (absent on disk)"*. That is now **stale** — the `Modules\Dental` vertical (G1–G8) is
> built. The classifications below supersede it for dental.

### (a) Safe to build now — presentation/seed over a tested backend (pull from need)
- **Patient → dental cross-link + a "Dental" nav/section** (G-1). Backend fully built; this is just a
  link. **A real dental customer needs this on day one** — without it the odontogram is unreachable
  from the product. *Highest-value item in this report.*
- **Dental demo seeder** (G-2). **A real customer's sales demo needs this** — the dental surface is
  empty until someone charts; a seeder makes the vertical demo-credible (as the clinic/Spitex ones do).
- **Portal "Treatment plan" nav link** (L-B). Trivial; a patient with an accepted plan expects it.
- **Fee Schedule Editor** — already built (DENTAL.G3); the FEATURE-INVENTORY "(D)" entry is resolved.

### (b) Needs a discovery answer
- **CH / KVG statutory billing pack** — the standing top discovery question (Spitex reimbursement
  model); also relevant if the dentist bills Swiss insurance rather than cash-pay. A dentist customer
  *may* need insurance billing — confirm their payer model before building.
- **Dental insurance / claims** — eligibility + submission + adjudication is a whole vertical; needs
  the customer's payer/clearinghouse reality (also partner-gated, see (c)).

### (c) Partner-gated (integration, not code)
- **Live imaging capture** (X-ray sensor / intraoral scanner), **DICOM/PACS**, **3D scan
  overlay/comparison** — the imaging module explicitly flags these as out-of-release. A real dental
  practice **will** eventually want sensor capture; it needs a device/vendor integration.
- **Online payment (PSP)** — manual payment recording is built; card capture needs a PSP. Relevant to
  both verticals; pull when a customer wants patients to pay online.
- **e-Prescribing / real lab connectivity** — pharmacy/drug-DB and lab HL7/FHIR partners.

### (d) Buildable-but-unvalidated (no dependency, nobody asked)
- **Dental chair-scheduling view (G9)** — reuse the day-board with chairs (already resources); a
  dentist would find this natural but it isn't blocking (chairs already appear on the day-board).
- **Sterilization / inventory (G10)**, **ortho / scan-compare (G11)** — real dental sub-domains, but
  no customer has pulled them.
- **Statement-of-Account PDF**, **AR account detail (per-patient)**, **recall worklist**,
  **notification center** — the cheap clinic-side "(E)" items from FEATURE-INVENTORY.

### (e) Intentional non-goal / fence (never build the homemade version)
- **AI caries/pathology detection on imaging**, **auto-annotation/overlay**, **auto-diagnosis /
  differential**, **perio staging/grading**, **odontogram severity heatmap**, **AI on a telehealth
  call**, **symptom/triage free-text on booking/kiosk** — all deliberately absent and enforced by
  the electric fence. A customer might *ask* for AI radiology; the correct answer is a
  partner/regulated-device path, never an in-house judgment engine.

---

## 9. What's solid / works well

- **The electric fence is genuinely enforced end-to-end** — proven live on the sharpest surfaces
  (no auto-diagnosis after caries + a 9 mm pocket; no AI reading after an X-ray upload; raw perio
  numbers; categorical odontogram colour). This is the product's core promise and it holds.
- **Dental billing integration is correct** — perform is atomic (charge + clinical + tooth-state, no
  orphan), the charge reconciles to the fee to the unit, fees snapshot (past charges never re-price),
  and the treatment plan estimates without charging (no double-charge; charge only on perform).
- **Append-only everywhere** — odontogram/perio/diagnosis corrections and signed-note amendments all
  preserve prior state with a reason.
- **Billing correctness** — string-id detail (post-C-1), frozen credit-noted originals, blocked
  over-allocation, reversible allocations, gapless numbering (CN-2), real PDFs.
- **RBAC is airtight** — server Gate authoritative, nav-hiding cosmetic; the dental read/write and
  chart/fee-schedule/perform splits all enforce by URL for the right roles.
- **Kiosk PHI-safety, portal consent-lockout (immediate, styled 403), staff/patient shell
  separation** (no staff nav leaks into the portal).
- **Design consistency, sub-250 ms pages, zero console errors** on every working page.

---

## 10. Deliverability verdict

**Both the CLINIC and the DENTAL verticals are demo/deliver-ready on functionality, safety, and
correctness.** Every prior clinic fix (C-1/FIX.1–FIX.5, W8–W10) re-confirmed in a real browser; the
entire new dental vertical driven end-to-end with **every electric-fence, billing-reconciliation,
append-only, atomic-perform, and RBAC guarantee holding**. No Critical/High/Medium
functional/safety/RBAC/billing/data-integrity defect was found.

### Must-fix before delivery (Critical/High)
**None.** There is no safety, fence, billing, tenancy, RBAC, or data-integrity blocker.

### Strongly-recommended-before-a-dental-demo (not blockers, all category (a) — safe UI/seed work)
1. **Wire the patient → dental cross-link + a "Dental" nav/section** (G-1) — otherwise a dentist
   cannot reach the odontogram from the product.
2. **Add a dental demo seeder** (G-2) — otherwise the dental surface is empty in a sales demo.
3. **Link the portal treatment-plan** (L-B).

### Polish / later
- CSV import "Save mapping" trap (L-A); portal credit-note "Open/negative" display (L-C); the
  "GOVERNANCE" admin eyebrow (L-D); org_admin nav density (L-E); trim the 334 KB main bundle.
- Dental long-poles remain **partner-gated / non-goal** (live capture, DICOM, 3D, AI radiology) or
  **discovery-gated** (CH/KVG, dental insurance) — conversations and partnerships, not code.

The correct next move is unchanged from the project's standing focus: **deliver the built verticals**
(and, for the dental customer, ship the three (a)-class navigability/seed items above so the delivered
product is reachable and demo-credible), while the CH/KVG and partner-gated items proceed as
discovery/partnership tracks — not more speculative building.

---

*Audit performed live via Playwright MCP with payload-key and DB cross-checks. All data created
during the audit (a KB article, a settings edit, dental charts/procedures/plan/perio/diagnosis/image,
billing mutations, a withdrawn portal consent) was written to the throwaway demo tenants only; no
application code, route, controller, prop contract, or test was changed.*
