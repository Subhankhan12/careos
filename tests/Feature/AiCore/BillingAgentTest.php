<?php

use App\AiCore\Agents\BillingAgent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\AiInteraction;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\KillSwitch;
use Modules\AiCore\Services\ToolDefinition;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\ChargeCaptureService;
use Modules\Billing\Services\ChargeValidator;
use Modules\Billing\Services\TariffResolver;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitNote;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource;

uses(RefreshDatabase::class);

function f8Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function f8User(Tenant $tenant, string $role = 'billing'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

/**
 * @param  list<array<string, mixed>>  $rules
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, staff: StaffProfile, catalog: TariffCatalog}
 */
function f8Fixture(string $slug = 'alpha', array $rules = []): array
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Care',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    f8Ctx()->set($tenant);

    $actor = f8User($tenant);
    $branch = Branch::query()->create([
        'name' => strtoupper(substr($slug, 0, 4)).' Branch',
        'code' => strtoupper(substr($slug, 0, 4)),
        'timezone' => 'Europe/Zurich',
    ]);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Billing',
        'last_name' => 'Agent',
        'date_of_birth' => '1985-01-01',
        'sex' => 'female',
    ]);
    $staff = StaffProfile::query()->create([
        'user_id' => $actor->id,
        'first_name' => 'Billing',
        'last_name' => 'Doctor',
        'display_name' => 'Billing Doctor',
        'profession' => 'doctor',
        'primary_branch_id' => $branch->id,
        'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $catalog = TariffCatalog::query()->create([
        'key' => 'eu-generic',
        'name' => 'EU Generic',
        'version' => 1,
        'valid_from' => '2026-01-01',
        'status' => TariffCatalog::STATUS_ACTIVE,
        'rules' => $rules,
    ]);

    return compact('tenant', 'actor', 'branch', 'patient', 'staff', 'catalog');
}

function f8Item(array $fx, string $code, string $description, int $price): TariffItem
{
    return TariffItem::query()->create([
        'tariff_catalog_id' => $fx['catalog']->id,
        'code' => $code,
        'description' => $description,
        'unit_price_minor' => $price,
        'vat_rate_bp' => 0,
        'unit' => 'session',
        'requires_service_documentation' => false,
        'active' => true,
    ]);
}

function f8Encounter(array $fx, string $planText, ?Patient $patient = null): Encounter
{
    $patient ??= $fx['patient'];

    $encounter = Encounter::query()->create([
        'patient_id' => $patient->id,
        'practitioner_id' => $fx['staff']->id,
        'branch_id' => $fx['branch']->id,
        'appointment_id' => null,
        'type' => Encounter::TYPE_CONSULTATION,
        'started_at' => '2026-06-10 10:00:00',
        'status' => Encounter::STATUS_OPEN,
        'reason_for_visit' => 'Billing agent fixture',
    ]);

    ClinicalNote::query()->create([
        'encounter_id' => $encounter->id,
        'patient_id' => $patient->id,
        'author_id' => $fx['staff']->id,
        'subjective' => 'Patient attended as planned.',
        'objective' => 'Findings recorded.',
        'assessment' => 'Stable presentation documented.',
        'plan' => $planText,
        'status' => ClinicalNote::STATUS_SIGNED,
        'signed_at' => '2026-06-10 11:00:00',
        'signed_by' => $fx['actor']->id,
        'version' => 1,
    ]);

    return $encounter;
}

function f8CompletedVisit(array $fx, string $noteBody): Visit
{
    $resource = Resource::query()->create([
        'type' => Resource::TYPE_PRACTITIONER,
        'name' => 'Billing Nurse Resource',
        'staff_profile_id' => $fx['staff']->id,
        'branch_id' => $fx['branch']->id,
        'active' => true,
    ]);

    $visit = Visit::query()->create([
        'planned_visit_id' => null,
        'patient_id' => $fx['patient']->id,
        'resource_id' => $resource->id,
        'branch_id' => $fx['branch']->id,
        'scheduled_start_at' => '2026-06-11 09:00:00',
        'checked_in_at' => '2026-06-11 09:05:00',
        'checked_out_at' => '2026-06-11 10:05:00',
        'status' => Visit::STATUS_COMPLETED,
        'client_visit_uuid' => 'billing-agent-'.strtolower((string) Str::ulid()),
    ]);

    VisitNote::query()->create([
        'visit_id' => $visit->id,
        'patient_id' => $fx['patient']->id,
        'body' => $noteBody,
        'author_resource_id' => $resource->id,
        'recorded_at' => '2026-06-11 10:00:00',
    ]);

    return $visit;
}

function f8DraftCharge(array $fx, Patient $patient, TariffItem $item, string $serviceDate, int $quantity = 1): Charge
{
    return Charge::query()->create([
        'patient_id' => $patient->id,
        'branch_id' => $fx['branch']->id,
        'service_date' => $serviceDate,
        'tariff_catalog_id' => $fx['catalog']->id,
        'tariff_item_id' => $item->id,
        'code' => $item->code,
        'description' => $item->description,
        'unit_price_minor' => $item->unit_price_minor,
        'vat_rate_bp' => $item->vat_rate_bp,
        'quantity' => $quantity,
        'line_total_minor' => $quantity * $item->unit_price_minor,
        'status' => Charge::STATUS_DRAFT,
        'created_by' => $fx['actor']->id,
    ]);
}

/**
 * @param  list<array{charge_id: string, rule: string, reason_code: string, message: string}>  $violations
 * @return list<string>
 */
function f8Canonical(array $violations): array
{
    $keys = array_map(
        fn (array $violation): string => $violation['charge_id'].'|'.$violation['rule'].'|'.$violation['reason_code'],
        $violations,
    );
    sort($keys);

    return $keys;
}

test('suggestions are generated source-linked to the signed note and go to the approval queue', function () {
    $fx = f8Fixture();
    f8Item($fx, 'CONS', 'standard consultation', 5000);
    f8Item($fx, 'DRESS', 'wound dressing change', 3000);
    f8Item($fx, 'XRAY', 'radiographic image', 8000);
    $encounter = f8Encounter($fx, 'Performed standard consultation and completed a wound dressing change.');

    $result = app(BillingAgent::class)->suggestChargeCodes([
        'source_type' => 'encounter',
        'source_id' => $encounter->id,
    ], $fx['actor']);

    /** @var AgentAction $action */
    $action = $result['action'];
    $suggestions = collect($action->proposed_output['suggestions']);
    $note = ClinicalNote::query()->where('encounter_id', $encounter->id)->firstOrFail();

    expect($result['status'])->toBe('pending')
        ->and($action->status)->toBe(AgentAction::STATUS_PENDING)
        ->and($suggestions->pluck('code')->sort()->values()->all())->toBe(['CONS', 'DRESS'])
        ->and($suggestions->pluck('code')->all())->not->toContain('XRAY')
        ->and($suggestions->every(fn (array $s): bool => $s['rationale']['source_id'] === $note->id))->toBeTrue()
        ->and($suggestions->every(fn (array $s): bool => mb_stripos($note->plan, $s['rationale']['source_text']) !== false))->toBeTrue()
        ->and($suggestions->firstWhere('code', 'CONS')['unit_price_minor'])->toBe(5000)
        ->and($action->proposed_output['prices_from'])->toBe(TariffResolver::class);
});

test('a completed visit maps suggestions from visit note text', function () {
    $fx = f8Fixture();
    f8Item($fx, 'HOMECARE', 'home nursing care session', 4500);
    $visit = f8CompletedVisit($fx, 'Delivered a home nursing care session and documented condition.');

    $result = app(BillingAgent::class)->suggestChargeCodes([
        'source_type' => 'visit',
        'source_id' => $visit->id,
    ], $fx['actor']);

    /** @var AgentAction $action */
    $action = $result['action'];

    expect($result['status'])->toBe('pending')
        ->and(collect($action->proposed_output['suggestions'])->pluck('code')->all())->toBe(['HOMECARE'])
        ->and($action->proposed_output['suggestions'][0]['rationale']['source_type'])->toBe('visit_note');
});

test('an unsourced suggestion is rejected in code before it reaches the approval queue', function () {
    $fx = f8Fixture();
    f8Item($fx, 'CONS', 'standard consultation', 5000);
    $encounter = f8Encounter($fx, 'Performed standard consultation only.');

    expect(fn () => app(BillingAgent::class)->suggestChargeCodes([
        'source_type' => 'encounter',
        'source_id' => $encounter->id,
        'suggestions' => [[
            'code' => 'CONS',
            'quantity' => 1,
            'rationale' => ['source_text' => 'Patient received acupuncture therapy'],
        ]],
    ], $fx['actor']))->toThrow(AiCoreException::class);

    expect(AgentAction::query()->count())->toBe(0)
        ->and(Charge::query()->count())->toBe(0)
        ->and(AiInteraction::query()->where('outcome', 'invalid_proposal')->count())->toBe(1);
});

test('accepting a suggestion captures through ChargeCaptureService and the tariff price wins over the agent price', function () {
    $fx = f8Fixture();
    f8Item($fx, 'CONS', 'standard consultation', 5000);
    $encounter = f8Encounter($fx, 'Performed standard consultation only.');

    // The "LLM" claims a wrong price of 1 minor unit; it must be ignored.
    $result = app(BillingAgent::class)->suggestChargeCodes([
        'source_type' => 'encounter',
        'source_id' => $encounter->id,
        'suggestions' => [[
            'code' => 'CONS',
            'quantity' => 2,
            'unit_price_minor' => 1,
            'rationale' => ['source_text' => 'standard consultation'],
        ]],
    ], $fx['actor']);

    /** @var AgentAction $action */
    $action = $result['action'];

    expect($action->proposed_output['suggestions'][0]['unit_price_minor'])->toBe(5000);

    $approved = app(ApprovalQueue::class)->approve($action, $fx['actor']);
    $charge = Charge::query()->firstOrFail();

    expect($approved->status)->toBe(AgentAction::STATUS_EXECUTED)
        ->and($approved->result['executed_via'])->toBe(ChargeCaptureService::class)
        ->and($charge->code)->toBe('CONS')
        ->and($charge->quantity)->toBe(2)
        ->and($charge->unit_price_minor)->toBe(5000)
        ->and($charge->line_total_minor)->toBe(10000)
        ->and($charge->tariff_item_id)->toBe(TariffItem::query()->where('code', 'CONS')->firstOrFail()->id)
        ->and(Charge::query()->where('unit_price_minor', 1)->count())->toBe(0);
});

test('nothing is captured while pending and a rejected suggestion never captures', function () {
    $fx = f8Fixture();
    f8Item($fx, 'CONS', 'standard consultation', 5000);
    $encounter = f8Encounter($fx, 'Performed standard consultation only.');

    $result = app(BillingAgent::class)->suggestChargeCodes([
        'source_type' => 'encounter',
        'source_id' => $encounter->id,
    ], $fx['actor']);

    /** @var AgentAction $action */
    $action = $result['action'];

    expect($action->status)->toBe(AgentAction::STATUS_PENDING)
        ->and(Charge::query()->count())->toBe(0);

    app(ApprovalQueue::class)->reject($action, $fx['actor'], 'Not billable this time');

    expect(Charge::query()->count())->toBe(0)
        ->and($action->refresh()->status)->toBe(AgentAction::STATUS_REJECTED);
});

test('preflight never issues an invoice even when approved', function () {
    $fx = f8Fixture();
    $item = f8Item($fx, 'CONS', 'standard consultation', 5000);
    f8DraftCharge($fx, $fx['patient'], $item, '2026-06-10');

    $result = app(BillingAgent::class)->preflightInvoice([
        'patient_id' => $fx['patient']->id,
        'from' => '2026-06-01',
        'to' => '2026-06-30',
    ], $fx['actor']);

    /** @var AgentAction $action */
    $action = $result['action'];

    expect($action->proposed_output['invoice_issued'])->toBeFalse()
        ->and(Invoice::query()->count())->toBe(0);

    $approved = app(ApprovalQueue::class)->approve($action, $fx['actor']);

    expect($approved->result['invoice_issued'])->toBeFalse()
        ->and($approved->result['decided_by'])->toBe(ChargeValidator::class)
        ->and(Invoice::query()->count())->toBe(0);
});

test('fuzzed preflight reports exactly the deterministic validator violations and discards llm claims', function () {
    $fx = f8Fixture('fuzz', [
        ['type' => ChargeValidator::RULE_MAX_QUANTITY_PER_PERIOD, 'code' => 'PHYS', 'max' => 2, 'period' => 'month'],
        ['type' => ChargeValidator::RULE_INCOMPATIBLE_CODES, 'codes' => ['PANO', 'BITE']],
        ['type' => ChargeValidator::RULE_REQUIRES_CODE, 'code' => 'ADDON', 'requires' => 'BASE'],
    ]);
    $items = [
        'PHYS' => f8Item($fx, 'PHYS', 'physiotherapy session', 4000),
        'PANO' => f8Item($fx, 'PANO', 'panoramic radiograph', 9000),
        'BITE' => f8Item($fx, 'BITE', 'bitewing radiograph', 2500),
        'ADDON' => f8Item($fx, 'ADDON', 'anesthesia addon', 1500),
        'BASE' => f8Item($fx, 'BASE', 'base procedure', 6000),
    ];
    $codes = array_keys($items);

    mt_srand(20260710);
    $iterations = 25;
    $totalViolations = 0;
    $iterationsWithViolations = 0;

    for ($i = 0; $i < $iterations; $i++) {
        $patient = app(PatientService::class)->create([
            'first_name' => 'Fuzz',
            'last_name' => 'Case'.$i,
            'date_of_birth' => '1990-01-01',
            'sex' => 'other',
        ]);

        $chargeCount = mt_rand(1, 6);
        for ($c = 0; $c < $chargeCount; $c++) {
            f8DraftCharge(
                $fx,
                $patient,
                $items[$codes[mt_rand(0, count($codes) - 1)]],
                sprintf('2026-06-%02d', mt_rand(1, 4)),
                mt_rand(1, 3),
            );
        }

        $result = app(BillingAgent::class)->preflightInvoice([
            'patient_id' => $patient->id,
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'llm_claims' => [[
                'charge_id' => 'hallucinated-'.$i,
                'reason_code' => 'TOTALLY_MADE_UP_'.$i,
            ]],
        ], $fx['actor']);

        /** @var AgentAction $action */
        $action = $result['action'];
        $agentViolations = $action->proposed_output['violations'];

        // Independent authoritative run of the deterministic validator.
        $authoritative = app(ChargeValidator::class)->validateForPatientPeriod(
            $patient,
            '2026-06-01',
            '2026-06-30',
            $fx['actor'],
        );

        expect(f8Canonical($agentViolations))->toBe(f8Canonical($authoritative['violations']))
            ->and(collect($agentViolations)->pluck('reason_code')->all())->not->toContain('TOTALLY_MADE_UP_'.$i)
            ->and($action->proposed_output['discarded_llm_claims'])->toBe(1)
            ->and($action->proposed_output['decided_by'])->toBe(ChargeValidator::class);

        $totalViolations += count($agentViolations);
        if ($agentViolations !== []) {
            $iterationsWithViolations++;
        }
    }

    expect($iterationsWithViolations)->toBeGreaterThan(0)
        ->and($totalViolations)->toBeGreaterThan(0);
});

test('clinical questions are refused with handoff and create no agent action', function () {
    $fx = f8Fixture();
    f8Item($fx, 'CONS', 'standard consultation', 5000);
    $encounter = f8Encounter($fx, 'Performed standard consultation only.');
    $agent = app(BillingAgent::class);

    $refusals = [
        $agent->suggestChargeCodes(
            ['source_type' => 'encounter', 'source_id' => $encounter->id],
            $fx['actor'],
            'Is this treatment appropriate for the patient?',
        ),
        $agent->suggestChargeCodes(
            ['source_type' => 'encounter', 'source_id' => $encounter->id],
            $fx['actor'],
            'Should we have done a root canal instead?',
        ),
        $agent->preflightInvoice(
            ['patient_id' => $fx['patient']->id, 'from' => '2026-06-01', 'to' => '2026-06-30'],
            $fx['actor'],
            'Is the patient getting worse?',
        ),
    ];

    foreach ($refusals as $refusal) {
        expect($refusal['status'])->toBe('refused')
            ->and($refusal['human_handoff'])->toBeTrue();
    }

    expect(AgentAction::query()->count())->toBe(0)
        ->and(Charge::query()->count())->toBe(0)
        ->and(AiInteraction::query()->where('outcome', 'refused')->count())->toBe(3);
});

test('both billing tools are financial and the ceiling cannot exceed approve', function () {
    f8Fixture();

    $policy = app(AutonomyPolicy::class);
    $suggestTool = app(ToolRegistry::class)->get('billing.suggest_charge_codes');
    $preflightTool = app(ToolRegistry::class)->get('billing.preflight_invoice');

    $policy->set($suggestTool->definition(), AutonomyPolicy::AUTO);
    $policy->set($preflightTool->definition(), AutonomyPolicy::AUTO);

    expect($suggestTool->definition()->category)->toBe(ToolDefinition::CATEGORY_FINANCIAL)
        ->and($preflightTool->definition()->category)->toBe(ToolDefinition::CATEGORY_FINANCIAL)
        ->and($suggestTool->definition()->autonomyCeiling)->toBe(AutonomyPolicy::APPROVE)
        ->and($preflightTool->definition()->autonomyCeiling)->toBe(AutonomyPolicy::APPROVE)
        ->and($policy->levelFor($suggestTool->definition()))->toBe(AutonomyPolicy::APPROVE)
        ->and($policy->levelFor($preflightTool->definition()))->toBe(AutonomyPolicy::APPROVE);
});

test('budget gate and kill switch disable the billing agent and degrade to manual', function () {
    $fx = f8Fixture();
    f8Item($fx, 'CONS', 'standard consultation', 5000);
    $encounter = f8Encounter($fx, 'Performed standard consultation only.');
    $agent = app(BillingAgent::class);

    app(SettingsService::class)->set('ai.monthly_budget_minor', 0, 'int');

    $blocked = $agent->suggestChargeCodes([
        'source_type' => 'encounter',
        'source_id' => $encounter->id,
    ], $fx['actor']);

    expect($blocked['status'])->toBe('budget_blocked')
        ->and($blocked['human_handoff'])->toBeTrue();

    app(SettingsService::class)->set('ai.monthly_budget_minor', 100, 'int');
    app(KillSwitch::class)->disable(BillingAgent::SUGGEST_FEATURE);

    $disabled = $agent->suggestChargeCodes([
        'source_type' => 'encounter',
        'source_id' => $encounter->id,
    ], $fx['actor']);

    expect($disabled['status'])->toBe('disabled')
        ->and($disabled['human_handoff'])->toBeTrue()
        ->and(AiInteraction::query()->whereIn('outcome', ['budget_blocked', 'disabled'])->count())->toBe(2)
        ->and(AgentAction::query()->count())->toBe(0)
        ->and(Charge::query()->count())->toBe(0);
});

test('billing agent paths are ledgered audited patient read-logged RBAC guarded and tenant isolated', function () {
    $alpha = f8Fixture('alpha');
    f8Item($alpha, 'CONS', 'standard consultation', 5000);
    $encounter = f8Encounter($alpha, 'Performed standard consultation only.');

    $reception = f8User($alpha['tenant'], 'reception');
    expect(fn () => app(BillingAgent::class)->suggestChargeCodes([
        'source_type' => 'encounter',
        'source_id' => $encounter->id,
    ], $reception))->toThrow(AuthorizationException::class);

    $result = app(BillingAgent::class)->suggestChargeCodes([
        'source_type' => 'encounter',
        'source_id' => $encounter->id,
    ], $alpha['actor']);

    /** @var AgentAction $action */
    $action = $result['action'];
    app(ApprovalQueue::class)->approve($action, $alpha['actor']);

    $readRows = collect(DB::select(
        "SELECT * FROM audit_events WHERE tenant_id = ? AND action = 'read' AND patient_id = ?",
        [$alpha['tenant']->id, $alpha['patient']->id],
    ));

    expect(AiInteraction::query()->whereIn('outcome', ['proposed', 'approved', 'executed'])->count())->toBe(3)
        ->and($readRows->count())->toBeGreaterThan(0)
        ->and($readRows->filter(fn (object $row): bool => str_contains((string) $row->context, 'billing_agent'))->count())->toBeGreaterThan(0)
        ->and(app(AuditService::class)->verifyChain($alpha['tenant']->id)['ok'])->toBeTrue();

    f8Fixture('beta');

    expect(AgentAction::query()->count())->toBe(0)
        ->and(AiInteraction::query()->count())->toBe(0)
        ->and(Charge::query()->count())->toBe(0);
});
