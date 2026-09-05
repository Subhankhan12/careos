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

    /**
     * The staff profile belonging to an authenticated user, within the current tenant.
     *
     * WHO WROTE A CLINICAL RECORD IS THE ACTING USER, NOT A DOMAIN ROLE (QA-FIX.2a, D-195). Before
     * this existed, callers that needed "the clinician doing this right now" each resolved it their
     * own way, and one of them — the day-board "Document" button — resolved it from the
     * APPOINTMENT'S practitioner instead, so a note Dr. A typed was authored to Dr. B (`P2-C1`).
     * Resolving it in one place keeps the answer to "who is acting?" identical everywhere.
     *
     * Returns NULL rather than falling back to an arbitrary profile: a caller that cannot identify
     * the actor must refuse, never guess. Guessing is the defect this method exists to prevent.
     *
     * Tenant scoping comes from {@see BelongsToTenant} on the query, so this can never reach across
     * tenants.
     */
    public static function forUser(?User $user): ?self
    {
        if ($user === null) {
            return null;
        }

        return self::query()->where('user_id', $user->getKey())->first();
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
