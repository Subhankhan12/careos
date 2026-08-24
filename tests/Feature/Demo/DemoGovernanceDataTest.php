<?php

use App\Services\AgentMetricsService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoClinicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\AiCore\Models\Agent;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\AiInteraction;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * GOV.P4 — the demo tenant must be able to SHOW every governance outcome.
 *
 * The audit (GOVERNANCE-AI-BATCH-DIFF.md) found the demo seed produced only `pending` and
 * `fence_refused`, so the Resolved and Rejected screens had nothing to display and AGENT.P5's
 * approved-as-is percentage had no denominator.
 *
 * The property these tests defend is not "the rows exist" — anyone can insert rows. It is that
 * **each state was reached by traversing its real guard**: an executed action really went through
 * `ApprovalQueue::approve()` (re-authorise + re-execute), a rejected one through `reject()` with the
 * reason the service demands, and the fence refusal by the fence actually firing. So each assertion
 * below looks for the FINGERPRINTS a real traversal leaves — a result the tool produced, the ledger
 * rows only that path writes, the `human_edited` provenance an edit stamps — and not merely a status
 * string, which is the one thing a hand-inserted row could fake.
 */

function govSeed(): Tenant
{
    Storage::fake('local');
    (new DemoClinicSeeder)->run();

    $tenant = Tenant::query()->where('slug', DemoClinicSeeder::TENANT_SLUG)->firstOrFail();
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

/**
 * The ledger rows an action accumulated, by outcome. `output_ref` is the action id, which is what
 * the real approve/reject paths stamp — a row that was not written by those paths will not be here.
 *
 * @return array<string, int>
 */
function govLedgerFor(AgentAction $action): array
{
    return AiInteraction::query()
        ->where('output_ref', $action->id)
        ->get()
        ->groupBy('outcome')
        ->map(fn ($rows): int => $rows->count())
        ->all();
}

test('the demo tenant carries at least one action in EVERY real outcome', function () {
    govSeed();

    // Asserted PER STATUS, not as a total: a total can be satisfied while a screen stays empty.
    $byStatus = AgentAction::query()->get()->groupBy('status')->map(fn ($r): int => $r->count());

    expect($byStatus[AgentAction::STATUS_PENDING] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and($byStatus[AgentAction::STATUS_EXECUTED] ?? 0)->toBeGreaterThanOrEqual(2)
        ->and($byStatus[AgentAction::STATUS_REJECTED] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and($byStatus[AgentAction::STATUS_FENCE_REFUSED] ?? 0)->toBeGreaterThanOrEqual(1);

    // The four statuses on the model are the whole outcome set — nothing seeded is off-menu.
    $known = [
        AgentAction::STATUS_PENDING,
        AgentAction::STATUS_EXECUTED,
        AgentAction::STATUS_REJECTED,
        AgentAction::STATUS_FENCE_REFUSED,
    ];
    foreach ($byStatus->keys() as $status) {
        expect(in_array($status, $known, true))->toBeTrue("unknown seeded status '{$status}'");
    }
});

test('the EXECUTED actions really went through approve() — a tool result, and the ledger rows only that path writes', function () {
    govSeed();

    $executed = AgentAction::query()->where('status', AgentAction::STATUS_EXECUTED)->get();
    expect($executed)->toHaveCount(2);

    foreach ($executed as $action) {
        /*
         * The fingerprints of a real traversal. A hand-written status could not produce any of
         * these: `result` is what the TOOL returned, `approved_at`/`executed_at` are stamped inside
         * approve(), `reviewed_by` is the re-authorised reviewer, and the ledger carries the
         * approved + executed pair that only approve() writes.
         */
        expect($action->result)->toBeArray()
            ->and($action->result)->not->toBeEmpty()
            ->and($action->approved_at)->not->toBeNull()
            ->and($action->executed_at)->not->toBeNull()
            ->and($action->reviewed_by)->not->toBeNull()
            ->and($action->rejection_reason)->toBeNull();

        $ledger = govLedgerFor($action);
        expect($ledger['approved'] ?? 0)->toBe(1)
            ->and($ledger['executed'] ?? 0)->toBe(1);
    }

    // The approved-AS-IS one: no edit, so it is what gives AGENT.P5's percentage a numerator.
    $asIs = $executed->firstWhere('edited_payload', null);
    expect($asIs)->not->toBeNull();
    expect($asIs->tool_key)->toBe('scheduler.suggest_slots');
    /*
     * Its execute() only READS the availability finder — the genuinely inert choice for a demo.
     * (`billing.preflight_invoice` was tried first and REJECTED: its validator persists validation
     * state and flipped the demo's dunning fee from draft to validated, breaking an invariant an
     * earlier gate pinned. A "report" can still write.)
     *
     * The result is the finder's own payload, and it says outright that approving books nothing.
     */
    expect($asIs->result)->toHaveKey('slots');
    expect($asIs->result['books_on_approval'] ?? null)->toBeFalse();
});

test('one EXECUTED action was EDITED through the gate, and says so', function () {
    govSeed();

    $edited = AgentAction::query()
        ->where('status', AgentAction::STATUS_EXECUTED)
        ->whereNotNull('edited_payload')
        ->get();

    expect($edited)->toHaveCount(1);
    $action = $edited->first();

    // The edit is recorded in all three places the real path stamps it.
    expect($action->edited_payload)->toBeArray()
        ->and($action->edited_payload['draft'] ?? null)->toBeArray()
        ->and($action->result['human_edited'] ?? null)->toBeTrue();

    $ledgerRows = AiInteraction::query()->where('output_ref', $action->id)->get();
    $edits = $ledgerRows->filter(fn (AiInteraction $r): bool => ($r->metadata['human_edited'] ?? null) === true);
    expect($edits->count())->toBe(2, 'the approved + executed ledger rows should both carry human_edited');

    /*
     * The edit went THROUGH the gate, not around it: the posted message is the human's wording and
     * it still carries the ai_assisted marker the tool sets, which only the real execute() writes.
     */
    expect($action->result['ai_assisted'] ?? null)->toBeTrue();
    expect($action->result)->toHaveKey('message_id');

    $posted = DB::table('messages')->where('id', $action->result['message_id'])->first();
    expect($posted)->not->toBeNull();
    expect($posted->body)->toContain('08:00');
    expect((int) $posted->ai_assisted)->toBe(1);
});

test('the REJECTED action carries the reason the service requires, and executed nothing', function () {
    govSeed();

    $rejected = AgentAction::query()->where('status', AgentAction::STATUS_REJECTED)->get();
    expect($rejected)->toHaveCount(1);
    $action = $rejected->first();

    expect(trim((string) $action->rejection_reason))->not->toBe('')
        ->and($action->rejected_at)->not->toBeNull()
        ->and($action->reviewed_by)->not->toBeNull()
        // Nothing ran: no result, no execution timestamps.
        ->and($action->result)->toBeNull()
        ->and($action->executed_at)->toBeNull()
        ->and($action->approved_at)->toBeNull();

    // The real reject path writes a `rejected` ledger row carrying the same reason — and NO
    // executed row, which is what proves nothing was posted.
    $ledger = govLedgerFor($action);
    expect($ledger['rejected'] ?? 0)->toBe(1)
        ->and($ledger['executed'] ?? 0)->toBe(0);

    $row = AiInteraction::query()->where('output_ref', $action->id)->where('outcome', 'rejected')->firstOrFail();
    expect($row->metadata['reason'] ?? null)->toBe($action->rejection_reason);
});

test('the FENCE-REFUSED action traversed the fence — it was not status-set', function () {
    govSeed();

    $refused = AgentAction::query()->where('status', AgentAction::STATUS_FENCE_REFUSED)->get();
    expect($refused)->toHaveCount(1);
    $action = $refused->first();

    /*
     * THE PROOF THAT THE FENCE FIRED, rather than a column being written:
     *  - the reason is the FENCE's own message from FenceRefusalException, not seeder prose;
     *  - there is no result and no executed_at, because execute() threw before returning;
     *  - the ledger carries an `approved` row FOLLOWED BY a `fence_refused` one — approve() records
     *    the approval first and the refusal only when the tool throws. A hand-set status would have
     *    neither row, and no code path produces that pair except a real refusal at approve time.
     */
    expect($action->rejection_reason)->toBe('This draft handed off to a human; there is nothing to send.')
        ->and($action->fence_refused_at)->not->toBeNull()
        ->and($action->result)->toBeNull()
        ->and($action->executed_at)->toBeNull();

    $ledger = govLedgerFor($action);
    expect($ledger['approved'] ?? 0)->toBe(1)
        ->and($ledger['fence_refused'] ?? 0)->toBe(1)
        ->and($ledger['executed'] ?? 0)->toBe(0);

    // Nothing was posted to the thread the refusal protected.
    expect(DB::table('messages')->where('ai_assisted', true)->count())
        ->toBe(1, 'only the edited-then-approved draft should have posted an ai_assisted message');
});

test('AGENT.P5 approved-as-is now has a REAL denominator, not "—"', function () {
    govSeed();

    $metrics = app(AgentMetricsService::class);

    $scheduler = Agent::query()->where('key', 'scheduler')->firstOrFail();
    $inbox = Agent::query()->where('key', 'inbox')->firstOrFail();

    /*
     * POSITIVE CONTROL (D-174): the number is honestly null when nothing has resolved, so a non-null
     * value here is only reachable because real actions really resolved. Both agents now have one.
     */
    $schedulerHero = $metrics->hero($scheduler);
    $inboxHero = $metrics->hero($inbox);

    expect($schedulerHero['approvedAsIsPct'])->not->toBeNull()
        // scheduler resolved one: the suggest-slots approve, unedited.
        ->and($schedulerHero['approvedAsIsPct'])->toBe(100)
        ->and($inboxHero['approvedAsIsPct'])->not->toBeNull()
        // inbox resolved two: one EDITED execute and one fence refusal — neither is approved-as-is.
        ->and($inboxHero['approvedAsIsPct'])->toBe(0)
        ->and($inboxHero['fenceRefused7d'])->toBeGreaterThanOrEqual(1);

    /*
     * ...and an agent with nothing resolved STILL reports honestly absent. This is the control that
     * makes the two numbers above meaningful: the "—" path is intact, so a real percentage is a real
     * percentage and not a default (AGENT.P5's honesty rule).
     */
    $dispatch = Agent::query()->where('key', 'dispatch')->firstOrFail();
    expect($metrics->hero($dispatch)['approvedAsIsPct'])->toBeNull();
});

test('the production seed path creates NO demo governance data', function () {
    /*
     * D-182-shaped: this assertion FAILS if DemoClinicSeeder is ever wired into DatabaseSeeder. It
     * runs the production seeder and then looks for the very rows the demo seeder produces — so the
     * refusal is meaningful rather than an accident of an empty database.
     */
    $this->seed(DatabaseSeeder::class);

    // Counted in SYSTEM mode: the models are tenant-scoped and fail closed without a context, and
    // the claim here is about the WHOLE database, not one tenant.
    $counts = app(TenantContext::class)->system(fn (): array => [
        'actions' => AgentAction::query()->count(),
        'ledger' => AiInteraction::query()->count(),
    ]);

    expect($counts['actions'])->toBe(0)
        ->and($counts['ledger'])->toBe(0)
        ->and(Tenant::query()->where('slug', DemoClinicSeeder::TENANT_SLUG)->exists())->toBeFalse();

    // POSITIVE CONTROL: the demo seeder, run explicitly, DOES produce them — so the emptiness above
    // is the production path's doing, not a broken seeder.
    govSeed();
    expect(AgentAction::query()->count())->toBeGreaterThanOrEqual(6)
        ->and(AiInteraction::query()->count())->toBeGreaterThan(0);
});

test('the governance rows are SPREAD across time, and the audit chain survives it', function () {
    $tenant = govSeed();

    /*
     * The spread is what makes a windowed metric meaningful — six rows in the same second would make
     * "last 7 days" and "last 30 days" identical. It is produced by travelling the clock around each
     * REAL call, so an action and its ledger rows move together.
     */
    $resolved = AgentAction::query()
        ->whereIn('status', [AgentAction::STATUS_EXECUTED, AgentAction::STATUS_REJECTED])
        ->get();

    $days = $resolved->map(fn (AgentAction $a): string => $a->created_at->toDateString())->unique();
    expect($days->count())->toBe(3, 'the three resolved actions should sit on three different days');
    expect($resolved->min('created_at')->lt(now()->subDays(7)))->toBeTrue('nothing lands outside the 7-day window');

    // The ledger moved WITH the action — a row back-dated on its own would be a different bug.
    foreach ($resolved as $action) {
        $ledger = AiInteraction::query()->where('output_ref', $action->id)->get();
        expect($ledger)->not->toBeEmpty();
        foreach ($ledger as $row) {
            expect($row->occurred_at->toDateString())->toBe($action->created_at->toDateString());
        }
    }

    // The fence refusal stays CURRENT, so AGENT.P5's 7-day counter has something in it.
    $fence = AgentAction::query()->where('status', AgentAction::STATUS_FENCE_REFUSED)->firstOrFail();
    expect($fence->fence_refused_at->gt(now()->subDays(7)))->toBeTrue();

    /*
     * AND THE POINT OF THE WHOLE TECHNIQUE: the hash-chained audit is untouched by it. AuditService
     * forces occurred_at strictly monotonic per tenant (prevTime + 1µs when the clock is not ahead),
     * so travelling the clock cannot reorder the chain against its verification order.
     */
    expect(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});
