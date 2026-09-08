<?php

use App\Exceptions\WGTSpainException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API is JSON-only. Without this, a request that forgets
        // `Accept: application/json` gets an HTML error page or a redirect.
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );

        // Single place where a business-rule failure becomes an HTTP response.
        $exceptions->render(fn (WGTSpainException $e) => response()->json(
            array_filter([
                'message' => $e->getMessage(),
                'context' => $e->context,
            ]),
            $e->getCode()
        ));
    })->create();
