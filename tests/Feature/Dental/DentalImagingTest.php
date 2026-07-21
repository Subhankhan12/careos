<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Models\AuditEvent;
use Modules\Clinical\Models\Document;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\DentalImage;
use Modules\Dental\Models\DentalImageReading;
use Modules\Dental\Services\DentalImagingService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * DENTAL.G8 — dental imaging: upload + view + a DENTIST-authored reading, over the EXISTING clinical
 * document storage. These tests prove: an upload is stored privately (tenant-prefixed path, no public
 * URL) with a Document + dental metadata; a reading is the dentist's own text, append-only (a change
 * preserves history — model AND raw-DB); the image asset is immutable; the FENCE — nothing analyses
 * the image and the payload carries no ai/finding/overlay field (nothing is auto-generated); RBAC;
 * tenant scoping.
 */

function diCtx(): TenantContext
{
    return app(TenantContext::class);
}

function diUser(Tenant $tenant, string $role): User
{
    diCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, doctor: User, patient: Patient}
 */
function diFixture(string $slug = 'alpha'): array
{
    Storage::fake('local');
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Dental', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    diCtx()->set($tenant);
    $doctor = diUser($tenant, 'doctor'); // holds dental.chart + patient.view + note.write (the general dentist)
    $patient = app(PatientService::class)->create(['first_name' => 'Ivan', 'last_name' => 'Image', 'date_of_birth' => '1979-09-09', 'sex' => 'male']);

    return compact('tenant', 'doctor', 'patient');
}

function diUpload(array $fx, string $type = 'bitewing', ?string $tooth = '16'): DentalImage
{
    // ->create(name, kb, mime) avoids a GD dependency in CI; DocumentService reads getMimeType().
    return app(DentalImagingService::class)->upload($fx['doctor'], $fx['patient'], UploadedFile::fake()->create('xray.jpg', 200, 'image/jpeg'), $type, $tooth);
}

/**
 * Recursively assert no analysis/AI key leaked into the imaging payload (the fence).
 *
 * @param  array<mixed>  $data
 */
function diAssertNoAnalysis(array $data): void
{
    $forbidden = ['ai', 'finding', 'findings', 'detected', 'detection', 'overlay', 'annotation', 'annotations', 'confidence', 'severity', 'grade', 'score', 'analysis', 'analyzed', 'predicted', 'prediction', 'auto', 'suggested', 'recommendation', 'pathology', 'diagnosis', 'flag'];
    foreach ($data as $key => $value) {
        expect(in_array((string) $key, $forbidden, true))->toBeFalse("analysis key '{$key}' leaked into the imaging payload");
        if (is_array($value)) {
            diAssertNoAnalysis($value);
        }
    }
}

test('an upload is stored via the EXISTING clinical storage — private, tenant-prefixed, no public URL — with metadata + audit', function () {
    $fx = diFixture();

    $image = diUpload($fx, 'bitewing', '16');

    // A Document (category image) backs it, and the file is on the PRIVATE 'local' disk under a
    // tenant-prefixed path (no public URL).
    $document = Document::query()->whereKey($image->document_id)->firstOrFail();
    expect($document->category)->toBe(Document::CATEGORY_IMAGE)
        ->and($document->storage_path)->toStartWith('tenants/'.$fx['tenant']->id.'/clinical-documents/')
        ->and(Storage::disk('local')->exists($document->storage_path))->toBeTrue();

    // The dental metadata is attached; no reading was auto-generated.
    expect($image->image_type)->toBe('bitewing')->and($image->tooth)->toBe('16')
        ->and(DentalImage::query()->where('patient_id', $fx['patient']->id)->count())->toBe(1)
        ->and(DentalImageReading::query()->count())->toBe(0);

    expect(AuditEvent::query()->where('tenant_id', $fx['tenant']->id)->where('action', 'dental.image_uploaded')->exists())->toBeTrue();

    // An invalid image type is refused (deterministic data-entry validation, not interpretation).
    expect(fn () => app(DentalImagingService::class)->upload($fx['doctor'], $fx['patient'], UploadedFile::fake()->create('x.png', 20, 'image/png'), 'ai-scan'))
        ->toThrow(DentalException::class);
});

test('a dentist reading is append-only (a change preserves history) and the image asset is immutable — model AND raw-DB', function () {
    $fx = diFixture();
    $svc = app(DentalImagingService::class);
    $image = diUpload($fx);

    $first = $svc->recordReading($fx['doctor'], $image, 'Interproximal radiolucency distal of 16 — my read.');
    $svc->recordReading($fx['doctor'], $image, 'On review, extends into dentine.', 'Second look.');

    expect(DentalImageReading::query()->where('dental_image_id', $image->id)->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'dental.image_read')->count())->toBe(2);

    // Append-only reading at model + raw-DB level.
    expect(fn () => $first->update(['reading' => 'x']))->toThrow(DentalException::class);
    expect(fn () => DB::table('dental_image_readings')->where('id', $first->id)->update(['reading' => 'x']))->toThrow(QueryException::class);
    expect(fn () => DB::table('dental_image_readings')->where('id', $first->id)->delete())->toThrow(QueryException::class);

    // The image asset itself is immutable.
    expect(fn () => $image->update(['image_type' => 'photo']))->toThrow(DentalException::class);
    expect(fn () => DB::table('dental_images')->where('id', $image->id)->update(['image_type' => 'photo']))->toThrow(QueryException::class);
    expect(fn () => DB::table('dental_images')->where('id', $image->id)->delete())->toThrow(QueryException::class);
});

test('the fence: nothing analyses the image and the payload carries no ai/finding/overlay field', function () {
    $fx = diFixture();
    $image = diUpload($fx, 'periapical', '46');
    app(DentalImagingService::class)->recordReading($fx['doctor'], $image, 'My written interpretation.');

    diCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->get(route('dental.imaging', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dental/Imaging')
            ->has('images', 1)
            ->has('types', 5)
            ->where('actions.can_manage', true)
            // Only the dentist's reading + facts — no analysis/finding/overlay key anywhere.
            ->where('images', function ($images) {
                diAssertNoAnalysis(collect($images)->toArray());
                // The reading is exactly what the dentist wrote (not generated).
                expect(collect($images)->first()['readings'][0]['reading'])->toBe('My written interpretation.');

                return true;
            }));
});

test('the private image streams only through the authed route (nosniff), never a public URL', function () {
    $fx = diFixture();
    $image = diUpload($fx);

    diCtx()->forget();
    $response = $this->actingAs($fx['doctor'])->get(route('dental.imaging.file', $image->id));
    $response->assertOk();
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Type'))->toContain('image/');

    // A read of the image was logged.
    expect(AuditEvent::query()->where('tenant_id', $fx['tenant']->id)->where('action', 'read')->where('resource_type', 'document')->exists())->toBeTrue();
});

test('RBAC: dental.chart uploads/annotates, patient.view views, and neither is bypassable', function () {
    $fx = diFixture();
    diUpload($fx);

    // reception has patient.view (can VIEW) but not dental.chart (cannot UPLOAD/annotate).
    $reception = diUser($fx['tenant'], 'reception');
    diCtx()->forget();
    $this->actingAs($reception)->get(route('dental.imaging', $fx['patient']->id))->assertOk();
    diCtx()->forget();
    $this->actingAs($reception)
        ->post(route('dental.imaging.store', $fx['patient']->id), ['file' => UploadedFile::fake()->create('x.jpg', 20, 'image/jpeg'), 'image_type' => 'photo'])
        ->assertForbidden();
    expect(DentalImage::query()->count())->toBe(1); // still just the fixture upload

    // billing has neither patient.view nor dental.chart → cannot even view.
    $billing = diUser($fx['tenant'], 'billing');
    diCtx()->forget();
    $this->actingAs($billing)->get(route('dental.imaging', $fx['patient']->id))->assertForbidden();
});

test('dental imaging is tenant-scoped: a cross-tenant patient/image fails closed', function () {
    $alpha = diFixture('alpha');
    $alphaImage = diUpload($alpha);

    $beta = diFixture('beta');

    // The gallery 404s on a cross-tenant patient.
    diCtx()->forget();
    $this->actingAs($beta['doctor'])->get(route('dental.imaging', $alpha['patient']->id))->assertNotFound();

    // A cross-tenant image download / reading fails closed (BelongsToTenant scopes the lookup → 404).
    diCtx()->forget();
    $this->actingAs($beta['doctor'])->get(route('dental.imaging.file', $alphaImage->id))->assertNotFound();
    diCtx()->forget();
    $this->actingAs($beta['doctor'])->post(route('dental.imaging.reading', $alphaImage->id), ['reading' => 'x'])->assertNotFound();
});
