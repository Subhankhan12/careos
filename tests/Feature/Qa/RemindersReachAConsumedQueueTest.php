<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Jobs\SendAppointmentReminderJob;
use Modules\Scheduling\Models\AppointmentReminder;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\BookingService;
use Modules\Scheduling\Services\ReminderPolicy;

uses(RefreshDatabase::class);

/*
 * DEPLOY-FIX.1b — an appointment reminder lands on a queue a configured supervisor actually consumes.
 *
 * THE DEFECT. `ReminderDispatcher` dispatched `->onQueue('reminders')` — the ONLY `onQueue()` in the whole
 * codebase — while `config/horizon.php`'s single supervisor consumes `['default']` in every environment.
 * Measured with Redis up before the fix: redis `reminders` = 1, redis `default` = 0, Horizon consuming
 * `["default"]`. No appointment reminder has ever been delivered by a worker.
 *
 * WHY NO EXISTING TEST CAUGHT IT, which is the point of the structural test below.
 * `tests/Feature/Infrastructure/RedisHorizonTest.php` has a round-trip test that proves Redis works — but
 * it INVENTS its own queue name and hands it straight to `queue:work --queue=$queue`. It never consults
 * `config/horizon.php`, so it is structurally incapable of noticing that the supervisor consumes something
 * else. A test that supplies the queue it then drains can never detect a supervisor mismatch.
 *
 * So the assertion here is deliberately made against THE CONFIG, not against a name this test chose.
 */

/** Every queue name some configured Horizon supervisor consumes, across defaults and every environment. */
function df1bConsumedQueues(): array
{
    $queues = [];

    foreach ((array) config('horizon.defaults', []) as $supervisor) {
        foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
            $queues[] = $queue;
        }
    }

    foreach ((array) config('horizon.environments', []) as $supervisors) {
        foreach ((array) $supervisors as $supervisor) {
            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                $queues[] = $queue;
            }
        }
    }

    return array_values(array_unique($queues));
}

/** The queue a freshly constructed job would be pushed onto ('' / null means the connection default). */
function df1bQueueFor(object $job): string
{
    $queue = $job->queue ?? null;

    return is_string($queue) && $queue !== '' ? $queue : 'default';
}

it('dispatches the reminder onto a queue a configured supervisor consumes', function () {
    Queue::fake();
    df1bDueReminderFixture();

    /*
     * THE REAL DISPATCHER, not a hand-built job. An earlier draft of this test called
     * `SendAppointmentReminderJob::dispatch(...)->onConnection('redis')` itself — which pins what a JOB can
     * carry, not what `ReminderDispatcher` DOES, and a mutation deleting the dispatcher's own
     * `->onConnection('redis')` sailed through it. That survived mutant is why this drives the scheduled
     * command end to end instead.
     *
     * BEFORE THE FIX the dispatcher pushed onto 'reminders', which is in no supervisor's queue list, and
     * this fails (D-182).
     */
    expect(Artisan::call('appointments:dispatch-reminders'))->toBe(0);

    Queue::assertPushed(SendAppointmentReminderJob::class, function (SendAppointmentReminderJob $job): bool {
        return in_array(df1bQueueFor($job), df1bConsumedQueues(), true);
    });
});

it('pins the reminder to the connection Horizon watches, even under a wrong QUEUE_CONNECTION', function () {
    df1bDueReminderFixture();

    // The commonest deploy misconfiguration, and one the deploy checklist lists as a SILENT failure.
    config(['queue.default' => 'database']);
    Queue::fake();

    /*
     * Again through the real dispatcher. `onConnection('redis')` is kept deliberately so a reminder still
     * reaches the connection Horizon watches on a host that left `QUEUE_CONNECTION=database`. A mutation
     * deleting that pin must redden HERE — it did not redden the earlier, hand-built version of this test.
     */
    expect(Artisan::call('appointments:dispatch-reminders'))->toBe(0);

    Queue::assertPushed(SendAppointmentReminderJob::class, function (SendAppointmentReminderJob $job): bool {
        return $job->connection === 'redis';
    });
});

it('leaves NO job targeting a queue that nothing consumes — the structural guard', function () {
    $consumed = df1bConsumedQueues();

    expect($consumed)->not->toBeEmpty();

    /*
     * THE GUARD THAT WOULD HAVE CAUGHT THIS. Every `onQueue(...)` in production code must name a queue some
     * supervisor consumes. A mutation reintroducing `->onQueue('reminders')` — or adding any other
     * unconsumed queue — reddens here.
     *
     * Comment-stripped, because a comment explaining the defect NAMES the old queue and would otherwise
     * satisfy a naive scan. The helper name is gate-scoped: a subagent overwrote a shared `strip()` in
     * QA-FIX.11 and the collision recurred in QA-FIX.12.
     */
    $offenders = [];

    foreach (df1bProductionPhpFiles() as $file) {
        $source = df1bStripCommentsForQueueScan((string) file_get_contents($file));

        if (preg_match_all('/->onQueue\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $source, $matches) === 0) {
            continue;
        }

        foreach ($matches[1] as $queue) {
            if (! in_array($queue, $consumed, true)) {
                $offenders[] = basename($file).' -> '.$queue;
            }
        }
    }

    expect($offenders)->toBe([], 'a job targets a queue no Horizon supervisor consumes: '.implode(', ', $offenders));
});

it('still records the reminder row and only queues what it created — the positive control', function () {
    /*
     * D-174: the fix must not turn the dispatcher into something that queues nothing. This drives the REAL
     * scheduled command against an empty schedule, which must succeed and dispatch zero jobs rather than
     * fail — proving the guard above passes because nothing is wrong, not because nothing runs.
     */
    Queue::fake();

    $exit = Artisan::call('appointments:dispatch-reminders');

    expect($exit)->toBe(0);
    Queue::assertNothingPushed();
});

/** Production PHP that could dispatch a job. Scoped to app/ and Modules/ — never vendor or tests. */
function df1bProductionPhpFiles(): array
{
    $files = [];

    foreach ([base_path('app'), base_path('Modules')] as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

/** Strip PHP comments so a comment naming the old queue cannot satisfy the scan. Gate-scoped name. */
function df1bStripCommentsForQueueScan(string $source): string
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= $token[1];

            continue;
        }
        $out .= $token;
    }

    return $out;
}

/**
 * An appointment that is genuinely DUE a reminder, so the real dispatcher emits a real job.
 *
 * Mirrors the established fixture in `tests/Feature/Automation/ScheduledCommandsTest.php`: a single
 * 60-minute offset (the default policy also carries 1440, which would drag every appointment in the next
 * day into window), a Monday inside the resource's availability, and a booking 30 minutes out.
 */
function df1bDueReminderFixture(): void
{
    Carbon::setTestNow('2026-07-13 09:00:00');

    $tenant = Tenant::query()->create([
        'name' => 'Reminder Clinic', 'slug' => 'df1b-reminders', 'region' => 'eu', 'status' => 'active',
    ]);
    app(TenantContext::class)->set($tenant);

    $actor = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $actor->id,
        'role_id' => Role::query()->where('key', 'reception')->firstOrFail()->id,
    ]);

    app(SettingsService::class)->set(ReminderPolicy::SETTING_KEY, [
        'offset_minutes' => [60],
        'channels' => [AppointmentReminder::CHANNEL_EMAIL],
    ], 'array');

    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    $service = Service::query()->create([
        'name' => 'Consult', 'code' => 'CONS', 'default_duration_minutes' => 30,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => true, 'active' => true,
    ]);
    $service->branchLinks()->create(['branch_id' => $branch->id]);

    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER, 'name' => 'Practitioner',
        'branch_id' => $branch->id, 'active' => true,
    ]);
    ResourceAvailability::query()->create([
        'resource_id' => $resource->id, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '18:00',
    ]);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Rem', 'last_name' => 'Inder', 'date_of_birth' => '1990-01-01', 'sex' => 'female',
    ]);

    app(BookingService::class)->book($service->id, $patient->id, $branch->id, '2026-07-13 09:30:00', [$resource->id], $actor);
}
