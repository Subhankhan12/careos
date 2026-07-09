<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Services\EncounterService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Tests\TestCase;

uses(RefreshDatabase::class);

function d4Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function d4Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function d4Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function d4User(Tenant $tenant, string $role = 'doctor'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    if ($role !== '') {
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => d4Role($role)->id,
        ]);
    }

    return $user;
}

function d4Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function d4Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Dora',
        'last_name' => 'Documents',
        'date_of_birth' => '1988-06-12',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function d4Practitioner(Branch $branch): StaffProfile
{
    return StaffProfile::query()->create([
        'first_name' => 'Doc',
        'last_name' => 'Writer',
        'display_name' => 'Doc Writer',
        'profession' => 'doctor',
        'primary_branch_id' => $branch->id,
    ]);
}

function d4Encounter(User $user, Patient $patient, ?Branch $branch = null): Encounter
{
    $branch ??= d4Branch();
    $practitioner = d4Practitioner($branch);

    return app(EncounterService::class)->open(
        $patient,
        $practitioner,
        $branch,
        null,
        Encounter::TYPE_CONSULTATION,
        $user,
    );
}

function d4ConsentTemplate(): ConsentTemplate
{
    return ConsentTemplate::query()->create([
        'key' => 'portal',
        'title' => 'Portal Access',
        'body' => 'Portal access consent',
        'version' => 1,
        'scope_keys' => ['portal.access'],
        'is_active' => true,
    ]);
}

function d4GrantPortalConsent(Patient $patient, User $staff): void
{
    if (! ConsentTemplate::query()->where('key', 'portal')->exists()) {
        d4ConsentTemplate();
    }

    app(ConsentService::class)->grant($patient, 'portal', 'Dora Documents', $staff);
}

function d4PortalAccount(Patient $patient, string $email = 'dora.portal@example.test'): PortalAccount
{
    return PortalAccount::query()->create([
        'patient_id' => $patient->id,
        'email' => $email,
        'password' => 'secret-password',
        'status' => PortalAccount::STATUS_ACTIVE,
        'invited_at' => now(),
        'activated_at' => now(),
    ]);
}

function d4File(string $name = 'result.pdf', int $kilobytes = 10, string $mime = 'application/pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, $kilobytes, $mime);
}

function d4Upload(TestCase $test, User $user, Patient $patient, array $overrides = []): Document
{
    $response = $test->actingAs($user)->postJson(route('clinical.documents.upload', $patient->id), [
        'category' => Document::CATEGORY_RESULT,
        'title' => 'Lab result',
        'file' => d4File('Lab Result.pdf'),
        ...$overrides,
    ]);

    $response->assertCreated();

    return Document::query()->whereKey($response->json('document.id'))->firstOrFail();
}

function d4AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('documents are tenant isolated fail closed and stored under a tenant prefix', function () {
    Storage::fake('local');
    $alpha = d4Tenant('alpha');
    $beta = d4Tenant('beta');

    d4Ctx()->set($alpha);
    $alphaUser = d4User($alpha);
    $alphaPatient = d4Patient(['last_name' => 'Alpha']);
    $alphaDocument = d4Upload($this, $alphaUser, $alphaPatient);

    d4Ctx()->set($beta);
    $betaUser = d4User($beta);
    $betaPatient = d4Patient(['last_name' => 'Beta']);
    d4Upload($this, $betaUser, $betaPatient);

    d4Ctx()->set($alpha);

    expect(Document::query()->count())->toBe(1)
        ->and(Document::query()->first()->is($alphaDocument))->toBeTrue()
        ->and($alphaDocument->storage_path)->toStartWith('tenants/'.$alpha->id.'/clinical-documents/'.$alphaPatient->id.'/');

    Storage::disk('local')->assertExists($alphaDocument->storage_path);

    d4Ctx()->forget();

    expect(fn () => Document::query()->count())->toThrow(TenantContextMissingException::class);
});

test('document upload view share and delete are RBAC guarded and audited', function () {
    Storage::fake('local');
    $tenant = d4Tenant('alpha');
    d4Ctx()->set($tenant);
    $doctor = d4User($tenant, 'doctor');
    $reception = d4User($tenant, 'reception');
    $patient = d4Patient();
    d4GrantPortalConsent($patient, $doctor);

    $this->actingAs($reception)
        ->postJson(route('clinical.documents.upload', $patient->id), [
            'category' => Document::CATEGORY_RESULT,
            'title' => 'Rejected',
            'file' => d4File(),
        ])->assertForbidden();

    $document = d4Upload($this, $doctor, $patient);

    $this->actingAs($reception)
        ->get(route('clinical.documents.download', $document->id))
        ->assertOk();

    $this->actingAs($reception)
        ->postJson(route('clinical.documents.share', $document->id))
        ->assertForbidden();

    $this->actingAs($doctor)
        ->postJson(route('clinical.documents.share', $document->id))
        ->assertOk()
        ->assertJsonPath('document.shared_with_patient', true);

    $this->actingAs($doctor)
        ->postJson(route('clinical.documents.unshare', $document->id))
        ->assertOk()
        ->assertJsonPath('document.shared_with_patient', false);

    $this->actingAs($doctor)
        ->deleteJson(route('clinical.documents.delete', $document->id))
        ->assertOk();

    expect(d4AuditRows($tenant->id, 'document.uploaded'))->toHaveCount(1)
        ->and(d4AuditRows($tenant->id, 'document.shared'))->toHaveCount(1)
        ->and(d4AuditRows($tenant->id, 'document.unshared'))->toHaveCount(1)
        ->and(d4AuditRows($tenant->id, 'document.deleted'))->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('storage paths are private and cannot be accessed as public URLs or across tenants', function () {
    Storage::fake('local');
    $alpha = d4Tenant('alpha');
    $beta = d4Tenant('beta');

    d4Ctx()->set($alpha);
    $alphaUser = d4User($alpha);
    $patient = d4Patient();
    $document = d4Upload($this, $alphaUser, $patient, [
        'file' => d4File('Unsafe <> Name.pdf'),
    ]);

    $uploadJson = $this->actingAs($alphaUser)->postJson(route('clinical.documents.upload', $patient->id), [
        'category' => Document::CATEGORY_LETTER,
        'title' => 'No path leak',
        'file' => d4File('another.pdf'),
    ]);
    $uploadJson->assertCreated();

    expect($uploadJson->json('document'))->not->toHaveKey('storage_path')
        ->and($document->original_filename)->toBe('Unsafe __ Name.pdf');

    $this->get('/storage/'.$document->storage_path)->assertForbidden();
    Auth::guard('web')->logout();
    $this->get(route('clinical.documents.download', $document->id))->assertRedirect('/login');

    d4Ctx()->set($beta);
    $betaUser = d4User($beta);

    $this->actingAs($betaUser)
        ->get(route('clinical.documents.download', $document->id))
        ->assertNotFound();
});

test('portal sharing requires portal access consent and portal users see only their shared documents', function () {
    Storage::fake('local');
    $tenant = d4Tenant('alpha');
    d4Ctx()->set($tenant);
    $staff = d4User($tenant, 'doctor');
    $patient = d4Patient(['last_name' => 'Owner']);
    $otherPatient = d4Patient(['last_name' => 'Other']);
    $ownedShared = d4Upload($this, $staff, $patient, ['title' => 'Owned shared']);
    $ownedPrivate = d4Upload($this, $staff, $patient, ['title' => 'Owned private']);
    $otherShared = d4Upload($this, $staff, $otherPatient, ['title' => 'Other shared']);

    $this->actingAs($staff)
        ->postJson(route('clinical.documents.share', $ownedShared->id))
        ->assertForbidden();

    d4GrantPortalConsent($patient, $staff);
    d4GrantPortalConsent($otherPatient, $staff);

    $this->actingAs($staff)->postJson(route('clinical.documents.share', $ownedShared->id))->assertOk();
    $this->actingAs($staff)->postJson(route('clinical.documents.share', $otherShared->id))->assertOk();

    Auth::guard('web')->logout();
    $account = d4PortalAccount($patient);

    $this->actingAs($account, 'patient')
        ->withSession(['portal_tenant_id' => $tenant->id])
        ->getJson(route('portal.documents.index'))
        ->assertOk()
        ->assertJsonCount(1, 'documents')
        ->assertJsonPath('documents.0.id', $ownedShared->id);

    $this->actingAs($account, 'patient')
        ->withSession(['portal_tenant_id' => $tenant->id])
        ->get(route('portal.documents.show', $ownedPrivate->id))
        ->assertNotFound();

    $this->actingAs($account, 'patient')
        ->withSession(['portal_tenant_id' => $tenant->id])
        ->get(route('portal.documents.show', $otherShared->id))
        ->assertNotFound();
});

test('staff and portal document downloads write patient scoped read audit rows', function () {
    Storage::fake('local');
    $tenant = d4Tenant('alpha');
    d4Ctx()->set($tenant);
    $staff = d4User($tenant, 'doctor');
    $patient = d4Patient();
    d4GrantPortalConsent($patient, $staff);
    $document = d4Upload($this, $staff, $patient);
    $this->actingAs($staff)->postJson(route('clinical.documents.share', $document->id))->assertOk();

    $this->actingAs($staff)
        ->get(route('clinical.documents.download', $document->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    Auth::guard('web')->logout();
    $account = d4PortalAccount($patient);

    $this->actingAs($account, 'patient')
        ->withSession(['portal_tenant_id' => $tenant->id])
        ->get(route('portal.documents.show', $document->id))
        ->assertOk();

    $rows = d4AuditRows($tenant->id, 'read');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->resource_type)->toBe('document')
        ->and($rows[0]->resource_id)->toBe($document->id)
        ->and($rows[0]->patient_id)->toBe($patient->id)
        ->and($rows[1]->resource_type)->toBe('document')
        ->and($rows[1]->patient_id)->toBe($patient->id)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('document upload validates mime type and size limits', function () {
    Storage::fake('local');
    $tenant = d4Tenant('alpha');
    d4Ctx()->set($tenant);
    $staff = d4User($tenant);
    $patient = d4Patient();

    $this->actingAs($staff)
        ->postJson(route('clinical.documents.upload', $patient->id), [
            'category' => Document::CATEGORY_RESULT,
            'title' => 'Executable',
            'file' => d4File('bad.exe', 1, 'application/x-msdownload'),
        ])->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    $this->actingAs($staff)
        ->postJson(route('clinical.documents.upload', $patient->id), [
            'category' => Document::CATEGORY_RESULT,
            'title' => 'Too large',
            'file' => d4File('huge.pdf', 11_000, 'application/pdf'),
        ])->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(Document::query()->count())->toBe(0);
});

test('soft deleted documents are excluded by default', function () {
    Storage::fake('local');
    $tenant = d4Tenant('alpha');
    d4Ctx()->set($tenant);
    $staff = d4User($tenant);
    $patient = d4Patient();
    $document = d4Upload($this, $staff, $patient);

    $this->actingAs($staff)
        ->deleteJson(route('clinical.documents.delete', $document->id))
        ->assertOk();

    expect(Document::query()->count())->toBe(0)
        ->and(Document::withTrashed()->count())->toBe(1);

    $this->actingAs($staff)
        ->get(route('clinical.documents.download', $document->id))
        ->assertNotFound();
});
