<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function audit(): AuditService
{
    return app(AuditService::class);
}

function partitionExists(string $name): bool
{
    $row = DB::selectOne(
        'SELECT COUNT(*) AS c FROM information_schema.PARTITIONS '
        .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME = ?',
        ['audit_events', $name],
    );

    return $row !== null && (int) $row->c > 0;
}

// (a) independent per-tenant chains verify OK ---------------------------------

test('records events across two tenants and each chain verifies independently', function () {
    $a = (string) Str::ulid();
    $b = (string) Str::ulid();

    audit()->record(['tenant_id' => $a, 'action' => 'patient.view', 'actor_type' => 'user', 'actor_id' => '1']);
    audit()->record(['tenant_id' => $a, 'action' => 'patient.edit', 'actor_type' => 'user', 'actor_id' => '1', 'context' => ['field' => 'name', 'nested' => ['b' => 2, 'a' => 1]]]);
    audit()->record(['tenant_id' => $b, 'action' => 'billing.view', 'actor_type' => 'service']);

    $chainA = audit()->verifyChain($a);
    $chainB = audit()->verifyChain($b);

    expect($chainA['ok'])->toBeTrue()
        ->and($chainA['count'])->toBe(2)
        ->and($chainB['ok'])->toBeTrue()
        ->and($chainB['count'])->toBe(1);
});

test('a platform-level (null tenant) chain verifies', function () {
    audit()->record(['tenant_id' => null, 'action' => 'platform.boot', 'actor_type' => 'service']);
    audit()->record(['tenant_id' => null, 'action' => 'platform.migrate', 'actor_type' => 'service']);

    expect(audit()->verifyChain(null)['ok'])->toBeTrue();
});

test('the audit context stamps tenant_id from TenantContext when not given', function () {
    $tenant = Tenant::create([
        'name' => 'Alpha', 'slug' => 'alpha', 'region' => 'eu', 'status' => 'active',
    ]);
    app(TenantContext::class)->set($tenant);

    $event = audit()->record(['action' => 'patient.view']);

    expect($event->tenant_id)->toBe($tenant->id)
        ->and(audit()->verifyChain($tenant->id)['ok'])->toBeTrue();
});

// (b) tampering detection -----------------------------------------------------

test('verifyChain detects a tampered/injected row', function () {
    $a = (string) Str::ulid();

    audit()->record(['tenant_id' => $a, 'action' => 'a']);
    audit()->record(['tenant_id' => $a, 'action' => 'b']);
    expect(audit()->verifyChain($a)['ok'])->toBeTrue();

    // Inject an inconsistent row directly (INSERT is allowed; only UPDATE/DELETE
    // are blocked). Its prev_hash + hash do not belong to the chain.
    DB::table('audit_events')->insert([
        'id' => (string) Str::ulid(),
        'tenant_id' => $a,
        'actor_type' => 'service',
        'action' => 'tampered',
        'occurred_at' => Carbon::now()->addSecond()->format('Y-m-d H:i:s.u'),
        'prev_hash' => str_repeat('0', 64),
        'hash' => str_repeat('f', 64),
    ]);

    $result = audit()->verifyChain($a);

    expect($result['ok'])->toBeFalse()
        ->and($result['broken_at'])->not->toBeNull();
});

// (c) DB-level append-only enforcement ---------------------------------------

test('UPDATE on audit_events is blocked by the trigger', function () {
    $event = audit()->record(['tenant_id' => (string) Str::ulid(), 'action' => 'a']);

    expect(fn () => DB::table('audit_events')->where('id', $event->id)->update(['reason' => 'hacked']))
        ->toThrow(QueryException::class);
});

test('DELETE on audit_events is blocked by the trigger', function () {
    $event = audit()->record(['tenant_id' => (string) Str::ulid(), 'action' => 'a']);

    expect(fn () => DB::table('audit_events')->where('id', $event->id)->delete())
        ->toThrow(QueryException::class);
});

// (d) partition maintenance ---------------------------------------------------

test('audit:ensure-partitions adds a future month idempotently', function () {
    $future = Carbon::now()->startOfMonth()->addMonths(6);
    $name = 'p_'.$future->format('Y_m');

    // Beyond the migration's initial four months → not present yet.
    expect(partitionExists($name))->toBeFalse();

    $this->artisan('audit:ensure-partitions')->assertSuccessful();
    expect(partitionExists($name))->toBeTrue();

    // Running again is safe and leaves it in place.
    $this->artisan('audit:ensure-partitions')->assertSuccessful();
    expect(partitionExists($name))->toBeTrue();
});
