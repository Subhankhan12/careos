<?php

namespace Modules\Billing\Channels;

use Illuminate\Support\Facades\Notification;
use Modules\Billing\Contracts\DunningChannel;
use Modules\Billing\Models\DunningEvent;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Notifications\DunningReminderNotification;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;

class EmailDunningChannel implements DunningChannel
{
    public const KEY = 'email';

    public function key(): string
    {
        return self::KEY;
    }

    public function canSend(Invoice $invoice): bool
    {
        return $this->emailFor($invoice) !== null;
    }

    public function send(Invoice $invoice, DunningEvent $event, string $body): void
    {
        $email = $this->emailFor($invoice);

        if ($email === null) {
            return;
        }

        // Deliberately NOT consent-gated: dunning is a contractual/legal
        // communication, not marketing (see DECISIONS D-F7).
        Notification::route('mail', $email)
            ->notify(new DunningReminderNotification($invoice, $event, $body));
    }

    private function emailFor(Invoice $invoice): ?string
    {
        $patient = Patient::query()->find($invoice->patient_id);

        if ($patient === null) {
            return null;
        }

        $email = PatientContact::query()
            ->where('patient_id', $patient->id)
            ->where('type', PatientContact::TYPE_EMAIL)
            ->whereNotNull('value')
            ->orderByDesc('is_primary')
            ->value('value');

        return $email !== null ? (string) $email : null;
    }
}
