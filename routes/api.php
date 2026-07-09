<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Nursing\Http\Controllers\NurseAuthController;
use Modules\Nursing\Http\Controllers\NurseDayPackController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Token-authenticated (Sanctum) endpoints for the Nurse PWA live here.
| IdentifyTenantFromUser + EnsureTwoFactorEnabled run group-wide (registered
| in bootstrap/app.php), so token requests still establish tenant context and
| enforce staff MFA.
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('nurse')->group(function () {
    Route::post('/login', [NurseAuthController::class, 'login'])->name('api.nurse.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [NurseAuthController::class, 'logout'])->name('api.nurse.logout');
        Route::get('/day-pack', NurseDayPackController::class)->name('api.nurse.day-pack');
    });
});
