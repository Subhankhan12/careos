<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Models\PortalLoginToken;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/**
 * P0P.G15 engine-parity regression: MariaDB 10.4 gives the FIRST non-nullable
 * TIMESTAMP column of a table an implicit ON UPDATE CURRENT_TIMESTAMP
 * (explicit_defaults_for_timestamp=OFF); MySQL 8 does not. On an UPDATE-able
 * table that silently rewrites a recorded moment on the dev engine only.
 * These tests lock the fix (mutable moment columns are DATETIME) on BOTH engines.
 */
function g15Fixture(): array
{
    $tenant = Tenant::create(['name' => 'Parity Clinic', 'slug' => 'parity', 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Parity',
        'last_name' => 'Patient',
        'date_of_birth' => '1970-01-01',
        'sex' => 'female',
    ]);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    return compact('tenant', 'patient', 'user');
}

test('withdrawing a consent preserves the recorded granted_at moment', function () {
    $fx = g15Fixture();

    $template = ConsentTemplate::query()->create([
        'key' => 'portal.access',
        'title' => 'Portal access',
        'body' => 'Portal consent body',
        'version' => 1,
        'scope_keys' => ['portal.access'],
        'is_active' => true,
    ]);

    $consent = PatientConsent::query()->create([
        'patient_id' => $fx['patient']->id,
        'template_id' => $template->id,
        'template_version' => 1,
        'template_key' => 'portal.access',
        'template_title' => 'Portal access',
        'template_body' => 'Portal consent body',
        'template_scope_keys' => ['portal.access'],
        'status' => PatientConsent::STATUS_GRANTED,
        'granted_at' => '2026-06-01 09:00:00',
        'signature' => ['typed' => 'Parity Patient'],
        'captured_by' => $fx['user']->id,
    ]);

    app(ConsentService::class)->withdraw($consent, 'Patient asked to withdraw');

    // The legally meaningful grant moment must survive the withdrawal UPDATE on
    // every engine (pre-fix, MariaDB rewrote it to now() via the implicit
    // ON UPDATE CURRENT_TIMESTAMP on the first TIMESTAMP column).
    expect($consent->refresh()->granted_at->toDateTimeString())->toBe('2026-06-01 09:00:00')
        ->and($consent->status)->toBe(PatientConsent::STATUS_WITHDRAWN);
});

test('consuming a portal login token preserves its expires_at moment', function () {
    $fx = g15Fixture();

    $account = PortalAccount::query()->create([
        'patient_id' => $fx['patient']->id,
        'email' => 'parity@portal.test',
        'password' => bcrypt('secret-portal-pass'),
        'status' => PortalAccount::STATUS_ACTIVE,
        'activated_at' => now(),
    ]);

    $token = PortalLoginToken::query()->create([
        'portal_account_id' => $account->id,
        'purpose' => 'activation',
        'token_hash' => str_repeat('a', 64),
        'otp_hash' => bcrypt('123456'),
        'expires_at' => '2026-06-01 10:00:00',
    ]);

    // The same mutation PortalAccessService performs on consumption.
    $token->forceFill(['consumed_at' => Carbon::now()])->save();

    expect($token->refresh()->expires_at->toDateTimeString())->toBe('2026-06-01 10:00:00')
        ->and($token->consumed_at)->not->toBeNull();
});

test('no UPDATE-able table carries an implicit on-update timestamp column on this engine', function () {
    // The six append-only ledgers keep TIMESTAMP safely — their UPDATE is blocked
    // by DB triggers, so an implicit ON UPDATE can never fire (P.3 sweep).
    $appendOnly = [
        'ai_interactions',
        'integrity_checks',
        'messages',
        'payment_allocations',
        'reconciliation_runs',
        'refunds',
    ];

    $rows = DB::select(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%on update%'",
    );

    $offenders = collect($rows)
        ->filter(fn ($row): bool => ! in_array($row->TABLE_NAME, $appendOnly, true))
        ->map(fn ($row): string => $row->TABLE_NAME.'.'.$row->COLUMN_NAME)
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
