<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\Service;

uses(RefreshDatabase::class);

/*
 * FIX.2 — the staff landing (/app) is wired to the EXISTING MetricsService for
 * today, tenant-scoped, RBAC-gated: operational figures require reporting.view,
 * the outstanding-balance figure requires billing.view. A role with neither gets
 * the shell (both props null) rather than the old "awaiting data" stub, and a
 * tenant with no data today shows a genuine zero (operational present, counts 0).
 */

function laCtx(): TenantContext
{
    return app(TenantContext::class);
}

function laUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    if ($role !== '') {
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
        ]);
    }

    return $user;
}

function laPatient(string $first): Patient
{
    return app(PatientService::class)->create(['first_name' => $first, 'last_name' => 'Landing', 'date_of_birth' => '1980-01-01', 'sex' => 'female']);
}

/**
 * @return array{tenant: Tenant, branch: Branch, service: Service, catalog: TariffCatalog}
 */
function laFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    laCtx()->set($tenant);

    $branch = Branch::query()->create(['name' => 'MAIN Branch', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    $service = Service::query()->create([
        'name' => 'Consult', 'code' => 'CONS', 'category' => 'general',
        'default_duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER], 'bookable_online' => false, 'active' => true,
    ]);
    $catalog = TariffCatalog::query()->create([
        'key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1,
        'valid_from' => '2026-01-01', 'valid_to' => null, 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => [],
    ]);

    return compact('tenant', 'branch', 'service', 'catalog');
}

function laAppt(array $fx, Patient $patient, string $status, int $hour, bool $checkedIn = false): void
{
    $start = now()->setTime($hour, 0, 0);
    Appointment::query()->create([
        'service_id' => $fx['service']->id,
        'branch_id' => $fx['branch']->id,
        'patient_id' => $patient->id,
        'starts_at' => $start->toDateTimeString(),
        'ends_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
        'status' => $status,
        'source' => 'staff',
        'checked_in_at' => $checkedIn ? $start->copy()->subMinutes(10)->toDateTimeString() : null,
    ]);
}

function laIssueInvoice(array $fx, Patient $patient, User $actor): Invoice
{
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $fx['catalog']->id, 'code' => 'LA-ITEM', 'description' => 'Service',
        'unit_price_minor' => 12000, 'vat_rate_bp' => 0, 'unit' => 'session',
        'requires_service_documentation' => false, 'active' => true,
    ]);
    $charge = Charge::query()->create([
        'patient_id' => $patient->id, 'branch_id' => $fx['branch']->id, 'service_date' => now()->toDateString(),
        'tariff_catalog_id' => $fx['catalog']->id, 'tariff_item_id' => $item->id, 'code' => $item->code,
        'description' => $item->description, 'unit_price_minor' => 12000, 'vat_rate_bp' => 0,
        'quantity' => 1, 'line_total_minor' => 12000, 'status' => Charge::STATUS_VALIDATED, 'created_by' => $actor->id,
    ]);
    $service = app(IssueService::class);

    return $service->issue($service->createDraftFromCharges($patient, [$charge], $actor, Invoice::PAYER_SELF_PAY, null, now(), now()->addDays(14)), $actor);
}

test('the staff landing shows real today figures + outstanding for a manager with data', function () {
    Storage::fake('local');
    $fx = laFixture();
    $manager = laUser($fx['tenant'], 'org_admin'); // has reporting.view + billing.view

    $p1 = laPatient('Ada');
    $p2 = laPatient('Bea');
    laAppt($fx, $p1, Appointment::STATUS_COMPLETED, 9, true);
    laAppt($fx, $p2, Appointment::STATUS_NO_SHOW, 10);
    laAppt($fx, $p2, Appointment::STATUS_ARRIVED, 11, true);
    laIssueInvoice($fx, $p1, $manager); // 12000 minor outstanding

    $this->actingAs($manager)
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('App/Landing')
            ->where('operational.appointments', 3)
            ->where('operational.no_shows', 1)
            ->where('operational.waiting', 1) // one ARRIVED today
            ->where('operational.by_status.completed', 1)
            ->where('operational.active_patients', 2)
            ->where('financial.outstanding_minor', 12000)
            ->where('financial.currency', 'EUR'));
});

test('a tenant with no activity today shows genuine zeros, not the awaiting-data stub', function () {
    Storage::fake('local');
    $fx = laFixture();
    $manager = laUser($fx['tenant'], 'org_admin');

    $this->actingAs($manager)
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('App/Landing')
            // operational is a populated object with real zeros (NOT null / not the stub)
            ->where('operational.appointments', 0)
            ->where('operational.no_shows', 0)
            ->where('operational.active_patients', 0)
            ->where('financial.outstanding_minor', 0));
});

test('landing figures are RBAC-gated: operational needs reporting.view, financial needs billing.view', function () {
    Storage::fake('local');
    $fx = laFixture();

    // coordinator: reporting.view, NO billing.view → operational shown, financial omitted.
    $this->actingAs(laUser($fx['tenant'], 'coordinator'))
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('App/Landing')
            ->has('operational')
            ->where('financial', null));

    // billing role: billing.view, NO reporting.view → financial shown, operational omitted.
    $this->actingAs(laUser($fx['tenant'], 'billing'))
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('operational', null)
            ->has('financial'));

    // reception: neither → shell only (both null), and the page still renders (no 500/stub).
    $this->actingAs(laUser($fx['tenant'], 'reception'))
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('App/Landing')
            ->where('operational', null)
            ->where('financial', null));
});
