<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Send booking reminders daily at 9:00 AM
        $schedule->command('bookings:send-reminders')->dailyAt('09:00');

        // Prune raw page-view rows older than 365 days nightly (keeps conversions).
        $schedule->command('analytics:prune')->dailyAt('03:30');

        // Send reminders for incomplete waivers whose visit date is within the
        // reminder window (default 24h). Hourly so per-company windows are honored.
        $schedule->command('waivers:send-reminders')->hourly();

        // Reset membership usage counters on term roll-over and enforce grace-period expiry.
        $schedule->command('memberships:reset-usage')->dailyAt('00:30');

        // Email the End of Day Sales Report at the close of each business day (Michigan time).
        $schedule->command('reports:send-daily-sales')->dailyAt('23:55');
    })
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
