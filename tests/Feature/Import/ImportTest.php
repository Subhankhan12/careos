<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Audit\Services\AuditService;
use Modules\Import\Exceptions\ImportException;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Models\ImportRow;
use Modules\Import\Services\ImportBatchService;
use Modules\Import\Services\ImportCommitter;
use Modules\Import\Services\ImportValidator;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function impCtx(): TenantContext
{
    return app(TenantContext::class);
}

function impTenant(string $slug): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    impCtx()->set($tenant);

    return $tenant;
}

function impUser(Tenant $tenant, string $role = 'org_admin'): User
{
    impCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

function impCsv(string $content, string $name = 'patients.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

/** Standard mapping for the "Given,Family,DOB,Gender,Email" header shape. */
function impMapping(): array
{
    return [
        'Given' => 'first_name',
        'Family' => 'last_name',
        'DOB' => 'date_of_birth',
        'Gender' => 'sex',
        'Email' => 'email',
    ];
}

test('a clean CSV validates all rows valid and commit creates that many patients via PatientService', function () {
    Storage::fake('local');
    $tenant = impTenant('alpha');
    $actor = impUser($tenant);

    $csv = "Given,Family,DOB,Gender,Email\n".
        "Ada,Lovelace,1990-01-02,female,ada@x.test\n".
        "Alan,Turing,1985-06-23,male,alan@x.test\n";

    $batch = app(ImportBatchService::class)->upload(impCsv($csv), $actor);
    expect($batch->status)->toBe(ImportBatch::STATUS_UPLOADED)
        ->and($batch->row_count)->toBe(2)
        ->and(ImportRow::query()->where('import_batch_id', $batch->id)->count())->toBe(2);

    app(ImportBatchService::class)->setMapping($batch, impMapping(), 'Y-m-d');
    app(ImportValidator::class)->validate($batch->refresh());

    // DRY-RUN WROTE NOTHING.
    expect(Patient::query()->count())->toBe(0)
        ->and($batch->refresh()->status)->toBe(ImportBatch::STATUS_VALIDATED)
        ->and($batch->summary['counts']['valid'])->toBe(2)
        ->and(ImportRow::query()->where('status', ImportRow::STATUS_VALID)->count())->toBe(2);

    app(ImportCommitter::class)->commit($batch->refresh(), $actor, ImportBatch::POLICY_SKIP);

    $patients = Patient::query()->orderBy('last_name')->get();
    expect($patients)->toHaveCount(2)
        ->and($patients->pluck('tenant_id')->unique()->all())->toBe([$tenant->id])
        ->and($patients->every(fn (Patient $p): bool => str_starts_with($p->mrn, 'MRN-')))->toBeTrue()
        ->and($patients->pluck('mrn')->unique()->count())->toBe(2)
        ->and(PatientContact::query()->where('type', 'email')->count())->toBe(2)
        ->and(ImportRow::query()->where('status', ImportRow::STATUS_IMPORTED)->count())->toBe(2)
        ->and(ImportRow::query()->whereNotNull('created_entity_id')->count())->toBe(2);

    // Import is audited and the chain still verifies.
    $auditRows = DB::select(
        "SELECT * FROM audit_events WHERE tenant_id = ? AND action = 'patient.import.committed'",
        [$tenant->id],
    );
    expect($auditRows)->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('a row missing a required field is invalid with a field error and is not imported', function () {
    Storage::fake('local');
    $tenant = impTenant('alpha');
    $actor = impUser($tenant);

    $csv = "Given,Family,DOB\n".
        "Ada,Lovelace,1990-01-02\n".
        ",Turing,1985-06-23\n"; // missing first name

    $batch = app(ImportBatchService::class)->upload(impCsv($csv), $actor);
    app(ImportBatchService::class)->setMapping($batch, [
        'Given' => 'first_name', 'Family' => 'last_name', 'DOB' => 'date_of_birth',
    ], 'Y-m-d');
    app(ImportValidator::class)->validate($batch->refresh());

    $invalid = ImportRow::query()->where('row_number', 2)->firstOrFail();
    expect($invalid->status)->toBe(ImportRow::STATUS_INVALID)
        ->and($invalid->errors)->toHaveKey('first_name')
        ->and($batch->refresh()->summary['counts'])->toMatchArray(['valid' => 1, 'invalid' => 1]);

    app(ImportCommitter::class)->commit($batch->refresh(), $actor);

    expect(Patient::query()->count())->toBe(1)
        ->and($invalid->refresh()->status)->toBe(ImportRow::STATUS_INVALID)
        ->and($invalid->created_entity_id)->toBeNull();
});

test('a row matching an existing patient is flagged duplicate and skipped on commit by default', function () {
    Storage::fake('local');
    $tenant = impTenant('alpha');
    $actor = impUser($tenant);

    $existing = app(PatientService::class)->create([
        'first_name' => 'Ada', 'last_name' => 'Lovelace',
        'date_of_birth' => '1990-01-02', 'sex' => 'female',
    ]);

    $csv = "Given,Family,DOB\nAda,Lovelace,1990-01-02\n";
    $batch = app(ImportBatchService::class)->upload(impCsv($csv), $actor);
    app(ImportBatchService::class)->setMapping($batch, [
        'Given' => 'first_name', 'Family' => 'last_name', 'DOB' => 'date_of_birth',
    ], 'Y-m-d');
    app(ImportValidator::class)->validate($batch->refresh());

    $row = ImportRow::query()->where('row_number', 1)->firstOrFail();
    expect($row->status)->toBe(ImportRow::STATUS_DUPLICATE)
        ->and($row->matched_patient_id)->toBe($existing->id)
        ->and($row->match['reasons'])->not->toBeEmpty()
        ->and($row->match['score'])->toBeGreaterThanOrEqual(50);

    app(ImportCommitter::class)->commit($batch->refresh(), $actor, ImportBatch::POLICY_SKIP);

    expect(Patient::query()->count())->toBe(1) // only the pre-existing one
        ->and($row->refresh()->status)->toBe(ImportRow::STATUS_SKIPPED)
        ->and($row->created_entity_id)->toBeNull();
});

test('the dry-run writes nothing: patient count only changes on commit', function () {
    Storage::fake('local');
    $tenant = impTenant('alpha');
    $actor = impUser($tenant);

    $csv = "Given,Family,DOB\nGrace,Hopper,1985-12-09\n";
    $batch = app(ImportBatchService::class)->upload(impCsv($csv), $actor);
    app(ImportBatchService::class)->setMapping($batch, [
        'Given' => 'first_name', 'Family' => 'last_name', 'DOB' => 'date_of_birth',
    ], 'Y-m-d');

    expect(Patient::query()->count())->toBe(0);
    app(ImportValidator::class)->validate($batch->refresh());
    expect(Patient::query()->count())->toBe(0); // still nothing after the dry-run

    app(ImportCommitter::class)->commit($batch->refresh(), $actor);
    expect(Patient::query()->count())->toBe(1); // only now
});

test('committing a batch twice does not double-import', function () {
    Storage::fake('local');
    $tenant = impTenant('alpha');
    $actor = impUser($tenant);

    $csv = "Given,Family,DOB\nGrace,Hopper,1985-12-09\nAda,Lovelace,1990-01-02\n";
    $batch = app(ImportBatchService::class)->upload(impCsv($csv), $actor);
    app(ImportBatchService::class)->setMapping($batch, [
        'Given' => 'first_name', 'Family' => 'last_name', 'DOB' => 'date_of_birth',
    ], 'Y-m-d');
    app(ImportValidator::class)->validate($batch->refresh());

    app(ImportCommitter::class)->commit($batch->refresh(), $actor);
    expect(Patient::query()->count())->toBe(2);

    // Second commit is a no-op.
    app(ImportCommitter::class)->commit($batch->refresh(), $actor);
    expect(Patient::query()->count())->toBe(2)
        ->and((int) DB::select("SELECT COUNT(*) c FROM audit_events WHERE tenant_id = ? AND action = 'patient.import.committed'", [$tenant->id])[0]->c)->toBe(1);
});

test('import is tenant isolated: another tenant data is neither matched nor visible', function () {
    Storage::fake('local');

    $alpha = impTenant('alpha');
    $alphaActor = impUser($alpha);
    app(PatientService::class)->create([
        'first_name' => 'Ada', 'last_name' => 'Lovelace',
        'date_of_birth' => '1990-01-02', 'sex' => 'female',
    ]);

    $beta = impTenant('beta');
    $betaActor = impUser($beta);
    $csv = "Given,Family,DOB\nAda,Lovelace,1990-01-02\n"; // same demographics as alpha's patient
    $batch = app(ImportBatchService::class)->upload(impCsv($csv), $betaActor);
    app(ImportBatchService::class)->setMapping($batch, [
        'Given' => 'first_name', 'Family' => 'last_name', 'DOB' => 'date_of_birth',
    ], 'Y-m-d');
    app(ImportValidator::class)->validate($batch->refresh());

    // Alpha's patient must NOT be matched from beta's context.
    $row = ImportRow::query()->where('import_batch_id', $batch->id)->firstOrFail();
    expect($row->status)->toBe(ImportRow::STATUS_VALID)
        ->and($row->matched_patient_id)->toBeNull();

    app(ImportCommitter::class)->commit($batch->refresh(), $betaActor);

    $betaPatient = Patient::query()->firstOrFail();
    expect($betaPatient->tenant_id)->toBe($beta->id)
        ->and(ImportBatch::query()->count())->toBe(1); // only beta's own batch is visible

    // Alpha still has exactly its one original patient.
    impCtx()->set($alpha);
    expect(Patient::query()->count())->toBe(1)
        ->and(ImportBatch::query()->count())->toBe(0);
});

test('the import UI and upload are RBAC gated on data.import', function () {
    $tenant = impTenant('alpha');
    $admin = impUser($tenant, 'org_admin');
    $doctor = impUser($tenant, 'doctor'); // no data.import

    impCtx()->set($tenant);

    $this->actingAs($admin)->get('/imports')->assertOk();

    $this->actingAs($doctor)->get('/imports')->assertForbidden();
    $this->actingAs($doctor)->get('/imports/create')->assertForbidden();
    $this->actingAs($doctor)
        ->post('/imports', ['file' => impCsv("Given\nAda\n")])
        ->assertForbidden();

    expect(ImportBatch::query()->count())->toBe(0);
});

test('a malformed / mis-encoded CSV is handled gracefully, not a crash', function () {
    Storage::fake('local');
    $tenant = impTenant('alpha');
    $actor = impUser($tenant);

    // Invalid UTF-8 byte (0xFF) and a stray quote. Graceful = either it parses
    // into rows whose raw is valid UTF-8, or it raises a clean ImportException —
    // never an uncaught fatal.
    $csv = "Given,Family,DOB\nAd\xFFa,\"Love\"lace,1990-01-02\n";

    try {
        $batch = app(ImportBatchService::class)->upload(impCsv($csv, 'weird.csv'), $actor);
        $row = ImportRow::query()->where('import_batch_id', $batch->id)->first();
        expect($row)->not->toBeNull()
            ->and(json_encode($row->raw))->not->toBeFalse();
    } catch (ImportException $e) {
        expect($e)->toBeInstanceOf(ImportException::class);
    }
});

test('the uploaded CSV is stored on the private disk with a tenant-prefixed path and no public URL', function () {
    Storage::fake('local');
    Storage::fake('public');
    $tenant = impTenant('alpha');
    $actor = impUser($tenant);

    $batch = app(ImportBatchService::class)->upload(impCsv("Given\nAda\n"), $actor);

    expect($batch->storage_path)->toStartWith('tenants/'.$tenant->id.'/imports/')
        ->and(Storage::disk('local')->exists($batch->storage_path))->toBeTrue()
        ->and(Storage::disk('public')->exists($batch->storage_path))->toBeFalse();
});
