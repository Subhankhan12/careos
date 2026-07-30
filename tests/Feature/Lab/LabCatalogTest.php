<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Models\AuditEvent;
use Modules\Clinical\Contracts\LabConnectivity;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\Clinical\Services\ManualLabConnectivity;
use Modules\Lab\Exceptions\LabCatalogException;
use Modules\Lab\Models\LabTest;
use Modules\Lab\Services\LabCatalogService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * LAB.G1 — the lab vertical FOUNDATION: the tenant-authored test catalog (a Clinical OrderableItem overlay,
 * NO licensed set), the FORMALIZED LabConnectivity seam (the existing manual no-op, kept + documented), and
 * lab RBAC. Per docs/HOSPITAL-PHASE3-LAB-MAP.md. FENCE: the reference range is recorded reference data, never
 * a computed grade. Lab REUSES Clinical's Order/OrderResult/seam — it does not duplicate them.
 */

function labCtx(): TenantContext
{
    return app(TenantContext::class);
}

function labUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, pathologist: User, labTech: User, reception: User} */
function labFixture(string $slug = 'lab'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Lab', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    labCtx()->set($tenant);

    $pathologist = labUser($tenant, 'pathologist'); // lab.catalog + lab.result
    $labTech = labUser($tenant, 'lab_tech');         // lab.result, NO lab.catalog
    $reception = labUser($tenant, 'reception');       // neither

    return compact('tenant', 'pathologist', 'labTech', 'reception');
}

test('a lab test is a TENANT-AUTHORED OrderableItem overlay (reused, not duplicated); the reference range is recorded reference data', function () {
    $fx = labFixture();

    $test = app(LabCatalogService::class)->authorTest($fx['pathologist'], 'LAB-K', 'Potassium', 'Blood', 'mmol/L', '3.5–5.1');

    // The lab test REUSES the existing Clinical OrderableItem (category=lab) — no parallel order/test entity.
    $orderable = OrderableItem::query()->findOrFail($test->orderable_item_id);
    expect($orderable->category)->toBe(OrderableItem::CATEGORY_LAB)
        ->and($orderable->code)->toBe('LAB-K')
        ->and($orderable->name)->toBe('Potassium')
        ->and($orderable->specimen_or_modality)->toBe('Blood')
        // The overlay carries only the DISPLAY reference data — a recorded range, NOT a computed grade.
        ->and($test->unit)->toBe('mmol/L')
        ->and($test->reference_range)->toBe('3.5–5.1')
        // Audited (tenant-level).
        ->and(AuditEvent::query()->where('action', 'lab.test_authored')->where('resource_type', 'lab_test')->count())->toBe(1);

    // Re-authoring the same code updates in place (idempotent; one orderable + one overlay).
    app(LabCatalogService::class)->authorTest($fx['pathologist'], 'LAB-K', 'Potassium', 'Blood', 'mmol/L', '3.6–5.0');
    expect(OrderableItem::query()->where('code', 'LAB-K')->count())->toBe(1)
        ->and(LabTest::query()->where('orderable_item_id', $orderable->id)->count())->toBe(1)
        ->and($test->fresh()->reference_range)->toBe('3.6–5.0');

    // A code + name are required.
    expect(fn () => app(LabCatalogService::class)->authorTest($fx['pathologist'], '  ', 'X'))->toThrow(LabCatalogException::class);
});

test('the starter set is a GENERIC editable template (NOT a licensed code set), tenant-isolated', function () {
    $fx = labFixture();

    $created = app(LabCatalogService::class)->seedStarter($fx['pathologist']);
    expect($created)->toBeGreaterThan(0)
        ->and(LabTest::query()->count())->toBe(6)                       // the generic template
        ->and(OrderableItem::query()->where('code', 'LAB-HB')->where('category', 'lab')->exists())->toBeTrue();

    // Idempotent — re-seeding creates nothing new.
    expect(app(LabCatalogService::class)->seedStarter($fx['pathologist']))->toBe(0)
        ->and(LabTest::query()->count())->toBe(6);

    // The starter is a plain, editable template — a tenant can deactivate/replace any item (not a locked set).
    $hb = LabTest::query()->whereHas('orderableItem', fn ($q) => $q->where('code', 'LAB-HB'))->firstOrFail();
    app(LabCatalogService::class)->deactivate($fx['pathologist'], $hb);
    expect(OrderableItem::query()->where('code', 'LAB-HB')->firstOrFail()->active)->toBeFalse();

    // Tenant-isolated: another tenant's catalog is empty until it authors/seeds its own (no shared licensed data).
    $fxB = labFixture('labb');
    labCtx()->set($fxB['tenant']);
    expect(LabTest::query()->count())->toBe(0)->and(OrderableItem::query()->where('category', 'lab')->count())->toBe(0);
});

test('FENCE: the catalog records a reference range but computes NO abnormal/critical grade; no judgment column', function () {
    $fx = labFixture();
    app(LabCatalogService::class)->authorTest($fx['pathologist'], 'LAB-GLU', 'Glucose', 'Blood', 'mmol/L', '3.9–5.5');

    // The lab_tests overlay carries only recorded reference data — NO computed-judgment column.
    $forbidden = ['abnormal', 'critical', 'flag', 'high', 'low', 'grade', 'score', 'severity', 'interpretation', 'result_flag'];
    $columns = Schema::getColumnListing('lab_tests');
    foreach ($forbidden as $word) {
        expect($columns)->not->toContain($word, "lab_tests must not carry a computed-judgment column: {$word}");
    }

    // The module grades nothing — no compute/grade/flag-a-result logic anywhere in Modules\Lab\src.
    $files = collect(File::allFiles(base_path('Modules/Lab/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['gradeResult', 'computeFlag', 'isAbnormal', 'flagResult', 'criticalValue', 'interpretResult'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Lab must not grade a result ({$needle})");
        }
    }
});

test('the LabConnectivity seam is FORMALIZED, not filled: the bound impl is the MANUAL no-op; no homemade HL7 client', function () {
    labFixture();

    // The seam already lives in Clinical and is bound to the MANUAL no-op — Lab CONSUMES it, does not re-create it.
    $lab = app(LabConnectivity::class);
    expect($lab)->toBeInstanceOf(ManualLabConnectivity::class);

    // The MANUAL path: transmit is a no-op (no exception, nothing sent).
    $order = new Order;
    expect(fn () => $lab->transmit($order))->not->toThrow(Exception::class);

    // The IMPORTED path is partner-gated (LAB.G7, SEAM-STUBBED): ingestResult THROWS "entered manually" today —
    // there is NO homemade HL7/analyzer client. A certified partner fills it later (append OrderResult, never interpret).
    expect(fn () => $lab->ingestResult(['x' => 1]))->toThrow(RuntimeException::class);

    // No homemade HL7/analyzer client is BUILT in the lab module (the defining LIS value is partner-gated).
    // Discriminating needles: a real client would `implements LabConnectivity` (the seam impl lives in
    // Clinical, not here) or be a named HL7/FHIR/analyzer client class / parser — none of which is prose.
    $files = collect(File::allFiles(base_path('Modules/Lab/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['implements LabConnectivity', 'FhirClient', 'Hl7Client', 'Hl7Message', 'AnalyzerFeed', 'parseHl7', 'sendHl7', 'MllpConnection'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Lab must not build a homemade HL7/analyzer client ({$needle})");
        }
    }
});

test('RBAC: the catalog is lab.catalog-gated; lab_tech (lab.result only) and reception are refused', function () {
    $fx = labFixture();
    $svc = app(LabCatalogService::class);

    // lab_tech holds lab.result but NOT lab.catalog — cannot author the catalog.
    expect(fn () => $svc->authorTest($fx['labTech'], 'X', 'X'))->toThrow(AuthorizationException::class);
    expect(fn () => $svc->seedStarter($fx['labTech']))->toThrow(AuthorizationException::class);
    // reception holds neither.
    expect(fn () => $svc->authorTest($fx['reception'], 'X', 'X'))->toThrow(AuthorizationException::class);

    // The pathologist (lab.catalog) can author.
    expect($svc->authorTest($fx['pathologist'], 'LAB-NA', 'Sodium', 'Blood', 'mmol/L', '135–145')->reference_range)->toBe('135–145');
});
