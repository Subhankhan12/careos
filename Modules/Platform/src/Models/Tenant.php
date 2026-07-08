<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
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
}
