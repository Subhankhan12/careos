<?php

namespace App\Comms;

use Modules\Billing\Channels\EmailDunningChannel;
use Modules\Billing\Models\DunningEvent;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Notifications\DunningReminderNotification;
use Modules\Comms\Models\NotificationTemplate;
use Modules\Comms\Services\NotificationService;
use Modules\Patients\Models\Patient;

/**
 * App-layer bridge (D-017): dunning delivery now records through the G.2
 * NotificationService as a LEGAL template (D-F7/D-G4 — never consent-gated)
 * while Billing stays free of any Comms dependency. The original notification
 * class and D-F7 behavior are preserved.
 */
class EngineDunningChannel extends EmailDunningChannel
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function send(Invoice $invoice, DunningEvent $event, string $body): void
    {
        $patient = Patient::query()->findOrFail($invoice->patient_id);

        $this->notifications->send(
            'billing.dunning',
            $patient,
            [
                'body' => $body,
                'invoice' => $invoice->series.'-'.$invoice->number,
                'level' => $event->level,
            ],
            NotificationTemplate::CATEGORY_LEGAL,
            new DunningReminderNotification($invoice, $event, $body),
        );
    }
}
