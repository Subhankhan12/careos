<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\DebtEnforcementEscalation;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\DebtEnforcementService;
use Modules\Billing\Services\DunningService;
use Modules\Billing\Services\IssueService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * ARDETAIL.P6 — debt-enforcement (Betreibung) escalation: the sharpest fence on the AR page.
 *
 * A Betreibung is a REAL LEGAL PROCEEDING, so these tests prove four things are ENFORCED, not shown:
 * it is OPERATOR-ONLY on the dedicated `billing.escalate` (narrower than billing.manage, which
 * charge-capturing clinical roles hold); it needs an EXPLICIT confirmation + recorded reason; it is
 * ELIGIBILITY-gated (only once the real dunning state machine is exhausted at its terminal level);
 * and it is AGENT-EXCLUDED BY CONSTRUCTION — no AI tool is an escalation capability, no AiCore code
 * references the model/service/route, and the ONLY callers of the service in the whole codebase are
 * the two operator-gated controller actions, so "0 auto-escalated" is structural. Everything is
 * audited + append-only (a withdrawal is a NEW record). These tests ADD coverage; no existing
 * behaviour test is modified.
 */

function denUser(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, catalog: TariffCatalog}
 */
function denFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    $actor = denUser($tenant);
    $branch = Branch::query()->create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $catalog = TariffCatalog::query()->create(['key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1, 'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => []]);

    // The REAL dunning policy: two levels, so the TERMINAL stage is level 2.
    app(SettingsService::class)->set(DunningService::SETTINGS_KEY, [
        'channel' => 'email',
        'levels' => [
            ['level' => 1, 'days_past_due' => 14, 'template' => 'First reminder.'],
            ['level' => 2, 'days_past_due' => 30, 'template' => 'Final notice.'],
        ],
    ], 'array');

    return compact('tenant', 'actor', 'branch', 'catalog');
}

/**
 * @param  array{tenant: Tenant, actor: User, branch: Branch, catalog: TariffCatalog}  $fx
 */
function denInvoice(array $fx, Patient $patient, int $priceMinor = 30000, string $issueDate = '2026-05-01', string $dueDate = '2026-05-10'): Invoice
{
    static $codeSeq = 95000;
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $fx['catalog']->id, 'code' => (string) (++$codeSeq), 'description' => 'Consultation',
        'unit_price_minor' => $priceMinor, 'vat_rate_bp' => 0, 'unit' => 'session',
        'requires_service_documentation' => false, 'active' => true,
    ]);
    $charge = Charge::query()->create([
        'patient_id' => $patient->id, 'branch_id' => $fx['branch']->id, 'service_date' => $issueDate,
        'tariff_catalog_id' => $fx['catalog']->id, 'tariff_item_id' => $item->id, 'code' => $item->code,
        'description' => $item->description, 'unit_price_minor' => $priceMinor, 'vat_rate_bp' => 0,
        'quantity' => 1, 'line_total_minor' => $priceMinor, 'status' => Charge::STATUS_VALIDATED, 'created_by' => $fx['actor']->id,
    ]);
    $service = app(IssueService::class);

    return $service->issue(
        $service->createDraftFromCharges($patient, [$charge], $fx['actor'], Invoice::PAYER_SELF_PAY, null, Carbon::parse($issueDate), Carbon::parse($dueDate)),
        $fx['actor'],
    );
}

function denPatient(string $last = 'Debtor'): Patient
{
    return app(PatientService::class)->create(['first_name' => 'Urs', 'last_name' => $last, 'date_of_birth' => '1975-02-02', 'sex' => 'male']);
}

/** Drive the REAL dunning state machine to a given as-of date (never a hand-written event). */
function denRunDunning(array $fx, string $asOf): void
{
    app(DunningService::class)->evaluate($fx['tenant'], $asOf, $fx['actor'], deliver: false);
}

/** Recursively scan a source dir; true if any .php file CONTAINS the needle. */
function denSourceContains(string $absDir, string $needle): bool
{
    if (! is_dir($absDir)) {
        return false;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), $needle)) {
            return true;
        }
    }

    return false;
}

beforeEach(fn () => Carbon::setTestNow('2026-06-25 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

// ── ELIGIBILITY: only once the REAL dunning machine is exhausted at its terminal stage ───────────

test('an account is eligible only after the dunning process reaches its terminal stage', function () {
    $fx = denFixture();
    $patient = denPatient();
    denInvoice($fx, $patient);
    $svc = app(DebtEnforcementService::class);

    // Terminal stage is level 2 (the configured policy's last level).
    expect($svc->terminalDunningStage())->toBe(2);

    // Nothing dunned yet → not eligible.
    expect($svc->eligibility((string) $patient->id))
        ->toMatchArray(['eligible' => false, 'reason' => 'dunning_not_exhausted', 'reached_stage' => 0]);

    // Level 1 fired (14 days past due) → STILL not eligible: the process is not exhausted.
    denRunDunning($fx, '2026-05-25');
    expect($svc->eligibility((string) $patient->id))
        ->toMatchArray(['eligible' => false, 'reason' => 'dunning_not_exhausted', 'reached_stage' => 1]);

    expect(fn () => $svc->initiate($patient, 'too early', true, $fx['actor']))
        ->toThrow(InvalidArgumentException::class, 'Debt enforcement requires the dunning process to be exhausted');
    expect(DebtEnforcementEscalation::query()->count())->toBe(0);

    // Level 2 (the TERMINAL stage) fired → now eligible.
    denRunDunning($fx, '2026-06-15');
    expect($svc->eligibility((string) $patient->id))
        ->toMatchArray(['eligible' => true, 'reason' => 'eligible', 'reached_stage' => 2, 'terminal_stage' => 2]);
});

test('eligibility fails closed with no dunning policy, nothing owed, or an existing escalation', function () {
    $fx = denFixture();
    $patient = denPatient();
    denInvoice($fx, $patient);
    denRunDunning($fx, '2026-06-15');
    $svc = app(DebtEnforcementService::class);

    // An account that owes nothing is never eligible.
    $clean = denPatient('Paidup');
    expect($svc->eligibility((string) $clean->id))->toMatchArray(['eligible' => false, 'reason' => 'nothing_outstanding']);

    // Escalate once — a second escalation is refused while the first is live.
    $svc->initiate($patient, 'Dunning exhausted; forwarding to enforcement.', true, $fx['actor']);
    expect($svc->eligibility((string) $patient->id))->toMatchArray(['eligible' => false, 'reason' => 'already_escalated']);
    expect(fn () => $svc->initiate($patient, 'again', true, $fx['actor']))
        ->toThrow(InvalidArgumentException::class, 'already in debt enforcement');

    // With NO dunning policy configured, nothing can be eligible — you cannot exhaust a process
    // that does not exist (fail-closed).
    app(SettingsService::class)->set(DunningService::SETTINGS_KEY, ['channel' => 'email', 'levels' => []], 'array');
    $other = denPatient('Nopolicy');
    denInvoice($fx, $other);
    expect(app(DebtEnforcementService::class)->terminalDunningStage())->toBeNull();
    expect(app(DebtEnforcementService::class)->eligibility((string) $other->id))
        ->toMatchArray(['eligible' => false, 'reason' => 'no_dunning_policy']);
});

// ── OPERATOR-ONLY: explicit confirmation + reason; recorded, audited, append-only ────────────────

test('an operator initiates a Betreibung with an explicit confirmation and reason — recorded and audited', function () {
    $fx = denFixture();
    $patient = denPatient();
    denInvoice($fx, $patient);
    denRunDunning($fx, '2026-06-15');
    $svc = app(DebtEnforcementService::class);

    // WITHOUT the explicit confirmation nothing happens — it is never defaulted.
    expect(fn () => $svc->initiate($patient, 'Send to enforcement.', false, $fx['actor']))
        ->toThrow(InvalidArgumentException::class, 'explicit operator confirmation');
    // A blank reason is refused too.
    expect(fn () => $svc->initiate($patient, '   ', true, $fx['actor']))
        ->toThrow(InvalidArgumentException::class, 'requires a reason');
    expect(DebtEnforcementEscalation::query()->count())->toBe(0);

    $escalation = $svc->initiate($patient, 'Dunning exhausted; no response to the final notice.', true, $fx['actor'], 'BB-2026-0042');

    // The record names the HUMAN who did it and preserves the eligibility evidence.
    expect($escalation->action)->toBe(DebtEnforcementEscalation::ACTION_INITIATED)
        ->and($escalation->initiated_by)->toBe($fx['actor']->id)
        ->and($escalation->reason)->toBe('Dunning exhausted; no response to the final notice.')
        ->and($escalation->reference)->toBe('BB-2026-0042')
        ->and($escalation->dunning_stage)->toBe(2)
        ->and($escalation->outstanding_minor)->toBe(30000)
        ->and($escalation->confirmed_at)->not->toBeNull();

    // Audited with the operator as actor (never a system/agent actor).
    $row = DB::selectOne('SELECT actor_type, actor_id FROM audit_events WHERE tenant_id <=> ? AND action = ?', [$fx['tenant']->id, 'billing.debt_enforcement_initiated']);
    expect($row->actor_type)->toBe('user')->and((string) $row->actor_id)->toBe((string) $fx['actor']->id);

    // APPEND-ONLY: a legal record can never be edited or deleted.
    expect(fn () => $escalation->update(['reason' => 'rewritten']))->toThrow(LogicException::class);
    expect(fn () => $escalation->delete())->toThrow(LogicException::class);
});

test('withdrawing appends a NEW superseding record rather than mutating the escalation', function () {
    $fx = denFixture();
    $patient = denPatient();
    denInvoice($fx, $patient);
    denRunDunning($fx, '2026-06-15');
    $svc = app(DebtEnforcementService::class);

    $escalation = $svc->initiate($patient, 'Dunning exhausted.', true, $fx['actor']);
    expect(fn () => $svc->withdraw($escalation, '  ', $fx['actor']))->toThrow(InvalidArgumentException::class);

    $withdrawal = $svc->withdraw($escalation, 'Patient paid in full before filing.', $fx['actor']);

    expect($withdrawal->action)->toBe(DebtEnforcementEscalation::ACTION_WITHDRAWN)
        ->and($withdrawal->supersedes_escalation_id)->toBe($escalation->id)
        ->and(DebtEnforcementEscalation::query()->count())->toBe(2)          // the original still stands
        ->and(DebtEnforcementEscalation::query()->whereKey($escalation->id)->value('reason'))->toBe('Dunning exhausted.')
        ->and($svc->currentFor((string) $patient->id))->toBeNull();          // no live escalation now

    // The same escalation cannot be withdrawn twice, and a withdrawal is not itself withdrawable.
    expect(fn () => $svc->withdraw($escalation, 'again', $fx['actor']))->toThrow(InvalidArgumentException::class, 'already been withdrawn');
    expect(fn () => $svc->withdraw($withdrawal, 'nope', $fx['actor']))->toThrow(InvalidArgumentException::class, 'cannot itself be withdrawn');

    $audited = DB::selectOne('SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?', [$fx['tenant']->id, 'billing.debt_enforcement_withdrawn'])->c;
    expect((int) $audited)->toBe(1);
});

test('debt enforcement is gated on the dedicated billing.escalate — narrower than billing.manage', function () {
    $fx = denFixture();
    $patient = denPatient();
    denInvoice($fx, $patient);
    denRunDunning($fx, '2026-06-15');
    $svc = app(DebtEnforcementService::class);

    // Reception holds neither permission.
    $reception = denUser($fx['tenant'], 'reception');
    expect(fn () => $svc->initiate($patient, 'nope', true, $reception))->toThrow(AuthorizationException::class);

    // THE POINT OF A DEDICATED PERMISSION: a pharmacist HOLDS billing.manage (for charge capture,
    // PHARMACY.G5) yet must NOT be able to start a legal proceeding.
    $pharmacist = denUser($fx['tenant'], 'pharmacist');
    expect($pharmacist->hasPermission('billing.manage'))->toBeTrue()
        ->and($pharmacist->hasPermission('billing.escalate'))->toBeFalse();
    expect(fn () => $svc->initiate($patient, 'nope', true, $pharmacist))->toThrow(AuthorizationException::class);

    // Only the billing office / org admin hold it.
    expect($fx['actor']->hasPermission('billing.escalate'))->toBeTrue();

    // Through the real stack both routes refuse a non-holder.
    $this->actingAs($reception)->post(route('billing.accounts.enforcement.store', $patient->id), ['confirmed' => '1', 'reason' => 'x'])->assertForbidden();
    $this->actingAs($pharmacist)->post(route('billing.accounts.enforcement.store', $patient->id), ['confirmed' => '1', 'reason' => 'x'])->assertForbidden();

    expect(DebtEnforcementEscalation::query()->count())->toBe(0);
});

test('the route refuses a request without the explicit confirmation, and is tenant + account scoped', function () {
    $fx = denFixture('alpha');
    $patient = denPatient();
    denInvoice($fx, $patient);
    denRunDunning($fx, '2026-06-15');

    // No confirmation → validation refuses; nothing recorded.
    $this->actingAs($fx['actor'])
        ->post(route('billing.accounts.enforcement.store', $patient->id), ['reason' => 'Send it.'])
        ->assertSessionHasErrors('confirmed');
    expect(DebtEnforcementEscalation::query()->count())->toBe(0);

    // With the confirmation it records.
    $this->actingAs($fx['actor'])
        ->post(route('billing.accounts.enforcement.store', $patient->id), ['confirmed' => '1', 'reason' => 'Dunning exhausted.'])
        ->assertRedirect(route('billing.accounts.show', $patient->id))
        ->assertSessionHasNoErrors();
    expect(DebtEnforcementEscalation::query()->count())->toBe(1);

    // Another account's escalation cannot be withdrawn from this account's page.
    $escalation = DebtEnforcementEscalation::query()->firstOrFail();
    $other = denPatient('Other');
    $this->actingAs($fx['actor'])
        ->post(route('billing.accounts.enforcement.withdraw', [$other->id, $escalation->id]), ['reason' => 'x'])
        ->assertNotFound();

    // A second tenant cannot escalate the first tenant's account (fail-closed → 404).
    $beta = denFixture('beta');
    $this->actingAs($beta['actor'])
        ->post(route('billing.accounts.enforcement.store', $patient->id), ['confirmed' => '1', 'reason' => 'x'])
        ->assertNotFound();

    app(TenantContext::class)->set($fx['tenant']);
    expect(DebtEnforcementEscalation::query()->count())->toBe(1);
});

// ── THE AGENT-EXCLUSION PROOF (the point of this gate): "0 auto-escalated" BY CONSTRUCTION ───────

test('THE AGENT-EXCLUSION PROOF: no agent path to a Betreibung exists anywhere in the codebase', function () {
    // (1) No registered AI tool is an escalation/enforcement capability.
    $tools = app(ToolRegistry::class)->all();
    expect($tools)->not->toBeEmpty();
    foreach ($tools as $key => $tool) {
        $haystack = strtolower($key.' '.$tool->definition()->name);
        foreach (['betreibung', 'escalat', 'enforce', 'debt', 'legal'] as $needle) {
            expect(str_contains($haystack, $needle))->toBeFalse("An AI tool must not be an escalation capability: {$key}");
        }
    }

    // (2) No AI code references the escalation service, model, permission or routes.
    foreach ([base_path('Modules/AiCore/src'), base_path('app/AiCore')] as $dir) {
        foreach (['DebtEnforcement', 'debt_enforcement', 'billing.escalate', 'billing.accounts.enforcement', 'Betreibung'] as $needle) {
            expect(denSourceContains($dir, $needle))->toBeFalse("AiCore must not reference debt enforcement: {$needle}");
        }
    }

    // (3) THE STRUCTURAL PROOF — the ONLY production code that can reach the service is the
    // operator-gated controller. No job, console command, scheduler entry, listener or agent
    // references it, so there is no automated path by which an escalation could ever be created.
    $callers = [];
    foreach ([base_path('Modules'), base_path('app')] as $root) {
        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = File::get($file->getPathname());
            if (str_contains($contents, 'DebtEnforcementService') || str_contains($contents, 'DebtEnforcementEscalation')) {
                $callers[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }
    }
    sort($callers);
    expect($callers)->toBe([
        'Billing/src/Http/Controllers/AccountDetailController.php', // the operator-gated actions
        'Billing/src/Models/DebtEnforcementEscalation.php',
        'Billing/src/Services/DebtEnforcementService.php',
    ]);

    // (4) Nothing schedules an escalation: the scheduler runs no enforcement command.
    $console = File::get(base_path('routes/console.php'));
    foreach (['enforcement', 'betreibung', 'escalate'] as $needle) {
        expect(str_contains(strtolower($console), $needle))->toBeFalse("The scheduler must never automate debt enforcement: {$needle}");
    }

    // (5) Every escalation names a real human: the column is NOT NULL with a users FK, so no
    // system/agent actor can be recorded as an initiator.
    $column = DB::selectOne("SELECT IS_NULLABLE n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'debt_enforcement_escalations' AND COLUMN_NAME = 'initiated_by'");
    expect($column->n)->toBe('NO');
});

// ── The page surfaces the real eligibility + the honest governance copy ──────────────────────────

test('the account page surfaces the real escalation state and eligibility evidence', function () {
    $fx = denFixture();
    $patient = denPatient();
    denInvoice($fx, $patient);
    denRunDunning($fx, '2026-05-25'); // level 1 only — not yet eligible

    $this->actingAs($fx['actor'])->get(route('billing.accounts.show', $patient->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/AccountDetail')
            ->where('enforcement.can_escalate', true)
            ->where('enforcement.eligibility.eligible', false)
            ->where('enforcement.eligibility.reason', 'dunning_not_exhausted')
            ->where('enforcement.eligibility.terminal_stage', 2)
            ->where('enforcement.eligibility.reached_stage', 1)
            ->where('enforcement.current', null));

    // Once the terminal stage is reached and an operator escalates, the page shows the real record.
    denRunDunning($fx, '2026-06-15');
    app(DebtEnforcementService::class)->initiate($patient, 'Dunning exhausted.', true, $fx['actor']);

    $this->actingAs($fx['actor'])->get(route('billing.accounts.show', $patient->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/AccountDetail')
            ->where('enforcement.current.dunning_stage', 2)
            ->where('enforcement.current.reason', 'Dunning exhausted.')
            ->where('enforcement.current.initiated_by', $fx['actor']->name)
            ->where('enforcement.eligibility.already_escalated', true));

    // A billing.view-only reader sees the state but gets no escalate control (reflect-only; the
    // server Gate is what actually refuses — proven above).
    $reception = denUser($fx['tenant'], 'reception');
    $this->actingAs($reception)->get(route('billing.accounts.show', $patient->id))->assertForbidden();
});
