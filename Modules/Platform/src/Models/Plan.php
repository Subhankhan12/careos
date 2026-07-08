<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subscription plan (PLATFORM-level catalog, shared across tenants).
 *
 * `price_minor` is money in integer minor units (e.g. cents) — never a float.
 * `limits` and `features` are JSON maps ({max_branches, max_staff}, feature
 * defaults), read by the Tenant and the FeatureService.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property int $price_minor
 * @property array<string, int>|null $limits
 * @property array<string, bool>|null $features
 */
class Plan extends Model
{
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'name',
        'price_minor',
        'limits',
        'features',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'limits' => 'array',
            'features' => 'array',
        ];
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }
}
