<?php

namespace Modules\Nursing\Events;

use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitEvent;
use Modules\Platform\Models\User;

class VisitEventRecorded
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly Visit $visit,
        public readonly VisitEvent $event,
        public readonly User $actor,
        public readonly array $context = [],
    ) {}
}
