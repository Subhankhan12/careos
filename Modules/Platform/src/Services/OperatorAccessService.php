<?php

namespace Modules\Platform\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Models\OperatorGrant;
use Modules\Platform\Models\User;

/**
 * OPMODE.G1 — THE FAIL-CLOSED ACCESS INVARIANT.
 *
 * A platform operator (super-admin, tenant_id null) may reach a tenant's data ONLY
 * through an OperatorGrant for THAT tenant that is ACTIVE, UNEXPIRED, IN-TIER and
 * IN-SCOPE. Without one, they have no tenant-data access whatsoever.
 *
 * This REPLACES the previous blanket `Gate::before` bypass, under which a super-admin
 * passed every permission check unconditionally and the only thing containing them was
 * the emergent side effect of never being given a tenant context. That containment was
 * never an access-control decision; this class makes it one.
 *
 * The split that keeps legitimate platform work working:
 *
 *  - NO tenant context  → PLATFORM level. The console, the tenant list, cron and system
 *                         jobs are unchanged; a super-admin still passes. No tenant row
 *                         is reachable there anyway, because TenantScope throws without
 *                         a context.
 *  - tenant context set → INSIDE a tenant. The blanket bypass is gone: only a grant
 *                         permits anything, and only what the grant says.
 *
 * Everything is an ALLOW-LIST. An ability that no tier names is DENIED — a new
 * permission added to the catalog tomorrow is therefore out of every operator tier
 * until someone deliberately places it. Fail-closed by construction.
 */
class OperatorAccessService
{
    /**
     * Non-PHI, read-only visibility: what the clinic runs on, not who it treats.
     * The floor tier — every higher tier includes it.
     *
     * @var list<string>
     */
    public const READ_ONLY_ABILITIES = [
        'billing.view',
        'reporting.view',
        'audit.view',
    ];

    /**
     * Configuration WRITES on top of the read-only floor: settings, agent config and
     * comms configuration. Still NO patient data.
     *
     * Note `ai.manage` can only ever NARROW agent autonomy (the AGENT.P1 resolver caps
     * every level at MIN(configured, tool ceiling, role ceiling)), so this tier cannot
     * be used to widen what an agent may do.
     *
     * @var list<string>
     */
    public const CONFIGURATION_ABILITIES = [
        'admin.manage',
        'ai.manage',
        'comms.manage',
    ];

    /**
     * PHI-class abilities: reachable ONLY at full_support, and even then ONLY for the
     * resource ids the grant enumerates. Each maps to the scope kind its resource id is
     * checked against.
     *
     * @var array<string, string>
     */
    public const PHI_ABILITIES = [
        'patient.view' => 'patients',
        'document.view' => 'patients',
        'encounter.manage' => 'patients',
        'dental.chart' => 'patients',
        'lab.result' => 'patients',
        'radiology.study' => 'patients',
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * The single decision point. Returns true only when an active grant covers this
     * ability, for this tenant, at this moment, for this resource.
     *
     * @param  array<mixed>  $arguments  the Gate ability arguments
     */
    public function allows(User $operator, string $tenantId, string $ability, array $arguments = []): bool
    {
        $grant = $this->activeGrantFor($operator, $tenantId);

        if (! $grant instanceof OperatorGrant) {
            return false;                       // T1 — no grant, no access.
        }

        return $this->tierAllows($grant, $ability, $arguments);
    }

    /**
     * The operator's currently usable grant in this tenant, or null.
     *
     * Read OUTSIDE tenant scoping on purpose: the grant is a platform row about a
     * tenant, and it is what produces the context in the first place. It is still
     * constrained explicitly to this operator AND this tenant, so it can never widen
     * to another tenant's grant.
     *
     * Status, expiry and revocation are re-checked HERE, on every call — so an expired
     * or revoked grant stops working immediately, including mid-session (T2, T3).
     */
    public function activeGrantFor(User $operator, string $tenantId): ?OperatorGrant
    {
        if (! $operator->isSuperAdmin()) {
            return null;                        // grants are for platform operators only
        }

        $grant = $this->context->system(fn (): ?OperatorGrant => OperatorGrant::query()
            ->where('operator_id', $operator->getKey())
            ->where('tenant_id', $tenantId)
            ->where('status', OperatorGrant::STATUS_ACTIVE)
            ->whereNull('revoked_at')
            ->orderByDesc('expires_at')
            ->first());

        return $grant instanceof OperatorGrant && $grant->isActiveAt() ? $grant : null;
    }

    /**
     * Does this grant's TIER (and, for PHI, its SCOPE) permit this ability?
     *
     * @param  array<mixed>  $arguments
     */
    private function tierAllows(OperatorGrant $grant, string $ability, array $arguments): bool
    {
        // PHI is the sharp edge: full_support only, and only inside the enumerated scope.
        if (array_key_exists($ability, self::PHI_ABILITIES)) {
            if ($grant->tier !== OperatorGrant::TIER_FULL_SUPPORT) {
                return false;                   // T5 — read_only/configuration never reach PHI.
            }

            // T4 — scope is enforced at ACCESS time, per resource, not once at session start.
            return $grant->coversResource(
                self::PHI_ABILITIES[$ability],
                self::resourceIdFromArguments($arguments),
            );
        }

        $allowed = match ($grant->tier) {
            OperatorGrant::TIER_READ_ONLY => self::READ_ONLY_ABILITIES,
            OperatorGrant::TIER_CONFIGURATION,
            OperatorGrant::TIER_FULL_SUPPORT => [...self::READ_ONLY_ABILITIES, ...self::CONFIGURATION_ABILITIES],
            default => [],                      // an unknown tier grants nothing
        };

        return in_array($ability, $allowed, true);
    }

    /**
     * The resource id a PHI ability is being checked against, following the existing
     * Gate argument convention (named key first, positional fallback).
     *
     * @param  array<mixed>  $arguments
     */
    public static function resourceIdFromArguments(array $arguments): ?string
    {
        foreach (['patient_id', 'resource_id', 'id'] as $key) {
            if (array_key_exists($key, $arguments) && $arguments[$key] !== null) {
                return self::stringify($arguments[$key]);
            }
        }

        // isset() already excludes null, so the positional fallback needs no null check.
        return isset($arguments[0]) ? self::stringify($arguments[0]) : null;
    }

    private static function stringify(mixed $value): ?string
    {
        if ($value instanceof Model) {
            $key = $value->getKey();

            return is_scalar($key) ? (string) $key : null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
