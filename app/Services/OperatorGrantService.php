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

    /**
     * Issue an ACTIVE grant. The approval rule is enforced here, not by a caller.
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

        if (! $operator->isSuperAdmin()) {
            throw new InvalidArgumentException('Only a platform operator can hold an operator grant.');
        }

        if (! in_array($tier, OperatorGrant::TIERS, true)) {
            throw new InvalidArgumentException("Unknown operator tier [$tier].");
        }

        if ($reason === '') {
            throw new InvalidArgumentException('An operator grant requires a reason.');
        }

        if ($ttlMinutes < 1) {
            throw new InvalidArgumentException('An operator grant must be time-boxed.');
        }

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
