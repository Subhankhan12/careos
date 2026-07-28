<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Surgery\Exceptions\SurgicalCaseException;

/**
 * A member of the surgical TEAM on a case (SURGERY.G2) — surgeon / anesthetist / scrub-OR nurse (the G1 OR
 * roles). `team_role` is a plain operational label; a person appears once per case. Tenant-scoped, fail-closed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $surgical_case_id
 * @property string $staff_profile_id
 * @property string $team_role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StaffProfile|null $staffProfile
 * @property-read SurgicalCase|null $surgicalCase
 */
class SurgicalCaseTeamMember extends Model
{
    use BelongsToTenant, HasUlids;

    // A plain, tenant-meaningful team role — an operational label, never a judgment.
    public const ROLE_SURGEON = 'surgeon';

    public const ROLE_ANESTHETIST = 'anesthetist';

    public const ROLE_SCRUB_NURSE = 'scrub_nurse';

    public const ROLE_OTHER = 'other';

    public const ROLES = [self::ROLE_SURGEON, self::ROLE_ANESTHETIST, self::ROLE_SCRUB_NURSE, self::ROLE_OTHER];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'surgical_case_id',
        'staff_profile_id',
        'team_role',
    ];

    protected static function booted(): void
    {
        static::saving(function (SurgicalCaseTeamMember $member): void {
            if (! in_array($member->team_role, self::ROLES, true)) {
                throw SurgicalCaseException::invalidTeamRole((string) $member->team_role);
            }
        });

        static::creating(function (SurgicalCaseTeamMember $member): void {
            if (! empty($member->staff_profile_id) && ! StaffProfile::whereKey($member->staff_profile_id)->exists()) {
                throw CrossTenantReferenceException::forAttribute('staff_profile_id', (string) $member->staff_profile_id);
            }
        });
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }
}
