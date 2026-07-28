<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\ED\Models\EdTriage;
use Modules\ED\Models\EdVisit;
use Modules\ED\Services\EdVisitService;
use Modules\ED\Services\TriageService;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * ED.G3 — the ED tracking board: the live cockpit of active ED visits + their flow state + the RECORDED
 * acuity, reusing the ward-board idiom over the EdVisit flow. Per docs/HOSPITAL-PHASE6-ED-MAP.md §2.2/§3.
 * THE FENCE: operational flow facts + the recorded acuity (staff MAY sort by it) — NO computed priority
 * ranking / acuity-driven judgment.
 */

function edbCtx(): TenantContext
{
    return app(TenantContext::class);
}

function edbUser(Tenant $tenant, string $role): User
{
    // twoFactorEnabled so the mandatory-MFA middleware lets the request reach the route/gate (HTTP tests).
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, branch: Branch, edStaff: User, triageNurse: User, reception: User, nurseProfile: StaffProfile, v1: EdVisit, v2: EdVisit} */
function edbFixture(string $slug = 'edboard'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' ED', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    edbCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $edStaff = edbUser($tenant, 'ed_charge_nurse'); // ed.manage + triage.record
    $triageNurse = edbUser($tenant, 'triage_nurse');
    $reception = edbUser($tenant, 'reception');      // no ed.manage
    $nurseProfile = StaffProfile::query()->create([
        'first_name' => 'Nadia', 'last_name' => 'Nurse', 'display_name' => 'Nadia Nurse',
        'profession' => 'nurse', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $p1 = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Ada', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);
    $p2 = app(PatientService::class)->create(['first_name' => 'Bo', 'last_name' => 'Ben', 'date_of_birth' => '1985-05-05', 'sex' => 'male']);

    $v1 = app(EdVisitService::class)->register($edStaff, $p1, $branch, EdVisit::ARRIVAL_AMBULANCE, 'Chest pain', now()->subMinutes(40));
    $v2 = app(EdVisitService::class)->register($edStaff, $p2, $branch, EdVisit::ARRIVAL_WALK_IN, 'Sprained ankle', now()->subMinutes(10));
    // Triage the first visit → an ASSIGNED acuity (ESI 2). The second stays untriaged.
    app(TriageService::class)->record($edStaff, $v1, $nurseProfile, 'Central chest pain', EdTriage::SCALE_ESI, '2', ['heart_rate' => 108]);

    return compact('tenant', 'branch', 'edStaff', 'triageNurse', 'reception', 'nurseProfile', 'v1', 'v2');
}

test('the ED board renders the active visits with flow state + the RECORDED acuity + facts + summary counts', function () {
    $fx = edbFixture();
    edbCtx()->forget(); // request-level: the middleware re-establishes context from the authed user

    $this->actingAs($fx['edStaff'])->get('/ed/board')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ED/Board')
            ->has('visits', 2)
            // Ordered by ARRIVAL (a fact): v1 (40 min ago) before v2 (10 min ago).
            ->where('visits.0.patient', 'Erin Ada')
            ->where('visits.0.status', EdVisit::STATUS_TRIAGED)          // the flow state
            ->where('visits.0.acuity.scale', 'ESI')                     // the RECORDED acuity (a fact)
            ->where('visits.0.acuity.level', '2')
            ->where('visits.0.acuity.by', 'Nadia Nurse')                // provenance
            ->where('visits.1.patient', 'Bo Ben')
            ->where('visits.1.acuity', null)                            // untriaged → no acuity
            // Plain department counts — facts.
            ->where('summary.total', 2)
            ->where('summary.waiting', 2)
            ->where('summary.in_treatment', 0)
            ->etc());
});

test('FENCE: the board payload carries the recorded acuity + provenance but NO computed priority/ranking/score, and is ordered by ARRIVAL', function () {
    $fx = edbFixture();
    edbCtx()->forget();

    $this->actingAs($fx['edStaff'])->get('/ed/board')
        ->assertInertia(fn (Assert $page) => $page
            ->component('ED/Board')
            // The recorded acuity is present (a fact)...
            ->has('visits.0.acuity')
            // ...but there is NO computed-priority/ranking/score/severity/deterioration/wait-risk field.
            ->missing('visits.0.priority')
            ->missing('visits.0.rank')
            ->missing('visits.0.priority_score')
            ->missing('visits.0.score')
            ->missing('visits.0.severity')
            ->missing('visits.0.deterioration')
            ->missing('visits.0.wait_risk')
            ->missing('summary.priority')
            ->etc());

    // The server orders by arrival (a recorded fact), never a computed priority — v1 arrived before v2.
    $ordered = EdVisit::query()->whereIn('status', [EdVisit::STATUS_TRIAGED, EdVisit::STATUS_ARRIVED, EdVisit::STATUS_IN_TREATMENT, EdVisit::STATUS_AWAITING_DISPOSITION])
        ->orderBy('arrived_at')->pluck('id')->all();
    expect($ordered[0])->toBe($fx['v1']->id);

    // No priority/ranking computation anywhere in the board controller (the module computes no judgment).
    $board = File::get(base_path('Modules/ED/src/Http/Controllers/EdBoardController.php'));
    foreach (['computePriority', 'priorityScore', 'rankBy', 'acuityScore', 'whoNext', 'waitRisk'] as $needle) {
        expect(str_contains($board, $needle))->toBeFalse("the ED board must not compute a priority ({$needle})");
    }
});

test('a flow action from the board advances the visit through the EXISTING service (the G1 legal transitions)', function () {
    $fx = edbFixture();
    edbCtx()->forget();

    // Legal: the triaged v1 → in_treatment (through EdVisitService::transition, the G1 machine).
    $this->actingAs($fx['edStaff'])->post('/ed/visits/'.$fx['v1']->id.'/transition', ['status' => EdVisit::STATUS_IN_TREATMENT])
        ->assertRedirect(route('ed.board'));
    expect($fx['v1']->fresh()->status)->toBe(EdVisit::STATUS_IN_TREATMENT);

    // Illegal: the untriaged v2 (arrived) → in_treatment is rejected by the state machine; status unchanged.
    edbCtx()->forget();
    $this->actingAs($fx['edStaff'])->post('/ed/visits/'.$fx['v2']->id.'/transition', ['status' => EdVisit::STATUS_IN_TREATMENT])
        ->assertSessionHasErrors('ed_visit');
    expect($fx['v2']->fresh()->status)->toBe(EdVisit::STATUS_ARRIVED);
});

test('RBAC: the board + its flow action are ed.manage-gated; a non-ed role (reception) is refused (403)', function () {
    $fx = edbFixture();

    edbCtx()->forget();
    $this->actingAs($fx['reception'])->get('/ed/board')->assertForbidden();

    edbCtx()->forget();
    $this->actingAs($fx['reception'])->post('/ed/visits/'.$fx['v1']->id.'/transition', ['status' => EdVisit::STATUS_IN_TREATMENT])
        ->assertForbidden();
    expect($fx['v1']->fresh()->status)->toBe(EdVisit::STATUS_TRIAGED); // untouched
});

test('the board is tenant scoped: another tenant ED visit never appears', function () {
    $fxA = edbFixture('alpha');
    edbFixture('beta'); // a second tenant with its own active visits

    edbCtx()->forget();
    $this->actingAs($fxA['edStaff'])->get('/ed/board')
        ->assertInertia(fn (Assert $page) => $page
            ->component('ED/Board')
            ->has('visits', 2) // only tenant A's two visits — beta's are invisible
            ->where('visits.0.patient', 'Erin Ada')
            ->etc());
});
