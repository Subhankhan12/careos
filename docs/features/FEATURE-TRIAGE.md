# FEATURE-TRIAGE.md — sizing the remaining HIGH features

**As of `c1c0010`, 2026-09-12. READ-ONLY: producing this map changed no application code and built nothing.**

This sizes the eleven items that the open-work register classifies as FEATURE work (plus the two deliberate
stops and pattern 1's grouped-navigation half) so that a product owner can **choose** which to build. It is a
sizing document, not a plan, and not a recommendation — the one section that states my own reading is marked
as such and kept separate from the facts.

Sources: `docs/OPEN-WORK.md` (the live register), `docs/qa/ROLE-AUDIT.md` (the authoritative QA record),
`DECISIONS.md`, and the code at `c1c0010`. Every layer claim below was read in the repository.

---

## 0. The headline, before the detail

**Most of these are not features. They are surfaces missing from finished engines.**

Five of the eleven have a complete, tested, permission-gated service with **zero production callers** — the
work is a route, a controller and a form. Three are genuinely unbuilt. One is a permission grant that the
register mislabelled. One is a dependency choice. One is a nav file.

| | Item | Built | What is actually missing |
|---|---|---|---|
| 1 | **`P9-H5`** consent capture | **100%** | *A permission grant.* **Not a feature — see §1.9** |
| 2 | **`NAV-GROUPED`** | ~95% | Nav entries for six built modules |
| 3 | **`QF13b-H1`** AI summary reach | ~90% | One reachable control + a date-range decision |
| 4 | **`P7-H3`** ED prescribing | ~85% | A link to an existing engine + one permission |
| 5 | **`P3-H3`** write-offs | ~80% | Route + controller + UI |
| 6 | **`P7-H1`** ED arrival | ~75% | Route + controller + form |
| 7 | **`P2-H4`** chart recording | ~70% avg | Varies per row — but **every row has a service** (see §1.5) |
| 8 | **`P3-H2`** real PDF | 85% delivery / **0% format** | A dependency + a template + a decision |
| 9 | **`P9-H1`** wedge recovery | 55% mechanism / **0% capability** | A supervised override — **and nobody is blocked today** |
| 10 | **`P6-H2`** theatre overlap | ~40% / ~5% | A duration model, a theatre surface, a slot lifecycle |
| 11 | **`P5-H3`** dispense reversal | ~30% | A compensating record — clinical **and** money |

**The single most reusable fact in this document:** a feature that is 80 % built is a completely different
decision from one that is 0 % built, and the register's flat `FEATURE` label hides that difference entirely.

---

## 1. The eleven, sized

Each entry: what exists · what is missing · what it touches · the owner's decision · size · who it unblocks ·
what breaks if it is never built.

### 1.1 `P3-H3` — Write-offs and contractual adjustments cannot be created at all

**EXISTS (9 layers).** `Modules/Billing/src/Services/AdjustmentService.php` — `writeOff()` (:41),
`contractualAdjustment()` (:47) and **`reverse()`** (:56), authorizing `billing.manage` (:189), taking
`InvoiceBalance` under `lockForUpdate` inside one transaction. Model `InvoiceAdjustment.php`, migration
`2026_08_28_000001_create_invoice_adjustments_table.php`. **Four test files**, of which
`tests/Feature/Billing/WriteOffAdjustmentTest.php` alone asserts: an append-only ledger movement that
reconciles **δ=0**; a contractual adjustment with an agreement reference; *balance = charges − payments −
adjustments − write-offs*; operator-gating; an **over-adjustment guard**; append-only correction by reversal
row; and a positive control that a payment-only invoice still reconciles.

**MISSING (3 layers).** Route · controller · UI. Verified: the only non-test references to `AdjustmentService`
anywhere are docblock `{@see}` mentions in the migration, the model and `PaymentService.php:317`.

**It is worse than "unreachable" — the product already reports on it.**
`resources/js/pages/Billing/Report.vue:112-113` pushes `{key:'adjustments'}` and `{key:'writeOffs'}` into the
AR roll-forward, labelled at `resources/js/lang/en.json:2685-2686`. **A shipped report renders two columns
that can only ever read zero.** And `DunningService::setPaused` (`:118`) has no route either, so an
uncollectible invoice can be neither written off **nor** paused from the product — it duns forever.

**TOUCHES.** Money — the invoice balance projection and the AR roll-forward. Routes through `AuditService`
(`billing.written_off` / `billing.adjusted`), `PaymentService`'s locked-balance transaction, and the
reconciliation unit. No clinical record, no disclosure.

**OWNER DECISION.** *Which permission may write off a debt?* `AdjustmentService` enforces `billing.manage`
today — but `billing.manage` is **also held by the pharmacist** (`RbacProvisioner.php:214`) **and the doctor
set** (`:106`). (a) Reuse it: zero RBAC work, but every doctor and pharmacist can forgive debt. (b) Introduce
a narrower key (the `billing.escalate` precedent, deliberately narrower than `billing.manage`): one new
permission, a migration to re-provision, and a decision about who holds it.

**SIZE.** **One gate**, wiring only. Closest precedent: **ARDETAIL.P6** (`3ddc7ab`) — a guarded service
surfaced behind a narrow permission with an audited, append-only write.

**UNBLOCKS.** The billing role group and `org_admin`. **IF NEVER BUILT:** bad debt can never leave the ledger;
AR ages forever; two report columns stay permanently zero; dunning chases money nobody intends to collect.

### 1.2 `P3-H2` — "PDF" invoices and dunning letters are plain-text files

**EXISTS.** The whole delivery pipeline: renderers, storage, both download routes (staff and portal), the UI
labels, the permission. **PARTLY FIXED** by QA-FIX.12d (`6df47ed`) — the forged `%PDF-1.4` header is gone, the
first line now says *"plain text — not a PDF"*, paths are `.txt`, and both surfaces serve
`text/plain; charset=utf-8`.

**MISSING.** The **format**: a PDF dependency (composer.json has none — dompdf/tcpdf/mpdf/snappy all absent)
and a laid-out invoice template.

**TWO CONSEQUENCES A FIXING GATE MUST PLAN FOR.**
1. **It must DELETE a test assertion, not add one.** `tests/Feature/Qa/MisleadingAndWedgingTest.php:233`
   asserts *"no PDF library was added and none is faked"* (`:241-243`). Adding the dependency deliberately
   falsifies a guard this programme wrote — a **CORRECTION**, and it must be flagged as one.
2. **Every historical invoice already carries a `.txt` path.** `InvoicePdfRenderer` is called **inside the
   issue transaction** (`Modules/Billing/src/Services/IssueService.php:128`) and its return is stamped into
   `pdf_path` (`:144`) on the gapless-numbering path that freezes the invoice.

**TOUCHES.** Money (the artefact of an issued invoice), the append-only issue path, the portal disclosure
surface. **OWNER DECISION.** *What happens to invoices already issued as `.txt`?* (a) Leave them — history
stays text, new ones are PDFs, two formats forever. (b) Re-render on download — the bytes change after
issuance, on a record deliberately frozen. (c) Migrate — a data migration over frozen financial records.

**SIZE.** **One gate**, but its risk is a dependency and a design artefact rather than code volume.
**UNBLOCKS.** The billing role, and the **patient** who receives the artefact. **IF NEVER BUILT:** a practice
sends patients `.txt` bills. Nothing is false any more — it is a commercial problem, not a technical one.

### 1.3 `P7-H1` — No HTTP path registers an ED presentation

**EXISTS (9 layers).** `EdVisitService::register(User, Patient, Branch, string $arrivalMode, string
$chiefComplaint, ?Carbon $arrivedAt)` at `Modules/ED/src/Services/EdVisitService.php:39` — authorizes
`ed.manage` (:47), fail-closed tenant assertions (:48-49), one transaction creating the visit **and** its
`arrived` flow event (:53-72). Migration `create_ed_visits_table`, append-only `ed_visit_events`, the legal
state machine (`EdVisit.php:85`, `status` deliberately outside `$fillable`). Audit already fires:
`AppServiceProvider.php:485` writes `ed_visit.registered`. **Nine ED test files** drive `register()` directly.
Even the form's hardest i18n exists — `en.json:498-502` ships `walk_in` / `ambulance` / `referral`.

**MISSING.** Route · controller action · form · nav entry. Verified: all 15 ED routes
(`routes/web.php:520-554`) take an existing `{visit}`; the only non-test callers of `register()` in the
repository are **four lines of `DemoHospitalSeeder`**.

**The permission ships a description of the missing feature.** `RbacProvisioner.php:80` reads
`'ed.manage' => 'Register ED visits and advance their flow'` — an act no HTTP path performs.

**A SECOND GAP THE FORM ALONE WOULD NOT CLOSE.** `ed_physician` holds `patient.view` but **not**
`patient.edit` (`RbacProvisioner.php:263-264`), and patient creation is gated on `patient.edit`
(`PatientRegistrationController.php:19,:29`). So ED staff still could not register an arrival for a walk-in
**who is not already in the system** — the commonest ED case.

**TOUCHES.** An operational/clinical record, two audit rows, the tenant fence. No money, no disclosure.
**OWNER DECISION.** *Can ED staff create a patient record at the door?* (a) Grant `patient.edit` to ED roles —
matches reality, widens who may edit demographics. (b) Keep it separate — reception registers the patient,
ED registers the visit; correct for a hospital with a front desk, wrong for an ambulance at 03:00.

**SIZE.** **One gate** for the form; the patient question is a permission decision that can ship with it.
Closest precedent: SURG.G1's case-scheduling surface. **UNBLOCKS.** The entire ED role group at **step one**.
**IF NEVER BUILT:** the ED vertical cannot begin. Every other ED surface — board, triage, documentation,
disposition, billing — presupposes a visit that only a seeder can create.

### 1.4 `P7-H3` — The ED physician cannot prescribe, and the ED record has no medication surface

**EXISTS (~85%).** A complete, tested prescribing engine in Pharmacy: `MedicationOrderController`
(`:67`, gating `medication.prescribe`), `MedicationOrderService`, its model and migration, and the
administration half (eMAR). **MISSING:** any ED path to it, and the permission for any ED role.

**TWO ASYMMETRIES THAT MAKE THIS SHARPER THAN IT LOOKS.**
1. **The giving half already works for ED.** eMAR administration gates on `note.write`
   (`MedicationAdministrationController.php:78`), which all three ED roles hold. **An ED nurse can already
   record administering a drug that no ED physician could order.**
2. **The prescribing surface has no inbound link from anywhere.** The route name
   `pharmacy.patient-medications` appears only inside its own controller — so this is a link problem twice
   over, not only for ED.

**TOUCHES.** A clinical record (a medication order), the medication-safety seam (`MedicationSafetyProvider`,
advisory, never auto-blocking), audit. **OWNER DECISION.** *Which ED roles prescribe?* (a) `ed_physician`
only — matches the doctor precedent. (b) Also `ed_charge_nurse` — matches some real EDs, widens a clinical
authority. **Note:** `provisionTenant()` uses `sync()` (`RbacProvisioner.php:371`), so any grant re-provisions
every tenant and replaces each system role's permission set wholesale.

**SIZE.** **One gate**, smaller than `P7-H1` if scoped patient-wise. **UNBLOCKS.** `ed_physician`.
**IF NEVER BUILT:** the ED documents and dispositions patients but cannot treat them pharmacologically — a
vertical that records care it cannot order.

### 1.5 `P2-H4` — The clinical chart cannot record anything the clinician is permitted to record

**This is five features wearing one ID, and the per-row number is the decision.**

| Chart row | Service | Route | Chart control | Verdict |
|---|---|---|---|---|
| **Orders** | ✅ | ✅ | ✅ | Already works — the finding says so |
| **Notes** | ✅ `ClinicalNoteService` | ✅ (off-chart editor) | ❌ | **Cheapest** — one entry point |
| **Problems** | ✅ `ClinicalListService::recordProblem` (`:28`) | ❌ | ❌ | Route + controller + control |
| **Vitals** | ✅ `ClinicalListService::recordVital` (`:106`) | ❌ | ❌ | Route + controller + control |
| **Documents** | ✅ `DocumentService` | ✅ download/share | ❌ upload UI anywhere | Upload surface |
| **Medications** | ⚠️ two stacks | ❌ chart route | ❌ | See below |

> **CORRECTION — AND IT MAKES THIS ITEM MUCH CHEAPER.** An earlier pass of this map recorded Problems and
> Vitals as having *no service at all*, on the strength of a search for files named `ProblemService.php` /
> `VitalService.php`. **That was a naming assumption, and it was wrong.** Both live in one class:
> `Modules/Clinical/src/Services/ClinicalListService.php` — `recordProblem()` (`:28`), `recordAllergy()`
> (`:67`), `recordVital()` (`:106`), `readListsForPatient()` (`:148`). **No route anywhere reaches it**
> (verified across `routes/`, `app/Http`, `Modules/*/src/Http`). So Problems and Vitals are not features —
> they are the **same "finished engine with no front door" shape as `P3-H3`, `P7-H1` and `P6-H2`**, and
> `P2-H4` belongs in Cluster A.
>
> **AND IT CLOSES A SEPARATE FINDING FOR FREE.** `recordAllergy()` is in the same class, equally unreachable
> — which is the whole cause of **`P5-M7`** (*"an allergy cannot be recorded anywhere in the product"*,
> MEDIUM). **One route + controller + panel over `ClinicalListService` closes three chart rows and `P5-M7`
> together.**

**THE MEDICATIONS ROW HIDES A STRUCTURAL PROBLEM.** The chart's Medications tab reads
`Modules\Clinical\Models\Medication` (`ClinicalChartController.php:137`); Pharmacy prescribing writes
`Modules\Pharmacy\Models\MedicationOrder`. **The two stacks write different tables and are not connected.**
Linking them is a data-model decision, not a UI one — and it is the same gap as `P7-H3`.

**TOUCHES.** Clinical records, signing/locking, audit, the medication-safety seam. **OWNER DECISION.**
*Which rows ship first?* (a) Notes only — restores the single most-used act, smallest gate. (b) Notes +
Vitals + Documents — a chart a clinician can actually work in. (c) All five — requires the medications
data-model decision first.

**SIZE.** One gate for (b); the Problems and Medications rows are their own gates. **UNBLOCKS.** Every
clinician role — `note.write` is held by every doctor and every nurse. The most central surface in the
product. **IF NEVER BUILT:** clinicians read the chart and record elsewhere; the chart becomes a viewer.

### 1.6 `QF13b-H1` — The AI clinical summary cannot be reached at all

**EXISTS (~90%).** Route, controller, validation, the agent, the tool, the permission, tests. **MISSING:** a
control outside `v-if="aiSummary"`, and a date range. The deadlock (verified in QA-FIX.13b, re-verified here):
`aiSummary` comes only from the flash a *successful* draft writes; the only POST is the refresh button; the
button renders only inside `v-if="aiSummary"`. **No summary → no button → no POST → no summary.**

**TOUCHES.** A clinical draft (never auto-inserted — human insert only), the AI ledger, the approval ceiling.
**OWNER DECISION.** *What is "since last visit"?* (a) The previous completed encounter's `started_at` → now:
matches the tool's name; undefined for a first visit. (b) A fixed window (e.g. 90 days): always defined, not
"since last visit". (c) A user-picked range: honest, one more control.

**SIZE.** **Smallest of the set** — a control, a range, and tests. **UNBLOCKS.** Every `note.write` holder,
but it is a convenience, not a workflow step. **IF NEVER BUILT:** a built, tested, permission-gated AI feature
ships permanently unreachable — the product pays for an engine nobody can start.

### 1.7 `P5-H3` — A dispense cannot be reversed, corrected or cancelled by any path

**EXISTS.** Nothing of the reversal. `DispensingService` has exactly three public methods — `dispense`,
`historyForPatient`, `onHandForItem`. **MISSING:** service method, route, controller, UI.

**IMMUTABILITY IS STRUCTURAL, SO A REVERSAL CANNOT BE A STATUS FLIP.** The `dispenses` table has **no status
column at all**; immutability is enforced by **two MySQL triggers** plus a model guard. A reversal must
therefore be a **compensating record**, exactly like `AdjustmentService::reverse()` — which is a strong,
already-proven in-repo pattern to copy.

**IT IS ALSO A MONEY QUESTION.** A dispense creates a `charge` (eager-loaded in the history at `:96`). And
`ChargeCaptureService::cancel()` (`:84`) **already exists and is tested**
(`tests/Feature/Billing/ChargeCaptureTest.php:327`, asserting both success and the invoiced-charge refusal) —
a second built-but-unreached reversal engine.

**TOUCHES.** A clinical record, stock levels, money, audit. **OWNER DECISION (must be answered before a line
is written — the `474cefe` model).** *When a dispense is reversed, does the money come back, and who may make
it?* (a) **Clinical only** — reverse the dispense, leave the charge; the patient is billed for a drug not
given. (b) **Clinical + credit** — route through `ChargeCaptureService::cancel()`; correct, but gives a
pharmacist a money-reversing power. (c) **Two-step** — pharmacist reverses clinically, billing credits
separately; safest, slowest, and can desynchronise. **Plus:** does stock return?

**SIZE.** One decision, then **two gates** (clinical reversal; the billing arm). **UNBLOCKS.** `pharmacist`
and `pharmacy_technician`. **IF NEVER BUILT:** a mis-dispense is permanent in the record and on the bill; the
workaround is a manual credit note and a note in free text.

### 1.8 `P6-H2` — The theatre double-booking guard is correct, tested, and unreachable

**EXISTS.** `TheatreSchedulingService::bookSlot` with `assertNoOverlap` (:105) and `lockTheatre`, covered by
`TheatreSchedulingTest` **and `TheatreBookingParallelHammerTest`** (a real concurrency hammer). **MISSING:**
every caller — the only ones are the seeder, a console command that exists to drive the hammer, and tests.
The product's actual path is `POST /surgery/cases` → `SurgicalCaseService`, which never touches it.

**FOUR BLOCKERS, AND THE FIRST GATES THE REST.**
1. **A surgical case has no duration.** `create_surgical_cases_table` has `scheduled_at` (:33) and **no
   duration column** — so a slot (start *and* end) cannot be derived from a case. *(Near-miss: the product
   does have one place a human types theatre minutes — `SurgicalBillingController.php:94` — but that is
   billing, after the fact.)*
2. No theatre management surface exists (list/create theatres).
3. **Nothing in the repo can ever change a theatre slot** — `TheatreSlot` defines statuses and
   `BLOCKING_STATUSES` (`:35-49`) but no release/cancel path, so a booked slot is permanent.
4. `surgical_scheduler` cannot even open `/surgery/cases` — it is `Gate::authorize('surgery.manage')`, which
   the role does not hold (that is `P6-H1`, a FIX).

**QA-FIX.6 Part 4 (`474cefe`) already sized this once and STOPPED**, building nothing. That determination
stands; blocker 4 above is new.

**TOUCHES.** Scheduling's overlap/locking discipline, the surgical record, audit. **OWNER DECISION.** *Does a
surgical case get a duration, and where does it come from?* (a) A typed `duration_minutes` — one migration,
one field, a surgeon guesses. (b) A procedure catalog with standard durations — correct, and a catalog is its
own feature (G1 deliberately left `procedure_description` free text).

**SIZE.** **Three gates** (duration + theatre CRUD · booking wiring · slot lifecycle), plus the `P6-H1`
permission fix. **THE MOST EXPENSIVE ITEM IN THIS DOCUMENT.** **UNBLOCKS.** `surgical_scheduler` as a role.
**IF NEVER BUILT:** theatre double-booking is prevented by nothing on the path the product uses; two surgeries
can be scheduled into one theatre and only a human will notice.

### 1.9 `P9-H5` (consent-capture half) — **NOT A FEATURE. The register is wrong.**

**EXISTS: 100 % of the capture path.** Migration, model, service, tests, routes
(`POST /patients/{patient}/consents` and `.../withdraw`, `routes/web.php:161-163`), controller, and a
**staff-side UI** in `resources/js/pages/Patients/Show.vue`.

**THE ONLY BLOCKER IS A PERMISSION.** `PatientConsentController` gates on `Gate::authorize('patient.edit')`
(`:16` and `:37`); `him_records` holds `patient.view`, `note.supervise`, `document.view`, `audit.view` —
**not** `patient.edit`.

**OWNER DECISION.** *How should the records role record consent?* (a) Grant `patient.edit` to `him_records` —
one line, but also grants demographic editing. (b) Introduce a narrower `consent.manage` key — a new
permission, two `Gate::authorize` changes, a re-provision migration.

**SIZE.** Option (a): one line plus a role-template test — **smaller than any gate in the recent log.**
**RECOMMENDATION FOR THE REGISTER:** reclassify `P9-H5` as **FIX (both halves)**, not `FIX · FEATURE`.

### 1.10 `P9-H1` wedge recovery — built as mechanism, absent as capability, **and urgent for nobody**

**CONTEXT.** QA-FIX.12d (`6df47ed`, D-229) **PREVENTED** the wedge: an occupied bed can no longer be moved to
`cleaning`. Recovery from an already-wedged row was deliberately not built, and the banner says what a fixing
gate would need.

**THE DECISIVE FACT.** **Zero wedged rows are known to exist, no production deployment exists, and the wedge
can no longer be created through the product.** The only way to hold one is a database that predates
`6df47ed`.

**OWNER DECISION.** *Is recovery worth building before a customer can possibly have a wedge?* (a) Not now —
cost zero until a pre-`6df47ed` database exists. (b) A console command now — cheap insurance, and it must not
become a way to move a patient by editing a column.

**SIZE.** One gate for a console command + tests + an audit action. **UNBLOCKS.** **Today: nobody.**
**IF NEVER BUILT:** if a wedge ever exists, a bed is unusable and the fix is a manual `UPDATE` — precisely
what the audit trail exists to prevent.

### 1.11 `NAV-GROUPED` (pattern 1's second half) — six built modules with no nav entry

**EXISTS (~95%).** Server, gates, controllers, pages and permission grants are complete for all six modules —
**Lab, Radiology, Surgery, Pharmacy, bed management, ED**. 34+ routes are reachable **only by typing a URL**.
**MISSING:** nav entries.

> **⚠️ THE BRIEF'S PREMISE IS WRONG, AND THIS MATTERS.** The task describes this as *"blocked by D-111's
> 10-item cap"*. **D-111 (`DECISIONS.md:1317-1333`) never states a cap.** It *split* org_admin's 15 flat
> items into 10 top-level + 5 under an "Admin" dropdown; the constraint it actually verified is
> *"nav height 40px = single row (no wrap)"* **at 1440px desktop**. Ten is the size of the day-to-day group
> that split produced, not a ceiling. **Nothing blocks adding a second dropdown.**

**TOUCHES.** Presentational only. D-111's own precedent is explicit that gating is unchanged: `NAV_PERMISSIONS`
keeps each item's exact permission and the server Gate stays authoritative.

**OWNER DECISION.** *How are six clinical modules grouped?* (a) A second dropdown ("Clinical" / "Hospital")
beside "Admin" — mirrors D-111 exactly. (b) Per-vertical navigation that changes by role — better for a
hospital, a bigger IA change. **SIZE.** **One gate**, one Vue file plus i18n keys — it resembles D-111/POLISH.2
itself. **UNBLOCKS.** Nearly every non-outpatient role group. **IF NEVER BUILT:** six built verticals are
invisible; staff reach them by bookmark, or not at all.

---

## 2. Clusters — where building one makes another cheap

**This is the most useful output of the map.**

### Cluster A — "A finished engine with no front door" (6 items, and it is the biggest cluster)
`P3-H3` · `P7-H1` · `P6-H2` · `QF13b-H1` · **`P2-H4`'s Problems / Vitals / Allergies rows** (+ `P5-M7`) ·
(`P9-H5`, smaller still)

They share **no code**, but they share a **shape and a gate template**: resolve ids from strings in-controller
(the C-1 convention), authorize the existing permission, call the existing service, render refusals through
`RefusalNotice`, and add a route-smoke line. **The second one costs materially less than the first** because
the gate pattern, the test shape and the review checklist are reused. `P9-H5` is the natural rehearsal.

### Cluster B — Medication, one data model, three findings
`P7-H3` (ED prescribing) · `P2-H4`'s Medications row · the unlinked `pharmacy.patient-medications` surface

All three are the same underlying fact: **`Modules\Clinical\Models\Medication` and
`Modules\Pharmacy\Models\MedicationOrder` are different tables and nothing connects them.** Answer that once
and all three become link-and-permission work. Answer it three times and you get three divergent designs.

### Cluster C — Reversal, one proven pattern, three engines
`P5-H3` (dispense) · `AdjustmentService::reverse()` (built) · `ChargeCaptureService::cancel()` (built, unreached)

Two compensating-record reversal engines already exist and are tested. `P5-H3` should **copy**, not invent —
and its billing arm probably *is* `ChargeCaptureService::cancel()`.

### Cluster D — Surgery: the scheduler cannot work at all
`P6-H1` (FIX — role 403 on every surgery route) · `P6-H2` (the unreachable overlap guard)

`P6-H2` is pointless without `P6-H1`: building theatre booking for a role that cannot open the case board
delivers nothing. **`P6-H1` must come first and is a permission fix.**

### Cluster E — ED: two halves of one first hour
`P7-H1` (arrival) · `P7-H3` (prescribing) · and the `patient.edit` question

The ED's first hour is: patient exists → visit registered → triaged → treated. Today step 1 may fail
(no `patient.edit`), step 2 is impossible (no route), and treatment cannot include a prescription.

---

## 3. Dependency order (what must exist before what)

```
P6-H1 (permission FIX) ─────────────► P6-H2 (theatre booking)
                                         ▲
                                   duration decision

patient.edit decision ──► P7-H1 (arrival) ──► the rest of the ED flow already works
                                         └──► P7-H3 (prescribing) ◄── medication data-model decision
                                                                          │
P2-H4 Medications row ◄───────────────────────────────────────────────────┘

P5-H3 billing decision ──► P5-H3 clinical gate ──► P5-H3 billing arm (probably ChargeCaptureService::cancel)

NAV-GROUPED ──► makes every other built-but-unreachable module discoverable (no dependency of its own)

P9-H5 (permission) · P3-H3 · QF13b-H1 · P3-H2 · P9-H1  — no dependencies on anything above
```

---

## 4. The cheapest real win, and the most expensive

### Cheapest: `P3-H3` (write-offs)

`P9-H5` is literally smaller — one permission line — but unblocks exactly one role template and closes no
capability the product advertises. **`P3-H3` is the best ratio of value to cost:**

- **Cost:** one gate, wiring only. No migration, no model, no service, no new engine logic, and — uniquely
  in this set — **no product decision about behaviour**, because the money semantics are already decided
  *and tested* (δ=0 reconciliation, over-adjustment guard, append-only reversal).
- **Value:** it closes a capability the product **already reports on**. Two columns of a shipped AR
  roll-forward can currently only read zero. It unblocks the billing role and `org_admin`, and it stops
  dunning chasing uncollectible debt.
- **Risk:** the lowest available — the dangerous half (money arithmetic under a lock) is the half that exists
  and is proven.

The only decision is *which permission*, and option (a) is zero-work.

### Most expensive: `P6-H2` (theatre overlap)

**Three gates plus a prerequisite FIX plus a modelling decision**, and one of its blockers (no slot may ever
be released) is a design gap nobody has sized. It is also the only item whose value depends on a role that
currently cannot open the module at all. **Defer it deliberately** — do not drift into it because
`assertNoOverlap` looks finished. It is finished; everything around it is not.

---

## 5. What a first customer hits in week one, per vertical

This is the question a flat list cannot answer.

### If the first customer is a **dental practice**
Hits **almost none of this.** Dental (DENTAL.G1–G8) is the most complete vertical and its nav entry exists.
Week one they hit `P2-H4`'s **Notes** row (recording in the chart) and `P3-H3` only if they write off a debt.
**Verdict: the safest first customer by a wide margin.**

### If the first customer is a **Spitex (home-care) organisation**
Hits **none of the eleven directly.** The nurse-facing product is the PWA, and the visit flow is complete.
They hit `P3-H3` at month-end billing and `NAV-GROUPED` if office staff need Pharmacy or Lab.
**Verdict: safe; the real Spitex risk is not on this list — it is the CH/KVG billing pack** (`DEFERRED.md`),
because Swiss Spitex reimbursement probably is not cash-pay.

### If the first customer is a **hospital**
Hits **nearly everything, on day one.**

| Day-one need | Blocked by |
|---|---|
| Find Lab / Radiology / Surgery / Pharmacy / beds / ED at all | **`NAV-GROUPED`** |
| Register an ED arrival | **`P7-H1`** (+ the `patient.edit` question) |
| Prescribe in the ED | **`P7-H3`** |
| Schedule a theatre without double-booking | **`P6-H2`** (+ `P6-H1`) |
| Correct a mis-dispense | **`P5-H3`** |
| Record in the chart beyond orders | **`P2-H4`** |

**Verdict: a hospital is the vertical this list is really about.** Eight of the eleven items are hospital
surfaces. A hospital first customer should expect **NAV-GROUPED + P7-H1 + P6-H1** as minimum pre-onboarding
work, and a decision on `P7-H3` within the first weeks.

---

## 6. My reading — **RECOMMENDATION, not fact**

> Everything above is evidence. This section is my opinion and the decision is not mine.

1. **Fix the register first.** `P9-H5`'s consent half is a permission grant, not a feature, and `P8-M2`,
   `P8-M3`, `P7-L2` are already fixed at HEAD. The register should not carry work that does not exist.
2. **Do `P3-H3` next** — cheapest real win, no behaviour decision, closes a capability the product already
   reports on.
3. **Then `NAV-GROUPED`**, because it is one presentational gate that makes six finished verticals visible,
   and because the constraint everyone believed was blocking it does not exist.
4. **Answer the medication data-model question before touching `P7-H3` or `P2-H4`'s Medications row.** It is
   one decision behind three findings; taking it late means taking it three times.
5. **Leave `P6-H2` alone until `P6-H1` is done and a duration decision exists.** `474cefe` was right to stop,
   and a fourth blocker has since appeared.
6. **`P9-H1` recovery: do nothing yet.** Nobody is blocked, and the wedge cannot be created any more.

**What I would not do:** build `P2-H4` as one gate. It is five rows at four readiness levels sharing one ID,
and the Medications row alone is blocked behind a data-model decision the others are not. Ship the
`ClinicalListService` rows (Problems + Vitals + Allergies — which also closes `P5-M7`) and Notes; leave
Medications until cluster B is decided.

---

## Appendix — corrections this map makes to the register

| Item | Register says | Evidence |
|---|---|---|
| `P9-H5` consent half | FEATURE | **FIX** — the whole path exists; `him_records` lacks `patient.edit` (`PatientConsentController.php:16,:37`) |
| grouped nav | blocked by a D-111 "10-item cap" | **No cap exists.** D-111 split 15 → 10 + 5; its constraint is desktop no-wrap at 1440px |
| `P2-H4` | one FEATURE | **Five rows at four different readiness levels** — every row has a service; only Medications needs a decision |
| `P7-H1`, `P3-H3`, `P6-H2` | FEATURE | Engines complete and tested; **the missing layer is HTTP** |

| `P2-H4` Problems / Vitals | (read as unbuilt) | **`ClinicalListService` has `recordProblem` (`:28`), `recordVital` (`:106`), `recordAllergy` (`:67`) — and no route reaches any of them** |
| `P5-M7` (MEDIUM, separate finding) | *"an allergy cannot be recorded anywhere"* | **Same cause** — `ClinicalListService::recordAllergy` is unreachable. Closing `P2-H4`'s list rows closes it too |

**A correction this map makes to ITSELF.** An earlier pass recorded Problems and Vitals as having no service
at all, on the strength of a search for files named `ProblemService.php` / `VitalService.php`. Both are
methods on `ClinicalListService`, and the mistake would have oversized `P2-H4` by two whole features. The
lesson is the one this programme keeps relearning: **verifying an absence in one file proves that file, not
the request.** Nothing else in this document rests on a filename search — every other layer claim was read.
