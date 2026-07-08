<?php

namespace Modules\Scheduling\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentReminder;

class AppointmentReminderNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly AppointmentReminder $reminder) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appointment = Appointment::query()->findOrFail($this->reminder->appointment_id);

        return (new MailMessage)
            ->subject('Appointment reminder')
            ->line('This is a reminder for an upcoming appointment.')
            ->line('Appointment time: '.$appointment->starts_at->toDateTimeString());
    }
}
