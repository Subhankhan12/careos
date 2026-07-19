<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A branch (physical site) belonging to a tenant.
 *
 * Tenant-owned: uses {@see BelongsToTenant}, so every query is fail-closed to
 * the current tenant and tenant_id is stamped on create.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $code
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $postal_code
 * @property string|null $country
 * @property string $timezone
 * @property bool $active
 */
class Branch extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'code',
        'address_line1',
        'address_line2',
        'city',
        'postal_code',
        'country',
        'timezone',
        'active',
    ];

    protected $attributes = [
        'timezone' => 'UTC',
        'active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
