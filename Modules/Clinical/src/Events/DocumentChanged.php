<?php

namespace Modules\Clinical\Events;

use Modules\Clinical\Models\Document;
use Modules\Platform\Models\User;

class DocumentChanged
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly Document $document,
        public readonly User $actor,
        public readonly string $action,
        public readonly array $context = [],
    ) {}
}
