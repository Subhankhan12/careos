<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function b6Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function b6Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function b6Role(string $key): Role
{
    return Role::where('key', $key)->firstOrFail();
}

function b6User(Tenant $tenant, string $role = 'doctor'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => b6Role($role)->id]);

    return $user;
}

function b6Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'date_of_birth' => '1980-05-10',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function b6ConsentTemplate(): ConsentTemplate
{
    return ConsentTemplate::create([
        'key' => 'portal',
        'title' => 'Portal Access',
        'body' => 'Portal access consent',
        'version' => 1,
        'scope_keys' => ['portal.access'],
        'is_active' => true,
    ]);
}

function b6ReadRows(string $tenantId, string $patientId): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? AND patient_id = ? ORDER BY occurred_at ASC',
        [$tenantId, 'read', $patientId],
    ));
}

test('patient index is RBAC gated tenant scoped and renders the Inertia component', function () {
    $a = b6Tenant('alpha');
    $b = b6Tenant('beta');

    b6Ctx()->set($a);
    $viewer = b6User($a, 'nurse');
    b6Patient(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

    b6Ctx()->set($b);
    b6Patient(['first_name' => 'Ada', 'last_name' => 'Beta']);

    b6Ctx()->set($a);

    $this->actingAs($viewer)
        ->get('/patients?q=Lovelace')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->has('patients', 1)
            ->where('patients.0.last_name', 'Lovelace'));

    $unprivileged = User::factory()->forTenant($a)->twoFactorEnabled()->create();

    $this->actingAs($unprivileged)->get('/patients')->assertForbidden();
});

test('registration wizard is edit gated and duplicate warnings surface through the detector endpoint', function () {
    $tenant = b6Tenant('alpha');
    b6Ctx()->set($tenant);
    $editor = b6User($tenant, 'doctor');
    $viewer = b6User($tenant, 'nurse');
    $existing = b6Patient([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'date_of_birth' => '1980-05-10',
    ]);

    $this->actingAs($editor)
        ->get('/patients/register')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Register')
            ->has('duplicateCheckUrl')
            ->has('storeUrl'));

    $this->actingAs($viewer)->get('/patients/register')->assertForbidden();

    $this->actingAs($editor)
        ->postJson('/patients/duplicates', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'date_of_birth' => '1980-05-10',
        ])
        ->assertOk()
        ->assertJsonPath('duplicates.0.id', $existing->id)
        ->assertJsonPath('duplicates.0.confidence', 'medium');

    $this->post('/patients', [
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'date_of_birth' => '1985-12-09',
        'sex' => 'female',
        'contacts' => [],
        'identifiers' => [],
        'coverages' => [],
    ])->assertRedirect();

    expect(Patient::where('last_name', 'Hopper')->exists())->toBeTrue();
});

test('portal invitation action is patient edit gated', function () {
    $tenant = b6Tenant('alpha');
    b6Ctx()->set($tenant);
    $editor = b6User($tenant, 'doctor');
    $viewer = b6User($tenant, 'nurse');
    $patient = b6Patient();
    b6ConsentTemplate();
    app(ConsentService::class)->grant($patient, 'portal', 'Ada Lovelace', $editor);

    $this->actingAs($viewer)
        ->postJson(route('portal.invitations.store'), [
            'patient_id' => $patient->id,
            'email' => 'ada@example.test',
        ])
        ->assertForbidden();
});

test('patient 360 is RBAC gated read logged and renders consents with access log', function () {
    $tenant = b6Tenant('alpha');
    b6Ctx()->set($tenant);
    $editor = b6User($tenant, 'doctor');
    $patient = b6Patient();
    b6ConsentTemplate();
    app(ConsentService::class)->grant($patient, 'portal', 'Ada Lovelace', $editor);

    $this->actingAs($editor)
        ->get(route('patients.show', $patient->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Show')
            ->where('patient.id', $patient->id)
            ->where('patient.consents.0.template_key', 'portal')
            ->has('accessLog', 1)
            ->where('actions.can_edit', true));

    expect(b6ReadRows($tenant->id, $patient->id))->toHaveCount(1);

    $unprivileged = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $this->actingAs($unprivileged)->get(route('patients.show', $patient->id))->assertForbidden();
});

test('patient 360 cannot cross tenant boundaries', function () {
    $a = b6Tenant('alpha');
    $b = b6Tenant('beta');

    b6Ctx()->set($a);
    $viewer = b6User($a, 'nurse');

    b6Ctx()->set($b);
    $patientB = b6Patient(['last_name' => 'Beta']);

    b6Ctx()->set($a);

    $this->actingAs($viewer)->get(route('patients.show', $patientB->id))->assertNotFound();
});
