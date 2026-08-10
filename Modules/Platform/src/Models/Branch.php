<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Builder;
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
 * @property bool $accepts_online_bookings
 * @property bool $is_primary
 * @property string|null $phone
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
        'accepts_online_bookings',
        'is_primary',
        'phone',
    ];

    protected $attributes = [
        'timezone' => 'UTC',
        'active' => true,
        'accepts_online_bookings' => true,
        'is_primary' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'accepts_online_bookings' => 'boolean',
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The FIRST branch a tenant ever gets is its PRIMARY (default) branch — the
        // exactly-one-primary invariant (BRANCH.P2) is seeded here so EVERY creation path
        // (service, factory, demo seeders that call Branch::create directly) leaves a tenant
        // with exactly one primary. Later branches default non-primary; move the flag with
        // BranchService::setPrimary. The query is tenant-scoped (BelongsToTenant).
        static::creating(function (Branch $branch): void {
            if (! $branch->is_primary && ! static::query()->exists()) {
                $branch->is_primary = true;
            }
        });
    }

    /**
     * A branch is exposed to online booking only when it is BOTH active AND accepting online
     * bookings. `accepts_online_bookings=false` (the soft-suspend) hides it from online booking
     * while it stays active for staff (day-board/dispatch) and keeps its existing appointments.
     *
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopeOnlineBookable($query)
    {
        return $query->where('active', true)->where('accepts_online_bookings', true);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
