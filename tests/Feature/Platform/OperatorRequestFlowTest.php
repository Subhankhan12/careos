<?php

use App\Services\OperatorGrantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Models\AuditEvent;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\OperatorGrant;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * OPMODE.G2 — THE REQUEST FLOW, AND ITS CORE PROPERTY: REQUESTING IS NOT GRANTING.
 *
 * This is the property that made BreakGlass the wrong model to extend — there,
 * `request()` sets `activated => true`, so asking IS receiving. Here:
 *
 *   configuration + full_support → PENDING, opening NOTHING until an owner decides (G3)
 *   read_only                    → self-granted, but ONLY non-PHI reads
 *
 * SETTLED PRODUCT DECISIONS pinned by these tests (D-162):
 *   - `configuration` is a WRITE tier and now REQUIRES owner approval (it used to be
 *     drawn as self-granted; the map flagged that as the design's weakest point).
 *   - `read_only` self-grants, and is non-PHI by construction.
 *
 * These tests ADD coverage. No existing behaviour test was modified except the one
 * flagged in OperatorGrantAccessTest for the configuration contract change.
 */

function orfTenant(string $slug = 'orf-alpha'): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active',
    ]);
}

function orfOperator(): User
{
    return User::factory()->create();                 // tenant_id null = platform operator
}

function orfCtx(): TenantContext
{
    return app(TenantContext::class);
}

function orfGrants(): OperatorGrantService
{
    return app(OperatorGrantService::class);
}

/** Every ability the tiers know about — used to prove a pending request opens NOTHING. */
function orfEveryAbility(): array
{
    return [
        'billing.view', 'reporting.view', 'audit.view',
        'admin.manage', 'ai.manage', 'comms.manage',
        'patient.view', 'document.view', 'encounter.manage',
        'dental.chart', 'lab.result', 'radiology.study',
    ];
}

// ── THE CORE PROPERTY — an approval tier grants NOTHING ──────────────────────────────

test('a full_support REQUEST is PENDING and opens NO tenant access at all', function () {
    $tenant = orfTenant();
    $operator = orfOperator();

    $grant = orfGrants()->request(
        $operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT,
        ['patients' => ['PT-4471', 'PT-4472', 'PT-4488']],
        'Investigating a booking data-sync fault — ticket #SYNC-4471.',
        15, 30,
    );

    // The row exists, and it is emphatically not a session: no clock has started.
    expect($grant->status)->toBe(OperatorGrant::STATUS_PENDING)
        ->and($grant->granted_at)->toBeNull()
        ->and($grant->expires_at)->toBeNull()
        ->and($grant->request_expires_at)->not->toBeNull()
        ->and($grant->requested_ttl_minutes)->toBe(15)
        ->and($grant->isActiveAt())->toBeFalse()
        ->and($grant->isAwaitingDecisionAt())->toBeTrue();

    // G1's invariant does the rest: inside the tenant, EVERY ability is denied —
    // including the very records the request names.
    orfCtx()->set($tenant);

    foreach (orfEveryAbility() as $ability) {
        expect(Gate::forUser($operator)->allows($ability, ['patient_id' => 'PT-4471']))->toBeFalse();
    }

    expect($operator->hasPermission('patient.view'))->toBeFalse();
});

test('a configuration REQUEST is PENDING too — the settled decision (D-162)', function () {
    $tenant = orfTenant();
    $operator = orfOperator();

    $grant = orfGrants()->request(
        $operator, $tenant, OperatorGrant::TIER_CONFIGURATION, [],
        'Config request — correcting a scheduling buffer the clinic reported.', 30, 30,
    );

    expect($grant->status)->toBe(OperatorGrant::STATUS_PENDING)
        ->and($grant->expires_at)->toBeNull()
        ->and($grant->requiresApproval())->toBeTrue();

    // A WRITE tier that opens nothing until an owner says so.
    orfCtx()->set($tenant);

    expect(Gate::forUser($operator)->allows('admin.manage'))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('ai.manage'))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('billing.view'))->toBeFalse();
});

// ── read_only self-grants, and ONLY non-PHI reads ───────────────────────────────────

test('a read_only REQUEST self-grants immediately but opens ONLY non-PHI reads', function () {
    $tenant = orfTenant();
    $operator = orfOperator();

    $grant = orfGrants()->request(
        $operator, $tenant, OperatorGrant::TIER_READ_ONLY, [],
        'Billing investigation — invoice discrepancy flagged 08 Jul.', 30, 30,
    );

    // Self-granted: the session clock started at once, no owner needed.
    expect($grant->status)->toBe(OperatorGrant::STATUS_ACTIVE)
        ->and($grant->requiresApproval())->toBeFalse()
        ->and($grant->granted_at)->not->toBeNull()
        ->and($grant->expires_at)->not->toBeNull()
        ->and($grant->isActiveAt())->toBeTrue();

    orfCtx()->set($tenant);

    // Exactly the non-PHI read floor …
    expect(Gate::forUser($operator)->allows('billing.view'))->toBeTrue()
        ->and(Gate::forUser($operator)->allows('reporting.view'))->toBeTrue()
        ->and(Gate::forUser($operator)->allows('audit.view'))->toBeTrue()
        // … and nothing else. No writes.
        ->and(Gate::forUser($operator)->allows('admin.manage'))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('billing.manage'))->toBeFalse()
        // … and NO patient data, which is what makes self-granting it defensible.
        ->and(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-1']))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('document.view', ['patient_id' => 'PT-1']))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('lab.result', ['patient_id' => 'PT-1']))->toBeFalse();
});

// ── NO self-approval path ───────────────────────────────────────────────────────────

test('NO SELF-APPROVAL: an operator cannot turn their own pending request active', function () {
    $tenant = orfTenant();
    $operator = orfOperator();

    $grant = orfGrants()->request(
        $operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT,
        ['patients' => ['PT-4471']], 'Need to confirm the mapping.', 15, 30,
    );

    // The only method that produces an ACTIVE approval-tier grant demands an approver who
    // belongs to the tenant and is not the operator — so the operator cannot use it on
    // themselves, nor can another platform operator stand in.
    expect(fn () => orfGrants()->issue(
        $operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT,
        ['patients' => ['PT-4471']], 'Need to confirm the mapping.', 15, $operator,
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => orfGrants()->issue(
            $operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT,
            ['patients' => ['PT-4471']], 'Need to confirm the mapping.', 15, orfOperator(),
        ))->toThrow(InvalidArgumentException::class);

    // The request is untouched by the attempts, and still opens nothing.
    expect($grant->refresh()->status)->toBe(OperatorGrant::STATUS_PENDING);

    orfCtx()->set($tenant);
    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeFalse();

    // FLAGGED CONTRACT CHANGE (OPMODE.G3, D-163): this originally asserted that NO
    // `approve` verb existed at all, which was true while nothing could decide a request.
    // G3 adds `approve()` — but it is OWNER-gated, so the property being pinned here is
    // unchanged and in fact stronger: the operator still cannot activate their own
    // request, now because the decision demands a target-tenant org_admin.
    expect(fn () => app(OperatorGrantService::class)->approve($grant, $operator))
        ->toThrow(InvalidArgumentException::class)
        ->and($grant->refresh()->status)->toBe(OperatorGrant::STATUS_PENDING);

    // The verbs that would let an operator self-serve still do not exist.
    $methods = get_class_methods(OperatorGrantService::class);
    foreach (['selfApprove', 'activate', 'grant'] as $forbidden) {
        expect(in_array($forbidden, $methods, true))->toBeFalse();
    }
});

// ── The REQUEST clock grants nothing ────────────────────────────────────────────────

test('an EXPIRED request can never be approved or activated — the request clock grants nothing', function () {
    $tenant = orfTenant();
    $operator = orfOperator();

    $grant = orfGrants()->request(
        $operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT,
        ['patients' => ['PT-4471']], 'Tracing a duplicated reminder.', 15, 30,
    );

    // Walk past the REQUEST clock (not the session clock — the session never started).
    Carbon::setTestNow($grant->request_expires_at->copy()->addSecond());

    expect($grant->isRequestExpiredAt())->toBeTrue()
        ->and($grant->isAwaitingDecisionAt())->toBeFalse()
        // The guard G3's approve() must call refuses it …
        ->and(fn () => orfGrants()->assertActivatable($grant))->toThrow(InvalidArgumentException::class);

    // … and it opens nothing, before or after the sweeper runs.
    orfCtx()->set($tenant);
    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeFalse();

    expect(orfGrants()->expireDueRequests())->toBe(1)
        ->and($grant->refresh()->status)->toBe(OperatorGrant::STATUS_EXPIRED)
        ->and($grant->granted_at)->toBeNull()
        ->and($grant->expires_at)->toBeNull()
        // Still unapprovable once swept — for the second reason as well as the first.
        ->and(fn () => orfGrants()->assertActivatable($grant))->toThrow(InvalidArgumentException::class);

    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeFalse();

    Carbon::setTestNow();
});

test('the sweeper leaves in-time requests and self-granted sessions alone', function () {
    $tenant = orfTenant();
    $operator = orfOperator();

    $pending = orfGrants()->request($operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT,
        ['patients' => ['PT-1']], 'Still within the window.', 15, 30);
    $selfGranted = orfGrants()->request(orfOperator(), $tenant, OperatorGrant::TIER_READ_ONLY, [],
        'Billing look.', 30, 30);

    expect(orfGrants()->expireDueRequests())->toBe(0)
        ->and($pending->refresh()->status)->toBe(OperatorGrant::STATUS_PENDING)
        ->and($selfGranted->refresh()->status)->toBe(OperatorGrant::STATUS_ACTIVE);
});

// ── Scope minimisation ──────────────────────────────────────────────────────────────

test('a full_support request must NAME its records — no wildcard, no empty scope', function () {
    $tenant = orfTenant();
    $operator = orfOperator();

    // No scope at all.
    expect(fn () => orfGrants()->request($operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT,
        [], 'r', 15, 30))->toThrow(InvalidArgumentException::class)
        // An empty patient list.
        ->and(fn () => orfGrants()->request($operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT,
            ['patients' => []], 'r', 15, 30))->toThrow(InvalidArgumentException::class);

    // Every wildcard spelling is refused — "all patient records" is an open product
    // decision, so until it is answered the only way in is to name the records.
    foreach (['*', 'all', 'ALL', 'any', '%'] as $wildcard) {
        expect(fn () => orfGrants()->request($operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT,
            ['patients' => [$wildcard]], 'r', 15, 30))->toThrow(InvalidArgumentException::class);
    }

    // A blank id is not a record either.
    expect(fn () => orfGrants()->request($operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT,
        ['patients' => ['  ']], 'r', 15, 30))->toThrow(InvalidArgumentException::class)
        ->and(OperatorGrant::query()->count())->toBe(0);

    // Named records are accepted, and recorded verbatim.
    $grant = orfGrants()->request($operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT,
        ['patients' => ['PT-4471', 'PT-4472', 'PT-4488']], 'Ticket #SYNC-4471.', 15, 30);

    expect($grant->scope['patients'])->toBe(['PT-4471', 'PT-4472', 'PT-4488']);
});

test('a request requires a justification, a session box and a request box, from an operator', function () {
    $tenant = orfTenant();
    $operator = orfOperator();
    $staff = User::factory()->forTenant($tenant)->create();

    expect(fn () => orfGrants()->request($operator, $tenant, OperatorGrant::TIER_READ_ONLY, [], '   ', 30, 30))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => orfGrants()->request($operator, $tenant, OperatorGrant::TIER_READ_ONLY, [], 'r', 0, 30))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => orfGrants()->request($operator, $tenant, OperatorGrant::TIER_READ_ONLY, [], 'r', 30, 0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => orfGrants()->request($operator, $tenant, 'god_mode', [], 'r', 30, 30))
        ->toThrow(InvalidArgumentException::class)
        // Tenant staff never request operator access — that is what RBAC is for.
        ->and(fn () => orfGrants()->request($staff, $tenant, OperatorGrant::TIER_READ_ONLY, [], 'r', 30, 30))
        ->toThrow(InvalidArgumentException::class)
        ->and(OperatorGrant::query()->count())->toBe(0);
});

// ── Audit ───────────────────────────────────────────────────────────────────────────

test('the request is audited as a REQUEST, not an access, in the target tenant ledger', function () {
    $tenant = orfTenant();
    $operator = orfOperator();

    orfGrants()->request($operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT,
        ['patients' => ['PT-4471']], 'Investigating a booking data-sync fault.', 15, 30);

    $row = app(TenantContext::class)->system(fn () => AuditEvent::query()
        ->where('tenant_id', $tenant->getKey())->orderBy('occurred_at')->first());

    expect($row->action)->toBe('operator.access_requested')
        ->and($row->actor_type)->toBe('operator')
        ->and($row->actor_id)->toBe((string) $operator->getKey())
        ->and($row->reason)->toBe('Investigating a booking data-sync fault.');

    // Decode, never match raw JSON text (D-156). The row must say plainly that this
    // granted nothing — an audit of a REQUEST, not of an access.
    $ctx = json_decode((string) $row->getRawOriginal('context'), true);
    expect($ctx['grants_access_now'])->toBeFalse()
        ->and($ctx['awaiting_owner_decision'])->toBeTrue()
        ->and($ctx['tier'])->toBe(OperatorGrant::TIER_FULL_SUPPORT)
        ->and($ctx['scope']['patients'])->toBe(['PT-4471'])
        ->and($ctx['expires_at'])->toBeNull()
        ->and($ctx['request_expires_at'])->not->toBeNull();

    expect(app(AuditService::class)->verifyChain($tenant->getKey())['ok'])->toBeTrue();
});

test('a self-granted read_only request audits as a self-grant that DID open access', function () {
    $tenant = orfTenant();
    $operator = orfOperator();

    orfGrants()->request($operator, $tenant, OperatorGrant::TIER_READ_ONLY, [],
        'Billing investigation.', 30, 30);

    $row = app(TenantContext::class)->system(fn () => AuditEvent::query()
        ->where('tenant_id', $tenant->getKey())->orderBy('occurred_at')->first());

    $ctx = json_decode((string) $row->getRawOriginal('context'), true);

    expect($row->action)->toBe('operator.self_granted')
        ->and($row->actor_type)->toBe('operator')
        ->and($ctx['grants_access_now'])->toBeTrue()
        ->and($ctx['awaiting_owner_decision'])->toBeFalse()
        ->and($ctx['expires_at'])->not->toBeNull();

    // The lapse of a request is auditable too, and says it granted nothing.
    $other = orfGrants()->request(orfOperator(), $tenant, OperatorGrant::TIER_FULL_SUPPORT,
        ['patients' => ['PT-9']], 'Nobody answered.', 15, 30);
    Carbon::setTestNow($other->request_expires_at->copy()->addSecond());
    orfGrants()->expireDueRequests();
    Carbon::setTestNow();

    $lapse = app(TenantContext::class)->system(fn () => AuditEvent::query()
        ->where('tenant_id', $tenant->getKey())->where('action', 'operator.request_expired')->first());

    expect($lapse)->not->toBeNull()
        ->and(json_decode((string) $lapse->getRawOriginal('context'), true)['granted_access'])->toBeFalse()
        ->and(app(AuditService::class)->verifyChain($tenant->getKey())['ok'])->toBeTrue();
});

// ── The session clock can never be re-pointed ───────────────────────────────────────

test('the session clock is set ONCE — a pending grant can start one, an active one cannot restart it', function () {
    $tenant = orfTenant();
    $operator = orfOperator();

    // A pending request has no clock; G3 will start it. That transition is permitted…
    $pending = orfGrants()->request($operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT,
        ['patients' => ['PT-1']], 'r', 15, 30);

    $pending->forceFill([
        'status' => OperatorGrant::STATUS_ACTIVE,
        'granted_at' => Carbon::now(),
        'expires_at' => Carbon::now()->addMinutes(15),
    ])->save();

    expect($pending->refresh()->isActiveAt())->toBeTrue();

    // …but only once. An existing session can never be silently re-clocked.
    $pending->expires_at = Carbon::now()->addDay();
    expect(fn () => $pending->save())->toThrow(RuntimeException::class);

    // And the request facts stay immutable throughout.
    $fresh = OperatorGrant::query()->findOrFail($pending->getKey());
    $fresh->request_expires_at = Carbon::now()->addDay();
    expect(fn () => $fresh->save())->toThrow(RuntimeException::class);
});

// ── The agent has no request path ───────────────────────────────────────────────────

test('THE AGENT EXCLUSION still holds: no agent path can request operator access', function () {
    // An agent actor is never a super-admin, so it can never satisfy request()'s first
    // guard; and no AiCore/AiTool code references the request flow at all.
    $scan = function (string $root): array {
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match('/OperatorGrant|operator_access_grants|->request\(/', (string) file_get_contents($file->getPathname()))) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    };

    expect(is_dir(base_path('Modules/AiCore')))->toBeTrue()
        ->and(is_dir(base_path('app/AiCore')))->toBeTrue();

    foreach ([...$scan(base_path('Modules/AiCore')), ...$scan(base_path('app/AiCore'))] as $file) {
        expect(str_contains((string) file_get_contents($file), 'OperatorGrant'))->toBeFalse();
    }

    // Nothing operator-related is scheduled — the request sweeper is not wired to cron
    // in this gate, so no unattended path touches a grant.
    expect(str_contains((string) file_get_contents(base_path('routes/console.php')), 'operator'))->toBeFalse();
});
