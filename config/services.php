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

    'ffmpeg' => [
        'binary' => env('FFMPEG_BINARY', 'ffmpeg'),
        'thumbnail_seek' => env('FFMPEG_THUMBNAIL_SEEK', '00:00:01.000'),
        'timeout' => (int) env('FFMPEG_TIMEOUT', 30),
    ],

    'heic_converter' => [
        'url' => env('HEIC_CONVERTER_URL', ''),
        'api_key' => env('HEIC_CONVERTER_API_KEY', ''),
        'timeout' => (int) env('HEIC_CONVERTER_TIMEOUT', 120),
        'connect_timeout' => (int) env('HEIC_CONVERTER_CONNECT_TIMEOUT', 20),
        'warmup_timeout' => (int) env('HEIC_CONVERTER_WARMUP_TIMEOUT', 25),
        'retries' => (int) env('HEIC_CONVERTER_RETRIES', 2),
        'retry_sleep_ms' => (int) env('HEIC_CONVERTER_RETRY_SLEEP_MS', 2000),
        'include_avif' => (bool) env('HEIC_CONVERTER_INCLUDE_AVIF', false),
        'mode' => env('HEIC_CONVERTER_MODE', 'fast'),
        'preview_max_dimension' => (int) env('HEIC_CONVERTER_PREVIEW_MAX_DIMENSION', 1800),
        'use_async_jobs' => (bool) env('HEIC_CONVERTER_USE_ASYNC_JOBS', true),
        'poll_interval_ms' => (int) env('HEIC_CONVERTER_POLL_INTERVAL_MS', 2000),
    ],

];
