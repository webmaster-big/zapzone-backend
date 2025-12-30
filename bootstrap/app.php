<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->use([
            \App\Http\Middleware\Cors::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        // For API routes, redirect to null (return JSON 401 instead of redirecting to login)
        $middleware->redirectGuestsTo(fn (Request $request) =>
            $request->expectsJson() || $request->is('api/*') ? null : route('login')
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle unauthenticated API requests with JSON response
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'error' => 'You must be logged in to access this resource.'
                ], 401);
            }
        });
    })->create();
