<?php

use Database\Seeders\DemoClinicSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PaymentService;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\Nursing\Models\Visit;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Reporting\Services\MetricsService;
use Modules\Reporting\Services\ReportingService;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\Service;

uses(RefreshDatabase::class);

function g14Tenant(string $slug): Tenant
{
    return Tenant::create(['name' => ucfirst($slug).' Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
}

function g14User(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

function g14Patient(string $first): Patient
{
    return app(PatientService::class)->create([
        'first_name' => $first,
        'last_name' => 'Metrics',
        'date_of_birth' => '1960-06-06',
        'sex' => 'female',
    ]);
}

function g14Appointment(array $attributes): Appointment
{
    return Appointment::query()->create([
        'ends_at' => Carbon::parse($attributes['starts_at'])->addMinutes(30)->toDateTimeString(),
        'source' => 'staff',
        ...$attributes,
    ]);
}

/**
 * Operational fixture: a controlled matrix of appointments, visits, encounters,
 * notes, and orders across two branches and July 2026, with out-of-range decoys.
 *
 * @return array<string, mixed>
 */
function g14OperationalFixture(): array
{
    $tenant = g14Tenant('alpha');
    app(TenantContext::class)->set($tenant);

    $manager = g14User($tenant, 'org_admin');
    $branchA = Branch::query()->create(['name' => 'Alpha One', 'code' => 'AL1']);
    $branchB = Branch::query()->create(['name' => 'Alpha Two', 'code' => 'AL2']);
    $service = Service::query()->create([
        'name' => 'Consult',
        'code' => 'ALP-CONS',
        'category' => 'general',
        'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => false,
        'active' => true,
    ]);
    $staff = StaffProfile::query()->create([
        'user_id' => $manager->id,
        'first_name' => 'Metric',
        'last_name' => 'Recorder',
        'display_name' => 'Metric Recorder',
        'profession' => 'doctor',
        'primary_branch_id' => $branchA->id,
    ]);
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Metrics Nurse',
        'branch_id' => $branchA->id,
    ]);

    $p1 = g14Patient('One');
    $p2 = g14Patient('Two');
    $p3 = g14Patient('Three');
    $p4 = g14Patient('Four'); // nothing in July → excluded from active patients

    $base = ['service_id' => $service->id, 'branch_id' => $branchA->id];

    // July, branch A.
    g14Appointment([...$base, 'patient_id' => $p1->id, 'starts_at' => '2026-07-10 09:00:00', 'status' => Appointment::STATUS_BOOKED]);
    g14Appointment([...$base, 'patient_id' => $p1->id, 'starts_at' => '2026-07-11 10:00:00', 'status' => Appointment::STATUS_COMPLETED, 'checked_in_at' => '2026-07-11 09:55:00']);
    g14Appointment([...$base, 'patient_id' => $p2->id, 'starts_at' => '2026-07-12 09:00:00', 'status' => Appointment::STATUS_NO_SHOW]);
    g14Appointment([...$base, 'patient_id' => $p2->id, 'starts_at' => '2026-07-13 09:00:00', 'status' => Appointment::STATUS_CANCELLED]);
    // July, branch B.
    g14Appointment([...$base, 'branch_id' => $branchB->id, 'patient_id' => $p3->id, 'starts_at' => '2026-07-14 09:00:00', 'status' => Appointment::STATUS_NO_SHOW]);
    g14Appointment([...$base, 'branch_id' => $branchB->id, 'patient_id' => $p3->id, 'starts_at' => '2026-07-15 09:00:00', 'status' => Appointment::STATUS_ARRIVED, 'checked_in_at' => '2026-07-15 08:50:00']);
    // Out of range decoys.
    g14Appointment([...$base, 'patient_id' => $p4->id, 'starts_at' => '2026-08-02 09:00:00', 'status' => Appointment::STATUS_BOOKED]);
    g14Appointment([...$base, 'patient_id' => $p2->id, 'starts_at' => '2026-06-30 23:00:00', 'status' => Appointment::STATUS_COMPLETED, 'checked_in_at' => '2026-06-30 22:55:00']);

    // Nursing visits.
    Visit::query()->create(['patient_id' => $p2->id, 'resource_id' => $resource->id, 'branch_id' => $branchA->id, 'scheduled_start_at' => '2026-07-10 08:00:00', 'status' => Visit::STATUS_COMPLETED, 'client_visit_uuid' => (string) Str::ulid()]);
    Visit::query()->create(['patient_id' => $p3->id, 'resource_id' => $resource->id, 'branch_id' => $branchB->id, 'scheduled_start_at' => '2026-07-20 08:00:00', 'status' => Visit::STATUS_COMPLETED, 'client_visit_uuid' => (string) Str::ulid()]);
    Visit::query()->create(['patient_id' => $p1->id, 'resource_id' => $resource->id, 'branch_id' => $branchA->id, 'scheduled_start_at' => '2026-07-21 08:00:00', 'status' => Visit::STATUS_SCHEDULED, 'client_visit_uuid' => (string) Str::ulid()]);
    Visit::query()->create(['patient_id' => $p1->id, 'resource_id' => $resource->id, 'branch_id' => $branchA->id, 'scheduled_start_at' => '2026-08-05 08:00:00', 'status' => Visit::STATUS_COMPLETED, 'client_visit_uuid' => (string) Str::ulid()]);

    // Encounters.
    $e1 = Encounter::query()->create(['patient_id' => $p1->id, 'practitioner_id' => $staff->id, 'branch_id' => $branchA->id, 'type' => 'consultation', 'started_at' => '2026-07-10 09:05:00', 'status' => Encounter::STATUS_OPEN]);
    Encounter::query()->create(['patient_id' => $p2->id, 'practitioner_id' => $staff->id, 'branch_id' => $branchB->id, 'type' => 'consultation', 'started_at' => '2026-07-12 10:00:00', 'status' => Encounter::STATUS_OPEN]);
    Encounter::query()->create(['patient_id' => $p2->id, 'practitioner_id' => $staff->id, 'branch_id' => $branchA->id, 'type' => 'consultation', 'started_at' => '2026-06-15 10:00:00', 'status' => Encounter::STATUS_CLOSED]);

    // Notes: one signed in range, one draft in range, one signed out of range.
    ClinicalNote::query()->create(['encounter_id' => $e1->id, 'patient_id' => $p1->id, 'author_id' => $staff->id, 'subjective' => 'Documented', 'status' => ClinicalNote::STATUS_SIGNED, 'signed_at' => '2026-07-12 11:00:00', 'signed_by' => $manager->id, 'version' => 1]);
    ClinicalNote::query()->create(['encounter_id' => $e1->id, 'patient_id' => $p1->id, 'author_id' => $staff->id, 'subjective' => 'Draft', 'status' => ClinicalNote::STATUS_DRAFT, 'version' => 1]);
    ClinicalNote::query()->create(['encounter_id' => $e1->id, 'patient_id' => $p1->id, 'author_id' => $staff->id, 'subjective' => 'Older', 'status' => ClinicalNote::STATUS_SIGNED, 'signed_at' => '2026-06-01 11:00:00', 'signed_by' => $manager->id, 'version' => 1]);

    // Orders: one in range, one out.
    $item = OrderableItem::query()->create(['category' => OrderableItem::CATEGORY_LAB, 'code' => 'FBC', 'name' => 'Full blood count', 'active' => true]);
    Order::query()->create(['patient_id' => $p1->id, 'orderable_item_id' => $item->id, 'ordered_by' => $manager->id, 'ordered_at' => '2026-07-13 09:30:00', 'priority' => 'routine', 'status' => Order::STATUS_ORDERED]);
    Order::query()->create(['patient_id' => $p1->id, 'orderable_item_id' => $item->id, 'ordered_by' => $manager->id, 'ordered_at' => '2026-06-13 09:30:00', 'priority' => 'routine', 'status' => Order::STATUS_ORDERED]);

    return compact('tenant', 'manager', 'branchA', 'branchB', 'service', 'staff', 'resource', 'p1', 'p2', 'p3', 'p4');
}

/**
 * Financial fixture through the REAL F.2/F.4/F.5 path: tariff item → validated
 * charge → IssueService draft+issue → PaymentService record+allocate.
 *
 * @return array<string, mixed>
 */
function g14FinancialFixture(array $fixture): array
{
    $catalog = TariffCatalog::query()->create([
        'key' => 'eu-generic',
        'name' => 'EU Generic',
        'version' => 1,
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'status' => TariffCatalog::STATUS_ACTIVE,
        'rules' => [],
    ]);

    $issue = function (int $totalMinor, string $code, string $issueDate, string $dueDate) use ($fixture, $catalog): Invoice {
        $item = TariffItem::query()->create([
            'tariff_catalog_id' => $catalog->id,
            'code' => $code,
            'description' => 'Metric item '.$code,
            'unit_price_minor' => $totalMinor,
            'vat_rate_bp' => 0,
            'unit' => 'session',
            'requires_service_documentation' => false,
            'active' => true,
        ]);
        $charge = Charge::query()->create([
            'patient_id' => $fixture['p1']->id,
            'branch_id' => $fixture['branchA']->id,
            'service_date' => '2026-05-10',
            'tariff_catalog_id' => $catalog->id,
            'tariff_item_id' => $item->id,
            'code' => $item->code,
            'description' => $item->description,
            'unit_price_minor' => $item->unit_price_minor,
            'vat_rate_bp' => $item->vat_rate_bp,
            'quantity' => 1,
            'line_total_minor' => $item->unit_price_minor,
            'status' => Charge::STATUS_VALIDATED,
            'created_by' => $fixture['manager']->id,
        ]);
        $service = app(IssueService::class);

        return $service->issue(
            $service->createDraftFromCharges(
                $fixture['p1'],
                [$charge],
                $fixture['manager'],
                Invoice::PAYER_SELF_PAY,
                null,
                Carbon::parse($issueDate),
                Carbon::parse($dueDate),
            ),
            $fixture['manager'],
        );
    };

    $inv1 = $issue(10000, 'MET-1', '2026-07-05', '2026-07-20');
    $inv2 = $issue(5000, 'MET-2', '2026-07-15', '2026-08-10');
    $inv3 = $issue(7000, 'MET-3', '2026-06-10', '2026-06-20');
    $inv4 = $issue(400, 'MET-4', '2026-03-01', '2026-03-01');

    // One payment in July, allocated against inv1 (open drops to 7000); one out of range.
    $payments = app(PaymentService::class);
    $july = $payments->record(3000, Payment::METHOD_CASH, $fixture['manager'], $fixture['p1'], null, null, '2026-07-06');
    $payments->allocate($july, $inv1, 3000, $fixture['manager']);
    $payments->record(2000, Payment::METHOD_CASH, $fixture['manager'], $fixture['p1'], null, null, '2026-08-01');

    return compact('catalog', 'inv1', 'inv2', 'inv3', 'inv4');
}

/**
 * Recursively collect every key and every leaf value of a nested array.
 *
 * @return array{keys: list<string>, leaves: list<mixed>}
 */
function g14Walk(array $data): array
{
    $keys = [];
    $leaves = [];
    $walk = function (array $node) use (&$walk, &$keys, &$leaves): void {
        foreach ($node as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $walk($value);
            } else {
                $leaves[] = $value;
            }
        }
    };
    $walk($data);

    return ['keys' => $keys, 'leaves' => $leaves];
}

test('appointments, no-shows, and check-ins return exact seeded counts and respect date bounds', function () {
    $fx = g14OperationalFixture();
    $metrics = app(MetricsService::class);

    $july = $metrics->appointmentsInRange($fx['manager'], '2026-07-01', '2026-07-31');

    expect($july['total'])->toBe(6)
        ->and($july['by_status'])->toBe([
            'booked' => 1,
            'confirmed' => 0,
            'arrived' => 1,
            'in_progress' => 0,
            'completed' => 1,
            'cancelled' => 1,
            'no_show' => 2,
            'rescheduled' => 0,
        ]);

    expect($metrics->noShows($fx['manager'], '2026-07-01', '2026-07-31'))
        ->toBe(['no_show' => 2, 'scheduled' => 6, 'rate' => 0.3333]);

    expect($metrics->checkedInCount($fx['manager'], '2026-07-01', '2026-07-31'))->toBe(2);

    // Date bounding: June sees only the June 30 decoy (and its check-in).
    $june = $metrics->appointmentsInRange($fx['manager'], '2026-06-01', '2026-06-30');
    expect($june['total'])->toBe(1)
        ->and($june['by_status']['completed'])->toBe(1)
        ->and($metrics->checkedInCount($fx['manager'], '2026-06-01', '2026-06-30'))->toBe(1);
});

test('visits, encounters, signed notes, orders, and active patients return exact counts', function () {
    $fx = g14OperationalFixture();
    $metrics = app(MetricsService::class);

    expect($metrics->visitsCompletedInRange($fx['manager'], '2026-07-01', '2026-07-31'))->toBe(2)
        ->and($metrics->encountersInRange($fx['manager'], '2026-07-01', '2026-07-31'))->toBe(2)
        ->and($metrics->signedNotesInRange($fx['manager'], '2026-07-01', '2026-07-31'))->toBe(1)
        ->and($metrics->ordersPlacedInRange($fx['manager'], '2026-07-01', '2026-07-31'))->toBe(1)
        // p1/p2/p3 are active in July; p4 exists but has nothing in range.
        ->and($metrics->activePatientsCount($fx['manager'], '2026-07-01', '2026-07-31'))->toBe(3)
        ->and(Patient::query()->count())->toBe(4);
});

test('branch filtering narrows branch-dimensioned metrics', function () {
    $fx = g14OperationalFixture();
    $metrics = app(MetricsService::class);

    $branchA = $metrics->appointmentsInRange($fx['manager'], '2026-07-01', '2026-07-31', $fx['branchA']->id);

    expect($branchA['total'])->toBe(4)
        ->and($branchA['by_status']['no_show'])->toBe(1)
        ->and($metrics->noShows($fx['manager'], '2026-07-01', '2026-07-31', $fx['branchA']->id))
        ->toBe(['no_show' => 1, 'scheduled' => 4, 'rate' => 0.25])
        ->and($metrics->checkedInCount($fx['manager'], '2026-07-01', '2026-07-31', $fx['branchA']->id))->toBe(1)
        ->and($metrics->visitsCompletedInRange($fx['manager'], '2026-07-01', '2026-07-31', $fx['branchA']->id))->toBe(1)
        ->and($metrics->encountersInRange($fx['manager'], '2026-07-01', '2026-07-31', $fx['branchA']->id))->toBe(1)
        // Branch A active patients: p1 (appointments/visit/encounter) + p2 (appointments/visit).
        ->and($metrics->activePatientsCount($fx['manager'], '2026-07-01', '2026-07-31', $fx['branchA']->id))->toBe(2);
});

test('financial metrics return exact integer minor totals from the real billing path', function () {
    $fx = g14OperationalFixture();
    g14FinancialFixture($fx);
    $metrics = app(MetricsService::class);

    // I4 definition: issued non-CN invoices with issue_date in range.
    expect($metrics->invoicedTotalMinor($fx['manager'], '2026-07-01', '2026-07-31'))->toBe(15000)
        // Payments by received_on; the August payment is excluded.
        ->and($metrics->paymentsReceivedTotalMinor($fx['manager'], '2026-07-01', '2026-07-31'))->toBe(3000)
        // Point-in-time projection: 7000 (inv1 after allocation) + 5000 + 7000 + 400.
        ->and($metrics->outstandingBalanceMinor($fx['manager']))->toBe(19400);
});

test('aging buckets split the outstanding balance by factual overdue-day boundaries', function () {
    $fx = g14OperationalFixture();
    g14FinancialFixture($fx);
    $metrics = app(MetricsService::class);

    // asOf 2026-07-21: inv1 due 07-20 → 1 day (1-30); inv2 due 08-10 → current;
    // inv3 due 06-20 → 31 days → 31-60 (the +31 boundary case); inv4 → 90+.
    expect($metrics->agingBuckets($fx['manager'], '2026-07-21'))->toBe([
        'current' => 5000,
        'days_1_30' => 7000,
        'days_31_60' => 7000,
        'days_61_90' => 0,
        'days_90_plus' => 400,
    ]);

    // asOf the due date itself: not yet past due → current.
    expect($metrics->agingBuckets($fx['manager'], '2026-07-20'))->toBe([
        'current' => 12000,
        'days_1_30' => 7000, // inv3: exactly 30 days past due stays in 1-30
        'days_31_60' => 0,
        'days_61_90' => 0,
        'days_90_plus' => 400,
    ]);

    // asOf 2026-08-20: inv1 → 31 (31-60); inv2 → 10 (1-30); inv3 → 61 (61-90).
    expect($metrics->agingBuckets($fx['manager'], '2026-08-20'))->toBe([
        'current' => 0,
        'days_1_30' => 5000,
        'days_31_60' => 7000,
        'days_61_90' => 7000,
        'days_90_plus' => 400,
    ]);
});

test('financial metrics reconcile with the F.7 reconciliation engine on the demo tenant', function () {
    $this->seed(DemoClinicSeeder::class);

    $tenant = Tenant::query()->where('slug', 'praxis-lindenhof')->firstOrFail();
    app(TenantContext::class)->set($tenant);
    $manager = g14User($tenant, 'org_admin');

    $period = DemoClinicSeeder::period();
    $from = DemoClinicSeeder::periodStart()->toDateString();
    $to = DemoClinicSeeder::periodStart()->endOfMonth()->toDateString();

    $report = app(ReconciliationEngine::class)->check($period);
    $invariants = collect($report['invariants'])->keyBy('invariant');

    // The demo month is the reconciled baseline.
    expect($report['passed'])->toBeTrue();

    $metrics = app(MetricsService::class);

    // Invoiced total agrees with I4's period total (same definition, same number).
    expect($metrics->invoicedTotalMinor($manager, $from, $to))->toBe((int) $invariants['I4']['expected_minor'])
        // Outstanding agrees with I2's summed projection (all demo invoices sit in the period).
        ->and($metrics->outstandingBalanceMinor($manager))->toBe((int) $invariants['I2']['actual_minor'])
        // Payments agree with the append-only ledger for the period.
        ->and($metrics->paymentsReceivedTotalMinor($manager, $from, $to))
        ->toBe((int) Payment::query()->whereBetween('received_on', [$from, $to])->sum('amount_minor'));

    // The command proves the layer end to end (a command, not a UI).
    $this->artisan('reporting:summary', ['tenant' => 'praxis-lindenhof', 'from' => $from, 'to' => $to])
        ->expectsOutputToContain('invoiced_total_minor')
        ->assertExitCode(0);
});

test('metrics are tenant-isolated', function () {
    $alpha = g14OperationalFixture();
    g14FinancialFixture($alpha);
    $metrics = app(MetricsService::class);

    $alphaInvoiced = $metrics->invoicedTotalMinor($alpha['manager'], '2026-07-01', '2026-07-31');
    $alphaAppointments = $metrics->appointmentsInRange($alpha['manager'], '2026-07-01', '2026-07-31')['total'];

    // Second tenant with its own July data.
    $beta = g14Tenant('beta');
    app(TenantContext::class)->set($beta);
    $betaManager = g14User($beta, 'org_admin');
    $betaBranch = Branch::query()->create(['name' => 'Beta One', 'code' => 'BE1']);
    $betaService = Service::query()->create([
        'name' => 'Consult',
        'code' => 'BET-CONS',
        'category' => 'general',
        'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => false,
        'active' => true,
    ]);
    $betaPatient = g14Patient('Beta');
    g14Appointment(['service_id' => $betaService->id, 'branch_id' => $betaBranch->id, 'patient_id' => $betaPatient->id, 'starts_at' => '2026-07-10 09:00:00', 'status' => Appointment::STATUS_BOOKED]);

    // Beta sees ONLY its own single appointment and no alpha money.
    expect($metrics->appointmentsInRange($betaManager, '2026-07-01', '2026-07-31')['total'])->toBe(1)
        ->and($metrics->invoicedTotalMinor($betaManager, '2026-07-01', '2026-07-31'))->toBe(0)
        ->and($metrics->outstandingBalanceMinor($betaManager))->toBe(0);

    // Back in alpha: numbers unchanged by beta's existence.
    app(TenantContext::class)->set($alpha['tenant']);
    expect($metrics->invoicedTotalMinor($alpha['manager'], '2026-07-01', '2026-07-31'))->toBe($alphaInvoiced)
        ->and($metrics->appointmentsInRange($alpha['manager'], '2026-07-01', '2026-07-31')['total'])->toBe($alphaAppointments);
});

test('the summary bundle carries only numeric facts and no judgment fields', function () {
    $fx = g14OperationalFixture();
    g14FinancialFixture($fx);

    $summary = app(ReportingService::class)->summary($fx['manager'], '2026-07-01', '2026-07-31');

    expect($summary)->toHaveKeys(['range', 'operational', 'throughput', 'financial']);

    $walked = g14Walk([
        'operational' => $summary['operational'],
        'throughput' => $summary['throughput'],
        'financial' => $summary['financial'],
    ]);

    // No judgment/label keys anywhere — facts only.
    $forbidden = ['good', 'bad', 'high', 'low', 'status', 'grade', 'score', 'label', 'verdict', 'rating', 'risk', 'flag', 'severity'];
    foreach ($forbidden as $key) {
        expect($walked['keys'])->not->toContain($key);
    }

    // Every leaf is a number (count, sum, or rate) — never a string judgment.
    foreach ($walked['leaves'] as $leaf) {
        expect(is_int($leaf) || is_float($leaf))->toBeTrue();
    }
});

test('RBAC: operational needs reporting.view, financial needs billing.view, summary composes fail-closed', function () {
    $fx = g14OperationalFixture();
    g14FinancialFixture($fx);
    $metrics = app(MetricsService::class);

    $coordinator = g14User($fx['tenant'], 'coordinator'); // reporting.view, no billing.view
    $billing = g14User($fx['tenant'], 'billing');         // billing.view, no reporting.view
    $reception = g14User($fx['tenant'], 'reception');     // neither

    // Reception: refused everywhere.
    expect(fn () => $metrics->appointmentsInRange($reception, '2026-07-01', '2026-07-31'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $metrics->invoicedTotalMinor($reception, '2026-07-01', '2026-07-31'))
        ->toThrow(AuthorizationException::class);

    // Billing role: financial yes, operational no, summary no (needs reporting.view).
    expect($metrics->invoicedTotalMinor($billing, '2026-07-01', '2026-07-31'))->toBe(15000)
        ->and(fn () => $metrics->appointmentsInRange($billing, '2026-07-01', '2026-07-31'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReportingService::class)->summary($billing, '2026-07-01', '2026-07-31'))
        ->toThrow(AuthorizationException::class);

    // Coordinator: operational yes; the summary omits the financial section entirely.
    $summary = app(ReportingService::class)->summary($coordinator, '2026-07-01', '2026-07-31');
    expect($summary)->toHaveKeys(['range', 'operational', 'throughput'])
        ->and($summary)->not->toHaveKey('financial')
        ->and(fn () => $metrics->invoicedTotalMinor($coordinator, '2026-07-01', '2026-07-31'))
        ->toThrow(AuthorizationException::class);
});

test('computing the full summary writes no audit rows and no patient read logs', function () {
    $fx = g14OperationalFixture();
    g14FinancialFixture($fx);

    $before = (int) DB::table('audit_events')->count();

    app(ReportingService::class)->summary($fx['manager'], '2026-07-01', '2026-07-31');

    // Aggregates are not patient records: no patient-scoped read rows, and the
    // layer is read-only: no audited writes of any kind.
    expect((int) DB::table('audit_events')->count())->toBe($before);
});
