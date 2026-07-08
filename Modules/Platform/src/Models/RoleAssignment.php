<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A role assignment: grants a user a role, optionally scoped to a single branch.
 *
 * Backed by the `role_user` table. Tenant-owned via {@see BelongsToTenant}.
 *   - branch_id NULL → the role applies across all branches in the tenant;
 *   - branch_id set  → the role's permissions apply only for that branch.
 *
 * `abac_conditions` is reserved for attribute-based conditions
 * (own_patients_only etc.) and is NOT evaluated yet — completed in a later gate.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $user_id
 * @property string $role_id
 * @property string|null $branch_id
 * @property array<string, mixed>|null $abac_conditions
 */
class RoleAssignment extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'role_user';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'role_id',
        'branch_id',
        'abac_conditions',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abac_conditions' => 'array',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
