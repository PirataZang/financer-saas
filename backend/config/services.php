<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Zip code (CEP) lookup provider, used by App\Service\ZipCodeService.
    'viacep' => [
        'url' => env('VIACEP_URL', 'https://viacep.com.br/ws'),
        'timeout' => (int) env('VIACEP_TIMEOUT', 4),
    ],

    // Telegram bot integration (BotMiddleware / BotActivationService). The bot token itself
    // lives only in the bot container.
    'telegram' => [
        // Shared secret the bot container sends as Bearer token on /api/bot/*.
        'bot_api_token' => env('BOT_API_TOKEN'),
        // Temporary single activation code; see BotActivationService.
        'activation_code' => env('TELEGRAM_ACTIVATION_CODE'),
    ],

];
