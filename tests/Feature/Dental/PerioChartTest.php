<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Models\AuditEvent;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\PerioExam;
use Modules\Dental\Models\PerioMeasurement;
use Modules\Dental\Services\PerioChartService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * DENTAL.G6 — perio charting. These tests prove: a perio exam records RAW per-site measurements
 * (depth/recession/BOP, + optional mobility/furcation), tenant + patient scoped, audited, and
 * read-logged; append-only (a re-exam preserves the prior exam — at model AND raw-DB level); the
 * schema/output carries NO stage/grade/severity/risk/flag field (the fence proof); a per-site
 * history renders raw over time with no judgment; FDI + site validity; RBAC; tenant scoping.
 */

function pcCtx(): TenantContext
{
    return app(TenantContext::class);
}

function pcUser(Tenant $tenant, string $role): User
{
    pcCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, doctor: User, patient: Patient}
 */
function pcFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Dental', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    pcCtx()->set($tenant);
    $doctor = pcUser($tenant, 'doctor'); // holds dental.chart + patient.view (the general dentist)
    $patient = app(PatientService::class)->create(['first_name' => 'Perry', 'last_name' => 'Perio', 'date_of_birth' => '1975-06-06', 'sex' => 'male']);

    return compact('tenant', 'doctor', 'patient');
}

/**
 * One full 6-site measurement set for a tooth, with raw values.
 *
 * @return array<int, array<string, mixed>>
 */
function pcSixSites(string $tooth, int $baseDepth = 3): array
{
    return collect(PerioMeasurement::SITES)->map(fn (string $site, int $i): array => [
        'tooth' => $tooth,
        'site' => $site,
        'pocket_depth_mm' => $baseDepth + ($i % 2),
        'recession_mm' => 1,
        'bleeding_on_probing' => $i % 2 === 0,
        'mobility' => $site === 'mesio_buccal' ? 1 : null,
        'furcation' => null,
    ])->all();
}

/**
 * Recursively assert no interpretation/judgment key leaked into the payload (the perio fence).
 *
 * @param  array<mixed>  $data
 */
function pcAssertNoJudgment(array $data): void
{
    $forbidden = ['stage', 'staging', 'grade', 'severity', 'risk', 'flag', 'flagged', 'classification', 'class', 'abnormal', 'worsening', 'improving', 'trend', 'status', 'priority', 'recommendation', 'interpretation', 'diagnosis', 'alert', 'watch', 'normal', 'score', 'rating'];
    foreach ($data as $key => $value) {
        expect(in_array((string) $key, $forbidden, true))->toBeFalse("interpretation key '{$key}' leaked into the perio payload");
        if (is_array($value)) {
            pcAssertNoJudgment($value);
        }
    }
}

test('a perio exam records raw per-site measurements — tenant+patient scoped, audited, read-logged', function () {
    $fx = pcFixture();
    $svc = app(PerioChartService::class);

    $exam = $svc->recordExam($fx['doctor'], $fx['patient'], '2026-07-20', pcSixSites('16', 4), 'Full mouth probing');

    // The exam + its six site rows persisted, with the RAW values as given.
    expect($exam->measurements)->toHaveCount(6)
        ->and(PerioExam::query()->where('patient_id', $fx['patient']->id)->count())->toBe(1)
        ->and(PerioMeasurement::query()->where('patient_id', $fx['patient']->id)->count())->toBe(6);

    $mb = PerioMeasurement::query()->where('tooth', '16')->where('site', 'mesio_buccal')->firstOrFail();
    expect($mb->pocket_depth_mm)->toBe(4)->and($mb->recession_mm)->toBe(1)->and($mb->bleeding_on_probing)->toBeTrue()->and($mb->mobility)->toBe(1);

    // Recording is audited.
    expect(AuditEvent::query()->where('tenant_id', $fx['tenant']->id)->where('action', 'dental.perio_charted')->exists())->toBeTrue();

    // Reading writes a patient-scoped read-log row.
    pcCtx()->set($fx['tenant']);
    $svc->examsFor($fx['doctor'], $fx['patient']);
    expect(AuditEvent::query()->where('tenant_id', $fx['tenant']->id)->where('action', 'read')->where('resource_type', 'perio_exams')->exists())->toBeTrue();
});

test('a re-exam is a new exam and the prior exam is preserved — append-only at model AND raw-DB level', function () {
    $fx = pcFixture();
    $svc = app(PerioChartService::class);

    $first = $svc->recordExam($fx['doctor'], $fx['patient'], '2026-01-10', pcSixSites('16', 3));
    $svc->recordExam($fx['doctor'], $fx['patient'], '2026-07-10', pcSixSites('16', 5));

    // Two separate exams; the first is untouched.
    expect(PerioExam::query()->where('patient_id', $fx['patient']->id)->count())->toBe(2);

    $firstMb = PerioMeasurement::query()->where('perio_exam_id', $first->id)->where('site', 'mesio_buccal')->firstOrFail();
    expect($firstMb->pocket_depth_mm)->toBe(3);

    // Append-only at model level: an edit/delete of an exam or a measurement throws.
    expect(fn () => $first->update(['note' => 'x']))->toThrow(DentalException::class);
    expect(fn () => $firstMb->update(['pocket_depth_mm' => 9]))->toThrow(DentalException::class);

    // ...and at raw-DB level, the trigger blocks UPDATE/DELETE (defence in depth).
    expect(fn () => DB::table('perio_measurements')->where('id', $firstMb->id)->update(['pocket_depth_mm' => 9]))->toThrow(QueryException::class);
    expect(fn () => DB::table('perio_measurements')->where('id', $firstMb->id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('perio_exams')->where('id', $first->id)->update(['note' => 'x']))->toThrow(QueryException::class);
    expect(fn () => DB::table('perio_exams')->where('id', $first->id)->delete())->toThrow(QueryException::class);
});

test('the perio page renders raw exams and the payload carries no stage/grade/severity/flag field (the fence)', function () {
    $fx = pcFixture();
    app(PerioChartService::class)->recordExam($fx['doctor'], $fx['patient'], '2026-07-20', pcSixSites('16', 4));

    pcCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->get(route('dental.perio', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dental/PerioChart')
            ->where('patient.id', $fx['patient']->id)
            ->has('exams', 1)
            ->has('sites', 6)
            ->has('teeth.permanent', 32)
            ->where('actions.can_chart', true)
            // The rendered exams are raw measurements only — no judgment key anywhere.
            ->where('exams', function ($exams) {
                pcAssertNoJudgment(collect($exams)->toArray());

                return true;
            }));
});

test('a per-site history is the raw numbers over time with no judgment field', function () {
    $fx = pcFixture();
    $svc = app(PerioChartService::class);
    $svc->recordExam($fx['doctor'], $fx['patient'], '2026-01-10', pcSixSites('16', 3));
    $svc->recordExam($fx['doctor'], $fx['patient'], '2026-07-10', pcSixSites('16', 6));

    pcCtx()->set($fx['tenant']);
    $history = $svc->siteHistory($fx['doctor'], $fx['patient'], '16', 'mesio_buccal');

    // Oldest first, RAW depths in sequence — the dentist reads the trend, the system does not label it.
    expect($history)->toHaveCount(2)
        ->and($history->pluck('pocket_depth_mm')->all())->toBe([3, 6]);
    pcAssertNoJudgment($history->map(fn (PerioMeasurement $m): array => $m->only(['tooth', 'site', 'pocket_depth_mm', 'recession_mm', 'bleeding_on_probing', 'mobility', 'furcation']))->all());
});

test('records reject an invalid FDI tooth, an invalid site, and an out-of-range value (deterministic, no interpretation)', function () {
    $fx = pcFixture();
    $svc = app(PerioChartService::class);

    // Invalid FDI id.
    expect(fn () => $svc->recordExam($fx['doctor'], $fx['patient'], '2026-07-20', [['tooth' => '99', 'site' => 'buccal', 'pocket_depth_mm' => 3]]))->toThrow(DentalException::class);
    // Invalid probing site.
    expect(fn () => $svc->recordExam($fx['doctor'], $fx['patient'], '2026-07-20', [['tooth' => '16', 'site' => 'sideways', 'pocket_depth_mm' => 3]]))->toThrow(DentalException::class);
    // Physically impossible depth (data-entry guard, not a grade).
    expect(fn () => $svc->recordExam($fx['doctor'], $fx['patient'], '2026-07-20', [['tooth' => '16', 'site' => 'buccal', 'pocket_depth_mm' => 200]]))->toThrow(DentalException::class);

    // None of the failed exams left a partial row (the transaction rolled back).
    expect(PerioExam::query()->count())->toBe(0)->and(PerioMeasurement::query()->count())->toBe(0);
});

test('RBAC: dental.chart records, patient.view reads, and neither is bypassable', function () {
    $fx = pcFixture();

    // reception has patient.view (can VIEW) but not dental.chart (cannot RECORD).
    $reception = pcUser($fx['tenant'], 'reception');
    pcCtx()->forget();
    $this->actingAs($reception)->get(route('dental.perio', $fx['patient']->id))->assertOk();
    pcCtx()->forget();
    $this->actingAs($reception)
        ->post(route('dental.perio.store', $fx['patient']->id), ['exam_date' => '2026-07-20', 'measurements' => pcSixSites('16')])
        ->assertForbidden();
    expect(PerioExam::query()->count())->toBe(0);

    // billing has neither patient.view nor dental.chart → cannot even view.
    $billing = pcUser($fx['tenant'], 'billing');
    pcCtx()->forget();
    $this->actingAs($billing)->get(route('dental.perio', $fx['patient']->id))->assertForbidden();
});

test('perio charting is tenant-scoped: a cross-tenant patient fails closed', function () {
    $alpha = pcFixture('alpha');
    $beta = pcFixture('beta');

    // The service fails closed across tenants.
    pcCtx()->set($beta['tenant']);
    expect(fn () => app(PerioChartService::class)->recordExam($beta['doctor'], $alpha['patient'], '2026-07-20', pcSixSites('16')))
        ->toThrow(CrossTenantReferenceException::class);

    // The page 404s on a cross-tenant patient.
    pcCtx()->forget();
    $this->actingAs($beta['doctor'])->get(route('dental.perio', $alpha['patient']->id))->assertNotFound();

    pcCtx()->set($alpha['tenant']);
    expect(PerioExam::query()->where('patient_id', $alpha['patient']->id)->count())->toBe(0);
});
