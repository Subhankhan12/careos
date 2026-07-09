<?php

namespace App\AiCore\Agents;

use Modules\AiCore\Services\AgentRuntime;
use Modules\Platform\Models\User;

class FollowUpAgent
{
    public const FEATURE = 'clinical.recall_follow_up';

    public const AGENT = 'clinical-follow-up-agent';

    public function __construct(private readonly AgentRuntime $runtime) {}

    /**
     * @return array<string, mixed>
     */
    public function draftRecallMessage(string $recallId, string $template, User $actor): array
    {
        return $this->runtime->runTool(
            'clinical.draft_recall_message',
            [
                'recall_id' => $recallId,
                'template' => $template,
            ],
            $actor,
            self::FEATURE,
            self::AGENT,
            'Draft recall outreach wording for clinician approval',
        );
    }
}
