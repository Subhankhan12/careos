<?php

namespace Modules\Comms\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TemplateNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly ?string $subject,
        public readonly string $body,
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
        $mail = (new MailMessage)->subject($this->subject ?? 'Notification');

        foreach (preg_split('/\r?\n/', $this->body) ?: [] as $line) {
            $mail->line($line);
        }

        return $mail;
    }
}
