<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Modules\Patients\Http\Controllers\PortalAuthController;
use Modules\Patients\Http\Controllers\PortalInvitationController;

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return redirect(auth()->user()->isSuperAdmin() ? '/admin' : '/app');
});

Route::middleware('auth')->group(function () {
    // Tenant app shell. Tenant identification + mandatory-MFA run in the web group.
    Route::get('/app', fn () => Inertia::render('App/Landing'))->name('app.landing');

    // Platform admin shell (super-admins only).
    Route::middleware('super-admin')
        ->get('/admin', fn () => Inertia::render('Admin/Landing'))
        ->name('admin.landing');

    // Mandatory MFA enrollment — the target EnsureTwoFactorEnabled routes un-enrolled
    // users to (and which it exempts so they are not locked out).
    Route::get('/two-factor/enrollment', fn () => Inertia::render('Auth/TwoFactorEnroll'))
        ->name('two-factor.enrollment');
});

Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('/login', fn () => response('Patient portal login pending UI'))->name('login');
    Route::post('/accept-invite', [PortalAuthController::class, 'acceptInvite'])->name('accept-invite');
    Route::post('/login', [PortalAuthController::class, 'login'])->name('login.attempt');

    Route::get('/', fn () => response('Patient portal pending UI'))
        ->middleware(['portal-tenant', 'portal-auth', 'portal-consent'])
        ->name('home');
});

Route::post('/portal/invitations', PortalInvitationController::class)
    ->middleware('auth')
    ->name('portal.invitations.store');
