<?php

use Database\Seeders\DemoClinicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\KbArticle;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\DunningEvent;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAllocation;
use Modules\Billing\Services\PaymentService;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\Clinical\Models\Allergy;
use Modules\Clinical\Models\CarePlan;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Models\Recall;
use Modules\Clinical\Models\Referral;
use Modules\Clinical\Services\UnsignedNotesWorklist;
use Modules\Comms\Models\Message;
use Modules\Comms\Models\NotificationDelivery;
use Modules\Comms\Models\Thread;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\SyncConflict;
use Modules\Nursing\Models\TimesheetLine;
use Modules\Nursing\Models\Visit;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\People\Models\Credential;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\WaitlistEntry;
use Modules\Scheduling\Models\WaitlistEntry as Waitlist;

uses(RefreshDatabase::class);

/**
 * Counts every table in the database, so "the second run added nothing" is a
 * claim about the whole schema rather than the handful of tables we remembered
 * to check.
 *
 * @return array<string, int>
 */
function demoRowCounts(): array
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

function demoTenant(): Tenant
{
    return Tenant::query()->where('slug', DemoClinicSeeder::TENANT_SLUG)->firstOrFail();
}

test('demo clinic seeder is idempotent: a second run adds nothing', function () {
    Storage::fake('local');

    (new DemoClinicSeeder)->run();
    $afterFirst = demoRowCounts();

    // Guard against a vacuous pass: comparing two empty databases proves
    // nothing, so assert the first run actually produced something to duplicate.
    expect($afterFirst['patients'])->toBeGreaterThan(0)
        ->and($afterFirst['invoices'])->toBeGreaterThan(0)
        ->and($afterFirst['visits'])->toBeGreaterThan(0)
        ->and($afterFirst['audit_events'])->toBeGreaterThan(0);

    (new DemoClinicSeeder)->run();
    $afterSecond = demoRowCounts();

    // Every table in the schema, not just the ones we thought to name.
    expect($afterSecond)->toBe($afterFirst)
        ->and(Tenant::query()->where('slug', DemoClinicSeeder::TENANT_SLUG)->count())->toBe(1);
});

test('demo clinic seeder produces a non-trivial, tenant-scoped clinic', function () {
    Storage::fake('local');

    (new DemoClinicSeeder)->run();

    $tenant = demoTenant();
    app(TenantContext::class)->set($tenant);

    expect($tenant->name)->toBe('Praxis Lindenhof')
        ->and($tenant->status)->toBe('active');

    // The gate's non-trivial counts.
    expect(Patient::query()->count())->toBeGreaterThan(0)
        ->and(Invoice::query()->whereNotNull('number')->count())->toBeGreaterThan(0)
        ->and(Visit::query()->count())->toBeGreaterThan(0);

    // People: the full staffing the demo promises, with a credential vault that
    // has something to warn about.
    expect(User::query()->where('tenant_id', $tenant->id)->count())->toBe(9)
        ->and(Credential::query()->where('status', Credential::STATUS_EXPIRING)->count())->toBeGreaterThan(0)
        ->and(Credential::query()->where('status', Credential::STATUS_EXPIRED)->count())->toBeGreaterThan(0)
        ->and(Credential::query()->where('status', Credential::STATUS_REVOKED)->count())->toBeGreaterThan(0);

    // Resources: rooms, a chair, two vehicles, and the practitioners.
    expect(Resource::query()->where('type', Resource::TYPE_ROOM)->count())->toBe(2)
        ->and(Resource::query()->where('type', Resource::TYPE_CHAIR)->count())->toBe(1)
        ->and(Resource::query()->where('type', Resource::TYPE_VEHICLE)->count())->toBe(2)
        ->and(Resource::query()->where('type', Resource::TYPE_PRACTITIONER)->count())->toBe(5);

    // Patients: near-duplicates for the dedup screen, consents, portal accounts.
    expect(Patient::query()->count())->toBeGreaterThanOrEqual(15)
        ->and(Patient::query()->where('last_name', 'Meier')->count())->toBe(2)
        ->and(PortalAccount::query()->where('status', PortalAccount::STATUS_ACTIVE)->count())->toBe(2);

    // Scheduling: the current week in every lifecycle state, plus online + waitlist.
    $states = Appointment::query()->distinct()->pluck('status')->all();
    foreach ([
        Appointment::STATUS_BOOKED,
        Appointment::STATUS_CONFIRMED,
        Appointment::STATUS_ARRIVED,
        Appointment::STATUS_IN_PROGRESS,
        Appointment::STATUS_COMPLETED,
        Appointment::STATUS_CANCELLED,
        Appointment::STATUS_NO_SHOW,
        Appointment::STATUS_RESCHEDULED,
    ] as $state) {
        expect($states)->toContain($state);
    }

    expect(Appointment::query()->where('source', Appointment::SOURCE_ONLINE)->count())->toBe(2)
        ->and(Waitlist::query()->where('status', WaitlistEntry::STATUS_WAITING)->count())->toBe(1);

    // Clinical: signed notes, an amendment chain, a loud allergy, raw vitals,
    // aged drafts, a shared document, a referral, a due recall, a care plan.
    $amended = ClinicalNote::query()->whereNotNull('supersedes_id')->first();

    expect(ClinicalNote::query()->where('status', ClinicalNote::STATUS_SIGNED)->count())->toBeGreaterThan(1)
        ->and($amended)->not->toBeNull()
        ->and($amended->amendment_reason)->not->toBeNull()
        ->and(ClinicalNote::query()->where('status', ClinicalNote::STATUS_DRAFT)->count())->toBe(3)
        ->and(Allergy::query()->where('severity', Allergy::SEVERITY_SEVERE)->count())->toBeGreaterThan(0)
        ->and(Document::query()->where('shared_with_patient', true)->count())->toBe(1)
        ->and(Referral::query()->where('status', Referral::STATUS_SENT)->count())->toBe(1)
        ->and(Recall::query()->where('status', Recall::STATUS_DUE)->count())->toBeGreaterThan(0)
        ->and(CarePlan::query()->count())->toBe(1);

    // Vitals carry raw values only — no interpretation ever reaches the record.
    $vitalColumns = array_keys((array) DB::selectOne('SELECT * FROM vitals LIMIT 1'));
    foreach (['flag', 'score', 'interpretation', 'severity', 'abnormal'] as $forbidden) {
        expect(implode(',', $vitalColumns))->not->toContain($forbidden);
    }

    // Aged drafts actually reach the unsigned-notes worklist being demoed.
    $worklist = app(UnsignedNotesWorklist::class)
        ->olderThan(User::query()->where('tenant_id', $tenant->id)->firstOrFail(), 2);
    expect($worklist->count())->toBeGreaterThan(0);

    // Nursing: assigned planned visits, executed visits with proof rows, a
    // timesheet line derived from them, and a conflict left for a human.
    expect(PlannedVisit::query()->where('status', PlannedVisit::STATUS_ASSIGNED)->count())->toBeGreaterThan(0)
        ->and(Visit::query()->where('status', Visit::STATUS_COMPLETED)->count())->toBeGreaterThan(0)
        ->and(TimesheetLine::query()->count())->toBeGreaterThan(0)
        ->and(TimesheetLine::query()->where('status', TimesheetLine::STATUS_APPROVED)->count())->toBeGreaterThan(0)
        ->and(SyncConflict::query()->where('status', SyncConflict::STATUS_OPEN)->count())->toBe(1)
        ->and(DB::table('visit_events')->count())->toBeGreaterThan(0);

    // Every executed visit's timesheet minutes come from real check-in/out
    // events, never from the planned window.
    expect(TimesheetLine::query()->whereNull('started_at')->count())->toBe(0);

    // Comms: patient threads, one flagged clinical, an internal thread, deliveries.
    expect(Thread::query()->where('type', Thread::TYPE_PATIENT)->count())->toBe(3)
        ->and(Thread::query()->whereNotNull('assigned_to')->count())->toBe(1)
        ->and(Thread::query()->where('type', Thread::TYPE_INTERNAL)->count())->toBe(1)
        ->and(Thread::query()->whereNotNull('clinician_attention_at')->count())->toBe(1)
        ->and(Message::query()->count())->toBeGreaterThan(0)
        ->and(NotificationDelivery::query()->count())->toBe(2)
        ->and(NotificationDelivery::query()->where('status', NotificationDelivery::STATUS_SKIPPED)->count())->toBe(1);

    // Internal threads may never reference a patient.
    expect(Thread::query()->where('type', Thread::TYPE_INTERNAL)->whereNotNull('patient_id')->count())->toBe(0);

    // AiCore: pending proposals that have done nothing, and an active KB.
    expect(AgentAction::query()->where('status', AgentAction::STATUS_PENDING)->count())->toBe(2)
        ->and(AgentAction::query()->whereNotNull('executed_at')->count())->toBe(0)
        ->and(KbArticle::query()->where('is_active', true)->count())->toBe(3);

    // The demo tenant is the only tenant this seeder created, and its audit
    // chain holds end to end.
    expect(Tenant::query()->count())->toBe(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('demo clinic: the demo period reconciles to the unit', function () {
    Storage::fake('local');

    (new DemoClinicSeeder)->run();

    $tenant = demoTenant();
    app(TenantContext::class)->set($tenant);
    $actor = User::query()->where('tenant_id', $tenant->id)->where('email', 'thomas.ammann@praxis-lindenhof.test')->firstOrFail();

    // Six gapless invoices, one of them multi-rate.
    $invoices = Invoice::query()
        ->where('series', Invoice::SERIES_INVOICE)
        ->whereNotNull('number')
        ->get();
    $numbers = $invoices->pluck('number')->map(fn (string $n): int => (int) $n)->sort()->values()->all();

    expect($invoices)->toHaveCount(6)
        ->and($numbers)->toBe([1, 2, 3, 4, 5, 6]);

    $multiRate = $invoices->first(
        fn (Invoice $invoice): bool => $invoice->lines()->distinct()->pluck('vat_rate_bp')->count() >= 2,
    );
    expect($multiRate)->not->toBeNull();

    // The tariff-version boundary is real: the same code priced differently on
    // either side, both invoiced.
    $consultBefore = Charge::query()->where('code', 'CONSULT-30')
        ->whereDate('service_date', '<=', DemoClinicSeeder::periodStart()->addDays(14)->toDateString())
        ->firstOrFail();
    $consultAfter = Charge::query()->where('code', 'CONSULT-30')
        ->whereDate('service_date', '>', DemoClinicSeeder::periodStart()->addDays(14)->toDateString())
        ->firstOrFail();

    expect($consultBefore->unit_price_minor)->toBe(6000)
        ->and($consultAfter->unit_price_minor)->toBe(6300)
        ->and($consultBefore->status)->toBe(Charge::STATUS_INVOICED)
        ->and($consultAfter->status)->toBe(Charge::STATUS_INVOICED);

    // Encounter- and visit-based charges both exist.
    expect(Charge::query()->whereNotNull('encounter_id')->count())->toBeGreaterThan(0)
        ->and(Charge::query()->whereNotNull('visit_id')->count())->toBeGreaterThan(0);

    // Payments: full, partial, an overpayment whose remainder stays unallocated,
    // and a reversal that nets to zero.
    expect(Payment::query()->count())->toBe(4)
        ->and(PaymentAllocation::query()->whereNotNull('reverses_allocation_id')->count())->toBe(1)
        ->and(app(PaymentService::class)->unallocated(
            Payment::query()->where('method', Payment::METHOD_CARD)->firstOrFail()
        ))->toBe(2500);

    // A partial credit note that leaves the original invoice document untouched.
    $creditNote = Invoice::query()->where('series', Invoice::SERIES_CREDIT_NOTE)->firstOrFail();
    $original = Invoice::query()->whereKey($creditNote->credit_note_for_invoice_id)->firstOrFail();

    expect($creditNote->total_minor)->toBeLessThan(0)
        ->and(abs($creditNote->total_minor))->toBeLessThan($original->total_minor)
        ->and($original->status)->toBe(Invoice::STATUS_ISSUED);

    // One invoice sits at dunning level 1, and the fee is a new draft charge.
    expect(DunningEvent::query()->count())->toBe(1)
        ->and(DunningEvent::query()->firstOrFail()->level)->toBe(1)
        ->and(Charge::query()->where('code', 'DUNNING-FEE')->firstOrFail()->status)->toBe(Charge::STATUS_DRAFT);

    // ------------------------------------------------------------------
    // The point of the exercise: the demo data is internally consistent.
    // Every invariant ok === true AND delta_minor === 0. Exactly zero.
    // ------------------------------------------------------------------
    $run = app(ReconciliationEngine::class)->run($tenant, DemoClinicSeeder::period(), $actor);

    expect($run->passed)->toBeTrue()
        ->and($run->report['invariants'])->toHaveCount(6);

    foreach ($run->report['invariants'] as $invariant) {
        expect($invariant['ok'] === true)->toBeTrue()
            ->and($invariant['delta_minor'] === 0)->toBeTrue()
            ->and($invariant['rows'])->toBe([]);
    }
});
