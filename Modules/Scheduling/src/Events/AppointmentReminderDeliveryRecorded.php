<?php

namespace Modules\Scheduling\Events;

use Modules\Scheduling\Models\AppointmentReminder;

class AppointmentReminderDeliveryRecorded
{
    public function __construct(public readonly AppointmentReminder $reminder) {}
}
