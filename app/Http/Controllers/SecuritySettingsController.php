<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Platform\Http\Middleware\EnsureTwoFactorEnabled;
use Modules\Platform\Models\User;

/**
 * "Security" settings card (SETTINGS.P4) — a strictly READ-ONLY reflection of the real, enforced
 * platform security controls. It has NO update action on purpose: there is no code path from this
 * page that can disable or weaken any control.
 *
 *  - Two-factor authentication is MANDATORY, enforced for every authenticated user by
 *    {@see EnsureTwoFactorEnabled}. There is no setting that
 *    turns it off; the card renders it "Mandatory · locked". (No POST route exists here.)
 *  - Staff session timeout is the platform `session.lifetime` (minutes) — a deployment config, not
 *    a tenant setting. Rendered read-only (SETTINGS.P4 decision (a)).
 *  - Nurse-PWA idle wipe is a CLIENT build constant in the separate PWA
 *    (`nurse-pwa/src/idle.ts`: `VITE_NURSE_IDLE_TIMEOUT_MS ?? 15 min`) — never read from the
 *    server. Rendered read-only as the platform default (decision (a)); an editable server setting
 *    would be ignored by the already-built PWA, which would be dishonest.
 *
 * Gated on `admin.manage`. Nothing is written, so there is no audit and no tenant write here.
 */
class SecuritySettingsController
{
    /** Mirror of the PWA build default (`nurse-pwa/src/idle.ts`), in minutes. Display only. */
    private const NURSE_PWA_IDLE_MINUTES = 15;

    public function index(Request $request): Response
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        return Inertia::render('Admin/Security', [
            'security' => [
                // Reflects the EnsureTwoFactorEnabled middleware — mandatory, platform-enforced, locked.
                'twoFactor' => 'mandatory',
                'sessionTimeoutMin' => (int) config('session.lifetime'),
                'nursePwaIdleMin' => self::NURSE_PWA_IDLE_MINUTES,
            ],
            'settingsUrl' => route('settings.index'),
        ]);
    }
}
