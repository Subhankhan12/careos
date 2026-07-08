<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A typed per-tenant setting.
 *
 * Tenant-owned ({@see BelongsToTenant}). `value` is stored as JSON (so native
 * types round-trip); `type` records the intended scalar type for coercion by
 * the SettingsService.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property mixed $value
 * @property string $type
 */
class Setting extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'value',
        'type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
