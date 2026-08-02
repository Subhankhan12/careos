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
        // Operating-theatre / surgery vertical (SURGERY.G1 — Phase 5). Additive — see docs/HOSPITAL-PHASE5-SURGERY-MAP.md §4.
        'theatre.manage' => 'Manage operating theatres (OR rooms)',
        'surgery.schedule' => 'Book and schedule surgical theatre blocks',
        'surgery.manage' => 'Create and manage surgical cases',
        // Emergency Department vertical (ED.G1 — Phase 6). Additive — see docs/HOSPITAL-PHASE6-ED-MAP.md §4.
        // The ED→ADT admit handoff (G5) additionally requires the existing `admission.manage`.
        'ed.manage' => 'Register ED visits and advance their flow (Emergency Department)',
        'triage.record' => 'Record a triage assessment and the nurse-assigned acuity (ED)',
        // Laboratory / LIS vertical (LAB.G1 — Phase 3). Additive — see docs/HOSPITAL-PHASE3-LAB-MAP.md §5.
        // Ordering a lab test reuses the existing `order.manage`; results (LAB.G4) will use `lab.result`.
        'lab.catalog' => 'Author the tenant lab test catalog + reference ranges (Laboratory)',
        'lab.result' => 'Enter lab results and manage specimens (Laboratory)',
        // Radiology / RIS vertical (RAD.G1 — Phase 4). Additive — see docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md §5.
        // Ordering an imaging exam reuses the existing `order.manage`; the report reuses `note.write`/`note.sign`.
        'radiology.catalog' => 'Author the tenant imaging exam catalog (Radiology)',
        'radiology.study' => 'Record and track imaging studies + the modality worklist (Radiology)',
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
                'theatre.manage', 'surgery.schedule', 'surgery.manage',
                'ed.manage', 'triage.record',
                'lab.catalog', 'lab.result',
                'radiology.catalog', 'radiology.study',
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
        // Operating-theatre / surgery vertical starter roles (SURGERY.G1 — Phase 5). Additive; the map §4.
        // Later gates add checklist.complete (G4) / consumable-stock (G5) / anesthesia.document to these.
        'surgeon' => [
            'name' => 'Surgeon',
            'permissions' => [
                // The operating clinician — manages + schedules the surgical case, charts clinically.
                'patient.view', 'encounter.manage', 'note.write', 'note.sign', 'order.manage',
                'surgery.manage', 'surgery.schedule',
            ],
        ],
        'anesthetist' => [
            'name' => 'Anesthetist',
            'permissions' => [
                // Co-manages the case + charts clinically (the anesthesia record + ASA arrive in a later gate).
                'patient.view', 'encounter.manage', 'note.write', 'note.sign', 'surgery.manage',
            ],
        ],
        'scrub_nurse' => [
            'name' => 'Scrub / OR Nurse',
            'permissions' => [
                // Assists in theatre + charts (checklist.complete + consumable stock arrive in later gates).
                'patient.view', 'note.write',
            ],
        ],
        'surgical_scheduler' => [
            'name' => 'Surgical Scheduler',
            'permissions' => [
                // Runs the OR list — authors theatres + books surgical blocks.
                'patient.view', 'appointment.manage', 'theatre.manage', 'surgery.schedule',
            ],
        ],
        // Emergency Department vertical starter roles (ED.G1 — Phase 6). Additive; the map §4. Later gates add
        // triage.record usage (G2) + billing.manage (G6); the ED physician gets admission.manage for the
        // ED→ADT admit handoff (G5). Acuity is ASSIGNED by the triage nurse (a recorded fact), never computed.
        'ed_physician' => [
            'name' => 'ED Physician',
            'permissions' => [
                // The treating emergency clinician — runs the ED visit + its flow, charts clinically, and can
                // admit from the ED (admission.manage — the ED→ADT handoff, G5).
                'patient.view', 'encounter.manage', 'note.write', 'note.sign', 'order.manage',
                'ed.manage', 'admission.manage',
            ],
        ],
        'triage_nurse' => [
            'name' => 'Triage Nurse',
            'permissions' => [
                // Assesses at arrival: records the triage assessment + the ASSIGNED acuity (triage.record, used
                // in G2) and moves the patient into treatment (ed.manage). Charts clinically.
                'patient.view', 'encounter.manage', 'note.write',
                'ed.manage', 'triage.record',
            ],
        ],
        'ed_charge_nurse' => [
            'name' => 'ED Charge Nurse',
            'permissions' => [
                // The ED's shift lead: runs the tracking board + oversees flow and triage, with chart-completion
                // oversight (note.supervise) + reporting.
                'patient.view', 'encounter.manage', 'note.write', 'note.sign', 'note.supervise', 'order.manage',
                'reporting.view', 'ed.manage', 'triage.record',
            ],
        ],
        // Laboratory / LIS vertical starter roles (LAB.G1 — Phase 3). Additive; the map §5. Ordering a lab
        // test reuses `order.manage` (the clinician orders); the lab bench enters results with `lab.result`.
        'lab_tech' => [
            'name' => 'Lab Technician',
            'permissions' => [
                // The lab bench: sees orders, tracks specimens + enters results (lab.result, used in G3/G4).
                'patient.view', 'order.manage', 'lab.result',
            ],
        ],
        'pathologist' => [
            'name' => 'Pathologist',
            'permissions' => [
                // The lab lead: authors the test catalog + ranges (lab.catalog), reviews/records readings,
                // charts clinically.
                'patient.view', 'encounter.manage', 'note.write', 'note.sign', 'order.manage',
                'lab.catalog', 'lab.result',
            ],
        ],
        'phlebotomist' => [
            'name' => 'Phlebotomist',
            'permissions' => [
                // Collects specimens (lab.result, scoped to specimen collection in G3).
                'patient.view', 'lab.result',
            ],
        ],
        // Radiology / RIS roles (RAD.G1 — Phase 4). Ordering reuses `order.manage`; the report reuses
        // `note.write`/`note.sign` (the sign-and-lock note); the modality worklist + study record use
        // `radiology.study`; the exam catalog uses `radiology.catalog`.
        'radiographer' => [
            'name' => 'Radiographer',
            'permissions' => [
                // The imaging bench: sees orders, records/acquires studies + manages the modality worklist (G3),
                // uploads the exported still. NO catalog authoring.
                'patient.view', 'order.manage', 'radiology.study',
            ],
        ],
        'radiologist' => [
            'name' => 'Radiologist',
            'permissions' => [
                // The radiology lead: authors the exam catalog (radiology.catalog), reads studies, and AUTHORS +
                // signs the report (note.write/note.sign — the human read, recorded), charts clinically.
                'patient.view', 'encounter.manage', 'note.write', 'note.sign', 'order.manage',
                'radiology.catalog', 'radiology.study',
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
