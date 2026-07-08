<?php

namespace Modules\People\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\People\Services\CredentialService;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * A professional credential, license, certification, or registration.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $staff_profile_id
 * @property string $type
 * @property string $name
 * @property string|null $issuing_authority
 * @property string|null $identifier
 * @property Carbon|null $issued_on
 * @property Carbon|null $expires_on
 * @property string $status
 * @property string|null $document_path
 */
class Credential extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_VALID = 'valid';

    public const STATUS_EXPIRING = 'expiring';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'staff_profile_id',
        'type',
        'name',
        'issuing_authority',
        'identifier',
        'issued_on',
        'expires_on',
        'status',
        'document_path',
    ];

    protected $attributes = [
        'status' => self::STATUS_VALID,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Credential $credential): void {
            $credential->assertStaffProfileWithinTenant();
            $credential->status = app(CredentialService::class)->statusFor(
                $credential->expires_on,
                $credential->status,
            );
        });

        static::updating(function (Credential $credential): void {
            if ($credential->isDirty('staff_profile_id')) {
                $credential->assertStaffProfileWithinTenant();
            }

            if ($credential->status !== self::STATUS_REVOKED && $credential->isDirty('expires_on')) {
                $credential->status = app(CredentialService::class)->statusFor(
                    $credential->expires_on,
                    $credential->status,
                );
            }
        });
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        $today = Carbon::today()->toDateString();
        $through = Carbon::today()->addDays(max(0, $days))->toDateString();

        return $query
            ->where('status', '!=', self::STATUS_REVOKED)
            ->whereNotNull('expires_on')
            ->whereBetween('expires_on', [$today, $through]);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', self::STATUS_REVOKED)
            ->whereNotNull('expires_on')
            ->where('expires_on', '<', Carbon::today()->toDateString());
    }

    private function assertStaffProfileWithinTenant(): void
    {
        if (! StaffProfile::whereKey($this->staff_profile_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('staff_profile_id', (string) $this->staff_profile_id);
        }
    }
}
