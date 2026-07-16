<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentSeries;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AppointmentSeriesService;
use Modules\Scheduling\Services\AppointmentService;
use Modules\Scheduling\Services\BookingService;

uses(RefreshDatabase::class);

function srCtx(): TenantContext
{
    return app(TenantContext::class);
}

function srTenant(string $slug): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    srCtx()->set($tenant);

    return $tenant;
}

function srUser(Tenant $tenant, string $role = 'reception'): User
{
    srCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

function srBranch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code, 'timezone' => 'Europe/Zurich']);
}

function srService(): Service
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

function srResource(Branch $branch): BookableResource
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

function srPatient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Series',
        'last_name' => 'Patient',
        'date_of_birth' => '1990-05-15',
        'sex' => 'female',
        ...$overrides,
    ]);
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, service: Service, resource: BookableResource, patient: Patient}
 */
function srFixture(string $slug = 'alpha'): array
{
    $tenant = srTenant($slug);
    $actor = srUser($tenant);
    $branch = srBranch(strtoupper(substr($slug, 0, 4)));
    $service = srService();
    $resource = srResource($branch);
    $patient = srPatient();

    return compact('tenant', 'actor', 'branch', 'service', 'resource', 'patient');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function srWeekly(array $fx, array $overrides = []): array
{
    return [
        'patient_id' => $fx['patient']->id,
        'service_id' => $fx['service']->id,
        'branch_id' => $fx['branch']->id,
        'resource_ids' => [$fx['resource']->id],
        'frequency' => 'weekly',
        'interval' => 1,
        'byday' => ['TU'],
        'start_time' => '09:00',
        'starts_on' => '2026-07-14', // a Tuesday
        'end_type' => 'count',
        'count' => 3,
        'timezone' => 'Europe/Zurich',
        ...$overrides,
    ];
}

test('a weekly series books the right individual appointments through BookingService', function () {
    $fx = srFixture();

    $result = app(AppointmentSeriesService::class)->create(srWeekly($fx), $fx['actor']);

    expect($result['booked'])->toHaveCount(3)
        ->and($result['failures'])->toBe([])
        ->and(collect($result['booked'])->pluck('date')->all())->toBe(['2026-07-14', '2026-07-21', '2026-07-28']);

    $appointments = Appointment::query()->where('series_id', $result['series']->id)->orderBy('starts_at')->get();
    expect($appointments)->toHaveCount(3)
        ->and($appointments->pluck('status')->unique()->all())->toBe([Appointment::STATUS_BOOKED])
        ->and($appointments->pluck('occurrence_date')->map->toDateString()->all())->toBe(['2026-07-14', '2026-07-21', '2026-07-28'])
        // Each went through BookingService: it stamps a check_in_code and links a resource.
        ->and($appointments->every(fn (Appointment $a): bool => $a->check_in_code !== null))->toBeTrue()
        ->and($appointments->every(fn (Appointment $a): bool => $a->resourceLinks()->count() === 1))->toBeTrue();
});

test('a conflicting occurrence is reported (not silently dropped) and the rest still book', function () {
    $fx = srFixture();

    // Pre-occupy the SAME resource at the 2nd occurrence slot.
    app(BookingService::class)->book(
        $fx['service']->id, srPatient(['first_name' => 'Blocker'])->id, $fx['branch']->id,
        '2026-07-21 09:00:00', [$fx['resource']->id], $fx['actor'],
    );

    $result = app(AppointmentSeriesService::class)->create(srWeekly($fx), $fx['actor']);

    expect($result['booked'])->toHaveCount(2)
        ->and(collect($result['booked'])->pluck('date')->all())->toBe(['2026-07-14', '2026-07-28'])
        ->and($result['failures'])->toHaveCount(1)
        ->and($result['failures'][0]['date'])->toBe('2026-07-21')
        ->and($result['failures'][0]['reason'])->toBe('resource_taken');

    // The series owns exactly the two booked occurrences; the conflict is not silently a series row.
    expect(Appointment::query()->where('series_id', $result['series']->id)->count())->toBe(2);
});

test('a DST-boundary series keeps local wall-clock time', function () {
    $fx = srFixture();

    // Europe/Zurich springs forward on 2026-03-29; a Tuesday series spans it.
    $result = app(AppointmentSeriesService::class)->create(srWeekly($fx, [
        'starts_on' => '2026-03-24',
        'count' => 3,
    ]), $fx['actor']);

    $appointments = Appointment::query()->where('series_id', $result['series']->id)->orderBy('starts_at')->get();

    expect($appointments->pluck('occurrence_date')->map->toDateString()->all())
        ->toBe(['2026-03-24', '2026-03-31', '2026-04-07']); // correct Tuesdays across DST
    // Local wall-clock stays 09:00 on every occurrence.
    $appointments->each(function (Appointment $a): void {
        expect($a->starts_at->format('H:i:s'))->toBe('09:00:00');
    });
});

test('cancelling one occurrence leaves the series and the other occurrences intact', function () {
    $fx = srFixture();
    $result = app(AppointmentSeriesService::class)->create(srWeekly($fx), $fx['actor']);
    $series = $result['series'];

    $middle = Appointment::query()->where('series_id', $series->id)->where('occurrence_date', '2026-07-21')->firstOrFail();
    app(AppointmentService::class)->cancel($middle, $fx['actor'], 'patient away that week');

    expect($middle->refresh()->status)->toBe(Appointment::STATUS_CANCELLED)
        ->and($series->refresh()->status)->toBe(AppointmentSeries::STATUS_ACTIVE) // rule intact
        ->and(Appointment::query()->where('series_id', $series->id)->where('status', Appointment::STATUS_BOOKED)->count())->toBe(2);
});

test('ending a series stops future generation but keeps booked occurrences', function () {
    $fx = srFixture();
    $result = app(AppointmentSeriesService::class)->create(srWeekly($fx, ['count' => 2]), $fx['actor']);
    $series = $result['series'];
    expect(Appointment::query()->where('series_id', $series->id)->count())->toBe(2);

    app(AppointmentSeriesService::class)->end($series, $fx['actor']);
    expect($series->refresh()->status)->toBe(AppointmentSeries::STATUS_ENDED);

    // Widen the rule so more occurrences COULD be generated — but the series is ended.
    $series->forceFill(['rrule' => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=TU;COUNT=4'])->save();

    $blocked = app(AppointmentSeriesService::class)->materialize($series->refresh(), $fx['actor']);
    expect($blocked['booked'])->toBe([])
        ->and(Appointment::query()->where('series_id', $series->id)->count())->toBe(2); // booked ones untouched

    // Re-activating proves materialize WOULD have generated them — the ended guard was the block.
    $series->forceFill(['status' => AppointmentSeries::STATUS_ACTIVE])->save();
    $now = app(AppointmentSeriesService::class)->materialize($series->refresh(), $fx['actor']);
    expect($now['booked'])->toHaveCount(2)
        ->and(Appointment::query()->where('series_id', $series->id)->count())->toBe(4);
});

test('series creation is RBAC gated on appointment.manage', function () {
    $fx = srFixture();
    $billing = srUser($fx['tenant'], 'billing'); // no appointment.manage

    expect(fn () => app(AppointmentSeriesService::class)->create(srWeekly($fx), $billing))
        ->toThrow(AuthorizationException::class);

    expect(AppointmentSeries::query()->count())->toBe(0);
});

test('series are tenant isolated and creation is audited', function () {
    $alpha = srFixture('alpha');
    $result = app(AppointmentSeriesService::class)->create(srWeekly($alpha), $alpha['actor']);

    $created = DB::select("SELECT * FROM audit_events WHERE tenant_id = ? AND action = 'appointment_series.created'", [$alpha['tenant']->id]);
    $booked = DB::select("SELECT COUNT(*) c FROM audit_events WHERE tenant_id = ? AND action = 'appointment.booked'", [$alpha['tenant']->id]);

    expect($created)->toHaveCount(1)
        ->and($created[0]->patient_id)->toBe($alpha['patient']->id)
        ->and((int) $booked[0]->c)->toBe(3) // each occurrence booked through BookingService is audited
        ->and(app(AuditService::class)->verifyChain($alpha['tenant']->id)['ok'])->toBeTrue();

    // A second tenant sees none of alpha's series or occurrences.
    srTenant('beta');
    expect(AppointmentSeries::query()->count())->toBe(0)
        ->and(Appointment::query()->whereNotNull('series_id')->count())->toBe(0);
});

test('the preview reports per-occurrence free/conflict without booking anything', function () {
    $fx = srFixture();

    // Block the 2nd occurrence.
    app(BookingService::class)->book(
        $fx['service']->id, srPatient(['first_name' => 'Blocker'])->id, $fx['branch']->id,
        '2026-07-21 09:00:00', [$fx['resource']->id], $fx['actor'],
    );
    $before = Appointment::query()->count();

    $preview = app(AppointmentSeriesService::class)->preview(srWeekly($fx), $fx['actor']);

    expect($preview['occurrences'])->toHaveCount(3)
        ->and($preview['occurrences'][0]['free'])->toBeTrue()
        ->and($preview['occurrences'][1]['free'])->toBeFalse()
        ->and($preview['occurrences'][1]['reason'])->toBe('resource_taken')
        ->and($preview['occurrences'][2]['free'])->toBeTrue()
        // Preview booked nothing.
        ->and(Appointment::query()->count())->toBe($before)
        ->and(AppointmentSeries::query()->count())->toBe(0);
});
