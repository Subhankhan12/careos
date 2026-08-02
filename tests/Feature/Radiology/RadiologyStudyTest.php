<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
use Modules\Radiology\Exceptions\ImagingStudyException;
use Modules\Radiology\Models\ImagingStudy;
use Modules\Radiology\Models\ImagingStudyEvent;
use Modules\Radiology\Models\RadiologyExam;
use Modules\Radiology\Models\RadiologyOrder;
use Modules\Radiology\Services\ImagingStudyService;
use Modules\Radiology\Services\RadiologyCatalogService;
use Modules\Radiology\Services\RadiologyOrderService;

uses(RefreshDatabase::class);

/*
 * RAD.G3 — the net-new ImagingStudy: a study registered against a RAD.G2 order, accessioned (unique per tenant),
 * advanced through a legal-only state machine (ordered → acquired → reported; + cancelled). Per
 * docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md §2.2 (the lab-Specimen analog). The DICOM image path is SEAM-STUBBED
 * (RAD.G6) — the study is metadata. FENCE: state/accession/worklist are facts — no computed image finding/CAD,
 * no computed priority.
 */

function risCtx(): TenantContext
{
    return app(TenantContext::class);
}

function risUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, admin: User, radiographer: User, reception: User, patient: Patient, exam: RadiologyExam, order: RadiologyOrder} */
function risFixture(string $slug = 'ris'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Imaging', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    risCtx()->set($tenant);

    Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $admin = risUser($tenant, 'org_admin');        // radiology.catalog + order.manage + radiology.study (setup)
    $radiographer = risUser($tenant, 'radiographer'); // radiology.study + order.manage (acquires)
    $reception = risUser($tenant, 'reception');       // no radiology.study
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);
    $exam = app(RadiologyCatalogService::class)->authorExam($admin, 'RAD-CXR', 'Chest X-ray', 'X-ray', 'Chest', false);
    $order = app(RadiologyOrderService::class)->place($admin, $patient, $exam, RadiologyOrder::PRIORITY_ROUTINE)['radiologyOrder'];

    return compact('tenant', 'admin', 'radiographer', 'reception', 'patient', 'exam', 'order');
}

test('a study is acquired for an imaging order — accessioned, typed, audited, read-logged; the Clinical Order is UNTOUCHED', function () {
    $fx = risFixture();

    // The radiographer (radiology.study) acquires — register (ordered) then ordered → acquired.
    $study = app(ImagingStudyService::class)->acquire($fx['radiographer'], $fx['order']);

    expect($study->status)->toBe(ImagingStudy::STATUS_ACQUIRED)
        ->and($study->accession_number)->toBe('IMG-000001')     // a generated factual identifier
        ->and($study->modality)->toBe('X-ray')                  // from the RAD.G2 order overlay
        ->and($study->acquired_by)->toBe($fx['radiographer']->id)
        ->and($study->patient_id)->toBe($fx['patient']->id)
        // Two append-only state events so far: ordered (register) + acquired.
        ->and(ImagingStudyEvent::query()->where('imaging_study_id', $study->id)->where('event_type', 'ordered')->count())->toBe(1)
        ->and(ImagingStudyEvent::query()->where('imaging_study_id', $study->id)->where('event_type', 'acquired')->count())->toBe(1)
        // Audited (patient-scoped).
        ->and(AuditEvent::query()->where('action', 'radiology.study_accessioned')->where('patient_id', $fx['patient']->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'radiology.study.acquired')->where('resource_type', 'imaging_study_event')->count())->toBe(1);

    // THE CLINICAL ORDER IS UNTOUCHED: acquiring the study did NOT advance the reused Order's lifecycle (it stays
    // 'ordered'; the Order is Clinical's — the report step (G4) advances it).
    $order = Order::query()->findOrFail($fx['order']->order_id);
    expect($order->status)->toBe(Order::STATUS_ORDERED);

    // Read-logged.
    $study->auditRead();
    expect(AuditEvent::query()->where('action', 'read')->where('resource_type', 'imaging_studies')->where('patient_id', $fx['patient']->id)->count())->toBe(1);
});

test('the accession is UNIQUE per tenant (safe sequential generation)', function () {
    $fx = risFixture();
    $svc = app(ImagingStudyService::class);

    $a = $svc->acquire($fx['admin'], $fx['order']);
    // A second imaging order → a second study with the next accession.
    $order2 = app(RadiologyOrderService::class)->place($fx['admin'], $fx['patient'], $fx['exam'], RadiologyOrder::PRIORITY_ROUTINE)['radiologyOrder'];
    $b = $svc->acquire($fx['admin'], $order2);
    expect($a->accession_number)->toBe('IMG-000001')
        ->and($b->accession_number)->toBe('IMG-000002')
        ->and($a->accession_number)->not->toBe($b->accession_number);

    // Another tenant's accessions start fresh (unique PER TENANT).
    $fxB = risFixture('risb');
    expect(app(ImagingStudyService::class)->acquire($fxB['admin'], $fxB['order'])->accession_number)->toBe('IMG-000001');
});

test('the study state machine is LEGAL-ONLY (ordered → acquired → reported); illegal throws; cancel needs a reason', function () {
    $fx = risFixture();
    $svc = app(ImagingStudyService::class);
    $study = $svc->register($fx['admin'], $fx['order']); // status ordered

    expect($study->status)->toBe(ImagingStudy::STATUS_ORDERED);

    // Cannot skip acquired: ordered → reported is illegal.
    expect(fn () => $svc->transition($fx['admin'], $study, ImagingStudy::STATUS_REPORTED))->toThrow(ImagingStudyException::class);

    // The legal path.
    $svc->transition($fx['admin'], $study, ImagingStudy::STATUS_ACQUIRED);
    $svc->transition($fx['admin'], $study->fresh(), ImagingStudy::STATUS_REPORTED);
    expect($study->fresh()->status)->toBe(ImagingStudy::STATUS_REPORTED)
        // reported is terminal.
        ->and(fn () => $svc->transition($fx['admin'], $study->fresh(), ImagingStudy::STATUS_ACQUIRED))->toThrow(ImagingStudyException::class);

    // Cancellation requires a reason.
    $order2 = app(RadiologyOrderService::class)->place($fx['admin'], $fx['patient'], $fx['exam'], RadiologyOrder::PRIORITY_ROUTINE)['radiologyOrder'];
    $s2 = $svc->register($fx['admin'], $order2);
    expect(fn () => $svc->transition($fx['admin'], $s2, ImagingStudy::STATUS_CANCELLED))->toThrow(ImagingStudyException::class);
    $svc->transition($fx['admin'], $s2->fresh(), ImagingStudy::STATUS_CANCELLED, 'Patient did not attend');
    expect($s2->fresh()->status)->toBe(ImagingStudy::STATUS_CANCELLED);
});

test('the study state history is APPEND-ONLY (model guard + DB trigger)', function () {
    $fx = risFixture();
    app(ImagingStudyService::class)->register($fx['admin'], $fx['order']);
    $event = ImagingStudyEvent::query()->firstOrFail();

    // Model guard (belt).
    expect(fn () => $event->update(['reason' => 'tampered']))->toThrow(ImagingStudyException::class);

    // DB trigger (suspenders).
    expect(fn () => DB::table('imaging_study_events')->where('id', $event->id)->update(['reason' => 'tampered']))->toThrow(QueryException::class);
    expect(fn () => DB::table('imaging_study_events')->where('id', $event->id)->delete())->toThrow(QueryException::class);
});

test('FENCE: study state + accession + worklist are facts; NO computed image finding/CAD/priority; NO DICOM/viewer built', function () {
    $fx = risFixture();
    app(ImagingStudyService::class)->acquire($fx['admin'], $fx['order']);

    // No computed image-read OR computed-priority column on imaging_studies — state + accession are facts.
    $forbidden = ['finding', 'cad', 'abnormal', 'ai', 'confidence', 'priority_score', 'urgency', 'rank', 'severity'];
    $columns = Schema::getColumnListing('imaging_studies');
    foreach ($forbidden as $word) {
        expect($columns)->not->toContain($word, "imaging_studies must not carry a computed-judgment column: {$word}");
    }

    // The module reads no image + ranks nothing — no CAD/finding/compute-priority logic in Modules\Radiology\src.
    $files = collect(File::allFiles(base_path('Modules/Radiology/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['computeFinding', 'detectAbnormality', 'cadRead', 'interpretImage', 'aiRead', 'computePriority', 'rankByUrgency', 'urgencyScore'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Radiology must not compute an image read/priority ({$needle})");
        }
    }

    // THE IMAGE PATH IS SEAM-STUBBED — no DICOM storage/viewer/PACS client is BUILT (RAD.G6, partner-gated).
    foreach (['DicomClient', 'PacsClient', 'DicomStore', 'MllpConnection', 'parseDicom', 'DicomViewer', 'ModalityWorklistServer'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Radiology must not build a homemade DICOM/PACS stack ({$needle})");
        }
    }
});

test('the modality worklist shows studies as FACTS — ordered by ordered-time, NOT by the recorded priority flag', function () {
    // Freeze the clock BEFORE the fixture so its routine order gets the EARLIER ordered-time deterministically.
    $this->travelTo(Carbon::parse('2026-08-02 09:00:00'));
    $fx = risFixture(); // the fixture's routine order is placed at 09:00 (earliest)

    // A STAT order placed LATER.
    $this->travelTo(Carbon::parse('2026-08-02 11:00:00'));
    $statOrder = app(RadiologyOrderService::class)->place($fx['admin'], $fx['patient'], $fx['exam'], RadiologyOrder::PRIORITY_STAT)['radiologyOrder'];

    // Both are awaiting acquisition → both on the worklist, ordered by ordered-time (a fact), NOT by STAT.
    $worklist = app(ImagingStudyService::class)->worklist($fx['radiographer']);
    expect($worklist)->toHaveCount(2)
        ->and($worklist->first()->id)->toBe($fx['order']->id)        // the earlier-ordered ROUTINE order sorts first
        ->and($worklist->last()->id)->toBe($statOrder->id);          // the later-ordered STAT order sorts last (not first)

    // Acquiring an order removes it from the "to acquire" worklist.
    app(ImagingStudyService::class)->acquire($fx['radiographer'], $fx['order']);
    expect(app(ImagingStudyService::class)->worklist($fx['radiographer'])->pluck('id')->all())->toBe([$statOrder->id]);
});

test('RBAC: acquiring + transitioning are radiology.study-gated; reception is refused', function () {
    $fx = risFixture();
    $svc = app(ImagingStudyService::class);

    // reception holds no radiology.study — cannot acquire.
    expect(fn () => $svc->acquire($fx['reception'], $fx['order']))->toThrow(AuthorizationException::class);

    // The radiographer (radiology.study) can acquire + transition.
    $study = $svc->acquire($fx['radiographer'], $fx['order']);
    expect($study->status)->toBe(ImagingStudy::STATUS_ACQUIRED);
    expect(fn () => $svc->transition($fx['reception'], $study, ImagingStudy::STATUS_REPORTED))->toThrow(AuthorizationException::class);
    expect($svc->transition($fx['radiographer'], $study, ImagingStudy::STATUS_REPORTED)->status)->toBe(ImagingStudy::STATUS_REPORTED);
});

test('cross-tenant is fail-closed: a tenant cannot acquire a study for another tenant imaging order', function () {
    $fxA = risFixture('alpha');

    $fxB = risFixture('beta');
    risCtx()->set($fxB['tenant']);

    expect(fn () => app(ImagingStudyService::class)->acquire($fxB['admin'], $fxA['order']))
        ->toThrow(CrossTenantReferenceException::class);
});
