<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\BreakGlassGrant;
use Modules\Platform\Models\User;

/**
 * Break-glass emergency access. Lives in the application layer so it may compose
 * the Platform grant model with the Audit write path without either module
 * depending on the other.
 *
 * Grants are time-boxed (expiry checked at access time), require a reason, and
 * every request is recorded as an audit event flagged break_glass. Callers that
 * act under an active grant flag their access via {@see isActive()} +
 * context['break_glass'].
 */
class BreakGlassService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Create a time-boxed grant and audit the request (flagged break_glass).
     */
    public function request(User $user, string $scope, string $reason, int $ttlSeconds): BreakGlassGrant
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Break-glass requires a reason.');
        }

        $now = Carbon::now();

        $grant = BreakGlassGrant::create([
            'user_id' => $user->getKey(),
            'scope' => $scope,
            'reason' => $reason,
            'granted_at' => $now,
            'expires_at' => $now->copy()->addSeconds($ttlSeconds),
            'activated' => true,
        ]);

        $this->audit->record([
            'action' => 'break_glass.request',
            'actor_type' => 'user',
            'actor_id' => (string) $user->getKey(),
            'resource_type' => 'break_glass_grant',
            'resource_id' => $grant->id,
            'reason' => $reason,
            'context' => [
                'break_glass' => true,
                'scope' => $scope,
                'expires_at' => $grant->expires_at->toIso8601String(),
            ],
        ]);

        return $grant;
    }

    public function isActive(User $user, string $scope): bool
    {
        return $this->activeGrant($user, $scope) !== null;
    }

    public function activeGrant(User $user, string $scope): ?BreakGlassGrant
    {
        return BreakGlassGrant::query()
            ->where('user_id', $user->getKey())
            ->where('scope', $scope)
            ->where('activated', true)
            ->where('expires_at', '>', Carbon::now())
            ->latest('expires_at')
            ->first();
    }
}
