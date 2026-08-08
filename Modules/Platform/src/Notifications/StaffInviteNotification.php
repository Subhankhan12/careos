<?php

namespace Modules\Platform\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The staff-invitation email (SETTINGS.P6) — mirrors the patient PortalInviteNotification: a mail
 * notification delivered via `Notification::route('mail', $email)`, which degrades cleanly when
 * mail is not configured. Carries the single-use accept URL.
 */
class StaffInviteNotification extends Notification
{
    public function __construct(
        public readonly string $acceptUrl,
        public readonly string $tenantName,
        public readonly string $roleName,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You have been invited to '.$this->tenantName.' on CareOS')
            ->line('You have been invited to join '.$this->tenantName.' as '.$this->roleName.'.')
            ->action('Accept invitation', $this->acceptUrl)
            ->line('This invitation is single-use and will expire. If you did not expect it, you can ignore this email.');
    }
}
