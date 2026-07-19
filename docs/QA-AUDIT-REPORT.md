# CareOS — Full Playwright E2E / UI QA Audit

**Date:** 2026-07-19 · **Method:** live browser (Playwright MCP, Chromium) driven through the running app, role by role · **Scope:** UI + end-to-end + integration on top of the 589 passing backend Pest tests. **No app code was changed** — audit and report only.

---

## 1. Test environment & coverage

| Item | Value |
|---|---|
| App URL | `http://127.0.0.1:8000` (via `php artisan serve`; Apache/`localhost` has no CareOS vhost) |
| Stack | Laravel 12.63 / PHP 8.2.12 · MariaDB 10.4 @3306 · Redis @6379 (both reachable) · assets pre-built (`public/build`) |
| Migrations | `migrate:status` → 0 pending |
| Seed | `DemoClinicSeeder` (Praxis Lindenhof) + `DemoSpitexSeeder` (Spitex Sonnengarten) → 2 tenants, 19 users, 15 clinic patients, 12 clinic appointments, 6 invoices/1 CN/4 payments, 38 Spitex visits |
| Staff login | `<first.last>@praxis-lindenhof.test` / `demo-password` + **mandatory TOTP 2FA** (all demo staff share the fixed factory secret `JBSWY3DPEHPK3PXP`) |
| Portal login | `erika.baumgartner@example.test` / `demo-portal-password` (no 2FA) |
| Roles exercised | org_admin (Andrea Lindenhof), reception (Nadia Steiner), portal patient (Erika Baumgartner) |

**Note:** the dev server was left running on `:8000` and `.env`'s `APP_URL` was temporarily pointed at it during the audit (restored to `http://localhost` afterwards). `.env` is not committed.

### Coverage map

| Area | Coverage | Result |
|---|---|---|
| A. Auth (login ±, 2FA, logout, unauth bounce) | Full | ✅ Pass |
| B. Landings | Staff ✅ ; Admin (`/admin` super-admin) not reached (no super-admin seeded) | ⚠ staff landing is a placeholder |
| C. Patients (index, 360, register+dup, consents) | Index/360/register+**live dup warning** ✅ ; staff consent grant not exercised (portal withdraw tested instead) | ✅ Pass |
| D. Scheduling (day-board, public booking, recurring/waitlist, **kiosk PHI-safety**) | Day-board incl. status action ✅ ; public booking ✅ ; kiosk bad-code ✅ ; recurring/waitlist UI present (not fully run) | ✅ Pass |
| E. Clinical (chart/**vitals fence**, note **sign→read-only→amend**, care/orders/labs) | Chart, allergy banner, vitals fence, signed-note read-only + amend + versions ✅ ; live draft→sign not re-run (no draft seeded) | ✅ Fence held |
| F. Nursing dispatch (competency block) | Clinic dispatch empty (home-care is Spitex-only); **not UI-exercised** (Spitex 2FA + specific mismatch needed) | ⧗ Not exercised (backend-covered) |
| G. Comms (inbox, not-visible chip, reply) | Inbox + internal-thread chip ✅ ; reply not posted | ✅ Pass |
| H. Telehealth | **Staff join screen NOT wired** (no route) ; portal video-visit "not recorded" ✅ | ⚠ staff surface absent |
| I. Billing (W6+W7) | Lists/aggregates ✅ ; **details + all write actions 500** | ❌ **Critical** |
| J. Portal (invoices no-pay, consent lockout, telehealth) | Home/invoices/telehealth/consent-withdraw-lockout ✅ | ✅ Fence held |
| K. Admin/Governance | Kiosk provisioning ✅ ; CSV import **dry-run page 500s** ; **settings / RBAC-UI / KB / AI-approval-queue / governance NOT wired** | ⚠ mostly not built |
| L. Cross-cutting (RBAC, console, perf) | Reception RBAC 403 on billing/reporting ✅ ; console clean on working pages ; no slow pages observed | ✅ Pass (2 UX nits) |

**Not reachable / out of scope of the clinic vertical (no wired route):** staff telehealth join, governance dashboard, KB admin, AI approval-queue page, settings pages, RBAC/roles admin UI, admin landing (needs a super-admin, none seeded). The offline Nurse PWA is a separate app and was not driven in this harness.

---

## 2. Bug list by severity

### 🔴 CRITICAL

**C-1 — The entire billing DETAIL + WRITE surface (and the CSV-import preview) returns HTTP 500 in the real browser.**
- **Pages/roles:** any staff role, any tenant. `GET /billing/invoices/{id}` → **500**. Confirmed identically for `/billing/credit-notes/{id}` and `/imports/{batch}`; by identical code also `/billing/payments/{id}`, the PDF download, and the POST actions (issue, credit-note, allocate, reverse).
- **Repro:** log in → **Billing** → click any invoice → *Internal Server Error*.
- **Exception:** `Modules\Platform\Exceptions\TenantContextMissingException` — *"Refusing to query [Invoice] with no tenant context (fail-closed)."*
- **Expected vs actual:** should open the invoice/payment/credit-note detail; instead 500s before the controller runs.
- **Root cause:** the billing detail/action controllers use **implicit route-model binding** — `InvoiceController@show(Invoice $invoice)`, `CreditNoteController@show(Invoice $invoice)`, `PaymentController@show(Payment $payment)` (+ `issue`/`creditNote`/`allocate`/`reverse`/`download`). Laravel resolves implicit bindings in `SubstituteBindings`, which in `bootstrap/app.php` runs **before** `IdentifyTenantFromUser` (registered via `$middleware->web(append: [IdentifyTenantFromUser, …])`). So the tenant-scoped model is queried with **no tenant context** → the fail-closed guard throws. Every *other* detail page in the app deliberately avoids this by taking a **string id** and querying inside the controller *after* context is set (`PatientShowController(string $patient)`, `ClinicalChartController(string $patient)`, `ClinicalNoteShowController(string $note)`). The W6/W7 billing controllers — and, pre-existing, `ImportBatchController@show(ImportBatch $batch)` — broke that convention.
- **Why 589 tests are green yet the browser is broken:** the billing feature tests pre-set the `TenantContext` singleton in their fixtures (`w6Ctx/w7 …->set($tenant)`) *before* the `->get()` call, so binding-time context is always present and the request-time middleware ordering is never actually exercised.
- **Blast radius:** you can view every billing *list/aggregate* (invoices, aging, dunning, payments, credit-notes, reporting, new-invoice) but **cannot open a single detail, issue an invoice, create a credit note, take/allocate/reverse a payment, or download a PDF.** Recording a payment also dead-ends (the store redirect targets the broken `payments.show`). CSV-import **dry-run preview** is unreachable (`/imports/{batch}` 500).
- **Fix direction (post-audit):** either (a) convert the billing/import controllers to string ids + in-controller queries (match the app convention), or (b) move `IdentifyTenantFromUser` before `SubstituteBindings`; and add a request-level test that does **not** pre-seed the context singleton so this cannot regress.

### 🟠 HIGH
*(none beyond the blast radius of C-1)*

### 🟡 MEDIUM

**M-1 — Staff landing (`/app`) is an unwired placeholder.** All "Today at a glance" cards show `—` / "Awaiting live data", "Today's schedule" says "Nothing scheduled yet", "Pending approvals" says "All caught up" — even though the tenant has 12 appointments, unsigned notes and open threads. Copy "Live figures appear here once your day is connected" confirms it's a stub. It's the **first screen a customer sees**. (Role: all staff.)

**M-2 — Date-only values are rendered timezone-fragile (`new Date(isoDate)`), shifting a day for behind-UTC viewers.** The patient **index** shows Erika's DOB as `03/11/1954` while the detail/chart and the DB show `1954-03-12`; the same one-day shift appears on the AR page ("As of July 18" when today is July 19) and other list dates. Root cause: `Intl.DateTimeFormat(...).format(new Date("1954-03-12"))` parses date-only strings as UTC midnight and re-renders in the viewer's local zone. **For a Swiss (UTC+1) deployment this is invisible** (renders the correct day); it is wrong for any viewer behind UTC (audit browser = America/Los_Angeles). DOB is identity-critical, so worth fixing for robustness. Same class as the W6 `isOverdue` fix — avoid `new Date(dateOnly)` for date-only values.

**M-3 — Vitals shown in storage base-units, not clinical units.** The chart Vitals tab shows Weight as `51340 g` and Height as `1552 mm` instead of `51.3 kg` / `155 cm`. Values are correct and raw (fence-compliant), but clinicians expect kg/cm.

**M-4 — Nav exposes modules a role cannot use.** The top nav is static (not permission-gated client-side): reception sees **Billing** and **Reporting** links, which correctly return 403 on click. Dead-end links for restricted roles.

**M-5 — Access-denied and portal-lockout screens are bare "Forbidden" pages.** 403s (reception→billing/reporting) and the portal consent-withdrawal lockout render the raw Symfony "Forbidden" page (browser title *Forbidden*), not a styled in-app "you don't have access / your access was withdrawn — contact the practice" screen. Functionally correct, visually jarring for a paying customer.

**M-6 — Seeded vitals are physiologically implausible (demo realism).** The same 72-year-old patient's height is seeded 155 → 157 → 170 cm and weight 51 → 101 → 87 kg across three weeks (random values). Not an app bug, but it undermines a customer demo. (Applies to the demo seeder.)

### 🟢 LOW

**L-1 — Pluralisation nit.** Credit-notes list header reads "**1 credit notes**" (should be "1 credit note"). Likely other `{count} x` strings share this.

**L-2 — Day-board resource mix.** The clinic (Praxis Lindenhof) day-board lists home-care **vehicles** ("Fahrzeug 1/2") alongside chairs/rooms — Spitex-flavoured resources bleeding into the clinic tenant's board (seed choice; cosmetic).

**L-3 — Clinic tenant currency is EUR.** A Swiss clinic would expect CHF; the clinic seeder uses EUR (billing figures render "313.00 EUR"). Confirm intended per tenant.

---

## 3. Critical / safety callouts (explicit)

- **Data-integrity / broken flow:** **C-1** — billing detail + all billing writes + CSV-import preview are 500 in the browser. Delivery-blocking.
- **PHI-safety (kiosk):** ✅ **PASS.** A real patient name + DOB + a wrong check-in code returns only *"We couldn't find your appointment. Please see reception."* — no patient data, no existence confirmation.
- **Electric fence in the UI:** ✅ **HELD.** Vitals render raw (per-metric tables, no colours/flags/ranges/arrows/scores); allergy banner is amber `bg-warning-soft` (never red); signed notes are read-only plain text with an always-visible version history and reason-gated amend; the reporting dashboard is facts-only (no grade/target/trend/RAG); the AI inbox draft is human-triggered (not auto-sent); public booking carries the non-emergency notice.
- **Billing numbers vs backend:** ✅ **Consistent** where visible — Outstanding 787.11 EUR reconciles to the open invoices (313 + 304.50 + 169.61); aging buckets and the reporting financials agree.
- **RBAC holes:** ✅ **None found.** Reception is correctly 403'd from `/billing/*` and `/reporting`; portal consent-withdrawal locks the portal (403); tenant scoping holds (patient sees only their own invoices). *(The very bug in C-1 is the fail-closed tenant guard doing its job — arguably over-firing due to middleware order, not a leak.)*

---

## 4. What works well (passed cleanly)

- **Auth**: login (valid/invalid), full TOTP 2FA challenge, logout, unauthenticated bounce to `/login`, mandatory-MFA enforcement.
- **Patients**: fast directory with avatars/MRN/DOB/age; name + DOB search; **live duplicate-detection warning** with match reasoning and "open profile / new patient" — excellent.
- **Scheduling**: day-board with date pager, branch/resource filters, populated grid, working status transitions (in-progress → completed), waitlist slot picker, recurring-appointment builder; public online booking with the non-emergency notice.
- **Clinical**: patient chart with prominent amber allergy banner, raw vitals, month-grouped encounter timeline; SOAP note editor with signed-note read-only wells, version chain and reason-gated amendment.
- **Kiosk**: clean ephemeral check-in view, admin one-time-token provisioning + revoke, and the PHI-safe not-found path.
- **Comms**: unified inbox with patient/internal filters, unread counts, the "Internal — not visible to the patient" chip, assign/close/reply and human-triggered AI draft.
- **Billing lists & reporting**: invoice list with self-consistent counters, factual AR aging, dunning worklist (idempotent, legal-comms framing), payments list with unallocated remainders, and a strictly facts-only reporting dashboard.
- **Portal**: information-only balance ("payment is handled at the practice", no pay button), per-invoice PDF, own-records scoping, "this call is not recorded" video note, and a consent-withdrawal flow with a clear consequence warning + reason that genuinely locks the portal.
- **Design**: Eucalyptus Glow applied consistently across every page (glass cards, dark tiles, euca palette, warning-soft accents); no layout breakage; **zero JS console errors on any working page** (the only console errors were the C-1 500s).

---

## 5. Recommendations (prioritised)

1. **Fix C-1 before anything else** (blocks the whole billing vertical). Prefer aligning the billing/import controllers to the app's string-id convention; add a request-level test that does not pre-seed the tenant-context singleton. Audit for any *other* implicit-bound tenant models.
2. **Wire the staff landing (M-1)** to real "today" figures (MetricsService already exists) — it's the first impression.
3. **De-fragilise date rendering (M-2)**: render server-formatted date strings (or parse date-only as local) so DOBs/dates never shift; verify in a non-UTC+1 timezone.
4. **Show vitals in clinical units (M-3)** — kg/cm (keep storage in g/mm).
5. **Style the 403 / access-withdrawn / lockout screens (M-5)** and **hide or disable nav items a role can't use (M-4)**.
6. **Replace the implausible demo vitals (M-6)** and reconcile demo currency/resources (L-2/L-3) so the demo tenant looks credible; fix pluralisation (L-1).
7. **Coverage gaps to decide on:** staff telehealth join, governance/KB/AI-approval-queue/settings/RBAC-admin screens are not wired — confirm they are intentionally out of the clinic vertical (they exist as prototypes only), or schedule them. Add browser/E2E smoke tests (Playwright) to CI so a request-time 500 like C-1 can't ship green again.
8. **Re-test not-yet-exercised flows after the C-1 fix:** issue-draft→gapless number, new-invoice-from-charges, credit-note creation (original untouched), record-payment + allocation + over-allocation guard, PDF download, CSV-import dry-run, and the Spitex nurse-competency hard/soft block.

---

## 6. Delivery-readiness verdict

**Not deliverable to a paying customer as-is.** The clinic vertical is, on the whole, polished, safe (kiosk PHI-safety and the clinical/reporting electric fence all hold), and its lists/search/scheduling/clinical/portal flows work well. **But billing — a core W6/W7 deliverable — is non-functional in a real browser (C-1):** every invoice/payment/credit-note detail, every billing write action, PDF download, and the CSV-import preview return HTTP 500. That single middleware-ordering defect must be fixed first; it is small and well-understood.

**Gate to demo/deliver:** fix **C-1** (Critical), then wire the **staff landing** (M-1) and fix **date rendering** (M-2) as the next two most visible issues. The remaining Medium/Low items are polish that can follow. After C-1 is fixed, re-run the billing write flows (issue / credit-note / payment+allocation / PDF / import dry-run) end-to-end in the browser to confirm — the backend logic behind them is already well-tested; only the presentation/binding layer is failing.

---

## 7. FIX-verify (post-audit, FIX.1 → FIX.3)

**Date:** 2026-07-19 · **Method:** live browser (Playwright MCP, Chromium) against a fresh `migrate:fresh` + `DemoClinicSeeder` + `DemoSpitexSeeder` seed, logged in as org_admin (Andrea Lindenhof) with real TOTP 2FA. **Audit browser timezone = America/Los_Angeles (UTC-7, behind UTC)** — the exact condition that triggered M-2.

### C-1 — CONFIRMED FIXED in the browser ✅

The middleware/binding defect was fixed in **FIX.1** by converting the billing + import controllers to the app's string-id convention (`Model::query()->whereKey($id)->firstOrFail()` **after** tenant context is set; 404 fail-closed). Every flow that returned HTTP 500 in the original audit was re-driven end-to-end and now passes:

| Flow | Result | Evidence |
|---|---|---|
| Open invoice **detail** (`/billing/invoices/{id}`) | ✅ Pass | INV-1 renders (lines, VAT, totals, actions) — no 500; URL carries the string id |
| **Issue** a draft invoice → gapless number | ✅ Pass | Draft (Erika, 15.00 EUR) → **INV-7**, next after INV-6; status Issued, balance 15.00 |
| Create a **credit note** → original untouched | ✅ Pass | INV-5 → new **CN-2** (gapless); INV-5 keeps number/dates/3 lines/subtotal 159.00/VAT 10.61/total 169.61; only its derived balance→0 / status→"Credit-noted" |
| Record a **payment** + **allocate** → balance updates | ✅ Pass | 100.00 EUR payment allocated to INV-1 → INV-1 balance **313.00 → 213.00**, status "Partially paid"; payment unallocated → 0.00 |
| **Over-allocation** prevented | ✅ Pass | Applying 150.00 from a 100.00 payment is rejected: *"Cannot allocate more than the payment unallocated remainder."*; unallocated stays 100.00, nothing applied |
| Download invoice **PDF** | ✅ Pass | `GET /billing/invoices/{id}/pdf` → HTTP 200, `application/pdf`, `%PDF-` magic bytes, `attachment; filename="INV-1.pdf"` |
| CSV **import dry-run** preview | ✅ Pass | `/imports/{batch}` renders; map → dry-run → both rows **valid**, batch "validated"; **writes nothing** (0 `Testimport` patients in DB after the run) |

### M-2 — FIXED ✅

- **Approach:** a small shared helper `resources/js/lib/date.ts` — `formatDateOnly()` / `ageFromDateOnly()`. A date-only string (`^\d{4}-\d{2}-\d{2}$`) is parsed as **local midnight** (`new Date(`${value}T00:00:00`)`) instead of UTC midnight, so the calendar day never shifts by the viewer's timezone. Full datetimes (with a time component) are passed through unchanged — **only date-only values were touched; no timestamped rendering was changed.**
- **Wired everywhere date-only renders:** `Patients/Index.vue` (DOB + age), `Clinical/Chart.vue` (age), and the six billing pages (`Invoices/Index`, `Invoices/Show`, `Payments/Index`, `Dunning/Index`, `CreditNotes/Index`, `Aging`).
- **Browser proof (America/Los_Angeles):** Erika Baumgartner's DOB (stored `1954-03-12`) now renders **`03/12/1954`** on the patients index — where the old `new Date("1954-03-12")` path renders `03/11/1954` in the same browser (verified inline). Invoice dates render the correct stored day too (draft issued `07/19/2026`).
- **Regression test:** `resources/js/lib/date.test.ts` (new root Vitest config, `npm run test:unit`, TZ pinned to `America/Los_Angeles`) — 7 tests green, including a self-validating assertion that the naive parse yields `03/11` in that zone while the helper yields `03/12`.

**Net:** the Critical (C-1) is closed and re-confirmed in the browser; M-2 is fixed with a reusable helper + a timezone-robust unit test. (FIX.4 subsequently cleared M-3/M-4/M-5/M-6/L-1/L-2/L-3; FIX.5 added the CI smoke guard below.)

---

## 8. Systemic guard — route smoke in CI (FIX.5)

Recommendation #7 was "add browser/E2E smoke tests to CI so a request-time 500 like C-1 can't ship green again." C-1 shipped green because the unit/feature suite **pre-seeded the `TenantContext` singleton before each request**, masking the real middleware ordering (`SubstituteBindings` runs before `IdentifyTenantFromUser`). The guard closes exactly that gap.

- **What it is:** `tests/Feature/Smoke/RouteSmokeTest.php` — a request-level smoke that seeds the demo clinic and drives **every major GET route** for **all six staff roles + a portal patient** through the full HTTP kernel, asserting each returns **200 (never a 500/419)**. It covers the landings, patients index/show/register, day-board, dispatch, competencies, inbox, clinical chart/encounter/note/note-edit/orders/snippets, all billing index + **detail** pages (invoice/credit-note/payment show — the C-1 surface) + aging/dunning/new-invoice/**PDF**, reporting, CSV **import** index/create/show, admin/kiosks, public booking, and every portal page — plus a per-role **RBAC** smoke (e.g. reception → **403** on `/billing/invoices` by URL).
- **Why it catches the C-1 class:** it calls `TenantContext::forget()` **before every request**, so `IdentifyTenantFromUser` must (re)establish context via the middleware exactly like an independent browser request. That is the generalisation of the FIX.1 regression test, which failed with a 500 against the old implicit-binding controllers — so a re-introduced request-time 500 fails this smoke.
- **Request-level, not a headless browser:** chosen for **reliability** — it runs in the existing MySQL-8 Pest job on every push, is deterministic and fast (~46 s), and exercises the identical middleware pipeline C-1 broke, with none of the `artisan serve` / browser-install / TOTP-timing flakiness a browser-in-CI would add. (The C-1 class is a server-side 500, fully covered here; Playwright remains the manual browser tool for client-render checks, e.g. the FIX.3 M-2 verification.)
- **CI wiring:** a dedicated fast-fail step **`Route smoke (real middleware stack, guards C-1-class 500s)`** runs `composer test:smoke` before the full `composer check`, so a 500 fails CI in ~1 min instead of after the whole suite.
- **Run it locally:** `composer test:smoke` (robust across environments) or `npm run test:smoke`.
- **Maintainability:** a single route list in the test — adding a new page later is one line.

---

## 9. Final pre-deployment pass (in-browser, post FIX.1–FIX.5)

**Date:** 2026-07-19 · **Method:** live browser (Playwright MCP, Chromium in **America/Los_Angeles**) driven role-by-role against a fresh `migrate:fresh` + `DemoClinicSeeder` + `DemoSpitexSeeder` seed (+ a temporary super-admin for `/admin`). Every FIX.1–FIX.5 remediation was re-confirmed in-browser and the full audit surface re-driven to catch anything new.

### Each remediation holds in-browser ✅

| Fix | In-browser confirmation |
|---|---|
| **C-1** (billing) | Invoice **detail** opens (no 500); a draft **issued → gapless INV-7**; **credit note → CN-2**, original INV-7 untouched (number/lines/total 15.00 CHF unchanged, only balance→0 / status "Credit-noted"); **payment** recorded + **allocated** (unallocated → 0.00 CHF); **over-allocation** of 150 from a 100 payment **rejected** ("Cannot allocate more than the payment unallocated remainder"); **PDF** = `200 application/pdf %PDF- INV-7.pdf`. |
| **M-1** (landing) | Real figures: Active patients **2**, **Outstanding 787.11 CHF**, "0 appointments · 0 waiting · 0 no-shows" (genuine Sunday zeros, not the old stub). |
| **M-2** (dates) | Erika's DOB renders **03/12/1954** on the index (the buggy `new Date` path renders 03/11/1954 in this UTC-7 browser); Patient-360 header + demographics show `1954-03-12`. |
| **M-3** (vitals) | Chart Vitals show **68.0 kg / 163 cm** (clinical units, no grams/mm), Systolic **125 mmHg** — raw, **zero fence violations** (no normal/abnormal/high/low/arrows/ranges/colours). |
| **M-4** (nav) | org_admin sees all 7 nav items; **reception sees only Dashboard/Patients/Scheduling/Inbox** (Billing/Reporting/Nursing correctly hidden). |
| **M-5** (styled denials) | Staff 403 → styled **"You don't have access to this area"** (HTTP 403); a not-wired URL → styled **"Page not found"** (HTTP 404); portal consent withdrawal → styled **"Your portal access has been withdrawn — contact the practice"** (HTTP 403). Status codes preserved (authorization unchanged). |
| **L-2** (resources) | Day-board resources are **Sprechzimmer 1/2/3 + Behandlungsstuhl 1/2** — no `Fahrzeug` vehicles. |
| **L-3** (currency) | **CHF** everywhere — landing (787.11 CHF), invoices, payments, portal invoices. |
| Electric fence | **HELD** — vitals raw (no interpretation); a signed note renders read-only with a signed-lock line, version history, and reason-gated amend. |
| Kiosk PHI-safety | **HELD** — a real name + DOB + a wrong code returns only *"We couldn't find your appointment. Please see reception."* (no PHI, no existence confirmation). |
| RBAC by URL | reception → `/billing/invoices` is still **403** by direct URL (nav hiding is a UX hint only; the server Gate stays authoritative). Console clean (only benign 403/404 network logs). |

### New issues found, classified

**(a) Safe-fixed inline** (presentational only; no billing/clinical/RBAC/tenancy logic changed; no existing test modified):
1. **Record-payment amount label hard-coded "Amount (EUR)".** On the standalone *Record payment* page (no invoice context) the label fell back to a literal `EUR`, wrong for the now-CHF clinic — even though the recorded payment itself already stamped CHF (the store resolves the tenant currency) and the allocate form already showed "Amount (CHF)". **Fix:** `PaymentController@create` now passes the tenant settlement currency (`SettingsService`), and `Payments/Record.vue` uses it as the fallback. Re-verified in-browser: the field now reads **"AMOUNT (CHF)"**. Display-only — no payment/currency logic changed.
2. **Portal consent scope chip doubled for `comms.email`** ("comms.emailcomms.email"). The portal consents view shows a friendly label + a small mono key; `comms.email` had no entry in the label map, so the label fell back to the key (rendered twice). **Fix:** added `comms.email → commsEmail` to the scope map + an i18n label ("Get email updates about your care"). Presentational i18n only.

**(b) Flagged for review:** **none.** No defect surfaced on the electric fence, billing/payment/dunning logic, or tenancy/RBAC surfaces — those all behaved correctly.

**(c) Not-a-bug / known gaps:**
- **Designed-but-unwired admin screens** (no route exists — a typed URL returns the styled 404): the **governance dashboard, knowledge-base admin, AI approval-queue, settings pages, RBAC/roles admin UI, and the staff telehealth *join* screen**. These are prototype/deferred surfaces, not defects — a **founder scope decision**, not a bug to fix.
- **Nursing competency hard/soft block** is a **Spitex (home-care) tenant** feature; the clinic day-board dispatch is empty (a clinic isn't home-care), so it is not drivable in the clinic tenant. Backend-covered by the Nursing test suite; a coverage limitation of the clinic vertical, not a break.
- **Staff landing "0 appointments today"** is correct — the audit day is a Sunday and the demo seeds weekday appointments.

### Deployment-readiness verdict — CLINIC vertical

**Deployable.** Every QA-audit finding is resolved and **re-confirmed in a real browser**: the Critical (C-1) billing surface works end-to-end, the two most-visible Mediums (M-1 landing, M-2 dates) hold, the polish batch (M-3 vitals units, M-4 nav gating, M-5 styled denials, L-1/L-2/L-3) is correct, and the safety invariants — the clinical **electric fence** and the **kiosk PHI-safety** — both hold. The only new issues were two cosmetic currency/label glitches, fixed inline. No fence/billing/RBAC defect remains open. The remaining gaps are **designed-but-unwired admin screens**, which are a scope decision for the founder rather than blockers for the clinic vertical.
