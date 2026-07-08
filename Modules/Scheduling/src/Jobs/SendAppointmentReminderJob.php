<?php

namespace Modules\Scheduling\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\ConsentService;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Events\AppointmentReminderDeliveryRecorded;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentReminder;
use Modules\Scheduling\Services\ReminderChannelManager;
use Throwable;

class SendAppointmentReminderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $reminderId,
    ) {}

    public function handle(
        TenantContext $tenants,
        ConsentService $consents,
        ReminderChannelManager $channels,
    ): void {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $previousTenant = $tenants->current();
        $tenants->set($tenant);

        try {
            DB::transaction(function () use ($consents, $channels): void {
                /** @var AppointmentReminder $reminder */
                $reminder = AppointmentReminder::query()
                    ->whereKey($this->reminderId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! in_array($reminder->status, [AppointmentReminder::STATUS_PENDING, AppointmentReminder::STATUS_FAILED], true)) {
                    return;
                }

                $appointment = Appointment::query()
                    ->findOrFail($reminder->appointment_id);

                if (! in_array($appointment->status, Appointment::blockingStatuses(), true)) {
                    $this->mark($reminder, AppointmentReminder::STATUS_SKIPPED, 'Appointment is no longer active.');

                    return;
                }

                $patient = $appointment->patient_id !== null
                    ? Patient::query()->find($appointment->patient_id)
                    : null;

                if ($patient === null || ! $consents->has($patient, 'comms.email')) {
                    $this->mark($reminder, AppointmentReminder::STATUS_SKIPPED, 'Missing comms.email consent.');

                    return;
                }

                if (! $channels->has($reminder->channel)) {
                    $this->mark($reminder, AppointmentReminder::STATUS_SKIPPED, 'Reminder channel is not configured.');

                    return;
                }

                $channel = $channels->get($reminder->channel);

                if (! $channel->canSend($reminder)) {
                    $this->mark($reminder, AppointmentReminder::STATUS_SKIPPED, 'No recipient for reminder channel.');

                    return;
                }

                try {
                    $channel->send($reminder);
                } catch (Throwable $exception) {
                    $this->mark($reminder, AppointmentReminder::STATUS_FAILED, $exception->getMessage());

                    throw $exception;
                }

                $this->mark($reminder, AppointmentReminder::STATUS_SENT);
            });
        } finally {
            if ($previousTenant !== null) {
                $tenants->set($previousTenant);
            } else {
                $tenants->forget();
            }
        }
    }

    private function mark(AppointmentReminder $reminder, string $status, ?string $reason = null): void
    {
        $reminder->forceFill([
            'status' => $status,
            'sent_at' => $status === AppointmentReminder::STATUS_SENT ? now() : $reminder->sent_at,
            'failed_at' => $status === AppointmentReminder::STATUS_FAILED ? now() : null,
            'failure_reason' => $reason,
        ])->save();

        Event::dispatch(new AppointmentReminderDeliveryRecorded($reminder->refresh()));
    }
}
