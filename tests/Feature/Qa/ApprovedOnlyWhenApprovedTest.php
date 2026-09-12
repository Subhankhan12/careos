<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AiCore\Contracts\AiTool;
use Modules\AiCore\Exceptions\FenceRefusalException;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\AiInteraction;
use Modules\AiCore\Services\ApprovalQueue;
use Modules\AiCore\Services\ToolDefinition;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
| QA-FIX.12e — `P10-H1`: the AI ledger says `approved` only when the action was approved INTO EFFECT.
|
| `ApprovalQueue::approve()` recorded the approval BEFORE calling the tool, and nothing compensated on
| failure — so every failed execution left a permanent `approved` row for an action that stayed
| `pending`. Phase 10 drove it three ways and found FOUR rows for one clinical action, of which two
| approvals never happened; the queue's own tiles moved with them (APPROVED · 30D 50% → 60% → 57%) and
| the governance table read approved 9 · executed 4 — a five-row gap no screen explained.
|
| IT WAS ALREADY INCONSISTENT INSIDE THE METHOD, WHICH IS WHAT MAKES IT A DEFECT RATHER THAN A DESIGN:
| the `approved` EVENT fires only on success, and `agent_actions.approved_at` is only stamped on success.
| The ledger was the one voice saying otherwise. And this method's own re-authorisation gate — which sits
| ABOVE the recorder — already leaves nothing at all when it refuses. A failed execution now matches it.
|
| THE LEDGER IS APPEND-ONLY AND HISTORICAL ROWS ARE NOT REWRITTEN (D-193/D-197). Rows written before this
| fix stand; what changes is that no new false one is created.
*/

function aoTenant(string $slug): Tenant
{
    $t = Tenant::query()->create(['name' => 'AO '.$slug, 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($t);

    return $t;
}

function aoUser(Tenant $tenant, string $roleKey = 'org_admin'): User
{
    $u = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $r = Role::query()->where('key', $roleKey)->first();
    if ($r !== null) {
        RoleAssignment::query()->firstOrCreate(['user_id' => $u->id, 'role_id' => $r->id]);
    }

    return $u;
}

/**
 * A REAL registered tool whose execute() either returns or throws.
 *
 * Deliberately a genuine `AiTool` put through `ToolRegistry::register()` and driven through the real
 * `propose()` → `approve()` path, rather than a mocked queue: the defect is the ORDER of two writes
 * inside `approve()`, and a double that skipped that method would prove nothing about it.
 */
function aoTool(string $key, ?Throwable $throw): AiTool
{
    return new class($key, $throw) implements AiTool
    {
        public function __construct(private string $key, private ?Throwable $throw) {}

        public function definition(): ToolDefinition
        {
            return new ToolDefinition(
                key: $this->key,
                name: 'QA12e probe',
                category: ToolDefinition::CATEGORY_OPERATIONAL,
                permission: 'ai.manage',
                schema: ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]],
                reversible: true,
            );
        }

        public function preview(array $input): array
        {
            return ['preview' => $input['message'] ?? ''];
        }

        public function execute(array $input, ?User $actor = null): array
        {
            if ($this->throw !== null) {
                throw $this->throw;
            }

            return ['echo' => $input['message'] ?? ''];
        }
    };
}

/**
 * A pending action whose tool will behave as asked on approve.
 *
 * @return array{0: AgentAction, 1: ApprovalQueue}
 */
function aoPendingActionWithTool(Tenant $tenant, User $reviewer, ?Throwable $throw, string $key = 'ao.probe'): array
{
    app(TenantContext::class)->set($tenant);
    app(ToolRegistry::class)->register(aoTool($key, $throw));

    $queue = app(ApprovalQueue::class);

    $action = $queue->propose(
        $key,
        ['message' => 'probe'],
        $reviewer,
        // The FEATURE resolves the prompt; the seeded `demo.echo` prompt is used so this fixture
        // introduces no prompt of its own.
        'demo.echo',
        'qa12e-agent',
        'QA-FIX.12e ledger-ordering probe',
        'approve',
    );

    return [$action, $queue];
}

/** Ledger rows for one action, grouped by outcome — `output_ref` is what the real paths stamp. */
function aoLedger(AgentAction $action): array
{
    return AiInteraction::query()
        ->where('output_ref', $action->id)
        ->get()
        ->groupBy('outcome')
        ->map(fn ($rows): int => $rows->count())
        ->all();
}

/* ------------------------------------------------------------------ *
 | The ordering itself, pinned structurally.                           |
 * ------------------------------------------------------------------ */

it('P10-H1: the approved row is recorded AFTER execute(), not before it', function () {
    $src = (string) file_get_contents(base_path('Modules/AiCore/src/Services/ApprovalQueue.php'));

    // Comment-stripped, so an explanatory comment quoting the old order cannot satisfy this.
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                $out .= "\n";

                continue;
            }
            $out .= $t[1];

            continue;
        }
        $out .= $t;
    }

    $approveBody = substr($out, (int) strpos($out, 'public function approve('));
    $approveBody = substr($approveBody, 0, (int) strpos($approveBody, 'public function reject('));

    $execAt = strpos($approveBody, '$tool->execute(');
    $approvedAt = strpos($approveBody, "'approved',");
    $executedAt = strpos($approveBody, "'executed',");

    expect($execAt)->not->toBeFalse()
        ->and($approvedAt)->not->toBeFalse()
        ->and($executedAt)->not->toBeFalse();

    /*
     * THE WHOLE DEFECT IN ONE COMPARISON. Mutation-checked: moving the `approved` record back above the
     * try reddens this and the two behavioural tests below.
     */
    expect($approvedAt)->toBeGreaterThan($execAt)
        ->and($executedAt)->toBeGreaterThan($approvedAt);
});

/* ------------------------------------------------------------------ *
 | Behaviour: a failed execution leaves no approval behind.            |
 * ------------------------------------------------------------------ */

it('P10-H1: a tool that THROWS leaves no approved row, and the action stays pending', function () {
    $tenant = aoTenant('ao-throw');
    $reviewer = aoUser($tenant);

    [$action, $queue] = aoPendingActionWithTool($tenant, $reviewer, throw: new RuntimeException('booking conflict'));

    /*
     * PHASE 10's FIRST FAILURE MODE, reproduced: the booking conflict of `P10-C2`. The action stayed
     * `pending` then and stays `pending` now — what changed is the residue.
     */
    expect(fn () => $queue->approve($action, $reviewer))->toThrow(RuntimeException::class);

    $fresh = $action->fresh();

    expect($fresh->status)->toBe(AgentAction::STATUS_PENDING)
        ->and($fresh->approved_at)->toBeNull()
        ->and($fresh->executed_at)->toBeNull();

    // BEFORE THE FIX this was `['approved' => 1]` — a permanent approval of something never approved.
    expect(aoLedger($fresh))->toBe([]);
});

it('P10-H1: three failed approves leave THREE times nothing, not three approvals', function () {
    $tenant = aoTenant('ao-thrice');
    $reviewer = aoUser($tenant);

    [$action, $queue] = aoPendingActionWithTool($tenant, $reviewer, throw: new RuntimeException('still conflicting'));

    /*
     * THE FINDING'S OWN SHAPE. It drove three failures and counted FOUR rows for one action, of which
     * two approvals never happened. A single-attempt test would not have caught the accumulation, which
     * is what made the queue's percentages drift.
     */
    foreach (range(1, 3) as $attempt) {
        try {
            $queue->approve($action->fresh(), $reviewer);
        } catch (RuntimeException) {
            // expected
        }
    }

    expect(aoLedger($action->fresh()))->toBe([])
        ->and($action->fresh()->status)->toBe(AgentAction::STATUS_PENDING);
});

it('P10-H1: a FENCE refusal records its own terminal row and no approval', function () {
    $tenant = aoTenant('ao-fence');
    $reviewer = aoUser($tenant);

    [$action, $queue] = aoPendingActionWithTool(
        $tenant,
        $reviewer,
        throw: new FenceRefusalException('This draft handed off to a human; there is nothing to send.')
    );

    expect(fn () => $queue->approve($action, $reviewer))->toThrow(FenceRefusalException::class);

    $fresh = $action->fresh();
    $ledger = aoLedger($fresh);

    /*
     * The fence keeps its terminal record — that is not what `P10-H1` objected to. What it objected to
     * is the phrase "and even then the stale approved row stands". It no longer does.
     */
    expect($fresh->status)->toBe(AgentAction::STATUS_FENCE_REFUSED)
        ->and($ledger['fence_refused'] ?? 0)->toBe(1)
        ->and($ledger['approved'] ?? 0)->toBe(0)
        ->and($ledger['executed'] ?? 0)->toBe(0);
});

/* ------------------------------------------------------------------ *
 | The positive control — a real approval still records the pair.      |
 * ------------------------------------------------------------------ */

it('POSITIVE CONTROL: a SUCCESSFUL approve still records approved AND executed, in that order', function () {
    $tenant = aoTenant('ao-ok');
    $reviewer = aoUser($tenant);

    [$action, $queue] = aoPendingActionWithTool($tenant, $reviewer, throw: null);

    /*
     * WITHOUT THIS, THE FIX COULD BE "NEVER RECORD AN APPROVAL" AND THE SUITE WOULD STILL PASS (D-174).
     * A real approval must still produce the pair — and in the order a reader expects.
     */
    $result = $queue->approve($action, $reviewer);

    expect($result->status)->toBe(AgentAction::STATUS_EXECUTED)
        ->and($result->approved_at)->not->toBeNull()
        ->and($result->executed_at)->not->toBeNull();

    $ledger = aoLedger($result);
    expect($ledger['approved'] ?? 0)->toBe(1)
        ->and($ledger['executed'] ?? 0)->toBe(1);

    $rows = AiInteraction::query()->where('output_ref', $result->id)->orderBy('id')->pluck('outcome')->all();
    expect($rows)->toBe(['approved', 'executed']);
});

it('the ledger now AGREES with the action status, which is the property the finding measured', function () {
    $tenant = aoTenant('ao-agree');
    $reviewer = aoUser($tenant);

    // One that fails, one that succeeds — the governance table's two columns, side by side.
    [$failing, $queue] = aoPendingActionWithTool($tenant, $reviewer, throw: new RuntimeException('nope'), key: 'ao.fails');
    try {
        $queue->approve($failing, $reviewer);
    } catch (RuntimeException) {
    }

    [$ok] = aoPendingActionWithTool($tenant, $reviewer, throw: null, key: 'ao.works');
    $queue->approve($ok, $reviewer);

    /*
     * `P10-H1`'s headline number was "approved 9 · executed 4 — a five-row gap that no screen explains".
     * With the recorder after execution the two counts can only differ by an action that executed
     * without approval, which no path produces. Asserted as an EQUALITY rather than as two counts, so a
     * future change that reintroduces the gap fails here.
     */
    $approved = AiInteraction::query()->where('outcome', 'approved')->count();
    $executed = AiInteraction::query()->where('outcome', 'executed')->count();

    expect($approved)->toBe(1)
        ->and($executed)->toBe(1)
        ->and($approved)->toBe($executed);
});
