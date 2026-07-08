<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Modules\Platform\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the user to the shell for their role after login (or after the 2FA
 * challenge): super-admins → /admin, tenant staff → /app. Un-enrolled staff are
 * then routed to MFA enrollment by EnsureTwoFactorEnabled.
 */
class RoleBasedLoginResponse implements LoginResponse, TwoFactorLoginResponse
{
    public function toResponse($request): Response
    {
        $user = $request->user();
        $target = $user instanceof User && $user->isSuperAdmin() ? '/admin' : '/app';

        return $request->wantsJson()
            ? response()->json(['redirect' => $target])
            : redirect()->intended($target);
    }
}
