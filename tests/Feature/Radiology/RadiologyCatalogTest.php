<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Models\AuditEvent;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Radiology\Contracts\ImagingConnectivity;
use Modules\Radiology\Exceptions\RadiologyCatalogException;
use Modules\Radiology\Models\RadiologyExam;
use Modules\Radiology\Services\NullImagingConnectivity;
use Modules\Radiology\Services\RadiologyCatalogService;

uses(RefreshDatabase::class);

/*
 * RAD.G1 — the radiology vertical FOUNDATION: the tenant-authored imaging exam catalog (a Clinical OrderableItem
 * overlay, NO licensed set), the CREATED ImagingConnectivity (PACS/DICOM) seam (a null no-op — no imaging seam
 * existed before), and radiology RBAC. Per docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md. FENCE: the catalog records
 * modality/body-part reference data; the seam never interprets an image (no CAD/finding — "AI radiology" is a
 * hard non-goal). Radiology REUSES Clinical's Order/ClinicalNote/Document — it does not duplicate them.
 */

function radCtx(): TenantContext
{
    return app(TenantContext::class);
}

function radUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, radiologist: User, radiographer: User, reception: User} */
function radFixture(string $slug = 'rad'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Imaging', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    radCtx()->set($tenant);

    $radiologist = radUser($tenant, 'radiologist');   // radiology.catalog + radiology.study
    $radiographer = radUser($tenant, 'radiographer'); // radiology.study, NO radiology.catalog
    $reception = radUser($tenant, 'reception');        // neither

    return compact('tenant', 'radiologist', 'radiographer', 'reception');
}

test('an imaging exam is a TENANT-AUTHORED OrderableItem overlay (reused, not duplicated); modality on the orderable, body-part/contrast on the overlay', function () {
    $fx = radFixture();

    $exam = app(RadiologyCatalogService::class)->authorExam($fx['radiologist'], 'RAD-CXR', 'Chest X-ray', 'X-ray', 'Chest', false);

    // The exam REUSES the existing Clinical OrderableItem (category=imaging) — no parallel order/exam entity.
    $orderable = OrderableItem::query()->findOrFail($exam->orderable_item_id);
    expect($orderable->category)->toBe(OrderableItem::CATEGORY_IMAGING)
        ->and($orderable->code)->toBe('RAD-CXR')
        ->and($orderable->name)->toBe('Chest X-ray')
        ->and($orderable->specimen_or_modality)->toBe('X-ray')   // the modality lives on the existing field
        // The overlay carries only imaging facts — body part + contrast (never a computed image read).
        ->and($exam->body_part)->toBe('Chest')
        ->and($exam->contrast)->toBeFalse()
        // Audited (tenant-level).
        ->and(AuditEvent::query()->where('action', 'radiology.exam_authored')->where('resource_type', 'radiology_exam')->count())->toBe(1);

    // Re-authoring the same code updates in place (idempotent; one orderable + one overlay).
    app(RadiologyCatalogService::class)->authorExam($fx['radiologist'], 'RAD-CXR', 'Chest X-ray', 'X-ray', 'Chest', true);
    expect(OrderableItem::query()->where('code', 'RAD-CXR')->count())->toBe(1)
        ->and(RadiologyExam::query()->where('orderable_item_id', $orderable->id)->count())->toBe(1)
        ->and($exam->fresh()->contrast)->toBeTrue();

    // A code + name are required.
    expect(fn () => app(RadiologyCatalogService::class)->authorExam($fx['radiologist'], '  ', 'X'))->toThrow(RadiologyCatalogException::class);
});

test('the starter set is a GENERIC editable template (NOT a licensed code set), tenant-isolated', function () {
    $fx = radFixture();

    $created = app(RadiologyCatalogService::class)->seedStarter($fx['radiologist']);
    expect($created)->toBeGreaterThan(0)
        ->and(RadiologyExam::query()->count())->toBe(6)                                    // the generic template
        ->and(OrderableItem::query()->where('code', 'RAD-CT-HEAD')->where('category', 'imaging')->exists())->toBeTrue();

    // Idempotent — re-seeding creates nothing new.
    expect(app(RadiologyCatalogService::class)->seedStarter($fx['radiologist']))->toBe(0)
        ->and(RadiologyExam::query()->count())->toBe(6);

    // The starter is a plain, editable template — a tenant can deactivate/replace any item (not a locked set).
    $ct = RadiologyExam::query()->whereHas('orderableItem', fn ($q) => $q->where('code', 'RAD-CT-HEAD'))->firstOrFail();
    app(RadiologyCatalogService::class)->deactivate($fx['radiologist'], $ct);
    expect(OrderableItem::query()->where('code', 'RAD-CT-HEAD')->firstOrFail()->active)->toBeFalse();

    // Tenant-isolated: another tenant's catalog is empty until it authors/seeds its own (no shared licensed data).
    $fxB = radFixture('radb');
    radCtx()->set($fxB['tenant']);
    expect(RadiologyExam::query()->count())->toBe(0)->and(OrderableItem::query()->where('category', 'imaging')->count())->toBe(0);
});

test('FENCE: the catalog records modality/body-part but computes NO image finding/CAD; no judgment column or logic', function () {
    $fx = radFixture();
    app(RadiologyCatalogService::class)->authorExam($fx['radiologist'], 'RAD-CT-CHEST', 'CT chest', 'CT', 'Chest', true);

    // The radiology_exams overlay carries only recorded reference data — NO computed-image-read column.
    $forbidden = ['finding', 'cad', 'abnormal', 'abnormality', 'ai', 'confidence', 'detected', 'overlay', 'interpretation', 'severity', 'flag'];
    $columns = Schema::getColumnListing('radiology_exams');
    foreach ($forbidden as $word) {
        expect($columns)->not->toContain($word, "radiology_exams must not carry a computed-image-read column: {$word}");
    }

    // The module reads no image — no CAD/finding/auto-read logic anywhere in Modules\Radiology\src.
    $files = collect(File::allFiles(base_path('Modules/Radiology/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['computeFinding', 'detectAbnormality', 'cadRead', 'autoRead', 'interpretImage', 'confidenceScore', 'aiRead', 'flagAbnormal'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Radiology must not read/interpret an image ({$needle})");
        }
    }
});

test('the ImagingConnectivity seam is CREATED + bound to the null no-op; NO DICOM/PACS integration; swappable for a partner; the seam never interprets', function () {
    radFixture();

    // The seam is CREATED (no imaging seam existed) and bound to the null no-op — NOT a built PACS integration.
    $imaging = app(ImagingConnectivity::class);
    expect($imaging)->toBeInstanceOf(NullImagingConnectivity::class);

    // transmitOrder is a no-op today (no modality worklist to push to) — no exception, nothing sent.
    $order = new Order;
    expect(fn () => $imaging->transmitOrder($order))->not->toThrow(Exception::class);

    // The imported path is partner-gated (RAD.G6, SEAM-STUBBED): ingestStudy THROWS "not available" today —
    // there is NO homemade DICOM/PACS stack. A certified partner fills it later (records; never interprets).
    expect(fn () => $imaging->ingestStudy(['study' => 'x']))->toThrow(RuntimeException::class);

    // The swap point works: bind a certified-partner test double in place of the null-object — no consumer change.
    $partner = new class implements ImagingConnectivity
    {
        public bool $transmitted = false;

        public function transmitOrder(Order $order): void
        {
            $this->transmitted = true; // a partner would push the DICOM MWL entry here
        }

        public function ingestStudy(array $payload): void
        {
            // a partner records an imported study/report here — it NEVER interprets the image
        }
    };
    app()->instance(ImagingConnectivity::class, $partner);
    $resolved = app(ImagingConnectivity::class);
    $resolved->transmitOrder($order);
    expect($resolved)->toBe($partner)
        ->and($partner->transmitted)->toBeTrue()
        ->and(fn () => $resolved->ingestStudy(['study' => 'x']))->not->toThrow(Exception::class); // a partner may record, never interpret

    // No homemade DICOM/PACS client is BUILT in the radiology module (the defining RIS value is partner-gated).
    $files = collect(File::allFiles(base_path('Modules/Radiology/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['DicomClient', 'PacsClient', 'DicomStore', 'MllpConnection', 'parseDicom', 'DicomViewer', 'ModalityWorklistServer'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Radiology must not build a homemade DICOM/PACS stack ({$needle})");
        }
    }
});

test('RBAC: the exam catalog is radiology.catalog-gated; radiographer (radiology.study only) and reception are refused', function () {
    $fx = radFixture();
    $svc = app(RadiologyCatalogService::class);

    // radiographer holds radiology.study but NOT radiology.catalog — cannot author the catalog.
    expect(fn () => $svc->authorExam($fx['radiographer'], 'X', 'X'))->toThrow(AuthorizationException::class);
    expect(fn () => $svc->seedStarter($fx['radiographer']))->toThrow(AuthorizationException::class);
    // reception holds neither.
    expect(fn () => $svc->authorExam($fx['reception'], 'X', 'X'))->toThrow(AuthorizationException::class);

    // The radiologist (radiology.catalog) can author.
    expect($svc->authorExam($fx['radiologist'], 'RAD-US-PELVIS', 'Pelvic ultrasound', 'US', 'Pelvis', false)->body_part)->toBe('Pelvis');
});
