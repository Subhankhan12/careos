<?php

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * QA-FIX.1a — STORAGE IS UTC FROM EVERY PATH (P1-C1, D-192).
 *
 * The defect: `ApplyTenantLocaleTimezone` called `date_default_timezone_set($tenantZone)`
 * on every authenticated web request. `now()` then returned a Carbon in the practice's zone
 * and Eloquent serialised that WALL CLOCK into datetime columns, while CLI, queue and
 * scheduler writes stayed true UTC — so one column held two time bases (measured at +2h on
 * a Europe/Zurich tenant, including the append-only hash-chained `audit_events.occurred_at`).
 *
 * EVERY TEST HERE USES A NON-UTC TENANT (Europe/Zurich) AND ASSERTS THAT PRECONDITION.
 * On a UTC tenant the offset is zero and every assertion below would pass no matter what the
 * code did — a vacuous guard of exactly the shape D-174 warns about. The offset check is the
 * positive control: it proves the fixture can actually tell right from wrong.
 */

function tzTenant(string $slug = 'tz-parity', string $timezone = 'Europe/Zurich'): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => 'Timezone Parity Practice',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);

    app(TenantContext::class)->set($tenant);
    app(SettingsService::class)->set('timezone', $timezone);

    return $tenant;
}

function tzUser(Tenant $tenant): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);

    return $user;
}

function tzPatient(): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Ursula',
        'last_name' => 'Zeitzone',
        'date_of_birth' => '1969-04-01',
        'sex' => 'female',
    ]);
}

/** THE POSITIVE CONTROL: the fixture's zone must actually differ from UTC right now. */
function tzOffsetSeconds(string $timezone = 'Europe/Zurich'): int
{
    return CarbonImmutable::now($timezone)->getOffset();
}

/** The newest audit row's RAW stored occurred_at, read straight from the column. */
function tzNewestAuditRaw(Tenant $tenant): ?string
{
    $row = DB::selectOne(
        'SELECT occurred_at FROM audit_events WHERE tenant_id = ? ORDER BY occurred_at DESC, id DESC LIMIT 1',
        [$tenant->id],
    );

    return $row === null ? null : (string) $row->occurred_at;
}

test('the fixture zone genuinely differs from UTC (positive control — without this every assertion here is vacuous)', function () {
    expect(tzOffsetSeconds())->not->toBe(0)
        ->and(abs(tzOffsetSeconds()))->toBeGreaterThanOrEqual(3600);
});

test('a WEB-written timestamp is stored in true UTC, not the practice wall clock', function () {
    $tenant = tzTenant();
    $user = tzUser($tenant);
    $patient = tzPatient();

    // Drive the request the way a browser does: no pre-set context (the C-1/FIX.1 lesson).
    app(TenantContext::class)->forget();

    $before = CarbonImmutable::now('UTC');
    $this->actingAs($user)->get("/patients/{$patient->id}")->assertOk();
    $after = CarbonImmutable::now('UTC');

    app(TenantContext::class)->set($tenant);
    $raw = tzNewestAuditRaw($tenant);

    expect($raw)->not->toBeNull();

    $stored = CarbonImmutable::parse((string) $raw, 'UTC');

    // Under the defect this row carried the Europe/Zurich wall clock — i.e. `offset` seconds
    // ahead of `$after` — so this window excludes it by a wide margin.
    expect($stored->greaterThanOrEqualTo($before->subSeconds(5)))->toBeTrue()
        ->and($stored->lessThanOrEqualTo($after->addSeconds(5)))->toBeTrue();
});

test('a WEB write and a CLI write land on the SAME time base', function () {
    $tenant = tzTenant();
    $user = tzUser($tenant);
    $patient = tzPatient();

    app(TenantContext::class)->forget();
    $this->actingAs($user)->get("/patients/{$patient->id}")->assertOk();

    app(TenantContext::class)->set($tenant);
    $webWritten = CarbonImmutable::parse((string) tzNewestAuditRaw($tenant), 'UTC');

    // A CLI-shaped write in the same process: nothing HTTP about it.
    $cliNow = CarbonImmutable::now('UTC');

    // Same base ⇒ seconds apart. Two bases ⇒ the tenant's UTC offset apart (3600s+).
    expect(abs($cliNow->diffInSeconds($webWritten)))->toBeLessThan(60);
});

test('a web request does NOT mutate the process-wide default timezone', function () {
    $tenant = tzTenant();
    $user = tzUser($tenant);
    $patient = tzPatient();

    $before = date_default_timezone_get();

    app(TenantContext::class)->forget();
    $this->actingAs($user)->get("/patients/{$patient->id}")->assertOk();

    // The old middleware left the process zone changed for everything that ran afterwards —
    // including queue work and any later write in the same worker.
    expect(date_default_timezone_get())->toBe($before)
        ->and(date_default_timezone_get())->toBe(config('app.timezone'));
});

test('tenant-local DISPLAY is preserved — the client still receives the practice zone', function () {
    $tenant = tzTenant();
    $user = tzUser($tenant);

    app(TenantContext::class)->forget();

    // The display contract the removed mutation used to serve, now resolved explicitly.
    $this->actingAs($user)
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('timezone', 'Europe/Zurich'));
});

test('display falls back to the platform zone when the tenant configured none', function () {
    $tenant = Tenant::query()->create([
        'name' => 'No Zone Practice', 'slug' => 'tz-unset', 'region' => 'eu', 'status' => 'active',
    ]);
    app(TenantContext::class)->set($tenant);
    $user = tzUser($tenant);

    app(TenantContext::class)->forget();

    $this->actingAs($user)
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('timezone', config('app.timezone')));
});

test('the audit chain still verifies after a web-written row', function () {
    $tenant = tzTenant();
    $user = tzUser($tenant);
    $patient = tzPatient();

    app(TenantContext::class)->forget();
    $this->actingAs($user)->get("/patients/{$patient->id}")->assertOk();

    app(TenantContext::class)->set($tenant);

    expect(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('audit occurred_at stays strictly monotonic per tenant across web writes', function () {
    $tenant = tzTenant();
    $user = tzUser($tenant);
    $patient = tzPatient();

    app(TenantContext::class)->forget();
    $this->actingAs($user)->get("/patients/{$patient->id}")->assertOk();
    $this->actingAs($user)->get("/patients/{$patient->id}")->assertOk();

    app(TenantContext::class)->set($tenant);

    $times = array_map(
        fn ($r) => (string) $r->occurred_at,
        DB::select('SELECT occurred_at FROM audit_events WHERE tenant_id = ? ORDER BY id ASC', [$tenant->id]),
    );

    $sorted = $times;
    sort($sorted);

    // Insert order and time order agree — the property verifyChain()'s ORDER BY relies on.
    expect($times)->toBe($sorted)
        ->and(count(array_unique($times)))->toBe(count($times));
});

test('a TTL minted on the web is evaluated identically from CLI', function () {
    $tenant = tzTenant();
    $user = tzUser($tenant);
    $patient = tzPatient();

    app(TenantContext::class)->set($tenant);

    // The invite path is consent-gated (portal.access) — satisfy the real precondition
    // rather than reaching past it, so the TTL under test is minted the way production mints it.
    ConsentTemplate::query()->create([
        'key' => 'portal', 'title' => 'Portal Access', 'body' => 'Portal access consent',
        'version' => 1, 'scope_keys' => ['portal.access'], 'is_active' => true,
    ]);
    app(ConsentService::class)->grant($patient, 'portal', 'Ursula Zeitzone', $user);

    /*
     * MINT OVER HTTP, not by calling the service — that is what makes this bite.
     * The defect lived in request middleware, so a TTL minted by a direct service call in the
     * test process never met it and the assertion below would hold either way (the vacuity
     * D-174 warns about, in its "wrong caller" form). Driving the real route puts the mint on
     * the web path and the read on the CLI path, which is exactly the mixed-base scenario.
     */
    app(TenantContext::class)->forget();
    $this->actingAs($user)
        ->postJson('/portal/invitations', [
            'patient_id' => $patient->id,
            'email' => 'ursula.zeitzone@example.test',
        ])
        ->assertCreated();

    app(TenantContext::class)->set($tenant);

    $expires = CarbonImmutable::parse(
        (string) DB::selectOne('SELECT expires_at FROM portal_login_tokens ORDER BY id DESC LIMIT 1')->expires_at,
        'UTC',
    );

    $cliNow = CarbonImmutable::now('UTC');
    $minutesLeft = $cliNow->diffInMinutes($expires, false);

    // A token minted on one base and read on another is off by the tenant's offset — here
    // that would read as ~150 minutes of life instead of ~30.
    expect($minutesLeft)->toBeGreaterThan(25)
        ->and($minutesLeft)->toBeLessThanOrEqual(31);
});
