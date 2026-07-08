<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Modules\Platform\Http\Middleware\EnsureTwoFactorEnabled;
use Modules\Platform\Http\Middleware\IdentifyTenantFromUser;

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
        ]);

        // After the guard resolves the user: set tenant context, then enforce MFA.
        // Both self-skip for guests, so they are safe to run group-wide.
        $middleware->web(append: [
            IdentifyTenantFromUser::class,
            EnsureTwoFactorEnabled::class,
        ]);

        $middleware->api(append: [
            IdentifyTenantFromUser::class,
            EnsureTwoFactorEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
