<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A time-boxed emergency ("break-glass") access grant.
 *
 * Tenant-owned ({@see BelongsToTenant}). Expiry is by timestamp and checked at
 * access time (no cron needed for correctness). The audit emission for a grant
 * request lives in the application-layer BreakGlassService.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $user_id
 * @property string $scope
 * @property string $reason
 * @property Carbon $granted_at
 * @property Carbon $expires_at
 * @property bool $activated
 */
class BreakGlassGrant extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'scope',
        'reason',
        'granted_at',
        'expires_at',
        'activated',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'activated' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
