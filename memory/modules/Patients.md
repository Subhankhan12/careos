# Module: Patients (`Modules\Patients`)

## Purpose

Tenant-owned patient CRM core: demographics, contacts, optional identifiers, coverages, MRN
generation, patient read-logging for the "who accessed my record" audit report, and patient
consent capture/scope checks, plus separate patient portal login identity.
Staff-facing patient index/search, registration wizard, and 360 view are now exposed through
Inertia pages.

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
- `consent_templates` - tenant-owned versioned consent text. `key`, `title`, `body`, integer
  `version`, JSON `scope_keys`, `is_active`; unique `(tenant_id, key, version)`.
- `patient_consents` - tenant-owned captured consent. `patient_id`, `template_id`,
  `template_version`, immutable signed template key/title/body/scope snapshot, `status`,
  `granted_at`, nullable `withdrawn_at`/`expires_at`, JSON `signature`, and `captured_by`.
  Index `(tenant_id, patient_id, status)`.
- `portal_accounts` - tenant-owned patient login identity separate from staff `users`.
  `patient_id`, globally unique `email`, nullable hashed `password` until activation, `status`,
  invite/activation/last-login timestamps, remember token. Unique `(tenant_id, patient_id)`.
- `portal_login_tokens` - tenant-owned short-lived magic-link + OTP verifiers for portal
  invite/activation. Stores token hash, OTP hash, purpose, expiry, and consumed timestamp.

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
- `Models\ConsentTemplate` and `PatientConsent` - tenant-owned consent templates and captures;
  patient/template/capturer references are same-tenant guarded.
- `Services\ConsentService` - grants current active template versions, withdraws with reason,
  writes patient-scoped audit events, and resolves `has(patient, scopeKey)` fail-closed.
- `Models\PortalAccount` and `PortalLoginToken` - tenant-owned portal identity and invite token
  rows; patient/account references are same-tenant guarded.
- `Services\PortalAccessService` - creates portal invites, sends magic-link/OTP notification,
  activates accounts with first password, logs in via the `patient` guard, and audits portal
  invite/first-login/login.
- `Http\Middleware\IdentifyTenantFromPortalSession`, `EnsurePatientPortalAuthenticated`, and
  `EnsurePortalConsent` - re-establish tenant context from the portal session, authenticate the
  patient guard, and enforce `portal.access` consent.
- Clinical document portal endpoints use the existing patient guard/session and portal consent
  middleware; portal accounts can list/download only Clinical documents explicitly shared for
  their own `patient_id`.
- `Http\Controllers\PatientIndexController` - RBAC-gated patient index/search using FULLTEXT
  name matching with deterministic fallback and optional DOB filter.
- `Http\Controllers\PatientRegistrationController` - RBAC-gated registration wizard endpoints;
  duplicate-check JSON endpoint calls `DuplicateDetector` before create.
- `Http\Controllers\PatientShowController` - RBAC-gated patient 360; calls `auditRead()`,
  returns demographics/contacts/coverages/consents and access log.
- Vue pages under lowercase `resources/js/pages/Patients`: `Index.vue`, `Register.vue`,
  `Show.vue`; components `StepNav`, `Tabs`, `DataList`.

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
- Patient consents move with patient merge/unmerge snapshots.
- Consent checks fail closed: no non-expired granted consent carrying the requested signed scope
  means `false`; withdrawn/expired consents never grant access.
- Captured consents keep immutable template text/scope snapshots even if templates are edited or
  superseded later (D-023).
- Consent grant/withdraw writes patient-scoped audit actions `consent.granted` and
  `consent.withdrawn`; read-logging remains reserved for patient record reads.
- Portal access is fail-closed: invite, activation, password login, and `/portal` access require
  an active `portal.access` consent.
- Patient portal auth uses the `patient` guard only. Patient accounts cannot satisfy staff/admin
  `web` guard routes, and staff users cannot satisfy portal guard routes.
- Portal sessions are tenant-bound (`portal_tenant_id`) and cross-tenant session tampering is
  denied before consent can grant access.
- Portal document access is fail-closed: only `shared_with_patient=true` documents for the
  authenticated account's own patient are visible/downloadable, and every download is read-logged.
- Patient UI routes are staff `web` guard only and RBAC-gated: `patient.view` for index/show,
  `patient.edit` for register/create/duplicate-check and consent/portal actions.
- Patient 360 viewing writes the existing patient-scoped `read` audit row and surfaces the
  tenant-scoped `PatientAccessReport`.
- Registration duplicate warnings are live client calls to `/patients/duplicates`, using B.3
  scoring before the create POST.

## Status

**Phase B COMPLETE.** Patients module registered; CRM core tables/models, MRN generator,
transactional service, read-logging, access-report stub, demographic duplicate detection,
permissioned audited merge, snapshot-based unmerge, consent engine, portal accounts, and first
staff-facing patient UI are in place.

## Open items

- Dev MariaDB 10.4 uses plain FULLTEXT while MySQL 8 CI/prod uses `WITH PARSER ngram` - patient
  name search tokenizes differently across environments; validate search parity before production.
- Portal UI screens are later; B.5 only exposes backend routes/guards/services.
- Later gates must call `ConsentService::has()` before portal access or clinical data sharing.
- D.4 Clinical document sharing now calls `ConsentService::has(patient, 'portal.access')` before
  exposing documents to the portal.
- MySQL 8 CI should verify the `WITH PARSER ngram` path; local MariaDB 10.4 lacks the ngram parser
  and uses the migration fallback FULLTEXT index.
