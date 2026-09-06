<?php

use App\Http\Middleware\ApplyTenantLocaleTimezone;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\FrontDesk\Http\Middleware\IdentifyKioskDevice;
use Modules\Patients\Http\Middleware\EnsurePatientPortalAuthenticated;
use Modules\Patients\Http\Middleware\EnsurePortalConsent;
use Modules\Patients\Http\Middleware\IdentifyTenantFromPortalSession;
use Modules\Platform\Http\Middleware\EnsureSuperAdmin;
use Modules\Platform\Http\Middleware\EnsureTwoFactorEnabled;
use Modules\Platform\Http\Middleware\IdentifyTenantFromUser;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // THE API IS TOKEN-ONLY — DO NOT ADD `statefulApi()` BACK WITHOUT A COOKIE CLIENT (QA-FIX.4a, D-201).
        //
        // `statefulApi()` used to be here "for the future PWA / SPA token auth", which is a
        // contradiction: it enables COOKIE-session auth for first-party SPAs and is exactly what
        // token auth does not need. Sanctum treats any request whose Origin is a stateful domain as
        // a first-party SPA and CSRF-checks it — and `SANCTUM_STATEFUL_DOMAINS` derives from
        // APP_URL, which is the very host `public/nurse-pwa/` is served from. So every POST the
        // Nurse PWA made to its own origin returned 419 while GET /day-pack still returned 200:
        // patient data reached the device and no recorded care could come back (P4-C1).
        //
        // Every route in routes/api.php authenticates with a Sanctum PERSONAL ACCESS TOKEN
        // (NurseAuthController mints one with the `nurse:day-pack` ability and a 12h expiry;
        // NurseSyncController re-checks it with tokenCan). A browser cannot silently attach a
        // Bearer token cross-origin, so CSRF is structurally inapplicable to these routes.
        //
        // CSRF for the Inertia app, the patient portal and the kiosk is UNAFFECTED — it lives in
        // the `web` middleware group, which this does not touch.
        $middleware->alias([
            'identify-tenant' => IdentifyTenantFromUser::class,
            'two-factor' => EnsureTwoFactorEnabled::class,
            'super-admin' => EnsureSuperAdmin::class,
            'portal-tenant' => IdentifyTenantFromPortalSession::class,
            'portal-auth' => EnsurePatientPortalAuthenticated::class,
            'portal-consent' => EnsurePortalConsent::class,
            'kiosk-device' => IdentifyKioskDevice::class,
        ]);

        // After the guard resolves the user: set tenant context, then enforce MFA.
        // Both self-skip for guests, so they are safe to run group-wide.
        // HandleInertiaRequests must run so shared props are available to pages.
        $middleware->web(append: [
            HandleInertiaRequests::class,
            IdentifyTenantFromUser::class,
            ApplyTenantLocaleTimezone::class,
            EnsureTwoFactorEnabled::class,
        ]);

        $middleware->api(append: [
            IdentifyTenantFromUser::class,
            EnsureTwoFactorEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Render user-facing denials / not-found as in-shell Eucalyptus Glow pages instead
        // of the bare Symfony error page. This is PRESENTATION ONLY: the status code — and
        // therefore the authorization decision that produced it — is preserved unchanged.
        // A staff 403 becomes a calm "no access" page; the portal consent-withdrawal lockout
        // (a 403 on a portal.* route) becomes the "access withdrawn — contact the practice"
        // page. Skipped under `testing` so the suite's status assertions stay exact, and for
        // API/JSON requests which must keep their machine-readable error responses.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            if (app()->environment('testing')
                || $request->is('api/*')
                || $request->expectsJson() && ! $request->header('X-Inertia')
                || ! in_array($status, [403, 404, 419, 503], true)) {
                return $response;
            }

            $isPortal = $request->routeIs('portal.*') || $request->is('portal', 'portal/*');

            return Inertia::render('Error', [
                'status' => $status,
                'context' => $isPortal ? 'portal' : 'staff',
            ])->toResponse($request)->setStatusCode($status);
        });
    })->create();
