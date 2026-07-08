<?php

namespace Modules\Scheduling\Contracts;

use Modules\Scheduling\Models\AppointmentReminder;

interface AppointmentReminderChannel
{
    public function key(): string;

    public function canSend(AppointmentReminder $reminder): bool;

    public function send(AppointmentReminder $reminder): void;
}
