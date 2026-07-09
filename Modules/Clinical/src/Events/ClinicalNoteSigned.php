<?php

namespace Modules\Clinical\Events;

use Modules\Clinical\Models\ClinicalNote;
use Modules\Platform\Models\User;

class ClinicalNoteSigned
{
    public function __construct(
        public readonly ClinicalNote $note,
        public readonly User $actor,
    ) {}
}
