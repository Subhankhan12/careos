<?php

namespace Modules\Clinical\Events;

use Modules\Platform\Models\User;

class ClinicalRecordChanged
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $action,
        public readonly string $resourceType,
        public readonly string $resourceId,
        public readonly string $patientId,
        public readonly User $actor,
        public readonly array $context = [],
        public readonly ?string $reason = null,
    ) {}
}
