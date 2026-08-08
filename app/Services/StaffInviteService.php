<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\StaffInvite;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Notifications\StaffInviteNotification;
use Modules\Platform\Services\TenantContext;

/**
 * Staff invitations (SETTINGS.P6) — the real invite → email → accept → provision flow, in the
 * application layer because it composes Platform (users/roles/tenant) with Audit and mail, and
 * Platform may not depend on Audit.
 *
 * Guarantees:
 *  - the invite grants a built-in ROLE TEMPLATE from the real catalog via the real RBAC path
 *    ({@see RoleAssignment::create}, which auto-audits `role.assigned`) — no permission editing,
 *    reflect-only RBAC preserved;
 *  - the token is single-use (pending → accepted), expiring, and tenant-bound (accept can only
 *    provision into the invite's own tenant);
 *  - accept uses the real User model + the existing mandatory-2FA onboarding (the enrollment
 *    middleware forces 2FA on first login);
 *  - the last-admin guard and tenant isolation are untouched (invites only ADD users).
 */
class StaffInviteService
{
    /** How long an invitation stays redeemable. */
    private const TTL_DAYS = 7;

    public function __construct(
        private readonly AuditService $audit,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Create a pending invite for the current tenant and email the accept link. Returns the invite
     * and the one-time plaintext token (never stored; only its hash is).
     *
     * @return array{invite: StaffInvite, token: string}
     *
     * @throws ValidationException
     */
    public function invite(string $email, string $roleId, User $invitedBy): array
    {
        $email = strtolower(trim($email));

        // Role must be one of THIS tenant's built-in templates (tenant-scoped → cross-tenant id
        // resolves to nothing). This is what keeps an invite to a real catalog role, never a
        // per-permission grant.
        $role = Role::query()->where('is_system', true)->whereKey($roleId)->first();
        if (! $role instanceof Role) {
            throw ValidationException::withMessages(['role_id' => 'Choose a role from the catalog.']);
        }

        // No duplicate pending invite for the same email in this tenant.
        if (StaffInvite::query()->where('email', $email)->where('status', StaffInvite::STATUS_PENDING)->exists()) {
            throw ValidationException::withMessages(['email' => 'There is already a pending invite for this email.']);
        }

        // Email is globally unique for a user — an existing account can't be re-provisioned.
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'A user with this email already exists.']);
        }

        return DB::transaction(function () use ($email, $role, $invitedBy): array {
            $plainToken = Str::random(64);

            $invite = StaffInvite::query()->create([
                'email' => $email,
                'role_id' => $role->id,
                'token_hash' => $this->hashToken($plainToken),
                'status' => StaffInvite::STATUS_PENDING,
                'invited_by' => $invitedBy->id,
                'expires_at' => Carbon::now()->addDays(self::TTL_DAYS),
            ]);

            $this->sendInviteEmail($invite, $plainToken, $role);

            $this->audit->record([
                'action' => 'staff_invite.created',
                'resource_type' => 'staff_invite',
                'resource_id' => $invite->id,
                'context' => ['email' => $email, 'role' => $role->key, 'expires_at' => $invite->expires_at->toISOString()],
            ]);

            return ['invite' => $invite, 'token' => $plainToken];
        });
    }

    /**
     * Re-issue a fresh token + expiry for a still-pending invite and re-send the email.
     *
     * @throws ValidationException
     */
    public function resend(StaffInvite $invite): void
    {
        if ($invite->status !== StaffInvite::STATUS_PENDING) {
            throw ValidationException::withMessages(['invite' => 'Only a pending invite can be resent.']);
        }

        $role = Role::query()->whereKey($invite->role_id)->firstOrFail();
        $plainToken = Str::random(64);

        DB::transaction(function () use ($invite, $plainToken, $role): void {
            $invite->forceFill([
                'token_hash' => $this->hashToken($plainToken),
                'expires_at' => Carbon::now()->addDays(self::TTL_DAYS),
            ])->save();

            $this->sendInviteEmail($invite, $plainToken, $role);

            $this->audit->record([
                'action' => 'staff_invite.resent',
                'resource_type' => 'staff_invite',
                'resource_id' => $invite->id,
                'context' => ['email' => $invite->email],
            ]);
        });
    }

    /** Revoke a pending invite — its token can no longer be redeemed. */
    public function revoke(StaffInvite $invite): void
    {
        if ($invite->status !== StaffInvite::STATUS_PENDING) {
            throw ValidationException::withMessages(['invite' => 'Only a pending invite can be revoked.']);
        }

        $invite->forceFill(['status' => StaffInvite::STATUS_REVOKED])->save();

        $this->audit->record([
            'action' => 'staff_invite.revoked',
            'resource_type' => 'staff_invite',
            'resource_id' => $invite->id,
            'context' => ['email' => $invite->email],
        ]);
    }

    /**
     * Redeem a token: provision the User in the invite's tenant with the invited role, via the real
     * user + RBAC path, and consume the token (single-use). No tenant context is assumed — the
     * invite (hence tenant) is resolved from the token, so a token can only provision into its own
     * tenant.
     *
     * @throws ValidationException
     */
    public function accept(string $plainToken, string $name, string $password): User
    {
        $hash = $this->hashToken($plainToken);

        // Resolve the invite with NO tenant context (a guest reaches this) — the token is the
        // tenant-bound secret, so the lookup runs unscoped, then the tenant is taken FROM the invite.
        $invite = $this->resolveByHash($hash);

        if (! $invite instanceof StaffInvite || ! $invite->isRedeemable()) {
            // Opportunistically mark a lapsed-but-pending invite expired.
            if ($invite instanceof StaffInvite && $invite->status === StaffInvite::STATUS_PENDING && $invite->expires_at->isPast()) {
                $this->withoutTenantContext(fn () => $this->tenants->system(fn () => $invite->forceFill(['status' => StaffInvite::STATUS_EXPIRED])->save()));
            }

            throw ValidationException::withMessages(['token' => 'This invitation is no longer valid.']);
        }

        $tenant = Tenant::query()->findOrFail($invite->tenant_id);
        $this->tenants->set($tenant);

        if (User::query()->where('email', $invite->email)->exists()) {
            throw ValidationException::withMessages(['email' => 'A user with this email already exists.']);
        }

        return DB::transaction(function () use ($invite, $name, $password): User {
            // The REAL user-creation path: the User model (password is hash-cast on the model).
            $user = User::query()->create([
                'name' => $name,
                'email' => $invite->email,
                'password' => $password,
            ]);
            $user->forceFill(['tenant_id' => $invite->tenant_id])->save();

            // The REAL RBAC path — RoleAssignment::create fires role.assigned (auto-audited). This
            // grants the built-in template role; it does NOT edit permissions.
            RoleAssignment::create(['user_id' => $user->id, 'role_id' => $invite->role_id, 'branch_id' => null]);

            $invite->forceFill(['status' => StaffInvite::STATUS_ACCEPTED, 'accepted_at' => Carbon::now()])->save();

            $this->audit->record([
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'action' => 'staff_invite.accepted',
                'resource_type' => 'staff_invite',
                'resource_id' => $invite->id,
                'context' => ['email' => $invite->email, 'user_id' => $user->id],
            ]);

            return $user;
        });
    }

    /**
     * Read-only context for the accept page (email + tenant + role) if the token is redeemable.
     *
     * @return array{email: string, tenantName: string, roleName: string}|null
     */
    public function preview(string $plainToken): ?array
    {
        $invite = $this->resolveByHash($this->hashToken($plainToken));

        if (! $invite instanceof StaffInvite || ! $invite->isRedeemable()) {
            return null;
        }

        // Tenant is NOT tenant-scoped, so it resolves without a context. Role IS tenant-scoped, so
        // resolve its name inside the invite's own tenant.
        $tenant = Tenant::query()->findOrFail($invite->tenant_id);
        $roleName = $this->inTenant($tenant, fn (): string => Role::query()->findOrFail($invite->role_id)->name);

        return [
            'email' => $invite->email,
            'tenantName' => $tenant->name,
            'roleName' => $roleName,
        ];
    }

    /** Resolve an invite by token hash with no tenant assumed — unscoped (the token is the secret). */
    private function resolveByHash(string $hash): ?StaffInvite
    {
        return $this->withoutTenantContext(fn () => $this->tenants->system(
            fn () => StaffInvite::query()->where('token_hash', $hash)->first()
        ));
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withoutTenantContext(callable $callback): mixed
    {
        $previous = $this->tenants->current();
        $this->tenants->forget();

        try {
            return $callback();
        } finally {
            if ($previous !== null) {
                $this->tenants->set($previous);
            }
        }
    }

    /**
     * Run a callback with the tenant context set to $tenant, restoring the previous context after.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function inTenant(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenants->current();
        $this->tenants->set($tenant);

        try {
            return $callback();
        } finally {
            $previous !== null ? $this->tenants->set($previous) : $this->tenants->forget();
        }
    }

    private function sendInviteEmail(StaffInvite $invite, string $plainToken, Role $role): void
    {
        $tenant = Tenant::query()->findOrFail($invite->tenant_id);

        Notification::route('mail', $invite->email)->notify(new StaffInviteNotification(
            route('staff-invite.show', ['token' => $plainToken]),
            $tenant->name,
            $role->name,
        ));
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
