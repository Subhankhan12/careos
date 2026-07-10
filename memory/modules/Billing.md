# Billing module memory

## Status

Phase F active. P0F.G3 added deterministic charge validation with golden-file coverage.

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

## Open items

- F.4 begins invoice draft, finalization, and credit-note work.
