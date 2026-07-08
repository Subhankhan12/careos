<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\People\Models\Credential;
use Modules\People\Models\StaffProfile;
use Modules\People\Services\CredentialService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function peopleTenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function peopleCtx(): TenantContext
{
    return app(TenantContext::class);
}

function peopleStaff(array $overrides = []): StaffProfile
{
    return StaffProfile::create([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'display_name' => 'Ada Lovelace',
        'profession' => 'nurse',
        ...$overrides,
    ]);
}

function peopleCredential(StaffProfile $staff, array $overrides = []): Credential
{
    return Credential::create([
        'staff_profile_id' => $staff->id,
        'type' => 'license',
        'name' => 'Registered Nurse',
        ...$overrides,
    ]);
}

function peopleAuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('staff profiles and credentials are tenant isolated and fail closed', function () {
    $a = peopleTenant('alpha');
    $b = peopleTenant('beta');

    peopleCtx()->set($a);
    $staffA = peopleStaff(['last_name' => 'Alpha', 'display_name' => 'Ada Alpha']);
    peopleCredential($staffA, ['name' => 'Alpha License']);

    peopleCtx()->set($b);
    $staffB = peopleStaff(['last_name' => 'Beta', 'display_name' => 'Ada Beta']);
    $credentialB = peopleCredential($staffB, ['name' => 'Beta License']);

    peopleCtx()->set($a);

    expect(StaffProfile::all())->toHaveCount(1)
        ->and(StaffProfile::first()->last_name)->toBe('Alpha')
        ->and(Credential::all())->toHaveCount(1)
        ->and(Credential::first()->name)->toBe('Alpha License')
        ->and(Credential::find($credentialB->id))->toBeNull();

    peopleCtx()->forget();

    expect(fn () => StaffProfile::count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => Credential::count())->toThrow(TenantContextMissingException::class);
});

test('expiry status computation uses the configured alert window on boundary dates', function () {
    $this->travelTo(Carbon::parse('2026-01-15 09:00:00'));

    $tenant = peopleTenant('alpha');
    peopleCtx()->set($tenant);
    app(SettingsService::class)->set(CredentialService::EXPIRY_WINDOW_SETTING, 60, 'int');
    $staff = peopleStaff();

    $expired = peopleCredential($staff, [
        'name' => 'Expired',
        'expires_on' => Carbon::today()->subDay(),
    ]);
    $today = peopleCredential($staff, [
        'name' => 'Today',
        'expires_on' => Carbon::today(),
    ]);
    $edge = peopleCredential($staff, [
        'name' => 'Edge',
        'expires_on' => Carbon::today()->addDays(60),
    ]);
    $outside = peopleCredential($staff, [
        'name' => 'Outside',
        'expires_on' => Carbon::today()->addDays(61),
    ]);
    $undated = peopleCredential($staff, [
        'name' => 'Undated',
        'expires_on' => null,
    ]);
    $revoked = peopleCredential($staff, [
        'name' => 'Revoked',
        'expires_on' => Carbon::today()->subDay(),
        'status' => Credential::STATUS_REVOKED,
    ]);

    expect($expired->status)->toBe(Credential::STATUS_EXPIRED)
        ->and($today->status)->toBe(Credential::STATUS_EXPIRING)
        ->and($edge->status)->toBe(Credential::STATUS_EXPIRING)
        ->and($outside->status)->toBe(Credential::STATUS_VALID)
        ->and($undated->status)->toBe(Credential::STATUS_VALID)
        ->and($revoked->status)->toBe(Credential::STATUS_REVOKED);
});

test('expiringWithin and expired scopes honor date boundaries and ignore revoked credentials', function () {
    $this->travelTo(Carbon::parse('2026-01-15 09:00:00'));

    $tenant = peopleTenant('alpha');
    peopleCtx()->set($tenant);
    $staff = peopleStaff();

    peopleCredential($staff, ['name' => 'Expired', 'expires_on' => Carbon::today()->subDay()]);
    peopleCredential($staff, ['name' => 'Today', 'expires_on' => Carbon::today()]);
    peopleCredential($staff, ['name' => 'Window Edge', 'expires_on' => Carbon::today()->addDays(30)]);
    peopleCredential($staff, ['name' => 'Outside', 'expires_on' => Carbon::today()->addDays(31)]);
    peopleCredential($staff, [
        'name' => 'Revoked',
        'expires_on' => Carbon::today()->addDays(10),
        'status' => Credential::STATUS_REVOKED,
    ]);
    peopleCredential($staff, ['name' => 'Undated', 'expires_on' => null]);

    expect(Credential::expiringWithin(30)->pluck('name')->all())
        ->toEqualCanonicalizing(['Today', 'Window Edge'])
        ->and(Credential::expired()->pluck('name')->all())
        ->toEqualCanonicalizing(['Expired']);
});

test('a staff profile cannot be attached to a branch from another tenant', function () {
    $a = peopleTenant('alpha');
    $b = peopleTenant('beta');

    peopleCtx()->set($b);
    $branchB = Branch::create(['name' => 'B Main', 'code' => 'MAIN']);

    peopleCtx()->set($a);
    $branchA = Branch::create(['name' => 'A Main', 'code' => 'MAIN']);

    expect(fn () => peopleStaff(['primary_branch_id' => $branchB->id]))
        ->toThrow(CrossTenantReferenceException::class);

    $staff = peopleStaff(['primary_branch_id' => $branchA->id]);
    $staff->primary_branch_id = $branchB->id;

    expect(fn () => $staff->save())->toThrow(CrossTenantReferenceException::class);
});

test('revoking a credential writes a credential revoked audit event', function () {
    $tenant = peopleTenant('alpha');
    peopleCtx()->set($tenant);
    $staff = peopleStaff();
    $credential = peopleCredential($staff);

    $credential->status = Credential::STATUS_REVOKED;
    $credential->save();

    $rows = peopleAuditRows($tenant->id, 'credential.revoked');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->resource_type)->toBe('credential')
        ->and($rows[0]->resource_id)->toBe($credential->id)
        ->and(json_decode($rows[0]->context, true)['status'])->toBe(Credential::STATUS_REVOKED);
});

test('credentials refresh status command is idempotent', function () {
    $this->travelTo(Carbon::parse('2026-01-15 09:00:00'));

    $tenant = peopleTenant('alpha');
    peopleCtx()->set($tenant);
    app(SettingsService::class)->set(CredentialService::EXPIRY_WINDOW_SETTING, 30, 'int');
    $staff = peopleStaff();
    $credential = peopleCredential($staff, [
        'name' => 'Moving Window',
        'expires_on' => Carbon::today()->addDays(31),
    ]);

    expect($credential->status)->toBe(Credential::STATUS_VALID);

    $this->travelTo(Carbon::parse('2026-01-17 09:00:00'));

    expect(Artisan::call('credentials:refresh-status'))->toBe(0)
        ->and(Artisan::output())->toContain('Credentials checked: 1; updated: 1.');

    expect($credential->refresh()->status)->toBe(Credential::STATUS_EXPIRING)
        ->and(peopleAuditRows($tenant->id, 'credential.updated'))->toHaveCount(1);

    expect(Artisan::call('credentials:refresh-status'))->toBe(0)
        ->and(Artisan::output())->toContain('Credentials checked: 1; updated: 0.')
        ->and(peopleAuditRows($tenant->id, 'credential.updated'))->toHaveCount(1);
});
