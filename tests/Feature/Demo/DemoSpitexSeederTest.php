<?php

use Carbon\CarbonInterface;
use Database\Seeders\DemoSpitexSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\KbArticle;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\DunningEvent;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Services\EuGenericTariffSeeder;
use Modules\Billing\Services\PaymentService;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\Clinical\Models\Allergy;
use Modules\Clinical\Models\CarePlan;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Services\VitalsHistoryService;
use Modules\Comms\Models\Thread;
use Modules\Nursing\Models\Incident;
use Modules\Nursing\Models\NurseCompetency;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\TimesheetLine;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitTask;
use Modules\Nursing\Services\AssignmentValidator;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Reporting\Services\ReportingService;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource;

uses(RefreshDatabase::class);

/**
 * Whole-schema row counts, so "the second run added nothing" is a claim about
 * every table rather than the handful we remembered to check.
 *
 * @return array<string, int>
 */
function spitexRowCounts(): array
{
    $counts = [];

    foreach (DB::select('SHOW TABLES') as $row) {
        $table = array_values((array) $row)[0];

        if (in_array($table, ['migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions'], true)) {
            continue;
        }

        $counts[$table] = (int) DB::table($table)->count();
    }

    return $counts;
}

function spitexTenant(): Tenant
{
    return Tenant::query()->where('slug', DemoSpitexSeeder::TENANT_SLUG)->firstOrFail();
}

function spitexPatient(string $lastName): Patient
{
    return Patient::query()->where('last_name', $lastName)->firstOrFail();
}

function spitexResource(string $name): Resource
{
    return Resource::query()->where('name', $name)->firstOrFail();
}

/**
 * A seeded planned visit that requires the given competency code.
 */
function spitexVisitRequiring(string $code): PlannedVisit
{
    return PlannedVisit::query()
        ->get()
        ->first(fn (PlannedVisit $visit): bool => in_array($code, $visit->required_competencies ?? [], true))
        ?? throw new RuntimeException("No seeded visit requires {$code}.");
}

test('spitex demo seeder is idempotent and produces the agency the demo promises', function () {
    (new DemoSpitexSeeder)->run();
    $afterFirst = spitexRowCounts();

    // Guard against a vacuous pass: the first run must produce real volume.
    expect($afterFirst['patients'])->toBeGreaterThan(0)
        ->and($afterFirst['invoices'])->toBeGreaterThan(0)
        ->and($afterFirst['visits'])->toBeGreaterThan(0)
        ->and($afterFirst['nurse_competencies'])->toBeGreaterThan(0)
        ->and($afterFirst['audit_events'])->toBeGreaterThan(0);

    (new DemoSpitexSeeder)->run();

    expect(spitexRowCounts())->toBe($afterFirst)
        ->and(Tenant::query()->where('slug', DemoSpitexSeeder::TENANT_SLUG)->count())->toBe(1);

    $tenant = spitexTenant();
    app(TenantContext::class)->set($tenant);

    // The roster: 9 staff users, 8 patients, competencies granted across it —
    // including one EXPIRED grant (expired counts as not held).
    expect(User::query()->where('tenant_id', $tenant->id)->count())->toBe(9)
        ->and(Patient::query()->count())->toBe(8)
        ->and(DB::table('competencies')->count())->toBe(5)
        ->and(NurseCompetency::query()->count())->toBe(9)
        ->and(NurseCompetency::query()->where('active', true)->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', today()->toDateString())->count())->toBe(1);

    // A FULL WEEK of assigned home-care visits: the daily insulin round alone
    // covers every day of the current week.
    $weekStart = now()->startOfWeek(CarbonInterface::MONDAY);
    $weekDays = PlannedVisit::query()
        ->where('status', PlannedVisit::STATUS_ASSIGNED)
        ->whereBetween('scheduled_date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
        ->pluck('scheduled_date')
        ->map(fn ($date) => $date->toDateString())
        ->unique();

    expect($weekDays->count())->toBeGreaterThanOrEqual(5);

    // Executed visits with proof, tasks (done AND documented not-done), raw
    // vitals, notes, one factual incident, and timesheets from actuals.
    expect(Visit::query()->where('status', Visit::STATUS_COMPLETED)->count())->toBeGreaterThanOrEqual(25)
        ->and(DB::table('visit_events')->count())->toBeGreaterThan(40)
        ->and(VisitTask::query()->where('status', VisitTask::STATUS_DONE)->count())->toBeGreaterThan(10)
        ->and(VisitTask::query()->where('status', VisitTask::STATUS_NOT_DONE)->whereNotNull('not_done_reason')->count())->toBe(2)
        ->and(Incident::query()->where('status', Incident::STATUS_OPEN)->count())->toBe(1)
        ->and(TimesheetLine::query()->count())->toBeGreaterThan(0)
        ->and(TimesheetLine::query()->where('status', TimesheetLine::STATUS_APPROVED)->count())->toBeGreaterThan(0)
        ->and(TimesheetLine::query()->whereNull('started_at')->count())->toBe(0);

    // The P.13 vitals trend: Margrit's unified series has a real line to draw,
    // merged from BOTH stores (visit-captured and clinic-captured readings).
    $series = app(VitalsHistoryService::class)->forPatient(spitexPatient('Ackermann')->id)['metrics'];
    $systolicSources = collect($series['systolic'])->pluck('source')->unique()->values()->all();

    expect(count($series['systolic']))->toBeGreaterThanOrEqual(5)
        ->and($systolicSources)->toContain('visit')
        ->and($systolicSources)->toContain('clinic');

    // The P.12 competency demo, on seeded data:
    $validator = app(AssignmentValidator::class);

    // HARD BLOCK — Ana Silva (RN) lacks wound_care, so a wound visit refuses her…
    $woundVisit = spitexVisitRequiring('wound_care');
    $blocked = $validator->evaluate($woundVisit, spitexResource('Ana Silva'), []);
    expect($blocked->passes())->toBeFalse()
        ->and($blocked->blocking)->toContain(AssignmentValidator::REASON_COMPETENCY_MISSING_HARD.':wound_care');

    // …while Verena Huber (holds it) passes the same visit.
    expect($validator->evaluate($woundVisit, spitexResource('Verena Huber'), [])->passes())->toBeTrue();

    // EXPIRED counts as not held: Hans Brunner's catheter_care lapsed.
    $catheterVisit = spitexVisitRequiring('catheter_care');
    $expired = $validator->evaluate($catheterVisit, spitexResource('Hans Brunner'), []);
    expect($expired->passes())->toBeFalse()
        ->and($expired->blocking)->toContain(AssignmentValidator::REASON_COMPETENCY_MISSING_HARD.':catheter_care');

    // SOFT WARN — David Okafor lacks dementia_care (soft): allowed, but flagged.
    $bathVisit = spitexVisitRequiring('dementia_care');
    $warned = $validator->evaluate($bathVisit, spitexResource('David Okafor'), []);
    expect($warned->passes())->toBeTrue()
        ->and($warned->warnings)->toContain(AssignmentValidator::REASON_COMPETENCY_MISSING_SOFT.':dementia_care');

    // Clinical surface: a signed assessment with an amendment chain, a severe
    // allergy for the banner, a care plan, and P.11 orders in both worklist states.
    $amended = ClinicalNote::query()->whereNotNull('supersedes_id')->first();
    expect(ClinicalNote::query()->where('status', ClinicalNote::STATUS_SIGNED)->count())->toBeGreaterThanOrEqual(4)
        ->and($amended)->not->toBeNull()
        ->and($amended->amendment_reason)->not->toBeNull()
        ->and(Allergy::query()->where('severity', Allergy::SEVERITY_SEVERE)->count())->toBe(1)
        ->and(CarePlan::query()->count())->toBe(1)
        ->and(Order::query()->where('status', Order::STATUS_RESULTED)->count())->toBe(1)
        ->and(Order::query()->where('status', Order::STATUS_REVIEWED)->count())->toBe(1);

    // The office day: three consultations including a REAL no-show.
    expect(Appointment::query()->count())->toBe(3)
        ->and(Appointment::query()->where('status', Appointment::STATUS_NO_SHOW)->count())->toBe(1)
        ->and(Appointment::query()->where('status', Appointment::STATUS_COMPLETED)->count())->toBe(1);

    // Comms: two patient threads (one flagged clinical), one internal, one
    // assigned; portal accounts through the real invite path; a live KB; two
    // pending AI approvals that have DONE nothing.
    expect(Thread::query()->where('type', Thread::TYPE_PATIENT)->count())->toBe(2)
        ->and(Thread::query()->whereNotNull('clinician_attention_at')->count())->toBe(1)
        ->and(Thread::query()->where('type', Thread::TYPE_INTERNAL)->count())->toBe(1)
        ->and(Thread::query()->whereNotNull('assigned_to')->count())->toBe(1)
        ->and(PortalAccount::query()->where('status', PortalAccount::STATUS_ACTIVE)->count())->toBe(2)
        ->and(KbArticle::query()->where('is_active', true)->count())->toBe(2)
        ->and(AgentAction::query()->where('status', AgentAction::STATUS_PENDING)->count())->toBe(2)
        ->and(AgentAction::query()->whereNotNull('executed_at')->count())->toBe(0);

    // HONESTY: the agency bills EU-GENERIC — the CH/KVG statutory pack is
    // deferred pending discovery, and nothing in the demo implies otherwise.
    expect(TariffCatalog::query()->pluck('key')->unique()->all())->toBe([EuGenericTariffSeeder::CATALOG_KEY]);

    // The P.14 reporting summary returns non-trivial operational AND financial
    // numbers for the agency's period + current week.
    $manager = User::query()->where('email', 'regula.baumann@spitex-sonnengarten.test')->firstOrFail();
    $summary = app(ReportingService::class)->summary(
        $manager,
        DemoSpitexSeeder::periodStart()->toDateString(),
        $weekStart->copy()->addDays(6)->toDateString(),
    );

    expect($summary['operational']['visits_completed'])->toBeGreaterThanOrEqual(25)
        ->and($summary['operational']['appointments']['total'])->toBe(3)
        ->and($summary['operational']['no_shows']['no_show'])->toBe(1)
        ->and($summary['operational']['active_patients'])->toBeGreaterThanOrEqual(6)
        ->and($summary['financial']['invoiced_total_minor'])->toBeGreaterThan(0)
        ->and($summary['financial']['outstanding_balance_minor'])->toBeGreaterThan(0);

    // And the audit chain the whole demo was seeded through still verifies.
    expect(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('spitex demo: the billing month reconciles to the unit', function () {
    (new DemoSpitexSeeder)->run();

    $tenant = spitexTenant();
    app(TenantContext::class)->set($tenant);
    $actor = User::query()->where('email', 'corinne.vogel@spitex-sonnengarten.test')->firstOrFail();

    // Six gapless invoices; visit-based AND encounter-based charges both exist;
    // the housekeeping line makes one invoice genuinely multi-rate.
    $invoices = Invoice::query()->where('series', Invoice::SERIES_INVOICE)->whereNotNull('number')->get();
    $numbers = $invoices->pluck('number')->map(fn (string $n): int => (int) $n)->sort()->values()->all();

    expect($invoices)->toHaveCount(6)
        ->and($numbers)->toBe([1, 2, 3, 4, 5, 6])
        ->and(Charge::query()->whereNotNull('visit_id')->count())->toBeGreaterThan(40)
        ->and(Charge::query()->whereNotNull('encounter_id')->count())->toBe(2);

    $multiRate = $invoices->first(
        fn (Invoice $invoice): bool => $invoice->lines()->distinct()->pluck('vat_rate_bp')->count() >= 2,
    );
    expect($multiRate)->not->toBeNull();

    // Payments: full, partial, and an overpayment whose remainder stays
    // visibly unallocated.
    expect(Payment::query()->count())->toBe(3)
        ->and(app(PaymentService::class)->unallocated(
            Payment::query()->where('method', Payment::METHOD_CARD)->firstOrFail(),
        ))->toBe(1500);

    // A partial credit note that leaves the original invoice untouched.
    $creditNote = Invoice::query()->where('series', Invoice::SERIES_CREDIT_NOTE)->firstOrFail();
    $original = Invoice::query()->whereKey($creditNote->credit_note_for_invoice_id)->firstOrFail();

    expect($creditNote->total_minor)->toBeLessThan(0)
        ->and(abs($creditNote->total_minor))->toBeLessThan($original->total_minor);

    // One invoice at dunning level 1; the fee is a NEW draft charge.
    expect(DunningEvent::query()->count())->toBe(1)
        ->and(DunningEvent::query()->firstOrFail()->level)->toBe(1)
        ->and(Charge::query()->where('code', 'DUNNING-FEE')->firstOrFail()->status)->toBe(Charge::STATUS_DRAFT);

    // The point of the exercise: all six invariants ok, delta exactly zero.
    $run = app(ReconciliationEngine::class)->run($tenant, DemoSpitexSeeder::period(), $actor);

    expect($run->passed)->toBeTrue()
        ->and($run->report['invariants'])->toHaveCount(6);

    foreach ($run->report['invariants'] as $invariant) {
        expect($invariant['ok'] === true)->toBeTrue()
            ->and($invariant['delta_minor'] === 0)->toBeTrue()
            ->and($invariant['rows'])->toBe([]);
    }
});
