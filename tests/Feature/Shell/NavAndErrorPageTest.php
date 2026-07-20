<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * FIX.4 — M-4 shares the nav-relevant permissions on auth.user.permissions so the staff
 * shell can hide links a role can't use; the server Gate stays authoritative (a hidden
 * route is still blocked, a shown one still 403s). M-5 renders staff 403s as an in-shell
 * Eucalyptus Glow Error page instead of the bare Symfony page — PRESENTATION ONLY, the
 * status code (and thus the authorization decision) is unchanged.
 */

function neTenant(string $slug = 'nav'): Tenant
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

function neUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

test('the shell shares nav permissions so a role only sees links it can use', function () {
    $tenant = neTenant();

    // reception: patient.view + appointment.manage + comms.manage; NOT dispatch/billing/
    // reporting, and NOT audit.view/ai.manage (governance + AI approval-queue are W9 admin-only).
    $this->actingAs(neUser($tenant, 'reception'))
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.permissions', [
                'patient.view' => true,
                'appointment.manage' => true,
                'dispatch.manage' => false,
                'comms.manage' => true,
                'billing.view' => false,
                'reporting.view' => false,
                'audit.view' => false,
                'ai.manage' => false,
                'admin.manage' => false,
            ]));

    // org_admin holds every nav permission.
    $this->actingAs(neUser($tenant, 'org_admin'))
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.permissions', [
                'patient.view' => true,
                'appointment.manage' => true,
                'dispatch.manage' => true,
                'comms.manage' => true,
                'billing.view' => true,
                'reporting.view' => true,
                'audit.view' => true,
                'ai.manage' => true,
                'admin.manage' => true,
            ]));
});

test('a staff 403 renders the styled in-shell error page, status preserved', function () {
    $tenant = neTenant();
    $reception = neUser($tenant, 'reception'); // lacks billing.view -> /billing/invoices 403s

    // The exception->Inertia renderer intentionally no-ops under `testing` so the suite's
    // status assertions stay exact; force a runtime env to exercise the presentation layer.
    app()->detectEnvironment(fn () => 'production');

    $this->actingAs($reception)
        ->get('/billing/invoices')
        ->assertStatus(403)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('status', 403)
            ->where('context', 'staff'));
});

test('server RBAC stays authoritative: the route is still blocked by URL under the test env', function () {
    $tenant = neTenant();
    $reception = neUser($tenant, 'reception');

    // No env flip: under `testing` the raw 403 is returned (proving the denial itself is
    // unchanged — M-5 only restyles an already-decided response).
    $this->actingAs($reception)
        ->get('/billing/invoices')
        ->assertForbidden();
});
