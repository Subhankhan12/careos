<?php

use Database\Seeders\DemoClinicSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\IntegrityCheck;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function p3AlarmCtx(): TenantContext
{
    return app(TenantContext::class);
}

test('audit:verify-chains passes on the demo tenant and records ok=true', function () {
    Storage::fake('local');
    (new DemoClinicSeeder)->run();

    $tenant = Tenant::query()->where('slug', DemoClinicSeeder::TENANT_SLUG)->firstOrFail();

    p3AlarmCtx()->forget();
    expect(Artisan::call('audit:verify-chains'))->toBe(0);

    p3AlarmCtx()->set($tenant);
    $check = IntegrityCheck::query()->where('kind', IntegrityCheck::KIND_AUDIT_CHAIN)->firstOrFail();

    expect($check->ok)->toBeTrue()
        ->and($check->detail['events'])->toBeGreaterThan(0)
        ->and($check->checked_at)->not->toBeNull();
});

test('audit:verify-chains DETECTS a corrupted chain: ok=false, error log, non-zero exit', function () {
    Storage::fake('local');
    (new DemoClinicSeeder)->run();

    $tenant = Tenant::query()->where('slug', DemoClinicSeeder::TENANT_SLUG)->firstOrFail();
    p3AlarmCtx()->set($tenant);

    // The chain is trigger-protected, so tampering has to be simulated the way
    // a real attacker with DB access would: drop the guard, alter the row, put
    // the guard back. If this ever stops breaking the chain, the chain is not
    // doing its job.
    $victim = DB::selectOne(
        'SELECT id FROM audit_events WHERE tenant_id = ? ORDER BY occurred_at DESC, id DESC LIMIT 1',
        [$tenant->id],
    );

    DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_update');
    DB::update("UPDATE audit_events SET action = 'quietly.rewritten' WHERE id = ?", [$victim->id]);
    DB::unprepared(
        "CREATE TRIGGER audit_events_no_update BEFORE UPDATE ON audit_events\n".
        "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_events is append-only: UPDATE is forbidden';"
    );

    // Sanity: the tamper really did break the chain.
    expect(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeFalse();

    Log::spy();
    p3AlarmCtx()->forget();

    expect(Artisan::call('audit:verify-chains'))->toBe(1);

    p3AlarmCtx()->set($tenant);
    $check = IntegrityCheck::query()->where('kind', IntegrityCheck::KIND_AUDIT_CHAIN)->firstOrFail();

    expect($check->ok)->toBeFalse()
        ->and($check->detail['broken_at'])->toBe($victim->id)
        ->and($check->detail['reason'])->not->toBeNull();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Audit chain verification FAILED')
            && $context['tenant_id'] === $tenant->id
            && $context['broken_at'] === $victim->id)
        ->once();
});

test('audit:verify-chains is tenant-scoped and skips inactive tenants', function () {
    $active = Tenant::query()->create(['name' => 'Live', 'slug' => 'live-clinic', 'region' => 'eu', 'status' => 'active']);
    $suspended = Tenant::query()->create(['name' => 'Susp', 'slug' => 'susp-clinic', 'region' => 'eu', 'status' => 'suspended']);

    p3AlarmCtx()->forget();
    expect(Artisan::call('audit:verify-chains'))->toBe(0);

    p3AlarmCtx()->set($active);
    expect(IntegrityCheck::query()->count())->toBe(1);

    // The suspended tenant was never entered — no row of its own.
    p3AlarmCtx()->set($suspended);
    expect(IntegrityCheck::query()->count())->toBe(0);
});

test('audit:verify-chains is safe to run repeatedly — each run appends its own evidence', function () {
    $tenant = Tenant::query()->create(['name' => 'Live', 'slug' => 'repeat-clinic', 'region' => 'eu', 'status' => 'active']);

    p3AlarmCtx()->forget();
    Artisan::call('audit:verify-chains');
    Artisan::call('audit:verify-chains');

    p3AlarmCtx()->set($tenant);

    // Append-only by design: a check that ran and was clean is provable later,
    // and a check that silently stopped running shows up as an absence.
    expect(IntegrityCheck::query()->count())->toBe(2)
        ->and(IntegrityCheck::query()->where('ok', true)->count())->toBe(2);
});

test('integrity_checks rows are themselves append-only', function () {
    $tenant = Tenant::query()->create(['name' => 'Live', 'slug' => 'frozen-clinic', 'region' => 'eu', 'status' => 'active']);

    p3AlarmCtx()->forget();
    Artisan::call('audit:verify-chains');
    p3AlarmCtx()->set($tenant);

    $check = IntegrityCheck::query()->firstOrFail();

    // Evidence that can be rewritten afterwards is not evidence — least of all
    // by whoever had a reason to rewrite it.
    expect(fn () => DB::update('UPDATE integrity_checks SET ok = 1 WHERE id = ?', [$check->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::delete('DELETE FROM integrity_checks WHERE id = ?', [$check->id]))
        ->toThrow(QueryException::class);
});
