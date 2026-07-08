<?php

namespace Modules\Patients\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * Short-lived magic-link token plus OTP verifier for portal provisioning.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $portal_account_id
 * @property string $purpose
 * @property string $token_hash
 * @property string $otp_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
class PortalLoginToken extends Model
{
    use BelongsToTenant, HasUlids;

    public const PURPOSE_INVITE = 'invite';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'portal_account_id',
        'purpose',
        'token_hash',
        'otp_hash',
        'expires_at',
        'consumed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (PortalLoginToken $token) => $token->assertAccountWithinTenant());
        static::updating(function (PortalLoginToken $token): void {
            if ($token->isDirty('portal_account_id')) {
                $token->assertAccountWithinTenant();
            }
        });
    }

    public function portalAccount(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class);
    }

    private function assertAccountWithinTenant(): void
    {
        if (! PortalAccount::whereKey($this->portal_account_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('portal_account_id', (string) $this->portal_account_id);
        }
    }
}
