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

    'authentik' => [
        'base_url' => env('AUTHENTIK_BASE_URL'),
        'client_id' => env('AUTHENTIK_CLIENT_ID'),
        'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
        'redirect' => env('AUTHENTIK_REDIRECT_URI'),
        // Slug de la aplicación en Authentik, usado para cerrar la sesión allá (end-session)
        'app_slug' => env('AUTHENTIK_APP_SLUG'),
    ],

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

    'google_vision' => [
        'key' => env('GOOGLE_VISION_API_KEY'),
    ],

    // Motor de lectura de fórmulas: gemini (el del sistema nativo) o vision.
    'transcripcion' => [
        'motor' => env('TRANSCRIPCION_MOTOR', 'vision'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'modelo' => env('GEMINI_MODELO', 'gemini-2.5-flash'),
    ],

    // API de inventario (proyecto inventario-api). Sin URL se usa el catálogo mock.
    'inventario' => [
        'url' => env('INVENTARIO_API_URL'),
        'token' => env('INVENTARIO_API_TOKEN'),
    ],

];
