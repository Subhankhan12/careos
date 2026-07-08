<?php

namespace Modules\Patients\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePatientPortalAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('patient')->check()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        return redirect()->route('portal.login');
    }
}
