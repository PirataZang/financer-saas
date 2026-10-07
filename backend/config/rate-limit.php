<?php

/*
| Named request limits, keyed by the client IP. Each profile becomes a Laravel rate limiter
| (registered in AppServiceProvider) and is applied with the native middleware:
|     Route::middleware('throttle:public')
| `default` covers every API route (bootstrap/app.php). A request beyond max_attempts within
| decay_seconds gets HTTP 429 with Retry-After / X-RateLimit-* headers.
|
| The client IP is resolved through TrustProxies (TRUSTED_PROXIES in .env) so users behind
| Nginx/Docker don't share one bucket and X-Forwarded-For can't be spoofed by direct clients.
*/

return [
    // Authenticated SPA traffic: a single screen already makes several calls
    // (session check, listing, pagination), so this is per-IP headroom, not 5/30.
    'default' => [
        'max_attempts' => (int) env('RATE_LIMIT_DEFAULT_MAX', 120),
        'decay_seconds' => (int) env('RATE_LIMIT_DEFAULT_DECAY', 60),
    ],

    // Unauthenticated endpoints (e.g. POST /api/public/customers).
    'public' => [
        'max_attempts' => (int) env('RATE_LIMIT_PUBLIC_MAX', 5),
        'decay_seconds' => (int) env('RATE_LIMIT_PUBLIC_DECAY', 30),
    ],

    // Login: slows down password guessing.
    'authentication' => [
        'max_attempts' => (int) env('RATE_LIMIT_AUTH_MAX', 5),
        'decay_seconds' => (int) env('RATE_LIMIT_AUTH_DECAY', 30),
    ],

    // Telegram bot (routes/api.php `bot`). Every bot request comes from the same container IP,
    // so these are keyed per Telegram user (`by_header`, set by the bot and checked by BotMiddleware).
    'bot' => [
        'max_attempts' => (int) env('RATE_LIMIT_BOT_MAX', 60),
        'decay_seconds' => (int) env('RATE_LIMIT_BOT_DECAY', 60),
        'by_header' => 'X-Telegram-User-Id',
    ],

    // Activation-code guesses.
    'bot-activation' => [
        'max_attempts' => (int) env('RATE_LIMIT_BOT_ACTIVATION_MAX', 5),
        'decay_seconds' => (int) env('RATE_LIMIT_BOT_ACTIVATION_DECAY', 600),
        'by_header' => 'X-Telegram-User-Id',
    ],

    // Caps how many transactions one Telegram user can create (runaway loops, stolen phone).
    'bot-transactions' => [
        'max_attempts' => (int) env('RATE_LIMIT_BOT_TRANSACTIONS_MAX', 30),
        'decay_seconds' => (int) env('RATE_LIMIT_BOT_TRANSACTIONS_DECAY', 3600),
        'by_header' => 'X-Telegram-User-Id',
    ],
];
