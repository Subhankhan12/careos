<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\OperatorGrant;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\OperatorAccessService;
use Modules\Platform\Services\TenantContext;

/**
 * OPMODE.G1 — issuing and ending operator grants.
 *
 * Lives in the application layer, exactly like {@see BreakGlassService}, so it may
 * compose the Platform grant model with the Audit write path without either module
 * depending on the other (D-017).
 *
 * THIS IS NOT A SELF-GRANT PATH. It reuses BreakGlass's *audit* discipline (a required
 * reason, an append-only hash-chained row for every transition) and deliberately NOT
 * its self-grant semantics: `BreakGlassService::request()` creates a grant with
 * `activated => true`, so requesting IS granting. Here, a tier that requires the tenant
 * owner's approval CANNOT be issued without an approver who is a user of the target
 * tenant and is not the operator — so an operator can never open their own access to
 * patient data.
 *
 * The operator-facing REQUEST flow (G2) and the owner DECISION flow (G3) will call this;
 * neither exists yet. There is no HTTP route to any of it in G1.
 */
class OperatorGrantService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TenantContext $tenants,
    ) {}

    /** Scope values that would silently mean "everything". Never accepted. */
    private const WILDCARDS = ['*', 'all', 'ALL', 'any', '%'];

    /**
     * OPMODE.G2 — THE REQUEST ENTRY POINT. **Requesting is not granting.**
     *
     * This is the operator-facing action: ask for access to a tenant, stating a tier, a
     * minimised scope, a justification and how long a session should last. What it does
     * NEXT is decided entirely by the tier, and is the whole point of the gate:
     *
     *  - `configuration` / `full_support` (SETTLED PRODUCT DECISION, D-162 — both now
     *    require the tenant owner) → a **PENDING** row that opens **NOTHING**. It has no
     *    `granted_at` and no `expires_at`, so G1's invariant denies every ability while
     *    it waits: `isActiveAt()` requires `status === active`, and a pending row is not.
     *    Only an owner decision (G3) can start its session clock.
     *  - `read_only` → self-granted **ACTIVE** immediately, because it is non-PHI reads
     *    only. Even then it opens nothing beyond that: G1's tier allow-list gives
     *    `read_only` exactly billing/reporting/audit **view** and refuses every PHI
     *    ability outright.
     *
     * There is no self-approval path anywhere in this class: nothing an operator can
     * call moves their own pending request to active. The only method that produces an
     * active approval-tier grant is {@see issue()}, which demands an approver who is a
     * user of the target tenant and is not the operator.
     *
     * @param  array<string, list<string>>  $scope  e.g. ['patients' => ['01J…']]
     */
    public function request(
        User $operator,
        Tenant $tenant,
        string $tier,
        array $scope,
        string $justification,
        int $sessionTtlMinutes,
        int $requestTtlMinutes,
    ): OperatorGrant {
        $justification = trim($justification);

        $this->assertRequestable($operator, $tier, $justification, $sessionTtlMinutes);

        if ($requestTtlMinutes < 1) {
            throw new InvalidArgumentException('A request must carry its own expiry.');
        }

        $scope = $this->assertMinimisedScope($tier, $scope);

        $now = Carbon::now();
        $needsApproval = in_array($tier, OperatorGrant::TIERS_REQUIRING_APPROVAL, true);

        $grant = $this->tenants->system(fn (): OperatorGrant => OperatorGrant::create([
            'operator_id' => $operator->getKey(),
            'tenant_id' => $tenant->getKey(),
            'tier' => $tier,
            'scope' => $scope,
            'reason' => $justification,
            'requested_at' => $now,
            'request_expires_at' => $now->copy()->addMinutes($requestTtlMinutes),
            'requested_ttl_minutes' => $sessionTtlMinutes,

            // The core property, expressed in two columns: an approval tier gets a
            // PENDING status and NO session clock, so there is nothing to be active with.
            'status' => $needsApproval ? OperatorGrant::STATUS_PENDING : OperatorGrant::STATUS_ACTIVE,
            'granted_at' => $needsApproval ? null : $now,
            'expires_at' => $needsApproval ? null : $now->copy()->addMinutes($sessionTtlMinutes),
        ]));

        $this->recordToTenantLedger(
            $grant,
            $needsApproval ? 'operator.access_requested' : 'operator.self_granted',
            $justification,
            [
                'tier' => $tier,
                'scope' => $scope,
                'grants_access_now' => ! $needsApproval,
                'awaiting_owner_decision' => $needsApproval,
                'request_expires_at' => $grant->request_expires_at?->toIso8601String(),
                'requested_ttl_minutes' => $sessionTtlMinutes,
                'expires_at' => $grant->expires_at?->toIso8601String(),
            ],
        );

        return $grant;
    }

    /**
     * Move every pending request whose ASK has lapsed to `expired`.
     *
     * The request clock grants nothing either way: a lapsed request could never have
     * been activated, because {@see OperatorGrant::isAwaitingDecisionAt()} already
     * refuses an out-of-time row. This sweeper is housekeeping — it makes the lapse
     * visible and auditable — and is never the thing that keeps access closed.
     */
    public function expireDueRequests(?Carbon $asOf = null): int
    {
        $moment = $asOf ?? Carbon::now();

        $due = $this->tenants->system(fn () => OperatorGrant::query()
            ->where('status', OperatorGrant::STATUS_PENDING)
            ->whereNotNull('request_expires_at')
            ->where('request_expires_at', '<=', $moment)
            ->get());

        foreach ($due as $grant) {
            $this->tenants->system(fn () => $grant->forceFill([
                'status' => OperatorGrant::STATUS_EXPIRED,
            ])->save());

            $this->recordToTenantLedger($grant, 'operator.request_expired', null, [
                'tier' => $grant->tier,
                'requested_at' => $grant->requested_at?->toIso8601String(),
                'request_expires_at' => $grant->request_expires_at?->toIso8601String(),
                'granted_access' => false,
            ]);
        }

        return $due->count();
    }

    /**
     * The guard G3's approve() MUST call before starting a session.
     *
     * It lives here now, with the request clock it enforces, so the rule cannot be
     * re-invented (or forgotten) alongside the approval endpoint later.
     */
    public function assertActivatable(OperatorGrant $grant, ?Carbon $moment = null): void
    {
        if ($grant->status !== OperatorGrant::STATUS_PENDING) {
            throw new InvalidArgumentException(
                "Only a pending request can be activated; this one is [{$grant->status}]."
            );
        }

        if ($grant->isRequestExpiredAt($moment)) {
            throw new InvalidArgumentException('This request has expired and can no longer be approved.');
        }
    }

    /**
     * Shared request/issue validation.
     */
    private function assertRequestable(User $operator, string $tier, string $justification, int $ttlMinutes): void
    {
        if (! $operator->isSuperAdmin()) {
            throw new InvalidArgumentException('Only a platform operator can request operator access.');
        }

        if (! in_array($tier, OperatorGrant::TIERS, true)) {
            throw new InvalidArgumentException("Unknown operator tier [$tier].");
        }

        if ($justification === '') {
            throw new InvalidArgumentException('An operator grant requires a reason.');
        }

        if ($ttlMinutes < 1) {
            throw new InvalidArgumentException('An operator grant must be time-boxed.');
        }
    }

    /**
     * SCOPE MINIMISATION. `full_support` must name the specific records it needs — the
     * map's "3 records tied to a ticket, not your whole database".
     *
     * There is deliberately NO wildcard: whether an "all patient records" grant should
     * exist at all is an open product decision, so until it is answered the only way to
     * reach a record is to have named it. Fail-closed by omission.
     *
     * The parameter is typed loosely ON PURPOSE. This runs at a security boundary and
     * will receive request input once G2's caller becomes an HTTP endpoint, so the
     * shape checks below are real work, not redundant assertions about a trusted type.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, list<string>>
     */
    private function assertMinimisedScope(string $tier, array $scope): array
    {
        foreach ($scope as $kind => $ids) {
            if (! is_array($ids)) {
                throw new InvalidArgumentException("Scope [$kind] must be a list of explicit ids.");
            }

            foreach ($ids as $id) {
                if (! is_scalar($id) || trim((string) $id) === '') {
                    throw new InvalidArgumentException("Scope [$kind] contains an empty id.");
                }

                if (in_array(trim((string) $id), self::WILDCARDS, true)) {
                    throw new InvalidArgumentException(
                        "Scope [$kind] may not use a wildcard — name the specific records."
                    );
                }
            }
        }

        if ($tier === OperatorGrant::TIER_FULL_SUPPORT) {
            $patients = $scope['patients'] ?? [];

            if (! is_array($patients) || $patients === []) {
                throw new InvalidArgumentException(
                    'A full_support request must name the specific patient records it needs.'
                );
            }
        }

        return $scope;
    }

    /**
     * Issue an ACTIVE grant. The approval rule is enforced here, not by a caller.
     *
     * This is the POST-DECISION primitive: G3's owner-approval flow calls it. It is not
     * a request, and it is not reachable by an operator acting alone — an approval tier
     * needs an approver who belongs to the target tenant and is not the operator.
     *
     * @param  array<string, list<string>>  $scope  e.g. ['patients' => ['01J…']]
     */
    public function issue(
        User $operator,
        Tenant $tenant,
        string $tier,
        array $scope,
        string $reason,
        int $ttlMinutes,
        ?User $approvedBy = null,
    ): OperatorGrant {
        $reason = trim($reason);

        $this->assertRequestable($operator, $tier, $reason, $ttlMinutes);
        $scope = $this->assertMinimisedScope($tier, $scope);

        // T6 — the approver can never be the requester, and must belong to the tenant
        // whose data is being opened. Structural, not a UI rule.
        if (in_array($tier, OperatorGrant::TIERS_REQUIRING_APPROVAL, true)) {
            if (! $approvedBy instanceof User) {
                throw new InvalidArgumentException("Tier [$tier] requires the tenant owner's approval.");
            }

            if ($approvedBy->getKey() === $operator->getKey()) {
                throw new InvalidArgumentException('An operator can never approve their own grant.');
            }

            if ($approvedBy->tenant_id !== $tenant->getKey()) {
                throw new InvalidArgumentException('Only a user of the target tenant can approve this grant.');
            }
        }

        $now = Carbon::now();

        // The grant is a platform row about a tenant; it is written outside tenant
        // scoping (see OperatorGrant) but always names its tenant explicitly.
        $grant = $this->tenants->system(fn (): OperatorGrant => OperatorGrant::create([
            'operator_id' => $operator->getKey(),
            'tenant_id' => $tenant->getKey(),
            'tier' => $tier,
            'scope' => $scope,
            'reason' => $reason,
            'status' => OperatorGrant::STATUS_ACTIVE,
            'granted_at' => $now,
            'expires_at' => $now->copy()->addMinutes($ttlMinutes),
        ]));

        $this->recordToTenantLedger($grant, 'operator.grant_issued', $reason, [
            'tier' => $tier,
            'scope' => $scope,
            'expires_at' => $grant->expires_at?->toIso8601String(),
            'approved_by' => $approvedBy?->getKey(),
        ]);

        return $grant;
    }

    /**
     * Revoke an active grant. Effective immediately — the very next access check fails,
     * because {@see OperatorAccessService} re-reads status and
     * revocation on every call rather than trusting anything cached (T3).
     */
    public function revoke(OperatorGrant $grant, User $revokedBy, ?string $reason = null): OperatorGrant
    {
        $this->tenants->system(function () use ($grant, $revokedBy): void {
            $grant->forceFill([
                'status' => OperatorGrant::STATUS_REVOKED,
                'revoked_at' => Carbon::now(),
                'revoked_by' => $revokedBy->getKey(),
            ])->save();
        });

        $this->recordToTenantLedger($grant, 'operator.grant_revoked', $reason, [
            'revoked_by' => $revokedBy->getKey(),
        ]);

        return $grant->refresh();
    }

    /**
     * Record one access made under a grant, into the TARGET TENANT's ledger.
     *
     * The tenant-visible access log and per-session accumulation are G4/G5; the audit
     * rows start here so the trail exists from the first gate.
     */
    public function recordAccess(OperatorGrant $grant, string $ability, ?string $resourceId = null): void
    {
        $this->recordToTenantLedger($grant, 'operator.access', null, [
            'ability' => $ability,
            'resource_id' => $resourceId,
            'tier' => $grant->tier,
        ]);
    }

    /**
     * Every operator event is written to the TARGET TENANT's append-only hash-chained
     * ledger — the clinic's own record of what the platform did, not only the
     * platform's — under actor_type 'operator' so it is distinguishable from staff,
     * agent, patient and system activity.
     *
     * @param  array<string, mixed>  $context
     */
    private function recordToTenantLedger(
        OperatorGrant $grant,
        string $action,
        ?string $reason,
        array $context,
    ): void {
        $this->audit->record([
            'tenant_id' => $grant->tenant_id,
            'action' => $action,
            'actor_type' => OperatorGrant::ACTOR_TYPE,
            'actor_id' => (string) $grant->operator_id,
            'resource_type' => 'operator_access_grant',
            'resource_id' => $grant->getKey(),
            'reason' => $reason,
            'context' => $context,
        ]);
    }
}
