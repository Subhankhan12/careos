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
    factual date math, no labeling).
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

**P0P.G14 complete.** MetricsService (12 metrics) + ReportingService summary +
reporting:summary command; 10 seeded exact-number tests including the F.7
reconciliation agreement on the demo tenant.

## Open items

- Dashboards/UI are deliberately NOT built — post-discovery work, wired on top of
  this layer once discovery says which metrics matter.
