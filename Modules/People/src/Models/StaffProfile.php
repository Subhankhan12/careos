<?php

namespace Modules\People\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;

/**
 * A tenant staff record, distinct from the auth user account.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int|null $user_id
 * @property string $first_name
 * @property string $last_name
 * @property string $display_name
 * @property string $profession
 * @property string|null $employee_ref
 * @property string|null $primary_branch_id
 * @property string $status
 */
class StaffProfile extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_ON_LEAVE = 'on_leave';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'display_name',
        'profession',
        'employee_ref',
        'primary_branch_id',
        'status',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected static function booted(): void
    {
        static::creating(function (StaffProfile $staffProfile): void {
            $staffProfile->assertPrimaryBranchWithinTenant();
        });

        static::updating(function (StaffProfile $staffProfile): void {
            if ($staffProfile->isDirty('primary_branch_id')) {
                $staffProfile->assertPrimaryBranchWithinTenant();
            }
        });
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function primaryBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'primary_branch_id');
    }

    private function assertPrimaryBranchWithinTenant(): void
    {
        if (empty($this->primary_branch_id)) {
            return;
        }

        if (! Branch::whereKey($this->primary_branch_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('primary_branch_id', (string) $this->primary_branch_id);
        }
    }
}
