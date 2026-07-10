# Billing module memory

## Status

Phase F active. P0F.G4 added invoices, gapless numbering, issued-document immutability, and
credit notes.

## Key classes

- `Modules\Billing\Models\TariffCatalog`: tenant-owned catalog version with key/name/version,
  currency, effective date range, status, and JSON rules. Guards same-key version overlaps.
- `Modules\Billing\Models\TariffItem`: tenant-owned billable item linked to a catalog; stores
  `unit_price_minor` and `vat_rate_bp` as integers.
- `Modules\Billing\Services\TariffResolver`: resolves a code for a tenant/service date against
  the active catalog version covering that date; throws a distinct no-coverage exception.
- `Modules\Billing\Services\EuGenericTariffSeeder`: tenant-scoped/idempotent EU-Generic starter
  catalog seed, using tenant `currency` setting with default `EUR`.
- `Modules\Billing\Models\Charge`: tenant-owned billable event from an encounter, visit, or
  manual capture; stores tariff pointers plus snapshot columns.
- `Modules\Billing\Services\ChargeCaptureService`: captures encounter/visit/manual charges,
  enforces documentation-required items, computes integer line totals, cancels draft/validated
  charges with a reason, and writes patient-scoped audit events.
- `Modules\Billing\Models\ChargeViolation`: tenant-owned persisted validation failure linked to
  a charge, with rule, reason code, message, and JSON context.
- `Modules\Billing\Services\ChargeValidator`: consumes catalog-version JSON rules for a
  patient/period or explicit charge set, persists current violations, transitions clean charges to
  `validated`, and writes patient-scoped audit events.
- `Modules\Billing\Models\Invoice`: tenant-owned legal invoice/credit-note document. Drafts are
  editable; issued-or-beyond rows are frozen by model guards and DB triggers.
- `Modules\Billing\Models\InvoiceLine`: self-contained invoice lines copied from charge snapshots
  or original invoice lines for credit notes; lines freeze when the parent invoice is issued.
- `Modules\Billing\Models\InvoiceSequence`: per-tenant/per-series `next_number` row locked at
  issue time for gapless numbering.
- `Modules\Billing\Models\InvoiceBalance`: mutable balance/status projection for F.5 payments and
  credit-note effects; keeps legal invoice rows fully frozen.
- `Modules\Billing\Services\IssueService`: creates draft invoices from validated charges, issues
  invoices with sequence locking and per-line VAT rounding, creates CN-series credit notes, marks
  charges invoiced, and writes patient-scoped audit events.
- `Modules\Billing\Services\InvoicePdfRenderer`: renders the EU-Generic VAT invoice artifact to
  private tenant-prefixed local storage.

## Invariants

- Billing rows are tenant-owned and fail closed through `BelongsToTenant`.
- Catalog versions are unique by `(tenant_id, key, version)` and must not overlap effective date
  ranges for the same tenant/key.
- Money values are integers in minor units; VAT rates are integer basis points.
- Billing may use Platform/Patients/Scheduling/Clinical/Nursing contracts/events but not Audit
  models or AiCore; architecture tests include Billing.
- `billing.manage` is granted to org-admin and billing starter roles, not reception.
- Charge economics are immutable snapshots: `code`, `description`, `unit_price_minor`, and
  `vat_rate_bp` are copied from the resolved tariff item at capture and are never re-resolved when
  the tariff item changes.
- Charge source is encounter XOR visit OR manual; the DB check also blocks encounter+visit on one
  charge.
- Documentation-required tariff items require a signed encounter note or a completed visit.
- `line_total_minor = quantity * unit_price_minor`; VAT is later computed per line using the
  snapshotted `vat_rate_bp`, round-half-up, never from floats or rounded subtotal sums.
- Invoiced charges are not directly cancellable; F.4 credit-note mechanics will correct them.
- Charge validation is deterministic and re-runnable. A clean draft charge becomes `validated`;
  a charge with violations remains or returns to `draft`.
- Supported validation rules and reason codes:
  `MAX_QUANTITY_PER_PERIOD` -> `MAX_QUANTITY_PER_PERIOD_EXCEEDED`,
  `INCOMPATIBLE_CODES` -> `INCOMPATIBLE_CODES_SAME_DATE`,
  `REQUIRES_CODE` -> `REQUIRED_CODE_MISSING`,
  `DOCUMENTATION_REQUIRED` -> `DOCUMENTATION_REQUIRED_MISSING`.
- Documentation-required validation rechecks current source state at validation time: signed
  encounter note or completed visit. It does not trust the earlier capture-time state.
- Golden fixtures in `tests/Fixtures/billing/golden/` freeze exact catalog-version behavior and
  are loaded as a complete set by `ChargeValidationTest`.
- Invoice numbering is gapless per `(tenant_id, series)`: drafts have no number; issue locks the
  `invoice_sequences` row with `FOR UPDATE`, assigns `next_number`, increments it in the same
  transaction, and retries deadlocks so rollbacks burn no numbers.
- Invoice series choices: normal invoices use `INV`; credit notes use separate `CN` series.
- Issued invoice immutability: `invoices_issued_no_update/delete` block UPDATE/DELETE when
  `OLD.status IN ('issued','paid','partially_paid','cancelled_by_credit_note')`. `invoice_lines`
  triggers block UPDATE/DELETE when the parent invoice is in those statuses. Model guards mirror
  the same rule; drafts stay editable and draft -> issued is allowed.
- F.4 chose a separate `invoice_balances` table for mutable payment/open-balance state, so F.5
  allocations never need to update frozen legal invoice fields.
- D-F2 snapshot rule continues through invoice issue: invoice lines copy charge code,
  description, quantity, unit price, VAT basis points, and line total; issued invoices do not
  re-read tariff tables.
- D-F3 rounding rule: VAT is computed per invoice line using the line's snapshotted
  `line_total_minor` and `vat_rate_bp` with integer round-half-up
  `intdiv(abs(line_total) * vat_rate_bp + 5000, 10000)`, signed back for credit notes; totals sum
  the already-rounded line VAT amounts. Never use floats or round a subtotal sum.
- Credit notes are new issued documents, require a reason, use negative quantities/line totals/VAT,
  reference original invoice lines, and leave the original invoice document unchanged. Full credits
  may update only the mutable `invoice_balances` projection for the original.

## Open items

- F.5 begins payment allocation/reconciliation against `invoice_balances`.
