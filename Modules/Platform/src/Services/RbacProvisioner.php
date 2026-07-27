<?php

namespace Modules\Platform\Services;

use Modules\Platform\Models\Permission;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\Tenant;

/**
 * Seeds the platform permission catalog and the per-tenant starter roles.
 *
 * The catalog is shared across tenants; the starter role templates are created
 * for each tenant on creation (wired via a Tenant `created` hook in
 * PlatformServiceProvider). Both operations are idempotent.
 *
 * `super_admin` is intentionally NOT a seeded tenant role: the platform
 * super-admin is the user with tenant_id = null, bypassing via Gate::before.
 */
class RbacProvisioner
{
    /**
     * The starter permission catalog: key => human description.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'patient.view' => 'View patients',
        'patient.edit' => 'Create and edit patients',
        'patient.merge' => 'Merge duplicate patients',
        'appointment.manage' => 'Manage appointments',
        'agreement.manage' => 'Manage nursing service agreements',
        'dispatch.manage' => 'Assign and unassign nursing planned visits',
        'competency.manage' => 'Define nurse competencies and grant them to nurses',
        'timesheet.approve' => 'Approve nursing timesheets',
        'encounter.manage' => 'Manage clinical encounters',
        'note.write' => 'Write clinical note drafts',
        'note.sign' => 'Sign clinical notes',
        'note.supervise' => 'Supervise unsigned clinical notes worklist',
        'snippet.manage.shared' => 'Manage the tenant-wide shared text-snippet library',
        'order.manage' => 'Place and track structured clinical orders',
        'allergy.override' => 'Override deterministic allergy hard-stops',
        'ai.manage' => 'Manage governed AI actions',
        'comms.manage' => 'Manage secure messaging threads',
        'billing.view' => 'View billing',
        'billing.manage' => 'Manage billing tariffs and billable items',
        'reporting.view' => 'View operational reporting aggregates',
        'audit.view' => 'View the audit log',
        'admin.manage' => 'Manage tenant settings and users',
        'data.import' => 'Import patients from CSV',
        'dental.chart' => 'Chart teeth and dental findings (odontogram)',
        // Inpatient / hospital vertical (HOSPITAL.G1). Additive — see docs/HOSPITAL-PHASE1-ADT-MAP.md §4.
        'ward.manage' => 'Manage inpatient wards and units',
        'bed.manage' => 'Manage inpatient beds and their housekeeping status',
        'admission.manage' => 'Admit, transfer, and discharge inpatients (ADT)',
        'document.view' => 'View and download patient clinical documents (HIM/records)',
        // Pharmacy / medication-management vertical (PHARMACY.G1). Additive — see docs/HOSPITAL-PHASE2-PHARMACY-MAP.md §4.
        'formulary.manage' => 'Author the tenant medication formulary',
        'dispense.manage' => 'Dispense medications and manage pharmacy stock',
        'medication.prescribe' => 'Prescribe medication orders (dose/route/frequency)',
    ];

    /**
     * The starter tenant role templates: key => [name, permission keys].
     *
     * @var array<string, array{name: string, permissions: list<string>}>
     */
    public const ROLE_TEMPLATES = [
        'org_admin' => [
            'name' => 'Organisation Admin',
            'permissions' => [
                'admin.manage', 'patient.view', 'patient.edit', 'patient.merge',
                'appointment.manage', 'agreement.manage', 'dispatch.manage', 'competency.manage',
                'encounter.manage',
                'timesheet.approve', 'note.write', 'note.sign', 'note.supervise', 'allergy.override',
                'snippet.manage.shared', 'order.manage', 'ai.manage', 'comms.manage', 'billing.view',
                'billing.manage', 'reporting.view', 'audit.view', 'data.import', 'dental.chart',
                'ward.manage', 'bed.manage', 'admission.manage', 'document.view',
                'formulary.manage', 'dispense.manage', 'medication.prescribe',
            ],
        ],
        'coordinator' => [
            'name' => 'Nursing Coordinator',
            'permissions' => [
                'patient.view', 'appointment.manage', 'agreement.manage', 'dispatch.manage',
                'competency.manage', 'timesheet.approve', 'reporting.view',
            ],
        ],
        'doctor' => [
            'name' => 'Doctor',
            'permissions' => [
                // Doctor is the clinical-lead / treating-clinician role that also
                // curates the shared snippet library. In a dental tenant this IS the
                // general dentist — hence `dental.chart` (odontogram charting). A
                // dedicated dentist/hygienist/assistant role split is a later dental gate.
                'patient.view', 'patient.edit', 'appointment.manage', 'encounter.manage',
                'note.write', 'note.sign', 'order.manage', 'snippet.manage.shared', 'allergy.override',
                'dental.chart', 'medication.prescribe',
            ],
        ],
        'nurse' => [
            'name' => 'Nurse',
            'permissions' => [
                'patient.view', 'appointment.manage', 'encounter.manage',
                'note.write', 'note.sign', 'order.manage',
            ],
        ],
        'reception' => [
            'name' => 'Reception',
            'permissions' => ['patient.view', 'appointment.manage', 'comms.manage'],
        ],
        'billing' => [
            'name' => 'Billing',
            'permissions' => ['billing.view', 'billing.manage'],
        ],
        // Inpatient / hospital vertical starter roles (HOSPITAL.G1). Additive; each
        // maps to an existing clinical role plus the minimal inpatient permission it
        // needs (docs/HOSPITAL-PHASE1-ADT-MAP.md §4). Ward-level scope is branch-level
        // for Phase 1 (deeper ward scoping is a later abac_conditions gate).
        'ward_nurse' => [
            'name' => 'Ward Nurse',
            'permissions' => [
                // Bedside inpatient nursing = the existing nurse clinical set (charting,
                // notes, orders). Bed/ward administration is the charge nurse / bed manager.
                'patient.view', 'appointment.manage', 'encounter.manage',
                'note.write', 'note.sign', 'order.manage',
            ],
        ],
        'charge_nurse' => [
            'name' => 'Charge Nurse',
            'permissions' => [
                // The ward's shift lead: the nurse clinical set + chart-completion
                // oversight (note.supervise) + ward-census/bed oversight + reporting.
                'patient.view', 'appointment.manage', 'encounter.manage',
                'note.write', 'note.sign', 'note.supervise', 'order.manage',
                'reporting.view', 'bed.manage',
            ],
        ],
        'hospitalist' => [
            'name' => 'Hospitalist',
            'permissions' => [
                // Treating inpatient physician = the doctor clinical set (minus
                // dental.chart) plus admit/transfer/discharge authority.
                'patient.view', 'patient.edit', 'appointment.manage', 'encounter.manage',
                'note.write', 'note.sign', 'order.manage', 'snippet.manage.shared',
                'allergy.override', 'admission.manage', 'medication.prescribe',
            ],
        ],
        'bed_manager' => [
            'name' => 'Bed Manager',
            'permissions' => [
                // Runs bed/ward operations (housekeeping status, ward layout); sees who
                // is where + occupancy reporting. No clinical charting.
                'ward.manage', 'bed.manage', 'patient.view', 'reporting.view',
            ],
        ],
        'admissions_clerk' => [
            'name' => 'Admissions Clerk',
            'permissions' => [
                // Registration + admission intake: reception's set + patient.edit + ADT.
                'patient.view', 'patient.edit', 'appointment.manage', 'comms.manage',
                'admission.manage',
            ],
        ],
        'him_records' => [
            'name' => 'Health Information / Records',
            'permissions' => [
                // Chart-completion oversight + clinical-document access + audit visibility.
                'patient.view', 'note.supervise', 'document.view', 'audit.view',
            ],
        ],
        // Pharmacy / medication-management vertical starter roles (PHARMACY.G1). Additive; the map §4.
        'pharmacist' => [
            'name' => 'Pharmacist',
            'permissions' => [
                // Authors the tenant formulary + dispenses + bills dispensed meds through the existing
                // engine (billing.manage, PHARMACY.G5). (Prescribing / allergy-override stay physician acts.)
                'patient.view', 'formulary.manage', 'dispense.manage', 'billing.manage',
            ],
        ],
        'pharmacy_technician' => [
            'name' => 'Pharmacy Technician',
            'permissions' => [
                // Dispenses + manages stock UNDER a pharmacist; no formulary authoring.
                'patient.view', 'dispense.manage',
            ],
        ],
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Upsert the shared permission catalog (platform-level, no tenant context).
     */
    public function syncPermissionCatalog(): void
    {
        foreach (self::PERMISSIONS as $key => $description) {
            Permission::query()->updateOrCreate(['key' => $key], ['description' => $description]);
        }
    }

    /**
     * Create/refresh the starter roles for a tenant and attach their permissions.
     */
    public function provisionTenant(Tenant $tenant): void
    {
        $this->syncPermissionCatalog();

        // System mode: roles are written with an explicit tenant_id, without an
        // ambient tenant context (tenant creation happens outside any tenant).
        $this->context->system(function () use ($tenant) {
            foreach (self::ROLE_TEMPLATES as $key => $template) {
                $role = Role::query()
                    ->where('tenant_id', $tenant->getKey())
                    ->where('key', $key)
                    ->first() ?? new Role;

                $role->forceFill([
                    'tenant_id' => $tenant->getKey(),
                    'key' => $key,
                    'name' => $template['name'],
                    'is_system' => true,
                ])->save();

                $permissionIds = Permission::query()
                    ->whereIn('key', $template['permissions'])
                    ->pluck('id');

                $role->permissions()->sync($permissionIds);
            }
        });
    }
}
