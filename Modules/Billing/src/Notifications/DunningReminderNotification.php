<?php

namespace Modules\Billing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Billing\Models\DunningEvent;
use Modules\Billing\Models\Invoice;

class DunningReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly DunningEvent $event,
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
        return (new MailMessage)
            ->subject('Payment reminder: invoice '.$this->invoice->series.'-'.$this->invoice->number)
            ->line($this->body)
            ->line('Invoice: '.$this->invoice->series.'-'.$this->invoice->number)
            ->line('Reminder level: '.$this->event->level);
    }
}
