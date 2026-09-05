<?php

use App\Services\BranchService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Audit\Models\AuditEvent;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Exceptions\BookingUnavailableException;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AvailableSlotFinder;
use Modules\Scheduling\Services\BookingService;

uses(RefreshDatabase::class);

/*
 * QA-FIX.1b — CLOCK FROZEN, ASSERTIONS UNTOUCHED. This file books on Monday 2026-07-13, a date
 * that has since elapsed; with a past start now refused (P1-H3) the fixture could never book.
 * 2026-07-12 08:00 is the anchor this file already uses for its hard-guard test below, so the
 * whole file now shares one explicit "now". A fixture correction, not a weakening.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-07-12 08:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
 * BRANCH.P1 — per-branch accepts_online_bookings (the SOFT-SUSPEND) + phone. Turning online bookings
 * off stops NEW online bookings (public/portal lists, online slots, and the online write path) while
 * the branch stays active: existing appointments and the internal day-board are untouched, and staff
 * can still book. It is DISTINCT from the HARD active=false deactivation (blocked while future
 * appointments exist, W8b) — soft-suspend never strands care, so it is always allowed and does NOT
 * deactivate. These tests ADD coverage; no existing behaviour test is modified.
 */

/**
 * @return array{tenant: Tenant, branch: Branch, service: Service, resource: BookableResource, patient: Patient, admin: User}
 */
function obFixture(): array
{
    $tenant = Tenant::create(['name' => 'Alpha Care', 'slug' => 'alpha', 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    $service = Service::create([
        'name' => 'Consult', 'code' => 'CONS', 'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER], 'bookable_online' => true, 'active' => true,
    ]);
    $resource = BookableResource::create(['type' => BookableResource::TYPE_PRACTITIONER, 'name' => 'Dr R', 'branch_id' => $branch->id, 'active' => true]);
    ResourceAvailability::create(['resource_id' => $resource->id, 'weekday' => 1, 'start_time' => '07:00', 'end_time' => '19:00']);
    $patient = app(PatientService::class)->create(['first_name' => 'Pat', 'last_name' => 'Online', 'date_of_birth' => '1980-01-01', 'sex' => 'female']);
    $admin = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $admin->id, 'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id]);

    return compact('tenant', 'branch', 'service', 'resource', 'patient', 'admin');
}

function obReception(Tenant $tenant): User
{
    app(TenantContext::class)->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', 'reception')->firstOrFail()->id]);

    return $user;
}

// ── The soft-suspend REALLY gates online bookings (not cosmetic) ────────────────────────────────────

test('accepts_online_bookings=false stops NEW online bookings but keeps existing appointments + the day-board', function () {
    $fx = obFixture();
    $engine = app(BookingService::class);

    // An EXISTING appointment booked while online was on (staff path).
    $existing = $engine->book($fx['service']->id, $fx['patient']->id, $fx['branch']->id, '2026-07-13 09:00:00', [$fx['resource']->id], $fx['admin']);

    // Soft-suspend: online bookings off (branch stays active).
    app(BranchService::class)->setOnlineBookings($fx['branch'], false);
    $fx['branch']->refresh();
    expect($fx['branch']->active)->toBeTrue()->and($fx['branch']->accepts_online_bookings)->toBeFalse();

    // A NEW ONLINE booking is refused (the online write path honors the state).
    expect(fn () => $engine->bookOnline($fx['service']->id, $fx['patient']->id, $fx['branch']->id, '2026-07-13 11:00:00', [$fx['resource']->id]))
        ->toThrow(BookingUnavailableException::class);

    // Existing appointment untouched.
    expect($existing->refresh()->status)->toBe(Appointment::STATUS_BOOKED);

    // Staff can STILL book (soft-suspend does not stop internal operation).
    $staff = $engine->book($fx['service']->id, $fx['patient']->id, $fx['branch']->id, '2026-07-13 10:00:00', [$fx['resource']->id], $fx['admin']);
    expect($staff->exists)->toBeTrue();

    // The internal day-board slot finder is UNCHANGED — it still returns slots for the branch.
    $daySlots = app(AvailableSlotFinder::class)->forServiceBranchDate($fx['service'], $fx['branch']->id, '2026-07-13');
    expect($daySlots)->not->toBe([]);
});

test('the public online-slot surface exposes slots when accepting and none when soft-suspended', function () {
    $fx = obFixture();

    // Accepting (default) → the public slots endpoint returns slots.
    $ok = $this->postJson(route('public.booking.slots', 'alpha'), [
        'service_id' => $fx['service']->id, 'branch_id' => $fx['branch']->id, 'date' => '2026-07-13',
    ]);
    $ok->assertOk();
    expect($ok->json('slots'))->not->toBe([]);

    // Soft-suspended → the SAME endpoint returns no online slots.
    app(BranchService::class)->setOnlineBookings($fx['branch'], false);
    $none = $this->postJson(route('public.booking.slots', 'alpha'), [
        'service_id' => $fx['service']->id, 'branch_id' => $fx['branch']->id, 'date' => '2026-07-13',
    ]);
    $none->assertOk();
    expect($none->json('slots'))->toBe([]);
});

test('a soft-suspended branch is not offered in the public booking branch list', function () {
    $fx = obFixture();
    app(BranchService::class)->setOnlineBookings($fx['branch'], false);

    $this->get(route('public.booking.index', 'alpha'))
        ->assertInertia(fn ($page) => $page->component('Public/Book')->where('branches', []));
});

// ── The phone field persists (validated) ────────────────────────────────────────────────────────────

test('the phone field persists through the branch update', function () {
    $fx = obFixture();

    app(TenantContext::class)->forget();
    $this->actingAs($fx['admin'])
        ->post(route('admin.branches.update', $fx['branch']->id), [
            'name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich', 'phone' => '+41 44 350 60 60',
        ])
        ->assertRedirect(route('admin.branches.index'));

    expect($fx['branch']->refresh()->phone)->toBe('+41 44 350 60 60');
});

// ── THE GUARD DISTINCTION: soft-suspend ≠ deactivate; the hard guard is UNCHANGED ───────────────────

test('soft-suspend is always allowed and does NOT deactivate, while the HARD deactivate guard still blocks on future appointments', function () {
    // Freeze the clock so the booked appointment is genuinely in the FUTURE relative to now (the
    // hard guard counts starts_at >= now); 2026-07-12 is a Sunday, 2026-07-13 the Monday we book.
    Carbon::setTestNow('2026-07-12 08:00:00');
    $fx = obFixture();
    $engine = app(BookingService::class);

    // A future appointment exists — the anti-orphaning condition for the hard guard.
    $engine->book($fx['service']->id, $fx['patient']->id, $fx['branch']->id, '2026-07-13 09:00:00', [$fx['resource']->id], $fx['admin']);

    app(TenantContext::class)->forget();

    // SOFT-SUSPEND is ALWAYS allowed (even with future appointments) and keeps the branch ACTIVE.
    $this->actingAs($fx['admin'])
        ->post(route('admin.branches.online_bookings', $fx['branch']->id), ['accepts_online_bookings' => false])
        ->assertRedirect(route('admin.branches.index'))
        ->assertSessionHas('status', 'onlineBookingsSuspended');
    $fx['branch']->refresh();
    expect($fx['branch']->active)->toBeTrue()               // NOT deactivated
        ->and($fx['branch']->accepts_online_bookings)->toBeFalse();

    // The HARD deactivate guard is UNCHANGED — still blocked while future appointments exist.
    app(TenantContext::class)->forget();
    $this->actingAs($fx['admin'])
        ->post(route('admin.branches.deactivate', $fx['branch']->id))
        ->assertSessionHasErrors('branch');
    expect($fx['branch']->refresh()->active)->toBeTrue();   // still active — the guard held

    Carbon::setTestNow();
});

// ── RBAC + tenant-scoped + audited ──────────────────────────────────────────────────────────────────

test('the online-bookings toggle is admin.manage-gated, tenant-scoped, and audited', function () {
    $fx = obFixture();

    // RBAC: reception (no admin.manage) is denied.
    $reception = obReception($fx['tenant']);
    app(TenantContext::class)->forget();
    $this->actingAs($reception)
        ->post(route('admin.branches.online_bookings', $fx['branch']->id), ['accepts_online_bookings' => false])
        ->assertForbidden();
    expect($fx['branch']->refresh()->accepts_online_bookings)->toBeTrue(); // unchanged

    // admin.manage → allowed + audited distinctly.
    app(TenantContext::class)->forget();
    $this->actingAs($fx['admin'])
        ->post(route('admin.branches.online_bookings', $fx['branch']->id), ['accepts_online_bookings' => false])
        ->assertRedirect(route('admin.branches.index'));
    expect(AuditEvent::query()->where('tenant_id', $fx['tenant']->id)->where('action', 'branch.online_bookings_suspended')->exists())->toBeTrue();

    // Tenant-scoped: another tenant's admin cannot resolve this branch (404).
    $beta = Tenant::create(['name' => 'Beta Care', 'slug' => 'beta', 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($beta);
    $betaAdmin = User::factory()->forTenant($beta)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $betaAdmin->id, 'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id]);
    app(TenantContext::class)->forget();
    $this->actingAs($betaAdmin)
        ->post(route('admin.branches.online_bookings', $fx['branch']->id), ['accepts_online_bookings' => true])
        ->assertNotFound();
});
