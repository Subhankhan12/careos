<?php

use App\Services\OperatorGrantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Audit\Models\AuditEvent;
use Modules\Audit\Services\AuditService;
use Modules\Comms\Models\NotificationDelivery;
use Modules\Comms\Services\NotificationPreferenceService;
use Modules\Comms\Services\NotificationService;
use Modules\Platform\Models\OperatorGrant;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * OPMODE.G3 — THE OWNER IS THE GATE.
 *
 * A pending configuration/full_support request from G2 opens nothing. Only a tenant
 * OWNER — the holders of `org_admin` in THAT tenant (SETTLED PRODUCT DECISION, D-163:
 * no new role was invented) — can decide it, and approval is the ONLY path that turns a
 * pending approval-tier grant into a live session.
 *
 * An owner may hand back LESS than was asked (downgrade) but never more. Because G1/G2
 * make a grant's facts permanently immutable, a downgrade does NOT mutate the request:
 * the request is closed and a NEW narrower grant is created pointing back at it, so both
 * "what was asked" and "what was granted" survive — the ARDETAIL.P6 recipe.
 *
 * These tests ADD coverage; no existing behaviour test was modified.
 */

function oodTenant(string $slug = 'ood-alpha'): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active',
    ]);
}

function oodOperator(): User
{
    return User::factory()->create();                  // tenant_id null = platform operator
}

function oodCtx(): TenantContext
{
    return app(TenantContext::class);
}

function oodGrants(): OperatorGrantService
{
    return app(OperatorGrantService::class);
}

/** A tenant user holding the tenant's `org_admin` role — an OWNER. */
function oodOwner(Tenant $tenant): User
{
    $user = User::factory()->forTenant($tenant)->create();

    app(TenantContext::class)->system(function () use ($tenant, $user): void {
        $role = Role::query()->where('tenant_id', $tenant->getKey())
            ->where('key', OperatorGrant::OWNER_ROLE_KEY)->firstOrFail();

        RoleAssignment::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'role_id' => $role->getKey(),
        ]);
    });

    return $user;
}

/** A pending full_support request over the three named records. */
function oodPendingRequest(User $operator, Tenant $tenant, array $ids = ['PT-4471', 'PT-4472', 'PT-4488']): OperatorGrant
{
    return oodGrants()->request(
        $operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT, ['patients' => $ids],
        'Investigating a booking data-sync fault — ticket #SYNC-4471.', 15, 30,
    );
}

// ── APPROVE activates — and it is the only thing that does ───────────────────────────

test('an OWNER approval activates the pending grant, which then permits exactly its tier + scope', function () {
    $tenant = oodTenant();
    $operator = oodOperator();
    $owner = oodOwner($tenant);
    $grant = oodPendingRequest($operator, $tenant);

    // Before the decision: nothing.
    oodCtx()->set($tenant);
    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeFalse();
    oodCtx()->forget();

    $approved = oodGrants()->approve($grant, $owner, note: 'Reason and records check out.');

    expect($approved->status)->toBe(OperatorGrant::STATUS_ACTIVE)
        ->and($approved->granted_at)->not->toBeNull()          // the session clock started NOW
        ->and($approved->expires_at)->not->toBeNull()
        ->and($approved->decided_by)->toBe($owner->getKey())
        ->and($approved->decided_at)->not->toBeNull()
        ->and($approved->isActiveAt())->toBeTrue();

    // And now G1 enforces exactly the approved tier + scope, and nothing further.
    oodCtx()->set($tenant);

    foreach (['PT-4471', 'PT-4472', 'PT-4488'] as $id) {
        expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => $id]))->toBeTrue();
    }

    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-9999']))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('patient.edit', ['patient_id' => 'PT-4471']))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('billing.manage'))->toBeFalse();
});

test('APPROVAL IS THE ONLY pending -> active path (structural)', function () {
    // Only two files in the whole codebase can even NAME an operator grant's active
    // status: the access service (which READS it to find a live grant) and the grant
    // service (which writes it in exactly three places — the self-granted read_only
    // request, issue(), and approve()). No controller, job, command, agent or model
    // callback can activate a grant. `STATUS_ACTIVE` alone is far too common a constant
    // name across the codebase to assert on, so this pins the QUALIFIED reference.
    $roots = [base_path('Modules'), base_path('app')];
    $writers = [];

    foreach ($roots as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $body = (string) file_get_contents($file->getPathname());
            if (str_contains($body, 'OperatorGrant::STATUS_ACTIVE')) {
                $writers[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            }
        }
    }

    sort($writers);

    expect($writers)->toBe([
        'Modules/Platform/src/Services/OperatorAccessService.php', // reads it to find a live grant
        'app/Services/OperatorGrantService.php',                // request(read_only) / issue() / approve()
    ]);

    // And within the grant service there are EXACTLY FOUR writes of the active status,
    // all of them accounted for: request() self-granting read_only (a ternary, never an
    // approval tier), approve() activating in place, approve() creating the downgraded
    // grant, and issue() (the post-decision primitive that demands an approver).
    $service = (string) file_get_contents(base_path('app/Services/OperatorGrantService.php'));
    expect(substr_count($service, 'OperatorGrant::STATUS_ACTIVE'))->toBe(4);

    // Nothing scheduled and no agent path can decide.
    expect(str_contains((string) file_get_contents(base_path('routes/console.php')), 'operator'))->toBeFalse();
});

// ── ONLY the target tenant's org_admin may decide ───────────────────────────────────

test('ONLY the target tenant org_admin can decide — operator, another tenant admin, and plain staff refused', function () {
    $tenant = oodTenant();
    $other = oodTenant('ood-beta');
    $operator = oodOperator();
    $grant = oodPendingRequest($operator, $tenant);

    $foreignOwner = oodOwner($other);                       // org_admin, wrong tenant
    $plainStaff = User::factory()->forTenant($tenant)->create();   // right tenant, no role
    $secondOperator = oodOperator();                        // another platform operator

    // THE OPERATOR CANNOT APPROVE THEIR OWN REQUEST (the G2 no-self-approval rule).
    foreach ([$operator, $secondOperator, $foreignOwner, $plainStaff] as $notAnOwner) {
        expect(fn () => oodGrants()->approve($grant, $notAnOwner))->toThrow(InvalidArgumentException::class);
        expect(fn () => oodGrants()->decline($grant, $notAnOwner))->toThrow(InvalidArgumentException::class);
    }

    // The request is untouched by every attempt, and still opens nothing.
    expect($grant->refresh()->status)->toBe(OperatorGrant::STATUS_PENDING)
        ->and($grant->granted_at)->toBeNull()
        ->and($grant->decided_by)->toBeNull();

    oodCtx()->set($tenant);
    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeFalse();
    oodCtx()->forget();

    // isOwnerOf() is the gate, and it agrees.
    expect(oodGrants()->isOwnerOf($operator, $tenant->getKey()))->toBeFalse()
        ->and(oodGrants()->isOwnerOf($foreignOwner, $tenant->getKey()))->toBeFalse()
        ->and(oodGrants()->isOwnerOf($plainStaff, $tenant->getKey()))->toBeFalse()
        ->and(oodGrants()->isOwnerOf(oodOwner($tenant), $tenant->getKey()))->toBeTrue();
});

// ── DOWNGRADE grants LESS, never more ───────────────────────────────────────────────

test('a DOWNGRADE grants less — the request is superseded, not mutated', function () {
    $tenant = oodTenant();
    $operator = oodOperator();
    $owner = oodOwner($tenant);
    $requested = oodPendingRequest($operator, $tenant);

    $granted = oodGrants()->approve(
        $requested, $owner, OperatorGrant::TIER_READ_ONLY, [],
        'The send log shows the trigger without opening anyone\'s chart.',
    );

    // BOTH facts survive: what was asked, and what was granted.
    expect($requested->refresh()->status)->toBe(OperatorGrant::STATUS_DECLINED)
        ->and($requested->tier)->toBe(OperatorGrant::TIER_FULL_SUPPORT)   // request unchanged
        ->and($requested->granted_at)->toBeNull()
        ->and($granted->getKey())->not->toBe($requested->getKey())
        ->and($granted->status)->toBe(OperatorGrant::STATUS_ACTIVE)
        ->and($granted->tier)->toBe(OperatorGrant::TIER_READ_ONLY)
        ->and($granted->supersedes_id)->toBe($requested->getKey())
        ->and($granted->supersedes->getKey())->toBe($requested->getKey());

    // The downgraded session reaches only the lower tier — patient records stay closed.
    oodCtx()->set($tenant);
    expect(Gate::forUser($operator)->allows('billing.view'))->toBeTrue()
        ->and(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('admin.manage'))->toBeFalse();
});

test('a downgrade may narrow the SCOPE as well — to a subset, never beyond it', function () {
    $tenant = oodTenant();
    $operator = oodOperator();
    $owner = oodOwner($tenant);
    $requested = oodPendingRequest($operator, $tenant);      // asked for 3 records

    $granted = oodGrants()->approve($requested, $owner, OperatorGrant::TIER_FULL_SUPPORT,
        ['patients' => ['PT-4471']], 'One record is enough for this.');

    expect($granted->scope['patients'])->toBe(['PT-4471']);

    oodCtx()->set($tenant);
    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeTrue()
        // The other two were requested but NOT granted.
        ->and(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4472']))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4488']))->toBeFalse();
});

test('a WIDENING "downgrade" is refused — tier cannot rise and scope cannot reach further', function () {
    $tenant = oodTenant();
    $operator = oodOperator();
    $owner = oodOwner($tenant);

    // Asked for configuration only.
    $configRequest = oodGrants()->request($operator, $tenant, OperatorGrant::TIER_CONFIGURATION, [],
        'Correcting a scheduling buffer.', 30, 30);

    // The owner cannot promote it to patient-record access.
    expect(fn () => oodGrants()->approve($configRequest, $owner, OperatorGrant::TIER_FULL_SUPPORT,
        ['patients' => ['PT-1']]))->toThrow(InvalidArgumentException::class);

    // Nor invent a record kind the request never mentioned.
    expect(fn () => oodGrants()->approve($configRequest, $owner, OperatorGrant::TIER_CONFIGURATION,
        ['patients' => ['PT-1']]))->toThrow(InvalidArgumentException::class);

    // And on a full_support request, a record outside the asked-for set is refused.
    $full = oodPendingRequest(oodOperator(), $tenant, ['PT-4471']);
    expect(fn () => oodGrants()->approve($full, $owner, OperatorGrant::TIER_FULL_SUPPORT,
        ['patients' => ['PT-4471', 'PT-9999']]))->toThrow(InvalidArgumentException::class);

    // Every refusal left the requests pending and opened nothing.
    expect($configRequest->refresh()->status)->toBe(OperatorGrant::STATUS_PENDING)
        ->and($full->refresh()->status)->toBe(OperatorGrant::STATUS_PENDING);

    oodCtx()->set($tenant);
    expect(Gate::forUser($operator)->allows('admin.manage'))->toBeFalse();
});

// ── DECLINE activates nothing ───────────────────────────────────────────────────────

test('a DECLINE activates nothing, and the request can never be decided again', function () {
    $tenant = oodTenant();
    $operator = oodOperator();
    $owner = oodOwner($tenant);
    $grant = oodPendingRequest($operator, $tenant);

    $declined = oodGrants()->decline($grant, $owner, 'We would rather platform not read patient records.');

    expect($declined->status)->toBe(OperatorGrant::STATUS_DECLINED)
        ->and($declined->granted_at)->toBeNull()
        ->and($declined->expires_at)->toBeNull()
        ->and($declined->decided_by)->toBe($owner->getKey())
        ->and($declined->isActiveAt())->toBeFalse();

    oodCtx()->set($tenant);
    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeFalse();
    oodCtx()->forget();

    // Terminal: no second bite, in either direction.
    expect(fn () => oodGrants()->approve($declined, $owner))->toThrow(InvalidArgumentException::class)
        ->and(fn () => oodGrants()->decline($declined, $owner))->toThrow(InvalidArgumentException::class);
});

test('an already-APPROVED request cannot be re-decided', function () {
    $tenant = oodTenant();
    $operator = oodOperator();
    $owner = oodOwner($tenant);
    $grant = oodPendingRequest($operator, $tenant);

    oodGrants()->approve($grant, $owner);

    expect(fn () => oodGrants()->approve($grant->refresh(), $owner))->toThrow(InvalidArgumentException::class)
        ->and(fn () => oodGrants()->decline($grant->refresh(), $owner))->toThrow(InvalidArgumentException::class)
        // Re-approving must not have re-clocked the live session either.
        ->and($grant->refresh()->status)->toBe(OperatorGrant::STATUS_ACTIVE);
});

test('an EXPIRED request cannot be approved by an owner (the G2 guard holds at the decision)', function () {
    $tenant = oodTenant();
    $operator = oodOperator();
    $owner = oodOwner($tenant);
    $grant = oodPendingRequest($operator, $tenant);

    Carbon::setTestNow($grant->request_expires_at->copy()->addSecond());

    expect(fn () => oodGrants()->approve($grant, $owner))->toThrow(InvalidArgumentException::class)
        ->and(fn () => oodGrants()->decline($grant, $owner))->toThrow(InvalidArgumentException::class)
        ->and($grant->refresh()->granted_at)->toBeNull();

    oodCtx()->set($tenant);
    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeFalse();

    Carbon::setTestNow();
});

// ── The owner is notified ───────────────────────────────────────────────────────────

test('the target tenant owners are NOTIFIED of a request, with operator, tier, scope and expiry', function () {
    $tenant = oodTenant();
    $operator = oodOperator();
    $ownerA = oodOwner($tenant);
    $ownerB = oodOwner($tenant);
    oodOwner(oodTenant('ood-beta'));                    // another tenant's owner: never notified

    oodPendingRequest($operator, $tenant);

    $deliveries = app(TenantContext::class)->system(fn () => NotificationDelivery::query()->get());

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('recipient_id')->map(fn ($id) => (string) $id)->sort()->values()->all())
        ->toBe(collect([$ownerA->getKey(), $ownerB->getKey()])->map(fn ($id) => (string) $id)->sort()->values()->all());

    $body = (string) $deliveries->first()->rendered_body;

    expect($body)->toContain(OperatorGrant::TIER_FULL_SUPPORT)
        ->and($body)->toContain('PT-4471')
        ->and($body)->toContain('booking data-sync fault')
        ->and($body)->toContain('Nothing is accessible to them unless you approve');
});

test('a self-granted read_only request notifies nobody — there is nothing to decide', function () {
    $tenant = oodTenant();
    oodOwner($tenant);

    oodGrants()->request(oodOperator(), $tenant, OperatorGrant::TIER_READ_ONLY, [],
        'Billing investigation.', 30, 30);

    expect(app(TenantContext::class)->system(fn () => NotificationDelivery::query()->count()))->toBe(0);
});

test('the owner notification can never be switched off, and a tenant with no owner fails closed', function () {
    $tenant = oodTenant('ood-ownerless');
    $operator = oodOperator();

    // Not a MANAGEABLE preference, so no admin screen can disable a governance request —
    // and with no override row it defaults ON.
    expect(in_array('operator.access_requested', NotificationPreferenceService::MANAGEABLE, true))->toBeFalse()
        ->and(NotificationService::BUILT_IN)->toHaveKey('operator.access_requested');

    oodCtx()->set($tenant);
    expect(app(NotificationPreferenceService::class)->emailEnabled('operator.access_requested'))->toBeTrue();
    oodCtx()->forget();

    // No owner to ask: the request still grants nothing and says so in the ledger.
    $grant = oodPendingRequest($operator, $tenant);

    expect($grant->status)->toBe(OperatorGrant::STATUS_PENDING);

    $rows = app(TenantContext::class)->system(fn () => AuditEvent::query()
        ->where('tenant_id', $tenant->getKey())->pluck('action')->all());

    expect($rows)->toContain('operator.owner_unreachable');

    oodCtx()->set($tenant);
    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeFalse();
});

// ── Two-sided audit ─────────────────────────────────────────────────────────────────

test('the decision is audited with the OWNER as actor, in the tenant ledger, chain intact', function () {
    $tenant = oodTenant();
    $operator = oodOperator();
    $owner = oodOwner($tenant);

    $approved = oodGrants()->approve(oodPendingRequest($operator, $tenant), $owner, note: 'Checks out.');
    $downgraded = oodGrants()->approve(oodPendingRequest($operator, $tenant), $owner,
        OperatorGrant::TIER_READ_ONLY, [], 'Read-only is enough here.');
    oodGrants()->decline(oodPendingRequest($operator, $tenant), $owner, 'Not for this one.');

    $rows = app(TenantContext::class)->system(fn () => AuditEvent::query()
        ->where('tenant_id', $tenant->getKey())
        ->whereIn('action', ['operator.request_approved', 'operator.request_downgraded', 'operator.request_declined'])
        ->orderBy('occurred_at')->get());

    expect($rows->pluck('action')->all())
        ->toBe(['operator.request_approved', 'operator.request_downgraded', 'operator.request_declined'])
        // THE TWO-SIDED PART: the actor is the clinic's own admin, not the operator.
        ->and($rows->pluck('actor_type')->unique()->all())->toBe(['user'])
        ->and($rows->pluck('actor_id')->unique()->all())->toBe([(string) $owner->getKey()]);

    // Decode, never match raw JSON text (D-156).
    $down = json_decode((string) $rows[1]->getRawOriginal('context'), true);
    expect($down['downgraded'])->toBeTrue()
        ->and($down['requested_tier'])->toBe(OperatorGrant::TIER_FULL_SUPPORT)
        ->and($down['tier'])->toBe(OperatorGrant::TIER_READ_ONLY)
        ->and($down['supersedes'])->toBe($downgraded->supersedes_id)
        ->and($down['operator_id'])->toBe($operator->getKey());

    $declined = json_decode((string) $rows[2]->getRawOriginal('context'), true);
    expect($declined['granted_access'])->toBeFalse()
        ->and($rows[2]->reason)->toBe('Not for this one.')
        ->and($approved->status)->toBe(OperatorGrant::STATUS_ACTIVE);

    expect(app(AuditService::class)->verifyChain($tenant->getKey())['ok'])->toBeTrue();
});
