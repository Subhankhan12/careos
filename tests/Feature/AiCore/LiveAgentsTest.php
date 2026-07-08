<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\AiCore\Agents\FrontDeskAgent;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\AiInteraction;
use Modules\AiCore\Models\KbArticle;
use Modules\AiCore\Retrieval\KbEmbeddingService;
use Modules\AiCore\Services\AgentRuntime;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\KillSwitch;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Models\WaitlistEntry;
use Modules\Scheduling\Services\WaitlistService;

uses(RefreshDatabase::class);

function c8Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function c8Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function c8User(Tenant $tenant): User
{
    c8Ctx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create([
        'user_id' => $user->id,
        'role_id' => Role::where('key', 'org_admin')->firstOrFail()->id,
    ]);

    return $user;
}

function c8Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Avery',
        'last_name' => 'Agent',
        'date_of_birth' => '1991-04-05',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function c8Branch(string $code = 'MAIN'): Branch
{
    return Branch::create(['name' => $code.' Branch', 'code' => $code]);
}

function c8Service(array $overrides = []): Service
{
    return Service::create([
        'name' => 'Consult',
        'code' => 'CONS',
        'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => true,
        'active' => true,
        ...$overrides,
    ]);
}

function c8Resource(Branch $branch): BookableResource
{
    $resource = BookableResource::create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Practitioner',
        'branch_id' => $branch->id,
        'active' => true,
    ]);

    ResourceAvailability::create([
        'resource_id' => $resource->id,
        'weekday' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    return $resource;
}

function c8Kb(string $title, string $body, array $tags = []): KbArticle
{
    $article = KbArticle::create([
        'title' => $title,
        'body' => $body,
        'tags' => $tags,
        'is_active' => true,
    ]);

    app(KbEmbeddingService::class)->syncArticle($article);

    return $article;
}

test('Front-Desk Agent answers a KB-covered question from tenant KB and cites the source', function () {
    Http::fake();
    $tenant = c8Tenant('alpha');
    c8Ctx()->set($tenant);
    $article = c8Kb('Parking', 'Parking is available behind the clinic. Use the north entrance.', ['parking']);

    $answer = app(FrontDeskAgent::class)->answer('Where can I park for my appointment?');

    expect($answer['status'])->toBe('answered')
        ->and($answer['answer'])->toBe($article->body)
        ->and($answer['source']['id'])->toBe($article->id)
        ->and($answer['source']['title'])->toBe('Parking')
        ->and($answer['label'])->toBe('AI draft - requires human review')
        ->and($answer['human_handoff'])->toBeFalse()
        ->and(AiInteraction::where('outcome', 'answered')->count())->toBe(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();

    Http::assertNothingSent();
});

test('Front-Desk Agent escalates when the answer is not in the tenant KB', function () {
    $tenant = c8Tenant('alpha');
    c8Ctx()->set($tenant);
    c8Kb('Opening hours', 'The clinic is open Monday to Friday from 09:00 to 17:00.', ['hours']);

    $answer = app(FrontDeskAgent::class)->answer('Do you validate parking downtown?');

    expect($answer['status'])->toBe('escalated')
        ->and($answer['answer'])->toBeNull()
        ->and($answer['human_handoff'])->toBeTrue()
        ->and(AiInteraction::where('outcome', 'escalated')->count())->toBe(1);
});

test('Front-Desk Agent refuses medical questions and hands off without advice', function () {
    $tenant = c8Tenant('alpha');
    c8Ctx()->set($tenant);
    c8Kb('Opening hours', 'The clinic is open Monday to Friday from 09:00 to 17:00.', ['hours']);

    $answer = app(FrontDeskAgent::class)->answer('I have chest pain and a fever, what should I do?');

    expect($answer['status'])->toBe('refused')
        ->and($answer['human_handoff'])->toBeTrue()
        ->and($answer['answer'])->toContain('cannot help with medical questions')
        ->and(AiInteraction::where('outcome', 'refused')->count())->toBe(1);
});

test('Scheduler fill-from-waitlist proposes first and books only after human approval through the safe path', function () {
    $tenant = c8Tenant('alpha');
    $user = c8User($tenant);
    $branch = c8Branch();
    $service = c8Service();
    $resource = c8Resource($branch);
    $patient = c8Patient();
    $waitlist = app(WaitlistService::class);
    $entry = $waitlist->create([
        'patient_id' => $patient->id,
        'service_id' => $service->id,
        'branch_id' => $branch->id,
        'desired_starts_at' => '2026-07-13 09:00:00',
        'desired_ends_at' => '2026-07-13 12:00:00',
        'flexible' => false,
        'priority' => 5,
    ]);

    $result = app(AgentRuntime::class)->runTool(
        'scheduler.fill_from_waitlist',
        [
            'service_id' => $service->id,
            'branch_id' => $branch->id,
            'starts_at' => '2026-07-13 10:00:00',
            'ends_at' => '2026-07-13 10:30:00',
            'resource_ids' => [$resource->id],
        ],
        $user,
        'scheduler.fill_waitlist',
        'scheduler-agent',
        'Open slot can be offered to a matching waitlist entry',
    );

    /** @var AgentAction $action */
    $action = $result['action'];

    expect($result['status'])->toBe('pending')
        ->and($action->autonomy_level)->toBe(AutonomyPolicy::SUGGEST)
        ->and($action->proposed_output['matches'][0]['waitlist_entry_id'])->toBe($entry->id)
        ->and(Appointment::query()->count())->toBe(0)
        ->and($entry->refresh()->status)->toBe(WaitlistEntry::STATUS_WAITING);

    $approved = app(ApprovalQueue::class)->approve($action, $user);

    expect($approved->status)->toBe(AgentAction::STATUS_EXECUTED)
        ->and($approved->result['booked'])->toBeTrue()
        ->and(Appointment::query()->count())->toBe(1)
        ->and(Appointment::firstOrFail()->source)->toBe(Appointment::SOURCE_STAFF)
        ->and($entry->refresh()->status)->toBe(WaitlistEntry::STATUS_BOOKED)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('Scheduler suggest-slots proposes available slots without booking', function () {
    $tenant = c8Tenant('alpha');
    $user = c8User($tenant);
    $branch = c8Branch();
    $service = c8Service();
    c8Resource($branch);

    $result = app(AgentRuntime::class)->runTool(
        'scheduler.suggest_slots',
        [
            'service_id' => $service->id,
            'branch_id' => $branch->id,
            'date' => '2026-07-13',
            'limit' => 2,
        ],
        $user,
        'scheduler.suggest_slots',
        'scheduler-agent',
        'Find safe free slots for a patient request',
    );

    /** @var AgentAction $action */
    $action = $result['action'];

    expect($result['status'])->toBe('pending')
        ->and($action->proposed_output['slots'])->toHaveCount(2)
        ->and($action->proposed_output['books_on_approval'])->toBeFalse()
        ->and(Appointment::query()->count())->toBe(0);
});

test('budget gate and kill switch disable live agents and degrade to manual', function () {
    $tenant = c8Tenant('alpha');
    $user = c8User($tenant);
    $branch = c8Branch();
    $service = c8Service();
    c8Resource($branch);
    app(SettingsService::class)->set('ai.monthly_budget_minor', 0, 'int');

    $scheduler = app(AgentRuntime::class)->runTool(
        'scheduler.suggest_slots',
        ['service_id' => $service->id, 'branch_id' => $branch->id, 'date' => '2026-07-13'],
        $user,
        'scheduler.suggest_slots',
        'scheduler-agent',
        'Budget blocked proposal',
    );
    $frontDesk = app(FrontDeskAgent::class)->answer('What are your opening hours?');

    expect($scheduler['status'])->toBe('budget_blocked')
        ->and($frontDesk['status'])->toBe('budget_blocked')
        ->and(AgentAction::query()->count())->toBe(0);

    app(SettingsService::class)->set('ai.monthly_budget_minor', 100, 'int');
    app(KillSwitch::class)->disable('scheduler.suggest_slots');

    $disabled = app(AgentRuntime::class)->runTool(
        'scheduler.suggest_slots',
        ['service_id' => $service->id, 'branch_id' => $branch->id, 'date' => '2026-07-13'],
        $user,
        'scheduler.suggest_slots',
        'scheduler-agent',
        'Kill switch blocks proposal',
    );

    expect($disabled['status'])->toBe('disabled')
        ->and(AiInteraction::whereIn('outcome', ['budget_blocked', 'disabled'])->count())->toBe(3);
});

test('live agent data and KB retrieval are tenant isolated', function () {
    $alpha = c8Tenant('alpha');
    c8Ctx()->set($alpha);
    c8Kb('Alpha parking', 'Alpha patients park in the blue garage.', ['parking']);

    $beta = c8Tenant('beta');
    c8Ctx()->set($beta);
    c8Kb('Beta parking', 'Beta patients park by the river entrance.', ['parking']);

    $answer = app(FrontDeskAgent::class)->answer('Where can patients park?');

    expect($answer['status'])->toBe('answered')
        ->and($answer['answer'])->toContain('river entrance')
        ->and($answer['answer'])->not->toContain('blue garage')
        ->and(KbArticle::query()->count())->toBe(1);
});

test('scheduler tools are governed with approve ceiling even when tenant asks for auto', function () {
    $tenant = c8Tenant('alpha');
    c8Ctx()->set($tenant);
    $tool = app(ToolRegistry::class)->get('scheduler.fill_from_waitlist');
    $policy = app(AutonomyPolicy::class);

    $policy->set($tool->definition(), AutonomyPolicy::AUTO);

    expect($tool->definition()->autonomyCeiling)->toBe(AutonomyPolicy::APPROVE)
        ->and($policy->levelFor($tool->definition()))->toBe(AutonomyPolicy::APPROVE);
});
