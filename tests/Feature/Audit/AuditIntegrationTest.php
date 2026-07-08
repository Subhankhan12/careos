<?php

use App\Services\BreakGlassService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Tests\Support\ReadProbe;

uses(RefreshDatabase::class);

function auditSvc(): AuditService
{
    return app(AuditService::class);
}

function integrationTenant(string $slug = 'alpha'): Tenant
{
    return Tenant::create(['name' => 'Alpha', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
}

function rowsFor(?string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

// (a) audited actions -> exactly one row, correct actor, chain valid ----------

test('login success writes exactly one auth.login row with the user as actor', function () {
    $tenant = integrationTenant();
    $user = User::factory()->forTenant($tenant)->create();

    Auth::login($user);

    $rows = rowsFor($tenant->id, 'auth.login');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->actor_type)->toBe('user')
        ->and($rows[0]->actor_id)->toBe((string) $user->id)
        ->and(auditSvc()->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('login failure writes exactly one auth.login_failed row', function () {
    $tenant = integrationTenant();
    $user = User::factory()->forTenant($tenant)->create();

    Auth::attempt(['email' => $user->email, 'password' => 'wrong-password']);

    expect(rowsFor(null, 'auth.login_failed'))->toHaveCount(1)
        ->and(auditSvc()->verifyChain(null)['ok'])->toBeTrue();
});

test('logout writes exactly one auth.logout row', function () {
    $tenant = integrationTenant();
    $user = User::factory()->forTenant($tenant)->create();

    Auth::login($user);
    Auth::logout();

    expect(rowsFor($tenant->id, 'auth.logout'))->toHaveCount(1)
        ->and(auditSvc()->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('assigning a role writes exactly one role.assigned row and keeps the chain valid', function () {
    $tenant = integrationTenant();
    app(TenantContext::class)->set($tenant);
    $user = User::factory()->forTenant($tenant)->create();

    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', 'doctor')->value('id')]);

    expect(rowsFor($tenant->id, 'role.assigned'))->toHaveCount(1)
        ->and(auditSvc()->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('a tenant status change writes exactly one tenant.status_changed row', function () {
    $tenant = integrationTenant();

    $tenant->status = 'suspended';
    $tenant->save();

    $rows = rowsFor($tenant->id, 'tenant.status_changed');
    expect($rows)->toHaveCount(1)
        ->and(json_decode($rows[0]->context, true)['status'])->toBe('suspended');
});

test('provisioning a tenant does not pollute the audit chain (system mode is skipped)', function () {
    $tenant = integrationTenant();

    // The seeded starter roles are created in system mode → no audit rows.
    expect(rowsFor($tenant->id, 'role.changed'))->toHaveCount(0);
});

// (b) read-logging ------------------------------------------------------------

test('recordRead / LogsReads produce a read audit row', function () {
    auditSvc()->recordRead('patient', 'PAT-1', 'PAT-1');

    $direct = rowsFor(null, 'read');
    expect($direct)->toHaveCount(1)
        ->and($direct[0]->resource_type)->toBe('patient')
        ->and($direct[0]->patient_id)->toBe('PAT-1');

    $probe = new ReadProbe;
    $probe->id = 'RES-1';
    $probe->auditRead();

    $viaTrait = collect(DB::select(
        "SELECT * FROM audit_events WHERE action = 'read' AND resource_type = 'probe'"
    ));
    expect($viaTrait)->toHaveCount(1)
        ->and($viaTrait[0]->resource_id)->toBe('RES-1');
});

// (c) break-glass -------------------------------------------------------------

test('break-glass requires a reason', function () {
    $tenant = integrationTenant();
    app(TenantContext::class)->set($tenant);
    $user = User::factory()->forTenant($tenant)->create();

    expect(fn () => app(BreakGlassService::class)->request($user, 'patient:P1', '   ', 300))
        ->toThrow(InvalidArgumentException::class);
});

test('requesting break-glass creates a grant and a flagged audit event', function () {
    $tenant = integrationTenant();
    app(TenantContext::class)->set($tenant);
    $user = User::factory()->forTenant($tenant)->create();

    $grant = app(BreakGlassService::class)->request($user, 'patient:P1', 'Emergency access', 300);

    expect($grant->reason)->toBe('Emergency access')
        ->and($grant->tenant_id)->toBe($tenant->id);

    $req = rowsFor($tenant->id, 'break_glass.request');
    expect($req)->toHaveCount(1)
        ->and(json_decode($req[0]->context, true)['break_glass'])->toBeTrue();
});

test('an active grant is honored and its use can be flagged; an expired grant is not honored', function () {
    $tenant = integrationTenant();
    app(TenantContext::class)->set($tenant);
    $user = User::factory()->forTenant($tenant)->create();
    $breakGlass = app(BreakGlassService::class);

    $breakGlass->request($user, 'patient:P1', 'Emergency access', 300);

    expect($breakGlass->isActive($user, 'patient:P1'))->toBeTrue();

    // An access performed under the active grant is audit-flagged.
    $active = $breakGlass->isActive($user, 'patient:P1');
    $event = auditSvc()->record([
        'action' => 'read',
        'resource_type' => 'patient',
        'resource_id' => 'P1',
        'patient_id' => 'P1',
        'context' => ['break_glass' => $active],
    ]);
    expect($event->context['break_glass'])->toBeTrue();

    // Past its TTL, the grant is no longer honored.
    $this->travel(301)->seconds();
    expect($breakGlass->isActive($user, 'patient:P1'))->toBeFalse();
});
