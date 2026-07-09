<?php

namespace App\Providers;

use App\AiCore\Tools\ClinicalSummaryTool;
use App\AiCore\Tools\DraftRecallMessageTool;
use App\AiCore\Tools\FillFromWaitlistTool;
use App\AiCore\Tools\SuggestSlotsTool;
use App\Audit\AuthAuditSubscriber;
use App\Audit\PlatformAuditContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\AiCore\Events\AgentActionLifecycleChanged;
use Modules\AiCore\Events\AiInteractionRecorded;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Audit\Contracts\AuditContext;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Events\ClinicalNoteAmended;
use Modules\Clinical\Events\ClinicalNoteSigned;
use Modules\Clinical\Events\ClinicalRecordChanged;
use Modules\Clinical\Events\DocumentChanged;
use Modules\Clinical\Events\EncounterClosed;
use Modules\Clinical\Events\EncounterOpened;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Nursing\Events\IncidentReported;
use Modules\Nursing\Events\NurseSyncActionProcessed;
use Modules\Nursing\Events\PlannedVisitChanged;
use Modules\Nursing\Events\ServiceAgreementChanged;
use Modules\Nursing\Events\VisitEventRecorded;
use Modules\People\Models\Credential;
use Modules\Platform\Models\FeatureFlag;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Setting;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Events\AppointmentBooked;
use Modules\Scheduling\Events\AppointmentReminderDeliveryRecorded;
use Modules\Scheduling\Events\AppointmentTransitioned;
use Modules\Scheduling\Events\WaitlistEntryStatusChanged;
use Modules\Scheduling\Models\Appointment;

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

        $this->app->afterResolving(ToolRegistry::class, function (ToolRegistry $registry): void {
            $registry->register($this->app->make(FillFromWaitlistTool::class));
            $registry->register($this->app->make(SuggestSlotsTool::class));
            $registry->register($this->app->make(ClinicalSummaryTool::class));
            $registry->register($this->app->make(DraftRecallMessageTool::class));
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
                'actor_type' => 'user',
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
