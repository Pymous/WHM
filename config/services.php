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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'eveonline' => [
        'client_id' => env('EVE_CLIENT_ID'),
        'client_secret' => env('EVE_CLIENT_SECRET'),
        'redirect' => rtrim(env('APP_URL'), '/').'/auth/eve/callback',
        'verify_ssl' => filter_var(env('CURL_SSL_VERIFY', true), FILTER_VALIDATE_BOOL),
        // EVE_SCOPES is a comma-separated list, but we convert it to a space-separated list for ESI
        'scopes' => str_replace(',', ' ', env('EVE_SCOPES', 'publicData esi-characters.read_contacts.v1')),
    ],

    'discord' => [
        'client_id' => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
        'redirect' => env('APP_URL').'/discord/auth/callback',
        'avatar_default_extension' => env('DISCORD_EXTENSION_DEFAULT', 'png'), // only pick from jpg, png, webp
        'bot_token' => env('DISCORD_BOT_TOKEN'),
        'server_id' => env('DISCORD_SERVER_ID'),
        'broadcast_channel' => env('DISCORD_BROADCAST_CHANNEL'),
    ],

];
