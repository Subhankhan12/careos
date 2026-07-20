<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentResource;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AvailableSlotFinder;

uses(RefreshDatabase::class);

/*
 * CLINIC.W8c — bookable-resource CRUD closes the W8b gap: a self-service branch can now
 * get rooms/chairs/vehicles and become bookable. Writes go through the app-layer
 * ResourceService, are admin.manage-gated, tenant+branch scoped, and audited. The
 * safety-critical rule mirrors branch deactivation: a resource with future appointments
 * cannot be deactivated (scheduled care is never orphaned). MONDAY is 2026-07-13.
 */

function rcTenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

function rcUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

function rcBranch(string $code = 'MAIN'): Branch
{
    return Branch::create(['name' => $code.' Branch', 'code' => $code, 'timezone' => 'Europe/Zurich']);
}

function rcResource(Branch $branch, string $name = 'Room 1', string $type = BookableResource::TYPE_ROOM): BookableResource
{
    return BookableResource::create(['type' => $type, 'name' => $name, 'branch_id' => $branch->id, 'active' => true]);
}

function rcAudit(Tenant $tenant, string $action): int
{
    return (int) DB::selectOne('SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?', [$tenant->id, $action])->c;
}

/** A future, blocking-status appointment that consumes the given resource (via the pivot). */
function rcFutureAppointment(Branch $branch, BookableResource $resource): Appointment
{
    $service = Service::create([
        'name' => 'Consult', 'code' => 'CONS', 'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
        'requires_resource_types' => [$resource->type], 'bookable_online' => true, 'active' => true,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Pat', 'last_name' => 'Future', 'date_of_birth' => '1980-01-01', 'sex' => 'female']);
    $appointment = Appointment::create([
        'service_id' => $service->id, 'branch_id' => $branch->id, 'patient_id' => $patient->id,
        'starts_at' => now()->addDays(3)->setTime(10, 0)->toDateTimeString(),
        'ends_at' => now()->addDays(3)->setTime(10, 30)->toDateTimeString(),
        'status' => Appointment::STATUS_BOOKED, 'source' => 'staff',
    ]);
    AppointmentResource::create(['appointment_id' => $appointment->id, 'resource_id' => $resource->id]);

    return $appointment;
}

// ── Create / edit ─────────────────────────────────────────────────────────────

test('an admin creates a resource under a branch — audited, active, branch-scoped', function () {
    $tenant = rcTenant();
    $admin = rcUser($tenant, 'org_admin');
    $branch = rcBranch();
    $before = rcAudit($tenant, 'resource.created');

    $this->actingAs($admin)
        ->post("/admin/branches/{$branch->id}/resources", ['name' => 'Treatment Room A', 'type' => 'room'])
        ->assertRedirect('/admin/branches');

    app(TenantContext::class)->set($tenant);
    $resource = BookableResource::query()->where('name', 'Treatment Room A')->firstOrFail();
    expect($resource->active)->toBeTrue()
        ->and($resource->type)->toBe('room')
        ->and($resource->branch_id)->toBe($branch->id)
        ->and(rcAudit($tenant, 'resource.created') - $before)->toBe(1);
});

test('resource create rejects the practitioner type and blank names', function () {
    $tenant = rcTenant();
    $admin = rcUser($tenant, 'org_admin');
    $branch = rcBranch();

    // practitioner is staff-profile driven, not admin-creatable here.
    $this->actingAs($admin)
        ->post("/admin/branches/{$branch->id}/resources", ['name' => 'Dr X', 'type' => 'practitioner'])
        ->assertSessionHasErrors('type');

    $this->actingAs($admin)
        ->post("/admin/branches/{$branch->id}/resources", ['name' => '', 'type' => 'room'])
        ->assertSessionHasErrors('name');
});

test('an admin edits a resource — audited', function () {
    $tenant = rcTenant();
    $admin = rcUser($tenant, 'org_admin');
    $branch = rcBranch();
    $resource = rcResource($branch, 'Old Name', 'room');
    $before = rcAudit($tenant, 'resource.updated');

    $this->actingAs($admin)
        ->post("/admin/resources/{$resource->id}/update", ['name' => 'Chair 2', 'type' => 'chair'])
        ->assertRedirect('/admin/branches');

    app(TenantContext::class)->set($tenant);
    $resource->refresh();
    expect($resource->name)->toBe('Chair 2')
        ->and($resource->type)->toBe('chair')
        ->and(rcAudit($tenant, 'resource.updated') - $before)->toBe(1);
});

// ── Booking integration (end-to-end) ──────────────────────────────────────────

test('a new branch + active resource becomes bookable end-to-end; deactivating it removes it', function () {
    $tenant = rcTenant();
    $admin = rcUser($tenant, 'org_admin');

    // A brand-new branch created through the admin surface (W8b) …
    $this->actingAs($admin)
        ->post('/admin/branches', ['name' => 'Oerlikon', 'code' => 'OER', 'timezone' => 'Europe/Zurich'])
        ->assertRedirect('/admin/branches');
    app(TenantContext::class)->set($tenant);
    $branch = Branch::query()->where('code', 'OER')->firstOrFail();

    // … now gets a room through the new resource CRUD.
    $this->actingAs($admin)
        ->post("/admin/branches/{$branch->id}/resources", ['name' => 'Room 1', 'type' => 'room'])
        ->assertRedirect('/admin/branches');
    app(TenantContext::class)->set($tenant);
    $resource = BookableResource::query()->where('name', 'Room 1')->firstOrFail();

    // The resource is immediately selectable on the day-board for its branch.
    $this->actingAs($admin)
        ->get("/scheduling/day-board?branch_id={$branch->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('resources', fn ($r) => collect($r)->contains('id', $resource->id)));

    // With availability configured (the existing per-resource mechanism) + a service that
    // needs a room, the slot finder now offers this branch — i.e. it is bookable.
    ResourceAvailability::create(['resource_id' => $resource->id, 'weekday' => 1, 'start_time' => '07:00', 'end_time' => '19:00']);
    $service = Service::create([
        'name' => 'Room Consult', 'code' => 'RCON', 'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
        'requires_resource_types' => ['room'], 'bookable_online' => true, 'active' => true,
    ]);
    expect(app(AvailableSlotFinder::class)->forServiceBranchDate($service, $branch->id, '2026-07-13'))->not->toBe([]);

    // Deactivate the resource → it drops out of the day-board AND the slot finder.
    $this->actingAs($admin)
        ->post("/admin/resources/{$resource->id}/deactivate")
        ->assertRedirect('/admin/branches');
    app(TenantContext::class)->set($tenant);

    expect($resource->refresh()->active)->toBeFalse()
        ->and(app(AvailableSlotFinder::class)->forServiceBranchDate($service, $branch->id, '2026-07-13'))->toBe([]);

    $this->actingAs($admin)
        ->get("/scheduling/day-board?branch_id={$branch->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('resources', fn ($r) => ! collect($r)->contains('id', $resource->id)));
});

// ── Deactivation guard (SAFETY) ───────────────────────────────────────────────

test('deactivating a resource with a future appointment is BLOCKED', function () {
    $tenant = rcTenant();
    $admin = rcUser($tenant, 'org_admin');
    $branch = rcBranch('BUSY');
    $resource = rcResource($branch, 'Busy Room', 'room');
    rcFutureAppointment($branch, $resource);

    $this->actingAs($admin)
        ->post("/admin/resources/{$resource->id}/deactivate")
        ->assertRedirect()
        ->assertSessionHasErrors('resource');

    app(TenantContext::class)->set($tenant);
    expect($resource->refresh()->active)->toBeTrue(); // still active — nothing orphaned
});

test('deactivating an empty resource works and is audited', function () {
    $tenant = rcTenant();
    $admin = rcUser($tenant, 'org_admin');
    $branch = rcBranch();
    $resource = rcResource($branch, 'Spare Chair', 'chair');
    $before = rcAudit($tenant, 'resource.deactivated');

    $this->actingAs($admin)
        ->post("/admin/resources/{$resource->id}/deactivate")
        ->assertRedirect('/admin/branches');

    app(TenantContext::class)->set($tenant);
    expect($resource->refresh()->active)->toBeFalse()
        ->and(rcAudit($tenant, 'resource.deactivated') - $before)->toBe(1);

    // …and reactivation brings it back.
    $this->actingAs($admin)
        ->post("/admin/resources/{$resource->id}/activate")
        ->assertRedirect('/admin/branches');
    app(TenantContext::class)->set($tenant);
    expect($resource->refresh()->active)->toBeTrue();
});

// ── RBAC + tenancy ────────────────────────────────────────────────────────────

test('resource CRUD is admin.manage gated', function () {
    $tenant = rcTenant();
    $branch = rcBranch();
    $resource = rcResource($branch);

    $this->actingAs(rcUser($tenant, 'reception'))->post("/admin/branches/{$branch->id}/resources", ['name' => 'X', 'type' => 'room'])->assertForbidden();
    $this->actingAs(rcUser($tenant, 'reception'))->post("/admin/resources/{$resource->id}/deactivate")->assertForbidden();
});

test('cross-tenant resource update / deactivate is 404', function () {
    $alpha = rcTenant('alpha');
    $alphaAdmin = rcUser($alpha, 'org_admin');
    $beta = rcTenant('beta');
    $betaResource = rcResource(rcBranch('BETA'), 'Beta Room');

    $this->actingAs($alphaAdmin)->post("/admin/resources/{$betaResource->id}/update", ['name' => 'X', 'type' => 'room'])->assertNotFound();
    $this->actingAs($alphaAdmin)->post("/admin/resources/{$betaResource->id}/deactivate")->assertNotFound();
});
