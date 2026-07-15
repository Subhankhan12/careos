<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Modules\Clinical\Models\Problem;
use Modules\Clinical\Models\Recall;
use Modules\Clinical\Models\RecallRule;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\Credential;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\SystemActorResolver;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Jobs\SendAppointmentReminderJob;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentReminder;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\BookingService;
use Modules\Scheduling\Services\ReminderPolicy;

uses(RefreshDatabase::class);

function p2Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function p2Tenant(string $slug, string $status = 'active'): Tenant
{
    return Tenant::query()->create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => $status,
    ]);
}

function p2User(Tenant $tenant, string $role = 'org_admin', ?string $branchId = null): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('tenant_id', $tenant->id)->where('key', $role)->firstOrFail()->id,
        'branch_id' => $branchId,
    ]);

    return $user;
}

// ---------------------------------------------------------------------------
// Tenant scoping: an unattended sweep must never touch an inactive tenant.
// ---------------------------------------------------------------------------

/**
 * Credential::creating derives status from expires_on, so a row is never born
 * stale. The drift this command exists to fix comes from TIME PASSING with no
 * write: a licence stored 'valid' months ago is expired today and nothing has
 * touched the row since. So the fixture writes an honest row, then travels.
 */
test('credentials:refresh-status recomputes for active tenants and never touches suspended ones', function () {
    Carbon::setTestNow('2026-01-10 03:00:00');

    $active = p2Tenant('active-clinic');
    $suspended = p2Tenant('suspended-clinic', 'suspended');
    $licences = [];

    foreach ([$active, $suspended] as $tenant) {
        p2Ctx()->set($tenant);
        $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
        $staff = StaffProfile::query()->create([
            'first_name' => 'Sam',
            'last_name' => 'Staff',
            'display_name' => 'Sam Staff',
            'profession' => 'nurse',
            'primary_branch_id' => $branch->id,
            'status' => StaffProfile::STATUS_ACTIVE,
        ]);

        // Expires in 60 days: genuinely 'valid' when written.
        $licences[$tenant->slug] = Credential::query()->create([
            'staff_profile_id' => $staff->id,
            'type' => 'licence',
            'name' => 'Nursing licence',
            'expires_on' => '2026-03-11',
        ]);
    }

    expect($licences['active-clinic']->status)->toBe(Credential::STATUS_VALID);

    // Six months on, nobody has edited either row — both are now stale on disk.
    Carbon::setTestNow('2026-07-10 02:10:00');

    p2Ctx()->forget();
    expect(Artisan::call('credentials:refresh-status'))->toBe(0);

    p2Ctx()->set($active);
    expect($licences['active-clinic']->refresh()->status)->toBe(Credential::STATUS_EXPIRED);

    // The suspended tenant's row is untouched — the sweep never entered it.
    p2Ctx()->set($suspended);
    expect($licences['suspended-clinic']->refresh()->status)->toBe(Credential::STATUS_VALID);

    Carbon::setTestNow();
});

test('credentials:refresh-status is idempotent and preserves manual revocation', function () {
    Carbon::setTestNow('2026-07-10 02:10:00');
    $tenant = p2Tenant('idem-clinic');
    p2Ctx()->set($tenant);

    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    $staff = StaffProfile::query()->create([
        'first_name' => 'Rev',
        'last_name' => 'Oked',
        'display_name' => 'Rev Oked',
        'profession' => 'nurse',
        'primary_branch_id' => $branch->id,
        'status' => StaffProfile::STATUS_ACTIVE,
    ]);

    $expiring = Credential::query()->create([
        'staff_profile_id' => $staff->id,
        'type' => 'licence',
        'name' => 'Expiring soon',
        'expires_on' => Carbon::today()->addDays(10)->toDateString(),
        'status' => Credential::STATUS_VALID,
    ]);
    $revoked = Credential::query()->create([
        'staff_profile_id' => $staff->id,
        'type' => 'certificate',
        'name' => 'Revoked by hand',
        'expires_on' => Carbon::today()->addYears(2)->toDateString(),
        'status' => Credential::STATUS_REVOKED,
    ]);

    p2Ctx()->forget();
    Artisan::call('credentials:refresh-status');
    p2Ctx()->set($tenant);
    $afterFirst = $expiring->refresh()->status;

    p2Ctx()->forget();
    Artisan::call('credentials:refresh-status');
    p2Ctx()->set($tenant);

    expect($afterFirst)->toBe(Credential::STATUS_EXPIRING)
        ->and($expiring->refresh()->status)->toBe($afterFirst) // second run changes nothing
        ->and($revoked->refresh()->status)->toBe(Credential::STATUS_REVOKED); // manual revocation wins

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Recall evaluation
// ---------------------------------------------------------------------------

test('clinical:evaluate-recalls generates due recalls for active tenants only and is idempotent', function () {
    $active = p2Tenant('recall-active');
    $suspended = p2Tenant('recall-suspended', 'suspended');

    foreach ([$active, $suspended] as $tenant) {
        p2Ctx()->set($tenant);
        $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
        $staff = StaffProfile::query()->create([
            'first_name' => 'Doc',
            'last_name' => 'Tor',
            'display_name' => 'Doc Tor',
            'profession' => 'doctor',
            'primary_branch_id' => $branch->id,
            'status' => StaffProfile::STATUS_ACTIVE,
        ]);
        $patient = app(PatientService::class)->create([
            'first_name' => 'Hyper',
            'last_name' => 'Tensive',
            'date_of_birth' => '1960-02-02',
            'sex' => 'male',
        ]);
        Problem::query()->create([
            'patient_id' => $patient->id,
            'description' => 'Essential hypertension',
            'code' => 'I10',
            'status' => Problem::STATUS_ACTIVE,
            'recorded_by' => $staff->id,
            'recorded_at' => now(),
        ]);
        RecallRule::query()->create([
            'name' => 'Hypertension annual review',
            'criteria' => ['active_problem_codes' => ['I10']],
            'interval_months' => 12,
            'active' => true,
        ]);
    }

    p2Ctx()->forget();
    expect(Artisan::call('clinical:evaluate-recalls'))->toBe(0);

    p2Ctx()->set($active);
    expect(Recall::query()->count())->toBe(1);

    // The suspended tenant was never evaluated.
    p2Ctx()->set($suspended);
    expect(Recall::query()->count())->toBe(0);

    // Second run adds nothing (firstOrCreate on tenant/patient/rule/due_on).
    p2Ctx()->forget();
    Artisan::call('clinical:evaluate-recalls');
    p2Ctx()->set($active);

    expect(Recall::query()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Reminder dispatch command: enqueue only, in-window, no double-send.
// ---------------------------------------------------------------------------

test('appointments:dispatch-reminders enqueues in-window reminders once, for active tenants only', function () {
    Queue::fake();
    Carbon::setTestNow('2026-07-13 09:00:00');

    $active = p2Tenant('rem-active');
    $suspended = p2Tenant('rem-suspended', 'suspended');

    foreach ([$active, $suspended] as $tenant) {
        p2Ctx()->set($tenant);
        $actor = p2User($tenant, 'reception');

        // A single 60-minute offset. The default policy also carries 1440, which
        // would pull every appointment in the next 24h into window and make
        // "out of window" untestable on a same-day fixture.
        app(SettingsService::class)->set(ReminderPolicy::SETTING_KEY, [
            'offset_minutes' => [60],
            'channels' => [AppointmentReminder::CHANNEL_EMAIL],
        ], 'array');

        $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
        $service = Service::query()->create([
            'name' => 'Consult',
            'code' => 'CONS',
            'default_duration_minutes' => 30,
            'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
            'bookable_online' => true,
            'active' => true,
        ]);
        $service->branchLinks()->create(['branch_id' => $branch->id]);
        $resource = BookableResource::query()->create([
            'type' => BookableResource::TYPE_PRACTITIONER,
            'name' => 'Practitioner',
            'branch_id' => $branch->id,
            'active' => true,
        ]);
        ResourceAvailability::query()->create([
            'resource_id' => $resource->id,
            'weekday' => 1, // 2026-07-13 is a Monday
            'start_time' => '08:00',
            'end_time' => '18:00',
        ]);
        $patient = app(PatientService::class)->create([
            'first_name' => 'Rem',
            'last_name' => 'Inder',
            'date_of_birth' => '1990-01-01',
            'sex' => 'female',
        ]);

        // In window for the default 60-minute offset.
        app(BookingService::class)->book(
            $service->id,
            $patient->id,
            $branch->id,
            '2026-07-13 09:30:00',
            [$resource->id],
            $actor,
        );
        // 8 hours out: past the 60-minute offset, so not yet due to enqueue.
        app(BookingService::class)->book(
            $service->id,
            $patient->id,
            $branch->id,
            '2026-07-13 17:00:00',
            [$resource->id],
            $actor,
        );
    }

    p2Ctx()->forget();
    expect(Artisan::call('appointments:dispatch-reminders'))->toBe(0);

    // Only the active tenant's in-window appointment produced a reminder.
    p2Ctx()->set($active);
    expect(AppointmentReminder::query()->count())->toBe(1)
        ->and(AppointmentReminder::query()->firstOrFail()->status)->toBe(AppointmentReminder::STATUS_PENDING);

    p2Ctx()->set($suspended);
    expect(AppointmentReminder::query()->count())->toBe(0);

    Queue::assertPushed(SendAppointmentReminderJob::class, 1);

    // Running the sweep again enqueues nothing new: no double-send.
    p2Ctx()->forget();
    Artisan::call('appointments:dispatch-reminders');

    p2Ctx()->set($active);
    expect(AppointmentReminder::query()->count())->toBe(1);
    Queue::assertPushed(SendAppointmentReminderJob::class, 1);

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// SystemActorResolver: the unattended runs must never escalate.
// ---------------------------------------------------------------------------

test('the system actor resolver picks a real tenant-wide permission holder, deterministically', function () {
    $tenant = p2Tenant('actor-clinic');
    p2Ctx()->set($tenant);

    $first = p2User($tenant, 'billing');
    p2User($tenant, 'billing'); // later id; the resolver must keep choosing $first

    $resolved = app(SystemActorResolver::class)->forPermission($tenant, 'billing.manage');

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($first->id)
        ->and(app(SystemActorResolver::class)->forPermission($tenant, 'billing.manage')->id)->toBe($first->id);
});

test('the system actor resolver never picks a super-admin, a branch-scoped holder, or another tenant', function () {
    $tenant = p2Tenant('lonely-clinic');
    $other = p2Tenant('other-clinic');

    p2Ctx()->set($other);
    p2User($other, 'billing'); // holds billing.manage, but in a DIFFERENT tenant

    p2Ctx()->set($tenant);
    User::factory()->twoFactorEnabled()->create(); // super-admin: tenant_id = null
    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    p2User($tenant, 'billing', $branch->id); // branch-scoped, so not tenant-wide
    p2User($tenant, 'reception'); // no billing.manage at all

    // Nobody in this tenant holds billing.manage tenant-wide => no actor, and
    // the caller skips rather than escalating.
    expect(app(SystemActorResolver::class)->forPermission($tenant, 'billing.manage'))->toBeNull();
});
