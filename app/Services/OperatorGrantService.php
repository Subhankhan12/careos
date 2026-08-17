<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\Audit\Services\AuditService;
use Modules\Comms\Services\NotificationService;
use Modules\Platform\Models\OperatorGrant;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
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
        private readonly NotificationService $notifications,
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

        // OPMODE.G3 — the owner is the gate, so the owner has to know. Only the tiers
        // that actually await a decision are routed; a self-granted read_only session
        // has nothing to decide.
        if ($needsApproval) {
            $this->notifyOwners($grant, $tenant, $operator);
        }

        return $grant;
    }

    /**
     * OPMODE.G3 — the tenant's OWNERS: the holders of the `org_admin` role in THAT tenant.
     *
     * SETTLED PRODUCT DECISION (D-163): the "owner" the wireframes draw is the tenant's
     * org_admin. No new role was invented — org_admin already means "runs this clinic",
     * and inventing a parallel owner concept would have created a second, weaker path to
     * the same authority.
     *
     * Read in system mode because the operator has no tenant context, but constrained
     * EXPLICITLY to this tenant on both the assignment and the role, so it can never
     * drift into another tenant's admins.
     *
     * @return Collection<int, User>
     */
    public function ownersFor(Tenant $tenant): Collection
    {
        return $this->tenants->system(function () use ($tenant): Collection {
            $userIds = RoleAssignment::query()
                ->where('role_user.tenant_id', $tenant->getKey())
                ->whereIn('role_id', Role::query()
                    ->where('tenant_id', $tenant->getKey())
                    ->where('key', OperatorGrant::OWNER_ROLE_KEY)
                    ->select('id'))
                ->pluck('user_id')
                ->unique();

            return User::query()
                ->whereIn('id', $userIds)
                ->where('tenant_id', $tenant->getKey())
                ->get();
        });
    }

    /** Is this user an owner (org_admin) of THIS tenant? The decision gate. */
    public function isOwnerOf(User $user, string $tenantId): bool
    {
        if ($user->isSuperAdmin() || $user->tenant_id !== $tenantId) {
            return false;                       // T6: an operator is never an owner
        }

        return $this->tenants->system(fn (): bool => RoleAssignment::query()
            ->where('role_user.tenant_id', $tenantId)
            ->where('user_id', $user->getKey())
            ->whereIn('role_id', Role::query()
                ->where('tenant_id', $tenantId)
                ->where('key', OperatorGrant::OWNER_ROLE_KEY)
                ->select('id'))
            ->exists());
    }

    /**
     * THE APPROVAL — the ONLY path that turns a pending approval-tier request into a live
     * session. Nothing else in the codebase moves a grant to `active` for those tiers.
     *
     * A straight approval activates the request row in place (its facts already say what
     * was asked, and that is exactly what is being granted).
     *
     * A DOWNGRADE — the owner handing back LESS than was asked — does NOT mutate the
     * request. G1/G2 make a grant's facts permanently immutable, so the request is closed
     * as `declined` and a NEW active grant is created at the narrower tier/scope, linked
     * by `supersedes_id`. Both facts survive: what was requested, and what was granted.
     *
     * @param  string|null  $tier  null = grant what was asked; otherwise a NARROWER tier
     * @param  array<string, mixed>|null  $scope  null = the requested scope; otherwise a SUBSET
     */
    public function approve(
        OperatorGrant $grant,
        User $owner,
        ?string $tier = null,
        ?array $scope = null,
        ?string $note = null,
    ): OperatorGrant {
        $this->assertOwnerMayDecide($grant, $owner);

        // The G2 guard: still pending, and the request clock has not run out.
        $this->assertActivatable($grant);

        $tier ??= $grant->tier;
        $scope ??= ($grant->scope ?? []);

        // AN OWNER MAY ONLY EVER NARROW. A "downgrade" that widened the tier or reached a
        // record the operator never asked for is refused outright.
        if (! $grant->isNarrowerOrEqual($tier, $scope)) {
            throw new InvalidArgumentException(
                'An owner decision may only narrow the request — never widen its tier or scope.'
            );
        }

        $scope = $this->assertMinimisedScope($tier, $scope);

        $now = Carbon::now();
        $ttl = $grant->requested_ttl_minutes ?? 15;
        $isDowngrade = $tier !== $grant->tier || $scope !== ($grant->scope ?? []);

        if (! $isDowngrade) {
            // Approved as asked: the session clock starts NOW (set-once from null, G2).
            $this->tenants->system(fn () => $grant->forceFill([
                'status' => OperatorGrant::STATUS_ACTIVE,
                'granted_at' => $now,
                'expires_at' => $now->copy()->addMinutes($ttl),
                'decided_at' => $now,
                'decided_by' => $owner->getKey(),
                'decision_note' => $note,
            ])->save());

            $this->recordDecision($grant, 'operator.request_approved', $owner, $note, [
                'tier' => $tier,
                'scope' => $scope,
                'downgraded' => false,
                'expires_at' => $grant->refresh()->expires_at?->toIso8601String(),
            ]);

            return $grant->refresh();
        }

        // Downgraded: close the request, then issue the narrower grant that answers it.
        $this->tenants->system(fn () => $grant->forceFill([
            'status' => OperatorGrant::STATUS_DECLINED,
            'decided_at' => $now,
            'decided_by' => $owner->getKey(),
            'decision_note' => $note,
        ])->save());

        $granted = $this->tenants->system(fn (): OperatorGrant => OperatorGrant::create([
            'operator_id' => $grant->operator_id,
            'tenant_id' => $grant->tenant_id,
            'tier' => $tier,
            'scope' => $scope,
            'reason' => $grant->reason,
            'status' => OperatorGrant::STATUS_ACTIVE,
            'requested_at' => $grant->requested_at,
            'request_expires_at' => $grant->request_expires_at,
            'requested_ttl_minutes' => $ttl,
            'granted_at' => $now,
            'expires_at' => $now->copy()->addMinutes($ttl),
            'decided_at' => $now,
            'decided_by' => $owner->getKey(),
            'decision_note' => $note,
            'supersedes_id' => $grant->getKey(),
        ]));

        $this->recordDecision($granted, 'operator.request_downgraded', $owner, $note, [
            'requested_tier' => $grant->tier,
            'requested_scope' => $grant->scope ?? [],
            'tier' => $tier,
            'scope' => $scope,
            'downgraded' => true,
            'supersedes' => $grant->getKey(),
            'expires_at' => $granted->expires_at?->toIso8601String(),
        ]);

        return $granted;
    }

    /**
     * THE DECLINE — the owner says no. Activates nothing, now or later: the row becomes
     * terminal, so {@see assertActivatable()} refuses it from then on.
     */
    public function decline(OperatorGrant $grant, User $owner, ?string $reason = null): OperatorGrant
    {
        $this->assertOwnerMayDecide($grant, $owner);
        $this->assertActivatable($grant);

        $now = Carbon::now();

        $this->tenants->system(fn () => $grant->forceFill([
            'status' => OperatorGrant::STATUS_DECLINED,
            'decided_at' => $now,
            'decided_by' => $owner->getKey(),
            'decision_note' => $reason,
        ])->save());

        $this->recordDecision($grant, 'operator.request_declined', $owner, $reason, [
            'tier' => $grant->tier,
            'scope' => $grant->scope ?? [],
            'granted_access' => false,
        ]);

        return $grant->refresh();
    }

    /**
     * ONLY an owner (org_admin) of the TARGET tenant may decide — fail-closed.
     *
     * This is where the two-party model is enforced: the operator cannot decide their own
     * request (they are a super-admin, so `isOwnerOf()` refuses them outright — the G2
     * no-self-approval rule), an org_admin of a DIFFERENT tenant cannot reach in, and a
     * tenant user without the role cannot decide either.
     */
    private function assertOwnerMayDecide(OperatorGrant $grant, User $owner): void
    {
        if (! $this->isOwnerOf($owner, (string) $grant->tenant_id)) {
            throw new InvalidArgumentException(
                'Only an owner (org_admin) of the target tenant can decide this request.'
            );
        }
    }

    /**
     * Notify the target tenant's owners that an operator is asking for access.
     *
     * HONEST ABOUT THE CHANNEL: email is the only transport that exists (the standing
     * SETTINGS.P5 seam — there is no in-app or push channel), so that is what is sent and
     * that is what is claimed. The template is deliberately NOT in
     * NotificationPreferenceService::MANAGEABLE, so a governance request can never be
     * switched off.
     *
     * @return int how many owners were notified
     */
    private function notifyOwners(OperatorGrant $grant, Tenant $tenant, User $operator): int
    {
        $owners = $this->ownersFor($tenant);

        if ($owners->isEmpty()) {
            // FAIL-CLOSED, and said out loud: with nobody to ask, the request simply
            // waits and then lapses. It never self-approves for want of an owner.
            $this->recordToTenantLedger($grant, 'operator.owner_unreachable', null, [
                'tier' => $grant->tier,
                'granted_access' => false,
            ]);

            return 0;
        }

        $scope = collect($grant->scope ?? [])
            ->map(fn (array $ids, string $kind): string => $kind.': '.implode(', ', $ids))
            ->implode(' · ');

        // The notification is a tenant-side write, so it needs that tenant's context.
        $previous = $this->tenants->current();
        $this->tenants->set($tenant);

        try {
            foreach ($owners as $owner) {
                $this->notifications->send('operator.access_requested', $owner, [
                    'operator' => $operator->name ?? ('user '.$operator->getKey()),
                    'tier' => $grant->tier,
                    'scope' => $scope !== '' ? $scope : 'configuration and settings only',
                    'justification' => $grant->reason,
                    'request_expires_at' => $grant->request_expires_at?->toDateTimeString() ?? '',
                ]);
            }
        } finally {
            $previous instanceof Tenant ? $this->tenants->set($previous) : $this->tenants->forget();
        }

        return $owners->count();
    }

    /**
     * A decision is recorded with the OWNER as the actor — not the operator. That is the
     * two-sided part of the audit: the clinic's ledger shows its own admin deciding, next
     * to the operator's request.
     *
     * @param  array<string, mixed>  $context
     */
    private function recordDecision(
        OperatorGrant $grant,
        string $action,
        User $owner,
        ?string $note,
        array $context,
    ): void {
        $this->audit->record([
            'tenant_id' => $grant->tenant_id,
            'action' => $action,
            'actor_type' => 'user',
            'actor_id' => (string) $owner->getKey(),
            'resource_type' => 'operator_access_grant',
            'resource_id' => $grant->getKey(),
            'reason' => $note,
            'context' => [...$context, 'operator_id' => $grant->operator_id],
        ]);
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
