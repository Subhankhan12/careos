<?php

namespace App\Providers;

use App\AiCore\Tools\ClassifyDocumentTool;
use App\AiCore\Tools\ClinicalSummaryTool;
use App\AiCore\Tools\DraftRecallMessageTool;
use App\AiCore\Tools\DraftReplyTool;
use App\AiCore\Tools\FillFromWaitlistTool;
use App\AiCore\Tools\NursingProposeAssignmentsTool;
use App\AiCore\Tools\NursingReplanDayTool;
use App\AiCore\Tools\PreflightInvoiceTool;
use App\AiCore\Tools\SuggestChargeCodesTool;
use App\AiCore\Tools\SuggestSlotsTool;
use App\Audit\AuthAuditSubscriber;
use App\Audit\PlatformAuditContext;
use App\Clinical\NursingVisitVitalsReader;
use App\Comms\AgentInboxDraftProvider;
use App\Comms\EngineAppointmentReminderChannel;
use App\Comms\EngineDunningChannel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\AiCore\Events\AgentActionLifecycleChanged;
use Modules\AiCore\Events\AiInteractionRecorded;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Audit\Contracts\AuditContext;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Channels\EmailDunningChannel;
use Modules\Clinical\Contracts\VisitVitalsReader;
use Modules\Clinical\Events\ClinicalNoteAmended;
use Modules\Clinical\Events\ClinicalNoteSigned;
use Modules\Clinical\Events\ClinicalRecordChanged;
use Modules\Clinical\Events\DocumentChanged;
use Modules\Clinical\Events\EncounterClosed;
use Modules\Clinical\Events\EncounterOpened;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Comms\Contracts\InboxDraftProvider;
use Modules\Comms\Models\NotificationTemplate;
use Modules\Comms\Services\NotificationService;
use Modules\ED\Models\EdTriage;
use Modules\ED\Models\EdVisit;
use Modules\ED\Models\EdVisitEvent;
use Modules\Hospital\Events\BedStatusChanged;
use Modules\Hospital\Events\StayTransitioned;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\DischargeSummary;
use Modules\Hospital\Models\Handover;
use Modules\Hospital\Models\Ward;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Models\LabResult;
use Modules\Lab\Models\LabTest;
use Modules\Lab\Models\Specimen;
use Modules\Lab\Models\SpecimenEvent;
use Modules\Nursing\Events\CompetencyChanged;
use Modules\Nursing\Events\IncidentReported;
use Modules\Nursing\Events\NurseCompetencyChanged;
use Modules\Nursing\Events\NurseSyncActionProcessed;
use Modules\Nursing\Events\PlannedVisitChanged;
use Modules\Nursing\Events\ServiceAgreementChanged;
use Modules\Nursing\Events\VisitEventRecorded;
use Modules\Patients\Models\Patient;
use Modules\People\Models\Credential;
use Modules\Pharmacy\Models\Dispense;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Pharmacy\Models\MedicationAdministration;
use Modules\Pharmacy\Models\MedicationOrderEvent;
use Modules\Pharmacy\Models\StockMovement;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\BranchHours;
use Modules\Platform\Models\FeatureFlag;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Setting;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Radiology\Models\ImagingStudy;
use Modules\Radiology\Models\ImagingStudyEvent;
use Modules\Radiology\Models\RadiologyExam;
use Modules\Radiology\Models\RadiologyOrder;
use Modules\Scheduling\Channels\EmailAppointmentReminderChannel;
use Modules\Scheduling\Events\AppointmentBooked;
use Modules\Scheduling\Events\AppointmentReminderDeliveryRecorded;
use Modules\Scheduling\Events\AppointmentSeriesLifecycleChanged;
use Modules\Scheduling\Events\AppointmentTransitioned;
use Modules\Scheduling\Events\WaitlistEntryStatusChanged;
use Modules\Scheduling\Events\WaitlistOfferLifecycleChanged;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\WaitlistOffer;
use Modules\Surgery\Models\CaseItemUsage;
use Modules\Surgery\Models\ImplantPlacement;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\SurgicalCaseEvent;
use Modules\Surgery\Models\SurgicalChecklist;
use Modules\Surgery\Models\SurgicalChecklistItem;
use Modules\Surgery\Models\SurgicalItem;
use Modules\Surgery\Models\SurgicalStockMovement;
use Modules\Surgery\Models\Theatre;
use Modules\Surgery\Models\TheatreSlot;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Wire the audit context to the Platform-aware implementation. This
        // binding lives in the app layer so neither module depends on the other.
        $this->app->bind(AuditContext::class, PlatformAuditContext::class);

        // G.2 notification engine bridges (D-017): Scheduling reminders and
        // Billing dunning deliver through Comms' NotificationService without
        // either module depending on Comms.
        $this->app->bind(EmailAppointmentReminderChannel::class, EngineAppointmentReminderChannel::class);
        $this->app->bind(EmailDunningChannel::class, EngineDunningChannel::class);

        // G.6 Inbox agent draft lookup (D-017): Comms reads pending AI drafts
        // through a contract implemented here, never depending on AiCore.
        $this->app->bind(InboxDraftProvider::class, AgentInboxDraftProvider::class);

        // P0P.G13 unified vitals history: Clinical reads Nursing-captured visit
        // vitals through this seam so the two stores merge into one series, without
        // Clinical depending on Nursing.
        $this->app->bind(VisitVitalsReader::class, NursingVisitVitalsReader::class);

        $this->app->afterResolving(ToolRegistry::class, function (ToolRegistry $registry): void {
            $registry->register($this->app->make(FillFromWaitlistTool::class));
            $registry->register($this->app->make(SuggestSlotsTool::class));
            $registry->register($this->app->make(ClinicalSummaryTool::class));
            $registry->register($this->app->make(DraftRecallMessageTool::class));
            $registry->register($this->app->make(NursingProposeAssignmentsTool::class));
            $registry->register($this->app->make(NursingReplanDayTool::class));
            $registry->register($this->app->make(SuggestChargeCodesTool::class));
            $registry->register($this->app->make(PreflightInvoiceTool::class));
            $registry->register($this->app->make(DraftReplyTool::class));
            $registry->register($this->app->make(ClassifyDocumentTool::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Auth events (Fortify fires the framework events).
        Event::subscribe(AuthAuditSubscriber::class);

        // RBAC changes.
        RoleAssignment::created(fn (RoleAssignment $m) => $this->auditChange('role.assigned', [
            'resource_type' => 'role_user',
            'resource_id' => $m->id,
            'context' => ['role_id' => $m->role_id, 'user_id' => $m->user_id, 'branch_id' => $m->branch_id],
        ]));
        RoleAssignment::deleted(fn (RoleAssignment $m) => $this->auditChange('role.revoked', [
            'resource_type' => 'role_user',
            'resource_id' => $m->id,
            'context' => ['role_id' => $m->role_id, 'user_id' => $m->user_id, 'branch_id' => $m->branch_id],
        ]));
        Role::created(fn (Role $m) => $this->auditChange('role.changed', [
            'resource_type' => 'role',
            'resource_id' => $m->id,
            'context' => ['key' => $m->key, 'op' => 'created'],
        ]));
        Role::updated(fn (Role $m) => $this->auditChange('role.changed', [
            'resource_type' => 'role',
            'resource_id' => $m->id,
            'context' => ['key' => $m->key, 'op' => 'updated'],
        ]));

        // Feature-flag & settings changes.
        FeatureFlag::saved(fn (FeatureFlag $m) => $this->auditChange('feature_flag.changed', [
            'resource_type' => 'feature_flag',
            'resource_id' => $m->id,
            'context' => ['key' => $m->key, 'enabled' => $m->enabled],
        ]));
        Setting::saved(fn (Setting $m) => $this->auditChange('setting.changed', [
            'resource_type' => 'setting',
            'resource_id' => $m->id,
            'context' => ['key' => $m->key],
        ]));

        // Practice profile + branch config changes (CLINIC.W8b). Kept in the app layer so
        // Platform stays free of Audit; auditChange skips seeding (system mode).
        Tenant::updated(function (Tenant $m): void {
            $profileFields = ['name', 'contact_email', 'contact_phone', 'address_line1', 'address_line2', 'city', 'postal_code', 'country'];
            $changed = array_values(array_intersect($profileFields, array_keys($m->getChanges())));
            if ($changed !== []) {
                $this->auditChange('tenant.profile_updated', [
                    'resource_type' => 'tenant',
                    'resource_id' => $m->id,
                    'context' => ['fields' => $changed],
                ]);
            }
        });
        Branch::created(fn (Branch $m) => $this->auditChange('branch.created', [
            'resource_type' => 'branch',
            'resource_id' => $m->id,
            'context' => ['name' => $m->name, 'code' => $m->code],
        ]));
        Branch::updated(function (Branch $m): void {
            $action = $m->wasChanged('active')
                ? ($m->active ? 'branch.activated' : 'branch.deactivated')
                : 'branch.updated';
            $this->auditChange($action, [
                'resource_type' => 'branch',
                'resource_id' => $m->id,
                'context' => ['fields' => array_keys($m->getChanges())],
            ]);
        });
        $auditHours = fn (BranchHours $m) => $this->auditChange('branch.hours_changed', [
            'resource_type' => 'branch_hours',
            'resource_id' => $m->id,
            'context' => ['branch_id' => $m->branch_id, 'weekday' => $m->weekday, 'is_closed' => $m->is_closed],
        ]);
        BranchHours::created($auditHours);
        BranchHours::updated($auditHours);

        // Bookable-resource CRUD (CLINIC.W8c). Resource is a Scheduling model; the hooks
        // live here so Scheduling stays free of Audit. activated/deactivated is derived
        // from the `active` change so the deactivation guard leaves an audit trail.
        Resource::created(fn (Resource $m) => $this->auditChange('resource.created', [
            'resource_type' => 'resource',
            'resource_id' => $m->id,
            'context' => ['name' => $m->name, 'type' => $m->type, 'branch_id' => $m->branch_id],
        ]));
        Resource::updated(function (Resource $m): void {
            $action = $m->wasChanged('active')
                ? ($m->active ? 'resource.activated' : 'resource.deactivated')
                : 'resource.updated';
            $this->auditChange($action, [
                'resource_type' => 'resource',
                'resource_id' => $m->id,
                'context' => ['fields' => array_keys($m->getChanges())],
            ]);
        });

        // Inpatient ward/bed CRUD + status (HOSPITAL.G1). Hooks + the BedStatusChanged
        // listener live here so Hospital stays free of Audit (the Branch/Resource +
        // AppointmentTransitioned pattern). Bed status transitions are audited via the
        // domain event (it carries actor/from/to/reason); Bed::updated deliberately
        // skips status changes so a transition is not double-audited.
        Ward::created(fn (Ward $m) => $this->auditChange('ward.created', [
            'resource_type' => 'ward',
            'resource_id' => $m->id,
            'context' => ['name' => $m->name, 'code' => $m->code, 'branch_id' => $m->branch_id],
        ]));
        Ward::updated(function (Ward $m): void {
            $action = $m->wasChanged('active')
                ? ($m->active ? 'ward.activated' : 'ward.deactivated')
                : 'ward.updated';
            $this->auditChange($action, [
                'resource_type' => 'ward',
                'resource_id' => $m->id,
                'context' => ['fields' => array_keys($m->getChanges())],
            ]);
        });
        Bed::created(fn (Bed $m) => $this->auditChange('bed.created', [
            'resource_type' => 'bed',
            'resource_id' => $m->id,
            'context' => ['label' => $m->label, 'bed_type' => $m->bed_type, 'ward_id' => $m->ward_id, 'branch_id' => $m->branch_id],
        ]));
        Bed::updated(function (Bed $m): void {
            if ($m->wasChanged('status')) {
                return; // status transitions are audited via BedStatusChanged below
            }
            $action = $m->wasChanged('active')
                ? ($m->active ? 'bed.activated' : 'bed.deactivated')
                : 'bed.updated';
            $this->auditChange($action, [
                'resource_type' => 'bed',
                'resource_id' => $m->id,
                'context' => ['fields' => array_keys($m->getChanges())],
            ]);
        });
        Event::listen(BedStatusChanged::class, function (BedStatusChanged $event): void {
            $this->auditChange('bed.status_changed', [
                'actor_type' => 'user',
                'actor_id' => (string) $event->actor->getKey(),
                'resource_type' => 'bed',
                'resource_id' => $event->bed->id,
                'reason' => $event->reason,
                'context' => [
                    'from_status' => $event->fromStatus,
                    'to_status' => $event->toStatus,
                    'ward_id' => $event->bed->ward_id,
                    'branch_id' => $event->bed->branch_id,
                ],
            ]);
        });
        // Inpatient ADT transitions (HOSPITAL.G2) — one append-only audit row per admit/transfer/
        // discharge, keyed by the event type (a transfer keeps status = admitted). Same pattern as
        // AppointmentTransitioned, so Hospital stays free of Audit.
        Event::listen(StayTransitioned::class, function (StayTransitioned $event): void {
            $this->auditChange('admission.'.$event->eventType, [
                'actor_type' => 'user',
                'actor_id' => (string) $event->actor->getKey(),
                'patient_id' => $event->stay->patient_id,
                'resource_type' => 'stay',
                'resource_id' => $event->stay->id,
                'reason' => $event->reason,
                'context' => [
                    'from_status' => $event->fromStatus,
                    'to_status' => $event->toStatus,
                    ...$event->context,
                ],
            ]);
        });
        // Nursing shift handover (HOSPITAL.G5) — the append-only handover write is audited here so
        // Hospital stays free of Audit. Patient-scoped; the SBAR content is the nurse's own words.
        Handover::created(fn (Handover $m) => $this->auditChange('handover.recorded', [
            'patient_id' => $m->patient_id,
            'resource_type' => 'handover',
            'resource_id' => $m->id,
            'context' => ['stay_id' => $m->stay_id, 'shift' => $m->shift],
        ]));

        // Discharge summary (HOSPITAL.G7) — the sign-and-lock write is audited here so Hospital stays free
        // of Audit. Patient-scoped; the narrative is the discharging clinician's own words. A draft create
        // is `drafted`; the finalize (a status change to finalized) is `finalized`; a draft edit is `drafted`.
        DischargeSummary::created(fn (DischargeSummary $m) => $this->auditChange('discharge_summary.drafted', [
            'patient_id' => $m->patient_id,
            'resource_type' => 'discharge_summary',
            'resource_id' => $m->id,
            'context' => ['stay_id' => $m->stay_id, 'status' => $m->status],
        ]));
        DischargeSummary::updated(fn (DischargeSummary $m) => $this->auditChange(
            $m->status === DischargeSummary::STATUS_FINALIZED ? 'discharge_summary.finalized' : 'discharge_summary.drafted',
            [
                'patient_id' => $m->patient_id,
                'resource_type' => 'discharge_summary',
                'resource_id' => $m->id,
                'context' => ['stay_id' => $m->stay_id, 'status' => $m->status],
            ],
        ));

        // Pharmacy formulary (PHARMACY.G1) — the tenant-authored catalog write is audited here so Pharmacy
        // stays free of Audit. A tenant-config change (not patient-scoped), like a tariff/procedure edit.
        FormularyItem::created(fn (FormularyItem $m) => $this->auditChange('formulary.item.created', [
            'resource_type' => 'formulary_item',
            'resource_id' => $m->id,
            'context' => ['code' => $m->code, 'name' => $m->name, 'active' => $m->active],
        ]));
        FormularyItem::updated(fn (FormularyItem $m) => $this->auditChange('formulary.item.updated', [
            'resource_type' => 'formulary_item',
            'resource_id' => $m->id,
            'context' => ['code' => $m->code, 'name' => $m->name, 'active' => $m->active],
        ]));

        // Medication orders (PHARMACY.G2) — the append-only lifecycle event is audited here (patient-scoped)
        // so Pharmacy stays free of Audit. One row per placed/held/resumed/discontinued/completed.
        MedicationOrderEvent::created(fn (MedicationOrderEvent $m) => $this->auditChange('medication_order.'.$m->event_type, [
            'patient_id' => $m->patient_id,
            'resource_type' => 'medication_order_event',
            'resource_id' => $m->id,
            'context' => ['medication_order_id' => $m->medication_order_id, 'event_type' => $m->event_type, 'reason' => $m->reason],
        ]));

        // eMAR administrations (PHARMACY.G3) — the append-only administration is audited here (patient-scoped)
        // so Pharmacy stays free of Audit. The outcome (given/held/refused) is the nurse's recorded fact.
        MedicationAdministration::created(fn (MedicationAdministration $m) => $this->auditChange('medication.administered', [
            'patient_id' => $m->patient_id,
            'resource_type' => 'medication_administration',
            'resource_id' => $m->id,
            'context' => ['medication_order_id' => $m->medication_order_id, 'outcome' => $m->outcome, 'reason' => $m->reason],
        ]));

        // Dispensing + inventory (PHARMACY.G4) — audited here so Pharmacy stays free of Audit. A dispense is
        // patient-scoped; a stock movement (received/dispensed/adjusted) is tenant-level inventory.
        Dispense::created(fn (Dispense $m) => $this->auditChange('medication.dispensed', [
            'patient_id' => $m->patient_id,
            'resource_type' => 'medication_dispense',
            'resource_id' => $m->id,
            'context' => ['medication_order_id' => $m->medication_order_id, 'quantity' => $m->quantity],
        ]));
        StockMovement::created(fn (StockMovement $m) => $this->auditChange('stock.'.$m->type, [
            'resource_type' => 'stock_movement',
            'resource_id' => $m->id,
            'context' => ['medication_stock_id' => $m->medication_stock_id, 'quantity_change' => $m->quantity_change, 'resulting_on_hand' => $m->resulting_on_hand, 'reason' => $m->reason],
        ]));

        // Operating theatre / surgery (SURGERY.G1) — audited here so Surgery stays free of Audit. A theatre +
        // a booked theatre-slot are tenant-level operational records; a surgical case is patient-scoped.
        Theatre::created(fn (Theatre $m) => $this->auditChange('theatre.created', [
            'resource_type' => 'theatre',
            'resource_id' => $m->id,
            'context' => ['branch_id' => $m->branch_id, 'name' => $m->name, 'type' => $m->type, 'active' => $m->active],
        ]));
        TheatreSlot::created(fn (TheatreSlot $m) => $this->auditChange('theatre_slot.booked', [
            'resource_type' => 'theatre_slot',
            'resource_id' => $m->id,
            'context' => ['theatre_id' => $m->theatre_id, 'surgical_case_id' => $m->surgical_case_id, 'status' => $m->status],
        ]));
        SurgicalCase::created(fn (SurgicalCase $m) => $this->auditChange('surgical_case.scheduled', [
            'patient_id' => $m->patient_id,
            'resource_type' => 'surgical_case',
            'resource_id' => $m->id,
            'context' => ['primary_surgeon_id' => $m->primary_surgeon_id, 'stay_id' => $m->stay_id, 'status' => $m->status],
        ]));
        // The append-only case lifecycle event (SURGERY.G2) — patient-scoped — so Surgery stays free of Audit.
        // One row per pre_op / in_progress / completed / post_op / cancelled transition. (Op-note reuse is
        // audited through Clinical's existing Encounter/ClinicalNote listeners, the WardRound posture.)
        SurgicalCaseEvent::created(fn (SurgicalCaseEvent $m) => $this->auditChange('surgical_case.'.$m->event_type, [
            'patient_id' => $m->patient_id,
            'resource_type' => 'surgical_case_event',
            'resource_id' => $m->id,
            'context' => ['surgical_case_id' => $m->surgical_case_id, 'event_type' => $m->event_type, 'reason' => $m->reason],
        ]));
        // The WHO Surgical Safety Checklist (SURGERY.G3) — patient-scoped — so Surgery stays free of Audit. The
        // checklist is a RECORD: opening the container + each APPEND-ONLY item confirmation is audited; it
        // never gates the case (no safety verdict is computed or stored).
        SurgicalChecklist::created(fn (SurgicalChecklist $m) => $this->auditChange('surgical_checklist.opened', [
            'patient_id' => $m->patient_id,
            'resource_type' => 'surgical_checklist',
            'resource_id' => $m->id,
            'context' => ['surgical_case_id' => $m->surgical_case_id],
        ]));
        SurgicalChecklistItem::created(fn (SurgicalChecklistItem $m) => $this->auditChange('surgical_checklist.item_confirmed', [
            'patient_id' => $m->patient_id,
            'resource_type' => 'surgical_checklist_item',
            'resource_id' => $m->id,
            'context' => ['surgical_checklist_id' => $m->surgical_checklist_id, 'phase' => $m->phase, 'checked' => $m->checked],
        ]));
        // Surgical consumables + implants (SURGERY.G4) — audited here so Surgery stays free of Audit. The item
        // catalog + stock movement are tenant-level inventory; a case usage + an implant placement are
        // patient-scoped. Implant traceability is a RECORD (lot/serial/UDI → patient), never a device verdict.
        SurgicalItem::created(fn (SurgicalItem $m) => $this->auditChange('surgical_item.created', [
            'resource_type' => 'surgical_item',
            'resource_id' => $m->id,
            'context' => ['code' => $m->code, 'name' => $m->name, 'is_implant' => $m->is_implant],
        ]));
        SurgicalStockMovement::created(fn (SurgicalStockMovement $m) => $this->auditChange('surgical_stock.'.$m->type, [
            'resource_type' => 'surgical_stock_movement',
            'resource_id' => $m->id,
            'context' => ['surgical_item_stock_id' => $m->surgical_item_stock_id, 'quantity_change' => $m->quantity_change, 'resulting_on_hand' => $m->resulting_on_hand],
        ]));
        CaseItemUsage::created(fn (CaseItemUsage $m) => $this->auditChange('surgical_item.used', [
            'patient_id' => $m->patient_id,
            'resource_type' => 'case_item_usage',
            'resource_id' => $m->id,
            'context' => ['surgical_case_id' => $m->surgical_case_id, 'surgical_item_id' => $m->surgical_item_id, 'quantity' => $m->quantity],
        ]));
        ImplantPlacement::created(fn (ImplantPlacement $m) => $this->auditChange('implant.placed', [
            'patient_id' => $m->patient_id,
            'resource_type' => 'implant_placement',
            'resource_id' => $m->id,
            'context' => ['surgical_case_id' => $m->surgical_case_id, 'surgical_item_id' => $m->surgical_item_id, 'lot_number' => $m->lot_number, 'udi' => $m->udi],
        ]));

        // Emergency Department (ED.G1 — Phase 6) — patient-scoped — so ED stays free of Audit. An ED visit is a
        // patient-FLOW record; the arrival + every legal-only flow transition is an append-only EdVisitEvent.
        // ELECTRIC FENCE: `arrival_mode` / `disposition` are operational facts, never a computed acuity/triage.
        EdVisit::created(fn (EdVisit $m) => $this->auditChange('ed_visit.registered', [
            'patient_id' => $m->patient_id,
            'resource_type' => 'ed_visit',
            'resource_id' => $m->id,
            'context' => ['branch_id' => $m->branch_id, 'arrival_mode' => $m->arrival_mode, 'status' => $m->status],
        ]));
        EdVisitEvent::created(fn (EdVisitEvent $m) => $this->auditChange('ed_visit.'.$m->event_type, [
            'patient_id' => $m->patient_id,
            'resource_type' => 'ed_visit_event',
            'resource_id' => $m->id,
            'context' => ['ed_visit_id' => $m->ed_visit_id, 'event_type' => $m->event_type, 'reason' => $m->reason],
        ]));
        // ED triage (ED.G2) — patient-scoped. The acuity is the nurse's ASSIGNED value (a recorded fact, the
        // ASA precedent); the audit context carries the assigned level + its provenance (scale + nurse), NEVER
        // a computed/suggested acuity. Raw vitals at triage are audited by Clinical's own `vital.recorded`.
        EdTriage::created(fn (EdTriage $m) => $this->auditChange('ed_triage.recorded', [
            'patient_id' => $m->patient_id,
            'resource_type' => 'ed_triage',
            'resource_id' => $m->id,
            'context' => ['ed_visit_id' => $m->ed_visit_id, 'acuity_scale' => $m->acuity_scale, 'acuity_level' => $m->acuity_level, 'triaged_by' => $m->triaged_by],
        ]));

        // Laboratory / LIS (LAB.G1 — Phase 3) — tenant-level (a catalog item, not patient-scoped) — so Lab
        // stays free of Audit. Authoring/updating a lab test (the tenant's test menu + reference ranges) is
        // audited. A lab test is an OrderableItem overlay; the ORDER/RESULT reuse Clinical (audited there).
        LabTest::created(fn (LabTest $m) => $this->auditChange('lab.test_authored', [
            'resource_type' => 'lab_test',
            'resource_id' => $m->id,
            'context' => ['orderable_item_id' => $m->orderable_item_id, 'unit' => $m->unit, 'reference_range' => $m->reference_range],
        ]));
        // A lab order (LAB.G2) — patient-scoped. The order itself is a REUSED Clinical `Order` (audited by
        // Clinical's `order.placed`); this records the thin lab overlay (specimen + priority). The priority is
        // the clinician's RECORDED flag, never computed.
        LabOrder::created(fn (LabOrder $m) => $this->auditChange('lab.order_placed', [
            'patient_id' => $m->patient_id,
            'resource_type' => 'lab_order',
            'resource_id' => $m->id,
            'context' => ['order_id' => $m->order_id, 'specimen_type' => $m->specimen_type, 'priority' => $m->priority],
        ]));
        // Specimen tracking (LAB.G3) — patient-scoped. The specimen is accessioned + advanced through its
        // legal-only state machine; each state is an append-only SpecimenEvent. Operational facts — accession +
        // state, never a computed priority/urgency.
        Specimen::created(fn (Specimen $m) => $this->auditChange('specimen.accessioned', [
            'patient_id' => $m->patient_id,
            'resource_type' => 'specimen',
            'resource_id' => $m->id,
            'context' => ['lab_order_id' => $m->lab_order_id, 'accession_number' => $m->accession_number, 'specimen_type' => $m->specimen_type],
        ]));
        SpecimenEvent::created(fn (SpecimenEvent $m) => $this->auditChange('specimen.'.$m->event_type, [
            'patient_id' => $m->patient_id,
            'resource_type' => 'specimen_event',
            'resource_id' => $m->id,
            'context' => ['specimen_id' => $m->specimen_id, 'event_type' => $m->event_type, 'reason' => $m->reason],
        ]));
        // Manual result entry (LAB.G4) — patient-scoped. The result itself is a REUSED Clinical `OrderResult`
        // (audited by Clinical's `order.resulted`, raw); this records the thin lab overlay linking the result to
        // the specimen that produced it. NO computed abnormal/critical flag — the value is raw, the range is
        // displayed reference data (the fence).
        LabResult::created(fn (LabResult $m) => $this->auditChange('lab.result_recorded', [
            'patient_id' => $m->patient_id,
            'resource_type' => 'lab_result',
            'resource_id' => $m->id,
            'context' => ['order_result_id' => $m->order_result_id, 'specimen_id' => $m->specimen_id],
        ]));
        // Radiology / RIS (RAD.G1 — Phase 4) — tenant-level (a catalog item, not patient-scoped) — so Radiology
        // stays free of Audit. Authoring an imaging exam (the tenant's exam menu) is audited. An exam is an
        // OrderableItem overlay; the order/report/image REUSE Clinical (audited there). NO computed image read.
        RadiologyExam::created(fn (RadiologyExam $m) => $this->auditChange('radiology.exam_authored', [
            'resource_type' => 'radiology_exam',
            'resource_id' => $m->id,
            'context' => ['orderable_item_id' => $m->orderable_item_id, 'body_part' => $m->body_part, 'contrast' => $m->contrast],
        ]));
        // An imaging order (RAD.G2) — patient-scoped. The order itself is a REUSED Clinical `Order` (audited by
        // Clinical's `order.placed`); this records the thin imaging overlay (modality/body-part + priority). The
        // priority is the clinician's RECORDED flag, never computed.
        RadiologyOrder::created(fn (RadiologyOrder $m) => $this->auditChange('radiology.order_placed', [
            'patient_id' => $m->patient_id,
            'resource_type' => 'radiology_order',
            'resource_id' => $m->id,
            'context' => ['order_id' => $m->order_id, 'modality' => $m->modality, 'body_part' => $m->body_part, 'priority' => $m->priority],
        ]));
        // The imaging study (RAD.G3) — patient-scoped. The study is accessioned + advanced through its
        // legal-only state machine; each state is an append-only ImagingStudyEvent. Operational facts —
        // accession + state, never a computed image finding/CAD or priority. The image is the PACS partner's
        // (RAD.G6); this records metadata only.
        ImagingStudy::created(fn (ImagingStudy $m) => $this->auditChange('radiology.study_accessioned', [
            'patient_id' => $m->patient_id,
            'resource_type' => 'imaging_study',
            'resource_id' => $m->id,
            'context' => ['radiology_order_id' => $m->radiology_order_id, 'accession_number' => $m->accession_number, 'modality' => $m->modality],
        ]));
        ImagingStudyEvent::created(fn (ImagingStudyEvent $m) => $this->auditChange('radiology.study.'.$m->event_type, [
            'patient_id' => $m->patient_id,
            'resource_type' => 'imaging_study_event',
            'resource_id' => $m->id,
            'context' => ['imaging_study_id' => $m->imaging_study_id, 'event_type' => $m->event_type, 'reason' => $m->reason],
        ]));

        // People credential vault changes. The observer lives here so People
        // stays independent from Audit while still using the Platform audit context.
        Credential::created(fn (Credential $m) => $this->auditCredentialChange($m, 'credential.created'));
        Credential::updated(function (Credential $m): void {
            $action = $m->wasChanged('status') && $m->status === Credential::STATUS_REVOKED
                ? 'credential.revoked'
                : 'credential.updated';

            $this->auditCredentialChange($m, $action);
        });

        // Scheduling booking changes. Kept in the app layer so Scheduling does
        // not depend on Audit models or services.
        Event::listen(AppointmentBooked::class, function (AppointmentBooked $event): void {
            $appointment = $event->appointment;

            $this->auditChange('appointment.booked', [
                'actor_type' => $appointment->booked_by !== null ? 'user' : 'system',
                'actor_id' => $appointment->booked_by,
                'patient_id' => $appointment->patient_id,
                'resource_type' => 'appointment',
                'resource_id' => $appointment->id,
                'context' => [
                    'service_id' => $appointment->service_id,
                    'branch_id' => $appointment->branch_id,
                    'starts_at' => $appointment->starts_at->toDateTimeString(),
                    'ends_at' => $appointment->ends_at->toDateTimeString(),
                    'resource_ids' => $event->resourceIds,
                    'source' => $appointment->source,
                ],
            ]);
        });
        Event::listen(AppointmentTransitioned::class, function (AppointmentTransitioned $event): void {
            $appointment = $event->appointment;

            $this->auditChange('appointment.'.$event->toStatus, [
                'actor_type' => $event->actor instanceof User ? 'user' : 'patient',
                'actor_id' => (string) $event->actor->getKey(),
                'patient_id' => $appointment->patient_id,
                'resource_type' => 'appointment',
                'resource_id' => $appointment->id,
                'reason' => $event->reason,
                'context' => [
                    'from_status' => $event->fromStatus,
                    'to_status' => $event->toStatus,
                    ...$event->context,
                ],
            ]);
        });
        Event::listen(WaitlistEntryStatusChanged::class, function (WaitlistEntryStatusChanged $event): void {
            $entry = $event->entry;

            $this->auditChange('waitlist.'.$event->toStatus, [
                'actor_type' => 'user',
                'actor_id' => (string) $event->actor->getKey(),
                'patient_id' => $entry->patient_id,
                'resource_type' => 'waitlist_entry',
                'resource_id' => $entry->id,
                'context' => [
                    'from_status' => $event->fromStatus,
                    'to_status' => $event->toStatus,
                    'service_id' => $entry->service_id,
                    'branch_id' => $entry->branch_id,
                    ...$event->context,
                ],
            ]);
        });
        // Recurring appointment series (P0P.G8). Audit create/end in the app layer
        // so Scheduling never depends on Audit models; each individual occurrence
        // booking is already audited via AppointmentBooked.
        Event::listen(AppointmentSeriesLifecycleChanged::class, function (AppointmentSeriesLifecycleChanged $event): void {
            $series = $event->series;

            $this->auditChange('appointment_series.'.$event->status, [
                'actor_type' => $event->actor !== null ? 'user' : 'system',
                'actor_id' => $event->actor !== null ? (string) $event->actor->getKey() : null,
                'patient_id' => $series->patient_id,
                'resource_type' => 'appointment_series',
                'resource_id' => $series->id,
                'context' => [
                    'status' => $event->status,
                    'service_id' => $series->service_id,
                    'branch_id' => $series->branch_id,
                    'rrule' => $series->rrule,
                    'timezone' => $series->timezone,
                    ...$event->context,
                ],
            ]);
        });
        // Waitlist auto-fill offers (P0P.G9). Audit every lifecycle change here,
        // and — only on creation — deliver the offer to the patient through the
        // Comms NotificationService. This composition lives in the app layer so
        // Scheduling never depends on Comms.
        Event::listen(WaitlistOfferLifecycleChanged::class, function (WaitlistOfferLifecycleChanged $event): void {
            $offer = $event->offer;

            $this->auditChange('waitlist_offer.'.$event->toStatus, [
                'actor_type' => $event->actor !== null ? 'user' : 'system',
                'actor_id' => $event->actor !== null ? (string) $event->actor->getKey() : null,
                'patient_id' => $offer->patient_id,
                'resource_type' => 'waitlist_offer',
                'resource_id' => $offer->id,
                'context' => [
                    'from_status' => $event->fromStatus,
                    'to_status' => $event->toStatus,
                    'waitlist_entry_id' => $offer->waitlist_entry_id,
                    'service_id' => $offer->service_id,
                    'branch_id' => $offer->branch_id,
                    'slot_starts_at' => $offer->slot_starts_at->toDateTimeString(),
                    ...$event->context,
                ],
            ]);

            if ($event->toStatus !== WaitlistOffer::STATUS_OFFERED) {
                return;
            }

            $patient = Patient::query()->find($offer->patient_id);
            if ($patient === null) {
                return;
            }

            // Consent-gated (D-G4): the transactional template skips fail-closed
            // without comms.email consent.
            $this->app->make(NotificationService::class)->send(
                'waitlist.offer',
                $patient,
                [
                    'starts_at' => $offer->slot_starts_at->toDateTimeString(),
                    'expires_at' => $offer->expires_at->toDateTimeString(),
                ],
                NotificationTemplate::CATEGORY_TRANSACTIONAL,
            );
        });
        Event::listen(AppointmentReminderDeliveryRecorded::class, function (AppointmentReminderDeliveryRecorded $event): void {
            $reminder = $event->reminder;
            $appointment = Appointment::query()->find($reminder->appointment_id);

            $this->auditChange('appointment_reminder.'.$reminder->status, [
                'patient_id' => $appointment?->patient_id,
                'resource_type' => 'appointment_reminder',
                'resource_id' => $reminder->id,
                'context' => [
                    'appointment_id' => $reminder->appointment_id,
                    'type' => $reminder->type,
                    'channel' => $reminder->channel,
                    'scheduled_for' => $reminder->scheduled_for->toDateTimeString(),
                    'sent_at' => $reminder->sent_at?->toDateTimeString(),
                    'failure_reason' => $reminder->failure_reason,
                ],
            ]);
        });

        // Clinical encounter changes. Kept in the app layer so Clinical does
        // not depend on Audit models or services.
        Event::listen(EncounterOpened::class, function (EncounterOpened $event): void {
            $this->auditEncounterChange($event->encounter, $event->actor, 'encounter.opened');
        });
        Event::listen(EncounterClosed::class, function (EncounterClosed $event): void {
            $this->auditEncounterChange($event->encounter, $event->actor, 'encounter.closed');
        });
        Event::listen(ClinicalNoteSigned::class, function (ClinicalNoteSigned $event): void {
            $this->auditClinicalNoteChange($event->note, $event->actor, 'note.signed');
        });
        Event::listen(ClinicalNoteAmended::class, function (ClinicalNoteAmended $event): void {
            $this->auditClinicalNoteChange($event->amendment, $event->actor, 'note.amended', [
                'supersedes_id' => $event->original->id,
                'amendment_id' => $event->amendment->id,
            ], $event->reason);
        });
        Event::listen(ClinicalRecordChanged::class, function (ClinicalRecordChanged $event): void {
            $this->auditChange($event->action, [
                'actor_type' => 'user',
                'actor_id' => (string) $event->actor->getKey(),
                'patient_id' => $event->patientId,
                'resource_type' => $event->resourceType,
                'resource_id' => $event->resourceId,
                'reason' => $event->reason,
                'context' => $event->context,
            ]);
        });
        Event::listen(DocumentChanged::class, function (DocumentChanged $event): void {
            $document = $event->document;

            $this->auditChange($event->action, [
                'actor_type' => 'user',
                'actor_id' => (string) $event->actor->getKey(),
                'patient_id' => $document->patient_id,
                'resource_type' => 'document',
                'resource_id' => $document->id,
                'context' => [
                    'category' => $document->category,
                    'title' => $document->title,
                    'original_filename' => $document->original_filename,
                    'shared_with_patient' => $document->shared_with_patient,
                    ...$event->context,
                ],
            ]);
        });
        Event::listen(ServiceAgreementChanged::class, function (ServiceAgreementChanged $event): void {
            $agreement = $event->agreement;

            $this->auditChange($event->action, [
                'actor_type' => 'user',
                'actor_id' => (string) $event->actor->getKey(),
                'patient_id' => $agreement->patient_id,
                'resource_type' => 'service_agreement',
                'resource_id' => $agreement->id,
                'context' => [
                    'branch_id' => $agreement->branch_id,
                    'funding_type' => $agreement->funding_type,
                    'status' => $agreement->status,
                    ...$event->context,
                ],
            ]);
        });
        Event::listen(PlannedVisitChanged::class, function (PlannedVisitChanged $event): void {
            $visit = $event->visit;

            $this->auditChange($event->action, [
                'actor_type' => $event->actor !== null ? 'user' : 'system',
                'actor_id' => $event->actor !== null ? (string) $event->actor->getKey() : null,
                'patient_id' => $visit->patient_id,
                'resource_type' => 'planned_visit',
                'resource_id' => $visit->id,
                'reason' => $event->reason,
                'context' => [
                    'visit_plan_id' => $visit->visit_plan_id,
                    'scheduled_date' => $visit->scheduled_date->toDateString(),
                    'status' => $visit->status,
                    'assigned_resource_id' => $visit->assigned_resource_id,
                    ...$event->context,
                ],
            ]);
        });
        Event::listen(CompetencyChanged::class, function (CompetencyChanged $event): void {
            $competency = $event->competency;

            // Competency definitions/enforcement are agency dispatch policy, not
            // patient data, so these rows carry no patient_id.
            $this->auditChange($event->action, [
                'actor_type' => $event->actor !== null ? 'user' : 'system',
                'actor_id' => $event->actor !== null ? (string) $event->actor->getKey() : null,
                'resource_type' => 'competency',
                'resource_id' => $competency->id,
                'context' => [
                    'code' => $competency->code,
                    'enforcement' => $competency->enforcement,
                    'active' => $competency->active,
                    ...$event->context,
                ],
            ]);
        });
        Event::listen(NurseCompetencyChanged::class, function (NurseCompetencyChanged $event): void {
            $grant = $event->grant;

            $this->auditChange($event->action, [
                'actor_type' => $event->actor !== null ? 'user' : 'system',
                'actor_id' => $event->actor !== null ? (string) $event->actor->getKey() : null,
                'resource_type' => 'nurse_competency',
                'resource_id' => $grant->id,
                'context' => [
                    'resource_id' => $grant->resource_id,
                    'competency_id' => $grant->competency_id,
                    'active' => $grant->active,
                    ...$event->context,
                ],
            ]);
        });
        Event::listen(VisitEventRecorded::class, function (VisitEventRecorded $event): void {
            $visitEvent = $event->event;
            $visit = $event->visit;

            $this->auditChange('visit.'.$visitEvent->type, [
                'actor_type' => 'user',
                'actor_id' => (string) $event->actor->getKey(),
                'patient_id' => $visit->patient_id,
                'resource_type' => 'visit',
                'resource_id' => $visit->id,
                'context' => [
                    'visit_event_id' => $visitEvent->id,
                    'planned_visit_id' => $visit->planned_visit_id,
                    'resource_id' => $visit->resource_id,
                    'branch_id' => $visit->branch_id,
                    'status' => $visit->status,
                    'location_source' => $visitEvent->location_source,
                    'distance_meters' => $visitEvent->distance_meters,
                    ...$event->context,
                ],
            ]);
        });
        Event::listen(IncidentReported::class, function (IncidentReported $event): void {
            $incident = $event->incident;

            $this->auditChange('incident.reported', [
                'actor_type' => 'user',
                'actor_id' => (string) $event->actor->getKey(),
                'patient_id' => $incident->patient_id,
                'resource_type' => 'incident',
                'resource_id' => $incident->id,
                'context' => [
                    'visit_id' => $incident->visit_id,
                    'reported_by_resource_id' => $incident->reported_by_resource_id,
                    'category' => $incident->category,
                    'severity' => $incident->severity,
                    'severity_source' => 'reporter_selected',
                    'system_assessed_severity' => false,
                    ...$event->context,
                ],
            ]);
        });
        Event::listen(NurseSyncActionProcessed::class, function (NurseSyncActionProcessed $event): void {
            $action = $event->action;

            $this->auditChange('nurse_sync.'.$action->status, [
                'actor_type' => 'user',
                'actor_id' => (string) $event->actor->getKey(),
                'patient_id' => $event->patientId,
                'resource_type' => 'nurse_sync_action',
                'resource_id' => $action->id,
                'context' => [
                    'client_action_uuid' => $action->client_action_uuid,
                    'visit_id' => $action->visit_id,
                    'nurse_resource_id' => $action->nurse_resource_id,
                    'action_type' => $action->action_type,
                    'device_sequence' => $action->device_sequence,
                    ...$event->context,
                ],
            ]);
        });

        // AiCore ledger/action events. This app-layer glue keeps AiCore from
        // depending on Audit while proving every governed path hits the chain.
        Event::listen(AiInteractionRecorded::class, function (AiInteractionRecorded $event): void {
            $interaction = $event->interaction;

            $this->auditChange('ai_interaction.'.$interaction->outcome, [
                'actor_type' => 'agent',
                'actor_id' => $interaction->agent,
                'resource_type' => 'ai_interaction',
                'resource_id' => $interaction->id,
                'context' => [
                    'feature' => $interaction->feature,
                    'provider' => $interaction->provider,
                    'model' => $interaction->model,
                    'prompt_hash' => $interaction->prompt_hash,
                    'cost_minor' => $interaction->cost_minor,
                    'label' => $interaction->label,
                ],
            ]);
        });
        Event::listen(AgentActionLifecycleChanged::class, function (AgentActionLifecycleChanged $event): void {
            $action = $event->action;

            $this->auditChange('agent_action.'.$event->state, [
                'actor_type' => 'agent',
                'actor_id' => $action->agent,
                'resource_type' => 'agent_action',
                'resource_id' => $action->id,
                'context' => [
                    'feature' => $action->feature,
                    'tool_key' => $action->tool_key,
                    'status' => $action->status,
                    ...$event->context,
                ],
            ]);
        });

        // Tenant status changes (platform-level; audited against the tenant itself).
        Tenant::updated(function (Tenant $tenant): void {
            if ($tenant->wasChanged('status')) {
                $this->app->make(AuditService::class)->record([
                    'action' => 'tenant.status_changed',
                    'tenant_id' => $tenant->id,
                    'resource_type' => 'tenant',
                    'resource_id' => $tenant->id,
                    'context' => ['status' => $tenant->status],
                ]);
            }
        });
    }

    /**
     * Record a tenant-scoped change, skipping system-mode provisioning so seeded
     * roles etc. do not pollute the audit chain.
     *
     * @param  array<string, mixed>  $data
     */
    private function auditChange(string $action, array $data): void
    {
        if ($this->app->make(TenantContext::class)->inSystemMode()) {
            return;
        }

        $this->app->make(AuditService::class)->record(['action' => $action, ...$data]);
    }

    private function auditCredentialChange(Credential $credential, string $action): void
    {
        $this->auditChange($action, [
            'resource_type' => 'credential',
            'resource_id' => $credential->id,
            'context' => [
                'staff_profile_id' => $credential->staff_profile_id,
                'type' => $credential->type,
                'status' => $credential->status,
                'expires_on' => $credential->expires_on?->toDateString(),
            ],
        ]);
    }

    private function auditEncounterChange(Encounter $encounter, User $actor, string $action): void
    {
        $this->auditChange($action, [
            'actor_type' => 'user',
            'actor_id' => (string) $actor->getKey(),
            'patient_id' => $encounter->patient_id,
            'resource_type' => 'encounter',
            'resource_id' => $encounter->id,
            'context' => [
                'practitioner_id' => $encounter->practitioner_id,
                'branch_id' => $encounter->branch_id,
                'appointment_id' => $encounter->appointment_id,
                'type' => $encounter->type,
                'status' => $encounter->status,
                'started_at' => $encounter->started_at->toDateTimeString(),
                'ended_at' => $encounter->ended_at?->toDateTimeString(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $extraContext
     */
    private function auditClinicalNoteChange(
        ClinicalNote $note,
        User $actor,
        string $action,
        array $extraContext = [],
        ?string $reason = null,
    ): void {
        $this->auditChange($action, [
            'actor_type' => 'user',
            'actor_id' => (string) $actor->getKey(),
            'patient_id' => $note->patient_id,
            'resource_type' => 'clinical_note',
            'resource_id' => $note->id,
            'reason' => $reason,
            'context' => [
                'encounter_id' => $note->encounter_id,
                'author_id' => $note->author_id,
                'status' => $note->status,
                'version' => $note->version,
                'template_id' => $note->template_id,
                'signed_at' => $note->signed_at?->toDateTimeString(),
                'signed_by' => $note->signed_by,
                ...$extraContext,
            ],
        ]);
    }
}
