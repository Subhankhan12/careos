<?php

namespace Modules\Scheduling\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\AppointmentSeries;

/**
 * A recurring appointment series was created or ended. The app layer listens to
 * audit it (Scheduling never depends on Audit models).
 */
class AppointmentSeriesLifecycleChanged
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly AppointmentSeries $series,
        public readonly string $status,
        public readonly ?User $actor,
        public readonly array $context = [],
    ) {}
}
