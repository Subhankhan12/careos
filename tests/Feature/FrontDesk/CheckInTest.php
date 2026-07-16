<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditService;
use Modules\FrontDesk\Exceptions\CheckInException;
use Modules\FrontDesk\Models\KioskDevice;
use Modules\FrontDesk\Services\CheckInService;
use Modules\FrontDesk\Services\KioskDeviceService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AppointmentService;
use Modules\Scheduling\Services\BookingService;

uses(RefreshDatabase::class);

function fdCtx(): TenantContext
{
    return app(TenantContext::class);
}

function fdTenant(string $slug): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    fdCtx()->set($tenant);

    return $tenant;
}

function fdUser(Tenant $tenant, string $role = 'org_admin'): User
{
    fdCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

function fdBranch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function fdService(): Service
{
    return Service::query()->create([
        'name' => 'Consult',
        'code' => 'CONS-'.strtoupper(substr(uniqid(), -5)),
        'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => true,
        'active' => true,
    ]);
}

function fdResource(Branch $branch): BookableResource
{
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Practitioner',
        'branch_id' => $branch->id,
        'active' => true,
    ]);

    for ($weekday = 0; $weekday <= 6; $weekday++) {
        ResourceAvailability::query()->create([
            'resource_id' => $resource->id,
            'weekday' => $weekday,
            'start_time' => '00:00',
            'end_time' => '23:59',
        ]);
    }

    return $resource;
}

function fdPatient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Kiosk',
        'last_name' => 'Patient',
        'date_of_birth' => '1990-05-15',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function fdBook(Service $service, Patient $patient, Branch $branch, User $actor, ?Carbon $startsAt = null): Appointment
{
    return app(BookingService::class)->book(
        $service->id,
        $patient->id,
        $branch->id,
        ($startsAt ?? Carbon::today()->setTime(10, 0))->toDateTimeString(),
        [fdResourceFor($branch)->id],
        $actor,
    );
}

function fdResourceFor(Branch $branch): BookableResource
{
    return BookableResource::query()->where('branch_id', $branch->id)->firstOrFail();
}

/**
 * @return array{device: KioskDevice, token: string}
 */
function fdKiosk(Branch $branch, User $actor): array
{
    return app(KioskDeviceService::class)->issue($branch->id, 'Lobby kiosk', $actor);
}

function fdPortalReady(Patient $patient, User $staff): array
{
    ConsentTemplate::query()->firstOrCreate(
        ['key' => 'portal', 'version' => 1],
        ['title' => 'Portal', 'body' => 'Portal access consent', 'scope_keys' => ['portal.access'], 'is_active' => true],
    );
    /** @var PatientConsent $consent */
    $consent = app(ConsentService::class)->grant($patient, 'portal', 'Kiosk Patient', $staff);

    $account = PortalAccount::query()->create([
        'patient_id' => $patient->id,
        'email' => 'portal.'.$patient->id.'@portal.test',
        'password' => bcrypt('secret-portal-pass'),
        'status' => PortalAccount::STATUS_ACTIVE,
        'activated_at' => now(),
    ]);

    return ['account' => $account, 'consent' => $consent];
}

// ---------------------------------------------------------------------------
// Kiosk
// ---------------------------------------------------------------------------

test('kiosk exact match resolves one appointment and checks in (arrived + audited)', function () {
    $tenant = fdTenant('alpha');
    $actor = fdUser($tenant);
    $branch = fdBranch();
    fdResource($branch);
    $service = fdService();
    $patient = fdPatient();
    $appointment = fdBook($service, $patient, $branch, $actor);
    ['token' => $token] = fdKiosk($branch, $actor);

    $resolve = $this->postJson("/kiosk/{$token}/resolve", [
        'name' => 'Kiosk Patient',
        'date_of_birth' => '1990-05-15',
        'code' => $appointment->check_in_code,
    ])->assertOk();

    $resolve->assertJsonPath('found', true);
    $verification = $resolve->json('verification');
    expect($verification)->not->toBeEmpty();

    $this->postJson("/kiosk/{$token}/check-in", ['verification' => $verification])
        ->assertOk()
        ->assertJsonPath('checked_in', true);

    $appointment->refresh();
    expect($appointment->status)->toBe(Appointment::STATUS_ARRIVED)
        ->and($appointment->checked_in_at)->not->toBeNull()
        ->and($appointment->check_in_source)->toBe('kiosk');

    $audit = DB::select(
        "SELECT * FROM audit_events WHERE tenant_id = ? AND action = 'appointment.checked_in' AND patient_id = ?",
        [$tenant->id, $patient->id],
    );
    expect($audit)->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('a wrong or ambiguous code returns a generic not-found with no patient data', function () {
    $tenant = fdTenant('alpha');
    $actor = fdUser($tenant);
    $branch = fdBranch();
    fdResource($branch);
    $service = fdService();
    $patient = fdPatient(['last_name' => 'Secret']);
    $appointment = fdBook($service, $patient, $branch, $actor);
    ['token' => $token] = fdKiosk($branch, $actor);

    // Wrong code -> generic not found, and NOTHING about the patient leaks.
    $wrong = $this->postJson("/kiosk/{$token}/resolve", [
        'name' => 'Kiosk Secret',
        'date_of_birth' => '1990-05-15',
        'code' => 'WRONG9',
    ])->assertOk();

    expect($wrong->json())->toBe(['found' => false]);
    $body = $wrong->getContent();
    expect($body)->not->toContain('Secret')
        ->and($body)->not->toContain($patient->id)
        ->and($body)->not->toContain($appointment->id);

    // Ambiguous: two patients with the same name+dob+code -> also generic not-found.
    $twin = fdPatient(['last_name' => 'Secret']);
    $appt2 = fdBook($service, $twin, $branch, $actor, Carbon::today()->setTime(11, 0));
    Appointment::query()->whereKey($appointment->id)->update(['check_in_code' => 'SAMEXX']);
    Appointment::query()->whereKey($appt2->id)->update(['check_in_code' => 'SAMEXX']);

    $ambiguous = $this->postJson("/kiosk/{$token}/resolve", [
        'name' => 'Kiosk Secret',
        'date_of_birth' => '1990-05-15',
        'code' => 'SAMEXX',
    ])->assertOk();

    expect($ambiguous->json())->toBe(['found' => false]);
});

test('the kiosk exposes no clinical data or patient browsing', function () {
    $tenant = fdTenant('alpha');
    $actor = fdUser($tenant);
    $branch = fdBranch();
    fdResource($branch);
    $service = fdService();
    $patient = fdPatient();
    $appointment = fdBook($service, $patient, $branch, $actor);
    ['token' => $token] = fdKiosk($branch, $actor);

    // The found payload carries ONLY appointment + own contact — no clinical keys.
    $found = $this->postJson("/kiosk/{$token}/resolve", [
        'name' => 'Kiosk Patient',
        'date_of_birth' => '1990-05-15',
        'code' => $appointment->check_in_code,
    ])->assertOk();

    expect(array_keys($found->json()))->toEqualCanonicalizing(['found', 'verification', 'appointment', 'contact'])
        ->and(array_keys($found->json('contact')))->toEqualCanonicalizing(['phone', 'email', 'address']);

    // The kiosk is a guest: staff routes (chart, patient list) are unreachable.
    $this->get("/clinical/chart/{$patient->id}")->assertRedirect();
    $this->get('/patients')->assertRedirect();
    // There is no kiosk search endpoint.
    $this->postJson("/kiosk/{$token}/search", ['q' => 'a'])->assertNotFound();
});

test('kiosk contact update changes only contact fields and is audited', function () {
    $tenant = fdTenant('alpha');
    $actor = fdUser($tenant);
    $branch = fdBranch();
    fdResource($branch);
    $service = fdService();
    $patient = fdPatient();
    $appointment = fdBook($service, $patient, $branch, $actor);
    ['token' => $token] = fdKiosk($branch, $actor);

    $verification = $this->postJson("/kiosk/{$token}/resolve", [
        'name' => 'Kiosk Patient',
        'date_of_birth' => '1990-05-15',
        'code' => $appointment->check_in_code,
    ])->json('verification');

    // Includes an attempt to change first_name — which must be ignored.
    $this->postJson("/kiosk/{$token}/contact", [
        'verification' => $verification,
        'phone' => '+41 79 111 22 33',
        'email' => 'updated@example.test',
        'first_name' => 'Hacker',
    ])->assertOk();

    $patient->refresh()->load('contacts');
    expect($patient->first_name)->toBe('Kiosk') // unchanged
        ->and($patient->date_of_birth->toDateString())->toBe('1990-05-15') // unchanged
        ->and($patient->contacts->firstWhere('type', 'phone')?->value)->toBe('+41 79 111 22 33')
        ->and($patient->contacts->firstWhere('type', 'email')?->value)->toBe('updated@example.test');

    $audit = DB::select(
        "SELECT * FROM audit_events WHERE tenant_id = ? AND action = 'patient.contact_updated' AND patient_id = ?",
        [$tenant->id, $patient->id],
    );
    expect($audit)->toHaveCount(1);
});

test('a kiosk token is branch-scoped and a revoked token is refused', function () {
    $tenant = fdTenant('alpha');
    $actor = fdUser($tenant);
    $branchA = fdBranch('AAAA');
    $branchB = fdBranch('BBBB');
    fdResource($branchA);
    fdResource($branchB);
    $service = fdService();
    $patient = fdPatient();
    // Appointment is at branch B.
    $appointment = fdBook($service, $patient, $branchB, $actor);

    // Kiosk provisioned to branch A cannot resolve a branch-B appointment.
    ['device' => $device, 'token' => $token] = fdKiosk($branchA, $actor);
    $this->postJson("/kiosk/{$token}/resolve", [
        'name' => 'Kiosk Patient',
        'date_of_birth' => '1990-05-15',
        'code' => $appointment->check_in_code,
    ])->assertOk()->assertJson(['found' => false]);

    // Revoked token -> flat 403 on every kiosk route.
    app(KioskDeviceService::class)->revoke($device);
    $this->get("/kiosk/{$token}")->assertForbidden();
    $this->postJson("/kiosk/{$token}/resolve", ['name' => 'x', 'date_of_birth' => '1990-05-15', 'code' => 'x'])->assertForbidden();
});

test('kiosk code entry is rate limited against brute force', function () {
    $tenant = fdTenant('alpha');
    $actor = fdUser($tenant);
    $branch = fdBranch();
    fdResource($branch);
    ['token' => $token] = fdKiosk($branch, $actor);

    for ($i = 0; $i < 10; $i++) {
        $this->postJson("/kiosk/{$token}/resolve", [
            'name' => 'Nobody Here',
            'date_of_birth' => '1990-05-15',
            'code' => 'NOPE'.$i,
        ])->assertOk();
    }

    $this->postJson("/kiosk/{$token}/resolve", [
        'name' => 'Nobody Here',
        'date_of_birth' => '1990-05-15',
        'code' => 'NOPE99',
    ])->assertStatus(429);
});

// ---------------------------------------------------------------------------
// Portal
// ---------------------------------------------------------------------------

test('portal check-in requires the authenticated patient and only their own appointment', function () {
    $tenant = fdTenant('alpha');
    $actor = fdUser($tenant);
    $branch = fdBranch();
    fdResource($branch);
    $service = fdService();
    $patient = fdPatient();
    $appointment = fdBook($service, $patient, $branch, $actor);
    ['account' => $account] = fdPortalReady($patient, $actor);

    // Another patient's appointment.
    $other = fdPatient(['first_name' => 'Other']);
    $otherAppt = fdBook($service, $other, $branch, $actor, Carbon::today()->setTime(12, 0));

    // Unauthenticated -> redirected to portal login.
    $this->post('/portal/check-in', ['appointment_id' => $appointment->id])->assertRedirect();

    // Authenticated patient checks into their OWN appointment.
    $this->actingAs($account, 'patient')
        ->withSession(['portal_tenant_id' => $tenant->id])
        ->post('/portal/check-in', ['appointment_id' => $appointment->id])
        ->assertRedirect(route('portal.appointments'));

    expect($appointment->refresh()->status)->toBe(Appointment::STATUS_ARRIVED)
        ->and($appointment->check_in_source)->toBe('portal');

    // Cannot check into someone else's appointment (ownership scoped -> 404).
    $this->actingAs($account, 'patient')
        ->withSession(['portal_tenant_id' => $tenant->id])
        ->post('/portal/check-in', ['appointment_id' => $otherAppt->id])
        ->assertNotFound();
});

test('portal check-in is blocked when portal.access consent is withdrawn', function () {
    $tenant = fdTenant('alpha');
    $actor = fdUser($tenant);
    $branch = fdBranch();
    fdResource($branch);
    $service = fdService();
    $patient = fdPatient();
    $appointment = fdBook($service, $patient, $branch, $actor);
    ['account' => $account, 'consent' => $consent] = fdPortalReady($patient, $actor);

    app(ConsentService::class)->withdraw($consent, 'patient asked');

    $this->actingAs($account, 'patient')
        ->withSession(['portal_tenant_id' => $tenant->id])
        ->post('/portal/check-in', ['appointment_id' => $appointment->id])
        ->assertForbidden(); // portal-consent middleware locks the portal (403)

    expect($appointment->refresh()->status)->toBe(Appointment::STATUS_BOOKED);
});

// ---------------------------------------------------------------------------
// Service-level invariants
// ---------------------------------------------------------------------------

test('double check-in is idempotent and check-in is limited to today, this branch, valid state', function () {
    $tenant = fdTenant('alpha');
    $actor = fdUser($tenant);
    $branch = fdBranch();
    fdResource($branch);
    $service = fdService();
    $patient = fdPatient();
    $appointment = fdBook($service, $patient, $branch, $actor);
    $checkIns = app(CheckInService::class);

    $first = $checkIns->checkIn($appointment, $patient, 'kiosk', $branch->id);
    expect($first['already_checked_in'])->toBeFalse()
        ->and($first['appointment']->status)->toBe(Appointment::STATUS_ARRIVED);

    $checkedInAt = $first['appointment']->checked_in_at;
    $second = $checkIns->checkIn($first['appointment'], $patient, 'kiosk', $branch->id);
    expect($second['already_checked_in'])->toBeTrue()
        ->and($second['appointment']->checked_in_at->eq($checkedInAt))->toBeTrue();

    // Only one arrived transition audited.
    $arrived = DB::select("SELECT COUNT(*) c FROM audit_events WHERE tenant_id = ? AND action = 'appointment.arrived'", [$tenant->id]);
    expect((int) $arrived[0]->c)->toBe(1);

    // Wrong branch.
    $otherBranch = fdBranch('OTHR');
    expect(fn () => $checkIns->checkIn($appointment->refresh(), $patient, 'kiosk', $otherBranch->id))
        ->toThrow(CheckInException::class);

    // Not today.
    $tomorrow = fdBook($service, $patient, $branch, $actor, Carbon::tomorrow()->setTime(10, 0));
    expect(fn () => $checkIns->checkIn($tomorrow, $patient, 'kiosk', $branch->id))->toThrow(CheckInException::class);

    // Invalid state (cancelled).
    $cancelled = fdBook($service, fdPatient(['first_name' => 'Cx']), $branch, $actor, Carbon::today()->setTime(13, 0));
    app(AppointmentService::class)->cancel($cancelled, $actor, 'no longer needed');
    expect(fn () => $checkIns->checkIn($cancelled->refresh(), Patient::query()->find($cancelled->patient_id), 'kiosk', $branch->id))
        ->toThrow(CheckInException::class);
});

test('check-in fails closed on a foreign patient (ownership)', function () {
    $tenant = fdTenant('alpha');
    $actor = fdUser($tenant);
    $branch = fdBranch();
    fdResource($branch);
    $service = fdService();
    $patient = fdPatient();
    $appointment = fdBook($service, $patient, $branch, $actor);
    $stranger = fdPatient(['first_name' => 'Stranger']);

    expect(fn () => app(CheckInService::class)->checkIn($appointment, $stranger, 'kiosk', $branch->id))
        ->toThrow(AuthorizationException::class);
});

test('check-in and kiosk resolution are tenant isolated', function () {
    $alpha = fdTenant('alpha');
    $alphaActor = fdUser($alpha);
    $alphaBranch = fdBranch('ALFA');
    fdResource($alphaBranch);
    $alphaService = fdService();
    $alphaPatient = fdPatient();
    $alphaAppt = fdBook($alphaService, $alphaPatient, $alphaBranch, $alphaActor);

    $beta = fdTenant('beta');
    $betaActor = fdUser($beta);
    $betaBranch = fdBranch('BETA');
    fdResource($betaBranch);
    $betaService = fdService();
    // Same identifying details as alpha's patient.
    $betaPatient = fdPatient();
    fdBook($betaService, $betaPatient, $betaBranch, $betaActor);
    ['token' => $betaToken] = fdKiosk($betaBranch, $betaActor);

    // The beta kiosk (beta tenant context) can never resolve alpha's appointment,
    // even with alpha's exact code.
    $this->postJson("/kiosk/{$betaToken}/resolve", [
        'name' => 'Kiosk Patient',
        'date_of_birth' => '1990-05-15',
        'code' => $alphaAppt->check_in_code,
    ])->assertOk()->assertJson(['found' => false]);
});
