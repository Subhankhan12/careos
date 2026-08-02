<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Models\AuditEvent;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderResult;
use Modules\Clinical\Services\OrderService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Radiology\Models\ImagingStudy;
use Modules\Radiology\Models\ImagingStudyReport;
use Modules\Radiology\Models\RadiologyOrder;
use Modules\Radiology\Services\ImagingStudyService;
use Modules\Radiology\Services\RadiologyCatalogService;
use Modules\Radiology\Services\RadiologyOrderService;
use Modules\Radiology\Services\RadiologyReportService;

uses(RefreshDatabase::class);

/*
 * RAD.G4 — THE FENCE GATE. A radiologist AUTHORS a report for a study (reuse the sign-and-lock ClinicalNote);
 * signing files it (study → reported, Order → resulted) and the EXISTING order → review flow routes it to the
 * ordering clinician. Per docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md §2.5. THE FENCE: the report is authored prose —
 * the system computes NO image finding/CAD/abnormality/confidence/auto-read/diagnosis (a hard non-goal).
 */

function rrCtx(): TenantContext
{
    return app(TenantContext::class);
}

function rrUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, admin: User, radiologist: User, radiologistStaff: StaffProfile, doctor: User, reception: User, patient: Patient, order: RadiologyOrder, study: ImagingStudy} */
function rrFixture(string $slug = 'rr'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Imaging', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    rrCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $admin = rrUser($tenant, 'org_admin');       // author exam + place order + acquire study
    $radiologist = rrUser($tenant, 'radiologist'); // radiology.study + note.write/sign + order.manage + encounter.manage
    $doctor = rrUser($tenant, 'doctor');          // order.manage — the review flow
    $reception = rrUser($tenant, 'reception');    // no note.write / note.sign
    $radiologistStaff = StaffProfile::query()->create([
        'first_name' => 'Rhea', 'last_name' => 'Ray', 'display_name' => 'Dr Rhea Ray',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE, 'user_id' => $radiologist->id,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);
    $exam = app(RadiologyCatalogService::class)->authorExam($admin, 'RAD-CXR', 'Chest X-ray', 'X-ray', 'Chest', false);
    $order = app(RadiologyOrderService::class)->place($admin, $patient, $exam, RadiologyOrder::PRIORITY_ROUTINE)['radiologyOrder'];
    $study = app(ImagingStudyService::class)->acquire($admin, $order); // acquired (the radiographer's G3 step)

    return compact('tenant', 'admin', 'radiologist', 'radiologistStaff', 'doctor', 'reception', 'patient', 'order', 'study');
}

test('a radiologist AUTHORS a report — reuses the sign-and-lock ClinicalNote, tied to the study; Clinical UNMODIFIED', function () {
    $fx = rrFixture();

    $note = app(RadiologyReportService::class)->saveDraft($fx['radiologist'], $fx['study'], $fx['radiologistStaff'], 'Clear lung fields.', 'No acute abnormality.');

    // The report IS a reused sign-and-lock ClinicalNote (draft) — findings=objective, impression=assessment (authored prose).
    expect($note)->toBeInstanceOf(ClinicalNote::class)
        ->and($note->status)->toBe(ClinicalNote::STATUS_DRAFT)
        ->and($note->objective)->toBe('Clear lung fields.')
        ->and($note->assessment)->toBe('No acute abnormality.')
        ->and($note->patient_id)->toBe($fx['patient']->id)
        // Tied to the study by a radiology-side link (Clinical stays untouched); audited.
        ->and(ImagingStudyReport::query()->where('imaging_study_id', $fx['study']->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'radiology.report_started')->where('patient_id', $fx['patient']->id)->count())->toBe(1);

    // CLINICAL UNMODIFIED: the report links radiology-side — clinical_notes/encounters carry no radiology column.
    foreach (['imaging_study_id', 'radiology_order_id', 'accession_number'] as $col) {
        expect(Schema::getColumnListing('clinical_notes'))->not->toContain($col);
        expect(Schema::getColumnListing('encounters'))->not->toContain($col);
    }
});

test('signing the report files it: note signed (locked) + study → reported + Order → resulted (the report IS the result)', function () {
    $fx = rrFixture();
    $svc = app(RadiologyReportService::class);
    $svc->saveDraft($fx['radiologist'], $fx['study'], $fx['radiologistStaff'], 'Clear lung fields.', 'No acute abnormality.');

    $signed = $svc->sign($fx['radiologist'], $fx['study']);

    expect($signed->status)->toBe(ClinicalNote::STATUS_SIGNED)
        ->and($signed->signed_at)->not->toBeNull()
        // The study advanced to reported (the G3 legal transition).
        ->and($fx['study']->fresh()->status)->toBe(ImagingStudy::STATUS_REPORTED);

    // The reused Clinical Order advanced to resulted — the report IS the result (an OrderResult appended).
    $order = Order::query()->findOrFail($fx['order']->order_id);
    $result = OrderResult::query()->where('order_id', $order->id)->firstOrFail();
    expect($order->status)->toBe(Order::STATUS_RESULTED)
        ->and($result->source)->toBe(OrderResult::SOURCE_MANUAL)
        ->and($result->result_value)->toBe('No acute abnormality.'); // the radiologist's impression is the result
});

test('THE FENCE: the report is AUTHORED prose — nothing auto-populates; NO computed image finding/CAD/abnormality/confidence field or logic', function () {
    $fx = rrFixture();

    // Nothing auto-populates: an empty draft stays empty (the system fills no findings/impression).
    $note = app(RadiologyReportService::class)->saveDraft($fx['radiologist'], $fx['study'], $fx['radiologistStaff'], null, null);
    expect($note->objective)->toBeNull()->and($note->assessment)->toBeNull();

    // No computed-image-read column on the radiology-side report link.
    $forbidden = ['finding', 'cad', 'abnormal', 'confidence', 'ai', 'interpretation', 'diagnosis', 'severity'];
    $columns = Schema::getColumnListing('imaging_study_reports');
    foreach ($forbidden as $word) {
        expect($columns)->not->toContain($word, "imaging_study_reports must not carry a computed-image-read column: {$word}");
    }

    // The module reads/interprets no image — no CAD/finding/auto-read/suggested-diagnosis/confidence logic.
    $files = collect(File::allFiles(base_path('Modules/Radiology/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['computeFinding', 'detectAbnormality', 'cadRead', 'autoRead', 'interpretImage', 'suggestDiagnosis', 'confidenceScore', 'aiRead'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Radiology must not compute an image read ({$needle})");
        }
    }
});

test('report routing REUSES the order → review flow: the resulted study appears in the ordering clinician review worklist; reviewing → reviewed', function () {
    $fx = rrFixture();
    $svc = app(RadiologyReportService::class);
    $svc->saveDraft($fx['radiologist'], $fx['study'], $fx['radiologistStaff'], 'Findings.', 'Impression.');
    $svc->sign($fx['radiologist'], $fx['study']);

    $order = Order::query()->findOrFail($fx['order']->order_id);

    // The resulted imaging order appears in the EXISTING clinical "orders to review" worklist (reused, not reinvented).
    $toReview = app(OrderService::class)->toReview($fx['doctor']);
    expect($toReview->pluck('id')->all())->toContain($order->id);

    // Reviewing advances the Order → reviewed (the existing markReviewed) — it leaves the worklist.
    app(OrderService::class)->markReviewed($order, $fx['doctor']);
    expect($order->fresh()->status)->toBe(Order::STATUS_REVIEWED)
        ->and(app(OrderService::class)->toReview($fx['doctor'])->pluck('id')->all())->not->toContain($order->id);
});

test('the report is APPEND-ONLY / sign-and-lock: a signed report is immutable; an amendment is a NEW version', function () {
    $fx = rrFixture();
    $svc = app(RadiologyReportService::class);
    $svc->saveDraft($fx['radiologist'], $fx['study'], $fx['radiologistStaff'], 'Findings v1.', 'Impression v1.');
    $signed = $svc->sign($fx['radiologist'], $fx['study']);

    // The signed note is immutable (the reused sign-and-lock discipline).
    expect(fn () => $signed->update(['assessment' => 'tampered']))->toThrow(LogicException::class);

    // An amendment is a NEW version (v2) superseding v1 — the original stays immutable.
    $amended = $svc->amend($fx['radiologist'], $fx['study'], $fx['radiologistStaff'], 'Findings v2.', 'Impression v2.', 'Addendum on review');
    expect($amended->version)->toBe(2)
        ->and($amended->supersedes_id)->toBe($signed->id)
        ->and($amended->status)->toBe(ClinicalNote::STATUS_DRAFT)
        ->and($svc->versionsFor($fx['study']->fresh()))->toHaveCount(2);
});

test('RBAC: authoring needs note.write, signing needs note.sign; reception is refused', function () {
    $fx = rrFixture();
    $svc = app(RadiologyReportService::class);

    // reception holds neither note.write nor note.sign.
    expect(fn () => $svc->saveDraft($fx['reception'], $fx['study'], $fx['radiologistStaff'], 'x', 'y'))->toThrow(AuthorizationException::class);

    // The radiologist authors, then signs.
    $svc->saveDraft($fx['radiologist'], $fx['study'], $fx['radiologistStaff'], 'Findings.', 'Impression.');
    expect(fn () => $svc->sign($fx['reception'], $fx['study']))->toThrow(AuthorizationException::class);
    expect($svc->sign($fx['radiologist'], $fx['study'])->status)->toBe(ClinicalNote::STATUS_SIGNED);
});

test('cross-tenant is fail-closed: a tenant cannot author a report for another tenant study', function () {
    $fxA = rrFixture('alpha');

    $fxB = rrFixture('beta');
    rrCtx()->set($fxB['tenant']);

    expect(fn () => app(RadiologyReportService::class)->saveDraft($fxB['radiologist'], $fxA['study'], $fxB['radiologistStaff'], 'x', 'y'))
        ->toThrow(CrossTenantReferenceException::class);
});
