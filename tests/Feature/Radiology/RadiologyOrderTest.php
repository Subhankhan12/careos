<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Models\AuditEvent;
use Modules\Clinical\Models\Order;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Radiology\Contracts\ImagingConnectivity;
use Modules\Radiology\Exceptions\RadiologyOrderException;
use Modules\Radiology\Models\RadiologyExam;
use Modules\Radiology\Models\RadiologyOrder;
use Modules\Radiology\Services\NullImagingConnectivity;
use Modules\Radiology\Services\RadiologyCatalogService;
use Modules\Radiology\Services\RadiologyOrderService;

uses(RefreshDatabase::class);

/*
 * RAD.G2 — imaging order entry: an imaging order IS a Clinical Order (REUSED via OrderService::place, ~95%
 * reuse), with a thin imaging overlay (modality/body-part + a RECORDED priority flag, incl STAT). Per
 * docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md. FENCE: the priority is the clinician's recorded flag — never computed;
 * no computed image finding (no image yet). Clinical's Order is not duplicated.
 */

function rdoCtx(): TenantContext
{
    return app(TenantContext::class);
}

function rdoUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, branch: Branch, admin: User, doctor: User, reception: User, patient: Patient, exam: RadiologyExam} */
function rdoFixture(string $slug = 'rdo'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Imaging', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    rdoCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $admin = rdoUser($tenant, 'org_admin');   // radiology.catalog (author the exam) + everything
    $doctor = rdoUser($tenant, 'doctor');      // order.manage (orders imaging)
    $reception = rdoUser($tenant, 'reception'); // NO order.manage
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);
    $exam = app(RadiologyCatalogService::class)->authorExam($admin, 'RAD-CXR', 'Chest X-ray', 'X-ray', 'Chest', false);

    return compact('tenant', 'branch', 'admin', 'doctor', 'reception', 'patient', 'exam');
}

test('placing an imaging order REUSES the Clinical Order (lifecycle) + appends the thin overlay (modality/body-part + priority); audited', function () {
    $fx = rdoFixture();

    $result = app(RadiologyOrderService::class)->place($fx['doctor'], $fx['patient'], $fx['exam'], RadiologyOrder::PRIORITY_URGENT, null, null, null, 'Cough');

    $order = $result['order'];
    $radiologyOrder = $result['radiologyOrder'];

    // The imaging order IS a reused Clinical Order — lifecycle starts at 'ordered'; the orderable is the exam.
    expect($order)->toBeInstanceOf(Order::class)
        ->and($order->status)->toBe(Order::STATUS_ORDERED)
        ->and($order->orderable_item_id)->toBe($fx['exam']->orderable_item_id)
        ->and($order->patient_id)->toBe($fx['patient']->id)
        // The thin overlay records the imaging placement facts (modality/body-part default from the exam).
        ->and($radiologyOrder->order_id)->toBe($order->id)
        ->and($radiologyOrder->modality)->toBe('X-ray')      // defaulted from the exam's orderable
        ->and($radiologyOrder->body_part)->toBe('Chest')     // defaulted from the exam overlay
        ->and($radiologyOrder->priority)->toBe('urgent')     // the RECORDED flag
        // Audited: Clinical audits the order; the overlay audits radiology.order_placed (patient-scoped).
        ->and(AuditEvent::query()->where('action', 'order.placed')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'radiology.order_placed')->where('patient_id', $fx['patient']->id)->count())->toBe(1);

    // Explicit modality/body-part override the exam defaults.
    $r2 = app(RadiologyOrderService::class)->place($fx['doctor'], $fx['patient'], $fx['exam'], RadiologyOrder::PRIORITY_ROUTINE, 'CT', 'Head');
    expect($r2['radiologyOrder']->modality)->toBe('CT')->and($r2['radiologyOrder']->body_part)->toBe('Head');
});

test('FENCE: the priority is the clinician RECORDED flag (STAT overlay-only); nothing computes/ranks a priority; the Clinical Order is UNTOUCHED', function () {
    $fx = rdoFixture();

    $result = app(RadiologyOrderService::class)->place($fx['doctor'], $fx['patient'], $fx['exam'], RadiologyOrder::PRIORITY_STAT);

    // STAT is a RECORDED imaging flag on the overlay; Clinical's Order is UNTOUCHED — its priority stays the
    // default routine (Clinical's Order accepts routine/urgent only; STAT lives only on the imaging overlay).
    expect($result['radiologyOrder']->priority)->toBe('stat')
        ->and($result['order']->priority)->toBe(Order::PRIORITY_ROUTINE);

    // No computed-priority/urgency column on the overlay — priority is a recorded flag, not a computed score.
    $forbidden = ['urgency_score', 'computed_priority', 'priority_score', 'rank', 'escalation', 'urgency'];
    $columns = Schema::getColumnListing('radiology_orders');
    foreach ($forbidden as $word) {
        expect($columns)->not->toContain($word, "radiology_orders must not carry a computed-priority column: {$word}");
    }
    // No computed image finding/CAD column either (there is no image yet — this is order entry).
    foreach (['finding', 'cad', 'abnormal', 'ai', 'confidence'] as $word) {
        expect($columns)->not->toContain($word, "radiology_orders must not carry a computed-image-read column: {$word}");
    }

    // Clinical's `orders` schema was NOT extended with imaging fields — the overlay is Radiology-side (Clinical untouched).
    $orderCols = Schema::getColumnListing('orders');
    expect($orderCols)->not->toContain('modality')->and($orderCols)->not->toContain('body_part')->and($orderCols)->not->toContain('imaging_priority');

    // The module computes/ranks no priority — no rank/escalate/compute-priority logic anywhere in Modules\Radiology\src.
    $files = collect(File::allFiles(base_path('Modules/Radiology/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['computePriority', 'rankByUrgency', 'autoEscalate', 'urgencyScore', 'sortByPriority'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Radiology must not compute/rank a priority ({$needle})");
        }
    }
});

test('the ImagingConnectivity transmitOrder() stays the NULL no-op on place — no homemade DICOM/MWL', function () {
    $fx = rdoFixture();

    // The seam is the null no-op — placing an imaging order calls transmitOrder() and it does nothing (no
    // exception, no network). Radiology owns the seam; it builds no DICOM/PACS client.
    expect(app(ImagingConnectivity::class))->toBeInstanceOf(NullImagingConnectivity::class);
    expect(fn () => app(RadiologyOrderService::class)->place($fx['doctor'], $fx['patient'], $fx['exam'], RadiologyOrder::PRIORITY_ROUTINE))
        ->not->toThrow(Exception::class);

    // No homemade DICOM/PACS client in the radiology module (the defining RIS value is partner-gated, RAD.G6).
    $files = collect(File::allFiles(base_path('Modules/Radiology/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['DicomClient', 'PacsClient', 'DicomStore', 'MllpConnection', 'parseDicom', 'DicomViewer', 'ModalityWorklistServer'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Radiology must not build a homemade DICOM/PACS client ({$needle})");
        }
    }
});

test('the imaging-order overlay is APPEND-ONLY (model guard + DB trigger); invalid priority rejected', function () {
    $fx = rdoFixture();
    $radiologyOrder = app(RadiologyOrderService::class)->place($fx['doctor'], $fx['patient'], $fx['exam'], RadiologyOrder::PRIORITY_ROUTINE)['radiologyOrder'];

    // Model guard (belt).
    expect(fn () => $radiologyOrder->update(['priority' => 'stat']))->toThrow(RadiologyOrderException::class);

    // DB trigger (suspenders) — a raw update/delete bypassing the model still fails.
    expect(fn () => DB::table('radiology_orders')->where('id', $radiologyOrder->id)->update(['priority' => 'stat']))->toThrow(QueryException::class);
    expect(fn () => DB::table('radiology_orders')->where('id', $radiologyOrder->id)->delete())->toThrow(QueryException::class);

    // An unknown priority is rejected (a recorded flag from a closed set, not a computed value).
    expect(fn () => app(RadiologyOrderService::class)->place($fx['doctor'], $fx['patient'], $fx['exam'], 'whenever'))
        ->toThrow(RadiologyOrderException::class);
});

test('RBAC: placing an imaging order is order.manage-gated (reused); reception is refused', function () {
    $fx = rdoFixture();

    // reception holds no order.manage — the reused OrderService::place refuses it.
    expect(fn () => app(RadiologyOrderService::class)->place($fx['reception'], $fx['patient'], $fx['exam'], RadiologyOrder::PRIORITY_ROUTINE))
        ->toThrow(AuthorizationException::class);

    // The doctor (order.manage) can place.
    expect(app(RadiologyOrderService::class)->place($fx['doctor'], $fx['patient'], $fx['exam'], RadiologyOrder::PRIORITY_ROUTINE)['order']->status)->toBe(Order::STATUS_ORDERED);
});

test('cross-tenant is fail-closed: a tenant cannot place an imaging order on another tenant patient/exam', function () {
    $fxA = rdoFixture('alpha');

    $fxB = rdoFixture('beta');
    rdoCtx()->set($fxB['tenant']);

    // Under tenant B, tenant A's patient is invisible — the reused OrderService::place fails closed.
    expect(fn () => app(RadiologyOrderService::class)->place($fxB['doctor'], $fxA['patient'], $fxB['exam'], RadiologyOrder::PRIORITY_ROUTINE))
        ->toThrow(CrossTenantReferenceException::class);
});
