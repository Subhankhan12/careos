<?php

namespace Modules\AiCore\Agents;

use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Retrieval\KbCandidate;
use Modules\AiCore\Retrieval\KbRetriever;
use Modules\AiCore\Services\AiInteractionRecorder;
use Modules\AiCore\Services\BudgetGate;
use Modules\AiCore\Services\KillSwitch;
use Modules\AiCore\Services\PromptRegistry;

class FrontDeskAgent
{
    public function __construct(
        private readonly KbRetriever $retriever,
        private readonly KillSwitch $killSwitch,
        private readonly BudgetGate $budgetGate,
        private readonly PromptRegistry $prompts,
        private readonly AiInteractionRecorder $recorder,
    ) {}

    /**
     * @return array{status: string, answer: string|null, label: string, human_handoff: bool, source?: array{id: string, title: string, score: float}}
     */
    public function answer(string $question): array
    {
        $feature = 'front_desk.faq';
        $prompt = $this->prompts->get($feature);

        if (! $this->killSwitch->enabled($feature)) {
            $this->recorder->record($feature, 'front-desk-agent', 'internal', 'kb-rag', '1', $prompt->hash(), 'disabled');

            return $this->handoff('disabled');
        }

        try {
            $this->budgetGate->assertWithinBudget(1);
        } catch (AiCoreException $e) {
            $this->recorder->record(
                $feature,
                'front-desk-agent',
                'internal',
                'kb-rag',
                '1',
                $prompt->hash(),
                'budget_blocked',
                errorMessage: $e->getMessage(),
            );

            return $this->handoff('budget_blocked');
        }

        if ($this->isMedicalQuestion($question)) {
            $this->recorder->record($feature, 'front-desk-agent', 'internal', 'kb-rag', '1', $prompt->hash(), 'refused');

            return [
                'status' => 'refused',
                'answer' => 'I cannot help with medical questions here. A human team member will take over.',
                'label' => AiInteractionRecorder::LABEL,
                'human_handoff' => true,
            ];
        }

        $candidate = $this->bestCandidate($question);

        if ($candidate === null || $candidate->score < 0.2 || ! $this->hasLexicalSupport($question, $candidate)) {
            $this->recorder->record($feature, 'front-desk-agent', 'internal', 'kb-rag', '1', $prompt->hash(), 'escalated');

            return $this->handoff('escalated');
        }

        $this->recorder->record(
            $feature,
            'front-desk-agent',
            'internal',
            'kb-rag',
            '1',
            $prompt->hash(),
            'answered',
            outputRef: $candidate->article->id,
            metadata: [
                'kb_article_id' => $candidate->article->id,
                'kb_article_title' => $candidate->article->title,
                'score' => $candidate->score,
            ],
        );

        return [
            'status' => 'answered',
            'answer' => $candidate->article->body,
            'label' => AiInteractionRecorder::LABEL,
            'human_handoff' => false,
            'source' => [
                'id' => $candidate->article->id,
                'title' => $candidate->article->title,
                'score' => round($candidate->score, 4),
            ],
        ];
    }

    private function bestCandidate(string $question): ?KbCandidate
    {
        return $this->retriever->retrieve($question, 1)[0] ?? null;
    }

    /**
     * @return array{status: string, answer: null, label: string, human_handoff: bool}
     */
    private function handoff(string $status): array
    {
        return [
            'status' => $status,
            'answer' => null,
            'label' => AiInteractionRecorder::LABEL,
            'human_handoff' => true,
        ];
    }

    private function isMedicalQuestion(string $question): bool
    {
        return preg_match('/\b(symptom|symptoms|pain|fever|bleeding|chest pain|dose|dosage|medication|medicine|diagnose|diagnosis|triage)\b/i', $question) === 1;
    }

    private function hasLexicalSupport(string $question, KbCandidate $candidate): bool
    {
        $questionTokens = $this->meaningfulTokens($question);
        $articleTokens = $this->meaningfulTokens(
            $candidate->article->title.' '.$candidate->article->body.' '.implode(' ', $candidate->article->tags ?? []),
        );

        return array_intersect($questionTokens, $articleTokens) !== [];
    }

    /**
     * @return list<string>
     */
    private function meaningfulTokens(string $text): array
    {
        preg_match_all('/[a-z0-9]+/i', strtolower($text), $matches);
        $stop = ['the', 'and', 'for', 'with', 'you', 'your', 'are', 'can', 'what', 'where', 'when', 'how', 'does'];

        $tokens = array_values(array_unique(array_filter(
            $matches[0],
            fn (string $token): bool => strlen($token) > 2 && ! in_array($token, $stop, true),
        )));

        foreach ($tokens as $token) {
            if (str_ends_with($token, 'ing') && strlen($token) > 5) {
                $tokens[] = substr($token, 0, -3);
            }
        }

        return array_values(array_unique($tokens));
    }
}
