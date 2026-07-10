<?php

namespace Modules\Comms\Contracts;

use Illuminate\Notifications\Notification;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;

/**
 * Delivery driver for one notification channel. Email ships now; sms and
 * portal drivers plug in behind this interface later (SMS is DEFERRED).
 */
interface NotificationChannelDriver
{
    public function channel(): string;

    public function canDeliver(Patient|User $recipient): bool;

    /**
     * Deliver rendered content. When a caller supplies a $mailable, the driver
     * sends that notification object (legacy senders keep their notification
     * classes); otherwise it delivers the rendered subject/body directly.
     */
    public function deliver(Patient|User $recipient, ?string $subject, string $body, ?Notification $mailable = null): void;
}
