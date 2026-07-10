<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function f4Tenant(string $slug): Tenant
{
    return Tenant::query()->create([
        'name' => ucfirst($slug).' Care',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function f4Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function f4Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function f4User(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => f4Role($role)->id,
    ]);

    return $user;
}

function f4Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create([
        'name' => $code.' Branch',
        'code' => $code,
        'timezone' => 'Europe/Zurich',
    ]);
}

function f4Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Invoice',
        'last_name' => 'Patient',
        'date_of_birth' => '1988-01-01',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function f4Catalog(array $overrides = []): TariffCatalog
{
    return TariffCatalog::query()->create([
        'key' => 'eu-generic',
        'name' => 'EU Generic',
        'version' => 1,
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'status' => TariffCatalog::STATUS_ACTIVE,
        'rules' => [],
        ...$overrides,
    ]);
}

function f4Item(TariffCatalog $catalog, array $overrides = []): TariffItem
{
    return TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id,
        'code' => 'INV-ITEM',
        'description' => 'Invoice item',
        'unit_price_minor' => 1000,
        'vat_rate_bp' => 810,
        'unit' => 'session',
        'requires_service_documentation' => false,
        'active' => true,
        ...$overrides,
    ]);
}

function f4Charge(
    Patient $patient,
    Branch $branch,
    TariffCatalog $catalog,
    TariffItem $item,
    User $actor,
    int $quantity = 1,
    string $status = Charge::STATUS_VALIDATED,
): Charge {
    return Charge::query()->create([
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'service_date' => '2026-05-10',
        'tariff_catalog_id' => $catalog->id,
        'tariff_item_id' => $item->id,
        'code' => $item->code,
        'description' => $item->description,
        'unit_price_minor' => $item->unit_price_minor,
        'vat_rate_bp' => $item->vat_rate_bp,
        'quantity' => $quantity,
        'line_total_minor' => $quantity * $item->unit_price_minor,
        'status' => $status,
        'created_by' => $actor->id,
    ]);
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, catalog: TariffCatalog}
 */
function f4Fixture(string $slug = 'alpha', string $role = 'billing'): array
{
    $tenant = f4Tenant($slug);
    f4Ctx()->set($tenant);
    $actor = f4User($tenant, $role);
    $branch = f4Branch(strtoupper(substr($slug, 0, 4)));
    $patient = f4Patient();
    $catalog = f4Catalog();

    return compact('tenant', 'actor', 'branch', 'patient', 'catalog');
}

function f4Issue(array $fixture, array $charges): Invoice
{
    return app(IssueService::class)->issue(
        app(IssueService::class)->createDraftFromCharges(
            $fixture['patient'],
            $charges,
            $fixture['actor'],
            Invoice::PAYER_SELF_PAY,
            null,
            now(),
            now()->addDays(14),
        ),
        $fixture['actor'],
    );
}

function f4AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('invoice issue computes multi-rate VAT per line and marks charges invoiced', function () {
    Storage::fake('local');
    $fixture = f4Fixture();
    $itemA = f4Item($fixture['catalog'], [
        'code' => 'VAT-810',
        'description' => 'VAT 8.1 percent',
        'unit_price_minor' => 1000,
        'vat_rate_bp' => 810,
    ]);
    $itemB = f4Item($fixture['catalog'], [
        'code' => 'VAT-1900',
        'description' => 'VAT 19 percent rounded',
        'unit_price_minor' => 333,
        'vat_rate_bp' => 1900,
    ]);
    $chargeA = f4Charge($fixture['patient'], $fixture['branch'], $fixture['catalog'], $itemA, $fixture['actor']);
    $chargeB = f4Charge($fixture['patient'], $fixture['branch'], $fixture['catalog'], $itemB, $fixture['actor'], 3);

    $invoice = f4Issue($fixture, [$chargeA, $chargeB]);

    expect($invoice->number)->toBe('1')
        ->and($invoice->series)->toBe(Invoice::SERIES_INVOICE)
        ->and($invoice->status)->toBe(Invoice::STATUS_ISSUED)
        ->and($invoice->subtotal_minor)->toBe(1999)
        ->and($invoice->vat_total_minor)->toBe(271)
        ->and($invoice->total_minor)->toBe(2270)
        ->and($invoice->open_balance_minor)->toBe(2270)
        ->and($invoice->lines()->where('code', 'VAT-810')->firstOrFail()->line_vat_minor)->toBe(81)
        ->and($invoice->lines()->where('code', 'VAT-1900')->firstOrFail()->line_vat_minor)->toBe(190)
        ->and($chargeA->refresh()->status)->toBe(Charge::STATUS_INVOICED)
        ->and($chargeB->refresh()->invoice_id)->toBe($invoice->id)
        ->and($invoice->balance()->firstOrFail()->open_balance_minor)->toBe(2270);
});

test('issued invoices and lines are immutable at model and database level while drafts remain editable', function () {
    Storage::fake('local');
    $fixture = f4Fixture();
    $item = f4Item($fixture['catalog']);
    $charge = f4Charge($fixture['patient'], $fixture['branch'], $fixture['catalog'], $item, $fixture['actor']);
    $service = app(IssueService::class);
    $draft = $service->createDraftFromCharges($fixture['patient'], [$charge], $fixture['actor']);
    $draftLine = $draft->lines()->firstOrFail();

    $draft->forceFill(['payer_name' => 'Editable Draft'])->save();
    $draftLine->forceFill(['description' => 'Editable draft line'])->save();

    $invoice = $service->issue($draft->refresh(), $fixture['actor']);
    $line = $invoice->lines()->firstOrFail();

    expect(fn () => $invoice->forceFill(['total_minor' => 9999])->save())->toThrow(LogicException::class)
        ->and(fn () => $line->forceFill(['line_total_minor' => 9999])->save())->toThrow(LogicException::class)
        ->and(fn () => $invoice->delete())->toThrow(LogicException::class)
        ->and(fn () => $line->delete())->toThrow(LogicException::class);

    foreach ([
        'total_minor = 9999',
        'subtotal_minor = 9999',
        'vat_total_minor = 9999',
        "number = '999'",
        "issue_date = '2026-06-01'",
    ] as $assignment) {
        expect(fn () => DB::update("UPDATE invoices SET {$assignment} WHERE id = ?", [$invoice->id]))
            ->toThrow(QueryException::class);
    }

    expect(fn () => DB::update('UPDATE invoice_lines SET line_total_minor = 9999 WHERE id = ?', [$line->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::update('UPDATE invoice_lines SET line_vat_minor = 9999 WHERE id = ?', [$line->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::delete('DELETE FROM invoice_lines WHERE id = ?', [$line->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::delete('DELETE FROM invoices WHERE id = ?', [$invoice->id]))
        ->toThrow(QueryException::class);
});

test('credit notes use CN series negative lines and leave the original invoice document untouched', function () {
    Storage::fake('local');
    $fixture = f4Fixture();
    $item = f4Item($fixture['catalog'], ['unit_price_minor' => 1000, 'vat_rate_bp' => 810]);
    $charge = f4Charge($fixture['patient'], $fixture['branch'], $fixture['catalog'], $item, $fixture['actor'], 2);
    $invoice = f4Issue($fixture, [$charge]);
    $originalSnapshot = $invoice->only(['status', 'number', 'subtotal_minor', 'vat_total_minor', 'total_minor']);
    $sourceLine = $invoice->lines()->firstOrFail();

    expect(fn () => app(IssueService::class)->creditNote($invoice, null, '', $fixture['actor']))
        ->toThrow(InvalidArgumentException::class);

    $creditNote = app(IssueService::class)->creditNote($invoice->refresh(), [[
        'invoice_line_id' => $sourceLine->id,
        'quantity' => 1,
    ]], 'Partial correction', $fixture['actor']);

    $creditLine = $creditNote->lines()->firstOrFail();

    expect($creditNote->series)->toBe(Invoice::SERIES_CREDIT_NOTE)
        ->and($creditNote->number)->toBe('1')
        ->and($creditNote->credit_note_for_invoice_id)->toBe($invoice->id)
        ->and($creditLine->quantity)->toBe(-1)
        ->and($creditLine->line_total_minor)->toBe(-1000)
        ->and($creditLine->line_vat_minor)->toBe(-81)
        ->and($creditLine->original_invoice_line_id)->toBe($sourceLine->id)
        ->and($invoice->refresh()->only(['status', 'number', 'subtotal_minor', 'vat_total_minor', 'total_minor']))->toBe($originalSnapshot)
        ->and(f4AuditRows($fixture['tenant']->id, 'invoice.credit_note_created'))->toHaveCount(1);
});

test('issued invoice lines are self-contained and PDF is private tenant-prefixed storage', function () {
    Storage::fake('local');
    $fixture = f4Fixture();
    $item = f4Item($fixture['catalog'], [
        'code' => 'SNAP',
        'description' => 'Snapshot line',
        'unit_price_minor' => 1234,
        'vat_rate_bp' => 810,
    ]);
    $charge = f4Charge($fixture['patient'], $fixture['branch'], $fixture['catalog'], $item, $fixture['actor']);
    $invoice = f4Issue($fixture, [$charge]);

    $item->forceFill([
        'code' => 'CHANGED',
        'description' => 'Changed tariff',
        'unit_price_minor' => 9999,
        'vat_rate_bp' => 1900,
    ])->save();

    try {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('tariff_items')->where('id', $item->id)->delete();
        DB::table('tariff_catalogs')->where('id', $fixture['catalog']->id)->delete();
    } finally {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    $line = $invoice->lines()->firstOrFail();

    expect($line->code)->toBe('SNAP')
        ->and($line->description)->toBe('Snapshot line')
        ->and($line->unit_price_minor)->toBe(1234)
        ->and($line->vat_rate_bp)->toBe(810)
        ->and($invoice->pdf_path)->toStartWith('tenants/'.$fixture['tenant']->id.'/billing/invoices/')
        ->and(Storage::disk('local')->exists($invoice->pdf_path))->toBeTrue();

    $this->get('/storage/'.$invoice->pdf_path)->assertForbidden();
});

test('invoice issue is tenant isolated audited fail closed and RBAC guarded', function () {
    Storage::fake('local');
    $alpha = f4Fixture('alpha');
    $item = f4Item($alpha['catalog'], ['code' => 'AUDIT']);
    $charge = f4Charge($alpha['patient'], $alpha['branch'], $alpha['catalog'], $item, $alpha['actor']);

    $reception = f4User($alpha['tenant'], 'reception');
    expect(fn () => app(IssueService::class)->createDraftFromCharges($alpha['patient'], [$charge], $reception))
        ->toThrow(AuthorizationException::class);

    $invoice = f4Issue($alpha, [$charge]);

    f4Fixture('beta');

    expect(Invoice::query()->whereKey($invoice->id)->exists())->toBeFalse();

    f4Ctx()->set($alpha['tenant']);

    expect(Invoice::query()->whereKey($invoice->id)->exists())->toBeTrue()
        ->and(f4AuditRows($alpha['tenant']->id, 'invoice.drafted'))->toHaveCount(1)
        ->and(f4AuditRows($alpha['tenant']->id, 'invoice.issued'))->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($alpha['tenant']->id)['ok'])->toBeTrue();

    f4Ctx()->forget();

    expect(fn () => Invoice::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => InvoiceBalance::query()->count())->toThrow(TenantContextMissingException::class);
});
