# Module: Patients (`Modules\Patients`)

## Purpose

Tenant-owned patient CRM core: demographics, contacts, optional identifiers, coverages, MRN
generation, and patient read-logging for the "who accessed my record" audit report.

## Key tables

- `patients` - tenant-owned (`BelongsToTenant`). ULID id, `tenant_id`, per-tenant `mrn`,
  demographics, nullable `deceased_at`, `status`, nullable `merged_into_id`, timestamps,
  soft deletes. Unique `(tenant_id, mrn)`, lookup `(tenant_id, last_name, date_of_birth)`,
  FULLTEXT `patients_name_fulltext` on `(first_name, last_name)`.
- `patient_contacts` - tenant-owned. `patient_id`, `type`, optional scalar `value`, address
  fields (`line1`, `line2`, `city`, `postal`, `country`), `is_primary`, timestamps.
- `patient_identifiers` - tenant-owned optional attributes. `patient_id`, `system`, `value`,
  nullable `valid_from`/`valid_to`. Not unique and not a dedupe key (D-021).
- `patient_coverages` - tenant-owned. `patient_id`, `payer_name`, `member_id`, nullable `plan`,
  EU-generic `coverage_type`, integer `priority`, nullable validity dates.

## Key services / classes

- `Models\Patient` - `BelongsToTenant`, `SoftDeletes`, `LogsReads`; has contacts, identifiers,
  coverages; `auditRead()` writes resource `patient` with `patient_id`.
- `Models\PatientContact`, `PatientIdentifier`, `PatientCoverage` - tenant-owned children;
  reject patient FKs invisible in the current tenant context.
- `Services\MrnGenerator` - tenant-row `FOR UPDATE` lock, fixed-width `MRN-000001` sequence,
  checks existing and soft-deleted MRNs before returning.
- `Services\PatientService` - create/update patient and child contacts/identifiers/coverages
  in one transaction.
- `Services\PatientAccessReport` - tenant-scoped stub listing read audit rows for a patient.
- `Services\DuplicateDetector` - tenant-scoped demographic duplicate scoring using deterministic
  name/DOB/address/identifier rules plus FULLTEXT support; returns reasons and confidence.
- `Services\PatientDuplicateReviewService` - review-list query wrapper for likely duplicates.
- `Services\PatientMergeService` - permissioned, reason-required merge/unmerge with audit snapshots.

## Invariants enforced

- Patients and all child rows are tenant-owned and fail closed without `TenantContext`.
- Child rows must point to a patient visible in the same tenant context.
- MRN is unique per tenant and generated collision-safe under the tenant lock.
- Identifiers are optional attributes; duplicate `(system, value)` values are allowed.
- Patient reads produce append-only audit `read` events with `patient_id` set.
- Soft-deleted patients are excluded by default.
- Duplicate detection never crosses tenants and never treats identifiers as the sole match key.
- Merge requires `patient.merge`, a reason, and same-tenant source/target. Source becomes
  `status=merged`, points to target, and is soft-deleted.
- Unmerge restores the source and child rows moved by the merge snapshot only; target records
  created after merge remain on the target (D-022).

## Status

**P0B.G3 COMPLETE.** Patients module registered; CRM core tables/models, MRN generator,
transactional service, read-logging, access-report stub, demographic duplicate detection,
permissioned audited merge, and snapshot-based unmerge are in place.

## Open items

- Dev MariaDB 10.4 uses plain FULLTEXT while MySQL 8 CI/prod uses `WITH PARSER ngram` - patient
  name search tokenizes differently across environments; validate search parity before production.
- B.6 patient 360 UI + registration wizard.
- MySQL 8 CI should verify the `WITH PARSER ngram` path; local MariaDB 10.4 lacks the ngram parser
  and uses the migration fallback FULLTEXT index.
