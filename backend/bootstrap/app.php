<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxies whose X-Forwarded-* headers are trusted for the client IP (rate limiting,
        // audit log). Default: private networks (Nginx on the host, Docker). Behind Cloudflare,
        // add its ranges. A direct client with a public IP can't spoof X-Forwarded-For.
        $middleware->trustProxies(at: array_map('trim', explode(',', env(
            'TRUSTED_PROXIES',
            '127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1,fc00::/7',
        ))));

        // Every route is rate limited (profile `default` in config/rate-limit.php);
        // stricter profiles are added per route (routes/api.php).
        $middleware->throttleApi('default');
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->web(append: 'throttle:default');

        $middleware->alias([
            'auth' => \App\Http\Middleware\AuthMiddleware::class,
            'company' => \App\Http\Middleware\ResolveCompany::class,
            'master' => \App\Http\Middleware\EnsureMaster::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'bot' => \App\Http\Middleware\BotMiddleware::class,
            'portal' => \App\Http\Middleware\PortalMiddleware::class,
        ]);
    })
    // Runs in the `scheduler` container (docker-compose: php artisan schedule:work).
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('integrations:sync')->everyThirtyMinutes()->withoutOverlapping(30);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => true,
        );
    })->create();
