<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A tenant role: a named bundle of permissions that can be assigned to users
 * ({@see RoleAssignment}).
 *
 * Tenant-owned: uses {@see BelongsToTenant}. `is_system` marks the seeded
 * starter templates (org_admin, doctor, …) versus tenant-authored custom roles.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $key
 * @property bool $is_system
 */
class Role extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'key',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }
}
