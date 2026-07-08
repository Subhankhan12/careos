<?php

namespace Modules\AiCore\Retrieval;

use Modules\AiCore\Models\KbArticle;

class KbCandidate
{
    public function __construct(
        public readonly KbArticle $article,
        public readonly float $score,
    ) {}
}
