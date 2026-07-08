<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Token-authenticated (Sanctum) endpoints for the future Nurse PWA live here.
| No endpoints yet beyond the identity probe; the API surface is built in
| later gates. IdentifyTenantFromUser + EnsureTwoFactorEnabled run group-wide
| (registered in bootstrap/app.php).
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
