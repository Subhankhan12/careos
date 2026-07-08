<?php

namespace Modules\AiCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * Portable vector-as-JSON embedding for a tenant KB article.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kb_article_id
 * @property string $embedding_model
 * @property list<float> $vector
 * @property string $content_hash
 */
class KbEmbedding extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'kb_article_id',
        'embedding_model',
        'vector',
        'content_hash',
    ];

    protected static function booted(): void
    {
        static::creating(function (KbEmbedding $embedding): void {
            $embedding->assertArticleInTenant();
        });

        static::updating(function (KbEmbedding $embedding): void {
            if ($embedding->isDirty('kb_article_id')) {
                $embedding->assertArticleInTenant();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vector' => 'array',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(KbArticle::class, 'kb_article_id');
    }

    private function assertArticleInTenant(): void
    {
        if (! KbArticle::whereKey($this->kb_article_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('kb_article_id', (string) $this->kb_article_id);
        }
    }
}
