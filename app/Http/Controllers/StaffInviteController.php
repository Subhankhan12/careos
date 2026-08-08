<?php

namespace App\Http\Controllers;

use App\Services\StaffInviteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Platform\Models\StaffInvite;
use Modules\Platform\Models\User;

/**
 * Staff invite admin actions (SETTINGS.P6) — create / resend / revoke, gated `admin.manage`. All
 * work goes through {@see StaffInviteService} (audited, real RBAC path). Invites resolve by string
 * id under the tenant scope (FIX.1) so a cross-tenant id fails closed as 404. There is NO
 * permission-editing surface here — an invite grants a built-in role template, nothing more.
 */
class StaffInviteController
{
    public function store(Request $request, StaffInviteService $invites): RedirectResponse
    {
        Gate::authorize('admin.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:160'],
            'role_id' => ['required', 'string'],
        ]);

        $invites->invite($data['email'], $data['role_id'], $actor);

        return redirect()->route('admin.roles.index')->with('status', 'invited');
    }

    public function resend(Request $request, string $invite, StaffInviteService $invites): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $invites->resend(StaffInvite::query()->whereKey($invite)->firstOrFail());

        return redirect()->route('admin.roles.index')->with('status', 'inviteResent');
    }

    public function revoke(Request $request, string $invite, StaffInviteService $invites): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $invites->revoke(StaffInvite::query()->whereKey($invite)->firstOrFail());

        return redirect()->route('admin.roles.index')->with('status', 'inviteRevoked');
    }
}
