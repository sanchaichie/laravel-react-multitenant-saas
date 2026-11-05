<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Api\Admin\ImpersonationController as AdminImpersonationController;
use App\Http\Controllers\Api\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Api\Admin\OrganizationOwnerController as AdminOrganizationOwnerController;
use App\Http\Controllers\Api\Admin\UserActionController as AdminUserActionController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AiUsageController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\JoinController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TaskChatController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskSummaryController;
use App\Http\Controllers\Api\TaskWritingHelpController;
use App\Http\Controllers\Api\TwoFactorChallengeController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\WebhookDeliveryController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\VerifyStripeWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Separate counters, so failed logins don't use up registrations. 'login' is per
// address (AppServiceProvider), the register prefix per IP.
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1,register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Always the same answer (no account discovery); per IP, apart from logins. The
// broker also allows one link per address a minute.
Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:6,1,password-reset');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:6,1,password-reset');

// The second step of a login or a reset when two-factor is on (the challenge they
// answered). Its own per-IP counter; CheckTwoFactorCode also limits each account.
Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:6,1,two-factor');

// The "this wasn't me" link from the "two-factor was turned on" email: no login.
Route::post('/account/two-factor/revert/{id}/{at}', [TwoFactorController::class, 'revert'])
    ->where(['id' => '[0-9]{1,18}', 'at' => '[0-9]{1,12}'])
    ->middleware(['signed:relative', 'throttle:6,1,two-factor-revert'])->name('account.two-factor.revert');

// The undo link from the "email was changed" notice: no login, the owner may be locked out.
Route::post('/account/email/revert/{id}/{old}/{new}', [AccountController::class, 'revertEmail'])
    ->where(['id' => '[0-9]{1,18}', 'old' => '[0-9a-f]{40}', 'new' => '[0-9a-f]{40}'])
    ->middleware(['signed:relative', 'throttle:6,1,email-revert'])->name('account.email.revert');

// From Stripe, not the app: no user, authenticated by the payload's signature.
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])->middleware(['throttle:120,1,stripe-webhook', VerifyStripeWebhook::class]);

// A plain OPTIONS on an admin path would list its methods, showing which admin routes
// exist: the same 404 as an unknown route. (A CORS preflight never gets here: HandleCors
// answers it first, for every path, so the app on another origin still works.)
Route::options('/admin/{path?}', fn (Request $request) => throw EnsurePlatformAdmin::notFound($request))->where('path', '.*');

// Ids up to 18 digits: always fit bigint and the int parameter (longer is a 404, not a 500).
// Throttle prefixes give each group its own per-user counter.
// 'impersonation': a platform admin's "view as user" session can only read (every route
// here, by default). Named routes are its exceptions (ReadOnlyImpersonation::ALLOWED_ROUTES).
Route::middleware(['auth:sanctum', 'impersonation'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/me', [AuthController::class, 'me']);

    // The link from the verification email (the app posts it here), and a new one.
    Route::post('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->where(['id' => '[0-9]{1,18}', 'hash' => '[0-9a-f]{40}'])
        ->middleware(['signed:relative', 'throttle:verify-email'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:verify-email');

    // The user's own account, whatever the current organization.
    Route::patch('/account', [AccountController::class, 'update'])->middleware('throttle:20,1,account');
    Route::put('/account/email', [AccountController::class, 'updateEmail'])->middleware(['throttle:current-password', 'throttle:account-email']);
    Route::post('/account/email/confirm/{id}/{hash}', [AccountController::class, 'confirmEmail'])
        ->where(['id' => '[0-9]{1,18}', 'hash' => '[0-9a-f]{40}'])
        ->middleware(['signed:relative', 'throttle:verify-email'])->name('account.email.confirm');
    Route::delete('/account/email/pending', [AccountController::class, 'cancelEmailChange'])->middleware('throttle:20,1,account');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->middleware('throttle:current-password');
    Route::delete('/account', [AccountController::class, 'destroy'])->middleware('throttle:current-password');
    Route::post('/account/two-factor', [TwoFactorController::class, 'store'])->middleware('throttle:current-password');
    Route::post('/account/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:6,1,two-factor-setup');
    // A fresh code on this device's token: platform admin routes need a recent one.
    Route::post('/account/two-factor/reconfirm', [TwoFactorController::class, 'reconfirm'])->middleware('throttle:6,1,two-factor-reconfirm');
    Route::post('/account/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->middleware('throttle:current-password');
    Route::delete('/account/two-factor', [TwoFactorController::class, 'destroy'])->middleware('throttle:current-password');
    // The user's own devices and their IP addresses: not in a support view.
    Route::get('/account/devices', [DeviceController::class, 'index'])->middleware('not-impersonating');
    Route::delete('/account/devices/{device}', [DeviceController::class, 'destroy'])->where('device', '[0-9]{1,18}')->middleware('throttle:60,1,devices');

    // The user's own organizations, whatever the current one.
    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store'])->middleware('throttle:20,1,organizations');
    Route::post('/organizations/{organization}/switch', [OrganizationController::class, 'switch'])->where('organization', '[0-9]{1,18}')->name('organizations.switch');

    // Accepting an invitation (token from the email link), from any current organization.
    // Not 'verified': accepting verifies the email (the link reached that inbox).
    Route::get('/join/{token}', [JoinController::class, 'show'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:20,1,join');
    Route::post('/join/{token}', [JoinController::class, 'store'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:20,1,join');

    // Platform admins (not an organization role): lookups across organizations, and actions. Outside
    // the organization group: they never act in a current organization. Non-admins get
    // the unknown-route 404 (the 'admin' limiter doesn't count them, 'verified' runs
    // after), so nothing tells them the area exists; admins need two-factor and a recent code.
    Route::prefix('admin')->middleware(['throttle:admin', 'platform-admin', 'verified'])->group(function () {
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->where('user', '[0-9]{1,18}');
        Route::get('/organizations', [AdminOrganizationController::class, 'index']);
        Route::get('/organizations/{organization}', [AdminOrganizationController::class, 'show'])->where('organization', '[0-9]{1,18}');
        Route::get('/audit-log', [AdminAuditLogController::class, 'index']);

        // Actions take a reason (for the platform log) and need a fresher code than lookups.
        Route::middleware('platform-admin:action')->where(['user' => '[0-9]{1,18}', 'organization' => '[0-9]{1,18}'])->group(function () {
            Route::post('/users/{user}/two-factor/reset', [AdminUserActionController::class, 'resetTwoFactor']);
            Route::post('/users/{user}/sign-out', [AdminUserActionController::class, 'signOut']);
            Route::post('/users/{user}/suspend', [AdminUserActionController::class, 'suspend']);
            Route::post('/users/{user}/unsuspend', [AdminUserActionController::class, 'unsuspend']);
            Route::post('/organizations/{organization}/owners', [AdminOrganizationOwnerController::class, 'store']);
            Route::post('/users/{user}/impersonate', [AdminImpersonationController::class, 'store']);
        });
    });

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

        // AI writing help for the task form, streamed (server-sent events). An AI call:
        // it costs money ('verified') and is limited per organization ('ai').
        Route::middleware(['verified', 'throttle:ai'])->group(function () {
            Route::post('/tasks/writing-help', [TaskWritingHelpController::class, 'store']);
            Route::post('/tasks/{task}/writing-help', [TaskWritingHelpController::class, 'update'])->where('task', '[0-9]{1,18}');
        });

        // Your own private AI chat about a task: sending streams (server-sent events), costs
        // money ('verified') and is limited per organization ('ai').
        Route::where(['task' => '[0-9]{1,18}'])->group(function () {
            // Task content: not in a support view.
            Route::get('/tasks/{task}/chat', [TaskChatController::class, 'index'])->middleware(['not-impersonating', 'throttle:120,1,task-chat-read']);
            Route::post('/tasks/{task}/chat', [TaskChatController::class, 'store'])->middleware(['verified', 'throttle:ai']);
            Route::delete('/tasks/{task}/chat', [TaskChatController::class, 'destroy'])->middleware('throttle:60,1,task-chat');
        });

        // AI summaries. {summary} must belong to {task} (scoped bindings); asking for a
        // new one is an AI call: it costs money ('verified') and is limited per organization ('ai').
        Route::scopeBindings()->where(['task' => '[0-9]{1,18}', 'summary' => '[0-9]{1,18}'])->group(function () {
            Route::get('/tasks/{task}/summary', [TaskSummaryController::class, 'show'])->middleware('not-impersonating');
            Route::post('/tasks/{task}/summary', [TaskSummaryController::class, 'store'])->middleware(['verified', 'throttle:ai']);
            Route::middleware('throttle:120,1,task-summaries')->group(function () {
                Route::post('/tasks/{task}/summaries/{summary}/approve', [TaskSummaryController::class, 'approve']);
                Route::post('/tasks/{task}/summaries/{summary}/reject', [TaskSummaryController::class, 'reject']);
            });
        });

        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/audit-log/actions', [AuditLogController::class, 'actions']);

        // AI usage of one month, and the monthly AI budget (raising it spends money: 'verified').
        Route::get('/ai-usage', [AiUsageController::class, 'index']);
        Route::put('/ai-budget', [AiUsageController::class, 'updateBudget'])->middleware(['verified', 'throttle:20,1,ai-budget']);

        // Billing (billing.manage). The overview reads our own tables; the rest call Stripe,
        // limited per user and per organization ('stripe'). Paying spends money: 'verified'.
        Route::get('/billing', [BillingController::class, 'show']);
        Route::middleware('throttle:stripe')->group(function () {
            Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware('verified');
            Route::post('/billing/credits/checkout', [BillingController::class, 'creditsCheckout'])->middleware('verified');
            Route::post('/billing/portal', [BillingController::class, 'portal'])->middleware('verified');
            // Live from Stripe, with billing details: not in a support view.
            Route::get('/billing/invoices', [BillingController::class, 'invoices'])->middleware('not-impersonating');
        });

        // Outgoing webhooks (webhooks.manage). They send task content to a URL outside the
        // app: creating, editing and sending need a verified email. {webhook} binds through
        // the organization scope, {delivery} must belong to it.
        Route::scopeBindings()->where(['webhook' => '[0-9]{1,18}', 'delivery' => '[0-9]{1,18}'])->middleware('throttle:60,1,webhooks')->group(function () {
            Route::get('/webhooks', [WebhookController::class, 'index']);
            Route::get('/webhooks/{webhook}', [WebhookController::class, 'show']);
            Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy']);
            Route::middleware('verified')->group(function () {
                Route::post('/webhooks', [WebhookController::class, 'store']);
                Route::patch('/webhooks/{webhook}', [WebhookController::class, 'update']);
                Route::post('/webhooks/{webhook}/secret', [WebhookController::class, 'rollSecret']);
                Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test'])->middleware('throttle:10,1,webhook-send');
                Route::post('/webhooks/{webhook}/deliveries/{delivery}/resend', [WebhookDeliveryController::class, 'resend'])->middleware('throttle:10,1,webhook-send');
            });
            // Payloads hold task content: not in a support view.
            Route::get('/webhooks/{webhook}/deliveries', [WebhookDeliveryController::class, 'index'])->middleware('not-impersonating');
        });
    });
});
