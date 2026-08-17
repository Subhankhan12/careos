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
 * @property Carbon|null $requested_at
 * @property Carbon|null $request_expires_at
 * @property int|null $requested_ttl_minutes
 * @property Carbon|null $decided_at
 * @property int|null $decided_by
 * @property string|null $decision_note
 * @property string|null $supersedes_id
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

    /**
     * The tiers a tenant owner must approve before they may become active.
     *
     * SETTLED PRODUCT DECISION (OPMODE.G2, D-162): `configuration` joined `full_support`
     * here. It is a WRITE tier — it changes a live clinic's settings and agent
     * configuration — and the map flagged self-granting it as the weakest point in the
     * design. Only `read_only` (non-PHI reads) self-grants now.
     */
    public const TIERS_REQUIRING_APPROVAL = [self::TIER_CONFIGURATION, self::TIER_FULL_SUPPORT];

    /** The only self-granting tier: non-PHI reads, and nothing else. */
    public const TIERS_SELF_GRANTED = [self::TIER_READ_ONLY];

    /**
     * How much each tier opens, as a rank. Used ONLY to enforce that an owner's decision
     * can narrow and never widen (OPMODE.G3): a granted tier's rank must be <= the rank
     * of the tier that was asked for.
     *
     * @var array<string, int>
     */
    public const TIER_RANK = [
        self::TIER_READ_ONLY => 0,
        self::TIER_CONFIGURATION => 1,
        self::TIER_FULL_SUPPORT => 2,
    ];

    /** The tenant role whose holders may decide an operator's request (D-163). */
    public const OWNER_ROLE_KEY = 'org_admin';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_DECLINED = 'declined';

    /** The audit actor type every operator action is recorded under. */
    public const ACTOR_TYPE = 'operator';

    /**
     * The grant facts. Once the row exists these can NEVER change — a different tier,
     * scope, tenant or justification is a different request, requiring its own decision.
     *
     * @var list<string>
     */
    private const IMMUTABLE = [
        'operator_id', 'tenant_id', 'tier', 'scope', 'reason',
        'requested_at', 'request_expires_at', 'requested_ttl_minutes',
    ];

    /**
     * The session clock. Immutable ONCE SET, but allowed to go null -> value exactly
     * once, because a PENDING request has no session yet: the clock starts when an owner
     * approves (G3). This is a narrowing of G1's rule, not a loosening — before, these
     * could never move at all, which the pending->active transition requires; now they
     * can only ever be filled in from null, and never rewritten afterwards. A session
     * can therefore never be silently extended by re-pointing the column.
     *
     * @var list<string>
     */
    private const SET_ONCE = ['granted_at', 'expires_at'];

    protected $table = 'operator_access_grants';

    protected $fillable = [
        'operator_id', 'tenant_id', 'tier', 'scope', 'reason',
        'status', 'granted_at', 'expires_at', 'revoked_at', 'revoked_by',
        'requested_at', 'request_expires_at', 'requested_ttl_minutes',
        'decided_at', 'decided_by', 'decision_note', 'supersedes_id',
    ];

    protected $casts = [
        'scope' => 'array',
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'requested_at' => 'datetime',
        'request_expires_at' => 'datetime',
        'requested_ttl_minutes' => 'integer',
        'decided_at' => 'datetime',
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

            // Set-once: fillable from null when the session starts, never rewritten.
            foreach (self::SET_ONCE as $column) {
                if ($grant->isDirty($column) && $grant->getOriginal($column) !== null) {
                    throw new RuntimeException(
                        "operator_access_grants.$column is already set — a session is never re-clocked."
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

    /** The request this grant was issued in answer to (a downgrade only). */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * Would granting $tier over $scope be NARROWER-OR-EQUAL to what this request asked
     * for? An owner may hand back less than was requested, never more (OPMODE.G3).
     *
     * Fail-closed on both axes:
     *  - TIER: the granted rank must be <= the requested rank.
     *  - SCOPE: every granted id must already appear in the request, per kind. A kind the
     *    request never mentioned cannot be introduced by the decision.
     *
     * @param  array<string, mixed>  $scope
     */
    public function isNarrowerOrEqual(string $tier, array $scope): bool
    {
        $grantedRank = self::TIER_RANK[$tier] ?? null;
        $requestedRank = self::TIER_RANK[$this->tier] ?? null;

        if ($grantedRank === null || $requestedRank === null || $grantedRank > $requestedRank) {
            return false;
        }

        $requested = $this->scope ?? [];

        foreach ($scope as $kind => $ids) {
            if (! is_array($ids)) {
                return false;
            }

            $allowed = is_array($requested[$kind] ?? null) ? array_map('strval', $requested[$kind]) : [];

            foreach ($ids as $id) {
                if (! in_array((string) $id, $allowed, true)) {
                    return false;       // a record the operator never asked for
                }
            }
        }

        return true;
    }

    /** Does this tier need a tenant owner's decision before it can ever open anything? */
    public function requiresApproval(): bool
    {
        return in_array($this->tier, self::TIERS_REQUIRING_APPROVAL, true);
    }

    /**
     * Has the ASK lapsed? This is the REQUEST clock, and it is not the session clock:
     * a lapsed request opens nothing and never did — it is the absence of a decision,
     * not the end of one. Kept separate from {@see isActiveAt()} on purpose.
     */
    public function isRequestExpiredAt(?Carbon $moment = null): bool
    {
        if ($this->request_expires_at === null) {
            return false;                       // never was a request with a clock
        }

        return $this->request_expires_at->lessThanOrEqualTo($moment ?? Carbon::now());
    }

    /**
     * Could an owner decision still turn this into a live session?
     *
     * Fail-closed: only a row that is STILL PENDING and whose request clock has NOT run
     * out. G3's approve() must gate on this — which is why the rule lives on the model
     * now rather than being invented alongside the endpoint later.
     */
    public function isAwaitingDecisionAt(?Carbon $moment = null): bool
    {
        return $this->status === self::STATUS_PENDING && ! $this->isRequestExpiredAt($moment);
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
