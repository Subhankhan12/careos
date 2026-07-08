<?php

namespace Modules\Scheduling\Channels;

use Illuminate\Support\Facades\Notification;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\Scheduling\Contracts\AppointmentReminderChannel;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentReminder;
use Modules\Scheduling\Notifications\AppointmentReminderNotification;

class EmailAppointmentReminderChannel implements AppointmentReminderChannel
{
    public function key(): string
    {
        return AppointmentReminder::CHANNEL_EMAIL;
    }

    public function canSend(AppointmentReminder $reminder): bool
    {
        return $this->emailFor($reminder) !== null;
    }

    public function send(AppointmentReminder $reminder): void
    {
        $email = $this->emailFor($reminder);

        if ($email === null) {
            return;
        }

        Notification::route('mail', $email)
            ->notify(new AppointmentReminderNotification($reminder));
    }

    private function emailFor(AppointmentReminder $reminder): ?string
    {
        $appointment = Appointment::query()
            ->findOrFail($reminder->appointment_id);

        if ($appointment->patient_id === null) {
            return null;
        }

        $patient = Patient::query()->find($appointment->patient_id);

        if ($patient === null) {
            return null;
        }

        $email = PatientContact::query()
            ->where('patient_id', $patient->id)
            ->where('type', PatientContact::TYPE_EMAIL)
            ->whereNotNull('value')
            ->orderByDesc('is_primary')
            ->value('value');

        return $email !== null ? (string) $email : null;
    }
}
