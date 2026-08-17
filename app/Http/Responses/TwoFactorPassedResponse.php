<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Modules\Platform\Http\Middleware\EnsureTwoFactorEnabled;
use Symfony\Component\HttpFoundation\Response;

/**
 * AUTH-SEC.1 — the ONE place a passed two-factor challenge is recorded on the session.
 *
 * Fortify calls this only after {@see TwoFactorAuthenticatedSessionController::store()}
 * has validated a TOTP code or a recovery code, so the flag can only be written by a user who actually
 * produced the second factor in this session — never by being authenticated, enrolled or remembered.
 * {@see EnsureTwoFactorEnabled} requires that flag before any application route, which is what stops a
 * remember-me cookie from standing in for the second factor.
 *
 * The redirect itself is unchanged: it delegates to {@see RoleBasedLoginResponse} so super-admins and
 * tenant staff still land on their own shells.
 */
class TwoFactorPassedResponse implements TwoFactorLoginResponse
{
    public function __construct(private readonly RoleBasedLoginResponse $destination) {}

    public function toResponse($request): Response
    {
        // Fortify regenerates the session id immediately before returning this response, so the flag
        // is written onto the post-regeneration session.
        $request->session()->put(EnsureTwoFactorEnabled::CHALLENGE_PASSED_KEY, now()->toDateTimeString());

        return $this->destination->toResponse($request);
    }
}
