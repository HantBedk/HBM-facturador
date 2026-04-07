<?php

use App\Http\Middleware\EnsureUserRole;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => EnsureUserRole::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('billing:generate-draft-invoices')->dailyAt('05:00');
        $schedule->command('billing:process-cutoff')->dailyAt('06:00');
        $schedule->command('billing:daily-reminders')->dailyAt('07:00');
        $schedule->command('db:backup')
            ->dailyAt('02:00')
            ->when(fn () => (bool) config('database.backup.enabled'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
