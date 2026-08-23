<?php

namespace Modules\Patients\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortalInviteNotification extends Notification
{
    public function __construct(
        public readonly string $token,
        public readonly string $otp,
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
            ->subject('CareOS portal access')
            ->line('Use the secure link token and one-time code to activate your portal account.')
            // PT.P6 — the link the token was always for. Until this gate there was no page to
            // land on, so the email could only hand the patient a raw token and no way to use it.
            ->action('Activate your portal', route('portal.invite.show', ['token' => $this->token]))
            ->line('Token: '.$this->token)
            ->line('Code: '.$this->otp);
    }
}
