# Billing & AR (overview) — Wireframe-Parity Diff (audit only)

**Scope:** diff the intended live surface against the decoded wireframe
`resources/prototype/billing-ar.wireframe.html` on every axis (period switcher · headline band · stat cards ·
AR aging · AR roll-forward · by-payer · collections-health · top-overdue · charged-vs-collected trend · styling ·
states · copy) **AND** the money-displayed-vs-computed determination per figure. **This is an audit. No app code
was changed.** Next page of the wireframe-parity pass (Admin Settings ✓, Approval Queue ✓, Branches ✓,
Agent & Tool Config ✓ full-parity; Allergy Alert safe-part ✓).

- **Date:** 2026-08-11 · **HEAD:** `46e45d1` · **CI:** green. **Env:** `migrate:fresh --seed` + `DemoClinicSeeder`,
  driven in Playwright as `andrea.lindenhof@praxis-lindenhof.test` (org_admin, 2FA).
- **Demo AR note:** the demo has real overdue AR (**CHF 787.11**, all in the **1–30 days** bucket; 3 overdue
  invoices in the dunning worklist) but it is **thin** — only one aging bucket populated, and no month-to-date
  invoiced/collected (the demo invoices were issued before the current month). The live aging surface still
  renders and is auditable; richer aged AR (spread across buckets, MTD activity) would populate more rows but is
  not needed for the structural diff.

> **THE CORE FENCE:** every money figure on this page is an **AGGREGATE that must be COMPUTED IN THE
> BILLING/RECONCILIATION ENGINE and DISPLAYED** — the page must **NEVER** re-sum/recompute money
> (reconcile-to-the-unit launch-blocker: all money math in the engine, displayed not recomputed). The good news
> (below): the engine (`MetricsService` over the reconciled `invoice_balances` projection) already computes the
> figures the live page shows; every wireframe-new figure is a **backend gap to be filled in the engine**, never
> page-side arithmetic.

---

## 1. The live surface found & how the wireframe maps

There is **no single "Billing & AR management report"** live. The wireframe's content is spread across three real
surfaces, over a genuinely-reconciling engine:

| Live surface | Route / controller / Vue | What it shows |
|---|---|---|
| **AR aging** (closest match) | `GET /billing/aging` (`billing.aging`) · `Modules\Billing\...\AgingController` · `resources/js/pages/Billing/Aging.vue` · gate `billing.view` | Dark header (as-of date) + **3 stat cards** (Total outstanding · Invoiced MTD · Collected MTD) + the **5-bucket aging table** (Age · Share% · Amount + Total) + a factual caption. |
| **Reporting hub** | `GET /reporting` (`reporting.dashboard`) · `Modules\Reporting\...\ReportingDashboardController` · `Reporting/Dashboard.vue` · gate `reporting.view` (+ `billing.view` for financial) | A **flat facts dashboard** (From/To date range) with Operational / Throughput / **Financial** (Invoiced · Received · Outstanding + a mini aging table). **Not a tiled hub** — there is no "Billing tile" that drills into a Billing & AR report. |
| **Dunning worklist** (≈ top-overdue) | `GET /billing/dunning` (`billing.dunning.index`) · `DunningController` · `Billing/Dunning/Index.vue` · gate `billing.view` | Overdue invoices: **INVOICE · PATIENT · DUE · OPEN BALANCE · REMINDER LEVEL · LAST REMINDER**; stat chips (Overdue N · No reminder yet N); operator-gated **Send reminders**. |

**The engine (the money source of truth):**
- `Modules\Reporting\...\MetricsService` — all financial aggregates, gated `billing.view`, **integer minor units**,
  "the view formats only". Computes: `outstandingBalanceMinor()`, `agingBuckets()` (current/1–30/31–60/61–90/90+,
  date math over `due_date` summing `invoice_balances.open_balance_minor`), `overdueBalanceMinor()`,
  `invoicedTotalMinor(range)`, `paymentsReceivedTotalMinor(range)`.
- `Modules\Billing\...\ReconciliationEngine` — the reconcile-to-the-unit engine (invariants **I1–I6**, exact
  integer arithmetic; I2 = `invoice_balances.open_balance_minor` == `total_minor` − net allocations). Tested:
  `SimulatedMonthTest` ("reconciles to the unit", every `delta_minor === 0`).
- `Modules\Billing\...\PaymentService` — records payments + `allocate()` with the **over-allocation guard**
  (cannot allocate more than the invoice open balance or the payment remainder), concurrency-safe.
- `Modules\Billing\...\DunningService` — idempotent, ascending, **never skips a level**, never auto-escalates
  (only an operator `billing.manage` run, or an explicitly-scheduled command, advances it).

**Aggregates the engine already computes vs. wireframe-new:**

| Aggregate | Engine today |
|---|---|
| Total AR outstanding | ✅ `outstandingBalanceMinor()` |
| AR aging buckets (5, current→90+) | ✅ `agingBuckets()` (exact wireframe buckets) |
| Overdue total | ✅ `overdueBalanceMinor()` |
| Invoiced / Collected (per range) | ✅ `invoicedTotalMinor()` / `paymentsReceivedTotalMinor()` |
| Top-overdue list (+ dunning stage + balance) | ◐ controller-assembled from stored projections (no money summing) — per-INVOICE, level-integer |
| **AR roll-forward** (opening + charges − collections − adjustments − write-offs = closing) | ❌ ABSENT — no opening/closing bridge; adjustments + write-offs unmodeled |
| **DSO** | ❌ ABSENT |
| **Net collection rate** (collected / collectible) | ❌ ABSENT (raw inputs exist; "collectible" unmodeled) |
| **By-payer split** | ❌ ABSENT aggregate; payer dimension PARTIAL (`invoices.payer_type` = only `self_pay` / `private_insurance`; no accident/social, no insurer entity) |
| **Charged-vs-collected trend** (weekly series) | ❌ ABSENT (each metric is one scalar per range; no time series) |
| **Write-offs / contractual adjustments** | ❌ ABSENT (only balance-down paths are payment allocation + credit note) |

---

## 2. Section-by-section diff

Class: **(a)** visual/layout · **(b)** feature/backend gap (an engine aggregate that doesn't exist yet) · **(c)**
money-fence (a figure the page would have to recompute — must be engine-computed) · **(d)** correctly-more-real.

| Section | Wireframe | Live | Diff | Class | Sev |
|---|---|---|---|---|---|
| **Period switcher** | Week / Month / Quarter / YTD · Compare · CSV · Export PDF | `/billing/aging`: none (as-of today + MTD fixed). `/reporting`: From/To date range | No period presets / Compare / CSV / PDF on the AR view | (a)+(b) | Med |
| **Headline band** | current / overdue / in-collection + Total AR + DSO | Total outstanding stat card only | No current/overdue/in-collection split band; no DSO | (a)+(b) | Med |
| **4 stat cards + sparklines** | Total AR (+%overdue) · Collected (of collectible) · Net collection rate · DSO — with hover sparklines | 3 plain cards (Outstanding · Invoiced MTD · Collected MTD), no sparklines | 2 metrics missing (collection rate, DSO), %overdue missing, no sparklines | (a)+(b) | Med |
| **AR aging schedule** | 5 buckets (current→90+ in collection), % + amount + total | 5-bucket table: Age · Share% · Amount + Total | **PRESENT & engine-computed.** Deltas: "90+ · in collection" label; wireframe %-per-bucket present live as "Share" | (d)+(a) | Low |
| **AR roll-forward** | opening + charges − collections − adjustments − write-offs = closing (reconcile statement) | **absent** | Whole section missing; two lines (adjustments, write-offs) have no data source | (b)+(c) | **High** |
| **By-payer split** | patient self-pay / supplementary / accident (SUVA/UVG) / social (municipal) | **absent** | No aggregate; payer dimension only `self_pay`/`private_insurance` | (b) | Med |
| **Collections-health (graphite)** | net collection rate + aging health bars + DSO gauge | **absent** | Whole panel missing (net rate + DSO absent) | (a)+(b) | Med |
| **Top-overdue accounts** | ACCOUNT · INVOICE · AGE · STAGE · BALANCE → drill to AR Account Detail | Dunning worklist: INVOICE · PATIENT · DUE · OPEN BALANCE · REMINDER LEVEL · LAST REMINDER | Per-INVOICE not per-ACCOUNT; DUE-date not AGE-days; level-integer not named Mahnung/Betreibung; no AR-Account-Detail drill (that page absent) | (a)+(b)+(d) | Med |
| **Charged-vs-collected trend** | weekly Charged vs Collected series (W1–W5) | **absent** | No time series | (b) | Low |
| **Styling** | management-report grid; glass cards + graphite health panel; sparklines; **Swiss grouping `CHF 286'400`** | glass cards + dark header tile; `787.11 CHF` (suffix, dot decimal, no `'` grouping) | Graphite health panel + sparklines absent; **number format drift** (CHF-prefix + Swiss `'` grouping vs suffix + dot) | (a) | Low |
| **Copy / governance** | Tarmed grounding; write-offs operator sign-off; agent never auto-escalates Betreibung | Aging caption: "factual … not adjusted for expected collectability"; dunning: "runs the tested dunning policy … re-running the same day changes nothing" | Live copy is fence-honest + more precise | (d) | — |
| **States** | period-switched, Compare | as-of-today snapshot; `/reporting` date-range | No Compare; AR view not period-parameterised (engine methods already accept ranges) | (a)+(b) | Low |

---

## 3. Visual deltas to reach parity (frontend, over existing/new engine data)
- Management-report **grid**: headline band → 4 stat cards (w/ sparklines) → aging + roll-forward row → by-payer +
  collections-health row → top-overdue → trend. Live AR page is a single column (header + 3 cards + table).
- **Stat-card sparklines** (hover series) — need a small time series per metric (backend).
- **Graphite collections-health panel** (`euca-tile-dark` exists — the aging header already uses it) with aging
  health bars + a DSO gauge.
- **Period switcher** (Week/Month/Quarter/YTD · Compare) — presentational over range-parameterised engine calls.
- **Swiss number format**: `CHF ` prefix + `'` thousands grouping (wireframe) vs `787.11 CHF` suffix (live). A
  formatting-helper change; **display only** (money stays minor-unit integers in the engine).
- `CSV` / `Export PDF` buttons.

---

## 4. Feature / backend gaps (engine aggregates — each its own gate)
Every one of these must be a **new tested `MetricsService`/engine method** (reusing the ReconciliationEngine
populations so reporting keeps reconciling with the billing source of truth) — **never page arithmetic**.

1. **AR roll-forward reconciliation** (High) — a service that produces a **tying** opening→closing bridge for a
   period: opening balance (period-start outstanding) + charges billed − collections − contractual adjustments −
   write-offs = closing (period-end outstanding). **Blocked on gap #6** (adjustments + write-offs must be modeled
   as distinct ledger movements first). This is a reconcile-to-the-unit statement — see §5.
2. **DSO** (Med) — days-sales-outstanding metric.
3. **Net collection rate** (Med) — collected / collectible; requires a modeled "collectible" (net of contractual
   adjustments), else the denominator is undefined.
4. **By-payer split** (Med) — a payer/insurer **dimension** (extend `payer_type` beyond self_pay/private_insurance
   to accident SUVA/UVG + social/municipal, and/or an insurer entity) + a `groupBy(payer)` aggregate.
5. **Charged-vs-collected time series** (Low) — a period-bucketed (weekly) charged + collected series.
6. **Write-offs / contractual adjustments** (Med, blocker for #1) — model them as distinct, **operator-gated**
   ledger movements (today the only balance-down paths are payment allocation + credit note; the credit note is
   the de-facto operator-gated write-off). Read-only on this report — see §5.
   **→ RESOLVED (BILLAR.P1):** `InvoiceAdjustment` (type `write_off` | `contractual`) is now a first-class,
   **append-only** ledger movement (integer minor units, signed; a correction is a reversal row) written only
   through the **operator-gated** `AdjustmentService` (`billing.manage`; the agent has no path). It **reduces the
   open balance** (`PaymentService::openBalance` = total − net allocations − net adjustments) and **reconciles to
   the unit** — the ReconciliationEngine's **I2** (balance derivation) and **I6** (no-orphan) were **extended to
   include it, invariant count unchanged at 6, tie-out not weakened** (δ=0). Locked by
   `tests/Feature/Billing/WriteOffAdjustmentTest.php` (7). This unblocks the roll-forward (#1/gate BILLAR.P2).
7. **Account-level overdue rollup + named dunning stages + the AR Account Detail drill** (Med) — the wireframe's
   top-overdue is per-ACCOUNT with named stages (Reminder → 1./2. Mahnung → Betreibung) drilling into an **AR
   Account Detail** page (a separate wireframe, not yet built). The live dunning worklist is per-invoice with
   level-integers.

---

## 5. THE MONEY-FENCE VERIFICATION (per figure)

Verdict key: **✅ engine-computed + displayed (keep)** · **⚠️ presentation ratio (not a money sum)** ·
**🚧 backend gap (must be an engine method — never page arithmetic)**.

| Figure | Verdict | Basis |
|---|---|---|
| Total AR outstanding | ✅ | `MetricsService::outstandingBalanceMinor()` (Σ `invoice_balances.open_balance_minor`). |
| current / overdue / in-collection split | ✅ | `agingBuckets()` (current) + `overdueBalanceMinor()` (past-due sum); 90+ bucket = "in collection". Engine. |
| Each aging bucket AMOUNT + total | ✅ | `agingBuckets()` sums the reconciled `open_balance_minor` per bucket. Exact wireframe buckets. |
| % AR per bucket / Share / % overdue | ⚠️ | A display ratio (`bucket_minor / total_minor × 100`) — live computes it in `Aging.vue` over two engine integers. **Not** a money sum/movement, so it does not violate reconcile-to-the-unit; low-risk. Prefer engine-provided for consistency. |
| Invoiced (charges billed) / Collected | ✅ | `invoicedTotalMinor(range)` / `paymentsReceivedTotalMinor(range)`. Engine. |
| Collectible / **Net collection rate** | 🚧 | Collected is engine; **collectible + the rate are ABSENT** — needs a modeled collectible + an engine ratio. |
| **DSO** | 🚧 | ABSENT — engine method. |
| **AR roll-forward** (opening/charges/collections/adjustments/write-offs/closing) | 🚧 **reconcile-to-the-unit statement** | Charges/collections/closing are engine figures, but **opening**, **contractual adjustments** and **write-offs** are ABSENT/unmodeled → the bridge **cannot tie out today**. The engine must produce a **tying** roll-forward (opening → closing reconciled to the unit); the page renders it. The page must **NEVER** compute the bridge or subtract the lines client-side. |
| **By-payer amounts** | 🚧 | ABSENT aggregate + incomplete payer dimension → engine `groupBy(payer)` after modeling the dimension. |
| Top-overdue balances / age / stage | ✅ (data) / 🚧 (rollup) | Balances read from stored `open_balance_minor` + folded `DunningEvent`s (no money summing — fence-safe). But a per-ACCOUNT "top N by balance" rollup is not an engine aggregate yet. |
| Charged-vs-collected trend points | 🚧 | ABSENT — engine time-series method. |

**Write-offs / contractual adjustments (read-only + operator-gated):** these appear only as **roll-forward lines**.
The report is a **read-only** view — it must **never initiate or compute** a write-off/adjustment. A write-off is
**operator-gated and happens in the engine elsewhere** (today the credit note is the de-facto operator-gated,
`billing.manage` write-off; there is no page action on the AR report to create one). If the roll-forward is built,
write-offs/adjustments must be modeled as operator-gated ledger movements and **displayed read-only** here.

**Net fence finding:** nothing on the *live* AR surface recomputes money — every displayed figure comes from
`MetricsService` over the reconciled `invoice_balances` projection (the existing invoice list/detail already comment
"no financial aggregation happens here"). The **only** fence risk is *future* work: matching the wireframe's
roll-forward / DSO / collection-rate / by-payer / trend must be done as **engine methods**, or it would force
page-side money math. Flag any PR that computes these in the Vue.

---

## 6. Correctly-more-real (keep)
- The live aging is **engine-computed over `invoice_balances`** (the reconcile-to-the-unit projection; reporting's
  outstanding == the engine's I2 actual) — more real than a static mock, and already fence-correct.
- Aging caption **"Amounts are factual and are not adjusted for expected collectability"** — honest; the wireframe's
  "collectible" implies an adjustment the engine deliberately does not make.
- **No "bad debt" / write-off labeling** on aging (explicit non-interpretation).
- The **dunning worklist** reads stored balances + folds real `DunningEvent`s; **operator-gated, idempotent** "Send
  reminders" ("re-running the same day changes nothing"); ascending, never-skip-a-level, never auto-escalates —
  more real (and more fence-correct) than the wireframe's static stage labels.
- `/reporting` financial section is **fail-closed** (no `billing.view` ⇒ no financial block).
- Money is **integer minor units end-to-end**; the view formats only.

---

## 7. Prioritised parity punch-list
**Visual-first (frontend over existing engine data):**
1. Restructure `/billing/aging` (or a new Billing & AR report page) into the management-report grid: headline band +
   the aging table + Swiss number format (`CHF ` prefix, `'` grouping) — display only.
2. Period switcher (Week/Month/Quarter/YTD · Compare) over the range-parameterised `MetricsService` calls; CSV /
   Export PDF.

**Engine-aggregate backend gaps (each its own gate — new tested `MetricsService`/engine methods, never page math):**
3. **AR roll-forward reconciliation** (High) — a tying opening→closing bridge; **depends on #6** (model write-offs
   + contractual adjustments as operator-gated ledger movements). Reconcile-to-the-unit; displayed read-only.
4. DSO metric.
5. Net collection rate (+ a modeled "collectible").
6. Write-offs / contractual adjustments as operator-gated ledger movements (blocker for #3; read-only on the report).
7. By-payer dimension (extend payer types / insurer entity) + `groupBy(payer)` aggregate.
8. Charged-vs-collected weekly time series (+ stat-card sparklines).
9. Account-level overdue rollup + named dunning stages + the **AR Account Detail** drill (its own wireframe/gate).

**Money-fence (must-hold):** every figure above is engine-computed + displayed; **no page-side money math**. The
roll-forward must **tie out in the engine**; write-offs/adjustments stay **operator-gated + read-only** on this
report.

---

## Parity progress (per gate)

| Gate | Item | Status |
|---|---|---|
| **BILLAR.P1** | Write-offs + contractual adjustments as operator-gated, reconciling, append-only ledger movements (`InvoiceAdjustment` + `AdjustmentService`; I2/I6 extended, tie-out δ=0, count still 6) | **RESOLVED** — the money-integrity foundation. No reporting UI this gate. |
| BILLAR.P2 (next) | AR roll-forward reconciliation (opening → charges − collections − adjustments − write-offs → closing), tying out in the engine — now unblocked by P1 | pending |
| BILLAR.P3+ | DSO · net collection rate · by-payer dimension+aggregate · charged-vs-collected series · management-report grid (visual) · account-level overdue rollup + AR Account Detail | pending |
