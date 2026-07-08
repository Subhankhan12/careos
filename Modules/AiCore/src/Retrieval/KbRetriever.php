<?php

namespace Modules\AiCore\Retrieval;

use Modules\AiCore\Models\KbArticle;
use Modules\AiCore\Models\KbEmbedding;

class KbRetriever
{
    public function __construct(private readonly KbEmbeddingService $embeddings) {}

    /**
     * @return list<KbCandidate>
     */
    public function retrieve(string $question, int $limit = 3): array
    {
        $queryVector = $this->embeddings->embed($question);
        $candidates = [];

        KbArticle::query()
            ->where('is_active', true)
            ->orderBy('title')
            ->get()
            ->each(function (KbArticle $article) use (&$candidates, $queryVector): void {
                $embedding = KbEmbedding::query()
                    ->where('kb_article_id', $article->id)
                    ->where('embedding_model', KbEmbeddingService::MODEL)
                    ->first();

                if ($embedding === null || $embedding->content_hash !== hash('sha256', $article->title.' '.$article->body.' '.implode(' ', $article->tags ?? []))) {
                    $embedding = $this->embeddings->syncArticle($article);
                }

                $score = $this->embeddings->cosine($queryVector, $embedding->vector);

                if ($score > 0.0) {
                    $candidates[] = new KbCandidate($article, $score);
                }
            });

        usort($candidates, fn (KbCandidate $a, KbCandidate $b): int => $b->score <=> $a->score);

        return array_slice($candidates, 0, $limit);
    }
}
