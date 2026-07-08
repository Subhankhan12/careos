<?php

namespace Modules\AiCore\Events;

use Modules\AiCore\Models\AiInteraction;

class AiInteractionRecorded
{
    public function __construct(public readonly AiInteraction $interaction) {}
}
