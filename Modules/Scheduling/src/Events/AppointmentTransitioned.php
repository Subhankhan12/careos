<?php

namespace Modules\Scheduling\Events;

use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\Appointment;

class AppointmentTransitioned
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly Appointment $appointment,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly User|Patient $actor,
        public readonly ?string $reason = null,
        public readonly array $context = [],
    ) {}
}
