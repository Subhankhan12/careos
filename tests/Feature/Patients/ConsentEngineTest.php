<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function b4Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function b4Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function b4ConsentService(): ConsentService
{
    return app(ConsentService::class);
}

function b4Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Nina',
        'last_name' => 'Novak',
        'date_of_birth' => '1988-02-14',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function b4User(Tenant $tenant): User
{
    return User::factory()->forTenant($tenant)->create();
}

/**
 * @param  list<string>  $scopes
 */
function b4Template(string $key = 'portal', int $version = 1, array $scopes = ['portal.access'], bool $active = true, array $overrides = []): ConsentTemplate
{
    return ConsentTemplate::create([
        'key' => $key,
        'title' => 'Portal Access v'.$version,
        'body' => 'Consent body v'.$version,
        'version' => $version,
        'scope_keys' => $scopes,
        'is_active' => $active,
        ...$overrides,
    ]);
}

function b4AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('template versioning supersedes active versions while captured consent text stays immutable', function () {
    $tenant = b4Tenant('alpha');
    b4Ctx()->set($tenant);
    $patient = b4Patient();
    $capturedBy = b4User($tenant);

    $v1 = b4Template(version: 1, active: true, overrides: ['body' => 'Original signed text']);

    $firstConsent = b4ConsentService()->grant($patient, 'portal', 'Nina Novak', $capturedBy);

    $v1->update(['is_active' => false, 'body' => 'Changed template text after capture']);
    b4Template(version: 2, active: true, overrides: ['body' => 'New signed text']);

    $secondConsent = b4ConsentService()->grant($patient, 'portal', 'Nina Novak', $capturedBy);

    expect($firstConsent->template_version)->toBe(1)
        ->and($firstConsent->template_body)->toBe('Original signed text')
        ->and($firstConsent->refresh()->template_body)->toBe('Original signed text')
        ->and($secondConsent->template_version)->toBe(2)
        ->and($secondConsent->template_body)->toBe('New signed text');
});

test('grant and withdrawal lifecycle writes patient scoped audit events with a valid chain', function () {
    $tenant = b4Tenant('alpha');
    b4Ctx()->set($tenant);
    $patient = b4Patient();
    $capturedBy = b4User($tenant);
    b4Template(scopes: ['portal.access', 'comms.email']);

    $consent = b4ConsentService()->grant($patient, 'portal', ['name' => 'Nina Novak', 'method' => 'typed'], $capturedBy);

    expect($consent->status)->toBe(PatientConsent::STATUS_GRANTED)
        ->and($consent->granted_at)->not->toBeNull()
        ->and($consent->captured_by)->toBe($capturedBy->id)
        ->and($consent->signature['name'])->toBe('Nina Novak')
        ->and($consent->signature['method'])->toBe('typed')
        ->and($consent->signature['hash'])->toHaveLength(64);

    $withdrawn = b4ConsentService()->withdraw($consent, 'Patient request');
    $grantRows = b4AuditRows($tenant->id, 'consent.granted');
    $withdrawRows = b4AuditRows($tenant->id, 'consent.withdrawn');

    expect($withdrawn->status)->toBe(PatientConsent::STATUS_WITHDRAWN)
        ->and($withdrawn->withdrawn_at)->not->toBeNull()
        ->and($grantRows)->toHaveCount(1)
        ->and($grantRows[0]->patient_id)->toBe($patient->id)
        ->and($grantRows[0]->resource_id)->toBe($consent->id)
        ->and($withdrawRows)->toHaveCount(1)
        ->and($withdrawRows[0]->patient_id)->toBe($patient->id)
        ->and($withdrawRows[0]->reason)->toBe('Patient request')
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('has scope is fail closed and respects expiry and withdrawal', function () {
    $tenant = b4Tenant('alpha');
    b4Ctx()->set($tenant);
    $patient = b4Patient();
    $capturedBy = b4User($tenant);
    b4Template(scopes: ['portal.access', 'data.share.referral']);

    expect(b4ConsentService()->has($patient, 'portal.access'))->toBeFalse();

    $expired = b4ConsentService()->grant($patient, 'portal', 'Nina Novak', $capturedBy, Carbon::now()->subDay());

    expect(b4ConsentService()->has($patient, 'portal.access'))->toBeFalse();

    $granted = b4ConsentService()->grant($patient, 'portal', 'Nina Novak', $capturedBy, Carbon::now()->addDay());

    expect(b4ConsentService()->has($patient, 'portal.access'))->toBeTrue()
        ->and(b4ConsentService()->has($patient, 'data.share.referral'))->toBeTrue()
        ->and(b4ConsentService()->has($patient, 'comms.sms'))->toBeFalse();

    b4ConsentService()->withdraw($granted, 'Patient request');
    $expired->forceFill(['status' => PatientConsent::STATUS_EXPIRED])->save();

    expect(b4ConsentService()->has($patient, 'portal.access'))->toBeFalse();
});

test('consents are tenant isolated and fail closed', function () {
    $a = b4Tenant('alpha');
    $b = b4Tenant('beta');

    b4Ctx()->set($a);
    $patientA = b4Patient(['last_name' => 'Alpha']);
    $userA = b4User($a);
    b4Template(scopes: ['portal.access']);
    b4ConsentService()->grant($patientA, 'portal', 'Nina Alpha', $userA);

    b4Ctx()->set($b);
    $patientB = b4Patient(['last_name' => 'Beta']);
    $userB = b4User($b);
    b4Template(scopes: ['portal.access']);

    expect(PatientConsent::count())->toBe(0)
        ->and(b4ConsentService()->has($patientB, 'portal.access'))->toBeFalse()
        ->and(fn () => b4ConsentService()->grant($patientA, 'portal', 'Nina Alpha', $userB))
        ->toThrow(CrossTenantReferenceException::class);

    b4Ctx()->forget();

    expect(fn () => PatientConsent::count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => ConsentTemplate::count())->toThrow(TenantContextMissingException::class);
});

test('e-signature requires a typed name and captures a stable hash payload', function () {
    $tenant = b4Tenant('alpha');
    b4Ctx()->set($tenant);
    $patient = b4Patient();
    $capturedBy = b4User($tenant);
    b4Template();

    expect(fn () => b4ConsentService()->grant($patient, 'portal', ['name' => '   '], $capturedBy))
        ->toThrow(InvalidArgumentException::class);

    $consent = b4ConsentService()->grant($patient, 'portal', ['typed_name' => 'Nina Novak'], $capturedBy);

    expect($consent->signature)->toHaveKeys(['name', 'method', 'signed_at', 'hash'])
        ->and($consent->signature['name'])->toBe('Nina Novak')
        ->and($consent->signature['method'])->toBe('typed')
        ->and($consent->signature['hash'])->toMatch('/^[a-f0-9]{64}$/');
});

test('grant uses only the current active template and rejects missing templates', function () {
    $tenant = b4Tenant('alpha');
    b4Ctx()->set($tenant);
    $patient = b4Patient();
    $capturedBy = b4User($tenant);

    b4Template(version: 1, active: false);
    b4Template(version: 2, active: true);
    b4Template(version: 3, active: false);

    $consent = b4ConsentService()->grant($patient, 'portal', 'Nina Novak', $capturedBy);

    expect($consent->template_version)->toBe(2);

    expect(fn () => b4ConsentService()->grant($patient, 'missing', 'Nina Novak', $capturedBy))
        ->toThrow(ModelNotFoundException::class);
});
