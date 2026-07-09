<?php

namespace Modules\Clinical\Events;

use Modules\Clinical\Models\ClinicalNote;
use Modules\Platform\Models\User;

class ClinicalNoteAmended
{
    public function __construct(
        public readonly ClinicalNote $original,
        public readonly ClinicalNote $amendment,
        public readonly User $actor,
        public readonly string $reason,
    ) {}
}
