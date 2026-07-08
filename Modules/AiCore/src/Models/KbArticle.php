<?php

namespace Modules\AiCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * Tenant-authored approved knowledge-base content for front-desk answers.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $title
 * @property string $body
 * @property list<string>|null $tags
 * @property bool $is_active
 */
class KbArticle extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'title',
        'body',
        'tags',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function embeddings(): HasMany
    {
        return $this->hasMany(KbEmbedding::class);
    }
}
