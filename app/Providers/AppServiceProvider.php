<?php

namespace App\Providers;

use App\Audit\AuthAuditSubscriber;
use App\Audit\PlatformAuditContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Audit\Contracts\AuditContext;
use Modules\Audit\Services\AuditService;
use Modules\People\Models\Credential;
use Modules\Platform\Models\FeatureFlag;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Setting;
use Modules\Platform\Models\Tenant;
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
}
