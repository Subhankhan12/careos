<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A per-tenant feature flag override.
 *
 * Tenant-owned ({@see BelongsToTenant}). An explicit row overrides the plan's
 * default for that feature key; see the FeatureService for resolution order.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property bool $enabled
 */
class FeatureFlag extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
