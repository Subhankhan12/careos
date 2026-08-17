<?php

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Platform\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mandatory MFA, enforced on BOTH axes:
 *
 *  1. **ENROLLED** — an authenticated user who has not completed TOTP enrollment cannot reach
 *     application routes (tenant staff and super-admins alike).
 *  2. **CHALLENGED THIS SESSION** (AUTH-SEC.1) — a session restored from the remember-me cookie has
 *     proved only the PASSWORD factor, so it must satisfy the second factor before app access.
 *
 * WHY (2) EXISTS. "Remember me" issues a long-lived recaller cookie that re-authenticates a browser
 * with no password prompt. Before this gate, such a session also skipped the 2FA challenge entirely
 * — the cookie alone opened the app — because this middleware only asked whether the user had
 * ENROLLED, never whether *this* session had passed a challenge. The recaller legitimately remembers
 * the PASSWORD factor; it must never stand in for the SECOND factor. A remembered browser therefore
 * still has to enter a code.
 *
 * WHAT COUNTS AS PROOF. The session flag is written in exactly two places, both of which require the
 * user to produce a valid TOTP/recovery code in that session: completing the two-factor challenge
 * (the app-layer `TwoFactorPassedResponse`, bound to Fortify's `TwoFactorLoginResponse`) and confirming
 * enrollment (the app-layer `TwoFactorAuthenticationConfirmed` listener). Merely being authenticated —
 * or enrolled, or remembered — never sets it. Both writers live in the APPLICATION layer, which is why
 * this middleware only reads the key it publishes: Platform never depends on `App\`.
 *
 * SCOPE — deliberately narrow. The re-challenge fires only for a session the guard restored FROM THE
 * RECALLER ({@see self::restoredFromRecaller()}), which is exactly the bypass that existed. Any other session is
 * untouched, because every other route to being authenticated either already involved the second
 * factor or is stopped by check (1): interactive login goes password → challenge → in; a freshly
 * enrolled user has just entered a valid code; an invited user is un-enrolled and caught above.
 * Widening this to "every session must carry the flag" would also reject test-authenticated sessions,
 * and papering over that in the test harness would hide the very bypass this gate closes.
 *
 * Interactive login is unchanged: password → challenge → in. Enrollment routes and logout stay
 * reachable so nobody can be locked out of completing (or leaving) the flow.
 */
class EnsureTwoFactorEnabled
{
    /** Set ONLY by a real second-factor proof in this session (challenge passed / enrollment confirmed). */
    public const CHALLENGE_PASSED_KEY = 'auth.two_factor_confirmed_at';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        // (1) Enrollment. Un-enrolled users are routed to enrollment (and may reach the routes that
        // let them enroll or log out).
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            if ($this->isEnrollmentRoute($request)) {
                return $next($request);
            }

            if ($request->expectsJson()) {
                abort(Response::HTTP_FORBIDDEN, __('platform::auth.two_factor_required'));
            }

            return redirect()->route('two-factor.enrollment');
        }

        // (2) A session restored from the REMEMBER-ME cookie has proved only the password factor.
        // Unless it has since satisfied the second factor, it must be challenged before app access.
        if (! $this->restoredFromRecaller($request) || $request->session()->has(self::CHALLENGE_PASSED_KEY)) {
            return $next($request);
        }

        if ($this->isEnrollmentRoute($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, __('platform::auth.two_factor_required'));
        }

        return $this->challengeAgain($request, $user);
    }

    /**
     * Was this request authenticated from the remember-me recaller cookie?
     *
     * The recaller is a SESSION-guard concept, so both conditions matter. A token-authenticated API
     * request (the Nurse PWA uses Sanctum) has no session and no recaller at all — and its guard is a
     * `RequestGuard`, which has no `viaRemember()` to ask. Asking the *default* guard would therefore
     * blow up on exactly those requests, so we ask the web guard, and only when a session exists.
     */
    private function restoredFromRecaller(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $guard = Auth::guard('web');

        return $guard instanceof SessionGuard && $guard->viaRemember();
    }

    /**
     * Turn an authenticated-but-unchallenged session (in practice: one restored from the remember-me
     * cookie) back into a PENDING two-factor login, and send it to the challenge.
     *
     * Signing the guard out first is deliberate: it drops the authenticated session and the recaller
     * cookie, so nothing downstream can treat this request as fully authenticated while the second
     * factor is still outstanding. `login.id` / `login.remember` are what Fortify's challenge expects,
     * so the user completes the normal challenge and — because `remember` is preserved — leaves with a
     * fresh remember cookie. The password factor stays remembered; the second factor never is.
     */
    private function challengeAgain(Request $request, User $user): Response
    {
        $userId = $user->getAuthIdentifier();

        Auth::guard('web')->logout();

        // We only get here from a recaller-restored session, so the user had chosen "remember me":
        // preserve that choice so the completed challenge re-issues a fresh recaller.
        $request->session()->put('login.id', $userId);
        $request->session()->put('login.remember', true);

        return redirect()->route('two-factor.login');
    }

    /**
     * Routes that must stay reachable so a user can enroll, satisfy the challenge, or leave.
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
