<?php

use App\Http\Middleware\EnsureCurrentOrganization;
use App\Http\Middleware\EnsureEmailIsVerified;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
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
        ]);

        // Before route model binding: {task} binds through the organization scope, so
        // without this a request with no organization (or from a tab that's behind)
        // would get a 404 instead of the 403 / 409 that tells the app what happened.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureCurrentOrganization::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A missing (or not yours) record: the same plain 404 whatever the model or id,
        // instead of "No query results for model [App\Models\...] 42".
        $exceptions->render(fn (NotFoundHttpException $exception, Request $request) => $request->is('api/*') && $exception->getPrevious() instanceof ModelNotFoundException
            ? response()->json(['message' => 'Not found.'], 404)
            : null);
    })->create();
