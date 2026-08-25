<?php

namespace App\Services;

use App\Http\Controllers\KbArticleController;
use Modules\AiCore\Models\KbArticle;
use Modules\AiCore\Retrieval\KbEmbeddingService;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\User;

/**
 * The ONE write path for knowledge-base articles (GOV.P3).
 *
 * Until this gate the create / update / toggle logic lived inline in
 * {@see KbArticleController}, which was fine while the controller was its only
 * caller — but GOV.P3 needs the demo seeder to produce articles that are indistinguishable from ones
 * a person really saved, and a second copy of "save, re-embed, audit" would be a second definition
 * to keep in step. So the three steps live here and the controller delegates.
 *
 * Every save does all three, in order:
 *   1. write the row;
 *   2. re-embed through the existing {@see KbEmbeddingService}, so what the agent retrieves matches
 *      what the article now says;
 *   3. record an AUDIT row naming the actor.
 *
 * **Step 3 is what supplies "last saved by" (G5).** No `updated_by` column was added: the audit
 * trail already records who saved an article and when, and duplicating that into a column would
 * create a second version of the same fact that could drift from the first. An article with no audit
 * row — one seeded straight into the table before this path existed — honestly reports no saver
 * rather than being attributed to somebody.
 *
 * This service does not evaluate an article. There is no quality, coverage or confidence score here,
 * and no ranking: the KB is staff-authored content, and the product records and displays it.
 */
class KbArticleService
{
    public const ACTION_CREATED = 'kb.article.created';

    public const ACTION_UPDATED = 'kb.article.updated';

    public const ACTION_ACTIVATED = 'kb.article.activated';

    public const ACTION_DEACTIVATED = 'kb.article.deactivated';

    /** Every audit action this service writes — the set "last saved by" reads back. */
    public const SAVE_ACTIONS = [
        self::ACTION_CREATED,
        self::ACTION_UPDATED,
        self::ACTION_ACTIVATED,
        self::ACTION_DEACTIVATED,
    ];

    public function __construct(
        private readonly KbEmbeddingService $embeddings,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $actor): KbArticle
    {
        $article = KbArticle::query()->create($attributes);

        $this->embeddings->syncArticle($article);
        $this->record(self::ACTION_CREATED, $article, $actor);

        return $article;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(KbArticle $article, array $attributes, User $actor): KbArticle
    {
        $article->forceFill($attributes)->save();

        // Content changed → re-embed through the existing path, so the agent never grounds on a
        // stale version of an article a human has just rewritten.
        $this->embeddings->syncArticle($article);
        $this->record(self::ACTION_UPDATED, $article, $actor);

        return $article;
    }

    /**
     * Flip the ACTIVE flag — the "delete" for a KB article. A deactivated article stops being
     * grounded on immediately (`KbRetriever` filters `is_active = true`) while its content and
     * embeddings are preserved, so it can be brought back.
     */
    public function toggle(KbArticle $article, User $actor): KbArticle
    {
        $article->forceFill(['is_active' => ! $article->is_active])->save();

        $this->record(
            $article->is_active ? self::ACTION_ACTIVATED : self::ACTION_DEACTIVATED,
            $article,
            $actor,
        );

        return $article;
    }

    private function record(string $action, KbArticle $article, User $actor): void
    {
        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => $action,
            'resource_type' => 'kb_article',
            'resource_id' => $article->id,
            'context' => [
                'title' => $article->title,
                'is_active' => $article->is_active,
            ],
        ]);
    }
}
