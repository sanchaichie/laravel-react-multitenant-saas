<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use Illuminate\Support\Facades\Route;

// Separate counters, so failed logins don't use up registrations. 'login' is per
// address (AppServiceProvider), the register prefix per IP.
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1,register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // The user's own organizations, whatever the current one.
    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store'])->middleware('throttle:20,1,organizations');
    // Up to 18 digits: always fits bigint and the int parameter (longer is a 404, not a 500).
    Route::post('/organizations/{organization}/switch', [OrganizationController::class, 'switch'])->where('organization', '[0-9]{1,18}');

    // Everything in here acts on the current organization (members, tasks, ... from plan 06).
    Route::middleware('organization')->group(function () {
        //
    });
});
