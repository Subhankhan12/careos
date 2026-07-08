<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Jobs\SendAppointmentReminderJob;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentReminder;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Notifications\AppointmentReminderNotification;
use Modules\Scheduling\Services\AppointmentService;
use Modules\Scheduling\Services\BookingService;
use Modules\Scheduling\Services\ReminderChannelManager;
use Modules\Scheduling\Services\ReminderDispatcher;
use Modules\Scheduling\Services\ReminderPolicy;

uses(RefreshDatabase::class);

function c5Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function c5Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function c5User(Tenant $tenant): User
{
    $user = User::factory()->forTenant($tenant)->create();

    RoleAssignment::create([
        'user_id' => $user->id,
        'role_id' => Role::where('key', 'reception')->firstOrFail()->id,
    ]);

    return $user;
}

function c5Branch(string $code = 'MAIN'): Branch
{
    return Branch::create(['name' => $code.' Branch', 'code' => $code]);
}

function c5Service(array $overrides = []): Service
{
    return Service::create([
        'name' => 'Consult',
        'code' => 'CONS',
        'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => true,
        'active' => true,
        ...$overrides,
    ]);
}

function c5Resource(Branch $branch): BookableResource
{
    $resource = BookableResource::create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Practitioner',
        'branch_id' => $branch->id,
        'active' => true,
    ]);

    ResourceAvailability::create([
        'resource_id' => $resource->id,
        'weekday' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    return $resource;
}

function c5Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create(
        [
            'first_name' => 'Riley',
            'last_name' => 'Reminder',
            'date_of_birth' => '1992-04-05',
            'sex' => 'female',
            ...$overrides,
        ],
        [['type' => 'email', 'value' => 'riley@example.test', 'is_primary' => true]],
    );
}

function c5Book(
    Service $service,
    Patient $patient,
    Branch $branch,
    BookableResource $resource,
    User $user,
    string $startsAt = '2026-07-13 10:00:00',
): Appointment {
    return app(BookingService::class)->book(
        $service->id,
        $patient->id,
        $branch->id,
        $startsAt,
        [$resource->id],
        $user,
    );
}

function c5SetReminderPolicy(array $offsets = [60], array $channels = [AppointmentReminder::CHANNEL_EMAIL]): void
{
    app(SettingsService::class)->set(ReminderPolicy::SETTING_KEY, [
        'offset_minutes' => $offsets,
        'channels' => $channels,
    ], 'array');
}

function c5GrantCommsConsent(Patient $patient, User $user): void
{
    ConsentTemplate::create([
        'key' => 'communications',
        'title' => 'Communications',
        'body' => 'Patient communications consent.',
        'version' => 1,
        'scope_keys' => ['comms.email'],
        'is_active' => true,
    ]);

    app(ConsentService::class)->grant($patient, 'communications', 'Riley Reminder', $user);
}

function c5ReminderFor(Appointment $appointment): AppointmentReminder
{
    return AppointmentReminder::query()->where('appointment_id', $appointment->id)->firstOrFail();
}

function c5RedisReachable(): bool
{
    try {
        Redis::connection('default')->command('PING');

        return true;
    } catch (Throwable) {
        return false;
    }
}

test('dispatcher enqueues reminders for in window appointments from tenant policy', function () {
    $this->travelTo('2026-07-13 09:00:00');
    Queue::fake();

    $tenant = c5Tenant('alpha');
    c5Ctx()->set($tenant);
    $user = c5User($tenant);
    $branch = c5Branch();
    $service = c5Service();
    $resource = c5Resource($branch);
    $patient = c5Patient();
    c5GrantCommsConsent($patient, $user);
    $appointment = c5Book($service, $patient, $branch, $resource, $user);
    c5SetReminderPolicy([60]);

    expect(app(ReminderDispatcher::class)->dispatchDue(now()))->toBe(1);

    $reminder = c5ReminderFor($appointment);

    expect($reminder->type)->toBe('before_1h')
        ->and($reminder->channel)->toBe(AppointmentReminder::CHANNEL_EMAIL)
        ->and($reminder->status)->toBe(AppointmentReminder::STATUS_PENDING);

    Queue::assertPushed(
        SendAppointmentReminderJob::class,
        fn (SendAppointmentReminderJob $job): bool => $job->tenantId === $tenant->id
            && $job->reminderId === $reminder->id,
    );
});

test('send job is fail closed without comms email consent', function () {
    $this->travelTo('2026-07-13 09:00:00');
    Notification::fake();
    Queue::fake();

    $tenant = c5Tenant('alpha');
    c5Ctx()->set($tenant);
    $user = c5User($tenant);
    $branch = c5Branch();
    $service = c5Service();
    $resource = c5Resource($branch);
    $patient = c5Patient();
    $appointment = c5Book($service, $patient, $branch, $resource, $user);
    c5SetReminderPolicy([60]);
    app(ReminderDispatcher::class)->dispatchDue(now());
    $reminder = c5ReminderFor($appointment);

    (new SendAppointmentReminderJob($tenant->id, $reminder->id))->handle(
        app(TenantContext::class),
        app(ConsentService::class),
        app(ReminderChannelManager::class),
    );

    Notification::assertNothingSent();
    expect($reminder->refresh()->status)->toBe(AppointmentReminder::STATUS_SKIPPED)
        ->and($reminder->failure_reason)->toBe('Missing comms.email consent.');
});

test('reminder jobs are idempotent and never double send', function () {
    $this->travelTo('2026-07-13 09:00:00');
    Notification::fake();
    Queue::fake();

    $tenant = c5Tenant('alpha');
    c5Ctx()->set($tenant);
    $user = c5User($tenant);
    $branch = c5Branch();
    $service = c5Service();
    $resource = c5Resource($branch);
    $patient = c5Patient();
    c5GrantCommsConsent($patient, $user);
    $appointment = c5Book($service, $patient, $branch, $resource, $user);
    c5SetReminderPolicy([60]);

    expect(app(ReminderDispatcher::class)->dispatchDue(now()))->toBe(1)
        ->and(app(ReminderDispatcher::class)->dispatchDue(now()))->toBe(0);

    $reminder = c5ReminderFor($appointment);
    $job = new SendAppointmentReminderJob($tenant->id, $reminder->id);

    $job->handle(app(TenantContext::class), app(ConsentService::class), app(ReminderChannelManager::class));
    $job->handle(app(TenantContext::class), app(ConsentService::class), app(ReminderChannelManager::class));

    Notification::assertSentOnDemand(AppointmentReminderNotification::class, 1);
    expect($reminder->refresh()->status)->toBe(AppointmentReminder::STATUS_SENT)
        ->and(AppointmentReminder::query()->count())->toBe(1);
});

test('cancelled and rescheduled appointments do not fire stale reminders', function () {
    $this->travelTo('2026-07-13 09:00:00');
    Notification::fake();
    Queue::fake();

    $tenant = c5Tenant('alpha');
    c5Ctx()->set($tenant);
    $user = c5User($tenant);
    $branch = c5Branch();
    $service = c5Service();
    $resource = c5Resource($branch);
    $patient = c5Patient();
    c5GrantCommsConsent($patient, $user);
    $cancelled = c5Book($service, $patient, $branch, $resource, $user);
    c5SetReminderPolicy([60]);
    app(ReminderDispatcher::class)->dispatchDue(now());
    $reminder = c5ReminderFor($cancelled);

    app(AppointmentService::class)->cancel($cancelled, $user, 'not needed');

    (new SendAppointmentReminderJob($tenant->id, $reminder->id))->handle(
        app(TenantContext::class),
        app(ConsentService::class),
        app(ReminderChannelManager::class),
    );

    $rescheduled = c5Book($service, $patient, $branch, $resource, $user, '2026-07-13 11:00:00');
    app(AppointmentService::class)->reschedule(
        $rescheduled,
        '2026-07-13 12:00:00',
        [$resource->id],
        $user,
        'move',
    );

    expect(app(ReminderDispatcher::class)->dispatchDue(now()))->toBe(0)
        ->and($reminder->refresh()->status)->toBe(AppointmentReminder::STATUS_SKIPPED);

    Notification::assertNothingSent();
});

test('reminders are tenant isolated and fail closed', function () {
    $this->travelTo('2026-07-13 09:00:00');
    Queue::fake();

    $alpha = c5Tenant('alpha');
    $beta = c5Tenant('beta');

    c5Ctx()->set($alpha);
    $alphaUser = c5User($alpha);
    $alphaBranch = c5Branch('A');
    $alphaService = c5Service();
    $alphaResource = c5Resource($alphaBranch);
    $alphaPatient = c5Patient();
    c5GrantCommsConsent($alphaPatient, $alphaUser);
    $alphaAppointment = c5Book($alphaService, $alphaPatient, $alphaBranch, $alphaResource, $alphaUser);
    c5SetReminderPolicy([60]);
    app(ReminderDispatcher::class)->dispatchDue(now());

    c5Ctx()->set($beta);
    $betaUser = c5User($beta);
    $betaBranch = c5Branch('B');
    $betaService = c5Service(['code' => 'B-CONS']);
    $betaResource = c5Resource($betaBranch);
    $betaPatient = c5Patient(['last_name' => 'Beta']);
    c5GrantCommsConsent($betaPatient, $betaUser);
    c5Book($betaService, $betaPatient, $betaBranch, $betaResource, $betaUser);
    c5SetReminderPolicy([60]);
    app(ReminderDispatcher::class)->dispatchDue(now());

    expect(AppointmentReminder::query()->count())->toBe(1)
        ->and(AppointmentReminder::query()->where('appointment_id', $alphaAppointment->id)->exists())->toBeFalse();

    c5Ctx()->forget();

    expect(fn () => AppointmentReminder::query()->count())
        ->toThrow(TenantContextMissingException::class);
});

test('redis queue can run one appointment reminder job round trip when available', function () {
    config([
        'queue.default' => 'redis',
        'queue.connections.redis.connection' => 'default',
        'database.redis.client' => 'predis',
        'database.redis.default.host' => env('REDIS_HOST', '127.0.0.1'),
        'database.redis.default.port' => env('REDIS_PORT', '6379'),
    ]);
    app('queue')->setDefaultDriver('redis');

    if (! c5RedisReachable()) {
        $this->markTestSkipped('Redis is not reachable on 127.0.0.1:6379.');
    }

    Notification::fake();
    $this->travelTo('2026-07-13 09:00:00');

    $tenant = c5Tenant('alpha');
    c5Ctx()->set($tenant);
    $user = c5User($tenant);
    $branch = c5Branch();
    $service = c5Service();
    $resource = c5Resource($branch);
    $patient = c5Patient();
    c5GrantCommsConsent($patient, $user);
    $appointment = c5Book($service, $patient, $branch, $resource, $user);
    c5SetReminderPolicy([60]);
    $queue = 'careos-c5-test-'.Str::lower(Str::random(8));
    $reminder = AppointmentReminder::create([
        'appointment_id' => $appointment->id,
        'type' => 'before_1h',
        'channel' => AppointmentReminder::CHANNEL_EMAIL,
        'scheduled_for' => now(),
    ]);

    SendAppointmentReminderJob::dispatch($tenant->id, $reminder->id)
        ->onConnection('redis')
        ->onQueue($queue);

    Artisan::call('queue:work', [
        'connection' => 'redis',
        '--queue' => $queue,
        '--once' => true,
        '--tries' => 1,
    ]);

    expect($reminder->refresh()->status)->toBe(AppointmentReminder::STATUS_SENT);
    Notification::assertSentOnDemand(AppointmentReminderNotification::class, 1);
});
