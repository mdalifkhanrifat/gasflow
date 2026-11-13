<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Shared\Lifecycle\LifecycleContext;
use App\Shared\Lifecycle\LifecycleViolation;
use App\Shared\RequestContext\RequestContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Device, IP city and GPS for the activity trail (before LifecycleContext).
            RequestContext::class,
            // Last, so the user and every context value are known before BEGIN.
            LifecycleContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Changes refused by the database lifecycle rules become 422 validation errors.
        $exceptions->map(
            fn (QueryException $exception) => LifecycleViolation::fromQueryException($exception) ?? $exception,
        );

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
