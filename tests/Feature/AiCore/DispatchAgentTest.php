<?php

use App\AiCore\Agents\DispatchAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\AiInteraction;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\KillSwitch;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Audit\Services\AuditService;
use Modules\Nursing\Models\AgreementService;
use Modules\Nursing\Models\NurseConstraint;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Services\AssignmentValidator;
use Modules\Nursing\Services\ServiceAgreementService;
use Modules\Nursing\Services\VisitAssignmentService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\Service;

uses(RefreshDatabase::class);

function e9Tenant(string $slug): Tenant
{
    return Tenant::query()->create([
        'name' => ucfirst($slug).' Nursing',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function e9Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function e9Role(string $key): Role
{
    return Role::query()->where('key', $key)->firstOrFail();
}

function e9User(Tenant $tenant, string $role = 'coordinator'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => e9Role($role)->id,
    ]);

    return $user;
}

function e9Branch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function e9Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Dispatch',
        'last_name' => 'Agent',
        'date_of_birth' => '1945-01-10',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function e9Service(array $overrides = []): Service
{
    return Service::query()->create([
        'name' => 'Home nursing',
        'code' => 'HOME-NURSING',
        'category' => 'home-care',
        'default_duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => false,
        'active' => true,
        ...$overrides,
    ]);
}

function e9Resource(Branch $branch, string $name, string $qualification = 'RN'): BookableResource
{
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => $name,
        'branch_id' => $branch->id,
        'active' => true,
    ]);

    NurseConstraint::query()->create([
        'resource_id' => $resource->id,
        'qualification' => $qualification,
        'max_hours_per_week' => '40.00',
        'max_travel_minutes_between_visits' => 120,
    ]);

    return $resource;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, agreement: ServiceAgreement, agreementService: AgreementService, service: Service}
 */
function e9Fixture(string $slug = 'alpha'): array
{
    $tenant = e9Tenant($slug);
    e9Ctx()->set($tenant);
    $actor = e9User($tenant);
    $branch = e9Branch(strtoupper(substr($slug, 0, 4)));
    $patient = e9Patient(['first_name' => ucfirst($slug)]);
    $service = e9Service(['code' => strtoupper($slug).'-HOME']);

    $agreement = app(ServiceAgreementService::class)->create([
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'funding_type' => ServiceAgreement::FUNDING_OTHER,
        'starts_on' => '2026-08-01',
    ], [[
        'service_id' => $service->id,
        'planned_frequency_text' => 'As scheduled',
        'required_qualification' => 'RN',
        'duration_minutes' => 60,
    ]], $actor);

    return [
        'tenant' => $tenant,
        'actor' => $actor,
        'branch' => $branch,
        'patient' => $patient,
        'agreement' => $agreement,
        'agreementService' => $agreement->agreementServices()->firstOrFail(),
        'service' => $service,
    ];
}

function e9Plan(array $fixture, string $suffix): VisitPlan
{
    return VisitPlan::query()->create([
        'service_agreement_id' => $fixture['agreement']->id,
        'agreement_service_id' => $fixture['agreementService']->id,
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=1',
        'timezone' => 'Europe/Zurich',
        'window_start_time' => '09:00:00',
        'window_end_time' => '11:00:00',
        'duration_minutes' => 60,
        'starts_on' => '2026-08-03',
        'active' => true,
        'created_at' => now()->addSecond((int) $suffix),
    ]);
}

function e9Visit(array $fixture, array $overrides = []): PlannedVisit
{
    static $counter = 0;
    $counter++;

    return PlannedVisit::query()->create([
        'visit_plan_id' => e9Plan($fixture, (string) $counter)->id,
        'patient_id' => $fixture['patient']->id,
        'scheduled_date' => '2026-08-03',
        'window_start_at' => '2026-08-03 09:00:00',
        'window_end_at' => '2026-08-03 10:00:00',
        'duration_minutes' => 60,
        'required_qualification' => 'RN',
        'status' => PlannedVisit::STATUS_PLANNED,
        'location_latitude' => '47.376900',
        'location_longitude' => '8.541700',
        ...$overrides,
    ]);
}

/**
 * @param  list<array<string, mixed>>  $proposals
 * @return list<string>
 */
function e9ReplayValidator(array $proposals): array
{
    $acceptedByResource = collect();
    $failures = [];

    foreach ($proposals as $proposal) {
        $visit = PlannedVisit::query()->whereKey((string) $proposal['visit_id'])->firstOrFail();
        $resource = BookableResource::query()->whereKey((string) $proposal['resource_id'])->firstOrFail();
        $assigned = PlannedVisit::query()
            ->where('assigned_resource_id', $resource->id)
            ->where('status', PlannedVisit::STATUS_ASSIGNED)
            ->get()
            ->merge($acceptedByResource->filter(fn (PlannedVisit $candidate): bool => $candidate->assigned_resource_id === $resource->id));
        $reasons = app(AssignmentValidator::class)->validate($visit, $resource, $assigned);

        if ($reasons !== []) {
            $failures = [...$failures, ...$reasons];
        }

        $acceptedByResource->push($visit->replicate()->forceFill([
            'id' => $visit->id,
            'tenant_id' => $visit->tenant_id,
            'assigned_resource_id' => $resource->id,
            'status' => PlannedVisit::STATUS_ASSIGNED,
        ]));
    }

    return array_values(array_unique($failures));
}

test('dispatch agent fuzzed generated proposals all pass the deterministic validator', function () {
    $fixture = e9Fixture();
    e9Resource($fixture['branch'], 'Nurse A');
    e9Resource($fixture['branch'], 'Nurse B');
    e9Visit($fixture, [
        'window_start_at' => '2026-08-03 09:00:00',
        'window_end_at' => '2026-08-03 10:00:00',
    ]);
    e9Visit($fixture, [
        'window_start_at' => '2026-08-03 09:30:00',
        'window_end_at' => '2026-08-03 10:30:00',
        'location_latitude' => '47.377100',
        'location_longitude' => '8.542100',
    ]);
    e9Visit($fixture, [
        'window_start_at' => '2026-08-03 11:00:00',
        'window_end_at' => '2026-08-03 12:00:00',
        'location_latitude' => '47.378000',
        'location_longitude' => '8.543000',
    ]);

    $result = app(DispatchAgent::class)->proposeAssignments([
        'date' => '2026-08-03',
        'branch_id' => $fixture['branch']->id,
    ], $fixture['actor']);

    /** @var AgentAction $action */
    $action = $result['action'];
    $proposals = $action->proposed_output['proposals'];

    expect($result['status'])->toBe('pending')
        ->and($proposals)->toHaveCount(3)
        ->and(e9ReplayValidator($proposals))->toBe([])
        ->and($proposals[0]['constraints_satisfied'])->toContain('qualification', 'window', 'travel', 'hour_cap')
        ->and($proposals[0]['optimized_for'])->toBe('lowest_estimated_added_straight_line_travel_minutes');
});

test('invalid nursing assignment proposal is rejected before it reaches the approval queue', function () {
    $fixture = e9Fixture('invalid');
    $resource = e9Resource($fixture['branch'], 'LPN Nurse', 'LPN');
    $visit = e9Visit($fixture, ['required_qualification' => 'RN']);

    expect(fn () => app(DispatchAgent::class)->proposeAssignments([
        'date' => '2026-08-03',
        'branch_id' => $fixture['branch']->id,
        'proposals' => [[
            'visit_id' => $visit->id,
            'resource_id' => $resource->id,
        ]],
    ], $fixture['actor']))->toThrow(AiCoreException::class);

    expect(AgentAction::query()->count())->toBe(0)
        ->and(AiInteraction::query()->where('outcome', 'invalid_proposal')->count())->toBe(1)
        ->and($visit->refresh()->assigned_resource_id)->toBeNull();
});

test('pending dispatch proposal assigns nothing until approval executes through the locked path', function () {
    $fixture = e9Fixture('approve');
    $resource = e9Resource($fixture['branch'], 'Nurse A');
    $visit = e9Visit($fixture);

    $result = app(DispatchAgent::class)->proposeAssignments([
        'date' => '2026-08-03',
        'branch_id' => $fixture['branch']->id,
        'visit_ids' => [$visit->id],
        'resource_ids' => [$resource->id],
    ], $fixture['actor']);

    /** @var AgentAction $action */
    $action = $result['action'];

    expect($action->status)->toBe(AgentAction::STATUS_PENDING)
        ->and($visit->refresh()->assigned_resource_id)->toBeNull()
        ->and(PlannedVisit::query()->whereNotNull('assigned_resource_id')->count())->toBe(0);

    $approved = app(ApprovalQueue::class)->approve($action, $fixture['actor']);

    expect($approved->status)->toBe(AgentAction::STATUS_EXECUTED)
        ->and($approved->result['executed_via'])->toBe(VisitAssignmentService::class)
        ->and($visit->refresh()->assigned_resource_id)->toBe($resource->id)
        ->and(AiInteraction::query()->whereIn('outcome', ['proposed', 'approved', 'executed'])->count())->toBe(3)
        ->and(app(AuditService::class)->verifyChain($fixture['tenant']->id)['ok'])->toBeTrue();
});

test('replan day proposes reassignment for an unavailable nurse and approval reassigns safely', function () {
    $fixture = e9Fixture('replan');
    $sickNurse = e9Resource($fixture['branch'], 'Sick Nurse');
    $coverNurse = e9Resource($fixture['branch'], 'Cover Nurse');
    $visit = e9Visit($fixture, [
        'status' => PlannedVisit::STATUS_ASSIGNED,
        'assigned_resource_id' => $sickNurse->id,
        'assigned_at' => '2026-08-02 09:00:00',
        'assigned_by' => $fixture['actor']->id,
    ]);

    $result = app(DispatchAgent::class)->replanDay([
        'date' => '2026-08-03',
        'branch_id' => $fixture['branch']->id,
        'unavailable_resource_id' => $sickNurse->id,
        'resource_ids' => [$coverNurse->id],
    ], $fixture['actor']);

    /** @var AgentAction $action */
    $action = $result['action'];

    expect($action->proposed_output['proposals'])->toHaveCount(1)
        ->and($action->proposed_output['proposals'][0]['resource_id'])->toBe($coverNurse->id)
        ->and($visit->refresh()->assigned_resource_id)->toBe($sickNurse->id);

    app(ApprovalQueue::class)->approve($action, $fixture['actor']);

    expect($visit->refresh()->assigned_resource_id)->toBe($coverNurse->id);
});

test('dispatch agent refuses clinically framed prioritization and creates no agent action', function () {
    $fixture = e9Fixture('clinical');
    e9Resource($fixture['branch'], 'Nurse A');
    e9Visit($fixture);

    $result = app(DispatchAgent::class)->proposeAssignments([
        'date' => '2026-08-03',
        'branch_id' => $fixture['branch']->id,
    ], $fixture['actor'], 'Which patient is sickest and should be prioritized first?');

    expect($result['status'])->toBe('refused')
        ->and($result['human_handoff'])->toBeTrue()
        ->and(AgentAction::query()->count())->toBe(0)
        ->and(AiInteraction::query()->where('outcome', 'refused')->count())->toBe(1)
        ->and(PlannedVisit::query()->whereNotNull('assigned_resource_id')->count())->toBe(0);
});

test('dispatch agent budget gate kill switch tenant isolation and approve ceilings hold', function () {
    $alpha = e9Fixture('alpha');
    e9Resource($alpha['branch'], 'Alpha Nurse');
    $alphaVisit = e9Visit($alpha);

    $beta = e9Fixture('beta');
    e9Resource($beta['branch'], 'Beta Nurse');
    $betaVisit = e9Visit($beta);

    $result = app(DispatchAgent::class)->proposeAssignments([
        'date' => '2026-08-03',
        'branch_id' => $beta['branch']->id,
    ], $beta['actor']);

    /** @var AgentAction $action */
    $action = $result['action'];

    expect(collect($action->proposed_output['proposals'])->pluck('visit_id')->all())->toContain($betaVisit->id)
        ->and(collect($action->proposed_output['proposals'])->pluck('visit_id')->all())->not->toContain($alphaVisit->id);

    app(SettingsService::class)->set('ai.monthly_budget_minor', 0, 'int');

    $blocked = app(DispatchAgent::class)->proposeAssignments([
        'date' => '2026-08-03',
        'branch_id' => $beta['branch']->id,
    ], $beta['actor']);

    expect($blocked['status'])->toBe('budget_blocked')
        ->and($blocked['human_handoff'])->toBeTrue();

    app(SettingsService::class)->set('ai.monthly_budget_minor', 100, 'int');
    app(KillSwitch::class)->disable(DispatchAgent::REPLAN_FEATURE);

    $disabled = app(DispatchAgent::class)->replanDay([
        'date' => '2026-08-03',
        'branch_id' => $beta['branch']->id,
    ], $beta['actor']);

    expect($disabled['status'])->toBe('disabled')
        ->and(AiInteraction::query()->whereIn('outcome', ['budget_blocked', 'disabled'])->count())->toBe(2);

    $policy = app(AutonomyPolicy::class);
    $assignTool = app(ToolRegistry::class)->get('nursing.propose_assignments');
    $replanTool = app(ToolRegistry::class)->get('nursing.replan_day');
    $policy->set($assignTool->definition(), AutonomyPolicy::AUTO);
    $policy->set($replanTool->definition(), AutonomyPolicy::AUTO);

    expect($assignTool->definition()->autonomyCeiling)->toBe(AutonomyPolicy::APPROVE)
        ->and($replanTool->definition()->autonomyCeiling)->toBe(AutonomyPolicy::APPROVE)
        ->and($policy->levelFor($assignTool->definition()))->toBe(AutonomyPolicy::APPROVE)
        ->and($policy->levelFor($replanTool->definition()))->toBe(AutonomyPolicy::APPROVE);
});
