<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Models\AuditEvent;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\ChargeValidator;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\DentalProcedure;
use Modules\Dental\Models\PerformedProcedure;
use Modules\Dental\Models\ToothRecord;
use Modules\Dental\Services\DentalCatalogService;
use Modules\Dental\Services\PerformProcedureService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * DENTAL.G4 — the perform-a-procedure workflow: one atomic action recording the clinical
 * fact + capturing the charge (through the existing G3 billing path) + charting any
 * tooth-state change (through G1's append-only charting). These tests prove: all three are
 * created consistently and the charge reconciles-to-the-unit; a forced failure rolls back
 * ALL THREE (no orphan charge, clinical record, or tooth-state); the clinical record is
 * append-only; RBAC needs BOTH dental.chart + billing.manage; tenant scoping; and the fence
 * (the rendered payload carries no judgment field).
 */

function ppCtx(): TenantContext
{
    return app(TenantContext::class);
}

function ppUser(Tenant $tenant, string $role): User
{
    ppCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, admin: User, branch: Branch, patient: Patient, procedure: DentalProcedure}
 */
function ppFixture(string $slug = 'alpha'): array
{
    Storage::fake('local');
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Dental', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    ppCtx()->set($tenant);

    $admin = ppUser($tenant, 'org_admin'); // holds BOTH dental.chart and billing.manage
    $branch = Branch::query()->create(['name' => 'Praxis', 'code' => strtoupper(substr($slug, 0, 3)), 'timezone' => 'Europe/Zurich']);
    $patient = app(PatientService::class)->create(['first_name' => 'Deni', 'last_name' => 'Dental', 'date_of_birth' => '1980-01-01', 'sex' => 'female']);
    $procedure = app(DentalCatalogService::class)->create($admin, 'D-FILL', 'Filling', 15000, 0, true); // tooth-scoped

    return compact('tenant', 'admin', 'branch', 'patient', 'procedure');
}

/**
 * @param  array<mixed>  $data
 */
function ppAssertNoJudgment(array $data): void
{
    $forbidden = ['severity', 'score', 'grade', 'risk', 'flag', 'abnormal', 'recommendation', 'priority', 'rating', 'interpretation', 'diagnosis', 'verdict', 'alert'];
    foreach ($data as $key => $value) {
        expect(in_array((string) $key, $forbidden, true))->toBeFalse("interpretation key '{$key}' leaked into the performed payload");
        if (is_array($value)) {
            ppAssertNoJudgment($value);
        }
    }
}

test('performing a procedure creates the clinical record + charge + tooth-state change, and reconciles-to-the-unit', function () {
    $fx = ppFixture();

    $performed = app(PerformProcedureService::class)->perform($fx['admin'], $fx['patient'], $fx['branch'], $fx['procedure'], '16', 'occlusal', 'MOD filling', 'restoration');

    // 1. The clinical record, tied to a charge.
    expect($performed->tooth)->toBe('16')
        ->and($performed->status)->toBe(PerformedProcedure::STATUS_COMPLETED)
        ->and($performed->charge_id)->not->toBeNull();

    // 2. The charge went through the existing engine (snapshotted fee).
    $charge = Charge::query()->whereKey($performed->charge_id)->firstOrFail();
    expect($charge->code)->toBe('D-FILL')->and($charge->unit_price_minor)->toBe(15000);

    // 3. The tooth-state change was charted through the append-only G1 path.
    $toothRecord = ToothRecord::query()->where('patient_id', $fx['patient']->id)->where('tooth', '16')->where('charted_condition', 'restoration')->first();
    expect($toothRecord)->not->toBeNull()->and($toothRecord->surface)->toBe('occlusal');

    expect(AuditEvent::query()->where('tenant_id', $fx['tenant']->id)->where('action', 'dental.procedure.performed')->exists())->toBeTrue();

    // The charge reconciles-to-the-unit through the existing billing engine.
    app(ChargeValidator::class)->validateCharges(collect([$charge]), $fx['admin']);
    $issue = app(IssueService::class);
    $invoice = $issue->issue($issue->createDraftFromCharges($fx['patient'], [$charge->refresh()], $fx['admin']), $fx['admin']);
    expect($invoice->status)->toBe(Invoice::STATUS_ISSUED);

    $report = app(ReconciliationEngine::class)->check($charge->service_date->format('Y-m'));
    expect($report['passed'])->toBeTrue();
    foreach ($report['invariants'] as $invariant) {
        expect($invariant['delta_minor'])->toBe(0);
    }
});

test('atomicity: a failure in the tooth-state step rolls back the charge AND the clinical record (no orphan)', function () {
    $fx = ppFixture();

    // An invalid resulting tooth-state makes the LAST step (G1 charting) throw — AFTER the
    // charge + clinical record were written inside the transaction. All must roll back.
    expect(fn () => app(PerformProcedureService::class)->perform($fx['admin'], $fx['patient'], $fx['branch'], $fx['procedure'], '16', 'occlusal', null, 'not-a-real-condition'))
        ->toThrow(DentalException::class);

    expect(Charge::query()->count())->toBe(0)
        ->and(PerformedProcedure::query()->count())->toBe(0)
        ->and(ToothRecord::query()->where('patient_id', $fx['patient']->id)->count())->toBe(0);
});

test('RBAC: performing needs BOTH dental.chart (clinical) and billing.manage (charge) — and a partial failure leaves nothing', function () {
    $fx = ppFixture();

    // A doctor has dental.chart but NOT billing.manage → the charge step throws and rolls back all.
    $doctor = ppUser($fx['tenant'], 'doctor');
    ppCtx()->set($fx['tenant']);
    expect(fn () => app(PerformProcedureService::class)->perform($doctor, $fx['patient'], $fx['branch'], $fx['procedure'], '16', null, null, 'restoration'))
        ->toThrow(AuthorizationException::class);
    expect(Charge::query()->count())->toBe(0)
        ->and(PerformedProcedure::query()->count())->toBe(0)
        ->and(ToothRecord::query()->where('patient_id', $fx['patient']->id)->count())->toBe(0);

    // Reception has no dental.chart → denied up front.
    $reception = ppUser($fx['tenant'], 'reception');
    ppCtx()->set($fx['tenant']);
    expect(fn () => app(PerformProcedureService::class)->perform($reception, $fx['patient'], $fx['branch'], $fx['procedure'], '16', null, null, null))
        ->toThrow(AuthorizationException::class);
});

test('a performed procedure is append-only (a correction is a new record; history preserved)', function () {
    $fx = ppFixture();
    $first = app(PerformProcedureService::class)->perform($fx['admin'], $fx['patient'], $fx['branch'], $fx['procedure'], '16', 'occlusal', null, 'restoration');

    // Immutable at model + DB-trigger level.
    expect(fn () => $first->update(['note' => 'edited']))->toThrow(DentalException::class);
    expect(fn () => DB::table('performed_procedures')->where('id', $first->id)->delete())->toThrow(QueryException::class);

    // A correction is a NEW record — the prior one is preserved.
    app(PerformProcedureService::class)->perform($fx['admin'], $fx['patient'], $fx['branch'], $fx['procedure'], '16', 'occlusal', 'redone', 'restoration');
    expect(PerformedProcedure::query()->where('patient_id', $fx['patient']->id)->count())->toBe(2);
});

test('the odontogram surfaces perform (can_perform + catalog + history), tenant-scoped, no judgment field', function () {
    $fx = ppFixture();
    app(PerformProcedureService::class)->perform($fx['admin'], $fx['patient'], $fx['branch'], $fx['procedure'], '16', 'occlusal', null, 'restoration');

    // The dentist-owner (org_admin: dental.chart + billing.manage) can perform; the payload is facts only.
    ppCtx()->forget();
    $this->actingAs($fx['admin'])
        ->get(route('dental.chart', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dental/Odontogram')
            ->where('actions.can_perform', true)
            ->has('procedures', 1)
            ->has('performed', 1)
            ->where('performed', function ($performed) {
                ppAssertNoJudgment(collect($performed)->toArray());

                return true;
            }));

    // A doctor (dental.chart but no billing.manage) cannot perform — no catalog surfaced.
    ppCtx()->forget();
    $this->actingAs(ppUser($fx['tenant'], 'doctor'))
        ->get(route('dental.chart', $fx['patient']->id))
        ->assertInertia(fn (Assert $page) => $page->where('actions.can_perform', false)->has('procedures', 0));

    // Cross-tenant: a second tenant's dentist cannot perform on this patient (404).
    $beta = ppFixture('beta');
    ppCtx()->forget();
    $this->actingAs($beta['admin'])
        ->post(route('dental.chart.perform', $fx['patient']->id), ['dental_procedure_id' => $beta['procedure']->id, 'branch_id' => $beta['branch']->id, 'tooth' => '16'])
        ->assertNotFound();
});
