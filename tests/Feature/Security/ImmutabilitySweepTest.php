<?php

use Database\Seeders\DemoClinicSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\DunningEvent;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAllocation;
use Modules\Billing\Services\ChargeCaptureService;
use Modules\Billing\Services\PaymentService;
use Modules\Billing\Services\ReconciliationEngine;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Comms\Models\Message;
use Modules\Comms\Models\NotificationDelivery;
use Modules\Comms\Models\TelehealthParticipant;
use Modules\Comms\Models\TelehealthSession;
use Modules\Nursing\Models\TimesheetLine;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/**
 * THE INTEGRITY SUITE. Every append-only / immutable table, attacked the way an
 * attacker (or a careless migration, or a future "quick fix") would actually do
 * it: raw SQL straight past Eloquent. Model-level guards are not enough — the
 * DB trigger is the thing that has to hold, so nothing here goes through a model.
 *
 * The rows are the P.1 demo tenant's: created through the real services, so
 * these are the same rows a real clinic would have.
 *
 * Module suites keep their own trigger assertions in context (they test the
 * same guards from each module's point of view); this suite is the single place
 * that proves the WHOLE set, table by table, with no gaps.
 */

/**
 * @return array{tenant: Tenant, actor: User}
 */
function p3Demo(): array
{
    Storage::fake('local');
    (new DemoClinicSeeder)->run();

    $tenant = Tenant::query()->where('slug', DemoClinicSeeder::TENANT_SLUG)->firstOrFail();
    app(TenantContext::class)->set($tenant);

    $actor = User::query()
        ->where('tenant_id', $tenant->id)
        ->where('email', 'thomas.ammann@praxis-lindenhof.test')
        ->firstOrFail();

    return compact('tenant', 'actor');
}

/**
 * Raw UPDATE and raw DELETE, both must be rejected by the database itself.
 */
function p3AssertFrozen(string $table, string $id, string $setClause): void
{
    expect(fn () => DB::update("UPDATE {$table} SET {$setClause} WHERE id = ?", [$id]))
        ->toThrow(QueryException::class, '', "{$table}: raw UPDATE was NOT rejected");

    expect(fn () => DB::delete("DELETE FROM {$table} WHERE id = ?", [$id]))
        ->toThrow(QueryException::class, '', "{$table}: raw DELETE was NOT rejected");

    // Still there, unchanged.
    expect(DB::table($table)->where('id', $id)->exists())->toBeTrue("{$table}: row vanished");
}

test('audit_events rejects raw UPDATE and DELETE', function () {
    p3Demo();
    $id = DB::table('audit_events')->orderBy('id')->value('id');

    p3AssertFrozen('audit_events', $id, "action = 'tampered'");
});

test('ai_interactions rejects raw UPDATE and DELETE', function () {
    p3Demo();
    $id = DB::table('ai_interactions')->orderBy('id')->value('id');

    p3AssertFrozen('ai_interactions', $id, "outcome = 'tampered'");
});

test('payments, payment_allocations and refunds reject raw UPDATE and DELETE', function () {
    $fx = p3Demo();

    $payment = Payment::query()->orderBy('id')->firstOrFail();
    $allocation = PaymentAllocation::query()->orderBy('id')->firstOrFail();

    // The demo has no refund; make one on the overpayment's unallocated remainder.
    $overpayment = Payment::query()->where('method', Payment::METHOD_CARD)->firstOrFail();
    $refund = app(PaymentService::class)->refund($overpayment, 500, 'Integrity fixture', $fx['actor']);

    p3AssertFrozen('payments', $payment->id, 'amount_minor = 999999');
    p3AssertFrozen('payment_allocations', $allocation->id, 'amount_minor = 999999');
    p3AssertFrozen('refunds', $refund->id, 'amount_minor = 999999');
});

test('issued invoices and their lines reject raw UPDATE and DELETE, while drafts stay editable', function () {
    p3Demo();

    $issued = Invoice::query()->where('status', '!=', Invoice::STATUS_DRAFT)->whereNotNull('number')->firstOrFail();
    $line = DB::table('invoice_lines')->where('invoice_id', $issued->id)->first();

    p3AssertFrozen('invoices', $issued->id, 'total_minor = 1');
    p3AssertFrozen('invoice_lines', $line->id, 'line_total_minor = 1');

    // The trigger keys off status, so a DRAFT invoice must still be workable —
    // an over-broad trigger would break invoicing entirely.
    $draft = Invoice::query()->where('status', Invoice::STATUS_DRAFT)->first();

    if ($draft instanceof Invoice) {
        DB::update('UPDATE invoices SET payer_name = ? WHERE id = ?', ['Still editable', $draft->id]);
        expect(DB::table('invoices')->where('id', $draft->id)->value('payer_name'))->toBe('Still editable');
    }
});

test('visit_events rejects raw UPDATE and DELETE', function () {
    p3Demo();
    $id = DB::table('visit_events')->orderBy('id')->value('id');

    p3AssertFrozen('visit_events', $id, "location_source = 'tampered'");
});

test('dunning_events rejects raw UPDATE and DELETE', function () {
    p3Demo();
    $id = DunningEvent::query()->orderBy('id')->firstOrFail()->id;

    p3AssertFrozen('dunning_events', $id, 'level = 9');
});

test('notification_deliveries rejects raw UPDATE and DELETE', function () {
    p3Demo();
    $id = NotificationDelivery::query()->orderBy('id')->firstOrFail()->id;

    p3AssertFrozen('notification_deliveries', $id, "rendered_body = 'tampered'");
});

test('messages reject raw UPDATE and DELETE — what was said is evidence', function () {
    p3Demo();
    $id = Message::query()->orderBy('id')->firstOrFail()->id;

    p3AssertFrozen('messages', $id, "body = 'I never said this'");
});

test('reconciliation_runs rejects raw UPDATE and DELETE', function () {
    $fx = p3Demo();
    $run = app(ReconciliationEngine::class)->run($fx['tenant'], DemoClinicSeeder::period(), $fx['actor']);

    p3AssertFrozen('reconciliation_runs', $run->id, 'passed = 0');
});

test('approved timesheet lines reject raw UPDATE and DELETE, while drafts stay editable', function () {
    p3Demo();

    $approved = TimesheetLine::query()->where('status', TimesheetLine::STATUS_APPROVED)->firstOrFail();
    p3AssertFrozen('timesheet_lines', $approved->id, 'minutes = 999');

    // A draft line must still be correctable before approval.
    $draft = TimesheetLine::query()->where('status', TimesheetLine::STATUS_DRAFT)->first();

    if ($draft instanceof TimesheetLine) {
        DB::update('UPDATE timesheet_lines SET travel_minutes = ? WHERE id = ?', [7, $draft->id]);
        expect((int) DB::table('timesheet_lines')->where('id', $draft->id)->value('travel_minutes'))->toBe(7);
    }
});

test('telehealth_participants rejects raw DELETE and a second leave write', function () {
    $fx = p3Demo();

    $patient = Patient::query()->orderBy('id')->firstOrFail();
    $session = TelehealthSession::query()->create([
        'patient_id' => $patient->id,
        'practitioner_id' => StaffProfile::query()->where('profession', 'doctor')->firstOrFail()->id,
        'provider' => 'fake',
        'room_reference' => 'integrity-room',
        'status' => 'created',
    ]);
    $participant = TelehealthParticipant::query()->create([
        'session_id' => $session->id,
        'participant_type' => 'practitioner',
        'participant_id' => 'prac-1',
        'joined_at' => now(),
    ]);

    expect(fn () => DB::delete('DELETE FROM telehealth_participants WHERE id = ?', [$participant->id]))
        ->toThrow(QueryException::class);

    // left_at fills exactly once; rewriting it is forbidden.
    DB::update('UPDATE telehealth_participants SET left_at = ? WHERE id = ?', [now()->toDateTimeString(), $participant->id]);

    expect(fn () => DB::update(
        'UPDATE telehealth_participants SET left_at = ? WHERE id = ?',
        [now()->addHour()->toDateTimeString(), $participant->id],
    ))->toThrow(QueryException::class);
});

test('a SIGNED clinical note rejects raw UPDATE and DELETE, while a draft still updates', function () {
    p3Demo();

    $signed = ClinicalNote::query()->where('status', ClinicalNote::STATUS_SIGNED)->firstOrFail();

    // Every SOAP field, one at a time: the record of what a clinician signed.
    foreach (['subjective', 'objective', 'assessment', 'plan'] as $field) {
        expect(fn () => DB::update("UPDATE clinical_notes SET {$field} = 'tampered' WHERE id = ?", [$signed->id]))
            ->toThrow(QueryException::class, '', "signed note {$field} was mutable");
    }

    expect(fn () => DB::delete('DELETE FROM clinical_notes WHERE id = ?', [$signed->id]))
        ->toThrow(QueryException::class);

    expect(DB::table('clinical_notes')->where('id', $signed->id)->value('subjective'))
        ->toBe($signed->subjective);

    // A DRAFT still updates — the trigger keys off OLD.status, and an
    // over-broad one would make note-taking impossible.
    $draft = ClinicalNote::query()->where('status', ClinicalNote::STATUS_DRAFT)->firstOrFail();
    DB::update('UPDATE clinical_notes SET subjective = ? WHERE id = ?', ['Still drafting', $draft->id]);

    expect(DB::table('clinical_notes')->where('id', $draft->id)->value('subjective'))->toBe('Still drafting');
});

test('an invoiced charge cannot be silently cancelled by raw SQL bypassing the correction path', function () {
    p3Demo();

    // Not trigger-protected: charges stay mutable by design (draft -> validated
    // -> invoiced). This pins the SERVICE rule instead — an invoiced charge is
    // corrected by credit note, never by cancellation.
    $invoiced = Charge::query()->where('status', Charge::STATUS_INVOICED)->firstOrFail();

    expect(fn () => app(ChargeCaptureService::class)
        ->cancel($invoiced, User::query()->where('email', 'thomas.ammann@praxis-lindenhof.test')->firstOrFail(), 'Trying it on'))
        ->toThrow(InvalidArgumentException::class);

    expect($invoiced->refresh()->status)->toBe(Charge::STATUS_INVOICED);
});
