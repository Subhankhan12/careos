<?php

namespace Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A tenant-authored orderable test/study. NOT a licensed catalog.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $category
 * @property string $code
 * @property string $name
 * @property string|null $specimen_or_modality
 * @property bool $active
 */
class OrderableItem extends Model
{
    use BelongsToTenant, HasUlids;

    public const CATEGORY_LAB = 'lab';

    public const CATEGORY_IMAGING = 'imaging';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [self::CATEGORY_LAB, self::CATEGORY_IMAGING, self::CATEGORY_OTHER];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'category',
        'code',
        'name',
        'specimen_or_modality',
        'active',
    ];

    protected $attributes = [
        'active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
