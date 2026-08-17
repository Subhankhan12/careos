<?php

use App\Services\OperatorGrantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Modules\Audit\Models\AuditEvent;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\OperatorGrant;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\OperatorAccessService;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * OPMODE.G1 — THE FAIL-CLOSED ACCESS INVARIANT.
 *
 * A platform operator (super-admin, tenant_id null) may reach a tenant's data ONLY
 * through an OperatorGrant for that tenant that is ACTIVE, UNEXPIRED, IN-TIER and
 * IN-SCOPE. These tests are the threat-model proofs from
 * docs/features/OPERATOR-MODE-MAP.md §4.4 — T1, T2, T3, T4, T5, T6, T9 — plus the
 * REGRESSION GUARD that the old blanket bypass cannot come back.
 *
 * These tests ADD coverage. One pre-existing assertion in RbacTest documented the OLD
 * blanket-bypass behaviour at PLATFORM level and still passes unchanged (it sets no
 * tenant context); it is extended there rather than modified.
 */

function opgTenant(string $slug = 'opg-alpha'): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active',
    ]);
}

function opgOperator(): User
{
    return User::factory()->create();            // tenant_id null = platform operator
}

function opgCtx(): TenantContext
{
    return app(TenantContext::class);
}

function opgGrants(): OperatorGrantService
{
    return app(OperatorGrantService::class);
}

/** Issue an active full_support grant over $patientIds, approved by a tenant user. */
function opgFullGrant(User $operator, Tenant $tenant, array $patientIds, int $ttl = 15): OperatorGrant
{
    $owner = User::factory()->forTenant($tenant)->create();

    return opgGrants()->issue(
        $operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT,
        ['patients' => $patientIds], 'Investigating a booking data-sync fault.', $ttl, $owner,
    );
}

// ── THE REGRESSION GUARD — the blanket bypass cannot return ──────────────────────────

test('REGRESSION GUARD: a super-admin with NO grant is DENIED tenant data (T1)', function () {
    $tenant = opgTenant();
    $operator = opgOperator();
    opgCtx()->set($tenant);                      // inside a tenant — the dangerous position

    // Every one of these returned TRUE before OPMODE.G1, unconditionally.
    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-1']))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('patient.edit'))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('billing.view'))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('admin.manage'))->toBeFalse()
        // The second bypass point: hasPermission() reaches PermissionService directly.
        ->and($operator->hasPermission('patient.view'))->toBeFalse()
        ->and($operator->hasPermission('anything.at.all'))->toBeFalse();
});

test('REGRESSION GUARD: the bypass is context-sensitive, not removed — platform level still works', function () {
    $operator = opgOperator();
    opgTenant();

    // No tenant context = platform level: the console, the tenant list, cron, system
    // jobs. Unchanged, and no tenant row is reachable here anyway (TenantScope throws).
    expect(opgCtx()->has())->toBeFalse()
        ->and(Gate::forUser($operator)->allows('billing.view'))->toBeTrue()
        ->and($operator->hasPermission('anything.at.all'))->toBeTrue();

    // Step into a tenant and the same operator loses everything without a grant.
    opgCtx()->set(Tenant::query()->firstOrFail());
    expect(Gate::forUser($operator)->allows('billing.view'))->toBeFalse();
});

test('a super-admin request CLEARS any inherited tenant context (never inherits one)', function () {
    $tenant = opgTenant();
    $operator = User::factory()->twoFactorEnabled()->create();   // 2FA is mandatory

    // Simulate a context left over from an earlier request in the same container — the
    // exact situation that made the Horizon guard fail before the middleware was made
    // to clear it. It must not decide a super-admin's abilities.
    opgCtx()->set($tenant);

    Route::middleware(['web', 'auth'])->get('/_opg_probe', function () {
        return response()->json(['tenant_id' => app(TenantContext::class)->id()]);
    });

    $this->actingAs($operator)->getJson('/_opg_probe')
        ->assertOk()
        ->assertExactJson(['tenant_id' => null]);
});

// ── An active grant permits EXACTLY its tier and scope, and nothing beyond ───────────

test('an active read_only grant permits its abilities and refuses writes and PHI (T5)', function () {
    $tenant = opgTenant();
    $operator = opgOperator();

    opgGrants()->issue($operator, $tenant, OperatorGrant::TIER_READ_ONLY, [],
        'Billing investigation — invoice discrepancy flagged 08 Jul.', 30);

    opgCtx()->set($tenant);

    expect(Gate::forUser($operator)->allows('billing.view'))->toBeTrue()
        ->and(Gate::forUser($operator)->allows('reporting.view'))->toBeTrue()
        // No writes.
        ->and(Gate::forUser($operator)->allows('billing.manage'))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('admin.manage'))->toBeFalse()
        // No patient data — the whole point of the tier.
        ->and(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-1']))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('document.view', ['patient_id' => 'PT-1']))->toBeFalse();
});

test('a configuration grant adds config writes but still refuses PHI (T5)', function () {
    $tenant = opgTenant();
    $operator = opgOperator();

    opgGrants()->issue($operator, $tenant, OperatorGrant::TIER_CONFIGURATION, [],
        'Config request — correcting the agent autonomy ceiling.', 30);

    opgCtx()->set($tenant);

    expect(Gate::forUser($operator)->allows('admin.manage'))->toBeTrue()
        ->and(Gate::forUser($operator)->allows('ai.manage'))->toBeTrue()
        ->and(Gate::forUser($operator)->allows('billing.view'))->toBeTrue()   // includes the floor
        ->and(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-1']))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('lab.result', ['patient_id' => 'PT-1']))->toBeFalse();
});

test('a full_support grant reaches ONLY the enumerated records — scope is checked at access time (T4)', function () {
    $tenant = opgTenant();
    $operator = opgOperator();
    opgFullGrant($operator, $tenant, ['PT-4471', 'PT-4472', 'PT-4488']);

    opgCtx()->set($tenant);

    // The three approved records.
    foreach (['PT-4471', 'PT-4472', 'PT-4488'] as $id) {
        expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => $id]))->toBeTrue();
    }

    // "+1,240 more records — all locked for this session" is literally true.
    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-9999']))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('document.view', ['patient_id' => 'PT-9999']))->toBeFalse()
        // A PHI ability with NO resource id names nothing, so it reaches nothing.
        ->and(Gate::forUser($operator)->allows('patient.view'))->toBeFalse()
        // Scope does not widen the tier: still no ability outside the allow-list.
        ->and(Gate::forUser($operator)->allows('billing.manage'))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('patient.edit', ['patient_id' => 'PT-4471']))->toBeFalse();
});

test('an unknown or absent ability is denied — the tier list is an allow-list, not a deny-list', function () {
    $tenant = opgTenant();
    $operator = opgOperator();
    opgFullGrant($operator, $tenant, ['PT-1']);

    opgCtx()->set($tenant);

    // A permission nobody placed in a tier is out of every tier by construction.
    expect(Gate::forUser($operator)->allows('surgery.manage'))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('some.future.permission'))->toBeFalse();
});

// ── Expiry and revocation grant nothing ─────────────────────────────────────────────

test('an EXPIRED grant grants nothing — the TTL is server-enforced, not a display timer (T2)', function () {
    $tenant = opgTenant();
    $operator = opgOperator();
    $grant = opgFullGrant($operator, $tenant, ['PT-4471'], 15);

    opgCtx()->set($tenant);
    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeTrue();

    // Walk past expires_at. Nothing else changes — no job runs, no status is rewritten.
    Carbon::setTestNow($grant->expires_at->copy()->addSecond());

    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('billing.view'))->toBeFalse()
        ->and(app(OperatorAccessService::class)->activeGrantFor($operator, $tenant->getKey()))->toBeNull();

    Carbon::setTestNow();
});

test('a REVOKED grant grants nothing on the very next check (T3)', function () {
    $tenant = opgTenant();
    $operator = opgOperator();
    $grant = opgFullGrant($operator, $tenant, ['PT-4471']);
    $owner = User::factory()->forTenant($tenant)->create();

    opgCtx()->set($tenant);
    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeTrue();

    opgGrants()->revoke($grant, $owner, 'Something came up on our side.');

    // Instant — no cache to bust, no session to invalidate.
    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeFalse()
        ->and($grant->refresh()->status)->toBe(OperatorGrant::STATUS_REVOKED);
});

test('a grant is only ever usable in the tenant it names, by the operator it names', function () {
    $alpha = opgTenant('opg-alpha');
    $beta = opgTenant('opg-beta');
    $operator = opgOperator();
    $other = opgOperator();
    opgFullGrant($operator, $alpha, ['PT-4471']);

    opgCtx()->set($beta);
    expect(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeFalse();

    opgCtx()->set($alpha);
    expect(Gate::forUser($other)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('patient.view', ['patient_id' => 'PT-4471']))->toBeTrue();
});

// ── Issuing is not self-granting ────────────────────────────────────────────────────

test('full_support cannot be issued without a tenant approver, and never self-approved (T6)', function () {
    $tenant = opgTenant();
    $operator = opgOperator();
    $scope = ['patients' => ['PT-4471']];

    // No approver at all.
    expect(fn () => opgGrants()->issue($operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT, $scope, 'r', 15))
        ->toThrow(InvalidArgumentException::class);

    // The operator approving themselves.
    expect(fn () => opgGrants()->issue($operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT, $scope, 'r', 15, $operator))
        ->toThrow(InvalidArgumentException::class);

    // Another platform operator approving — not a user of the tenant.
    expect(fn () => opgGrants()->issue($operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT, $scope, 'r', 15, opgOperator()))
        ->toThrow(InvalidArgumentException::class);

    // A user of a DIFFERENT tenant approving.
    $stranger = User::factory()->forTenant(opgTenant('opg-gamma'))->create();
    expect(fn () => opgGrants()->issue($operator, $tenant, OperatorGrant::TIER_FULL_SUPPORT, $scope, 'r', 15, $stranger))
        ->toThrow(InvalidArgumentException::class);

    // Nothing was created by any of those attempts.
    expect(OperatorGrant::query()->count())->toBe(0);
});

test('a grant requires a reason and a time-box, and only a platform operator can hold one', function () {
    $tenant = opgTenant();
    $operator = opgOperator();
    $staff = User::factory()->forTenant($tenant)->create();

    expect(fn () => opgGrants()->issue($operator, $tenant, OperatorGrant::TIER_READ_ONLY, [], '   ', 15))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => opgGrants()->issue($operator, $tenant, OperatorGrant::TIER_READ_ONLY, [], 'r', 0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => opgGrants()->issue($operator, $tenant, 'god_mode', [], 'r', 15))
        ->toThrow(InvalidArgumentException::class)
        // Tenant staff never hold an operator grant — that is what RBAC is for.
        ->and(fn () => opgGrants()->issue($staff, $tenant, OperatorGrant::TIER_READ_ONLY, [], 'r', 15))
        ->toThrow(InvalidArgumentException::class);
});

test('the grant FACTS are immutable — a grant cannot be widened after the fact', function () {
    $tenant = opgTenant();
    $operator = opgOperator();
    $grant = opgFullGrant($operator, $tenant, ['PT-4471']);

    foreach ([
        ['tier' => OperatorGrant::TIER_READ_ONLY],                       // a different tier
        ['scope' => ['patients' => ['PT-4471', 'PT-9999']]],             // a wider scope
        ['expires_at' => Carbon::now()->addDay()],                       // a later expiry
        ['tenant_id' => opgTenant('opg-delta')->getKey()],               // another tenant
        ['operator_id' => opgOperator()->getKey()],                      // another operator
    ] as $change) {
        $fresh = OperatorGrant::query()->findOrFail($grant->getKey());
        $key = array_key_first($change);
        $fresh->{$key} = $change[$key];
        expect(fn () => $fresh->save())->toThrow(RuntimeException::class);
    }

    expect(fn () => OperatorGrant::query()->findOrFail($grant->getKey())->delete())
        ->toThrow(RuntimeException::class);
});

// ── The agent never holds a grant (T9) ──────────────────────────────────────────────

test('THE AGENT EXCLUSION: no agent path can reach an operator grant (T9)', function () {
    // Structural, in the Betreibung (ARDETAIL.P6) style: the ONLY files in Modules/ and
    // app/ that reference the grant model or its services are the model, the access
    // service, the issuing service and the two RBAC integration points.
    /** PHP-native scan — no shell_exec, so it behaves identically on Windows and CI Linux. */
    $scan = function (string $root): array {
        if (! is_dir($root)) {
            return [];
        }
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $body = (string) file_get_contents($file->getPathname());
            if (preg_match('/OperatorGrant|OperatorAccessService|operator_access_grants/', $body)) {
                $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            }
        }

        return $found;
    };

    $hits = [...$scan(base_path('Modules')), ...$scan(base_path('app'))];
    sort($hits);

    expect($hits)->toBe([
        // The migration, the model, the two RBAC integration points, the decision
        // service, the issuing service — and the tenant-context middleware, which only
        // NAMES the invariant in a comment explaining why it clears a super-admin's
        // context. Nothing else in Modules/ or app/ can reach an operator grant.
        'Modules/Platform/database/migrations/2026_08_31_000001_create_operator_access_grants_table.php',
        'Modules/Platform/src/Http/Middleware/IdentifyTenantFromUser.php',
        'Modules/Platform/src/Models/OperatorGrant.php',
        'Modules/Platform/src/Providers/PlatformServiceProvider.php',
        'Modules/Platform/src/Services/OperatorAccessService.php',
        'Modules/Platform/src/Services/PermissionService.php',
        'app/Services/OperatorGrantService.php',
    ]);

    // The agent layer is name-checked directly: no AiCore code and no AiTool touches a
    // grant, and nothing operator-related is scheduled. (Both directories genuinely
    // exist, so an empty result means "no reference", never "the scan did not run".)
    expect(is_dir(base_path('Modules/AiCore')))->toBeTrue()
        ->and(is_dir(base_path('app/AiCore')))->toBeTrue()
        ->and($scan(base_path('Modules/AiCore')))->toBe([])
        ->and($scan(base_path('app/AiCore')))->toBe([])
        ->and(str_contains((string) file_get_contents(base_path('routes/console.php')), 'operator'))->toBeFalse();

    // And an agent actor is not a super-admin, so it can never satisfy the rule anyway.
    expect(OperatorGrant::ACTOR_TYPE)->toBe('operator');
});

// ── Audit (T11/T12 groundwork) ──────────────────────────────────────────────────────

test('the grant, its revocation and accesses are audited into the TARGET TENANT ledger, chain intact', function () {
    $tenant = opgTenant();
    $operator = opgOperator();
    $grant = opgFullGrant($operator, $tenant, ['PT-4471']);
    $owner = User::factory()->forTenant($tenant)->create();

    opgGrants()->recordAccess($grant, 'patient.view', 'PT-4471');
    opgGrants()->revoke($grant, $owner, 'Pausing this for now.');

    $rows = app(TenantContext::class)->system(fn () => AuditEvent::query()
        ->where('tenant_id', $tenant->getKey())
        ->where('resource_type', 'operator_access_grant')
        ->orderBy('occurred_at')->get());

    expect($rows->pluck('action')->all())
        ->toBe(['operator.grant_issued', 'operator.access', 'operator.grant_revoked'])
        // Written to the CLINIC's own ledger, under an actor type the clinic can single out.
        ->and($rows->pluck('tenant_id')->unique()->all())->toBe([$tenant->getKey()])
        ->and($rows->pluck('actor_type')->unique()->all())->toBe(['operator'])
        ->and($rows->first()->reason)->toBe('Investigating a booking data-sync fault.');

    // The scope is recorded on the issuing row (decode, never match raw JSON — D-156).
    $issued = json_decode((string) $rows->first()->getRawOriginal('context'), true);
    expect($issued['tier'])->toBe(OperatorGrant::TIER_FULL_SUPPORT)
        ->and($issued['scope']['patients'])->toBe(['PT-4471']);

    // Append-only + hash-chained: the tenant's chain still verifies.
    expect(app(AuditService::class)->verifyChain($tenant->getKey())['ok'])->toBeTrue();
});
