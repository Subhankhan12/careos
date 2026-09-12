# OPEN-WORK.md — the itemised open-work register

**As of QA-FIX.13b, 2026-09-12.** First written at `de57a7c` against `2b4ec48`; refreshed by QA-FIX.13b,
which closed `QF11a-M1` and recorded two new findings.

This is every piece of open work CareOS has, in one place: the 133 open QA findings itemised one per row,
plus the product decisions, the deliberately deferred work, the partner-gated work and the deployment
track. It is derived from `docs/qa/ROLE-AUDIT.md` (the authoritative append-only QA record), `DEFERRED.md`,
`DECISIONS.md` and `PROJECT-STATE.md`.

**It is a view, not a source.** `ROLE-AUDIT.md` remains the record; where this file and a summary disagree,
the artifact wins and the disagreement is written down below rather than smoothed over.

---

## 0. How this was counted, and the three discrepancies it found

Every number here was produced by **parsing `docs/qa/ROLE-AUDIT.md` itself** — matching each finding id to
its own record (a `####` heading or a `- **`id`` bullet), reading the resolution banner inside that
record's own block, and cross-checking against the `## Fix status` table at the top of the file. No summary
line, totals row, or `PROJECT-STATE.md` figure was trusted as input.

**The counted result:**

| | Count |
|---|---|
| Distinct finding ids in the artifact | **193** (185 `P*` + 8 `QF*`) |
| Findings with a record of their own | **193 / 193** — none orphaned |
| Per phase | P1 18 · P2 19 · P3 15 · P4 23 · P5 14 · P6 20 · P7 17 · P8 17 · P9 25 · P10 17 (+8 gate-raised) |
| Resolved | **60** |
| **Open** | **133** — C **0** · H **21** · M **81** · L **31** |
| Of the open, PARTLY FIXED | 2 — `P3-H2`, `P10-M1` |

**What QA-FIX.13b changed:** `QF11a-M1` closed (MEDIUM −1), and two new findings were recorded rather than
widened into — `QF13b-H1` and `QF13b-H2`, both HIGH (HIGH +2). 191 → 193 recorded, 59 → 60 resolved,
132 → 133 open. **The open HIGH count went UP, and that is the honest outcome of enumerating a surface
nobody had enumerated**: `QF11a-M1` itself said Clinical had never been surveyed.

The totals reconcile with `PROJECT-STATE.md` only after three discrepancies in the artifact are resolved.
All three are defects in the artifact's own bookkeeping, not in the underlying work.

**Discrepancy 1 — the `## Fix status` table (line 78) is STALE.** It stops at QA-FIX.10c. Thirteen
findings fixed by gates 11 and 12 appear nowhere in it and are recorded only by a banner on their own
record: `P1-H2` `P2-H3` `P3-H2` `P4-H1` `P4-H2` `P4-H4` `P6-H3` `P9-H1` `P9-H2` `P10-H1` `P10-H4` `P10-H5`
`QF10a-H1`. Gates 11a, 11b and 12a–12e each appended their own local fix-status block instead of extending
the table at the top, so the top-of-file table now understates the fixed count by 13. **Anyone reading only
that table would think 13 closed findings are still open.**

**Discrepancy 2 — nine findings are in the table with NO banner on their own record.** `P5-M4` and `P7-M5`,
plus the seven covered by the table's single "pattern 1 — the OVER-OFFER half" row: `P1-H1` `P2-H2` `P3-M7`
`P4-H5` `P5-H2` `P6-H4` `P7-H4`. They are genuinely fixed (QA-FIX.5b `88d50eb`, QA-FIX.8c `946cf87`,
QA-FIX.7d `c999181`); the gates recorded the fix in the table and did not go back to banner each record.
**Anyone scanning records for banners would think these nine are still open.**

**Discrepancy 3 — `QF12a-H1` is fixed in fact and carries NO banner at all.** Its record says it was
*"Recorded by QA-FIX.12a … and fixed in the same commit"* and carries a `**Fix.**` bullet, but no
resolution banner was appended and it is in no fix-status table. It is the one finding that both
mechanisms miss. **This is my own omission in QA-FIX.12a.** Counting it as fixed is what makes the
artifact yield 59/132; counting it by banner alone yields 58/133.

**How the 60 resolve:** 49 records carry a `✅ FIXED` banner (48, plus `QF11a-M1` from QA-FIX.13b) · 9 more
are fixed by the table only (discrepancy 2) · `QF12a-H1` is fixed with neither (discrepancy 3) · `P9-H1` is
`✅ PREVENTED` by QA-FIX.12d, and prevention was the gate's stated definition of a fix for a wedging defect.
The two `⚠️ PARTLY FIXED` findings — `P3-H2` and `P10-M1` — are counted **open**, as their own banners
instruct.

> **Correction to this register's first edition (`de57a7c`).** It described `P9-H1`'s banner as
> `🛑 PREVENTED`; the artifact reads `✅ PREVENTED`. The verdict was right, the emoji quoted was not —
> corrected here, and a reminder that this file is a derived view: for exact banner text, read
> `ROLE-AUDIT.md`.

> **Recommended housekeeping (not done here — this task is read-only):** append banners to the ten
> unbannered-but-fixed records and bring the top-of-file `## Fix status` table up to gate 12, so a reader
> can get the right answer from either mechanism alone.

### How to read the family and precedent columns

The artifact's *"open list, prioritised"* table groups findings into **seven families by shared remedy**.
It states family membership **for HIGH findings only**. Therefore:

- A family marked **†** is **stated in the artifact**. There are 17 such rows (family 1's sixteen, and
  `P3-H2` in family 2; `P10-M1` is named by family 3's row).
- Every other family assignment in this register is **derived here** from the finding's own record. The
  artifact asserts the MEDIUMs and LOWs *"thicken families 1, 3 and 5 above all and introduce no new
  family"*. **Counted out, that is half right.** Families 1 and 5 do absorb most of them — but the
  thickening lands on **1 and 5, not 3**; family 3 gains only 5 findings in total. And **9 findings fit
  none of the seven**: they are listed under *Outside the seven families* rather than forced into one.
- The italicised phrase after each description is a **sub-pattern**, derived here, for findings that
  cluster more tightly than their family does.

**The whole open set, by family (derived except where marked †):**

| Family | Open | H | M | L |
|---|---|---|---|---|
| **1** — unreachable capabilities & missing nav | **55** | 17 (16†) | 31 | 7 |
| **2** — operations that mislead, or that cannot be undone | **22** | 2 (1†) | 16 | 4 |
| **3** — invisible refusals | **5** | 1 | 2 (1†) | 2 |
| **4** — unrecorded disclosure | **3** | 0 | 2 | 1 |
| **5** — display / locale divergence | **32** | 1 | 20 | 11 |
| **6** — attribution recorded but not surfaced | **5** | 0 | 3 | 2 |
| **7** — partial writes outside a transaction | **2** | 0 | 2 | 0 |
| **outside the seven** | **9** | 0 | 5 | 4 |
| **Total** | **133** | **21** | **81** | **31** |

**Families 1 and 5 are two-thirds of everything open.** That is the scheduling fact this register exists
to surface: 87 of 133 findings are *"a capability you cannot reach"* or *"a value rendered wrongly"*.

**FIX or FEATURE** follows the QA-FIX.12d classification: a **FIX** changes code that already exists to
stop it misleading, losing or refusing wrongly; a **FEATURE** builds a capability that is not there. The
distinction is load-bearing — QA-FIX.6 Part 4 (`474cefe`) and QA-FIX.12d both STOPPED rather than build a
feature inside a fix gate, and this register keeps that line visible so the next gate can too.

---
## 1. Open HIGHs, by family — 19

**No open HIGH blocks deployment.** The artifact's standing verdict, unchanged by gates 12 and 13: none of
them loses data, falsifies a clinical or financial record, or breaches authorisation. They are defects of
reach, visibility, attribution-display and locale.

**Family 1 is 17 of the 21 and it is NOT one job.** **Seven of its seventeen are FEATURE work** — `P2-H4`
`P3-H3` `P5-H3` `P6-H2` `P7-H1` `P7-H3` and now `QF13b-H1`, where the capability does not exist or cannot
be reached and no amount of RBAC wiring creates it — and an eighth, `P9-H5`, is half of each. The other
nine are genuinely wiring, all against the one D-214 precedent. The artifact's own instruction stands:
**triage each before scheduling.** Families 4, 6 and 7 have no open HIGH at all.

**Across the whole 133: 119 are FIX, 12 are FEATURE, and 2 are part of each** (`P9-H5`, `P10-M1`). The 14
FEATURE-bearing findings are the ones a fix gate must **stop** on rather than build through — the
`474cefe`, QA-FIX.12d and QA-FIX.13b precedent.

### Family 1 — unreachable capabilities & missing nav — 17 open HIGH

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `P10-H3` | H | P10 | org_admin (any appointment.manage holder) | The waitlist auto-fill panel cannot make an offer for a cancelled slot — the only kind of slot it exists for *(wiring defect)* | `POST /scheduling/waitlist/offer` | 1† | none cited; the cause is pinned to `DayBoard.vue:171` | **FIX** |
| `P2-H4` | H | P2 | doctor · Clinical | The clinical chart cannot record anything the clinician is permitted to record *(no write affordance exists)* | `/clinical/chart/{patient}` | 1† | PARTIAL — D-214 closed the over-offer half only | **FEATURE** |
| `P3-H3` | H | P3 | billing | Write-offs and contractual adjustments cannot be created at all *(capability absent)* | `none exists` | 1† | none — the capability does not exist | **FEATURE** |
| `P3-H4` | H | P3 | pharmacist (holds billing.manage only) · Billing | The pharmacist is refused every billing read surface but can open invoice creation *(RBAC mismatch)* | `/billing/new-invoice` | 1† | D-214 (QA-FIX.7d, `c999181`) — the nav/permission map | **FIX** |
| `P5-H1` | H | P5 | pharmacist · Billing | The pharmacist can open /billing/new-invoice and can never bill a single thing on it (resolves P3-H4) *(RBAC mismatch)* | `/billing/new-invoice` | 1† | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P5-H3` | H | P5 | both | A dispense cannot be reversed, corrected or cancelled by any path *(capability absent)* | `Dispense.php:54-55` | 1† | none — no reversal path exists | **FEATURE** |
| `P6-H1` | H | P6 | surgical_scheduler | The Surgical Scheduler cannot open a single surgery route, and its two permissions gate a service with no controller *(RBAC mismatch)* | `RbacProvisioner.php:248-254` | 1† | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P6-H2` | H | P6 | surgeon | The theatre double-booking guard is correct, tested, and unreachable; the path the product actually uses has no overlap check at all *(guard with no reachable caller)* | `POST /surgery/cases` | 1† | QA-FIX.6 Part 4 (`474cefe`) determined this one and STOPPED | **FEATURE** |
| `P6-H5` | H | P6 | Surgery / operating theatre · Clinical | What the Phase-2 permission anomalies actually cost (the assigned follow-up) *(RBAC mismatch)* | `Modules/Clinical/src/Services/OrderService.php:229` | 1† | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P7-H1` | H | P7 | Emergency Department | No HTTP path registers an ED presentation: the vertical's entry point has no surface *(capability absent)* | — | 1† | none — no HTTP entry point at all | **FEATURE** |
| `P7-H3` | H | P7 | Emergency Department · Ed | The ED physician cannot prescribe anything, and the ED record has no medication surface at all *(capability absent)* | `/ed/visits/{v}/record` | 1† | none — ED has no medication surface | **FEATURE** |
| `P7-H5` | H | P7 | Emergency Department | No ED role can reach ED billing: all five routes are 403 for the entire group *(RBAC mismatch)* | `billing.manage (permission)` | 1† | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P8-H4` | H | P8 | Lab + Radiology | Lab and Radiology have no entry anywhere in the shell: 34 routes reachable only by typing a URL *(missing nav entry)* | `AppLayout.vue:35-46` | 1† | D-214 (QA-FIX.7d, `c999181`) — the same nav map | **FIX** |
| `P8-H5` | H | P8 | Lab + Radiology | Both billing surfaces are gated on a permission no role in this group holds *(RBAC mismatch)* | `billing.manage (permission)` | 1† | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P9-H4` | H | P9 | him_records | document.view gates nothing; clinical documents are gated by patient.view *(permission gates nothing)* | `GET /clinical/documents/{d}` | 1† | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P9-H5` | H | P9 | him_records | The records role cannot do records work: it is refused the only release action and cannot record consent *(RBAC mismatch)* | `POST /clinical/documents/{d}/share, POST /patients/{p}/consents` | 1† | D-214 for the release half | **FIX (release) · FEATURE (consent capture)** |
| `QF13b-H1` | H | QF13b | resources/js/pages/Clinical/Chart.vue | The AI clinical summary cannot be reached at all: its only entry point renders only after it has already been used *(capability unreachable (bootstrap deadlock))* | `POST /clinical/chart/{patient}/summary-draft` | 1 | none — the panel has no entry point outside its own output | **FEATURE** |

### Family 2 — operations that mislead, or that cannot be undone — 2 open HIGH

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `QF12b-H1` | H | QA-FIX.12b | nurse · Nurse PWA | A nurse's written observation is silently discarded if the note is queued before the check-in *(silent discard)* | `nurse-pwa/src/App.vue:79` | 2 | D-227 (QA-FIX.12b, `9b48dae`) fixed the server half of the same batch | **FIX** |
| `P3-H2` | H | P3 | billing · Billing | "PDF" invoices and dunning letters are plain-text files *(PARTLY FIXED — honesty half closed, capability half open)* | `/billing/invoices/{id}/pdf` | 2† | D-229 (QA-FIX.12d, `6df47ed`) withdrew the claim; D-176 no unbacked presence | **FEATURE** |

### Family 3 — invisible refusals — 1 open HIGH

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `QF13b-H2` | H | QF13b | Chart.vue (place order | Three sibling order endpoints still answer a domain refusal with HTTP 500 *(domain refusal escapes as HTTP 500)* | `Chart.vue` | 3 | D-224 (QA-FIX.11a, `51017e2`) — the narrow catch, three lines away in the same controller | **FIX** |

### Family 5 — display / locale divergence — 1 open HIGH

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `QF12c-H1` | H | QA-FIX.12c | surgeon / org_admin | The surgery scheduling form interprets the typed wall clock as UTC, so a case is stored an offset away from what the surgeon typed *(wall clock read as UTC)* | `POST /surgery/cases` | 5 | D-228 (QA-FIX.12c, `2e76387`) — `formatDateTime` + naive-instant normalisation | **FIX** |

## 2. Open MEDIUMs, by family / pattern — 82

82 open MEDIUMs. Family assignments here are **derived** (see §0) — the artifact states membership for
HIGHs only. The clusters worth taking as **one job** are named in the sub-pattern column, counted across
all severities:

| Cluster | Open | Precedent | Note |
|---|---|---|---|
| **RBAC mismatch** — a role 403 on the routes its own permissions name | **13** (H7 · M5 · L1) | D-214 | The single biggest cluster in the product, and all of it is wiring |
| **Responsive nav absent** below 768 px | **9** (M7 · L2) | **none** | No precedent exists, and one shell change closes every instance. The cheapest 9 findings on this list |
| **Date/time rendering** | **17** (M13 · L4) | D-091 · D-192/D-193 · D-228 | The helpers now all exist — `formatDateOnly`, `formatDateTime`, the tenant zone on the page — so this is adoption, not design |
| **Money display** | **6** (M6) | D-091 shape | 4 render money with no currency or a module-local formatter; **2 derive money page-side**, which is the more serious half |
| **Missing nav entry** — a module with no link anywhere | **5** (H1 · M4) | D-214 | Same nav map as the RBAC cluster; take them together |
| **Over-offer / role-blind picker** | **6** (M5 · L1) | D-214 | The half of pattern 1 that `c999181` did *not* close |
| **Capability absent** | **5** (H4 · M1) | none | **FEATURE work.** These do not belong in a fix gate |
| **Invisible refusal** | **3** (M1 · L2) | D-210/D-213 | `RefusalNotice` adoption; `P10-M1` (24 pages) needs decision **D3** first |



### Family 1 — unreachable capabilities & missing nav — 31 open MEDIUM

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `P1-M2` | M | P1 | reception (and admissions_clerk for the f… · App (UI) | Controls offered to roles that cannot use them; one strands the user *(over-offer)* | `resources/js/pages/App/Landing.vue:171-179` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P1-M4` | M | P1 | reception | No navigation at all below 768 px *(responsive nav absent)* | `all authenticated pages` | 1 | none — no responsive-nav precedent exists | **FIX** |
| `P1-M7` | M | P1 | all staff · Login | No link to password recovery from the login page *(unreachable capability)* | `/login` | 1 | none | **FIX** |
| `P10-M6` | M | P10 | Admin / governance + patient portal | Two of the product's narrowest guards cannot fire for any role that exists *(guard that cannot fire)* | `ai.manage (permission)` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P2-M1` | M | P2 | Clinician (doctor / dentist) · Governance | No clinician can reach the approval queue; only an administrator can approve an agent draft *(RBAC mismatch)* | `/governance/approvals` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P2-M2` | M | P2 | Clinician (doctor / dentist) | Five of the seven physician roles cannot prescribe *(RBAC mismatch)* | — | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P2-M4` | M | P2 | Clinician (doctor / dentist) · Clinical | No navigation below 768 px, and for this role Search is gone too *(responsive nav absent)* | `/clinical/chart/{id}` | 1 | none | **FIX** |
| `P2-M5` | M | P2 | Clinician (doctor / dentist) | Encounters are listed but cannot be opened *(unreachable capability)* | — | 1 | none | **FIX** |
| `P2-M6` | M | P2 | Clinician (doctor / dentist) · Dental | The dentist cannot view the dental fee schedule, but a pharmacist can *(RBAC mismatch)* | `/dental/fee-schedule` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P3-M8` | M | P3 | Billing / finance | No navigation below 768 px *(responsive nav absent)* | — | 1 | none | **FIX** |
| `P4-M1` | M | P4 | Nursing / Spitex + Nurse PWA · App | No navigation whatsoever below 768 px, and this phase's primary device is a phone. *(responsive nav absent)* | `/app` | 1 | none | **FIX** |
| `P4-M2` | M | P4 | Nursing / Spitex + Nurse PWA · Nursing | timesheet.approve and agreement.manage are granted to coordinator with no surface. *(permission with no surface)* | `/nursing/timesheets` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P4-M3` | M | P4 | Nursing / Spitex + Nurse PWA | note.supervise is granted to charge_nurse and enforced by nothing. No controller *(permission enforced by nothing)* | `note.supervise (permission)` | 1 | D-183 load-bearing guard in the service | **FIX** |
| `P4-M4` | M | P4 | Nursing / Spitex + Nurse PWA · Hospital | The ward board is reachable but unreferenced. /hospital/wards returns 200 for both *(missing nav entry)* | `/hospital/wards` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P4-M9` | M | P4 | Nursing / Spitex + Nurse PWA | The nurse role has no nursing surface in the web app at all. The only nursing web *(module with no surface)* | — | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P5-M1` | M | P5 | Pharmacy | The Pharmacy module appears in no navigation, for either role. Driven: the pharmacist's *(missing nav entry)* | `/pharmacy/formulary` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P5-M6` | M | P5 | Pharmacy | No navigation below 768 px. At 390×844 on the dispensing screen both nav links measure *(responsive nav absent)* | — | 1 | none | **FIX** |
| `P5-M7` | M | P5 | Pharmacy | An allergy cannot be recorded anywhere in the product. *(capability absent)* | — | 1 | none — no allergy write path exists anywhere | **FEATURE** |
| `P6-M1` | M | P6 | Surgery / operating theatre · app/ | No surgery permission exists in the shell's navigation map, so no role ever sees a surgery link *(missing nav entry)* | `app/Http/Middleware/HandleInertiaRequests.php:24-41` | 1 | D-214 (QA-FIX.7d, `c999181`) — the same nav map | **FIX** |
| `P6-M2` | M | P6 | Surgery / operating theatre | The landing page offers "Nursing dispatch" to all four surgery roles, and it 403s *(over-offer)* | `Landing.vue` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P6-M3` | M | P6 | Surgery / operating theatre · Surgery (UI) | The case detail offers a Billing link that 403s for both roles that can see the case *(over-offer)* | `resources/js/pages/Surgery/Case.vue:79-81` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P6-M6` | M | P6 | Surgery / operating theatre · Surgery | The surgeon, anesthetist and team dropdowns are role-blind: a pharmacy technician is offered as "Primary surgeon" *(role-blind picker)* | `/surgery/cases` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P7-M1` | M | P7 | Emergency Department | A triage nurse can record a DISCHARGE, while being unable to sign a note or place an order *(RBAC mismatch)* | `EdDispositionController.php:95` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P7-M2` | M | P7 | Emergency Department | Every staff picker in ED is role-blind and 20 entries long *(role-blind picker)* | `EdTriageController.php:75` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P7-M4` | M | P7 | Emergency Department · Ed | No navigation below 768 px, on a board that is plausibly a tablet *(responsive nav absent)* | `/ed/board` | 1 | none | **FIX** |
| `P7-M7` | M | P7 | Emergency Department | Two visit states and one disposition are unreachable in practice *(unreachable state)* | — | 1 | none | **FIX** |
| `P8-M5` | M | P8 | Lab + Radiology · Lab | /lab/results/review is reachable by four roles and permanently empty for three of them *(RBAC mismatch)* | `/lab/results/review` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P8-M6` | M | P8 | Lab + Radiology | Six data tables have no narrow-viewport behaviour, diverging from the rest of the app *(responsive nav absent)* | `Radiology/Orders.vue:65` | 1 | none | **FIX** |
| `P9-M11` | M | P9 | Bed management + medical records | Stay's declared state machine is dead code *(guard with no reachable caller)* | `BedService.php:91` | 1 | D-183 load-bearing guard in the service | **FIX** |
| `P9-M5` | M | P9 | Bed management + medical records | Bed management has no nav entry at any width, and its two clinical surfaces have no click path from anywhere *(missing nav entry)* | `AppLayout.vue:35-57` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P9-M6` | M | P9 | Bed management + medical records | Two ward.manage capabilities and two bed.manage capabilities have no HTTP surface at all *(permission with no surface)* | `ward.manage (permission)` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |

### Family 2 — operations that mislead, or that cannot be undone — 16 open MEDIUM

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `P1-M1` | M | P1 | reception · Scheduling | Two surfaces answer "how many are checked in?" differently; desk arrivals are invisible to reporting *(two surfaces disagree)* | `/scheduling/day-board, MetricsService::checkedInCount` | 2 | none | **FIX** |
| `P1-M5` | M | P1 | reception · Scheduling | The Quick-book modal cannot be dismissed by any mouse action *(no way back)* | `/scheduling/day-board` | 2 | none | **FIX** |
| `P10-M5` | M | P10 | Admin / governance + patient portal · Portal | The portal booking date field offers dates the server will refuse *(offers what the server refuses)* | `/portal/appointments` | 2 | D-194 (QA-FIX.1b) past-start guard | **FIX** |
| `P10-M7` | M | P10 | Admin / governance + patient portal | Approval executes the stored INPUT, never the proposed output the reviewer actually read *(executes what the reviewer never read)* | — | 2 | D-230 (QA-FIX.12e, `2b4ec48`) — the adjacent approval-ordering defect | **FIX** |
| `P2-M7` | M | P2 | Clinician (doctor / dentist) | An amended note's primary link opens the superseded version *(misleading link)* | — | 2 | D-193/D-197 — do not rewrite history; surface the current version | **FIX** |
| `P2-M8` | M | P2 | Clinician (doctor / dentist) | The order form renders in full against an empty catalogue, with no empty state *(no empty state)* | — | 2 | D-176 no unbacked presence | **FIX** |
| `P2-M9` | M | P2 | Clinician (doctor / dentist) | Session expiry mid-form discards typed input *(no way back)* | — | 2 | none | **FIX** |
| `P3-M5` | M | P3 | Billing / finance | "REMINDERS SENT" contradicts the "Prepared" rows beneath it *(contradictory copy)* | `dunning.sent (permission)` | 2 | D-176 no unbacked presence | **FIX** |
| `P3-M6` | M | P3 | Billing / finance | The account ledger says "every invoice" and omits credit notes *(incomplete claim)* | — | 2 | none | **FIX** |
| `P4-M10` | M | P4 | Nursing / Spitex + Nurse PWA | The visit detail renders a "Tasks" heading with no content and no empty state. The *(no empty state)* | — | 2 | D-176 no unbacked presence | **FIX** |
| `P4-M6` | M | P4 | Nursing / Spitex + Nurse PWA | The PWA never says whether it is online. The status text is identical online and *(status text says nothing)* | — | 2 | D-176 no unbacked presence | **FIX** |
| `P4-M8` | M | P4 | Nursing / Spitex + Nurse PWA | The two sync status lines contradict each other. After the P4-C3 wipe the header *(two surfaces disagree)* | — | 2 | none | **FIX** |
| `P6-M4` | M | P6 | Surgery / operating theatre | A case's checklist state is invisible from the case, so a completed case looks the same whether the checklist was run or not *(state invisible from the surface that owns it)* | — | 2 | none | **FIX** |
| `P6-M8` | M | P6 | Surgery / operating theatre | A confirmed checklist item can be un-confirmed after the case is closed, and the screen shows no history *(silent un-do, no history)* | `surgical_checklist.item_confirmed (permission)` | 2 | D-199 / the append-only discipline | **FIX** |
| `P9-M7` | M | P9 | Bed management + medical records | Nothing binds bed occupancy to a stay, and the board silently hides the second patient *(the board hides a patient)* | `2026_07_26_000003_create_stays_table.php:22-48` | 2 | D-229 (QA-FIX.12d, `6df47ed`) guarded the adjacent occupied-bed transition | **FIX** |
| `P9-M9` | M | P9 | Bed management + medical records | "Invoice this stay" 500s on any tenant that has not run the demo seeder *(unhandled failure on a shipped surface)* | `BedBillingController.php:32` | 2 | none | **FIX** |

### Family 3 — invisible refusals — 2 open MEDIUM

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `P3-M4` | M | P3 | Billing / finance | "Send reminders" gives no feedback and writes no audit row *(no feedback + unrecorded action)* | `billing.payment_plan_created (permission)` | 3 | D-210/D-213 `RefusalNotice` | **FIX** |
| `P10-M1` | M | P10 | Admin / governance + patient portal | Admin, governance and portal render almost no refusals: 13 withErrors sites, 3 of 24 pages that could show one *(PARTLY FIXED — approval queue only; 24 pages open)* | `Governance/ApprovalQueue.vue` | 3† | D-210/D-213 `RefusalNotice` — already at 12+ call sites | **FIX (adoption) · FEATURE (per-page design)** |

### Family 4 — unrecorded disclosure — 2 open MEDIUM

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `P10-M4` | M | P10 | Admin / governance + patient portal · Imports | Creating a patient is not audited at all, by any path *(unrecorded write)* | `/imports` | 4 | D-221/D-222 shape (the recorder) — though this is a WRITE, not a disclosure | **FIX** |
| `QF12a-M1` | M | QA-FIX.12a | raised by a fix gate | A live staff session in the same browser steals attribution for the patient's own portal reads *(disclosure mis-attributed)* | — | 4 | D-226 (QA-FIX.12a, `3c5fed1`) recorded it; `P10-L1` is the same defect | **FIX** |

### Family 5 — display / locale divergence — 20 open MEDIUM

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `P1-M3` | M | P1 | reception · Patients | The same date renders in three different formats, one of them US month/day in a de-CH tenant *(date format divergence)* | `/patients, /patients/{id}, /scheduling/appointments/{id}` | 5 | D-091 `formatDateOnly`; D-228 `formatDateTime` | **FIX** |
| `P10-M2` | M | P10 | Admin / governance + patient portal | The portal tells the patient what day it is in the VIEWER's timezone, so an appointment today is labelled "tomorrow" *(viewer-zone rendering)* | — | 5 | D-228 (QA-FIX.12c, `2e76387`) | **FIX** |
| `P10-M3` | M | P10 | Admin / governance + patient portal · Portal | Three portal pages print raw UTC timestamps beside a viewer-zone date *(raw UTC on a shipped page)* | `/portal/documents` | 5 | D-228 (QA-FIX.12c, `2e76387`) | **FIX** |
| `P2-M3` | M | P2 | Clinician (doctor / dentist) · Patients | The same kind of date renders in four formats, one of them US month/day in a de-CH tenant *(date format divergence)* | `/patients` | 5 | D-091; D-228 | **FIX** |
| `P3-M1` | M | P3 | Billing / finance · UI shell | The Swiss money formatter is used on 1 of 12 billing surfaces *(money display divergence)* | `resources/js/lib/money.ts` | 5 | D-091 shape — one shared formatter, all call sites | **FIX** |
| `P3-M2` | M | P3 | Billing / finance | Date-entry defaults come from the viewer's calendar, not the practice's *(viewer-zone default)* | `AccountDetail.vue:209-212` | 5 | D-228 (the tenant zone reaches the page) + D-091 | **FIX** |
| `P3-M3` | M | P3 | Billing / finance | Dates render in two formats inside the billing module *(date format divergence)* | — | 5 | D-091 `formatDateOnly` | **FIX** |
| `P4-M7` | M | P4 | Nursing / Spitex + Nurse PWA | locale is de and the entire UI is English. The /app payload carries *(locale divergence)* | `en.json` | 5 | none | **FIX** |
| `P5-M2` | M | P5 | Pharmacy · Pharmacy (UI) | Dates render in US format in the viewer's timezone. The dispensing history shows *(date format divergence)* | `resources/js/pages/Pharmacy/Dispensing.vue:35` | 5 | D-091; D-228 | **FIX** |
| `P5-M3` | M | P5 | Pharmacy | The pricing screen shows money with no currency at all. Verbatim: *"MED-AMOX-500 · *(money display divergence)* | — | 5 | D-091 shape — one shared formatter | **FIX** |
| `P7-M3` | M | P7 | Emergency Department | Dates and times render in US format in the viewer's timezone *(date format divergence)* | — | 5 | D-091; D-228 | **FIX** |
| `P7-M6` | M | P7 | Emergency Department · ED (UI) | The ED billing surface derives money client-side *(money derived page-side)* | `resources/js/pages/ED/Billing.vue:39` | 5 | D-091 shape — money from the server, formatted once | **FIX** |
| `P8-M1` | M | P8 | Lab + Radiology | Timestamps render in the viewer's zone and US format; the tenant's zone is shipped and read by nothing *(viewer-zone rendering)* | `Lab/Orders.vue:37` | 5 | D-192/D-193 + D-228 (the tenant zone is already shipped) | **FIX** |
| `P8-M2` | M | P8 | Lab + Radiology | Both billing pages do money arithmetic in the view layer while claiming they do not *(money derived page-side)* | `Lab/Billing.vue:92` | 5 | D-091 shape — money from the server | **FIX** |
| `P8-M3` | M | P8 | Lab + Radiology | No currency is shipped to either billing page, so every figure is a bare number *(money display divergence)* | `Invoice.php:31,74` | 5 | D-091 shape | **FIX** |
| `P8-M7` | M | P8 | Lab + Radiology | The stat priority is coloured on three pages and not on the fourth *(display divergence)* | `Lab/Orders.vue:100` | 5 | none | **FIX** |
| `P9-M1` | M | P9 | Bed management + medical records | The admission page prints raw ISO-8601, and two Hospital pages disagree about when the same event happened *(raw ISO, and two surfaces disagree)* | `Admission.vue` | 5 | D-228 (QA-FIX.12c, `2e76387`) | **FIX** |
| `P9-M2` | M | P9 | Bed management + medical records | Observations render as bare chips: no timestamp, no unit, no direction *(display divergence)* | `StayChart.vue:17` | 5 | D-228 (QA-FIX.12c, `2e76387`) | **FIX** |
| `P9-M3` | M | P9 | Bed management + medical records | The discharge summary prints an amount with no currency, through a module-local formatter *(money display divergence)* | `DischargeSummary.vue:77-79` | 5 | D-091 shape — one shared formatter | **FIX** |
| `P9-M4` | M | P9 | Bed management + medical records | The date-only day-shift D-091 exists to prevent, in a page written after the fix *(date-only day shift)* | `DischargeSummary.vue:73-75` | 5 | D-091 `formatDateOnly` — the helper this page did not use | **FIX** |

### Family 6 — attribution recorded but not surfaced, or resolved by convenience — 3 open MEDIUM

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `P1-M6` | M | P1 | reception | A staff reschedule is recorded as "booked online" *(attribution by convenience)* | `POST /scheduling/appointments/{id}/reschedule` | 6 | D-216/D-220 (QA-FIX.11b, `18786eb`) | **FIX** |
| `P6-M5` | M | P6 | Surgery / operating theatre | Team membership records no attribution, and cannot distinguish planned from present *(attribution not recorded)* | `SurgicalCaseService.php:112` | 6 | D-216/D-220 (QA-FIX.11b, `18786eb`) | **FIX** |
| `P6-M9` | M | P6 | Surgery / operating theatre | The actor is stored on every case event and usage row, and displayed on none of them *(attribution recorded, never surfaced)* | `surgical_case_events.performed_by (permission)` | 6 | D-216/D-220 (QA-FIX.11b, `18786eb`) — `P8-H3` is the same shape, already fixed | **FIX** |

### Family 7 — partial writes (create-then-associate outside a transaction) — 2 open MEDIUM

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `P4-M5` | M | P4 | Nursing / Spitex + Nurse PWA | The outbox sequence is allocated non-atomically and collides. *(non-atomic allocation)* | `dayPackStore.ts:194` | 7 | D-199 one operation, one transaction | **FIX** |
| `P9-M8` | M | P9 | Bed management + medical records | "Invoice this stay" is four independently committed transactions; a failure at the last step leaves an orphan draft and a retry builds a second *(partial write)* | `charge.invoice_id (permission)` | 7 | D-199 one operation, one transaction — proven six times | **FIX** |

### Outside the seven families — 5 open MEDIUM

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `P1-M8` | M | P1 | all | Redis unavailable ⇒ HTTP 500 on login, not a handled degradation *(unhandled dependency failure)* | `POST /login` | — | none | **FIX** |
| `P5-M5` | M | P5 | Pharmacy | No batch or expiry tracking exists in the model. medication_stocks has no batch, lot *(domain model absent)* | — | — | none — the model has no batch/expiry columns | **FEATURE** |
| `P6-M7` | M | P6 | Surgery / operating theatre · Surgery | Surgical counts are a tick-box, not numbers, so a discrepancy cannot be recorded *(domain model absent)* | `/surgery/cases/{case}/supplies` | — | none — the record cannot express the fact | **FEATURE** |
| `P8-M4` | M | P8 | Lab + Radiology · Lab | A lab result is published by the act of entering it: there is no release or verification step *(lifecycle step absent)* | `/lab/results/review` | — | none — no release/verification step exists | **FEATURE** |
| `P9-M10` | M | P9 | Bed management + medical records | The nightly accrual has no error handling and leaks tenant context on failure *(unattended-path robustness)* | — | — | D-229 shape — wrap the unattended path | **FIX** |

## 3. Open LOWs — 31

31 open LOWs. Same derivation caveat as §2. Several are literally the same defect in a second phase
(`P1-L3` / `P2-L1`; `P10-L1` / `QF12a-M1`) and should be closed together.

### Family 1 — unreachable capabilities & missing nav — 7 open LOW

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `P1-L5` | L | P1 | Reception / front-desk · Hospital | admissions_clerk has no route to her own core function. She holds *(RBAC mismatch)* | `/hospital/wards` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P2-L5` | L | P2 | Clinician (doctor / dentist) · Dental | A medical practice is offered dental charting for every patient *(over-offer)* | `/dental` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P3-L2` | L | P3 | Billing / finance · Billing | The New-invoice breadcrumb points at a surface the role cannot open *(breadcrumb to an unreachable surface)* | `/billing/new-invoice` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P5-L1` | L | P5 | Pharmacy · Billing | /billing/new-invoice's only breadcrumb is a dead end for the role that can reach it. *(breadcrumb to an unreachable surface)* | `/billing/new-invoice` | 1 | D-214 (QA-FIX.7d, `c999181`) | **FIX** |
| `P8-L1` | L | P8 | Lab + Radiology | No navigation below 768 px *(responsive nav absent)* | `AppLayout.vue:113` | 1 | none | **FIX** |
| `P9-L1` | L | P9 | Bed management + medical records · App | No navigation below 768 px, ninth phase — and newly load-bearing *(responsive nav absent)* | `/app` | 1 | none | **FIX** |
| `P9-L4` | L | P9 | Bed management + medical records · app/ | BreakGlassService has no production consumer, and the governance dashboard lists an action nothing emits *(an action nothing emits)* | `app/Services/BreakGlassService.php` | 1 | D-176 no unbacked presence | **FIX** |

### Family 2 — operations that mislead, or that cannot be undone — 4 open LOW

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `P1-L1` | L | P1 | Reception / front-desk · Scheduling (UI) | Waitlist "Fill a freed slot" lists slots that are not free. The picker offers every *(offers what is not available)* | `resources/js/pages/Scheduling/DayBoard.vue:341` | 2 | none | **FIX** |
| `P10-L2` | L | P10 | Admin / governance + patient portal · Portal | The portal booking form pre-selects the first service, with no placeholder *(pre-selected first entry)* | `/portal/appointments` | 2 | D-211 no form-default attribution (the `P10-H4` fix) | **FIX** |
| `P8-L3` | L | P8 | Lab + Radiology | The lab result form has no client-side required, and the exam select pre-selects the first catalog entry *(pre-selected first entry)* | `Radiology/Orders.vue:32` | 2 | D-211 no form-default attribution (the `P10-H4` fix) | **FIX** |
| `P9-L2` | L | P9 | Bed management + medical records | The portal consent screen advertises three scopes nothing seeds, checks or enforces *(advertises what nothing enforces)* | `Portal/Consents.vue:33-39` | 2 | D-176 no unbacked presence | **FIX** |

### Family 3 — invisible refusals — 2 open LOW

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `P3-L1` | L | P3 | Billing / finance | A refused escalation says nothing *(invisible refusal)* | — | 3 | D-210/D-213 `RefusalNotice` | **FIX** |
| `P4-L3` | L | P4 | Nursing / Spitex + Nurse PWA | The PWA login failure surfaces only as a console Error: nurse.login.failed. On the *(invisible refusal)* | — | 3 | D-210/D-213 shape, PWA-side | **FIX** |

### Family 4 — unrecorded disclosure — 1 open LOW

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `P10-L1` | L | P10 | Admin / governance + patient portal | A portal read is attributed to the staff user when a staff session exists in the same browser *(disclosure mis-attributed)* | — | 4 | D-226 (QA-FIX.12a, `3c5fed1`); `QF12a-M1` is the same defect | **FIX** |

### Family 5 — display / locale divergence — 11 open LOW

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `P1-L2` | L | P1 | Reception / front-desk | Inbox timestamps are raw UTC; appointment history is tenant-local. A message stored *(raw UTC)* | — | 5 | D-228 (QA-FIX.12c, `2e76387`) | **FIX** |
| `P1-L3` | L | P1 | Reception / front-desk | The allergy block renders twice on Patient 360 — once in the dark hero tile *(duplicate rendering)* | — | 5 | none | **FIX** |
| `P1-L4` | L | P1 | Reception / front-desk | Ungrammatical action-panel heading. Cancelling shows "REASON FOR CANCEL *(copy defect)* | — | 5 | none | **FIX** |
| `P2-L1` | L | P2 | Clinician (doctor / dentist) | The allergy is rendered twice on Patient 360 *(duplicate rendering)* | — | 5 | none — the same defect as `P1-L3` | **FIX** |
| `P2-L2` | L | P2 | Clinician (doctor / dentist) | <html lang="de"> while the entire interface is English *(locale divergence)* | — | 5 | none — the same root as `P4-M7` | **FIX** |
| `P2-L4` | L | P2 | Clinician (doctor / dentist) | Age is abbreviated differently on the medical and dental charts *(display divergence)* | — | 5 | none | **FIX** |
| `P4-L1` | L | P4 | Nursing / Spitex + Nurse PWA | "Last synced" prints raw millisecond ISO (2026-09-06T15:20:10.761Z) to a field *(raw ISO)* | — | 5 | D-228 (QA-FIX.12c) + `nurse-pwa/src/visitTime.ts` | **FIX** |
| `P6-L1` | L | P6 | Surgery / operating theatre | A fifth date mechanism: ISO string-slicing, which prints stored UTC verbatim *(a fifth date mechanism)* | `Case.vue:64` | 5 | D-228 (QA-FIX.12c, `2e76387`) | **FIX** |
| `P7-L1` | L | P7 | Emergency Department | The board's elapsed time is computed client-side and does not tick *(client-side clock)* | `Board.vue:68` | 5 | D-228 (QA-FIX.12c, `2e76387`) | **FIX** |
| `P7-L2` | L | P7 | Emergency Department · UI shell | The ed.triage.level translation key is null and unused *(dead i18n key)* | `resources/js/lang/en.json` | 5 | none | **FIX** |
| `P8-L2` | L | P8 | Lab + Radiology | The amendment reason is collected through a native prompt() dialog *(native dialog instead of the app shell)* | — | 5 | none | **FIX** |

### Family 6 — attribution recorded but not surfaced, or resolved by convenience — 2 open LOW

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `P9-L3` | L | P9 | Bed management + medical records | One report, two renderings: the Patient-360 tab shows raw actor ids where the PC.P5 screen shows names *(raw ids where names exist)* | `patient.view (permission)` | 6 | D-216/D-220 (QA-FIX.11b, `18786eb`) — `P8-H3` is the same shape | **FIX** |
| `P9-L5` | L | P9 | Bed management + medical records | A bed's status has no provenance anywhere in the product *(no provenance)* | `WardBoardController.php:68-83` | 6 | D-216/D-220 (QA-FIX.11b, `18786eb`) | **FIX** |

### Outside the seven families — 4 open LOW

| ID | Sev | Phase | Role / module | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `P1-L6` | L | P1 | Reception / front-desk | "Sex" and "Gender" are free-text inputs on registration. Server validation is only *(validation / data quality)* | — | — | none | **FIX** |
| `P2-L3` | L | P2 | Clinician (doctor / dentist) | Validation runs before authorization, leaking the schema to unauthorized callers *(schema leak before authorisation)* | — | — | D-183 load-bearing guard in the service | **FIX** |
| `P4-L2` | L | P4 | Nursing / Spitex + Nurse PWA · Api | 500 responses from /api/nurse/sync return the full Laravel stack trace (exception *(error disclosure)* | `/api/nurse/sync` | — | none | **FIX** |
| `P5-L2` | L | P5 | Pharmacy | Enoxaparin is in the formulary, priced and prescribed, but has no stock row. It appears *(seed / data inconsistency)* | — | — | none | **FIX** |

## 4. Findings raised BY the fix gates — 8 recorded, 5 open

Eight findings were recorded **by the fix gates themselves** rather than by a QA phase — the standing rule
since QA-FIX.9a is to record what a gate notices outside its own scope and **not widen into it**. Three are
fixed, five are open. They are listed again here because they are easy to lose: they belong to no phase, so
a phase-ordered read of the artifact never reaches them.

**This group now accounts for 5 of the 133 open findings, and 3 of the 21 open HIGHs.** It is the fastest-
growing group in the register, which is what a rule that forbids widening produces: each gate closes its
own target and leaves a written note where it had to look. `QF13b-H1` and `QF13b-H2` both came out of
QA-FIX.13b enumerating `Clinical/Chart.vue` in order to adopt one component on it.

| ID | Sev | Raised by | Status | What is open | Route or `file:line` | Family | Precedent | FIX / FEATURE |
|---|---|---|---|---|---|---|---|---|
| `QF10a-H1` | H | QA-FIX.10a | ✅ fixed — QA-FIX.12a `3c5fed1` | The nurse day-pack streams a home-visit photo or signature with no audit row of any kind | `GET /api/nurse/attachments/{attachment}/download` | 4 | D-221/D-222, D-226 | — |
| `QF11a-M1` | M | QA-FIX.11a | ✅ fixed — QA-FIX.13b (D-231) | Clinical's own pages render no error bag either, and QA-FIX.11a has now routed a real refusal to two of them | `Chart.vue` | 3 | D-210/D-213 `RefusalNotice` — a pure adoption | — |
| `QF12a-H1` | H | QA-FIX.12a | ✅ fixed — QA-FIX.12a `3c5fed1` (**no banner — discrepancy 3**) | The nurse day-pack attachment route has never worked: implicit model binding of a tenant-owned row 500s for every caller | `GET /api/nurse/attachments/{attachment}/download` | 4 | D-221/D-222, D-226 | — |
| `QF12a-M1` | M | QA-FIX.12a | 📋 **open** | A live staff session in the same browser steals attribution for the patient's own portal reads | — | 4 | D-226 (QA-FIX.12a, `3c5fed1`) recorded it; `P10-L1` is the same defect | **FIX** |
| `QF12b-H1` | H | QA-FIX.12b | 📋 **open** | A nurse's written observation is silently discarded if the note is queued before the check-in | `nurse-pwa/src/App.vue:79` | 2 | D-227 (QA-FIX.12b, `9b48dae`) fixed the server half of the same batch | **FIX** |
| `QF12c-H1` | H | QA-FIX.12c | 📋 **open** | The surgery scheduling form interprets the typed wall clock as UTC, so a case is stored an offset away from what the surgeon typed | `POST /surgery/cases` | 5 | D-228 (QA-FIX.12c, `2e76387`) — `formatDateTime` + naive-instant normalisation | **FIX** |
| `QF13b-H1` | H | QF13b | 📋 **open** | The AI clinical summary cannot be reached at all: its only entry point renders only after it has already been used | `POST /clinical/chart/{patient}/summary-draft` | 1 | none — the panel has no entry point outside its own output | **FEATURE** |
| `QF13b-H2` | H | QF13b | 📋 **open** | Three sibling order endpoints still answer a domain refusal with HTTP 500 | `Chart.vue` | 3 | D-224 (QA-FIX.11a, `51017e2`) — the narrow catch, three lines away in the same controller | **FIX** |

## 5. Open product decisions awaiting the owner — 14

**None of these is a defect and none is a build task.** Each needs a decision from the product owner
before any gate can act; several are explicitly cheap to decide **now**, because no customer is live.
For each: what is being decided, and what it costs either way.

### 5.1 — Decisions that a QA finding is blocked on

| # | Decision | Source | Cost of A | Cost of B |
|---|---|---|---|---|
| **D1** | **`P3-H2` — produce a real PDF, or keep calling it text?** The forged `%PDF-1.4` header is gone and both download surfaces now serve `text/plain` honestly. The capability half is open. | ROLE-AUDIT `P3-H2` banner · D-229 | **Build it:** a new PDF dependency (CareOS has no PDF library — a test asserts none was added) + a laid-out invoice template + a decision about the `.txt` invoices already stored and downloaded. | **Leave it text:** invoices and dunning letters remain plain-text files. Nothing is broken and nothing is claimed falsely — but a practice sending a patient a `.txt` bill is a commercial problem, not a technical one. |
| **D2** | **`P9-H1` — recovery for a bed wedged under a patient.** QA-FIX.12d made the wedge **unreachable** (an occupied bed can no longer be moved to `cleaning`). Rows already wedged in a live database have no product path out. | ROLE-AUDIT `P9-H1` `🛑 PREVENTED` banner · D-229 | **Build recovery:** a supervised bed-state override with its own audit shape — a feature, and one that must not become a way to move a patient by editing a column. | **Prevention only (today):** no live tenant has a wedged row, and the demo tenant's only admitted patient was deliberately not stranded by driving the fix. Cost is zero until a wedge predates the guard. |
| **D3** | **`P10-M1` — refusal rendering on the other 24 pages.** QA-FIX.11a fixed the approval queue; each remaining page needs a **replace-or-duplicate** decision (does `RefusalNotice` replace that page's existing inline error, or sit beside it?). | ROLE-AUDIT `P10-M1` banner · D-210/D-213 | **Decide per page:** design work, 24 pages, but the component already exists and is proven at 12+ call sites. | **Leave it:** refusals stay invisible on 24 pages. Nothing is written wrongly; the user is simply not told. |
| **D4** | **The operative-note attribution question.** Is an operative note meant to be attributed to the **responsible surgeon by design** (as the operating record), or to whoever typed it? Only a surgical reviewer can settle this. | DEFERRED.md · D-195 | **Attribute to the typist** (the `QA-FIX.2a` shape, `StaffProfile::forUser`): consistent with every other note path; may be wrong for an operating record. | **Keep the surgeon:** defensible as a legal operating record, but then `BedsideChartService`'s identical shape (admitting clinician) must be judged separately, and the divergence documented so nobody "fixes" it later. |

### 5.2 — Decisions about historical data (all cheap now, expensive after go-live)

| # | Decision | Source | Cost of A | Cost of B |
|---|---|---|---|---|
| **D5** | **The historical +2 h skew — rewrite it or not?** Rows written on web paths before QA-FIX.1a carry tenant-local wall clock in UTC columns (`audit_events.occurred_at`, `messages.created_at`, `appointments.created_at`/`status_changed_at`, web-minted `expires_at`). Scope is narrower than it looks: **web group only** — the API group never had the mutation and portal requests self-skipped. | D-193 · ROLE-AUDIT "Open decisions raised by the fixes" | **Leave as-is (recommended):** history stays off by one tenant offset for a bounded window (CLINIC.W8b → QA-FIX.1a); `verifyChain()` stays intact. | **Correct it:** a data migration over append-only, hash-chained tables — the per-row offset depends on the tenant zone *and* the DST state on that date, and rewriting `occurred_at` either breaks `verifyChain()` or requires re-hashing the chain. Needs its own gate and a base-marker column. |
| **D6** | **Notes written under the old attribution — rewrite or not?** 24 notes across four demo tenants (earliest 2026-08-25) carry the appointment's practitioner as author. 2 show author ≠ signatory and both are the legitimate radiology shape. | D-197 | **Leave as-is (recommended):** the audit chain holds the truth (`encounter.opened` / `note.signed` carry the real `actor_id`) and the immutability guard stays intact. | **Correct it:** `ClinicalNote::updating` refuses any change to a signed note, so it cannot go through the model at all; and the true author is recoverable only by reconstruction from the audit chain. Rewriting a signature-bearing clinical row from a reconstruction is not a quiet operation. |

### 5.3 — Decisions about a convention the code has not declared

| # | Decision | Source | Cost of A | Cost of B |
|---|---|---|---|---|
| **D7** | **What is `appointments.starts_at`'s time base?** It holds a **naive local wall clock**; six `where('starts_at', '>=', now())` comparisons lined up by accident while web `now()` was also local, and are now uniformly off by the tenant offset. **The portal cancel window is the only one that LOOSENS** — a patient can cancel closer to the appointment than the configured minimum. None of the six sites has a test. | D-192 · DEFERRED.md | **Declare and migrate:** a data migration with its own gate, then fix the six sites against the declared base with a test each. | **Patch the call sites:** cheaper, but it entrenches an undeclared convention instead of naming it — the D-191 shape, where an unnamed value invites whatever meaning the next caller needs. |
| **D8** | **Invert `BookingService::book()`'s past-start default?** It is permissive because it is simultaneously the interactive booking path **and** the historical-recording path. The four interactive paths `P1-H3` covers are strict; waitlist-accept and recurring-series are not, and any future interactive caller inherits the permissive default. | D-194 · DEFERRED.md | **Invert it:** the permissive case becomes visible at every site that uses it — but breaks both demo seeders and 13 existing behaviour fixtures that legitimately book elapsed dates, which must be updated in the same gate. | **Leave it:** a guard whose default fails **open**. Narrow in practice (the slot finder cannot produce a past slot; a waitlist offer's TTL is ~30 min), but the wrong direction for a guard. |
| **D9** | **Unify `author_id` and `signed_by`?** On one row `author_id` is a ULID FK to `staff_profiles.id` while `signed_by` is an integer `users.id`; `tooth_records.charted_by` and `orders.ordered_by` are also `users.id`, so `author_id` is the odd one out. | D-196 | **Unify:** a migration over a versioned, append-only clinical table plus every reader of both columns — its own gate. | **Leave split:** the risk is demonstrated, not theoretical — an early Phase-2 query compared a ULID against the integer column, MySQL coerced it to `1`, and the audit briefly "found" a note authored by *Test User*. |
| **D10** | **On the payments screen, should a refused allocation keep the receipt?** Today it does, and the operator is told (redirect to `billing.payments.show`). `payments` is append-only, so an unallocated receipt can only be refunded or left unallocated. | D-199 · DEFERRED.md | **Keep today's behaviour:** coherent front-desk workflow — cash arrived, record it now, fix the allocation next. The operator is *told*, but not *asked*. | **Offer the choice:** a confirmation before keeping an unallocated receipt. **Do NOT simply copy QA-FIX.3a's transaction here** — that would silently discard a receipt for money that physically arrived, which is the opposite failure. |

### 5.4 — Standing product decisions, not raised by QA

| # | Decision | Source | Cost of A | Cost of B |
|---|---|---|---|---|
| **D11** | **⚠️ The password policy.** The effective policy is `Password::default()` — minimum 8 characters, no `Password::defaults()` configured anywhere, so **no mixed-case, digit, symbol or breach check**. The reset flow correctly enforces whatever is configured. | DEFERRED.md · PROJECT-STATE.md · D-152 | **Strengthen it:** one `Password::defaults()` call; costs a decision about existing accounts and about breach-check egress (a third-party API call per password). | **Leave it:** 8 characters with no complexity rule on a system holding PHI. Deliberately not slipped into the AUTH-SEC security sprint — it is the owner's call, not a security engineer's. |
| **D12** | **Is an "all patient records" operator scope ever permitted?** Currently **fail-closed — no wildcard exists in any form** (`*`, `all`, `ALL`, `any`, `%`, empty lists and blank ids are all refused), so the only way to reach a record is to have named it. **Answer before G4 if it might be "yes".** | DEFERRED.md · PROJECT-STATE.md · D-164 | **Permit it:** a support engineer can act without knowing the record id in advance; a tenant's entire record set becomes reachable under one grant. | **Keep fail-closed:** support must name what it needs. Costs friction in a genuine emergency — which is why **D13** exists. |
| **D13** | **The Operator Mode open sub-decisions:** who may be an operator (today: any user with `tenant_id = null`) · the request/session windows and any extension cap · whether there is an emergency no-owner-reachable path — **and if there is to be none, say so explicitly so nobody improvises one** · whether `BreakGlassGrant` keeps its own self-grant model. | DEFERRED.md · D-164 | **Decide now:** cheap; G4–G11 are not built, so nothing must be unwound. | **Defer to G4:** the decisions arrive mid-build, when the surface exists and changing them is expensive. |
| **D14** | **Does the PATIENT see that a portal reply was agent-drafted?** Staff-side provenance is settled (COMMS.P1 names the human sender); the patient-facing half is not. | DEFERRED.md · PT.P4 | **Disclose:** honest, and consistent with the product's draft-until-approved posture; may unsettle patients who read "AI" as "not my clinician". | **Do not disclose:** a human approved and sent every word, so the statement "your clinician replied" is true — but the product then holds a fact about the patient's own message that it does not tell them. |

---
## 6. Deliberately deferred work

**Deferred is not open.** Everything here was parked by an explicit decision with a written trigger; none
of it is a defect and none is queued. Full detail and triggers live in `DEFERRED.md`.

### 6.1 — Deferred with a named trigger

| Item | Trigger | Source |
|---|---|---|
| **Operator Mode G4–G11** — elevated-session mechanics, mid-session revoke + expiry sweep + session receipt, and ~7 operator/owner screens. Backend is **inert: zero HTTP routes**. Adds no safety property G1–G3 do not already enforce. | The first customer is live — then resume at G4, reading `docs/features/OPERATOR-MODE-MAP.md` first | D-164 |
| **Waitlist Management page** — audited, fix chain not started. ~70 % renders an already-rich backend. **🔴 The blocker: `WaitlistService::create()` has exactly one caller in the repo (`DemoClinicSeeder`)** — no route, no controller, no UI. Without the write path the page is a viewer for seeded rows. Scope is decided: **omit** the auto-send tier (the tool ceiling is `APPROVE`; `AUTO` is unreachable) and **seam** SMS/phone. | After deployment, or a customer whose reception workflow needs it. **Priority: below DEPLOY** | D-191 · `WAITLIST-MANAGEMENT-DIFF.md` |
| **APPT.P4 — room/resource capability field.** The wireframe drew "scanner · X-ray" chips; `Resource` has no capability field. Also blocks Dental chair scheduling. | A customer needs capability-based room selection | DEFERRED.md |
| **APPT.P5 — preferred-practitioner slot filter.** `AvailableSlotFinder` takes no preferred-resource parameter; offering the toggle would fabricate a filter the engine cannot honour. | A customer needs to book against a preferred practitioner | DEFERRED.md |
| **Dense-grid accessibility sweep** (odontogram, perio, ward board, ED board, eMAR). A11Y.1/D-148 fixed the two named Low findings; a full keyboard/focus/contrast/ARIA audit remains. | An accessibility requirement from a customer or procurement, or a public-sector deployment | D-148 |
| **Realistic-volume load / performance test.** The audits ran on modest demo volume — no N+1 symptom observed, but that is not a load assessment. | Before onboarding a high-volume customer, or the first slow board under real data | DEFERRED.md |
| **Full per-widget timezone display.** QA-FIX.12c declared the display zone and shipped it to the day-pack and the surgery board; per-widget rendering everywhere else is the remainder — and is exactly what family 5's open MEDIUMs enumerate. | A customer operating across more than one timezone | D-192 · D-228 |
| **Resource-availability admin screen.** Rooms/chairs/resources have CRUD; editing their availability windows has no admin UI (seeded/managed at the data layer). | A customer needs to self-manage resource availability | CLINIC.W8c |
| **Real Swiss QR-bill** (IBAN + structured reference payment part). No QR-bill renderer exists; ARDETAIL.P3 surfaced the existing invoice output honestly rather than faking a payment part. **Adjacent to D1 and to the CH/KVG pack.** | A Swiss customer needs real QR-bill payment slips | DEFERRED.md |
| **Finer Swiss payer taxonomy.** `arByPayer` groups over the real modelled `payer_type`; the wireframe's 4-way split (supplementary / accident SUVA-UVG / social-municipal) and an insurer entity are not modelled and not fabricated. | A customer needs the finer split | BILLAR.P4 |
| **CH / KVG billing pack.** Swiss Spitex reimbursement is probably **not** cash-pay but KVG/KLV insurance + canton/municipal contributions + patient co-pays — a third billing model the built EU-Generic pack does not cover. **Currently a hypothesis; KVG rules unverified.** The deferred item most likely to become the real first build. | Discovery confirms the KVG/canton route **and** a design partner needs real Spitex billing | `docs/DISCOVERY.md` |
| **AGENT.P6 confidence threshold.** Deferred because **the codebase has no confidence/uncertainty signal at all** (grep-confirmed) — a threshold control would be a phantom. The escalation floor itself is always-on and un-removable. | A real confidence signal exists to threshold against | AGENT.P6 |
| **Send-QR-bill / send-reminder from the AR account page.** Sending stays inside `DunningService` + the agent-cap/ApprovalQueue path. | A customer workflow needs page-initiated sending — design idempotency + the agent cap first | DEFERRED.md |
| **A real SMS notification-preference provider.** SETTINGS.P5 built the email store + gate and rendered SMS as an honest disabled seam. | A customer needs SMS notifications **and** a chosen provider | SETTINGS.P5 |
| **Telehealth recording + transcripts.** Recording is disabled at the provider level, no media columns exist, grants pin `recorder=false`. | A customer requirement **and** a completed consent/retention design — never enable without both | D-G2 |
| **Realtime (Laravel Reverb)** for inbox / day-board / presence. | Polling latency becomes a real complaint | DEFERRED.md |
| **Multi-language content.** i18n scaffolding exists; clinical/UI copy is English only. *(This is the root of open findings `P4-M7` and `P2-L2`.)* | A customer/market in that language **plus** a native reviewer for clinical copy | DEFERRED.md |
| **Portal payment processing (PSP).** G.5 shows invoices read-only. | Customers want online payment **plus** a chosen PSP | DEFERRED.md |
| **Phase H agents** (full RAG front-desk, ops-analyst, onboarding). | Design partners ask, or a repeated manual pain a specific agent removes | DEFERRED.md |
| **AI-credits metering & billing.** `ai_interactions` already ledgers every call and the budget gate caps spend; invoicing it is a separate product decision. | A paying customer **plus** a decision to charge for AI | DEFERRED.md |
| **Real routing for nurse travel** (replace the straight-line estimate). | A nurse reports the estimate is wrong in practice | E.3 |
| **Cross-tenant referrals** via explicit share objects — **never scope-widening**. | Two customer tenants that need to refer to each other | D.5 |
| **Staff rostering / shift planning.** Not built and not planned: CareOS schedules appointments and visits, not shifts. | A customer needs real shift rostering — treat it as a new vertical and map it first | DEFERRED.md |
| **Clinician countersigning for nurse visit notes.** | — (comes after E.7) | E.7 |
| **Later dental gates** (chair-view, sterilization/inventory, ortho/aligner tracking) · **live dental imaging capture** · **3D scan overlay** · **licensed dental code sets**. | A dental customer whose workflow needs them; for imaging, a specific device **plus** a funded integration | DENTAL.G8/G9 |
| **Playwright transport-layer offline test.** The airplane-mode exit proof today is a Laravel API end-to-end test plus the PWA Vitest suite. | Pull forward when prepping the sales demo | DEFERRED.md |
| **Production vector search for KB RAG** · **expanded AI prompt eval harness** · **Meilisearch swap for FULLTEXT** · **qualified e-signature** · **SSO/SAML** · **white-label** · **silo tenancy tier** · **Capacitor wrappers** · **payroll connectors** · **voice receptionist** · **route optimization (OR-tools)** · **WhatsApp channel** · **SMS/WhatsApp reminder drivers** · **e-prescription rails** · **US X12 claims** · **US EVV aggregator exports** · **multi-tenant same-email membership**. | Each carries its own trigger in `DEFERRED.md` | DEFERRED.md |

### 6.2 — Pre-deploy SHOULD-FIXes (non-blocking; the four BLOCKERS were closed by DEPLOY.PROV)

| Item | Why it is non-blocking | Trigger |
|---|---|---|
| **S1 — `audit:ensure-partitions` is not scheduled.** `audit_events` is RANGE-partitioned monthly; the maintenance command is absent from `routes/console.php`. **It degrades rather than fails** — a `p_max VALUES LESS THAN (MAXVALUE)` catch-all absorbs every row past the last real partition, so inserts keep working. What is lost is pruning, retention-by-partition and query locality, silently accumulating into one growing partition. **The one scheduler item still unwired; the other 9 commands are wired and asserted by `ScheduleRegistrationTest`.** | Inserts never fail | Before go-live, or the first time audit retention matters. **One scheduler line.** |
| **S2 — the four demo seeders carry no production guard.** No `App::environment()` check, no abort. | They are **not** in `DatabaseSeeder` (pinned by a test in `ProvisioningCommandsTest`), so `db:seed --force` is safe and required in production. A demo tenant can only appear if someone explicitly types `--class=Demo…`. | Cheap hardening whenever convenient — add an `app()->environment('production')` refusal to each seeder |
| **Least-privilege DB user for `audit_events`.** Production should run under a DB user with UPDATE/DELETE revoked (defence in depth). | Dev uses root, so the append-only BEFORE UPDATE/DELETE triggers are the active guard today | Production hardening |
| **MySQL 8 migration** of the dev database (MariaDB 10.4 is EOL; prod target is MySQL 8), and **validate patient-name search parity** — dev MariaDB uses plain FULLTEXT while MySQL 8 CI/prod uses `WITH PARSER ngram`, so names tokenize differently. | CI already runs MySQL 8 | Before production |
| **Laravel 13 / PHP 8.3+** upgrade. | PHP 8.2 security support ends ~Dec 2026 | When convenient |

### 6.3 — Declined, not deferred (D-188) — these are **not** gaps

| Screen | Why declining beats a reduction |
|---|---|
| **Consent-Blocked Draft · Opt-in Confirmed · Request Consent Update** (Comms) | They rest on per-topic and per-channel consent, a household grouping, and a campaign feature. The product has ONE outbound consent (`comms.email`, per patient, all-or-nothing for non-legal mail) and no household or campaign model. A reduced version — a topic list holding one topic, a channel matrix with one live column — would teach staff to promise what the practice cannot keep. |
| **No-Show Follow-Up** (Scheduling) | Its organising idea is *triage by clinical risk*. Nothing records that and computing it is the electric fence's central prohibition. A plain no-show **worklist** is buildable and should be specified as its own screen. |

---
## 7. Partner-gated work

**Each of these is already threaded through the built code as a null-object seam, so the wiring is proven
and a certified partner can drop in.** They are business conversations, not gates. **A homemade version is
a permanent NON-GOAL, not a backlog item** — the electric fence held through ten phases of adversarial
driving and this register does not reopen it.

| Seam | Bound implementation today | What a partner supplies | Trigger |
|---|---|---|---|
| **`MedicationSafetyProvider`** — drug interaction / dose / contraindication | null object; called at medication **ordering** (PHARMACY.G2) and **administration** (PHARMACY.G3); surfaces a partner's findings, **never auto-blocks** | A certified drug-database engine | A licensed drug-database partner **and** a funded regulatory track |
| **`LabConnectivity`** — HL7/FHIR transmission + automated result ingestion (**LAB.G7**) | `ManualLabConnectivity` — transmit is a no-op, automated ingestion throws | A real HL7/FHIR client against a specific lab's interface | A customer using a specific lab/analyzer **and** a funded integration (plus a licensed source for any coded catalog) |
| **`ImagingConnectivity`** — PACS/DICOM + diagnostic viewer (**RAD.G6**) | `NullImagingConnectivity` — no image storage, streaming or viewer | The vendor's PACS/DICOM product | A customer with a PACS **and** a funded DICOM integration |
| **`TriageAcuityProvider`** — ED triage acuity | Null provider; **the seam stays empty by design** — acuity is a clinician-ASSIGNED value, never computed | A certified triage product | None for a homemade version — **do not build** |
| **Anaesthesia intra-op device-data feed** | Noted + stubbed, not built (a `Modules\Surgery\src` grep asserts no `DeviceFeed`/`AnesthesiaMachine`/`hl7` code). Anaesthesia **documentation** (ASA record, op notes) IS built | HL7/device ingestion from the anaesthesia machine / patient monitor | A customer with specific devices **and** a funded integration |
| **Insurance / claims clearinghouse** | Not built | Market-specific claims transport | A signed customer in that market |
| **Live dental imaging capture** (X-ray sensor / intraoral scanner) | DENTAL.G8 ships manual upload + a 2D viewer + a dentist-authored reading | The vendor device SDK/driver | A customer with a specific sensor **and** a funded integration |

### Permanent medical-device NON-GOALS — never build the homemade version, at any trigger

Homemade **drug-interaction / dose / contraindication checking** · **AI radiology / caries / pathology
detection** on any image (CADe/CADx is a regulated device) · **computed perio staging/grading** ·
**computed surgical-risk or early-warning scores** (a homemade NEWS/MEWS/EWS or surgical-risk predictor;
a *blocking* safety checklist is likewise a non-goal — the WHO checklist RECORDS completion, it never
gates the case) · **computed triage acuity** · **AI anywhere in the clinical-decision path**. A diagnosis
or finding is clinician-authored; AI stays in the ops/admin lane, draft-until-approved and autonomy-capped.

---
## 8. Deployment — the one queued track

**Verdict: 🟢 GO** (`docs/DEPLOY-READINESS-CHECK.md`, moved from 🟡 CONDITIONAL by DEPLOY.PROV / D-165).
**The QA programme did not change that verdict: no open finding loses data, falsifies a clinical or
financial record, or breaches authorisation.**

### Ready and rehearsed

| Asset | Where |
|---|---|
| Deployment runbook (with a MUST-FILL `.env` section, catalog seeding in the release sequence, both provisioning commands documented) | `docs/DEPLOY-RUNBOOK.md` |
| Deploy-readiness reconciliation against the eight-vertical code | `e9f9247` |
| Production `.env` template — placeholders only, no secrets | `f74a318` |
| Rehearsed per-customer onboarding | `docs/ONBOARDING-REHEARSAL-REPORT.md` |
| First-customer provisioning — `plans:seed` · `tenant:create` · `tenant:add-admin` (the first-org_admin bootstrap the invite flow structurally cannot do). All refuse rather than half-create; mandatory 2FA still applies to the bootstrapped admin; the production seed path stays demo-free | `b006d07` · D-165 |
| Four demo seeders for sales/demo tenants (Clinic · Spitex · Dental · Hospital) | `database/seeders/` |

**⚠️ Skipping `plans:seed` silently turns every feature off.** It is not optional.

### Still to do

1. Stand up a Linux host.
2. Wire a real email transport.
3. Supply a production LiveKit key/secret and production config/secrets.
4. Import each customer's data via the P0P.G6 CSV tool, and onboard.
5. **S1** — add the one `audit:ensure-partitions` scheduler line (§6.2), before go-live.

### ⚠️ The one known unknown

**An undiagnosed staging error was hit earlier and never debugged. No detail about it was captured
anywhere.** Expect to reproduce it from scratch as the **first** real step of the deployment track. **Do
not assume the deploy path is clean merely because the suite and CI are green** — they were green when it
happened.

---
## Appendix — what this register deliberately does NOT do

- **It fixes nothing.** No application code was read for repair and none was changed. Producing it was
  read-only by instruction.
- **It does not re-triage.** Severities are the artifact's. Where I disagreed with one I left it alone —
  re-grading a finding in a summary document is how a record loses its authority.
- **It does not invent work.** Every row traces to a record in `docs/qa/ROLE-AUDIT.md`, an entry in
  `DEFERRED.md`, a decision in `DECISIONS.md`, or the deployment section of `PROJECT-STATE.md`. Nothing
  here is a suggestion of mine that no source states.
- **It does not supersede `ROLE-AUDIT.md`.** That file stays the record. This one goes stale the moment
  the next gate lands, and the three discrepancies in §0 are the proof that a derived view must always
  say how it was derived.
