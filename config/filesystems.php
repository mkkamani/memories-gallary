<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Media Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | This disk is used by the media upload flow. Default to the local public
    | disk unless an explicit media disk is configured or a Cloudflare R2
    | bucket is available.
    |
    */

    'media_disk' => env(
        'MEDIA_DISK',
        env('CLOUDFLARE_R2_BUCKET') ? 'r2' : 'public',
    ),

    /*
    |--------------------------------------------------------------------------
    | Media CDN URL
    |--------------------------------------------------------------------------
    |
    | When set, media stored on object storage disks such as Cloudflare R2
    | will be served through this CDN/Worker origin instead of presigned URLs
    | or local thumbnails. This is used for fast, cacheable listing and
    | preview URLs while keeping local-public storage behavior unchanged.
    |
    */

    'cdn_url' => env('CDN_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'r2' => [
            'driver' => 's3',
            'key' => env('CLOUDFLARE_R2_ACCESS_KEY_ID'),
            'secret' => env('CLOUDFLARE_R2_SECRET_ACCESS_KEY'),
            'region' => env('CLOUDFLARE_R2_REGION', 'auto'),
            'bucket' => env('CLOUDFLARE_R2_BUCKET'),
            'url' => env('CLOUDFLARE_R2_URL'),
            'endpoint' => env('CLOUDFLARE_R2_ENDPOINT'),
            'use_path_style_endpoint' => env('CLOUDFLARE_R2_USE_PATH_STYLE_ENDPOINT', true),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
