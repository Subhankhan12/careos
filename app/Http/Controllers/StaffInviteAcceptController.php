<?php

namespace App\Http\Controllers;

use App\Services\StaffInviteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public staff-invite acceptance (SETTINGS.P6). A guest reaches this via the single-use token in
 * the invite email. Accepting provisions the User in the invite's tenant (via
 * {@see StaffInviteService::accept}) and logs them in; the mandatory-2FA enrollment middleware then
 * forces 2FA on their first authenticated request — the existing onboarding, reused. Rate-limited
 * at the route. The token alone authorizes provisioning, and only into its own tenant.
 */
class StaffInviteAcceptController
{
    public function show(string $token, StaffInviteService $invites): Response
    {
        $preview = $invites->preview($token);

        return Inertia::render('Auth/AcceptInvite', [
            'token' => $token,
            'valid' => $preview !== null,
            'email' => $preview['email'] ?? null,
            'tenantName' => $preview['tenantName'] ?? null,
            'roleName' => $preview['roleName'] ?? null,
        ]);
    }

    public function accept(Request $request, string $token, StaffInviteService $invites): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Throws a validation error for an invalid/expired/consumed token (single-use).
        $user = $invites->accept($token, $data['name'], $data['password']);

        // Log in through the real guard; the two-factor middleware redirects to enrollment next.
        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/app');
    }
}
