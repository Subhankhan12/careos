<?php

namespace Modules\Platform\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Services\PermissionService;

/**
 * A platform user (staff or super-admin).
 *
 * PLATFORM-level model: it is looked up by email at login BEFORE any tenant
 * context exists, so it deliberately does NOT use the fail-closed
 * {@see BelongsToTenant} scope. Instead it carries a
 * nullable tenant_id:
 *   - NULL     → platform super-admin (operates via system mode / platform scope);
 *   - non-null → tenant staff (their tenant is set into TenantContext post-auth).
 *
 * Email is globally unique for now. Multi-tenant same-email membership (one
 * human belonging to several tenants) is DEFERRED — see DEFERRED.md.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $tenant_id
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_confirmed_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * A super-admin has no tenant and operates at the platform level.
     */
    public function isSuperAdmin(): bool
    {
        return $this->tenant_id === null;
    }

    /**
     * Tenant staff belong to exactly one tenant.
     */
    public function isTenantStaff(): bool
    {
        return $this->tenant_id !== null;
    }

    /**
     * Whether the user holds a permission, optionally for a specific branch.
     * Delegates to the PermissionService (super-admin always true).
     */
    public function hasPermission(string $key, ?string $branchId = null): bool
    {
        return app(PermissionService::class)->has($this, $key, $branchId);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
