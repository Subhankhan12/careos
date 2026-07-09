<?php

namespace Modules\Nursing\Events;

use Modules\Nursing\Models\NurseSyncAction;
use Modules\Platform\Models\User;

class NurseSyncActionProcessed
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly NurseSyncAction $action,
        public readonly User $actor,
        public readonly ?string $patientId = null,
        public readonly array $context = [],
    ) {}
}
