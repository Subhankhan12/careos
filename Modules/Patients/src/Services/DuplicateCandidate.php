<?php

namespace Modules\Patients\Services;

use Modules\Patients\Models\Patient;

final class DuplicateCandidate
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly Patient $patient,
        public readonly int $score,
        public readonly string $confidence,
        public readonly array $reasons,
    ) {}
}
