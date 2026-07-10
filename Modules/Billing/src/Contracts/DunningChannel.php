<?php

namespace Modules\Billing\Contracts;

use Modules\Billing\Models\DunningEvent;
use Modules\Billing\Models\Invoice;

/**
 * Delivery channel for dunning reminders. Mirrors the Phase C reminder-channel
 * abstraction (channel contract + manager + Laravel Notification routing), but
 * dunning is a contractual/legal communication and is NOT gated on comms
 * consent — see DECISIONS D-F7.
 */
interface DunningChannel
{
    public function key(): string;

    public function canSend(Invoice $invoice): bool;

    public function send(Invoice $invoice, DunningEvent $event, string $body): void;
}
