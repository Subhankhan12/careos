<?php

namespace Modules\Patients\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * Patient portal login identity, separate from staff/admin users.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $email
 * @property string|null $password
 * @property string $status
 * @property Carbon|null $invited_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $last_login_at
 */
class PortalAccount extends Authenticatable
{
    use BelongsToTenant, HasUlids, Notifiable;

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'email',
        'password',
        'status',
        'invited_at',
        'activated_at',
        'last_login_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'invited_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (PortalAccount $account) => $account->assertPatientWithinTenant());
        static::updating(function (PortalAccount $account): void {
            if ($account->isDirty('patient_id')) {
                $account->assertPatientWithinTenant();
            }
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function loginTokens(): HasMany
    {
        return $this->hasMany(PortalLoginToken::class);
    }

    private function assertPatientWithinTenant(): void
    {
        if (! Patient::whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }
    }
}
