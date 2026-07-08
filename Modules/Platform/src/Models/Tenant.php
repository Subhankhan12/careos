<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\TenantRegionImmutableException;

/**
 * A tenant (clinic / practice / agency).
 *
 * PLATFORM-level row: NOT tenant-owned, so it deliberately does NOT use
 * {@see BelongsToTenant} and carries no tenant_id.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $region
 * @property string $status
 * @property string|null $plan_id
 * @property-read Plan|null $plan
 */
class Tenant extends Model
{
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'slug',
        'region',
        'status',
        'plan_id',
    ];

    /**
     * Model-level defaults, mirroring the migration so a freshly created model
     * carries them in memory (not just after a DB round-trip).
     */
    protected $attributes = [
        'region' => 'eu',
        'status' => 'provisioning',
    ];

    protected static function booted(): void
    {
        // Region is immutable after creation (see TenantRegionImmutableException).
        static::updating(function (Tenant $tenant): void {
            if ($tenant->isDirty('region')) {
                throw TenantRegionImmutableException::make();
            }
        });
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Read a limit from the tenant's plan (e.g. 'max_branches'), or $default.
     */
    public function planLimit(string $key, mixed $default = null): mixed
    {
        if ($this->plan_id === null) {
            return $default;
        }

        // plan_id is set → the plan exists (FK nullOnDelete keeps this consistent).
        $limits = $this->plan->limits ?? [];

        return $limits[$key] ?? $default;
    }
}
