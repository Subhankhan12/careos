<?php

namespace Modules\Nursing\Events;

use Modules\Nursing\Models\Incident;
use Modules\Platform\Models\User;

class IncidentReported
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly Incident $incident,
        public readonly User $actor,
        public readonly array $context = [],
    ) {}
}
