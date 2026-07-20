<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\ChargeValidator;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\Dental\Models\DentalProcedure;
use Modules\Dental\Models\DentalProcedureCharge;
use Modules\Dental\Services\DentalCatalogService;
use Modules\Dental\Services\DentalChargeService;
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
 * DENTAL.G3 — billing integration. A dental procedure IS a tariff item; charging it runs
 * through the EXISTING ChargeCaptureService → the charge snapshots the fee and flows into
 * the existing invoice → reconciliation pipeline UNCHANGED. These tests prove: a dental
 * charge is a real Charge with the snapshotted fee; it reconciles-to-the-unit; a later fee
 * edit never changes a past charge; the tooth link ties the charge to a tooth; and charging
 * requires billing.manage. NO new billing logic is introduced in dental code.
 */

function dblCtx(): TenantContext
{
    return app(TenantContext::class);
}

function dblUser(Tenant $tenant, string $role): User
{
    dblCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, admin: User, branch: Branch, patient: Patient, procedure: DentalProcedure}
 */
function dblFixture(string $slug = 'alpha'): array
{
    Storage::fake('local'); // invoice PDFs land on the fake disk
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Dental', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    dblCtx()->set($tenant);

    $admin = dblUser($tenant, 'org_admin'); // holds billing.manage
    $branch = Branch::query()->create(['name' => 'Praxis', 'code' => strtoupper(substr($slug, 0, 3)), 'timezone' => 'Europe/Zurich']);
    $patient = app(PatientService::class)->create(['first_name' => 'Deni', 'last_name' => 'Dental', 'date_of_birth' => '1980-01-01', 'sex' => 'female']);
    $procedure = app(DentalCatalogService::class)->create($admin, 'D-FILL', 'Filling', 15000, 0, true);

    return compact('tenant', 'admin', 'branch', 'patient', 'procedure');
}

test('charging a dental procedure creates a real Charge through the existing engine, with a tooth link', function () {
    $fx = dblFixture();

    $charge = app(DentalChargeService::class)->capture($fx['admin'], $fx['patient'], $fx['branch'], $fx['procedure'], '16', 'occlusal');

    // The engine resolved + snapshotted the fee and computed the line total — dental added none of this.
    expect($charge)->toBeInstanceOf(Charge::class)
        ->and($charge->code)->toBe('D-FILL')
        ->and($charge->unit_price_minor)->toBe(15000)  // snapshotted from the tariff item
        ->and($charge->line_total_minor)->toBe(15000)  // quantity(1) × fee, computed by the engine
        ->and($charge->status)->toBe(Charge::STATUS_DRAFT);

    // The light tooth link ties the charge to the odontogram tooth.
    $link = DentalProcedureCharge::query()->where('charge_id', $charge->id)->first();
    expect($link)->not->toBeNull()
        ->and($link->tooth)->toBe('16')
        ->and($link->surface)->toBe('occlusal');
});

test('a dental charge reconciles-to-the-unit through the existing ReconciliationEngine', function () {
    $fx = dblFixture();
    $charge = app(DentalChargeService::class)->capture($fx['admin'], $fx['patient'], $fx['branch'], $fx['procedure'], '16');

    // Validate + issue through the existing billing services (no dental billing logic).
    app(ChargeValidator::class)->validateCharges(collect([$charge]), $fx['admin']);
    expect($charge->refresh()->status)->toBe(Charge::STATUS_VALIDATED);

    $issue = app(IssueService::class);
    $invoice = $issue->issue($issue->createDraftFromCharges($fx['patient'], [$charge->refresh()], $fx['admin']), $fx['admin']);
    expect($invoice->status)->toBe(Invoice::STATUS_ISSUED)
        ->and($charge->refresh()->status)->toBe(Charge::STATUS_INVOICED);

    // Reconcile the charge's period — all six invariants ok, delta 0 exactly.
    $report = app(ReconciliationEngine::class)->check($charge->service_date->format('Y-m'));
    expect($report['passed'])->toBeTrue();
    foreach ($report['invariants'] as $invariant) {
        expect($invariant['delta_minor'])->toBe(0);
    }
});

test('editing a procedure fee never changes a charge already captured (snapshot discipline)', function () {
    $fx = dblFixture();
    $charge = app(DentalChargeService::class)->capture($fx['admin'], $fx['patient'], $fx['branch'], $fx['procedure']);
    expect($charge->unit_price_minor)->toBe(15000);

    // Raise the fee on the catalog.
    app(DentalCatalogService::class)->update($fx['admin'], $fx['procedure'], 'Filling', 22000, 0, true, true);
    expect($fx['procedure']->refresh()->tariffItem->unit_price_minor)->toBe(22000);

    // The past charge is frozen at the fee it was captured at.
    expect($charge->refresh()->unit_price_minor)->toBe(15000)
        ->and($charge->line_total_minor)->toBe(15000);

    // A NEW charge uses the new fee.
    $charge2 = app(DentalChargeService::class)->capture($fx['admin'], $fx['patient'], $fx['branch'], $fx['procedure']);
    expect($charge2->unit_price_minor)->toBe(22000);
});

test('charging a dental procedure requires billing.manage', function () {
    $fx = dblFixture();
    $doctor = dblUser($fx['tenant'], 'doctor'); // no billing.manage
    dblCtx()->set($fx['tenant']);

    expect(fn () => app(DentalChargeService::class)->capture($doctor, $fx['patient'], $fx['branch'], $fx['procedure']))
        ->toThrow(AuthorizationException::class);
    expect(Charge::query()->count())->toBe(0);
});
