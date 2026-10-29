<?php

use App\Http\Middleware\EnsureCurrentOrganization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
        ]);
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
