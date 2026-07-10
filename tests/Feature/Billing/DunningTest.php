<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\DunningEvent;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Notifications\DunningReminderNotification;
use Modules\Billing\Services\DunningService;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PaymentService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

const F6_DUE = '2026-06-01';

function f6Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function f6User(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, catalog: TariffCatalog}
 */
function f6Fixture(string $slug = 'alpha', bool $withEmail = true): array
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Care',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    f6Ctx()->set($tenant);

    $actor = f6User($tenant);
    $branch = Branch::query()->create([
        'name' => strtoupper(substr($slug, 0, 4)).' Branch',
        'code' => strtoupper(substr($slug, 0, 4)),
        'timezone' => 'Europe/Zurich',
    ]);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Dun',
        'last_name' => 'Patient',
        'date_of_birth' => '1988-01-01',
        'sex' => 'female',
    ]);

    if ($withEmail) {
        PatientContact::query()->create([
            'patient_id' => $patient->id,
            'type' => PatientContact::TYPE_EMAIL,
            'value' => $slug.'@example.test',
            'is_primary' => true,
        ]);
    }

    $catalog = TariffCatalog::query()->create([
        'key' => 'eu-generic',
        'name' => 'EU Generic',
        'version' => 1,
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'status' => TariffCatalog::STATUS_ACTIVE,
        'rules' => [],
    ]);

    return compact('tenant', 'actor', 'branch', 'patient', 'catalog');
}

function f6Invoice(array $fixture, int $totalMinor = 1000, string $code = 'DUN-ITEM', string $dueDate = F6_DUE): Invoice
{
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $fixture['catalog']->id,
        'code' => $code,
        'description' => 'Payable '.$code,
        'unit_price_minor' => $totalMinor,
        'vat_rate_bp' => 0,
        'unit' => 'session',
        'requires_service_documentation' => false,
        'active' => true,
    ]);

    $charge = Charge::query()->create([
        'patient_id' => $fixture['patient']->id,
        'branch_id' => $fixture['branch']->id,
        'service_date' => '2026-05-10',
        'tariff_catalog_id' => $fixture['catalog']->id,
        'tariff_item_id' => $item->id,
        'code' => $item->code,
        'description' => $item->description,
        'unit_price_minor' => $item->unit_price_minor,
        'vat_rate_bp' => $item->vat_rate_bp,
        'quantity' => 1,
        'line_total_minor' => $item->unit_price_minor,
        'status' => Charge::STATUS_VALIDATED,
        'created_by' => $fixture['actor']->id,
    ]);

    $service = app(IssueService::class);

    return $service->issue(
        $service->createDraftFromCharges(
            $fixture['patient'],
            [$charge],
            $fixture['actor'],
            Invoice::PAYER_SELF_PAY,
            null,
            Carbon::parse('2026-05-18'),
            Carbon::parse($dueDate),
        ),
        $fixture['actor'],
    );
}

/**
 * @param  list<array{level: int, days_past_due: int, template?: string, fee_code?: ?string}>  $levels
 */
function f6Policy(array $levels): void
{
    app(SettingsService::class)->set(DunningService::SETTINGS_KEY, [
        'channel' => 'email',
        'levels' => $levels,
    ], 'array');
}

function f6AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('level thresholds fire exactly on the day boundary: +13 does not, +14 does', function () {
    Notification::fake();
    Storage::fake('local');
    $fixture = f6Fixture();
    $invoice = f6Invoice($fixture);
    f6Policy([['level' => 1, 'days_past_due' => 14, 'template' => 'Please pay.']]);
    $service = app(DunningService::class);

    $none = $service->evaluate($fixture['tenant'], '2026-06-14', $fixture['actor']);
    expect($none)->toHaveCount(0)
        ->and(DunningEvent::query()->count())->toBe(0);

    $fired = $service->evaluate($fixture['tenant'], '2026-06-15', $fixture['actor']);

    expect($fired)->toHaveCount(1)
        ->and($fired[0]->level)->toBe(1)
        ->and($fired[0]->status)->toBe(DunningEvent::STATUS_SENT)
        ->and($fired[0]->document_path)->toStartWith('tenants/'.$fixture['tenant']->id.'/billing/dunning/');
    Notification::assertSentOnDemand(DunningReminderNotification::class);
});

test('evaluate is idempotent for the same as-of date', function () {
    Notification::fake();
    Storage::fake('local');
    $fixture = f6Fixture();
    $invoice = f6Invoice($fixture);
    f6Policy([
        ['level' => 1, 'days_past_due' => 14],
        ['level' => 2, 'days_past_due' => 30],
        ['level' => 3, 'days_past_due' => 45],
    ]);
    $service = app(DunningService::class);

    $first = $service->evaluate($fixture['tenant'], '2026-07-21', $fixture['actor']); // +50 days
    $second = $service->evaluate($fixture['tenant'], '2026-07-21', $fixture['actor']);

    expect($first)->toHaveCount(3)
        ->and($second)->toHaveCount(0)
        ->and(DunningEvent::query()->where('invoice_id', $invoice->id)->pluck('level')->sort()->values()->all())->toBe([1, 2, 3]);
});

test('paused invoices are skipped until resumed', function () {
    Notification::fake();
    Storage::fake('local');
    $fixture = f6Fixture();
    $invoice = f6Invoice($fixture);
    f6Policy([['level' => 1, 'days_past_due' => 14]]);
    $service = app(DunningService::class);

    $service->setPaused($invoice, true, $fixture['actor'], 'Amount disputed');

    expect($service->evaluate($fixture['tenant'], '2026-06-25', $fixture['actor']))->toHaveCount(0)
        ->and(DunningEvent::query()->count())->toBe(0)
        ->and(f6AuditRows($fixture['tenant']->id, 'dunning.paused'))->toHaveCount(1);

    $service->setPaused($invoice, false, $fixture['actor']);

    expect($service->evaluate($fixture['tenant'], '2026-06-25', $fixture['actor']))->toHaveCount(1);
});

test('level 2 never fires before level 1', function () {
    Notification::fake();
    Storage::fake('local');
    $fixture = f6Fixture();
    $invoice = f6Invoice($fixture);
    f6Policy([
        ['level' => 1, 'days_past_due' => 14],
        ['level' => 2, 'days_past_due' => 30],
    ]);
    $service = app(DunningService::class);

    $service->evaluate($fixture['tenant'], '2026-06-15', $fixture['actor']); // +14: only L1
    expect(DunningEvent::query()->pluck('level')->all())->toBe([1]);

    $service->evaluate($fixture['tenant'], '2026-07-01', $fixture['actor']); // +30: adds L2
    expect(DunningEvent::query()->orderBy('level')->pluck('level')->all())->toBe([1, 2]);
});

test('fully paid invoices never dun', function () {
    Notification::fake();
    Storage::fake('local');
    $fixture = f6Fixture();
    $invoice = f6Invoice($fixture, 1000);
    $payment = app(PaymentService::class)->record(1000, Payment::METHOD_BANK_TRANSFER, $fixture['actor']);
    app(PaymentService::class)->allocate($payment, $invoice, 1000, $fixture['actor']);
    f6Policy([['level' => 1, 'days_past_due' => 14]]);

    expect(app(DunningService::class)->evaluate($fixture['tenant'], '2026-06-25', $fixture['actor']))->toHaveCount(0)
        ->and($invoice->balance()->firstOrFail()->status)->toBe(Invoice::STATUS_PAID);
});

test('a credit-noted invoice with zero open balance never duns', function () {
    Notification::fake();
    Storage::fake('local');
    $fixture = f6Fixture();
    $invoice = f6Invoice($fixture, 1000);
    app(IssueService::class)->creditNote($invoice, null, 'Full correction', $fixture['actor']);
    f6Policy([['level' => 1, 'days_past_due' => 14]]);

    expect($invoice->balance()->firstOrFail()->open_balance_minor)->toBe(0)
        ->and(app(DunningService::class)->evaluate($fixture['tenant'], '2026-06-25', $fixture['actor']))->toHaveCount(0);
});

test('an optional per-level fee creates a NEW charge and leaves the original invoice untouched', function () {
    Notification::fake();
    Storage::fake('local');
    $fixture = f6Fixture();
    TariffItem::query()->create([
        'tariff_catalog_id' => $fixture['catalog']->id,
        'code' => 'DUN-FEE',
        'description' => 'Dunning fee',
        'unit_price_minor' => 2000,
        'vat_rate_bp' => 0,
        'unit' => 'item',
        'requires_service_documentation' => false,
        'active' => true,
    ]);
    $invoice = f6Invoice($fixture, 1000);
    $frozen = $invoice->only(['status', 'number', 'subtotal_minor', 'vat_total_minor', 'total_minor', 'open_balance_minor']);
    f6Policy([['level' => 1, 'days_past_due' => 14, 'fee_code' => 'DUN-FEE']]);

    app(DunningService::class)->evaluate($fixture['tenant'], '2026-06-15', $fixture['actor']);

    $feeCharge = Charge::query()->where('code', 'DUN-FEE')->firstOrFail();

    expect($feeCharge->status)->toBe(Charge::STATUS_DRAFT)
        ->and($feeCharge->invoice_id)->toBeNull()
        ->and($feeCharge->line_total_minor)->toBe(2000)
        // Original invoice document is entirely untouched.
        ->and($invoice->refresh()->only(['status', 'number', 'subtotal_minor', 'vat_total_minor', 'total_minor', 'open_balance_minor']))->toBe($frozen)
        ->and($invoice->balance()->firstOrFail()->open_balance_minor)->toBe(1000);
});

test('dunning delivery is a legal communication and is NOT gated on comms consent', function () {
    Notification::fake();
    Storage::fake('local');
    // Patient has an email but no comms.email consent recorded.
    $fixture = f6Fixture();
    $invoice = f6Invoice($fixture);
    f6Policy([['level' => 1, 'days_past_due' => 14]]);

    $fired = app(DunningService::class)->evaluate($fixture['tenant'], '2026-06-15', $fixture['actor']);

    expect($fired[0]->status)->toBe(DunningEvent::STATUS_SENT)
        ->and(f6AuditRows($fixture['tenant']->id, 'dunning.sent'))->toHaveCount(1);
    Notification::assertCount(1);
});

test('without a deliverable recipient the event is created but not sent', function () {
    Notification::fake();
    Storage::fake('local');
    $fixture = f6Fixture('nomail', withEmail: false);
    $invoice = f6Invoice($fixture);
    f6Policy([['level' => 1, 'days_past_due' => 14]]);

    $fired = app(DunningService::class)->evaluate($fixture['tenant'], '2026-06-15', $fixture['actor']);

    expect($fired[0]->status)->toBe(DunningEvent::STATUS_CREATED)
        ->and(f6AuditRows($fixture['tenant']->id, 'dunning.sent'))->toHaveCount(0);
    Notification::assertNothingSent();
});

test('dunning events are append-only at the database level', function () {
    Notification::fake();
    Storage::fake('local');
    $fixture = f6Fixture();
    $invoice = f6Invoice($fixture);
    f6Policy([['level' => 1, 'days_past_due' => 14]]);
    $event = app(DunningService::class)->evaluate($fixture['tenant'], '2026-06-15', $fixture['actor'])[0];

    expect(fn () => DB::update("UPDATE dunning_events SET status = 'sent' WHERE id = ?", [$event->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::delete('DELETE FROM dunning_events WHERE id = ?', [$event->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => $event->forceFill(['status' => DunningEvent::STATUS_CREATED])->save())
        ->toThrow(LogicException::class);
});

test('dunning is RBAC guarded tenant isolated audited and fail closed', function () {
    Notification::fake();
    Storage::fake('local');
    $alpha = f6Fixture('alpha');
    $invoice = f6Invoice($alpha);
    f6Policy([['level' => 1, 'days_past_due' => 14]]);
    $reception = f6User($alpha['tenant'], 'reception');

    expect(fn () => app(DunningService::class)->evaluate($alpha['tenant'], '2026-06-15', $reception))
        ->toThrow(AuthorizationException::class);

    $event = app(DunningService::class)->evaluate($alpha['tenant'], '2026-06-15', $alpha['actor'])[0];

    expect(f6AuditRows($alpha['tenant']->id, 'dunning.triggered'))->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($alpha['tenant']->id)['ok'])->toBeTrue();

    f6Fixture('beta');
    expect(DunningEvent::query()->whereKey($event->id)->exists())->toBeFalse();

    f6Ctx()->set($alpha['tenant']);
    expect(DunningEvent::query()->whereKey($event->id)->exists())->toBeTrue();

    f6Ctx()->forget();
    expect(fn () => DunningEvent::query()->count())->toThrow(TenantContextMissingException::class);
});
