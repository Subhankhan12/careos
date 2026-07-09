<?php

namespace Modules\Nursing\Events;

use Modules\Nursing\Models\ServiceAgreement;
use Modules\Platform\Models\User;

class ServiceAgreementChanged
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly ServiceAgreement $agreement,
        public readonly User $actor,
        public readonly string $action,
        public readonly array $context = [],
    ) {}
}
