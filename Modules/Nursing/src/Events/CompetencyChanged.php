<?php

namespace Modules\Nursing\Events;

use Modules\Nursing\Models\Competency;
use Modules\Platform\Models\User;

/**
 * A competency definition changed (created, edited, enforcement flipped, or
 * deactivated). Audited in the app layer because it governs who can be assigned
 * to whom — an enforcement change (hard <-> soft) is a dispatch-policy change.
 */
class CompetencyChanged
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly Competency $competency,
        public readonly string $action,
        public readonly array $context = [],
        public readonly ?User $actor = null,
    ) {}
}
