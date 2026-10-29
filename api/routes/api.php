<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\JoinController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\OrganizationController;
use Illuminate\Support\Facades\Route;

// Separate counters, so failed logins don't use up registrations. 'login' is per
// address (AppServiceProvider), the register prefix per IP.
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1,register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Ids up to 18 digits: always fit bigint and the int parameter (longer is a 404, not a 500).
// Throttle prefixes give each group its own per-user counter.
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // The link from the verification email (the app posts it here), and a new one.
    Route::post('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->where(['id' => '[0-9]{1,18}', 'hash' => '[0-9a-f]{40}'])
        ->middleware(['signed:relative', 'throttle:verify-email'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:verify-email');

    // The user's own organizations, whatever the current one.
    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store'])->middleware('throttle:20,1,organizations');
    Route::post('/organizations/{organization}/switch', [OrganizationController::class, 'switch'])->where('organization', '[0-9]{1,18}');

    // Accepting an invitation (token from the email link), from any current organization.
    // Not 'verified': accepting verifies the email (the link reached that inbox).
    Route::get('/join/{token}', [JoinController::class, 'show'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:20,1,join');
    Route::post('/join/{token}', [JoinController::class, 'store'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:20,1,join');

    // Everything in here acts on the current organization. Features that reach other
    // people or cost money also need a verified email ('verified').
    Route::middleware('organization')->group(function () {
        Route::get('/members', [MemberController::class, 'index']);
        Route::patch('/members/{member}', [MemberController::class, 'update'])->where('member', '[0-9]{1,18}')->middleware('throttle:60,1,members');
        Route::delete('/members/{member}', [MemberController::class, 'destroy'])->where('member', '[0-9]{1,18}')->middleware('throttle:60,1,members');

        Route::get('/invitations', [InvitationController::class, 'index']);
        Route::post('/invitations', [InvitationController::class, 'store'])->middleware(['verified', 'throttle:invitations']);
        Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])->where('invitation', '[0-9]{1,18}');
    });
});
