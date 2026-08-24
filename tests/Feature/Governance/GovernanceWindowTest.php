<?php

use App\Services\AgentMetricsService;
use Database\Seeders\DemoClinicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\AiCore\Models\Agent;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * GOV.P1 — the windowed governance reader (G1) and the dashboard over it.
 *
 * The governance audit found the wireframe drawing numbers the product cannot source: a confidence
 * score with no signal, a "0 breaches" tally with no breach record, an "escalated" outcome that is
 * not a status, a KB-gap ranking with no telemetry, and nine tool keys that were never built. On
 * this screen the fence IS the product, so these tests are as much about what must NOT appear as
 * about what must.
 *
 * Every count is checked against GOV.P4's real seeded spread — rejected 12 days ago, executed-as-is
 * 5 days ago, edited-then-approved 2 days ago, fence-refused and two pending today — so a window
 * boundary either includes the right rows or the test fails.
 */

function gwSeed(): Tenant
{
    Storage::fake('local');
    (new DemoClinicSeeder)->run();

    $tenant = Tenant::query()->where('slug', DemoClinicSeeder::TENANT_SLUG)->firstOrFail();
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

/** An auditor who may actually open the dashboard (`audit.view`). */
function gwAuditor(Tenant $tenant): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);

    return $user;
}

test('G1 counts the REAL records, and a window boundary genuinely excludes what falls outside it', function () {
    gwSeed();
    $metrics = app(AgentMetricsService::class);

    // 30 days: everything GOV.P4 seeded.
    $wide = $metrics->window(Carbon::today()->subDays(29), Carbon::today());

    expect($wide['byStatus'][AgentAction::STATUS_EXECUTED] ?? 0)->toBe(2)
        ->and($wide['byStatus'][AgentAction::STATUS_REJECTED] ?? 0)->toBe(1)
        ->and($wide['byStatus'][AgentAction::STATUS_FENCE_REFUSED] ?? 0)->toBe(1)
        ->and($wide['byStatus'][AgentAction::STATUS_PENDING] ?? 0)->toBe(2);

    /*
     * 7 days: the rejection is 12 days old, so it MUST fall out — and everything else must stay.
     * This is the assertion a mis-windowed reader fails, and it only means something because the
     * fixture really does straddle the boundary (D-174).
     */
    $narrow = $metrics->window(Carbon::today()->subDays(6), Carbon::today());

    expect($narrow['byStatus'][AgentAction::STATUS_REJECTED] ?? 0)->toBe(0, 'the 12-day-old rejection must fall outside a 7-day window')
        ->and($narrow['byStatus'][AgentAction::STATUS_EXECUTED] ?? 0)->toBe(2)
        ->and($narrow['byStatus'][AgentAction::STATUS_FENCE_REFUSED] ?? 0)->toBe(1);

    // ...and a window BEFORE anything happened is empty rather than defaulting to everything.
    $ancient = $metrics->window(Carbon::today()->subDays(400), Carbon::today()->subDays(300));
    expect(array_sum($ancient['byStatus']))->toBe(0)
        ->and($ancient['ledgerTotal'])->toBe(0);

    // The counts are the database's, not the reader's arithmetic.
    expect($wide['byStatus'][AgentAction::STATUS_EXECUTED])
        ->toBe(AgentAction::query()->where('status', AgentAction::STATUS_EXECUTED)->count());
});

test('the reader and the agent pages agree — ONE definition of approved-as-is', function () {
    gwSeed();
    $metrics = app(AgentMetricsService::class);

    $window = $metrics->window(Carbon::today()->subDays(29), Carbon::today());
    $byKey = collect($window['byAgent'])->keyBy('key');

    /*
     * The dashboard and the agent's own page must never disagree about the same number. They share
     * one private helper, and this asserts the outcome of that sharing rather than trusting it.
     */
    foreach (['inbox', 'scheduler', 'recall', 'dispatch'] as $key) {
        $agent = Agent::query()->where('key', $key)->firstOrFail();
        expect($byKey[$key]['approvedAsIsPct'])->toBe(
            $metrics->hero($agent)['approvedAsIsPct'],
            "the dashboard and the {$key} agent page disagree about approved-as-is",
        );
    }

    // The real values over GOV.P4's spread, and the CONTROL that makes them meaningful: an agent
    // with nothing resolved stays honestly null → "—", never a fabricated 0 (the AGENT.P5 rule).
    expect($byKey['scheduler']['approvedAsIsPct'])->toBe(100)
        ->and($byKey['inbox']['approvedAsIsPct'])->toBe(0)
        ->and($byKey['dispatch']['approvedAsIsPct'])->toBeNull()
        ->and($byKey['clinical_summary']['approvedAsIsPct'])->toBeNull();

    // Every canonical agent is present even at zero — an absent row would read as "no such agent".
    expect(collect($window['byAgent'])->pluck('key')->sort()->values()->all())
        ->toBe(['billing', 'clinical_summary', 'dispatch', 'inbox', 'recall', 'scheduler']);
});

test('per-tool activity contains ONLY registered tools — the nine invented keys cannot appear', function () {
    gwSeed();

    $registered = array_keys(app(ToolRegistry::class)->all());
    $window = app(AgentMetricsService::class)->window(Carbon::today()->subDays(29), Carbon::today());

    // POSITIVE CONTROL (D-182): the list is NOT empty, so "contains only registry keys" is a real
    // constraint on real rows rather than a statement about nothing.
    expect($window['byTool'])->not->toBeEmpty();

    foreach ($window['byTool'] as $tool) {
        expect(in_array($tool['key'], $registered, true))->toBeTrue("unregistered tool '{$tool['key']}' reached the dashboard");
    }

    /*
     * The specific keys the wireframe drew. Each names an ACTING capability — send, sign, charge,
     * book — and none of them was ever built; printing one would tell a reader it exists and was
     * merely refused (D-170).
     */
    $invented = [
        'comms.send', 'clinical.sign', 'billing.charge', 'recall.send_batch',
        'clinical.summary_draft', 'nursing.dispatch_suggest',
        'scheduling.read', 'scheduling.book', 'comms.draft',
    ];
    $emitted = collect($window['byTool'])->pluck('key')->all();
    foreach ($invented as $key) {
        expect(in_array($key, $emitted, true))->toBeFalse("the invented tool '{$key}' appears on the dashboard");
        expect(in_array($key, $registered, true))->toBeFalse("'{$key}' has become a real tool — this test needs revisiting");
    }

    // Each tool states its REAL ceiling, so the screen names the cap instead of implying autonomy.
    foreach ($window['byTool'] as $tool) {
        expect(in_array($tool['ceiling'], ['off', 'suggest', 'approve', 'auto'], true))->toBeTrue();
        if (in_array($tool['category'], ['clinical', 'financial'], true)) {
            expect($tool['ceiling'])->not->toBe('auto', 'a clinical/financial tool may never reach auto');
        }
    }
});

test('the range picker re-parameterises the SERVER, not a client filter', function () {
    $tenant = gwSeed();
    $auditor = gwAuditor($tenant);

    $wide = $this->actingAs($auditor)->get(route('governance.dashboard', ['range' => '30d']))
        ->assertOk()->viewData('page')['props'];
    $narrow = $this->actingAs($auditor)->get(route('governance.dashboard', ['range' => '7d']))
        ->assertOk()->viewData('page')['props'];

    /*
     * The two responses carry DIFFERENT server-computed figures. A client-side filter would have
     * returned the same payload twice and re-sliced it in the browser — which could only narrow what
     * was already fetched, and would disagree with the database beyond the page size.
     */
    expect($wide['metrics']['byStatus'][AgentAction::STATUS_REJECTED] ?? 0)->toBe(1)
        ->and($narrow['metrics']['byStatus'][AgentAction::STATUS_REJECTED] ?? 0)->toBe(0)
        ->and($wide['metrics']['from'])->not->toBe($narrow['metrics']['from'])
        ->and($narrow['range'])->toBe('7d');

    // The ledger table is windowed on the server too.
    expect(count($wide['windowLedger']))->toBeGreaterThan(count($narrow['windowLedger']));

    // An unknown range falls back to the default rather than erroring on a hand-edited URL.
    $bogus = $this->actingAs($auditor)->get(route('governance.dashboard', ['range' => 'all-time-ever']))
        ->assertOk()->viewData('page')['props'];
    expect($bogus['range'])->toBe('30d');
});

test('THE RE-ASSERTION: no fabricated metric, no invented status, no clinical content', function () {
    $tenant = gwSeed();
    $auditor = gwAuditor($tenant);

    $props = $this->actingAs($auditor)->get(route('governance.dashboard'))->assertOk()->viewData('page')['props'];

    // POSITIVE CONTROL (D-174): the payload is NON-EMPTY and carries real activity, so the scan below
    // is looking at a populated screen rather than passing on absence.
    expect(array_sum($props['metrics']['byStatus']))->toBeGreaterThan(0)
        ->and($props['metrics']['byTool'])->not->toBeEmpty()
        ->and($props['windowLedger'])->not->toBeEmpty();

    $squashed = preg_replace('~[^a-z0-9]~', '', strtolower(json_encode($props) ?: '')) ?? '';
    expect(strlen($squashed))->toBeGreaterThan(500);

    /*
     * The audit's list, each with its reason:
     *  - confidence/threshold — no runtime signal (AGENT.P6 deferred it honestly);
     *  - breach — nothing records one, so any count is unfalsifiable;
     *  - escalated — not an agent_action status; the real hand-off is clinician_attention;
     *  - kbgap/ungrounded — no telemetry exists at all;
     *  - signoff — implies AUTO is reachable, which it never is for clinical/financial;
     *  - redflag/triage/severity/urgency — clinical judgment on an audit.view screen.
     */
    $forbidden = [
        'confidencescore', 'confidencethreshold', 'breach', 'escalatedpct', 'kbgap', 'ungrounded',
        'governancesignoff', 'redflag', 'triage', 'severityband', 'riskscore', 'accuracy',
        'qualityscore', 'trendpct',
    ];
    foreach ($forbidden as $token) {
        expect(str_contains($squashed, $token))->toBeFalse("the dashboard payload carries '{$token}'");
    }

    // Only REAL statuses are counted.
    foreach (array_keys($props['metrics']['byStatus']) as $status) {
        expect(in_array($status, [
            AgentAction::STATUS_PENDING, AgentAction::STATUS_EXECUTED,
            AgentAction::STATUS_REJECTED, AgentAction::STATUS_FENCE_REFUSED,
        ], true))->toBeTrue("invented status '{$status}' on the dashboard");
    }

    // No clinical content: the demo's flagged thread carries a symptom sentence, and none of it may
    // reach a governance screen.
    foreach (['chesttightness', 'dizzy', 'symptom', 'diagnos', 'mrn'] as $clinical) {
        expect(str_contains($squashed, $clinical))->toBeFalse("clinical content '{$clinical}' on the governance dashboard");
    }

    // D-173 — the scan follows the file, and fails loudly if it moves out from under it.
    $path = base_path('resources/js/pages/Governance/Dashboard.vue');
    expect(file_exists($path))->toBeTrue('Dashboard.vue is missing — this fence would scan nothing');
    $source = (string) file_get_contents($path);
    $stripped = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;
    $stripped = preg_replace('~/\*.*?\*/~s', ' ', $stripped) ?? $stripped;

    foreach (['comms.send', 'clinical.sign', 'billing.charge', 'recall.send_batch'] as $inventedKey) {
        expect(str_contains($stripped, $inventedKey))->toBeFalse("Dashboard.vue names the invented tool '{$inventedKey}'");
    }

    /*
     * D-169 — no styling keyed to a judgment. The fence COUNT may be emphasised (it is a count, not
     * a grade), but nothing may be tinted by severity, risk or urgency.
     */
    preg_match_all('~:class="([^"]*)"~', $stripped, $bindings);
    foreach ($bindings[1] ?? [] as $binding) {
        foreach (['sever', 'risk', 'urgen', 'critical'] as $judgment) {
            expect(str_contains(strtolower($binding), $judgment))->toBeFalse("a class binding is driven by '{$judgment}'");
        }
    }
});

test('the omissions are STATED, not silently dropped', function () {
    $tenant = gwSeed();
    $auditor = gwAuditor($tenant);

    $this->actingAs($auditor)->get(route('governance.dashboard'))->assertOk();

    /*
     * The P5/PT precedent: where the wireframe promised a number we cannot source, the screen says
     * so. A reader who expected it learns it has no source rather than assuming a bug — and the
     * copy is the thing that would otherwise quietly rot, so it is asserted.
     */
    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    $omitted = $en['governance']['omitted'] ?? [];

    expect($omitted)->toHaveKeys(['confidence', 'breaches', 'kbGaps', 'escalated']);
    expect(strtolower($omitted['breaches']))->toContain('unfalsifiable');
    expect(strtolower($omitted['confidence']))->toContain('no confidence signal');
    expect(strtolower($omitted['escalated']))->toContain('thread');

    // The fence card states what it counts, and explicitly not a breach tally.
    expect(strtolower($en['governance']['window']['fence']['note']))->toContain('no "breaches" figure');
});

test('the dashboard stays audit.view gated and tenant-scoped', function () {
    $tenant = gwSeed();

    // A user with no role holds no permission — fail closed.
    $nobody = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $this->actingAs($nobody)->get(route('governance.dashboard'))->assertForbidden();

    // POSITIVE CONTROL: the auditor gets in, so the 403 above is the gate and not a broken route.
    $this->actingAs(gwAuditor($tenant))->get(route('governance.dashboard'))->assertOk();
});
