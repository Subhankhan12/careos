<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\BranchHours;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Service;

uses(RefreshDatabase::class);

/*
 * CLINIC.W8b — editable practice profile + branch CRUD (with opening hours) built over
 * new backends. Profile writes tenant columns + settings; branch writes go through the
 * app-layer BranchService; both admin.manage-gated, tenant-scoped, audited. The
 * safety-critical rule: a branch with future appointments cannot be deactivated.
 */

function w8bTenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

function w8bUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

function w8bBranch(string $code = 'MAIN'): Branch
{
    return Branch::create(['name' => $code.' Branch', 'code' => $code, 'timezone' => 'Europe/Zurich']);
}

function w8bAudit(Tenant $tenant, string $action): int
{
    return (int) DB::selectOne('SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?', [$tenant->id, $action])->c;
}

function w8bClosedWeek(): array
{
    return collect(range(0, 6))->map(fn (int $wd): array => ['weekday' => $wd, 'is_closed' => true, 'open_time' => null, 'close_time' => null])->all();
}

// ── Practice profile ──────────────────────────────────────────────────────────

test('an admin edits the practice profile — tenant columns + settings, audited', function () {
    $tenant = w8bTenant();
    $admin = w8bUser($tenant, 'org_admin');
    $before = w8bAudit($tenant, 'tenant.profile_updated');

    $this->actingAs($admin)
        ->post('/settings/profile', [
            'name' => 'Praxis Lindenhof AG',
            'contact_email' => 'desk@lindenhof.test',
            'contact_phone' => '+41 44 350 60 60',
            'address_line1' => 'Bahnhofstrasse 1',
            'city' => 'Zürich',
            'postal_code' => '8001',
            'country' => 'ch',
            'locale' => 'de',
            'timezone' => 'Europe/Zurich',
        ])
        ->assertRedirect('/settings');

    $tenant->refresh();
    expect($tenant->name)->toBe('Praxis Lindenhof AG')
        ->and($tenant->contact_email)->toBe('desk@lindenhof.test')
        ->and($tenant->country)->toBe('CH') // upper-cased
        ->and(w8bAudit($tenant, 'tenant.profile_updated') - $before)->toBe(1);

    app(TenantContext::class)->set($tenant);
    expect(app(SettingsService::class)->get('locale'))->toBe('de')
        ->and(app(SettingsService::class)->get('timezone'))->toBe('Europe/Zurich');
});

test('profile edit is admin.manage gated and validated', function () {
    $tenant = w8bTenant();

    $this->actingAs(w8bUser($tenant, 'reception'))
        ->post('/settings/profile', ['name' => 'X', 'locale' => 'en', 'timezone' => 'UTC'])
        ->assertForbidden();

    $this->actingAs(w8bUser($tenant, 'org_admin'))
        ->post('/settings/profile', ['name' => 'X', 'contact_email' => 'not-an-email', 'locale' => 'en', 'timezone' => 'UTC'])
        ->assertSessionHasErrors('contact_email');
});

// ── Branch CRUD ───────────────────────────────────────────────────────────────

test('an admin creates a branch — audited, active, and selectable on the day-board', function () {
    $tenant = w8bTenant();
    $admin = w8bUser($tenant, 'org_admin');
    $before = w8bAudit($tenant, 'branch.created');

    $this->actingAs($admin)
        ->post('/admin/branches', ['name' => 'Oerlikon', 'code' => 'OER', 'timezone' => 'Europe/Zurich'])
        ->assertRedirect('/admin/branches');

    app(TenantContext::class)->set($tenant);
    $branch = Branch::query()->where('code', 'OER')->firstOrFail();
    expect($branch->active)->toBeTrue()
        ->and(w8bAudit($tenant, 'branch.created') - $before)->toBe(1);

    // A new active branch appears on the staff day-board.
    $this->actingAs($admin)
        ->get('/scheduling/day-board')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('branches', fn ($branches) => collect($branches)->contains('id', $branch->id)));
});

test('branch code must be unique within the tenant', function () {
    $tenant = w8bTenant();
    $admin = w8bUser($tenant, 'org_admin');
    w8bBranch('DUP');

    $this->actingAs($admin)
        ->post('/admin/branches', ['name' => 'Another', 'code' => 'DUP', 'timezone' => 'UTC'])
        ->assertSessionHasErrors('code');
});

test('branch CRUD is admin.manage gated', function () {
    $tenant = w8bTenant();
    $branch = w8bBranch();

    $this->actingAs(w8bUser($tenant, 'reception'))->get('/admin/branches')->assertForbidden();
    $this->actingAs(w8bUser($tenant, 'reception'))->post('/admin/branches', ['name' => 'X', 'code' => 'X', 'timezone' => 'UTC'])->assertForbidden();
    $this->actingAs(w8bUser($tenant, 'reception'))->post("/admin/branches/{$branch->id}/deactivate")->assertForbidden();
});

// ── Deactivation guard (SAFETY) ───────────────────────────────────────────────

test('deactivating a branch with future appointments is BLOCKED', function () {
    $tenant = w8bTenant();
    $admin = w8bUser($tenant, 'org_admin');
    $branch = w8bBranch('BUSY');

    $service = Service::create([
        'name' => 'Consult', 'code' => 'CONS', 'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
        'requires_resource_types' => ['practitioner'], 'bookable_online' => true, 'active' => true,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Pat', 'last_name' => 'Future', 'date_of_birth' => '1980-01-01', 'sex' => 'female']);
    Appointment::create([
        'service_id' => $service->id, 'branch_id' => $branch->id, 'patient_id' => $patient->id,
        'starts_at' => now()->addDays(3)->setTime(10, 0)->toDateTimeString(),
        'ends_at' => now()->addDays(3)->setTime(10, 30)->toDateTimeString(),
        'status' => Appointment::STATUS_BOOKED, 'source' => 'staff',
    ]);

    $this->actingAs($admin)
        ->post("/admin/branches/{$branch->id}/deactivate")
        ->assertRedirect()
        ->assertSessionHasErrors('branch');

    app(TenantContext::class)->set($tenant);
    expect($branch->refresh()->active)->toBeTrue(); // still active — nothing orphaned
});

test('deactivating an empty branch works, is audited, and removes it from booking', function () {
    $tenant = w8bTenant();
    $admin = w8bUser($tenant, 'org_admin');
    w8bBranch('KEEP'); // a second active branch so the day-board still resolves
    $branch = w8bBranch('GONE');
    $before = w8bAudit($tenant, 'branch.deactivated');

    $this->actingAs($admin)
        ->post("/admin/branches/{$branch->id}/deactivate")
        ->assertRedirect('/admin/branches');

    app(TenantContext::class)->set($tenant);
    expect($branch->refresh()->active)->toBeFalse()
        ->and(w8bAudit($tenant, 'branch.deactivated') - $before)->toBe(1);

    // The deactivated branch is no longer selectable on the day-board.
    $this->actingAs($admin)
        ->get('/scheduling/day-board')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('branches', fn ($branches) => ! collect($branches)->contains('id', $branch->id)));
});

test('cross-tenant branch update / deactivate is 404', function () {
    $alpha = w8bTenant('alpha');
    $alphaAdmin = w8bUser($alpha, 'org_admin');
    $beta = w8bTenant('beta');
    $betaBranch = w8bBranch('BETA');

    $this->actingAs($alphaAdmin)->post("/admin/branches/{$betaBranch->id}/update", ['name' => 'X', 'code' => 'X', 'timezone' => 'UTC'])->assertNotFound();
    $this->actingAs($alphaAdmin)->post("/admin/branches/{$betaBranch->id}/deactivate")->assertNotFound();
});

// ── Opening hours ─────────────────────────────────────────────────────────────

test('opening hours are saved (audited) and reject an invalid window', function () {
    $tenant = w8bTenant();
    $admin = w8bUser($tenant, 'org_admin');
    $branch = w8bBranch('HRS');
    $before = w8bAudit($tenant, 'branch.hours_changed');

    $days = w8bClosedWeek();
    $days[1] = ['weekday' => 1, 'is_closed' => false, 'open_time' => '09:00', 'close_time' => '17:00']; // Monday open

    $this->actingAs($admin)->post("/admin/branches/{$branch->id}/hours", ['days' => $days])->assertRedirect('/admin/branches');

    app(TenantContext::class)->set($tenant);
    expect(BranchHours::query()->where('branch_id', $branch->id)->count())->toBe(7)
        ->and(w8bAudit($tenant, 'branch.hours_changed') - $before)->toBeGreaterThan(0);

    // open >= close is rejected.
    $bad = w8bClosedWeek();
    $bad[2] = ['weekday' => 2, 'is_closed' => false, 'open_time' => '17:00', 'close_time' => '09:00'];
    $this->actingAs($admin)->post("/admin/branches/{$branch->id}/hours", ['days' => $bad])->assertSessionHasErrors('days');
});

// ── Timezone application ──────────────────────────────────────────────────────

test('the tenant timezone is applied + surfaced to the page', function () {
    $tenant = w8bTenant();
    $admin = w8bUser($tenant, 'org_admin');
    app(SettingsService::class)->set('timezone', 'Europe/Zurich');

    $this->actingAs($admin)
        ->get('/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('timezone', 'Europe/Zurich'));
});
