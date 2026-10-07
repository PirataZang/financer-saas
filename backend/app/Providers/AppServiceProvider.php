<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // One limiter per profile in config/rate-limit.php → `throttle:{profile}`. Keyed by client
        // IP, or by a header the profile names (only on routes whose middleware validated it).
        foreach (config('rate-limit') as $profile => $limit) {
            RateLimiter::for($profile, fn (Request $request) => Limit::perSecond($limit['max_attempts'], $limit['decay_seconds'])
                ->by(isset($limit['by_header']) ? (string) $request->header($limit['by_header']) : $request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'code' => 'TOO_MANY_REQUESTS',
                    'message' => 'Muitas requisições. Aguarde '.$headers['Retry-After'].' segundos e tente novamente.',
                ], 429, $headers)));
        }
    }
}
