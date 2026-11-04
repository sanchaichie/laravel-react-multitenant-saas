<?php

use App\Http\Middleware\EnsureCurrentOrganization;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\NotImpersonating;
use App\Http\Middleware\ReadOnlyImpersonation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // No web login page: API guests get a JSON 401 instead of a redirect to route('login').
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        $middleware->alias([
            'organization' => EnsureCurrentOrganization::class,
            // JSON 403 with code email_unverified, in place of Laravel's redirect.
            'verified' => EnsureEmailIsVerified::class,
            // Platform admin routes: 404 for everyone else (EnsurePlatformAdmin).
            'platform-admin' => EnsurePlatformAdmin::class,
            // A platform admin's "view as user" session: read-only, and some things hidden.
            'impersonation' => ReadOnlyImpersonation::class,
            'not-impersonating' => NotImpersonating::class,
        ]);

        // Before route model binding: {task} binds through the organization scope, so
        // without this a request with no organization (or from a tab that's behind)
        // would get a 404 instead of the 403 / 409 that tells the app what happened.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureCurrentOrganization::class);
        // Non-admins get 404 before {user} / {organization} are looked up, so ids can't be probed.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsurePlatformAdmin::class);
        // A "view as user" session's writes are refused before anything is looked up, and
        // before the user's rate limits, so the admin's clicks don't use them up.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: ReadOnlyImpersonation::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A wrong method on an admin route answers 405 and names the methods, before any
        // middleware runs: answer the unknown-route 404 instead, so the area isn't revealed.
        $exceptions->map(MethodNotAllowedHttpException::class, fn (MethodNotAllowedHttpException $exception) => request()->is('api/admin', 'api/admin/*')
            ? EnsurePlatformAdmin::notFound(request())
            : $exception);

        // A missing (or not yours) record: the same plain 404 whatever the model or id,
        // instead of "No query results for model [App\Models\...] 42".
        $exceptions->render(fn (NotFoundHttpException $exception, Request $request) => $request->is('api/*') && $exception->getPrevious() instanceof ModelNotFoundException
            ? response()->json(['message' => 'Not found.'], 404)
            : null);
    })->create();
