<?php

use App\AiCore\Agents\ClinicalSummaryAgent;
use App\AiCore\Agents\FollowUpAgent;
use App\AiCore\Support\ClinicalSummarySourceValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\AiInteraction;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\KillSwitch;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Audit\Models\AuditEvent;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Medication;
use Modules\Clinical\Models\Problem;
use Modules\Clinical\Models\Recall;
use Modules\Clinical\Models\RecallRule;
use Modules\Clinical\Models\Vital;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function d8Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function d8Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function d8Doctor(Tenant $tenant): User
{
    d8Ctx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create([
        'user_id' => $user->id,
        'role_id' => Role::where('key', 'doctor')->firstOrFail()->id,
    ]);

    StaffProfile::create([
        'user_id' => $user->id,
        'first_name' => 'Dana',
        'last_name' => 'Doctor',
        'display_name' => 'Dana Doctor',
        'profession' => 'doctor',
        'status' => StaffProfile::STATUS_ACTIVE,
    ]);

    return $user;
}

function d8Staff(User $user): StaffProfile
{
    return StaffProfile::where('user_id', $user->id)->firstOrFail();
}

function d8Patient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Avery',
        'last_name' => 'Clinical',
        'date_of_birth' => '1990-02-03',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function d8Encounter(Patient $patient, StaffProfile $staff): Encounter
{
    $code = 'B'.Str::upper(Str::random(6));
    $branch = Branch::create(['name' => $code, 'code' => $code]);

    return Encounter::create([
        'patient_id' => $patient->id,
        'practitioner_id' => $staff->id,
        'branch_id' => $branch->id,
        'type' => 'consultation',
        'started_at' => '2026-07-01 09:00:00',
        'status' => 'open',
        'reason_for_visit' => 'Follow-up',
    ]);
}

function d8SignedNote(Patient $patient, StaffProfile $staff, User $signer, array $overrides = []): ClinicalNote
{
    return ClinicalNote::create([
        'encounter_id' => d8Encounter($patient, $staff)->id,
        'patient_id' => $patient->id,
        'author_id' => $staff->id,
        'subjective' => 'Patient reports attending scheduled review.',
        'objective' => 'Documented examination text.',
        'assessment' => 'Clinician documented assessment text.',
        'plan' => 'Clinician documented plan text.',
        'status' => ClinicalNote::STATUS_SIGNED,
        'signed_at' => '2026-07-02 10:00:00',
        'signed_by' => $signer->id,
        'version' => 1,
        ...$overrides,
    ]);
}

function d8ClinicalRows(Patient $patient, StaffProfile $staff, User $signer): array
{
    $note = d8SignedNote($patient, $staff, $signer);
    $problem = Problem::create([
        'patient_id' => $patient->id,
        'description' => 'Documented active problem text',
        'code' => 'P-D8',
        'status' => Problem::STATUS_ACTIVE,
        'recorded_by' => $staff->id,
        'recorded_at' => '2026-07-03 09:00:00',
    ]);
    $medication = Medication::create([
        'patient_id' => $patient->id,
        'name' => 'Documented medication',
        'substance_key' => 'documented-medication',
        'dose_text' => 'as documented',
        'status' => Medication::STATUS_ACTIVE,
        'started_on' => '2026-07-03',
        'recorded_by' => $staff->id,
        'recorded_at' => '2026-07-03 10:00:00',
    ]);
    $vital = Vital::create([
        'patient_id' => $patient->id,
        'recorded_at' => '2026-07-03 11:00:00',
        'systolic' => 120,
        'diastolic' => 80,
        'recorded_by' => $staff->id,
    ]);

    return [$note, $problem, $medication, $vital];
}

function d8Recall(Patient $patient): Recall
{
    $rule = RecallRule::create([
        'name' => 'Annual review',
        'criteria' => ['active_problem_codes' => ['P-D8']],
        'interval_months' => 12,
        'active' => true,
    ]);

    return Recall::create([
        'patient_id' => $patient->id,
        'rule_id' => $rule->id,
        'due_on' => '2026-08-01',
        'status' => Recall::STATUS_DUE,
    ]);
}

function d8CommsConsent(Patient $patient, User $doctor): void
{
    ConsentTemplate::create([
        'key' => 'communication',
        'title' => 'Communication',
        'body' => 'Communication consent',
        'version' => 1,
        'scope_keys' => ['comms.email'],
        'is_active' => true,
    ]);

    app(ConsentService::class)->grant($patient, 'communication', 'Avery Clinical', $doctor);
}

test('Summary output is source-linked to real patient records and unsourced lines are rejected', function () {
    $tenant = d8Tenant('alpha');
    $doctor = d8Doctor($tenant);
    $patient = d8Patient();
    d8ClinicalRows($patient, d8Staff($doctor), $doctor);
    $this->actingAs($doctor);

    $result = app(ClinicalSummaryAgent::class)->summarize($patient->id, '2026-07-01', '2026-07-31', $doctor);
    /** @var AgentAction $action */
    $action = $result['action'];
    $lines = $action->proposed_output['lines'];

    expect($result['status'])->toBe('pending')
        ->and($action->autonomy_level)->toBe(AutonomyPolicy::SUGGEST)
        ->and($lines)->not->toBeEmpty();

    foreach ($lines as $line) {
        expect($line['source']['id'])->not->toBe('')
            ->and($line['source']['type'])->toBeIn(['clinical_note', 'problem', 'medication', 'vital']);
    }

    expect(fn () => app(ClinicalSummarySourceValidator::class)->validate($patient->id, [
        ['text' => 'This line has no source.'],
    ]))->toThrow(AiCoreException::class)
        ->and(AuditEvent::where('action', 'read')->where('patient_id', $patient->id)->count())->toBeGreaterThan(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('Summary refuses interpretive questions and never writes to the clinical record', function () {
    $tenant = d8Tenant('alpha');
    $doctor = d8Doctor($tenant);
    $patient = d8Patient();
    d8ClinicalRows($patient, d8Staff($doctor), $doctor);
    $this->actingAs($doctor);
    $before = ClinicalNote::count();

    $refused = app(ClinicalSummaryAgent::class)->summarize(
        $patient->id,
        '2026-07-01',
        '2026-07-31',
        $doctor,
        'what is the diagnosis and is this getting worse?',
    );

    expect($refused['status'])->toBe('refused')
        ->and($refused['human_handoff'])->toBeTrue()
        ->and(ClinicalNote::count())->toBe($before)
        ->and(AgentAction::count())->toBe(0)
        ->and(AiInteraction::where('outcome', 'refused')->count())->toBe(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('Summary is tenant and patient scoped and cannot retrieve another patient record', function () {
    $alpha = d8Tenant('alpha');
    $doctor = d8Doctor($alpha);
    $alphaPatient = d8Patient(['first_name' => 'Alpha']);
    d8ClinicalRows($alphaPatient, d8Staff($doctor), $doctor);

    $beta = d8Tenant('beta');
    $betaDoctor = d8Doctor($beta);
    $betaPatient = d8Patient(['first_name' => 'Beta']);
    d8ClinicalRows($betaPatient, d8Staff($betaDoctor), $betaDoctor);

    d8Ctx()->set($alpha);
    $this->actingAs($doctor);
    $result = app(ClinicalSummaryAgent::class)->summarize($alphaPatient->id, '2026-07-01', '2026-07-31', $doctor);
    /** @var AgentAction $action */
    $action = $result['action'];

    expect(collect($action->proposed_output['lines'])->pluck('text')->implode(' '))->not->toContain('Beta')
        ->and(fn () => app(ClinicalSummaryAgent::class)->summarize($betaPatient->id, '2026-07-01', '2026-07-31', $doctor))
        ->toThrow(Exception::class);
});

test('Follow-up drafts only from template and recall then sends nothing without approval and consent', function () {
    $tenant = d8Tenant('alpha');
    $doctor = d8Doctor($tenant);
    $patient = d8Patient();
    $recall = d8Recall($patient);
    $this->actingAs($doctor);
    $template = 'Hello {first_name}, please contact us to arrange {rule_name} by {due_on}.';

    $result = app(FollowUpAgent::class)->draftRecallMessage($recall->id, $template, $doctor);
    /** @var AgentAction $action */
    $action = $result['action'];

    expect($result['status'])->toBe('pending')
        ->and($action->proposed_output['message'])->toBe('Hello Avery, please contact us to arrange Annual review by 2026-08-01.')
        ->and($action->proposed_output['selected_by'])->toBe('deterministic_recall_engine')
        ->and($action->proposed_output['sent'])->toBeFalse()
        ->and($action->proposed_output['message'])->not->toMatch('/symptom|diagnos|dose|triage|medical advice/i');

    $blocked = app(ApprovalQueue::class)->approve($action, $doctor);
    expect($blocked->result['status'])->toBe('blocked_no_comms_consent')
        ->and($blocked->result['sent'])->toBeFalse();

    d8CommsConsent($patient, $doctor);
    $second = app(FollowUpAgent::class)->draftRecallMessage($recall->id, $template, $doctor);
    /** @var AgentAction $secondAction */
    $secondAction = $second['action'];
    $ready = app(ApprovalQueue::class)->approve($secondAction, $doctor);

    expect($ready->result['status'])->toBe('ready_for_human_delivery')
        ->and($ready->result['sent'])->toBeFalse()
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('Clinical agent tools cannot be raised above suggest and gates disable both agents', function () {
    $tenant = d8Tenant('alpha');
    $doctor = d8Doctor($tenant);
    $patient = d8Patient();
    d8ClinicalRows($patient, d8Staff($doctor), $doctor);
    $recall = d8Recall($patient);
    $this->actingAs($doctor);

    $summaryTool = app(ToolRegistry::class)->get('clinical.summarize_since_last_visit');
    $followUpTool = app(ToolRegistry::class)->get('clinical.draft_recall_message');
    $policy = app(AutonomyPolicy::class);
    $policy->set($summaryTool->definition(), AutonomyPolicy::AUTO);
    $policy->set($followUpTool->definition(), AutonomyPolicy::APPROVE);

    expect($summaryTool->definition()->autonomyCeiling)->toBe(AutonomyPolicy::SUGGEST)
        ->and($followUpTool->definition()->autonomyCeiling)->toBe(AutonomyPolicy::SUGGEST)
        ->and($policy->levelFor($summaryTool->definition()))->toBe(AutonomyPolicy::SUGGEST)
        ->and($policy->levelFor($followUpTool->definition()))->toBe(AutonomyPolicy::SUGGEST);

    app(SettingsService::class)->set('ai.monthly_budget_minor', 0, 'int');
    $budgetSummary = app(ClinicalSummaryAgent::class)->summarize($patient->id, '2026-07-01', '2026-07-31', $doctor);
    $budgetFollowUp = app(FollowUpAgent::class)->draftRecallMessage($recall->id, 'Hello {first_name}', $doctor);

    expect($budgetSummary['status'])->toBe('budget_blocked')
        ->and($budgetFollowUp['status'])->toBe('budget_blocked');

    app(SettingsService::class)->set('ai.monthly_budget_minor', 100, 'int');
    app(KillSwitch::class)->disable(ClinicalSummaryAgent::FEATURE);
    app(KillSwitch::class)->disable(FollowUpAgent::FEATURE);

    $disabledSummary = app(ClinicalSummaryAgent::class)->summarize($patient->id, '2026-07-01', '2026-07-31', $doctor);
    $disabledFollowUp = app(FollowUpAgent::class)->draftRecallMessage($recall->id, 'Hello {first_name}', $doctor);

    expect($disabledSummary['status'])->toBe('disabled')
        ->and($disabledFollowUp['status'])->toBe('disabled')
        ->and(AiInteraction::whereIn('outcome', ['budget_blocked', 'disabled'])->count())->toBe(4)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('Chart surfaces the summary draft as props and clinician insertion is explicit', function () {
    $tenant = d8Tenant('alpha');
    $doctor = d8Doctor($tenant);
    $patient = d8Patient();
    $staff = d8Staff($doctor);
    d8ClinicalRows($patient, $staff, $doctor);
    ClinicalNote::create([
        'encounter_id' => d8Encounter($patient, $staff)->id,
        'patient_id' => $patient->id,
        'author_id' => $staff->id,
        'subjective' => 'Draft subjective',
        'objective' => 'Draft objective',
        'assessment' => 'Draft assessment',
        'plan' => 'Draft plan',
        'status' => ClinicalNote::STATUS_DRAFT,
        'version' => 1,
    ]);

    $this->actingAs($doctor)
        ->post(route('clinical.summary.draft', $patient->id), [
            'from' => '2026-07-01',
            'to' => '2026-07-31',
        ])
        ->assertRedirect(route('clinical.chart', $patient->id));

    $this->actingAs($doctor)
        ->get(route('clinical.chart', $patient->id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinical/Chart')
            ->has('aiSummary.lines')
            ->where('aiSummary.label', 'AI draft - requires human review')
        );

    /** @var AgentAction $action */
    $action = AgentAction::where('tool_key', 'clinical.summarize_since_last_visit')->firstOrFail();

    $this->actingAs($doctor)
        ->post(route('clinical.summary.insert', $patient->id), ['action_id' => $action->id])
        ->assertRedirect();

    expect(ClinicalNote::where('status', ClinicalNote::STATUS_SIGNED)->count())->toBe(1)
        ->and(ClinicalNote::where('status', ClinicalNote::STATUS_DRAFT)->firstOrFail()->plan)->toContain('clinical_note:')
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});
