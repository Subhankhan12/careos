<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Placeholder MFA enrollment target. The real enrollment UI arrives in gate A.8;
// EnsureTwoFactorEnabled redirects un-enrolled users here (this route is exempt).
Route::get('/two-factor/enrollment', function () {
    return response(__('platform::auth.two_factor_required'), 200);
})->middleware('auth')->name('two-factor.enrollment');
