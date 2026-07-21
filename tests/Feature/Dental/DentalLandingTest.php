<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * DENTAL.G9 — the dental section landing (a patient picker) makes the dental vertical
 * REACHABLE from the top nav. Presentational (P0D.GU): it lists the tenant's patients and
 * links each to that patient's odontogram. These tests prove:
 *   - it is role-gated on dental.chart (a non-dental role does NOT get it — the same gate as
 *     the top-nav Dental entry), server-authoritative by URL;
 *   - the shared permissions carry dental.chart so the client nav can gate the link;
 *   - each row's cross-link resolves to the right patient's dental chart, tenant-scoped.
 */

function dlCtx(): TenantContext
{
    return app(TenantContext::class);
}

function dlUser(Tenant $tenant, string $role): User
{
    dlCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

function dlTenant(string $slug): Tenant
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Dental', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    dlCtx()->set($tenant);

    return $tenant;
}

test('the dental landing lists the tenant patients, each linked to their odontogram', function () {
    $tenant = dlTenant('alpha');
    $dentist = dlUser($tenant, 'doctor'); // holds dental.chart + patient.view
    $patient = app(PatientService::class)->create(['first_name' => 'Tom', 'last_name' => 'Tooth', 'date_of_birth' => '1990-03-03', 'sex' => 'male']);

    dlCtx()->forget();

    $this->actingAs($dentist)->get('/dental')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dental/Index')
            ->where('patients.0.id', $patient->id)
            ->where('patients.0.chart_url', route('dental.chart', $patient->id))
            ->where('can_manage_fees', false) // a doctor is not billing.manage
        );
});

test('the dental landing is role-gated on dental.chart — a non-dental role is denied by URL', function () {
    $tenant = dlTenant('beta');

    // The dentist roles reach it.
    foreach (['org_admin', 'doctor'] as $role) {
        $user = dlUser($tenant, $role);
        dlCtx()->forget();
        $this->actingAs($user)->get('/dental')->assertOk();
    }

    // Non-dental roles are refused, even though reception holds patient.view (it lacks
    // dental.chart, so it neither sees the nav entry nor reaches the URL).
    foreach (['reception', 'billing'] as $role) {
        $user = dlUser($tenant, $role);
        dlCtx()->forget();
        $this->actingAs($user)->get('/dental')->assertForbidden();
    }
});

test('the shared permissions carry dental.chart so the nav can gate the Dental link', function () {
    $tenant = dlTenant('gamma');
    $dentist = dlUser($tenant, 'doctor');
    $reception = dlUser($tenant, 'reception');
    app(PatientService::class)->create(['first_name' => 'Ida', 'last_name' => 'Incisor', 'date_of_birth' => '1985-01-01', 'sex' => 'female']);

    // The dentist gets dental.chart = true (the client shows the Dental nav entry).
    // The permission key itself contains a dot, so inspect the shared permissions array
    // directly rather than through a dotted assertion path.
    dlCtx()->forget();
    $this->actingAs($dentist)->get('/dental')
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.permissions', fn ($permissions) => ($permissions['dental.chart'] ?? null) === true));

    // Reception (no dental.chart) gets it = false, so the client hides the Dental nav entry.
    // Reception can still reach a page it is allowed (patients) to read the shared prop.
    dlCtx()->forget();
    $this->actingAs($reception)->get('/patients')
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.permissions', fn ($permissions) => ($permissions['dental.chart'] ?? null) === false));
});

test('the dental landing is tenant-scoped: only the acting tenant patients appear', function () {
    $alpha = dlTenant('alpha2');
    $alphaDentist = dlUser($alpha, 'doctor');
    app(PatientService::class)->create(['first_name' => 'Alice', 'last_name' => 'Alpha', 'date_of_birth' => '1980-01-01', 'sex' => 'female']);

    $beta = dlTenant('beta2');
    dlUser($beta, 'doctor');
    app(PatientService::class)->create(['first_name' => 'Bob', 'last_name' => 'Beta', 'date_of_birth' => '1981-01-01', 'sex' => 'male']);

    dlCtx()->forget();

    $this->actingAs($alphaDentist)->get('/dental')
        ->assertInertia(fn (Assert $page) => $page
            ->has('patients', 1)
            ->where('patients.0.name', 'Alice Alpha')
        );
});
