<?php

use App\Services\AgentMetricsService;
use App\Services\NeedsHumanReader;
use Database\Seeders\DemoClinicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\Comms\Models\Message;
use Modules\Comms\Models\Thread;
use Modules\Comms\Services\ThreadService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * GOV.P2 — the "needs a human" reader.
 *
 * The wireframe drew an "escalated" slice on the outcome chart; GOV.P1 refused it because no query
 * could produce it. This is the real thing, and its risks are the mirror image of that refusal:
 *
 *   - a category that is not a real state (invented) — every one here has a cited setter and clearer;
 *   - a category MISSING from the set (a false all-clear) — the exhaustiveness control below turns
 *     red if one is dropped, and the panel names the worklists it deliberately excludes;
 *   - a fabricated urgency — there is no priority, SLA or overdue band, and the re-assertion scans
 *     for them over a NON-EMPTY payload.
 */

function nhSeed(): Tenant
{
    Storage::fake('local');
    (new DemoClinicSeeder)->run();

    $tenant = Tenant::query()->where('slug', DemoClinicSeeder::TENANT_SLUG)->firstOrFail();
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

/** A user holding a named role, so per-category permission scoping can be exercised for real. */
function nhUser(Tenant $tenant, string $roleKey): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
    ]);

    return $user;
}

/**
 * @param  array<string, mixed>  $result
 * @return array<string, mixed>
 */
function nhCategory(array $result, string $key): array
{
    foreach ($result['categories'] as $category) {
        if ($category['key'] === $key) {
            return $category;
        }
    }

    return [];
}

test('the fixture really contains one of each in-scope category', function () {
    nhSeed();

    // POSITIVE CONTROL (D-174): both categories have real rows, so every count below is a count of
    // something rather than a statement about an empty table.
    expect(AgentAction::query()->where('status', AgentAction::STATUS_PENDING)->count())->toBe(2);

    $flagged = Thread::query()->whereNotNull('clinician_attention_at')->get();
    expect($flagged)->toHaveCount(1);
    expect($flagged->first()->status)->toBe(Thread::STATUS_OPEN)
        ->and($flagged->first()->clinician_attention_reason)->not->toBeNull();
});

test('each category counts the REAL rows, and the exhaustive set is present', function () {
    $tenant = nhSeed();
    $admin = nhUser($tenant, 'org_admin');

    $result = app(NeedsHumanReader::class)->forUser($admin);

    /*
     * THE EXHAUSTIVENESS CONTROL. The set of categories is asserted whole — dropping one turns this
     * red. An omitted category is a false all-clear, which is the failure mode a worklist has that a
     * metric does not (the PC.P5 completeness lesson).
     */
    expect(collect($result['categories'])->pluck('key')->all())
        ->toBe([NeedsHumanReader::CATEGORY_APPROVALS, NeedsHumanReader::CATEGORY_CLINICIAN]);

    $approvals = nhCategory($result, NeedsHumanReader::CATEGORY_APPROVALS);
    $clinician = nhCategory($result, NeedsHumanReader::CATEGORY_CLINICIAN);

    expect($approvals['count'])->toBe(AgentAction::query()->where('status', AgentAction::STATUS_PENDING)->count())
        ->and($approvals['count'])->toBe(2)
        ->and($clinician['count'])->toBe(1)
        ->and($result['total'])->toBe(3);

    // The items describe the SAME set as the count, and carry a real waiting-since timestamp.
    expect($approvals['items'])->toHaveCount(2)
        ->and($clinician['items'])->toHaveCount(1)
        ->and($clinician['items'][0]['waitingSince'])->not->toBeNull()
        ->and($clinician['items'][0]['reason'])->not->toBeNull();

    // Each category links to where a person actually acts on it.
    expect($approvals['actionUrl'])->toBe(route('governance.approvals.index'))
        ->and($clinician['actionUrl'])->toBe(route('comms.inbox'));

    // The excluded-but-real work is NAMED, so an empty panel could never read as a global all-clear.
    expect($result['elsewhere'])->not->toBeEmpty()
        ->and($result['unproducible'])->toBe(['operator_access_request']);
});

test('ONE DEFINITION — the pending count agrees with the dashboard and the queue itself', function () {
    $tenant = nhSeed();
    $admin = nhUser($tenant, 'org_admin');

    $needs = app(NeedsHumanReader::class)->forUser($admin);
    $dashboard = app(AgentMetricsService::class)->window(now()->subDays(29), now());

    $fromReader = nhCategory($needs, NeedsHumanReader::CATEGORY_APPROVALS)['count'];
    $fromQueue = AgentAction::query()->where('status', AgentAction::STATUS_PENDING)->count();

    expect($fromReader)->toBe($dashboard['pendingNow'], 'the panel and the dashboard disagree about the queue depth')
        ->and($fromReader)->toBe($fromQueue, 'the panel disagrees with the approval queue itself');
});

test('resolving an item removes it — proven by driving the REAL path', function () {
    $tenant = nhSeed();
    $admin = nhUser($tenant, 'org_admin');
    $reader = app(NeedsHumanReader::class);

    $before = $reader->forUser($admin);
    expect(nhCategory($before, NeedsHumanReader::CATEGORY_APPROVALS)['count'])->toBe(2);

    /*
     * Rejected through ApprovalQueue::reject() — the real gate, with the reason it requires. Not a
     * status write: a category that only empties when someone edits a column would prove nothing
     * about the queue a person actually works (the GOV.P4 discipline).
     */
    $pending = AgentAction::query()->where('status', AgentAction::STATUS_PENDING)->orderBy('created_at')->firstOrFail();
    app(ApprovalQueue::class)->reject($pending, $admin, 'Not needed — handled by phone.');

    expect(nhCategory($reader->forUser($admin), NeedsHumanReader::CATEGORY_APPROVALS)['count'])->toBe(1);

    // And the clinician hand-off clears the same way: a real staff REPLY, through ThreadService.
    $flagged = Thread::query()->whereNotNull('clinician_attention_at')->firstOrFail();
    expect(nhCategory($reader->forUser($admin), NeedsHumanReader::CATEGORY_CLINICIAN)['count'])->toBe(1);

    app(ThreadService::class)->postStaffMessage($flagged, $admin, 'Dr Brunner will call you this afternoon.');

    $after = $reader->forUser($admin);
    expect(nhCategory($after, NeedsHumanReader::CATEGORY_CLINICIAN)['count'])->toBe(0);

    /*
     * ...and note WHAT DID NOT HAPPEN: the flag itself is still set, because nothing in the codebase
     * clears it. That is exactly why "still waiting" is defined as the conjunction rather than as the
     * column — a count of flagged threads could never fall.
     */
    expect($flagged->refresh()->clinician_attention_at)->not->toBeNull();
});

test('CLOSING a flagged thread also resolves it — the second half of the definition', function () {
    $tenant = nhSeed();
    $admin = nhUser($tenant, 'org_admin');
    $reader = app(NeedsHumanReader::class);

    /*
     * A mutation caught this gap: with only the reply path tested, deleting the `status = OPEN`
     * conjunct from the reader left the suite green — the fixture's one flagged thread was open
     * either way, so nothing measured that clause.
     *
     * A human has TWO ways to deal with a flagged thread, and the reader's definition names both:
     * answer it, or close it. This pins the second, through the real ThreadService::close(), so the
     * conjunct is load-bearing (D-182: the case must be one that would otherwise still count).
     */
    $flagged = Thread::query()->whereNotNull('clinician_attention_at')->firstOrFail();

    // POSITIVE CONTROL: it is counted right now, and it is counted because it is OPEN and unanswered.
    expect(nhCategory($reader->forUser($admin), NeedsHumanReader::CATEGORY_CLINICIAN)['count'])->toBe(1);
    expect($flagged->status)->toBe(Thread::STATUS_OPEN);

    app(ThreadService::class)->close($flagged, $admin);

    expect(nhCategory($reader->forUser($admin), NeedsHumanReader::CATEGORY_CLINICIAN)['count'])->toBe(0);

    // Closing answered nobody: no staff reply was posted, and the flag is still set — so the ONLY
    // thing that removed it from the list is the thread no longer being open.
    expect($flagged->refresh()->status)->toBe(Thread::STATUS_CLOSED)
        ->and($flagged->clinician_attention_at)->not->toBeNull();
    expect(
        Message::query()
            ->where('thread_id', $flagged->id)
            ->where('author_type', Message::AUTHOR_STAFF)
            ->where('sent_at', '>=', $flagged->clinician_attention_at)
            ->exists()
    )->toBeFalse();
});
test('per-category permission scoping is FAIL-CLOSED, and never shows another category instead', function () {
    $tenant = nhSeed();
    $reader = app(NeedsHumanReader::class);

    /*
     * D-183 — pinned at the reader, called DIRECTLY, so no route middleware can answer first. A
     * reception user holds comms.manage but not ai.manage; the approvals category must come back
     * invisible and empty for them, while the category they MAY see still works. That second half is
     * the control: an "everything is hidden" result would pass a weaker test for the wrong reason.
     */
    $reception = nhUser($tenant, 'reception');
    $result = $reader->forUser($reception);

    $approvals = nhCategory($result, NeedsHumanReader::CATEGORY_APPROVALS);
    $clinician = nhCategory($result, NeedsHumanReader::CATEGORY_CLINICIAN);

    expect($approvals['visible'])->toBeFalse()
        ->and($approvals['count'])->toBe(0)
        ->and($approvals['items'])->toBe([])
        // The control: the permission they DO hold still returns real data.
        ->and($clinician['visible'])->toBeTrue()
        ->and($clinician['count'])->toBe(1);

    // The viewer's total reflects only what they can see — they are not told the queue is empty.
    expect($result['total'])->toBe(1);

    // A user with no role at all sees nothing anywhere, and no category leaks another's rows.
    $nobody = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $blind = $reader->forUser($nobody);

    foreach ($blind['categories'] as $category) {
        expect($category['visible'])->toBeFalse()
            ->and($category['count'])->toBe(0)
            ->and($category['items'])->toBe([]);
    }
    expect($blind['total'])->toBe(0);

    // POSITIVE CONTROL: an admin sees both, so the zeroes above are the gate and not an empty tenant.
    $admin = nhUser($tenant, 'org_admin');
    expect($reader->forUser($admin)['total'])->toBe(3);
});

test('THE RE-ASSERTION: no urgency, no SLA, no priority, no clinical content', function () {
    $tenant = nhSeed();
    $admin = nhUser($tenant, 'org_admin');

    $props = $this->actingAs($admin)->get(route('governance.dashboard'))->assertOk()->viewData('page')['props'];

    // POSITIVE CONTROL (D-174): the panel is NON-EMPTY, so the scan reads a populated payload.
    expect($props['needsHuman']['total'])->toBeGreaterThan(0);
    expect(collect($props['needsHuman']['categories'])->sum(fn (array $c): int => count($c['items'])))->toBeGreaterThan(0);

    $squashed = preg_replace('~[^a-z0-9]~', '', strtolower(json_encode($props['needsHuman']) ?: '')) ?? '';
    expect(strlen($squashed))->toBeGreaterThan(200);

    foreach ([
        'urgency', 'urgent', 'priority', 'sla', 'overdue', 'breach', 'escalatedcount',
        'riskscore', 'severity', 'critical', 'agerank', 'importance',
    ] as $token) {
        expect(str_contains($squashed, $token))->toBeFalse("the needs-a-human payload carries '{$token}'");
    }

    /*
     * NO CLINICAL CONTENT — and the line is drawn precisely, because a worklist needs to identify
     * its items.
     *
     * PERMITTED: the thread's SUBJECT (a staff-authored title, already visible to everyone in the
     * inbox) and the recorded refusal REASON (a routing code the fence wrote). Both are the item's
     * identity; without them the row is an anonymous "1 waiting" that nobody can act on.
     *
     * NEVER: the patient's own words. In the demo the flagged message reads "mir ist seit gestern
     * schwindlig" — a symptom — and that sentence is what a governance screen must not carry
     * (GOV.P1 refused "possible red flag — chest tightness" for the same reason).
     *
     * The body is read FROM THE DATABASE rather than hardcoded, so this stays true if the seed
     * changes — and it catches a body in any language, which a fixed English word list would not.
     */
    $flagged = Thread::query()->whereNotNull('clinician_attention_at')->firstOrFail();
    $patientWords = Message::query()
        ->where('thread_id', $flagged->id)
        ->where('author_type', Message::AUTHOR_PATIENT)
        ->pluck('body');

    // POSITIVE CONTROL: there IS a patient message with real clinical content to leak.
    expect($patientWords)->not->toBeEmpty();

    foreach ($patientWords as $body) {
        $squashedBody = preg_replace('~[^a-z0-9]~', '', strtolower((string) $body)) ?? '';
        expect(strlen($squashedBody))->toBeGreaterThan(20);
        expect(str_contains($squashed, $squashedBody))->toBeFalse('the patient message body reached the governance panel');

        // ...and not merely absent as a whole string: no distinctive run of it appears either.
        expect(str_contains($squashed, substr($squashedBody, 0, 25)))->toBeFalse('part of the patient message body reached the governance panel');
    }

    foreach (['dizzy', 'schwindlig', 'chesttightness', 'symptom', 'diagnos', 'mrn', 'dateofbirth'] as $clinical) {
        expect(str_contains($squashed, $clinical))->toBeFalse("clinical content '{$clinical}' on a governance surface");
    }

    // D-173 — the scan follows the files it depends on.
    foreach ([
        base_path('app/Services/NeedsHumanReader.php'),
        base_path('resources/js/pages/Governance/Dashboard.vue'),
    ] as $path) {
        expect(file_exists($path))->toBeTrue(basename($path).' is missing — this fence would scan nothing');
    }

    // No styling keyed to how long something has waited (D-169): the timestamp is displayed, never
    // used to tint anything.
    $source = (string) file_get_contents(base_path('resources/js/pages/Governance/Dashboard.vue'));
    $stripped = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;
    preg_match_all('~:class="([^"]*)"~', $stripped, $bindings);
    foreach ($bindings[1] ?? [] as $binding) {
        foreach (['waiting', 'age', 'overdue', 'urgen', 'sever'] as $judgment) {
            expect(str_contains(strtolower($binding), $judgment))->toBeFalse("a class binding is driven by '{$judgment}'");
        }
    }
});

test('the empty state is honest — and states its boundary rather than claiming a global all-clear', function () {
    $tenant = nhSeed();
    $admin = nhUser($tenant, 'org_admin');

    // Empty the two categories through the REAL paths.
    foreach (AgentAction::query()->where('status', AgentAction::STATUS_PENDING)->get() as $action) {
        app(ApprovalQueue::class)->reject($action, $admin, 'Cleared for this test through the real gate.');
    }
    foreach (Thread::query()->whereNotNull('clinician_attention_at')->get() as $thread) {
        app(ThreadService::class)->postStaffMessage($thread, $admin, 'Answered by a clinician.');
    }

    $result = app(NeedsHumanReader::class)->forUser($admin);
    expect($result['total'])->toBe(0);

    /*
     * The panel may now say "nothing in agent governance is waiting" — and that claim is true only
     * because it is SCOPED. The copy names the boundary and the worklists that live elsewhere, so an
     * empty panel cannot be read as "nothing anywhere needs a person". Asserted, because copy is
     * exactly what rots quietly.
     */
    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    $copy = $en['governance']['needsHuman'];

    expect(strtolower($copy['empty']))->toContain('agent governance');
    expect(strtolower($copy['scope']['body']))->toContain('own screen');
    expect($copy['elsewhere'])->toHaveKeys(['orders_review', 'recalls_due', 'referrals_draft', 'notes_draft']);
    // The state that cannot occur today is stated, not listed as an empty queue.
    expect(strtolower($copy['unproducible']['operator_access_request']))->toContain('switched off');
});
