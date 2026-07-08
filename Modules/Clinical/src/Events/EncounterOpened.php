<?php

namespace Modules\Clinical\Events;

use Modules\Clinical\Models\Encounter;
use Modules\Platform\Models\User;

class EncounterOpened
{
    public function __construct(
        public readonly Encounter $encounter,
        public readonly User $actor,
    ) {}
}
