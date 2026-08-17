<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Services\OperatorAccessService;
use RuntimeException;

/**
 * OPMODE.G1 — a platform operator's grant to reach ONE tenant's data, for a bounded
 * time, in a bounded tier, over a bounded set of resources.
 *
 * This is the ONLY thing that opens a tenant's data to a super-admin. Without an
 * active grant a super-admin has no tenant-data access at all (see
 * {@see OperatorAccessService}).
 *
 * NOT tenant-scoped: it is a platform-owned row that REFERENCES a tenant. Scoping it
 * to a tenant context would be circular — the grant is what produces the context.
 *
 * NOT the BreakGlass self-grant model: creating a grant does not activate it. A tier
 * that requires the tenant owner's approval can only be activated by the approval flow
 * (G3); requesting is never granting.
 *
 * The GRANT FACTS are immutable once written (operator, tenant, tier, scope, expiry) —
 * enforced in {@see booted()}. Only the lifecycle columns move, and every movement is
 * written to the TARGET TENANT's append-only hash-chained ledger.
 *
 * @property string $id
 * @property int $operator_id
 * @property string $tenant_id
 * @property string $tier
 * @property array<string, list<string>>|null $scope
 * @property string $reason
 * @property string $status
 * @property Carbon|null $granted_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 */
class OperatorGrant extends Model
{
    use HasUlids;

    /** Configuration and usage, read-only. NO writes. NO patient data. */
    public const TIER_READ_ONLY = 'read_only';

    /** Adds settings/agent-config WRITES. Still NO patient data. */
    public const TIER_CONFIGURATION = 'configuration';

    /** Adds patient-record access, limited to the grant's scope. Owner-approved (G3). */
    public const TIER_FULL_SUPPORT = 'full_support';

    public const TIERS = [self::TIER_READ_ONLY, self::TIER_CONFIGURATION, self::TIER_FULL_SUPPORT];

    /** The tiers a tenant owner must approve before they may become active (enforced in G3). */
    public const TIERS_REQUIRING_APPROVAL = [self::TIER_FULL_SUPPORT];

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_DECLINED = 'declined';

    /** The audit actor type every operator action is recorded under. */
    public const ACTOR_TYPE = 'operator';

    /**
     * The grant facts. Once the row exists these can never change — a different tier,
     * scope, tenant or expiry is a different grant, requiring its own decision.
     *
     * @var list<string>
     */
    private const IMMUTABLE = ['operator_id', 'tenant_id', 'tier', 'scope', 'expires_at', 'granted_at', 'reason'];

    protected $table = 'operator_access_grants';

    protected $fillable = [
        'operator_id', 'tenant_id', 'tier', 'scope', 'reason',
        'status', 'granted_at', 'expires_at', 'revoked_at', 'revoked_by',
    ];

    protected $casts = [
        'scope' => 'array',
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // The grant facts are immutable; the lifecycle may move forward. A grant is a
        // recorded decision, so silently re-pointing one at another tenant, a higher
        // tier, a wider scope or a later expiry must be impossible.
        static::updating(function (OperatorGrant $grant): void {
            foreach (self::IMMUTABLE as $column) {
                if ($grant->isDirty($column)) {
                    throw new RuntimeException(
                        "operator_access_grants.$column is immutable — issue a new grant instead."
                    );
                }
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('operator_access_grants rows are never deleted.');
        });
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Is this grant usable RIGHT NOW? Status, expiry and revocation are all checked
     * here, at ACCESS time — never cached, never trusted from the client. An expired or
     * revoked grant opens nothing, even mid-request.
     */
    public function isActiveAt(?Carbon $moment = null): bool
    {
        $moment ??= Carbon::now();

        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($this->revoked_at !== null) {
            return false;
        }

        // Fail-closed: a grant with no expiry is not an eternal grant, it is an invalid one.
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->greaterThan($moment);
    }

    /**
     * Is $resourceId within this grant's scope for $kind (e.g. 'patients')?
     *
     * Fail-closed by construction: an absent kind, an empty list, or a null id is NOT
     * in scope. There is no "unrestricted" value — a grant reaches exactly what it
     * enumerates and nothing else.
     */
    public function coversResource(string $kind, ?string $resourceId): bool
    {
        if ($resourceId === null) {
            return false;
        }

        $scope = $this->scope ?? [];
        $ids = $scope[$kind] ?? null;

        if (! is_array($ids) || $ids === []) {
            return false;
        }

        return in_array($resourceId, array_map('strval', $ids), true);
    }
}
