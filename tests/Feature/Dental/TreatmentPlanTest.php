<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Models\AuditEvent;
use Modules\Billing\Models\Charge;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\DentalProcedure;
use Modules\Dental\Models\PerformedProcedure;
use Modules\Dental\Services\DentalCatalogService;
use Modules\Dental\Services\PerformProcedureService;
use Modules\Dental\Services\TreatmentPlanService;
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
 * DENTAL.G5 — the phased, fee-scheduled treatment plan. These tests prove: a dentist-authored
 * plan with phases + items whose estimate is the G3 fee, SNAPSHOTTED at proposal (a later fee
 * edit doesn't change an accepted plan); the plan ESTIMATES but posts NO charge (no
 * double-charge — G4 performing charges); performing a linked procedure marks the item done;
 * legal-only lifecycle; RBAC; tenant scoping; and the fence (dentist-authored, no
 * auto-suggested/severity/AI field). No new billing math in dental (grep, separately).
 */

function tpCtx(): TenantContext
{
    return app(TenantContext::class);
}

function tpUser(Tenant $tenant, string $role): User
{
    tpCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, admin: User, branch: Branch, patient: Patient, procedure: DentalProcedure}
 */
function tpFixture(string $slug = 'alpha'): array
{
    Storage::fake('local');
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Dental', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    tpCtx()->set($tenant);

    $admin = tpUser($tenant, 'org_admin'); // dental.chart + billing.manage
    $branch = Branch::query()->create(['name' => 'Praxis', 'code' => strtoupper(substr($slug, 0, 3)), 'timezone' => 'Europe/Zurich']);
    $patient = app(PatientService::class)->create(['first_name' => 'Deni', 'last_name' => 'Dental', 'date_of_birth' => '1980-01-01', 'sex' => 'female']);
    $procedure = app(DentalCatalogService::class)->create($admin, 'D-FILL', 'Filling', 15000, 0, true);

    return compact('tenant', 'admin', 'branch', 'patient', 'procedure');
}

/**
 * @param  array<mixed>  $data
 */
function tpAssertNoJudgment(array $data): void
{
    $forbidden = ['severity', 'score', 'grade', 'risk', 'flag', 'abnormal', 'recommendation', 'priority', 'rating', 'interpretation', 'diagnosis', 'verdict', 'suggested', 'ai'];
    foreach ($data as $key => $value) {
        expect(in_array((string) $key, $forbidden, true))->toBeFalse("interpretation key '{$key}' leaked into the treatment-plan payload");
        if (is_array($value)) {
            tpAssertNoJudgment($value);
        }
    }
}

test('a plan estimates from the G3 fee schedule, snapshotted at proposal (a later fee edit does not change it)', function () {
    $fx = tpFixture();
    $svc = app(TreatmentPlanService::class);

    $plan = $svc->create($fx['admin'], $fx['patient'], 'Restore quadrant');
    $phase = $svc->addPhase($fx['admin'], $plan, 'Phase 1');
    $item = $svc->addItem($fx['admin'], $plan, $phase, $fx['procedure'], '16', 'occlusal');

    // Draft: the estimate is the LIVE fee (not yet snapshotted).
    expect($item->estimated_fee_minor)->toBeNull()->and($svc->itemEstimate($item))->toBe(15000);

    // Propose → snapshot; accept.
    $svc->propose($fx['admin'], $plan);
    expect($item->refresh()->estimated_fee_minor)->toBe(15000)->and($plan->refresh()->status)->toBe('proposed');
    $svc->accept($fx['admin'], $plan);
    expect($plan->refresh()->status)->toBe('accepted')->and($plan->accepted_at)->not->toBeNull();

    // Editing the fee schedule UP does NOT change the accepted plan's agreed estimate (snapshot).
    app(DentalCatalogService::class)->update($fx['admin'], $fx['procedure'], 'Filling', 22000, 0, true, true);
    expect($svc->itemEstimate($item->refresh()))->toBe(15000);

    expect(AuditEvent::query()->where('tenant_id', $fx['tenant']->id)->where('action', 'treatment_plan.created')->exists())->toBeTrue();
});

test('the plan ESTIMATES but posts no charge (no double-charge) — G4 performing creates the charge and marks the item done', function () {
    $fx = tpFixture();
    $svc = app(TreatmentPlanService::class);

    $plan = $svc->create($fx['admin'], $fx['patient'], null);
    $phase = $svc->addPhase($fx['admin'], $plan, 'Phase 1');
    $item = $svc->addItem($fx['admin'], $plan, $phase, $fx['procedure'], '16', 'occlusal');
    $svc->propose($fx['admin'], $plan);
    $svc->accept($fx['admin'], $plan);
    $svc->start($fx['admin'], $plan);

    // Accepting/starting the plan created NO charge — the plan estimates, it does not bill.
    expect(Charge::query()->count())->toBe(0);

    // Performing the planned item (G4) — with the plan-item link — creates the charge and completes the item.
    $performed = app(PerformProcedureService::class)->perform($fx['admin'], $fx['patient'], $fx['branch'], $fx['procedure'], '16', 'occlusal', null, null, 1, $item);
    expect($performed->treatment_plan_item_id)->toBe($item->id)
        ->and(Charge::query()->count())->toBe(1)
        ->and(PerformedProcedure::query()->where('treatment_plan_item_id', $item->id)->exists())->toBeTrue();
});

test('the treatment-plan lifecycle is legal-only', function () {
    $fx = tpFixture();
    $svc = app(TreatmentPlanService::class);
    $plan = $svc->create($fx['admin'], $fx['patient'], null);

    // Illegal: draft -> accept (must be proposed first).
    expect(fn () => $svc->accept($fx['admin'], $plan))->toThrow(DentalException::class);

    // Legal: draft -> proposed -> accepted -> in_progress -> completed.
    $svc->propose($fx['admin'], $plan);
    $svc->accept($fx['admin'], $plan);
    $svc->start($fx['admin'], $plan);
    $svc->complete($fx['admin'], $plan);
    expect($plan->refresh()->status)->toBe('completed');

    // Illegal: completed is terminal.
    expect(fn () => $svc->start($fx['admin'], $plan))->toThrow(DentalException::class);

    // Decline path: proposed -> declined (terminal).
    $plan2 = $svc->create($fx['admin'], $fx['patient'], null);
    $svc->propose($fx['admin'], $plan2);
    $svc->decline($fx['admin'], $plan2);
    expect($plan2->refresh()->status)->toBe('declined')
        ->and(fn () => $svc->accept($fx['admin'], $plan2))->toThrow(DentalException::class);
});

test('RBAC: managing needs dental.chart, reading needs patient.view; the payload carries no judgment field', function () {
    $fx = tpFixture();
    $svc = app(TreatmentPlanService::class);
    $plan = $svc->create($fx['admin'], $fx['patient'], 'Plan');
    $phase = $svc->addPhase($fx['admin'], $plan, 'Phase 1');
    $svc->addItem($fx['admin'], $plan, $phase, $fx['procedure'], '16', 'occlusal');
    $svc->propose($fx['admin'], $plan);

    // reception has no dental.chart → cannot author a plan.
    $reception = tpUser($fx['tenant'], 'reception');
    tpCtx()->set($fx['tenant']);
    expect(fn () => $svc->create($reception, $fx['patient'], 'x'))->toThrow(AuthorizationException::class);

    // The dentist reads the plan (patient.view); the payload is facts only.
    tpCtx()->forget();
    $this->actingAs($fx['admin'])
        ->get(route('dental.plans', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dental/TreatmentPlans')
            ->has('plans', 1)
            ->where('plans', function ($plans) {
                tpAssertNoJudgment(collect($plans)->toArray());

                return true;
            }));

    // billing (no patient.view) cannot even read the page.
    tpCtx()->forget();
    $this->actingAs(tpUser($fx['tenant'], 'billing'))->get(route('dental.plans', $fx['patient']->id))->assertForbidden();
});

test('treatment plans are tenant-scoped: a cross-tenant patient fails closed as 404', function () {
    $alpha = tpFixture('alpha');
    app(TreatmentPlanService::class)->create($alpha['admin'], $alpha['patient'], 'A');

    $beta = tpFixture('beta');
    tpCtx()->forget();
    $this->actingAs($beta['admin'])->get(route('dental.plans', $alpha['patient']->id))->assertNotFound();
});
