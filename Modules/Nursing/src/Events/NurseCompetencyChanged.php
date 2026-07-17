<?php

namespace Modules\Nursing\Events;

use Modules\Nursing\Models\NurseCompetency;
use Modules\Platform\Models\User;

/**
 * A nurse competency grant changed (granted or revoked). Audited in the app layer
 * because it changes which visits the nurse may be assigned to.
 */
class NurseCompetencyChanged
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly NurseCompetency $grant,
        public readonly string $action,
        public readonly array $context = [],
        public readonly ?User $actor = null,
    ) {}
}
