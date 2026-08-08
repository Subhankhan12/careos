<?php

use App\Services\StaffInviteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\StaffInvite;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Notifications\StaffInviteNotification;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * SETTINGS.P6 — staff invitations. An admin invites an email + a built-in role; accepting the
 * single-use, expiring, tenant-bound token provisions the User in that tenant via the REAL user +
 * RBAC path. RBAC stays reflect-only (no permission editing); the last-admin guard + tenant
 * isolation are untouched.
 */

function inv6Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function inv6Tenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    inv6Ctx()->set($tenant);

    return $tenant;
}

function inv6User(Tenant $tenant, string $roleKey): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    if ($roleKey !== '') {
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id]);
    }

    return $user;
}

function inv6RoleId(string $key): string
{
    return (string) Role::query()->where('key', $key)->value('id');
}

/** Create an invite through the real service and return [invite, plainToken]. */
function inv6Invite(Tenant $tenant, User $admin, string $email, string $roleKey): array
{
    inv6Ctx()->set($tenant);
    $result = app(StaffInviteService::class)->invite($email, inv6RoleId($roleKey), $admin);

    return [$result['invite'], $result['token']];
}

// ── Create ────────────────────────────────────────────────────────────────────

test('an admin creates a staff invite — pending, real role, audited, and an email is sent', function () {
    Notification::fake();
    $tenant = inv6Tenant();
    $admin = inv6User($tenant, 'org_admin');

    $this->actingAs($admin)
        ->post('/admin/invites', ['email' => 'new.nurse@example.test', 'role_id' => inv6RoleId('nurse')])
        ->assertRedirect('/admin/roles');

    inv6Ctx()->set($tenant);
    $invite = StaffInvite::query()->where('email', 'new.nurse@example.test')->firstOrFail();
    expect($invite->status)->toBe(StaffInvite::STATUS_PENDING)
        ->and($invite->role_id)->toBe(inv6RoleId('nurse'))
        ->and($invite->invited_by)->toBe($admin->id);

    Notification::assertSentOnDemand(StaffInviteNotification::class);

    $audited = DB::selectOne('SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?', [$tenant->id, 'staff_invite.created'])->c;
    expect((int) $audited)->toBe(1);
});

test('creating an invite is gated on admin.manage — a non-admin is 403', function () {
    Notification::fake();
    $tenant = inv6Tenant();
    $reception = inv6User($tenant, 'reception'); // no admin.manage

    $this->actingAs($reception)
        ->post('/admin/invites', ['email' => 'x@example.test', 'role_id' => inv6RoleId('nurse')])
        ->assertForbidden();
});

test('a duplicate pending invite and an existing-user email are rejected', function () {
    Notification::fake();
    $tenant = inv6Tenant();
    $admin = inv6User($tenant, 'org_admin');
    inv6Invite($tenant, $admin, 'dupe@example.test', 'nurse');

    // Second pending invite for the same email → validation error.
    $this->actingAs($admin)
        ->post('/admin/invites', ['email' => 'dupe@example.test', 'role_id' => inv6RoleId('nurse')])
        ->assertSessionHasErrors('email');

    // An email that already belongs to a user → rejected.
    $this->actingAs($admin)
        ->post('/admin/invites', ['email' => $admin->email, 'role_id' => inv6RoleId('nurse')])
        ->assertSessionHasErrors('email');
});

// ── Accept provisions via the REAL path ───────────────────────────────────────

test('accepting an invite provisions the user in the tenant with the invited role, via the real RBAC path', function () {
    Notification::fake();
    $tenant = inv6Tenant();
    $admin = inv6User($tenant, 'org_admin');
    [$invite, $token] = inv6Invite($tenant, $admin, 'accept.me@example.test', 'nurse');

    // The public accept page shows the invite as valid.
    $this->get("/invite/{$token}")->assertOk();

    $this->post("/invite/{$token}", [
        'name' => 'Accepted Nurse', 'password' => 'accept-password', 'password_confirmation' => 'accept-password',
    ])->assertRedirect('/app');

    // The user is provisioned in THIS tenant with the invited role (RoleAssignment = the real path).
    inv6Ctx()->set($tenant);
    $user = User::query()->where('email', 'accept.me@example.test')->firstOrFail();
    expect($user->tenant_id)->toBe($tenant->id)
        ->and(RoleAssignment::query()->where('user_id', $user->id)->where('role_id', inv6RoleId('nurse'))->exists())->toBeTrue()
        ->and($invite->fresh()->status)->toBe(StaffInvite::STATUS_ACCEPTED);

    // The real RBAC path auto-audited role.assigned.
    $roleAssigned = DB::selectOne('SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?', [$tenant->id, 'role.assigned'])->c;
    expect((int) $roleAssigned)->toBeGreaterThanOrEqual(1);
});

test('THE FENCE: an invite token is single-use — a consumed token can never provision again', function () {
    Notification::fake();
    $tenant = inv6Tenant();
    $admin = inv6User($tenant, 'org_admin');
    [, $token] = inv6Invite($tenant, $admin, 'once@example.test', 'nurse');

    // First accept (guest) provisions the user and logs them in.
    $this->post("/invite/{$token}", ['name' => 'Once', 'password' => 'accept-password', 'password_confirmation' => 'accept-password'])
        ->assertRedirect('/app');

    inv6Ctx()->set($tenant);
    expect(StaffInvite::query()->where('email', 'once@example.test')->firstOrFail()->status)->toBe(StaffInvite::STATUS_ACCEPTED);

    // Single-use is enforced at the service (the authoritative guard): the consumed token is refused.
    // (Over HTTP the now-logged-in invitee is bounced to 2FA enrollment before reaching accept — a
    // second *guest* use hits this same refusal.)
    expect(fn () => app(StaffInviteService::class)->accept($token, 'Twice', 'accept-password'))
        ->toThrow(ValidationException::class);

    expect(User::query()->where('email', 'once@example.test')->count())->toBe(1); // only one user ever created
});

test('an expired or revoked invite cannot be accepted', function () {
    Notification::fake();
    $tenant = inv6Tenant();
    $admin = inv6User($tenant, 'org_admin');

    // Expired.
    [$expired, $expiredToken] = inv6Invite($tenant, $admin, 'expired@example.test', 'nurse');
    inv6Ctx()->set($tenant);
    $expired->forceFill(['expires_at' => Carbon::now()->subDay()])->save();
    $this->post("/invite/{$expiredToken}", ['name' => 'E', 'password' => 'accept-password', 'password_confirmation' => 'accept-password'])
        ->assertSessionHasErrors('token');

    // Revoked (through the admin endpoint).
    [$revoked, $revokedToken] = inv6Invite($tenant, $admin, 'revoked@example.test', 'nurse');
    $this->actingAs($admin)->post("/admin/invites/{$revoked->id}/revoke")->assertRedirect('/admin/roles');
    $this->post("/invite/{$revokedToken}", ['name' => 'R', 'password' => 'accept-password', 'password_confirmation' => 'accept-password'])
        ->assertSessionHasErrors('token');

    inv6Ctx()->set($tenant);
    expect(User::query()->whereIn('email', ['expired@example.test', 'revoked@example.test'])->count())->toBe(0);
});

test('THE FENCE: an invite is tenant-bound — accept provisions only into its own tenant', function () {
    Notification::fake();
    $alpha = inv6Tenant('alpha');
    $alphaAdmin = inv6User($alpha, 'org_admin');
    [, $token] = inv6Invite($alpha, $alphaAdmin, 'bound@example.test', 'nurse');

    // A different tenant exists and is the "current" context — but accept resolves the tenant from
    // the token, so the user is provisioned into ALPHA regardless.
    $beta = inv6Tenant('beta');

    $this->post("/invite/{$token}", ['name' => 'Bound', 'password' => 'accept-password', 'password_confirmation' => 'accept-password'])
        ->assertRedirect('/app');

    inv6Ctx()->set($alpha);
    $user = User::query()->where('email', 'bound@example.test')->firstOrFail();
    expect($user->tenant_id)->toBe($alpha->id); // never beta
});

// ── Governance preserved ──────────────────────────────────────────────────────

test('the last-admin guard is intact — the sole org_admin still cannot be demoted', function () {
    $tenant = inv6Tenant();
    $admin = inv6User($tenant, 'org_admin'); // the only org_admin

    // Demoting the sole org_admin via the real assign endpoint is still blocked.
    $this->actingAs($admin)
        ->post('/admin/roles/assign', ['user_id' => $admin->id, 'role_id' => inv6RoleId('nurse')])
        ->assertSessionHasErrors('role');

    inv6Ctx()->set($tenant);
    expect(RoleAssignment::query()->where('user_id', $admin->id)->where('role_id', inv6RoleId('org_admin'))->exists())->toBeTrue();
});

test('RBAC stays reflect-only — there is no endpoint to edit a permission', function () {
    // The only role-write routes are template assignment + invites (which grant a template role).
    // There is deliberately no per-permission edit route — the catalog is read-only.
    expect(Route::has('admin.roles.permissions.update'))->toBeFalse()
        ->and(Route::has('admin.roles.permission.update'))->toBeFalse()
        ->and(Route::has('admin.permissions.update'))->toBeFalse();

    $roleWrites = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), 'admin/roles') && in_array('POST', $r->methods(), true))
        ->map(fn ($r) => $r->uri())
        ->values()
        ->all();

    // Only the role-template assignment writes under /admin/roles — no permission-cell write path.
    expect($roleWrites)->toBe(['admin/roles/assign']);
});
