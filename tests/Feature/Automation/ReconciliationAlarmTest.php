<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\ReconciliationRun;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\ReconciliationAlarm;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/**
 * The launch-blocker rule (AGENTS.md) says a period must reconcile to the unit
 * before real invoicing. The scheduled daily run is what makes that a standing
 * check instead of a one-off — but only if a failure is impossible to miss.
 */

/**
 * @return array{tenant: Tenant, actor: User, invoice: Invoice}
 */
function p2AlarmFixture(string $slug = 'alarm-clinic'): array
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    app(TenantContext::class)->set($tenant);

    $actor = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $actor->id,
        'role_id' => Role::query()
            ->where('tenant_id', $tenant->id)
            ->where('key', 'billing')
            ->firstOrFail()->id,
    ]);

    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Recon',
        'last_name' => 'Patient',
        'date_of_birth' => '1980-05-05',
        'sex' => 'female',
    ]);
    $catalog = TariffCatalog::query()->create([
        'key' => 'eu-generic',
        'name' => 'EU Generic',
        'version' => 1,
        'valid_from' => '2026-01-01',
        'status' => TariffCatalog::STATUS_ACTIVE,
        'rules' => [],
    ]);
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id,
        'code' => 'CONS',
        'description' => 'Consultation',
        'unit_price_minor' => 1000,
        'vat_rate_bp' => 0,
        'unit' => 'session',
        'requires_service_documentation' => false,
        'active' => true,
    ]);
    $charge = Charge::query()->create([
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'service_date' => '2026-07-01',
        'tariff_catalog_id' => $catalog->id,
        'tariff_item_id' => $item->id,
        'code' => $item->code,
        'description' => $item->description,
        'unit_price_minor' => 1000,
        'vat_rate_bp' => 0,
        'quantity' => 1,
        'line_total_minor' => 1000,
        'status' => Charge::STATUS_VALIDATED,
        'created_by' => $actor->id,
    ]);

    $issuer = app(IssueService::class);
    $invoice = $issuer->issue(
        $issuer->createDraftFromCharges(
            $patient,
            [$charge],
            $actor,
            Invoice::PAYER_SELF_PAY,
            null,
            Carbon::parse('2026-07-05'),
            Carbon::parse('2026-07-19'),
        ),
        $actor,
    );

    return compact('tenant', 'actor', 'invoice');
}

test('a clean scheduled reconcile passes, records the artifact, and raises no alarm', function () {
    Storage::fake('local');
    Carbon::setTestNow('2026-07-15 06:30:00');

    $fx = p2AlarmFixture();

    app(TenantContext::class)->forget();
    expect(Artisan::call('billing:reconcile'))->toBe(0);

    app(TenantContext::class)->set($fx['tenant']);
    $run = ReconciliationRun::query()->where('period', '2026-07')->firstOrFail();

    expect($run->passed)->toBeTrue()
        ->and(app(SettingsService::class)->get(ReconciliationAlarm::SETTINGS_KEY))->toBeNull();

    Carbon::setTestNow();
});

test('a failing scheduled reconcile writes a failed run, logs at error level, and sets the alarm flag', function () {
    Storage::fake('local');
    Carbon::setTestNow('2026-07-15 06:30:00');

    $fx = p2AlarmFixture();

    // Drift the mutable projection with no payment behind it => I2 fails.
    $fx['invoice']->balance()->firstOrFail()->forceFill(['open_balance_minor' => 970])->save();

    Log::spy();
    app(TenantContext::class)->forget();

    // Non-zero exit so the runner sees it too.
    expect(Artisan::call('billing:reconcile'))->toBe(1);

    app(TenantContext::class)->set($fx['tenant']);

    // 1. The append-only evidence row.
    $run = ReconciliationRun::query()->where('period', '2026-07')->firstOrFail();
    expect($run->passed)->toBeFalse();

    // 2. The error-level log a drain can alert on.
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'reconciliation FAILED')
            && $context['tenant_id'] === $fx['tenant']->id
            && $context['period'] === '2026-07'
            && in_array('I2', $context['failed_invariants'], true))
        ->once();

    // 3. The persisted flag an admin surface can read later, without scanning
    //    run history. No UI is built for it here.
    $alarm = app(SettingsService::class)->get(ReconciliationAlarm::SETTINGS_KEY);

    expect($alarm['period'])->toBe('2026-07')
        ->and($alarm['failed_invariants'])->toContain('I2')
        ->and($alarm['reconciliation_run_id'])->toBe($run->id)
        ->and($alarm['failed_at'])->not->toBeNull();

    Carbon::setTestNow();
});

test('the alarm clears only when the same period later passes', function () {
    Storage::fake('local');
    Carbon::setTestNow('2026-07-15 06:30:00');

    $fx = p2AlarmFixture();
    $balance = $fx['invoice']->balance()->firstOrFail();
    $balance->forceFill(['open_balance_minor' => 970])->save();

    app(TenantContext::class)->forget();
    Artisan::call('billing:reconcile');
    app(TenantContext::class)->set($fx['tenant']);
    expect(app(SettingsService::class)->get(ReconciliationAlarm::SETTINGS_KEY))->not->toBeNull();

    // A DIFFERENT period passing must not clear a broken July: the drift is
    // still there and still unfixed.
    Carbon::setTestNow('2026-08-15 06:30:00');
    app(TenantContext::class)->forget();
    Artisan::call('billing:reconcile');
    app(TenantContext::class)->set($fx['tenant']);
    expect(app(SettingsService::class)->get(ReconciliationAlarm::SETTINGS_KEY)['period'])->toBe('2026-07');

    // Fix the drift, reconcile July again => the alarm clears.
    $balance->forceFill(['open_balance_minor' => 1000])->save();
    app(TenantContext::class)->forget();
    Artisan::call('billing:reconcile', ['tenant' => $fx['tenant']->id, 'period' => '2026-07', 'actorId' => $fx['actor']->id]);
    app(TenantContext::class)->set($fx['tenant']);

    expect(app(SettingsService::class)->get(ReconciliationAlarm::SETTINGS_KEY))->toBeNull();

    Carbon::setTestNow();
});

test('an unattended reconcile skips a tenant with no billing manager rather than escalating', function () {
    Storage::fake('local');
    Carbon::setTestNow('2026-07-15 06:30:00');

    $fx = p2AlarmFixture('no-manager-clinic');

    // The invoice needed a billing manager to exist; the tenant then loses one
    // (staff leave). The unattended sweep now has nobody to act as.
    RoleAssignment::query()->where('user_id', $fx['actor']->id)->delete();

    app(TenantContext::class)->forget();
    expect(Artisan::call('billing:reconcile'))->toBe(0);

    app(TenantContext::class)->set($fx['tenant']);

    // Nobody holds billing.manage, so nothing ran — the run was never executed
    // by a user who lacks the permission.
    expect(ReconciliationRun::query()->count())->toBe(0);

    Carbon::setTestNow();
});
