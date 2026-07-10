<?php

namespace App\Comms;

use Modules\Comms\Models\NotificationTemplate;
use Modules\Comms\Services\NotificationService;
use Modules\Patients\Models\Patient;
use Modules\Scheduling\Channels\EmailAppointmentReminderChannel;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentReminder;
use Modules\Scheduling\Notifications\AppointmentReminderNotification;

/**
 * App-layer bridge (D-017): appointment reminders now deliver through the G.2
 * NotificationService as a TRANSACTIONAL template while Scheduling stays free
 * of any Comms dependency. Behavior is unchanged — the reminder job's consent
 * gate still applies first, the engine's transactional consent gate agrees,
 * and the original notification class is preserved.
 */
class EngineAppointmentReminderChannel extends EmailAppointmentReminderChannel
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function send(AppointmentReminder $reminder): void
    {
        $appointment = Appointment::query()->findOrFail($reminder->appointment_id);
        $patient = Patient::query()->findOrFail($appointment->patient_id);

        $this->notifications->send(
            'appointment.reminder',
            $patient,
            [
                'starts_at' => $appointment->starts_at->toDateTimeString(),
                'appointment_id' => $appointment->id,
                'reminder_id' => $reminder->id,
                'type' => $reminder->type,
            ],
            NotificationTemplate::CATEGORY_TRANSACTIONAL,
            new AppointmentReminderNotification($reminder),
        );
    }
}
