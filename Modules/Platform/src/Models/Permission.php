<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A permission in the PLATFORM-level catalog (seeded, shared across tenants).
 *
 * NOT tenant-owned — it is the same catalog for everyone; who can do what is
 * decided by tenant roles ({@see Role}) that reference these permissions.
 *
 * @property string $id
 * @property string $key
 * @property string|null $description
 */
class Permission extends Model
{
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'description',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }
}
