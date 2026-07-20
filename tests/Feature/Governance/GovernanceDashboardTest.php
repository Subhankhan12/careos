<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AiCore\Services\AiInteractionRecorder;
use Modules\Audit\Exceptions\AuditEventImmutableException;
use Modules\Audit\Models\AuditEvent;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\ReconciliationRun;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * CLINIC.W9 — the governance dashboard is a STRICTLY READ-ONLY oversight window over the
 * tested audit / integrity / reconciliation / AI-usage backends. These tests prove it is
 * read-only (no mutation path; 'verify now' writes nothing), shows the REAL status from the
 * existing checks, is permission-gated on audit.view, and is tenant-scoped — the last is the
 * critical one, because AuditEvent has no BelongsToTenant scope, so the controller must
 * filter tenant_id explicitly.
 */

function g9Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function g9Tenant(string $slug): Tenant
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    g9Ctx()->set($tenant);

    return $tenant;
}

function g9User(Tenant $tenant, string $role): User
{
    g9Ctx()->set($tenant); // the Role/RoleAssignment models are tenant-scoped
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

test('governance dashboard is audit.view gated and renders the real chain/reconciliation status', function () {
    $tenant = g9Tenant('alpha');
    $admin = g9User($tenant, 'org_admin');

    // Real reconciliation evidence + an AI interaction the dashboard should reflect.
    ReconciliationRun::query()->create(['period' => '2026-06', 'ran_at' => now(), 'passed' => true, 'report' => ['passed' => true], 'ran_by' => $admin->id]);
    app(AiInteractionRecorder::class)->record('demo.echo', 'demo-agent', 'internal', 'demo', '1', 'hash', 'executed', costMinor: 250);

    g9Ctx()->forget();
    $this->actingAs($admin)
        ->get(route('governance.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Governance/Dashboard')
            ->where('chain.ok', true)
            ->where('reconciliation.passed', true)
            ->where('reconciliation.period', '2026-06')
            ->where('ai.total', 1)
            ->has('activity')
            ->has('security')
            ->has('queue'));

    // reception holds neither audit.view nor ai.manage → denied.
    g9Ctx()->forget();
    $this->actingAs(g9User($tenant, 'reception'))->get(route('governance.dashboard'))->assertForbidden();
});

test('the dashboard is tenant-scoped: audit activity never leaks across tenants', function () {
    $alpha = g9Tenant('alpha');
    $alphaAdmin = g9User($alpha, 'org_admin');
    app(AuditService::class)->record(['action' => 'g9.alpha.marker', 'actor_type' => 'user', 'actor_id' => (string) $alphaAdmin->id]);

    // Switch context to a second tenant and record a distinct marker there.
    $beta = g9Tenant('beta');
    $betaAdmin = g9User($beta, 'org_admin');
    app(AuditService::class)->record(['action' => 'g9.beta.marker', 'actor_type' => 'user', 'actor_id' => (string) $betaAdmin->id]);

    // As alpha's admin, the activity feed contains alpha's marker and NONE of beta's.
    g9Ctx()->forget();
    $this->actingAs($alphaAdmin)
        ->get(route('governance.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activity', fn ($activity) => collect($activity)->pluck('action')->contains('g9.alpha.marker')
                && ! collect($activity)->pluck('action')->contains('g9.beta.marker')));
});

test("'verify now' re-runs the existing verification and writes nothing (read-only, no mutation path)", function () {
    $tenant = g9Tenant('alpha');
    $admin = g9User($tenant, 'org_admin');
    app(AuditService::class)->record(['action' => 'g9.marker', 'actor_type' => 'user', 'actor_id' => (string) $admin->id]);

    $before = AuditEvent::query()->where('tenant_id', $tenant->id)->count();

    g9Ctx()->forget();
    $this->actingAs($admin)
        ->post(route('governance.verify-chain'))
        ->assertRedirect(route('governance.dashboard'))
        ->assertSessionHas('status', 'chain_ok');

    // The verification appended nothing: the append-only chain is unchanged.
    expect(AuditEvent::query()->where('tenant_id', $tenant->id)->count())->toBe($before);

    // There is no route to edit or delete an audit event — the surface exposes reads only.
    expect(Route::has('governance.audit.update'))->toBeFalse()
        ->and(Route::has('governance.audit.destroy'))->toBeFalse();

    // And the audit record itself is immutable at the model layer (defence in depth).
    $event = AuditEvent::query()->where('tenant_id', $tenant->id)->firstOrFail();
    expect(fn () => $event->update(['action' => 'tampered']))->toThrow(AuditEventImmutableException::class);
});

test('a viewer without audit.view cannot reach the dashboard even by URL', function () {
    $tenant = g9Tenant('alpha');

    // A billing user has billing.view but not audit.view → fail closed.
    g9Ctx()->forget();
    $this->actingAs(g9User($tenant, 'billing'))->get(route('governance.dashboard'))->assertForbidden();
});
