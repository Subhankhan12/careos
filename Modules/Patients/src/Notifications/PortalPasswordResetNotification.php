<?php

namespace Modules\Patients\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The portal password-reset email (PT.P7) — the same shape as {@see PortalInviteNotification}: a
 * link carrying the single-use token, and a code in the body.
 *
 * The code is not security theatre borrowed from the invite. It means a LINK on its own is not
 * enough: a URL that leaks through a forwarded mail, a shared screen, a referrer or a browser
 * history cannot reset the password without the body it came with.
 */
class PortalPasswordResetNotification extends Notification
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
            ->subject('Reset your CareOS portal password')
            ->line('Someone asked to reset the password for your patient portal account.')
            ->action('Set a new password', route('portal.password.reset', ['token' => $this->token]))
            ->line('Code: '.$this->otp)
            ->line('This link can be used once and stops working in 30 minutes.')
            // Not an instruction to act — an instruction to do nothing, which is the honest advice
            // when the recipient did not ask for this.
            ->line('If you did not ask for this, you can ignore this email — your password has not changed.');
    }
}
