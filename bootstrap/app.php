<?php

use App\Exceptions\Lifecycle\LifecycleViolation;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureLicensed;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LifecycleContext;
use App\Http\Middleware\RequestContext;
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
        // Correlation id first, so every response, log line and trail row carries it.
        $middleware->prepend(AssignRequestId::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Read-only lock without a valid license; refused writes never open a transaction.
            EnsureLicensed::class,
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
