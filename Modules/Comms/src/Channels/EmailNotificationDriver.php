<?php

namespace Modules\Comms\Channels;

use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;
use Modules\Comms\Contracts\NotificationChannelDriver;
use Modules\Comms\Models\NotificationTemplate;
use Modules\Comms\Notifications\TemplateNotification;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\Platform\Models\User;
use RuntimeException;

class EmailNotificationDriver implements NotificationChannelDriver
{
    public function channel(): string
    {
        return NotificationTemplate::CHANNEL_EMAIL;
    }

    public function canDeliver(Patient|User $recipient): bool
    {
        return $this->emailFor($recipient) !== null;
    }

    public function deliver(Patient|User $recipient, ?string $subject, string $body, ?BaseNotification $mailable = null): void
    {
        $email = $this->emailFor($recipient);

        if ($email === null) {
            throw new RuntimeException('The recipient has no email address.');
        }

        Notification::route('mail', $email)
            ->notify($mailable ?? new TemplateNotification($subject, $body));
    }

    private function emailFor(Patient|User $recipient): ?string
    {
        if ($recipient instanceof User) {
            return $recipient->email;
        }

        $email = PatientContact::query()
            ->where('patient_id', $recipient->id)
            ->where('type', PatientContact::TYPE_EMAIL)
            ->whereNotNull('value')
            ->orderByDesc('is_primary')
            ->value('value');

        return $email !== null ? (string) $email : null;
    }
}
