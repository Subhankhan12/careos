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
            ->line('Token: '.$this->token)
            ->line('Code: '.$this->otp);
    }
}
