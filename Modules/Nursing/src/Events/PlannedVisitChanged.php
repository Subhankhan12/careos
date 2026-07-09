<?php

namespace Modules\Nursing\Events;

use Modules\Nursing\Models\PlannedVisit;
use Modules\Platform\Models\User;

class PlannedVisitChanged
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly PlannedVisit $visit,
        public readonly string $action,
        public readonly array $context = [],
        public readonly ?User $actor = null,
        public readonly ?string $reason = null,
    ) {}
}
