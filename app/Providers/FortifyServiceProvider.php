<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\RoleBasedLoginResponse;
use App\Http\Responses\TwoFactorPassedResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Fortify;
use Modules\Platform\Http\Middleware\EnsureTwoFactorEnabled;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Role-based redirect after login and after the 2FA challenge.
        $this->app->singleton(LoginResponse::class, RoleBasedLoginResponse::class);
        // AUTH-SEC.1 — the two-factor response ALSO records that this session satisfied the second
        // factor (it still redirects via RoleBasedLoginResponse). EnsureTwoFactorEnabled requires that
        // record, which is what stops a remember-me cookie from standing in for the challenge.
        $this->app->singleton(TwoFactorLoginResponse::class, TwoFactorPassedResponse::class);
    }

    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // AUTH-SEC.1 — confirming enrollment requires a valid TOTP code, so it is a genuine
        // second-factor proof for this session: record it, exactly as a passed challenge would. Without
        // this a user would finish enrollment and immediately be bounced to a challenge.
        Event::listen(TwoFactorAuthenticationConfirmed::class, function (): void {
            session()->put(EnsureTwoFactorEnabled::CHALLENGE_PASSED_KEY, now()->toDateTimeString());
        });

        // Inertia pages for the headless Fortify auth flow (login + 2FA challenge).
        Fortify::loginView(fn () => Inertia::render('Auth/Login', [
            'status' => session('status'),
        ]));
        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/TwoFactorChallenge'));

        // Credential check + fail-closed rejection of suspended-tenant staff at login.
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', $request->input(Fortify::username()))->first();

            if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            if ($user->isTenantStaff()) {
                $tenant = Tenant::find($user->tenant_id);

                if ($tenant === null || $tenant->status === 'suspended') {
                    return null;
                }
            }

            return $user;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(
                Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip()
            );

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
