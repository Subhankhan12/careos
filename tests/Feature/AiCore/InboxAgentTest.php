<?php

use App\AiCore\Agents\InboxAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\AiInteraction;
use Modules\AiCore\Models\KbArticle;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\KillSwitch;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Services\DocumentService;
use Modules\Comms\Models\Message;
use Modules\Comms\Models\Thread;
use Modules\Comms\Services\ThreadService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function g6Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function g6User(Tenant $tenant, string $role = 'org_admin'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, patient: Patient, thread: Thread}
 */
function g6Fixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Care',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    g6Ctx()->set($tenant);

    $actor = g6User($tenant); // org_admin: comms.manage + note.write
    $patient = app(PatientService::class)->create([
        'first_name' => 'Inbox',
        'last_name' => 'Agent',
        'date_of_birth' => '1994-04-04',
        'sex' => 'female',
    ]);
    g6PortalReady($patient, $actor);
    $thread = app(ThreadService::class)->openPatientThread($patient, 'Question', $actor);

    return compact('tenant', 'actor', 'patient', 'thread');
}

function g6PortalReady(Patient $patient, User $staff): void
{
    ConsentTemplate::query()->firstOrCreate(
        ['key' => 'portal', 'version' => 1],
        [
            'title' => 'Portal Access',
            'body' => 'Portal access consent',
            'scope_keys' => ['portal.access'],
            'is_active' => true,
        ],
    );
    app(ConsentService::class)->grant($patient, 'portal', 'Inbox Agent', $staff);

    PortalAccount::query()->create([
        'patient_id' => $patient->id,
        'email' => 'inbox.'.$patient->id.'@portal.test',
        'password' => bcrypt('secret-portal-pass'),
        'status' => PortalAccount::STATUS_ACTIVE,
        'activated_at' => now(),
    ]);
}

function g6PatientAsks(array $fx, string $body): Message
{
    return app(ThreadService::class)->postPatientMessage($fx['thread'], $fx['patient'], $body);
}

test('clinical questions produce NO draft at all: refusal, handoff, thread flagged', function () {
    $fx = g6Fixture();
    $agent = app(InboxAgent::class);

    $clinicalInputs = [
        'Should I come in? My incision looks red.',
        'Is this normal? I have been dizzy since the new medication.',
        'Is this rash getting worse? Should I stop taking the pills?',
    ];

    foreach ($clinicalInputs as $index => $ask) {
        g6PatientAsks($fx, $ask);

        $result = $agent->draftReply(['thread_id' => $fx['thread']->id], $fx['actor']);

        expect($result['status'])->toBe('refused')
            ->and($result['human_handoff'])->toBeTrue()
            ->and($result['thread_flagged'])->toBeTrue()
            ->and($result)->not->toHaveKey('action');
    }

    // ZERO draft content exists anywhere: no agent actions at all, and the
    // ledger records the refusals.
    expect(AgentAction::query()->count())->toBe(0)
        ->and(AiInteraction::query()->where('outcome', 'refused')->count())->toBe(3)
        ->and($fx['thread']->refresh()->clinician_attention_at)->not->toBeNull()
        ->and($fx['thread']->clinician_attention_reason)->toContain('clinical question');

    // The flag is audited patient-scoped.
    $flags = collect(DB::select(
        "SELECT * FROM audit_events WHERE tenant_id = ? AND action = 'thread.flagged_for_clinician'",
        [$fx['tenant']->id],
    ));
    expect($flags->count())->toBe(3)
        ->and($flags->first()->patient_id)->toBe($fx['patient']->id);
});

test('an ungrounded factual claim is rejected in code before the approval queue', function () {
    $fx = g6Fixture();
    g6PatientAsks($fx, 'When is my next appointment?');

    // The "LLM" claims an appointment time that does not match reality.
    expect(fn () => app(InboxAgent::class)->draftReply([
        'thread_id' => $fx['thread']->id,
        'draft' => [[
            'text' => 'Your next appointment is on 2030-01-01 09:00:00.',
            'source' => ['type' => 'admin_fact', 'key' => 'next_appointment', 'value' => '2030-01-01 09:00:00'],
        ]],
    ], $fx['actor']))->toThrow(AiCoreException::class, 'does not match');

    // A line with no source at all is rejected too.
    expect(fn () => app(InboxAgent::class)->draftReply([
        'thread_id' => $fx['thread']->id,
        'draft' => [['text' => 'We are the best clinic in town.']],
    ], $fx['actor']))->toThrow(AiCoreException::class, 'source');

    // A disallowed source type is rejected.
    expect(fn () => app(InboxAgent::class)->draftReply([
        'thread_id' => $fx['thread']->id,
        'draft' => [[
            'text' => 'Trust me.',
            'source' => ['type' => 'the_internet', 'id' => 'x'],
        ]],
    ], $fx['actor']))->toThrow(AiCoreException::class, 'not allowed');

    expect(AgentAction::query()->count())->toBe(0)
        ->and(AiInteraction::query()->where('outcome', 'invalid_proposal')->count())->toBe(3);
});

test('drafts carry resolvable source references from the three permitted sources', function () {
    $fx = g6Fixture();
    $ask = g6PatientAsks($fx, 'Can you confirm what I owe on my invoice?');
    $kb = KbArticle::query()->create([
        'title' => 'Opening hours',
        'body' => 'We are open Monday to Friday, 08:00-18:00.',
        'tags' => ['hours'],
        'is_active' => true,
    ]);

    $result = app(InboxAgent::class)->draftReply([
        'thread_id' => $fx['thread']->id,
        'draft' => [
            [
                'text' => 'You asked about your invoice.',
                'source' => ['type' => 'thread_message', 'id' => $ask->id],
            ],
            [
                'text' => 'Your current open balance is 0 (minor units).',
                'source' => ['type' => 'admin_fact', 'key' => 'invoice_open_balance', 'value' => '0'],
            ],
            [
                'text' => 'We are open Monday to Friday, 08:00-18:00.',
                'source' => ['type' => 'kb_article', 'id' => $kb->id],
            ],
        ],
    ], $fx['actor']);

    /** @var AgentAction $action */
    $action = $result['action'];
    $lines = collect($action->proposed_output['lines']);

    expect($result['status'])->toBe('pending')
        ->and($lines)->toHaveCount(3)
        ->and($lines->pluck('source.type')->sort()->values()->all())->toBe(['admin_fact', 'kb_article', 'thread_message'])
        ->and($lines->firstWhere('source.type', 'thread_message')['source']['id'])->toBe($ask->id)
        ->and($lines->firstWhere('source.type', 'kb_article')['source']['id'])->toBe($kb->id);

    // An INACTIVE KB article is not a permitted source.
    $kb->forceFill(['is_active' => false])->save();

    expect(fn () => app(InboxAgent::class)->draftReply([
        'thread_id' => $fx['thread']->id,
        'draft' => [[
            'text' => 'We are open Monday to Friday, 08:00-18:00. (v2)',
            'source' => ['type' => 'kb_article', 'id' => $kb->id],
        ]],
    ], $fx['actor']))->toThrow(AiCoreException::class, 'active');
});

test('the agent never posts: message count is unchanged while a draft is pending', function () {
    $fx = g6Fixture();
    g6PatientAsks($fx, 'When is my next appointment please?');
    $countBeforeDraft = Message::query()->where('thread_id', $fx['thread']->id)->count();

    $result = app(InboxAgent::class)->draftReply([
        'thread_id' => $fx['thread']->id,
        'draft' => [[
            'text' => 'Your current open balance is 0 (minor units).',
            'source' => ['type' => 'admin_fact', 'key' => 'invoice_open_balance', 'value' => '0'],
        ]],
    ], $fx['actor']);

    /** @var AgentAction $action */
    $action = $result['action'];

    expect($action->status)->toBe(AgentAction::STATUS_PENDING)
        ->and(Message::query()->where('thread_id', $fx['thread']->id)->count())->toBe($countBeforeDraft);

    // Rejecting the draft also never posts.
    app(ApprovalQueue::class)->reject($action, $fx['actor'], 'Not appropriate');
    expect(Message::query()->where('thread_id', $fx['thread']->id)->count())->toBe($countBeforeDraft);
});

test('an explicit human send posts through ThreadService with ai_assisted=true', function () {
    $fx = g6Fixture();
    g6PatientAsks($fx, 'What do I owe?');

    $result = app(InboxAgent::class)->draftReply([
        'thread_id' => $fx['thread']->id,
        'draft' => [[
            'text' => 'Your current open balance is 0 (minor units).',
            'source' => ['type' => 'admin_fact', 'key' => 'invoice_open_balance', 'value' => '0'],
        ]],
    ], $fx['actor']);

    /** @var AgentAction $action */
    $action = $result['action'];

    // The staff member explicitly sends via the inbox endpoint.
    $this->actingAs($fx['actor'])
        ->post(route('comms.inbox.send-draft'), ['action_id' => $action->id])
        ->assertRedirect();

    $sent = Message::query()
        ->where('thread_id', $fx['thread']->id)
        ->where('author_type', Message::AUTHOR_STAFF)
        ->firstOrFail();

    expect($sent->ai_assisted)->toBeTrue()
        ->and($sent->author_staff_user_id)->toBe($fx['actor']->id)
        ->and($sent->body)->toContain('open balance')
        ->and($action->refresh()->status)->toBe(AgentAction::STATUS_EXECUTED)
        ->and($action->result['executed_via'])->toBe(ThreadService::class);
});

test('document classification never files without human confirmation and never moves patients', function () {
    Storage::fake('local');
    $fx = g6Fixture();
    $document = app(DocumentService::class)->upload(
        $fx['patient'],
        $fx['actor'],
        UploadedFile::fake()->create('lab-result.pdf', 20, 'application/pdf'),
        ['category' => Document::CATEGORY_OTHER, 'title' => 'Lab result inbound'],
    );

    $other = app(PatientService::class)->create([
        'first_name' => 'Wrong',
        'last_name' => 'Match',
        'date_of_birth' => '1980-01-01',
        'sex' => 'male',
    ]);

    // The "LLM" suggests a category AND a different patient.
    $result = app(InboxAgent::class)->classifyDocument([
        'document_id' => $document->id,
        'classification' => ['category' => Document::CATEGORY_RESULT, 'patient_id' => $other->id],
    ], $fx['actor']);

    /** @var AgentAction $action */
    $action = $result['action'];

    // Pending: nothing filed, nothing moved.
    expect($action->status)->toBe(AgentAction::STATUS_PENDING)
        ->and($action->proposed_output['patient_match_auto_applied'])->toBeFalse()
        ->and($document->refresh()->category)->toBe(Document::CATEGORY_OTHER)
        ->and($document->patient_id)->toBe($fx['patient']->id);

    // Human confirms: the deterministic DocumentService files the CATEGORY;
    // the patient match is NEVER auto-applied.
    $approved = app(ApprovalQueue::class)->approve($action, $fx['actor']);

    expect($approved->result['executed_via'])->toBe(DocumentService::class)
        ->and($approved->result['patient_unchanged'])->toBeTrue()
        ->and($document->refresh()->category)->toBe(Document::CATEGORY_RESULT)
        ->and($document->patient_id)->toBe($fx['patient']->id);
});

test('the ceiling cannot exceed suggest for either tool', function () {
    g6Fixture();

    $policy = app(AutonomyPolicy::class);
    $draftTool = app(ToolRegistry::class)->get('comms.draft_reply');
    $classifyTool = app(ToolRegistry::class)->get('comms.classify_document');

    $policy->set($draftTool->definition(), AutonomyPolicy::AUTO);
    $policy->set($classifyTool->definition(), AutonomyPolicy::APPROVE);

    expect($draftTool->definition()->autonomyCeiling)->toBe(AutonomyPolicy::SUGGEST)
        ->and($classifyTool->definition()->autonomyCeiling)->toBe(AutonomyPolicy::SUGGEST)
        ->and($policy->levelFor($draftTool->definition()))->toBe(AutonomyPolicy::SUGGEST)
        ->and($policy->levelFor($classifyTool->definition()))->toBe(AutonomyPolicy::SUGGEST);
});

test('budget gate and kill switch disable the inbox agent and degrade to manual', function () {
    $fx = g6Fixture();
    g6PatientAsks($fx, 'When are you open?');
    $agent = app(InboxAgent::class);

    app(SettingsService::class)->set('ai.monthly_budget_minor', 0, 'int');

    $blocked = $agent->draftReply(['thread_id' => $fx['thread']->id], $fx['actor']);
    expect($blocked['status'])->toBe('budget_blocked')
        ->and($blocked['human_handoff'])->toBeTrue();

    app(SettingsService::class)->set('ai.monthly_budget_minor', 100, 'int');
    app(KillSwitch::class)->disable(InboxAgent::DRAFT_FEATURE);

    $disabled = $agent->draftReply(['thread_id' => $fx['thread']->id], $fx['actor']);
    expect($disabled['status'])->toBe('disabled')
        ->and(AiInteraction::query()->whereIn('outcome', ['budget_blocked', 'disabled'])->count())->toBe(2)
        ->and(AgentAction::query()->count())->toBe(0)
        ->and(Message::query()->where('author_type', Message::AUTHOR_STAFF)->count())->toBe(0);
});

test('all paths are ledgered audited read-logged and tenant isolated', function () {
    $fx = g6Fixture();
    g6PatientAsks($fx, 'What do I owe on my bill?');

    $result = app(InboxAgent::class)->draftReply([
        'thread_id' => $fx['thread']->id,
        'draft' => [[
            'text' => 'Your current open balance is 0 (minor units).',
            'source' => ['type' => 'admin_fact', 'key' => 'invoice_open_balance', 'value' => '0'],
        ]],
    ], $fx['actor']);

    app(ApprovalQueue::class)->approve($result['action'], $fx['actor']);

    $reads = collect(DB::select(
        "SELECT * FROM audit_events WHERE tenant_id = ? AND action = 'read' AND patient_id = ?",
        [$fx['tenant']->id, $fx['patient']->id],
    ));

    expect(AiInteraction::query()->whereIn('outcome', ['proposed', 'approved', 'executed'])->count())->toBe(3)
        ->and($reads->filter(fn (object $row): bool => str_contains((string) $row->context, 'inbox_agent'))->count())->toBeGreaterThanOrEqual(1)
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();

    g6Fixture('beta');

    expect(AgentAction::query()->count())->toBe(0)
        ->and(AiInteraction::query()->count())->toBe(0)
        ->and(Thread::query()->count())->toBe(1); // beta's own fixture thread only
});
