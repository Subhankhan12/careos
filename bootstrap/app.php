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
        // Sanctum stateful API for the future PWA / SPA token auth.
        $middleware->statefulApi();

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
