<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Models\PortalLoginToken;
use Modules\Patients\Notifications\PortalInviteNotification;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function b5Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function b5Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function b5Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Mara',
        'last_name' => 'Meyer',
        'date_of_birth' => '1990-04-20',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function b5Staff(Tenant $tenant): User
{
    return User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
}

function b5ConsentTemplate(): ConsentTemplate
{
    return ConsentTemplate::create([
        'key' => 'portal',
        'title' => 'Portal Access',
        'body' => 'Portal access consent',
        'version' => 1,
        'scope_keys' => ['portal.access'],
        'is_active' => true,
    ]);
}

function b5GrantPortalConsent(Patient $patient, User $staff): void
{
    b5ConsentTemplate();
    app(ConsentService::class)->grant($patient, 'portal', 'Mara Meyer', $staff);
}

/**
 * @return array{token: string, otp: string}
 */
function b5CapturedInvite(): array
{
    $captured = ['token' => '', 'otp' => ''];

    Notification::assertSentOnDemand(
        PortalInviteNotification::class,
        function (PortalInviteNotification $notification, array $channels) use (&$captured): bool {
            $captured = ['token' => $notification->token, 'otp' => $notification->otp];

            return $channels === ['mail'];
        }
    );

    return $captured;
}

function b5AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('invite and magic-link OTP provisioning activates a portal account and audits first login', function () {
    Notification::fake();
    $tenant = b5Tenant('alpha');
    b5Ctx()->set($tenant);
    $staff = b5Staff($tenant);
    $patient = b5Patient();
    b5GrantPortalConsent($patient, $staff);

    $this->actingAs($staff)
        ->postJson(route('portal.invitations.store'), [
            'patient_id' => $patient->id,
            'email' => 'mara@example.test',
        ])
        ->assertCreated()
        ->assertJsonPath('status', PortalAccount::STATUS_INVITED);

    $invite = b5CapturedInvite();

    $this->postJson(route('portal.accept-invite'), [
        'token' => $invite['token'],
        'otp' => $invite['otp'],
        'password' => 'secret-password',
    ])->assertOk()->assertJsonPath('patient_id', $patient->id);

    $account = PortalAccount::firstOrFail();

    expect($account->status)->toBe(PortalAccount::STATUS_ACTIVE)
        ->and($account->activated_at)->not->toBeNull()
        ->and(PortalLoginToken::firstOrFail()->consumed_at)->not->toBeNull();

    $this->get(route('portal.home'))->assertOk();

    $this->postJson(route('portal.login.attempt'), [
        'email' => 'mara@example.test',
        'password' => 'secret-password',
    ])->assertOk()->assertJsonPath('patient_id', $patient->id);

    expect(b5AuditRows($tenant->id, 'portal.invited'))->toHaveCount(1)
        ->and(b5AuditRows($tenant->id, 'portal.first_login'))->toHaveCount(1)
        ->and(b5AuditRows($tenant->id, 'portal.login'))->toHaveCount(2)
        ->and(b5AuditRows($tenant->id, 'portal.login')->first()->patient_id)->toBe($patient->id)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('portal provisioning and login are fail-closed without portal access consent', function () {
    Notification::fake();
    $tenant = b5Tenant('alpha');
    b5Ctx()->set($tenant);
    $staff = b5Staff($tenant);
    $patient = b5Patient();

    $this->actingAs($staff)
        ->postJson(route('portal.invitations.store'), [
            'patient_id' => $patient->id,
            'email' => 'mara@example.test',
        ])
        ->assertForbidden();

    b5GrantPortalConsent($patient, $staff);

    $this->postJson(route('portal.invitations.store'), [
        'patient_id' => $patient->id,
        'email' => 'mara@example.test',
    ])->assertCreated();

    $invite = b5CapturedInvite();

    $this->postJson(route('portal.accept-invite'), [
        'token' => $invite['token'],
        'otp' => $invite['otp'],
        'password' => 'secret-password',
    ])->assertOk();

    $consent = $patient->consents()->firstOrFail();
    app(ConsentService::class)->withdraw($consent, 'Patient request');

    $this->get(route('portal.home'))->assertForbidden();

    Auth::guard('patient')->logout();
    $this->flushSession();

    b5Ctx()->set($tenant);

    $this->postJson(route('portal.login.attempt'), [
        'email' => 'mara@example.test',
        'password' => 'secret-password',
    ])->assertForbidden();
});

test('a patient portal account cannot reach staff app or admin areas', function () {
    Notification::fake();
    $tenant = b5Tenant('alpha');
    b5Ctx()->set($tenant);
    $staff = b5Staff($tenant);
    $patient = b5Patient();
    b5GrantPortalConsent($patient, $staff);

    $this->actingAs($staff)
        ->postJson(route('portal.invitations.store'), [
            'patient_id' => $patient->id,
            'email' => 'mara@example.test',
        ])
        ->assertCreated();

    $invite = b5CapturedInvite();

    Auth::guard('web')->logout();
    $this->postJson(route('portal.accept-invite'), [
        'token' => $invite['token'],
        'otp' => $invite['otp'],
        'password' => 'secret-password',
    ])->assertOk();

    $this->get('/app')->assertRedirect('/login');
    $this->get('/admin')->assertRedirect('/login');
});

test('staff users cannot access the patient portal guard', function () {
    $tenant = b5Tenant('alpha');
    $staff = b5Staff($tenant);

    $this->actingAs($staff)->get(route('portal.home'))->assertRedirect(route('portal.login'));
});

test('portal accounts are tenant isolated and portal sessions cannot cross tenants', function () {
    Notification::fake();
    $a = b5Tenant('alpha');
    $b = b5Tenant('beta');

    b5Ctx()->set($a);
    $staffA = b5Staff($a);
    $patientA = b5Patient(['last_name' => 'Alpha']);
    b5GrantPortalConsent($patientA, $staffA);

    $this->actingAs($staffA)
        ->postJson(route('portal.invitations.store'), [
            'patient_id' => $patientA->id,
            'email' => 'alpha.patient@example.test',
        ])
        ->assertCreated();

    $invite = b5CapturedInvite();
    Auth::guard('web')->logout();

    $this->postJson(route('portal.accept-invite'), [
        'token' => $invite['token'],
        'otp' => $invite['otp'],
        'password' => 'secret-password',
    ])->assertOk();

    $this->withSession(['portal_tenant_id' => $b->id])
        ->get(route('portal.home'))
        ->assertForbidden();

    b5Ctx()->set($b);

    expect(PortalAccount::count())->toBe(0)
        ->and(PortalLoginToken::count())->toBe(0);

    b5Ctx()->forget();

    expect(fn () => PortalAccount::count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => PortalLoginToken::count())->toThrow(TenantContextMissingException::class);
});
