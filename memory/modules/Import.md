# Module: Import (`Modules\Import`)

Onboarding / migration tooling: generic CSV **patient import** with column mapping, a mandatory
dry-run, duplicate detection against the existing engine, and an audited commit. This is the thing
that lets a real clinic move off their old system.

Chosen name **Import** (not `Migration`) to avoid confusion with database migrations (D-072).

## How to use it (staff)

1. Nav → **Import** (`/imports`, RBAC `data.import`, org_admin only by default).
2. **Upload** a CSV (`/imports/create`). It is stored on the private disk, tenant-prefixed
   (`tenants/{tenant}/imports/{ulid}.csv`), no public URL. Headers + raw rows are parsed with
   **league/csv** (BOM/quoting/delimiter-sniff/encoding-repair — never hand-rolled).
3. **Map columns** to CareOS fields + pick an explicit **date format**; unmapped columns are ignored.
   Required to be mapped: `first_name`, `last_name`, `date_of_birth`.
4. **Run dry-run** (`ImportValidator`) — validates every row, parses dates via the chosen format,
   runs the existing `DuplicateDetector`, and produces a summary (valid/invalid/duplicate + per-row
   errors + duplicate candidates). **Writes NOTHING to patients.**
5. **Choose a duplicate policy** (default `skip`; also `import_as_new`, `merge`) and **commit**
   (`ImportCommitter`).

CLI/service entry points: `ImportBatchService::upload/setMapping`, `ImportValidator::validate`,
`ImportCommitter::commit`.

## Invariants (locked by tests in `tests/Feature/Import/ImportTest.php`)

- Imported patients go through the REAL `PatientService::create` (+ contacts/identifiers/coverages) —
  never raw inserts — so **MRN generation, tenancy, validation, and audit** all apply as normal.
- **Dry-run is mandatory and writes nothing**: after `validate()` the patient count is unchanged;
  only `commit()` on a `validated` batch creates rows.
- Duplicate detection reuses `DuplicateDetector::findForDemographics`; a top candidate with
  `score >= 50` (medium/high) → row `duplicate` + `matched_patient_id` + reasons. Default commit
  policy **skips** duplicates. `merge` goes through the existing audited `PatientMergeService::merge`
  (new record merged INTO the matched existing patient; needs `patient.merge`).
- **Idempotent**: committing a batch twice does not re-import (guard on batch status `committed` +
  per-row status). One `patient.import.committed` audit event per commit; the chain verifies.
- Everything tenant-scoped/fail-closed (`ImportBatch`/`ImportRow` are `BelongsToTenant`); another
  tenant's patients are neither matched nor visible. RBAC `data.import` on every controller action.
- Malformed / mis-encoded CSV degrades gracefully (invalid UTF-8 repaired; parse failure → clean
  `ImportException`, never a fatal).

## Key classes

- Models: `ImportBatch` (uploaded/mapped/validated/committed/failed; mapping + summary JSON),
  `ImportRow` (pending/valid/invalid/duplicate/imported/skipped; raw/errors/match JSON).
- Services: `CsvReader`, `PatientFieldMap` (field catalog + shaping to PatientService inputs),
  `ImportBatchService`, `ImportValidator` (dry-run), `ImportCommitter`.
- Controller `ImportBatchController` (index/create/store/show/mapping/validate/commit) → routes
  `import.*`. Pages `Import/Index.vue` + `Import/Upload.vue` (net-new, on the current shell/tokens;
  NOT part of the design pass; presentational only, server enforces everything).
- Permission `data.import` added to `RbacProvisioner::PERMISSIONS` + the `org_admin` role template.

## Boundaries

Import may use Platform, Patients (services + models), and `Audit\Services` (not `Audit\Models`).
It must NOT depend on AiCore/Scheduling/Clinical/Nursing/Billing/Comms
(`tests/Architecture/ModuleBoundariesTest.php`).

## Open items / deferred

- Import types beyond `patients` (the `type` column is extensible; e.g. appointments/charges) —
  build when a customer needs it.
- Balance/charge import (money in integer minor units) is out of scope for P0P.G6 (patients + their
  contacts/identifiers/coverages only).
- Existing tenants need a re-provision (`RbacProvisioner::provisionTenant`) to pick up `data.import`;
  fresh tenants get it automatically on `Tenant::created`.
