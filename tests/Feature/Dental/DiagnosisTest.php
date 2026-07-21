<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Models\AuditEvent;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\Diagnosis;
use Modules\Dental\Models\DiagnosisTerm;
use Modules\Dental\Services\DiagnosisService;
use Modules\Dental\Services\PerioChartService;
use Modules\Dental\Services\ToothChartService;
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
 * DENTAL.G7 — the diagnosis record, the SHARPEST fence in the dental vertical. These tests prove:
 * the DENTIST records a diagnosis (they write it / pick from their own list, and set the status);
 * NOTHING is auto-suggested, proposed, ranked, or auto-populated (the strictest fence — asserted
 * over the payload AND by proving charting/perio produce zero diagnoses); append-only (a change is
 * a new record, history preserved — model AND raw-DB); the tenant list is a plain pick-list; RBAC;
 * tenant scoping.
 */

function dxCtx(): TenantContext
{
    return app(TenantContext::class);
}

function dxUser(Tenant $tenant, string $role): User
{
    dxCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, doctor: User, patient: Patient}
 */
function dxFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Dental', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    dxCtx()->set($tenant);
    $doctor = dxUser($tenant, 'doctor'); // holds dental.chart + patient.view (the general dentist)
    $patient = app(PatientService::class)->create(['first_name' => 'Dana', 'last_name' => 'Diag', 'date_of_birth' => '1988-08-08', 'sex' => 'female']);

    return compact('tenant', 'doctor', 'patient');
}

/**
 * Recursively assert NO suggestion/inference key leaked into the payload — the strictest fence.
 *
 * @param  array<mixed>  $data
 */
function dxAssertNoSuggestion(array $data): void
{
    $forbidden = ['suggested', 'suggestion', 'proposed', 'differential', 'likelihood', 'confidence', 'ranked', 'rank', 'ai', 'recommended', 'recommendation', 'probability', 'score', 'severity', 'grade', 'risk', 'auto', 'predicted', 'prediction', 'inferred'];
    foreach ($data as $key => $value) {
        expect(in_array((string) $key, $forbidden, true))->toBeFalse("suggestion key '{$key}' leaked into the diagnosis payload");
        if (is_array($value)) {
            dxAssertNoSuggestion($value);
        }
    }
}

test('the dentist records a diagnosis — stored, tenant+patient scoped, audited, read-logged, and the dentist sets the status', function () {
    $fx = dxFixture();
    $svc = app(DiagnosisService::class);

    // Free text, the dentist sets status = provisional.
    $dx = $svc->record($fx['doctor'], $fx['patient'], 'Irreversible pulpitis', Diagnosis::STATUS_PROVISIONAL, '16', null, 'Lingering response to cold; tender to percussion.');
    expect($dx->label)->toBe('Irreversible pulpitis')
        ->and($dx->status)->toBe('provisional')
        ->and($dx->tooth)->toBe('16')
        ->and($dx->diagnosis_term_id)->toBeNull()
        ->and(Diagnosis::query()->where('patient_id', $fx['patient']->id)->count())->toBe(1);

    // The DENTIST determines the status — a later record with a different status is theirs to set.
    $svc->record($fx['doctor'], $fx['patient'], 'Irreversible pulpitis', Diagnosis::STATUS_CONFIRMED, '16', null, null, null, 'Confirmed after testing.');
    expect(Diagnosis::query()->where('patient_id', $fx['patient']->id)->where('status', 'confirmed')->exists())->toBeTrue();

    // Recording is audited.
    expect(AuditEvent::query()->where('tenant_id', $fx['tenant']->id)->where('action', 'dental.diagnosis_recorded')->count())->toBe(2);

    // Reading writes a patient-scoped read-log row.
    dxCtx()->set($fx['tenant']);
    $svc->diagnosesFor($fx['doctor'], $fx['patient']);
    expect(AuditEvent::query()->where('tenant_id', $fx['tenant']->id)->where('action', 'read')->where('resource_type', 'diagnoses')->exists())->toBeTrue();
});

test('NOTHING is auto-suggested, proposed, ranked, or auto-populated — the payload carries no such field and charting/perio derive no diagnosis (the fence)', function () {
    $fx = dxFixture();

    // The dentist charts caries (G2) and probes deep perio pockets (G6) — plenty for a human to
    // diagnose from. The system must NOT turn any of it into a diagnosis.
    app(ToothChartService::class)->chart($fx['doctor'], $fx['patient'], '16', 'occlusal', 'caries');
    app(PerioChartService::class)->recordExam($fx['doctor'], $fx['patient'], '2026-07-20', [
        ['tooth' => '16', 'site' => 'mesio_buccal', 'pocket_depth_mm' => 9, 'bleeding_on_probing' => true],
    ]);

    // Zero diagnoses exist — nothing auto-populated one from the clinical data.
    dxCtx()->set($fx['tenant']);
    expect(Diagnosis::query()->count())->toBe(0);

    // The page renders (with a dentist-recorded diagnosis) and its payload carries NO suggestion field.
    app(DiagnosisService::class)->record($fx['doctor'], $fx['patient'], 'Deep caries, tooth 16', Diagnosis::STATUS_PROVISIONAL, '16');

    dxCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->get(route('dental.diagnoses', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dental/Diagnoses')
            ->has('diagnoses', 1)   // only the ONE the dentist entered
            ->has('terms')
            ->has('statuses', 3)
            ->where('actions.can_record', true)
            ->where('diagnoses', function ($diagnoses) {
                dxAssertNoSuggestion(collect($diagnoses)->toArray());

                return true;
            })
            ->where('terms', function ($terms) {
                dxAssertNoSuggestion(collect($terms)->toArray());

                return true;
            }));
});

test('a diagnosis is append-only — a change is a new record and the prior is preserved (model AND raw-DB)', function () {
    $fx = dxFixture();
    $svc = app(DiagnosisService::class);

    $first = $svc->record($fx['doctor'], $fx['patient'], 'Reversible pulpitis', Diagnosis::STATUS_PROVISIONAL, '16');
    // The dentist changes their mind — a NEW record + reason; the prior is kept.
    $svc->record($fx['doctor'], $fx['patient'], 'Irreversible pulpitis', Diagnosis::STATUS_CONFIRMED, '16', null, null, null, 'Symptoms progressed.');

    expect(Diagnosis::query()->where('patient_id', $fx['patient']->id)->count())->toBe(2)
        ->and(Diagnosis::query()->where('label', 'Reversible pulpitis')->exists())->toBeTrue();

    // Append-only at model level.
    expect(fn () => $first->update(['status' => 'confirmed']))->toThrow(DentalException::class);
    // ...and at raw-DB level, the trigger blocks UPDATE/DELETE.
    expect(fn () => DB::table('diagnoses')->where('id', $first->id)->update(['status' => 'confirmed']))->toThrow(QueryException::class);
    expect(fn () => DB::table('diagnoses')->where('id', $first->id)->delete())->toThrow(QueryException::class);
});

test('the tenant diagnosis list is a plain pick-list — the dentist authors it, and it is never ranked; free text also works', function () {
    $fx = dxFixture();
    $svc = app(DiagnosisService::class);

    // The dentist adds their own terms — a plain list (no rank/likelihood/score attribute exists).
    $term = $svc->addTerm($fx['doctor'], 'Chronic apical periodontitis');
    $svc->addTerm($fx['doctor'], 'Dentine hypersensitivity');
    expect(DiagnosisTerm::query()->count())->toBe(2);

    $terms = $svc->terms($fx['doctor']);
    expect($terms->pluck('label')->all())->toBe(['Chronic apical periodontitis', 'Dentine hypersensitivity']); // alphabetical, not ranked
    foreach ($terms as $t) {
        expect(array_keys($t->getAttributes()))->not->toContain('rank')->not->toContain('likelihood')->not->toContain('score');
    }

    // Picking a term is provenance only; free text (no term) is equally valid.
    $picked = $svc->record($fx['doctor'], $fx['patient'], $term->label, Diagnosis::STATUS_CONFIRMED, null, null, null, $term->id);
    $free = $svc->record($fx['doctor'], $fx['patient'], 'Something I typed myself', Diagnosis::STATUS_PROVISIONAL);
    expect($picked->diagnosis_term_id)->toBe($term->id)->and($free->diagnosis_term_id)->toBeNull();

    // A term id from another tenant is refused (fails closed).
    expect(fn () => $svc->record($fx['doctor'], $fx['patient'], 'x', Diagnosis::STATUS_PROVISIONAL, null, null, null, '01JUNKJUNKJUNKJUNKJUNKJUNK'))->toThrow(DentalException::class);
});

test('RBAC: dental.chart records, patient.view reads, and neither is bypassable', function () {
    $fx = dxFixture();

    // reception has patient.view (can VIEW) but not dental.chart (cannot RECORD).
    $reception = dxUser($fx['tenant'], 'reception');
    dxCtx()->forget();
    $this->actingAs($reception)->get(route('dental.diagnoses', $fx['patient']->id))->assertOk();
    dxCtx()->forget();
    $this->actingAs($reception)
        ->post(route('dental.diagnoses.store', $fx['patient']->id), ['label' => 'x', 'status' => 'provisional'])
        ->assertForbidden();
    expect(Diagnosis::query()->count())->toBe(0);

    // billing has neither patient.view nor dental.chart → cannot even view.
    $billing = dxUser($fx['tenant'], 'billing');
    dxCtx()->forget();
    $this->actingAs($billing)->get(route('dental.diagnoses', $fx['patient']->id))->assertForbidden();
});

test('diagnoses are tenant-scoped: a cross-tenant patient fails closed', function () {
    $alpha = dxFixture('alpha');
    $beta = dxFixture('beta');

    // The service fails closed across tenants.
    dxCtx()->set($beta['tenant']);
    expect(fn () => app(DiagnosisService::class)->record($beta['doctor'], $alpha['patient'], 'x', Diagnosis::STATUS_PROVISIONAL))
        ->toThrow(CrossTenantReferenceException::class);

    // The page 404s on a cross-tenant patient.
    dxCtx()->forget();
    $this->actingAs($beta['doctor'])->get(route('dental.diagnoses', $alpha['patient']->id))->assertNotFound();

    dxCtx()->set($alpha['tenant']);
    expect(Diagnosis::query()->where('patient_id', $alpha['patient']->id)->count())->toBe(0);
});
