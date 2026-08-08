<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A tenant-bound, single-use, expiring staff invitation (SETTINGS.P6). Accepting it provisions a
 * User in this tenant with {@see $role_id} (a built-in role template) via the real RBAC path. Only
 * the sha256 hash of the plaintext token is stored.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property string $role_id
 * @property string $token_hash
 * @property string $status
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
class StaffInvite extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'email',
        'role_id',
        'token_hash',
        'status',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** True when this invite is still usable (pending and not past its expiry). */
    public function isRedeemable(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
