<?php

namespace Modules\AiCore\Retrieval;

use Modules\AiCore\Models\KbArticle;
use Modules\AiCore\Models\KbEmbedding;
use Modules\AiCore\Services\AiInteractionRecorder;
use Modules\AiCore\Services\PromptRegistry;

class KbEmbeddingService
{
    public const MODEL = 'careos-portable-hash-v1';

    public const DIMENSIONS = 32;

    public function __construct(
        private readonly AiInteractionRecorder $recorder,
        private readonly PromptRegistry $prompts,
    ) {}

    public function syncArticle(KbArticle $article): KbEmbedding
    {
        $content = $this->contentFor($article);
        $prompt = $this->prompts->get('front_desk.faq');

        $embedding = KbEmbedding::query()->updateOrCreate(
            [
                'kb_article_id' => $article->id,
                'embedding_model' => self::MODEL,
            ],
            [
                'vector' => $this->embed($content),
                'content_hash' => hash('sha256', $content),
            ],
        );

        $this->recorder->record(
            'front_desk.faq',
            'front-desk-agent',
            'internal',
            self::MODEL,
            '1',
            $prompt->hash(),
            'embedded',
            inputTokens: max(1, (int) ceil(strlen($content) / 4)),
            costMinor: 1,
            metadata: ['kb_article_id' => $article->id],
        );

        return $embedding;
    }

    /**
     * @return list<float>
     */
    public function embed(string $text): array
    {
        $vector = array_fill(0, self::DIMENSIONS, 0.0);

        foreach ($this->tokens($text) as $token) {
            $index = abs((int) crc32($token)) % self::DIMENSIONS;
            $vector[$index] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $vector)));

        if ($norm <= 0.0) {
            return $vector;
        }

        return array_map(fn (float $value): float => round($value / $norm, 6), $vector);
    }

    public function cosine(array $a, array $b): float
    {
        $sum = 0.0;

        for ($i = 0; $i < min(count($a), count($b)); $i++) {
            $sum += ((float) $a[$i]) * ((float) $b[$i]);
        }

        return $sum;
    }

    private function contentFor(KbArticle $article): string
    {
        return $article->title.' '.$article->body.' '.implode(' ', $article->tags ?? []);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        preg_match_all('/[a-z0-9]+/i', strtolower($text), $matches);

        return array_values(array_filter($matches[0], fn (string $token): bool => strlen($token) > 2));
    }
}
