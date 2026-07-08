<?php

namespace Modules\Scheduling\Events;

use Modules\Scheduling\Models\Appointment;

class AppointmentBooked
{
    /**
     * @param  list<string>  $resourceIds
     */
    public function __construct(
        public readonly Appointment $appointment,
        public readonly array $resourceIds,
    ) {}
}
