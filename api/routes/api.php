<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\JoinController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskSummaryController;
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

        // {role} binds through the organization scope, like {task}.
        Route::get('/permissions', [PermissionController::class, 'index']);
        Route::get('/roles', [RoleController::class, 'index']);
        Route::middleware('throttle:60,1,roles')->group(function () {
            Route::post('/roles', [RoleController::class, 'store']);
            Route::patch('/roles/{role}', [RoleController::class, 'update'])->where('role', '[0-9]{1,18}');
            Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->where('role', '[0-9]{1,18}');
        });

        // {task} binds through the organization scope: another organization's task is a 404.
        Route::get('/tasks', [TaskController::class, 'index']);
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->where('task', '[0-9]{1,18}');
        Route::middleware('throttle:120,1,tasks')->group(function () {
            Route::post('/tasks', [TaskController::class, 'store']);
            Route::patch('/tasks/{task}', [TaskController::class, 'update'])->where('task', '[0-9]{1,18}');
            Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->where('task', '[0-9]{1,18}');
        });

        // AI summaries. {summary} must belong to {task} (scoped bindings); asking for a
        // new one is an AI call: it costs money ('verified') and is limited per organization ('ai').
        Route::scopeBindings()->where(['task' => '[0-9]{1,18}', 'summary' => '[0-9]{1,18}'])->group(function () {
            Route::get('/tasks/{task}/summary', [TaskSummaryController::class, 'show']);
            Route::post('/tasks/{task}/summary', [TaskSummaryController::class, 'store'])->middleware(['verified', 'throttle:ai']);
            Route::middleware('throttle:120,1,task-summaries')->group(function () {
                Route::post('/tasks/{task}/summaries/{summary}/approve', [TaskSummaryController::class, 'approve']);
                Route::post('/tasks/{task}/summaries/{summary}/reject', [TaskSummaryController::class, 'reject']);
            });
        });

        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/audit-log/actions', [AuditLogController::class, 'actions']);
    });
});
