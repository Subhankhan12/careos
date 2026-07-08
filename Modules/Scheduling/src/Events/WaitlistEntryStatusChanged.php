<?php

namespace Modules\Scheduling\Events;

use Modules\Platform\Models\User;
use Modules\Scheduling\Models\WaitlistEntry;

class WaitlistEntryStatusChanged
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly WaitlistEntry $entry,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly User $actor,
        public readonly array $context = [],
    ) {}
}
