<?php

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Platform\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mandatory MFA: an authenticated user who has NOT completed TOTP two-factor
 * enrollment cannot reach application routes. This applies to everyone —
 * tenant staff and super-admins alike.
 *
 * The routes needed to actually enroll (and to log out) are exempt so the user
 * is not locked out of completing enrollment. The enrollment UI itself arrives
 * in gate A.8; here we build and test the enforcement.
 */
class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        if ($this->isEnrollmentRoute($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, __('platform::auth.two_factor_required'));
        }

        return redirect()->route('two-factor.enrollment');
    }

    /**
     * Routes that must stay reachable so an un-enrolled user can enroll or leave.
     */
    private function isEnrollmentRoute(Request $request): bool
    {
        if ($request->routeIs('two-factor.enrollment', 'logout')) {
            return true;
        }

        return $request->is(
            'user/two-factor-authentication',
            'user/confirmed-two-factor-authentication',
            'user/two-factor-qr-code',
            'user/two-factor-recovery-codes',
            'user/two-factor-secret-key',
            'user/confirm-password',
            'user/confirmed-password-status',
            'two-factor-challenge',
        );
    }
}
