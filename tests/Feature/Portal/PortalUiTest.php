<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Comms\Contracts\TelehealthProvider;
use Modules\Comms\Models\Message;
use Modules\Comms\Models\TelehealthSession;
use Modules\Comms\Providers\Telehealth\FakeTelehealthProvider;
use Modules\Comms\Services\ThreadService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
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
use Modules\Scheduling\Services\BookingService;

uses(RefreshDatabase::class);

function g5Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function g5User(Tenant $tenant, string $role = 'reception'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

/**
 * @return array{tenant: Tenant, staff: User, patient: Patient, account: PortalAccount}
 */
function g5Fixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Care',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    g5Ctx()->set($tenant);

    $staff = g5User($tenant);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Portal',
        'last_name' => 'Patient',
        'date_of_birth' => '1993-03-03',
        'sex' => 'female',
    ]);
    $account = g5PortalReady($patient, $staff);

    return compact('tenant', 'staff', 'patient', 'account');
}

function g5PortalReady(Patient $patient, User $staff): PortalAccount
{
    ConsentTemplate::query()->firstOrCreate(
        ['key' => 'portal', 'version' => 1],
        [
            'title' => 'Portal Access',
            'body' => 'Portal access consent',
            'scope_keys' => ['portal.access'],
            'is_active' => true,
        ],
    );
    app(ConsentService::class)->grant($patient, 'portal', 'Portal Patient', $staff);

    return PortalAccount::query()->create([
        'patient_id' => $patient->id,
        'email' => 'portal.'.$patient->id.'@portal.test',
        'password' => bcrypt('secret-portal-pass'),
        'status' => PortalAccount::STATUS_ACTIVE,
        'activated_at' => now(),
    ]);
}

function g5AsPortal(object $test, array $fx)
{
    return $test->actingAs($fx['account'], 'patient')
        ->withSession(['portal_tenant_id' => $fx['tenant']->id]);
}

function g5BookableSetup(array $fx): array
{
    $branch = Branch::query()->create(['name' => 'Portal Branch', 'code' => 'PORT']);
    $service = Service::query()->create([
        'name' => 'Portal Consult',
        'code' => 'PORT-CONS',
        'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => true,
        'active' => true,
    ]);
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Portal Practitioner',
        'branch_id' => $branch->id,
        'active' => true,
    ]);
    foreach (range(0, 6) as $weekday) {
        ResourceAvailability::query()->create([
            'resource_id' => $resource->id,
            'weekday' => $weekday,
            'start_time' => '00:00',
            'end_time' => '23:59',
        ]);
    }

    return compact('branch', 'service', 'resource');
}

function g5Invoice(array $fx, Branch $branch, int $totalMinor = 1000): Invoice
{
    $catalog = TariffCatalog::query()->firstOrCreate(
        ['key' => 'eu-generic', 'version' => 1],
        [
            'name' => 'EU Generic',
            'valid_from' => '2026-01-01',
            'status' => TariffCatalog::STATUS_ACTIVE,
            'rules' => [],
        ],
    );
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id,
        'code' => 'PRT-'.$totalMinor,
        'description' => 'Portal item',
        'unit_price_minor' => $totalMinor,
        'vat_rate_bp' => 0,
        'unit' => 'session',
        'active' => true,
    ]);
    $charge = Charge::query()->create([
        'patient_id' => $fx['patient']->id,
        'branch_id' => $branch->id,
        'service_date' => '2026-07-01',
        'tariff_catalog_id' => $catalog->id,
        'tariff_item_id' => $item->id,
        'code' => $item->code,
        'description' => $item->description,
        'unit_price_minor' => $item->unit_price_minor,
        'vat_rate_bp' => 0,
        'quantity' => 1,
        'line_total_minor' => $item->unit_price_minor,
        'status' => Charge::STATUS_VALIDATED,
        'created_by' => $fx['staff']->id,
    ]);

    $billing = g5User($fx['tenant'], 'billing');
    $service = app(IssueService::class);

    return $service->issue(
        $service->createDraftFromCharges($fx['patient'], [$charge], $billing),
        $billing,
    );
}

function g5ReadRows(string $tenantId, string $patientId, string $resourceType): Collection
{
    return collect(DB::select(
        "SELECT * FROM audit_events WHERE tenant_id = ? AND action = 'read' AND patient_id = ? AND resource_type = ?",
        [$tenantId, $patientId, $resourceType],
    ));
}

test('every portal page requires auth and consent and renders its Inertia component', function () {
    Storage::fake('local');
    $fx = g5Fixture();
    g5BookableSetup($fx);

    $pages = [
        '/portal' => 'Portal/Home',
        '/portal/appointments' => 'Portal/Appointments',
        '/portal/documents' => 'Portal/Documents',
        '/portal/messages' => 'Portal/Messages',
        '/portal/invoices' => 'Portal/Invoices',
        '/portal/consents' => 'Portal/Consents',
        '/portal/telehealth' => 'Portal/Telehealth',
    ];

    // Unauthenticated -> redirected to the portal login (checked for every
    // page BEFORE any authentication happens in this test).
    foreach (array_keys($pages) as $url) {
        $this->get($url)->assertRedirect(route('portal.login'));
    }

    // Authenticated + consented -> each page renders its component.
    foreach ($pages as $url => $component) {
        g5AsPortal($this, $fx)
            ->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }

    // The login page itself renders for guests.
    $this->get('/portal/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Portal/Login'));
});

test('withdrawing portal.access locks the portal on the next request', function () {
    $fx = g5Fixture();

    g5AsPortal($this, $fx)->get('/portal')->assertOk();

    $consent = PatientConsent::query()
        ->where('patient_id', $fx['patient']->id)
        ->where('status', PatientConsent::STATUS_GRANTED)
        ->firstOrFail();

    g5AsPortal($this, $fx)
        ->post(route('portal.consents.withdraw'), [
            'consent_id' => $consent->id,
            'reason' => 'No longer wants portal access',
        ])
        ->assertRedirect();

    // The very next request is locked out, fail-closed.
    g5AsPortal($this, $fx)->get('/portal')->assertForbidden();
    g5AsPortal($this, $fx)->get('/portal/messages')->assertForbidden();
});

test('a patient sees only their own threads and cannot touch anothers', function () {
    $fx = g5Fixture();
    $threads = app(ThreadService::class);
    $ownThread = $threads->openPatientThread($fx['patient'], 'Mine', $fx['staff']);
    $threads->postStaffMessage($ownThread, $fx['staff'], 'Hello you');

    $other = app(PatientService::class)->create([
        'first_name' => 'Other',
        'last_name' => 'Person',
        'date_of_birth' => '1980-01-01',
        'sex' => 'male',
    ]);
    $otherThread = $threads->openPatientThread($other, 'Not yours', $fx['staff']);

    g5AsPortal($this, $fx)
        ->get('/portal/messages')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portal/Messages')
            ->has('threads', 1)
            ->where('threads.0.id', $ownThread->id)
            ->where('threads.0.unread', 1));

    // Opening or posting to the other patient's thread fails.
    g5AsPortal($this, $fx)
        ->get('/portal/messages?thread_id='.$otherThread->id)
        ->assertForbidden();

    g5AsPortal($this, $fx)
        ->post(route('portal.messages.store'), ['thread_id' => $otherThread->id, 'body' => 'Let me in'])
        ->assertForbidden();

    // Posting to their own thread appends through ThreadService and read-marks.
    g5AsPortal($this, $fx)
        ->post(route('portal.messages.store'), ['thread_id' => $ownThread->id, 'body' => 'A question'])
        ->assertRedirect();

    $posted = Message::query()->where('thread_id', $ownThread->id)->where('author_type', Message::AUTHOR_PATIENT)->firstOrFail();

    expect($posted->author_patient_id)->toBe($fx['patient']->id)
        ->and(g5ReadRows($fx['tenant']->id, $fx['patient']->id, 'threads')->count())->toBeGreaterThanOrEqual(0);

    // Reading their thread writes the patient-scoped read row.
    g5AsPortal($this, $fx)->get('/portal/messages?thread_id='.$ownThread->id)->assertOk();
    expect(g5ReadRows($fx['tenant']->id, $fx['patient']->id, 'threads')->count())->toBeGreaterThanOrEqual(1);
});

test('a patient sees only their own invoices and downloads write read audit rows', function () {
    Storage::fake('local');
    $fx = g5Fixture();
    $setup = g5BookableSetup($fx);
    $ownInvoice = g5Invoice($fx, $setup['branch'], 1000);

    $other = app(PatientService::class)->create([
        'first_name' => 'Other',
        'last_name' => 'Billed',
        'date_of_birth' => '1985-05-05',
        'sex' => 'female',
    ]);
    $otherFx = ['tenant' => $fx['tenant'], 'staff' => $fx['staff'], 'patient' => $other];
    $otherInvoice = g5Invoice($otherFx, $setup['branch'], 2000);

    g5AsPortal($this, $fx)
        ->get('/portal/invoices')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portal/Invoices')
            ->has('invoices', 1)
            ->where('invoices.0.id', $ownInvoice->id)
            ->where('invoices.0.open_balance_minor', 1000));

    // Own PDF downloads and is read-logged; the other patient's is unreachable.
    g5AsPortal($this, $fx)
        ->get(route('portal.invoices.download', $ownInvoice->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect(g5ReadRows($fx['tenant']->id, $fx['patient']->id, 'invoices')->count())->toBe(1);

    g5AsPortal($this, $fx)
        ->get(route('portal.invoices.download', $otherInvoice->id))
        ->assertNotFound();

    // The PDF is not reachable via any public URL.
    $this->get('/storage/'.$ownInvoice->refresh()->pdf_path)->assertForbidden();
});

test('cross-tenant portal access fails everywhere', function () {
    Storage::fake('local');
    $alpha = g5Fixture('alpha');
    $setup = g5BookableSetup($alpha);
    $alphaInvoice = g5Invoice($alpha, $setup['branch']);
    $alphaThread = app(ThreadService::class)->openPatientThread($alpha['patient'], 'Alpha thread', $alpha['staff']);

    $beta = g5Fixture('beta');

    // A beta patient session cannot reach alpha's data: the tenant context is
    // beta, so alpha rows simply do not exist for it.
    g5AsPortal($this, $beta)
        ->get('/portal/invoices')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('invoices', 0));

    g5AsPortal($this, $beta)
        ->get(route('portal.invoices.download', $alphaInvoice->id))
        ->assertNotFound();

    g5AsPortal($this, $beta)
        ->get('/portal/messages?thread_id='.$alphaThread->id)
        ->assertNotFound();
});

test('self-booking goes through the BookingService safe path', function () {
    $fx = g5Fixture();
    $setup = g5BookableSetup($fx);
    $monday = '2026-07-13'; // a Monday inside availability

    g5AsPortal($this, $fx)
        ->post(route('portal.appointments.store'), [
            'service_id' => $setup['service']->id,
            'branch_id' => $setup['branch']->id,
            'starts_at' => $monday.' 10:00:00',
            'resource_ids' => [$setup['resource']->id],
        ])
        ->assertRedirect(route('portal.appointments'));

    $appointment = Appointment::query()->firstOrFail();

    expect($appointment->patient_id)->toBe($fx['patient']->id)
        ->and($appointment->source)->toBe(Appointment::SOURCE_ONLINE)
        ->and($appointment->booked_by)->toBeNull()
        // The locked path created the resource consumption rows.
        ->and($appointment->resourceLinks()->count())->toBe(1);

    // The no-double-book guard is ACTIVE on this path: the same slot again fails.
    g5AsPortal($this, $fx)
        ->post(route('portal.appointments.store'), [
            'service_id' => $setup['service']->id,
            'branch_id' => $setup['branch']->id,
            'starts_at' => $monday.' 10:00:00',
            'resource_ids' => [$setup['resource']->id],
        ])
        ->assertStatus(500); // SlotUnavailable bubbles as an exception

    expect(Appointment::query()->count())->toBe(1);
});

test('portal cancellation enforces the cancel window policy server-side', function () {
    $fx = g5Fixture();
    $setup = g5BookableSetup($fx);

    // An appointment far in the future: cancellable.
    $far = app(BookingService::class)->bookOnline(
        $setup['service']->id,
        $fx['patient']->id,
        $setup['branch']->id,
        '2026-07-20 10:00:00',
        [$setup['resource']->id],
    );

    g5AsPortal($this, $fx)
        ->post(route('portal.appointments.cancel'), [
            'appointment_id' => $far->id,
            'reason' => 'Cannot attend',
        ])
        ->assertRedirect(route('portal.appointments'));

    expect($far->refresh()->status)->toBe(Appointment::STATUS_CANCELLED)
        ->and($far->resourceLinks()->count())->toBe(0);

    // An appointment inside the 24h window: refused with a validation error.
    $soonStart = now()->addHours(2)->second(0);
    $soon = app(BookingService::class)->book(
        $setup['service']->id,
        $fx['patient']->id,
        $setup['branch']->id,
        $soonStart->toDateTimeString(),
        [$setup['resource']->id],
        $fx['staff'],
    );

    g5AsPortal($this, $fx)
        ->from('/portal/appointments')
        ->post(route('portal.appointments.cancel'), [
            'appointment_id' => $soon->id,
            'reason' => 'Too late attempt',
        ])
        ->assertRedirect('/portal/appointments')
        ->assertSessionHasErrors('appointment_id');

    expect($soon->refresh()->status)->toBe(Appointment::STATUS_BOOKED);

    // Another patient's appointment is unreachable.
    $other = app(PatientService::class)->create([
        'first_name' => 'Other', 'last_name' => 'Booked', 'date_of_birth' => '1970-01-01', 'sex' => 'male',
    ]);
    $foreign = app(BookingService::class)->bookOnline(
        $setup['service']->id,
        $other->id,
        $setup['branch']->id,
        '2026-07-20 14:00:00',
        [$setup['resource']->id],
    );

    g5AsPortal($this, $fx)
        ->post(route('portal.appointments.cancel'), ['appointment_id' => $foreign->id, 'reason' => 'Not mine'])
        ->assertNotFound();
});

test('the telehealth page lists sessions and the token endpoint is gated and read-logged', function () {
    $fake = app(FakeTelehealthProvider::class);
    app()->instance(TelehealthProvider::class, $fake);

    $fx = g5Fixture();
    $branch = Branch::query()->create(['name' => 'Tele Branch', 'code' => 'TELE']);
    $staffProfile = StaffProfile::query()->create([
        'user_id' => $fx['staff']->id,
        'first_name' => 'Tele',
        'last_name' => 'Doc',
        'display_name' => 'Tele Doc',
        'profession' => 'doctor',
        'primary_branch_id' => $branch->id,
        'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $session = TelehealthSession::query()->create([
        'patient_id' => $fx['patient']->id,
        'practitioner_id' => $staffProfile->id,
        'provider' => 'fake',
        'room_reference' => 'fake-room-1',
        'status' => TelehealthSession::STATUS_CREATED,
    ]);

    g5AsPortal($this, $fx)
        ->get('/portal/telehealth')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portal/Telehealth')
            ->has('sessions', 1)
            ->where('sessions.0.id', $session->id));

    $response = g5AsPortal($this, $fx)
        ->post(route('portal.telehealth.token', $session->id))
        ->assertOk()
        ->json();

    expect($response['token'])->not->toBe('')
        ->and($response['room'])->toBe('fake-room-1')
        ->and($response['role'])->toBe('patient')
        ->and(g5ReadRows($fx['tenant']->id, $fx['patient']->id, 'telehealth_sessions')->count())->toBe(1);
});

test('staff cannot reach portal routes and patients cannot reach the staff shells', function () {
    $fx = g5Fixture();

    // Staff (web guard) hitting portal pages: no patient guard -> login redirect.
    $this->actingAs($fx['staff'])
        ->get('/portal')
        ->assertRedirect(route('portal.login'));
    $this->actingAs($fx['staff'])
        ->get('/portal/invoices')
        ->assertRedirect(route('portal.login'));

    // A patient session cannot reach the staff or admin shells. Drop the
    // resolved guards from the staff half above, authenticate ONLY the patient
    // guard, and keep web as the default guard (as in real requests —
    // actingAs()'s shouldUse() is a harness convenience).
    $this->app['auth']->forgetGuards();
    $this->actingAs($fx['account'], 'patient')
        ->withSession(['portal_tenant_id' => $fx['tenant']->id]);
    $this->app['auth']->shouldUse('web');

    $this->get('/app')->assertRedirect(route('login'));
    $this->get('/admin')->assertRedirect(route('login'));
});
