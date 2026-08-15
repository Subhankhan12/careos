# Module: Reporting (`Modules\Reporting`)

## Purpose

Tenant-scoped, READ-ONLY aggregation layer exposing universal operational/financial
metrics — the query foundation for post-discovery dashboards (P0P.G14). NO UI exists:
service + artisan command only. Dashboards get wired AFTER discovery says which
metrics matter; this layer makes that wiring fast.

## Key tables

None. Reporting owns no tables, runs no migrations, and never writes. It reads other
modules' tenant-owned data through their Eloquent query surfaces.

## Key services / classes

- `Providers\ReportingServiceProvider` - registers the console command; no
  migrations, no bindings.
- `Services\MetricsService` - one clear method per metric, each taking an actor +
  date range (+ optional branch where the data has a branch dimension):
  - OPERATIONAL (`reporting.view`): `appointmentsInRange` (total + zero-filled
    per-status breakdown over all 8 lifecycle statuses), `noShows` ({no_show,
    scheduled, rate}; denominator = ALL appointments in range regardless of final
    status), `checkedInCount` (by `checked_in_at` moment; P0P.G7 data lives on
    appointments), `visitsCompletedInRange` (Nursing visits status=completed,
    attributed by `scheduled_start_at`), `activePatientsCount` (distinct patients
    with any appointment/encounter/visit in range — a count, never a list).
  - FINANCIAL (`billing.view`, integer minor units, F.7 definitions):
    `invoicedTotalMinor` (I4 verbatim: series=INV + frozen statuses + `issue_date`
    in range, sum `total_minor`), `paymentsReceivedTotalMinor` (`received_on` in
    range; refunds are separate rows, not netted), `outstandingBalanceMinor`
    (point-in-time sum of `invoice_balances.open_balance_minor` over issued INV
    invoices — the I2 projection; no date range), `agingBuckets(asOf)` (open
    balance split current / 1-30 / 31-60 / 61-90 / 90+ days past `due_date`;
    factual date math, no labeling), `overdueBalanceMinor(asOf)` (Σ the past-due
    aging buckets).
  - FINANCIAL — the BILLING & AR wireframe-parity methods (BILLAR/ARDETAIL,
    `billing.view`, integer minor, all TIE δ=0; the pages DISPLAY these — no
    page-side money math; see [[D-150]]/[[D-151]]): `arRollForward` (opening +
    charges − collections − adjustments − write-offs = closing, ties two ways,
    a non-tie surfaced) · `daysSalesOutstanding` + `netCollectionRate` (+ honest
    collectible = charges − contractual; zero/edge → honest null "—") · `arByPayer`
    (groups over the real `payer_type`, tie δ=0; finer Swiss taxonomy is a flagged
    gap, not fabricated) · `chargedVsCollectedTrend(from,to,bucket)` (buckets
    partition the range δ=0, shared helpers) · `topOverdueAccounts(asOf,limit)`
    (per-account overdue rollup ordered most-overdue-first; stage = real
    `max(DunningEvent.level)`; rollup ties to its invoices + `overdueBalanceMinor`,
    δ=0) · `accountLedger(accountId,asOf)` (per-account running-balance ledger; Σ
    rows === `outstandingBalanceMinor` for the account, final running === total,
    δ=0) · `accountDunning(accountId,feeCodeByLevel,asOf)` (READ-ONLY display of the
    real dunning state machine — the persisted `dunning_events` + per-event fee
    matched to the real captured fee Charge; fees tie). The private shared helpers
    (`chargesBilledMinor` net of credit-note cancellations, `netCollectionsMinor`,
    `contractualAdjustmentsMinor`, `writeOffsMinor`, `dateBounds`/`dateTimeBounds`)
    give ONE definition of "charges"/"collections" across all of the above.
  - THROUGHPUT (`reporting.view`, counts only): `encountersInRange` (`started_at`),
    `signedNotesInRange` (status=signed + `signed_at`), `ordersPlacedInRange`
    (`ordered_at`).
- `Services\ReportingService` - `summary(actor, from, to, ?branch)` assembles the
  full bundle as PLAIN DATA (range/operational/throughput[/financial]). Requires
  `reporting.view`; the `financial` section is included ONLY when the actor also
  holds `billing.view` (omitted otherwise, fail-closed). Aging in the summary is
  as-of the range end.
- `Console\ReportingSummaryCommand` - `reporting:summary {tenant} {from} {to}
  {--branch=}` prints the bundle as JSON (ops/debug proof, NOT a UI). Unattended
  actor per D-067 via `SystemActorResolver::forPermission(tenant,
  'reporting.view')`; nobody qualified → refused.

## Invariants enforced

- Facts, not judgments: every result is counts/sums/rates. No good/bad/high/low/
  status/grade/score/label keys anywhere (test walks the bundle recursively; every
  leaf value is int|float).
- ELECTRIC FENCE: operational + financial aggregates only — no clinical
  interpretation, no risk scoring, no "sickest patients", no outcome grading.
- Read-only + fail-closed: all queries run through BelongsToTenant models, so no
  tenant context → throw; cross-tenant aggregation is impossible (tested with two
  tenants). The layer performs zero writes (tested: audit_events count unchanged
  by a full summary).
- Aggregates are NOT patient records → NO patient-scoped read-audit rows. If a
  future metric can resolve to a single patient, it must be treated as a patient
  read instead (documented in the service header).
- Money is integer minor units; financial definitions reuse ReconciliationEngine
  (I4/I2) verbatim so reporting numbers agree with the billing source of truth —
  proven against DemoClinicSeeder's reconciled month (I4 expected == invoiced
  total; I2 projection sum == outstanding).
- RBAC mapping: `reporting.view` (NEW; org_admin + coordinator) gates operational +
  throughput; `billing.view` (existing; org_admin + billing) gates financial.
- Branch filtering only where the table carries branch_id (appointments, visits,
  encounters); invoices/payments/notes/orders have no branch dimension and take no
  branch parameter (documented).
- Arch boundary: Reporting may read care modules but never Audit models, AiCore,
  Comms, Import, or FrontDesk (check-in data lives on appointments).

## Status

**P0P.G14 complete.** MetricsService (12 metrics; +`overdueBalanceMinor` added in
CLINIC.W6) + ReportingService summary + reporting:summary command; 10 seeded
exact-number tests including the F.7 reconciliation agreement on the demo tenant.

**Reporting dashboard built (CLINIC.W7).** `Http/Controllers/ReportingDashboardController`
(`GET /reporting`, gate `reporting.view`) renders `Reporting/Dashboard.vue` from
`ReportingService::summary($actor, $from, $to)` verbatim — operational + throughput +
(only with `billing.view`) financial. A date-range picker (defaults month-to-date)
re-queries via `?from/?to`. FACTS ONLY: neutral styling, no judgment/target/trend/grade
fields; the no_show `rate` is shown as a formatted % (a service fact). `currency` comes
from `SettingsService`; money stays integer minor units and the view only formats. RBAC
proven in `tests/Feature/Billing/BillingUiPart2Test.php`: coordinator (reporting.view,
no billing.view) → operational-only, `financial` omitted; billing role (no reporting.view)
→ 403; a recursive test asserts no judgment key leaks. See [[Billing]].

**Staff landing consumes MetricsService (FIX.2).** `App\Http\Controllers\AppLandingController`
(`GET /app`) assembles a today, tenant-wide view-model from the SAME service — appointments
(+ by_status), waiting (arrived), no-shows, active patients (operational, only with
`reporting.view`) and outstanding balance (financial, only with `billing.view`). Unlike
`ReportingService::summary` (which requires reporting.view), the landing calls each metric
CONDITIONALLY on `Gate::allows` so a role with neither (e.g. reception) gets the shell with
both props `null` — never a throw. Genuine zeros (operational present, counts 0) replaced the
old "awaiting data" stub. Test: `tests/Feature/AppLandingTest.php`. No new metric invented.

## Open items

- The reporting surface wires ONLY the metrics `ReportingService::summary` already returns.
  New metrics (production-by-category, provider throughput, recall compliance, etc.) are
  post-discovery work — add the service method first, then surface it; never invent a metric
  in the controller/view.
