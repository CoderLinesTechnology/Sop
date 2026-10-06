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

        // Customer uploads and generated documents (development / single server).
        // Never served directly: files are streamed through authorised routes.
        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/customer-files'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        // Customer files in production: a private S3-compatible bucket (AWS S3,
        // Cloudflare R2, Backblaze B2, MinIO) with server-side encryption. Files
        // are additionally encrypted by the application before upload.
        'private_s3' => [
            'driver' => 's3',
            'key' => env('PRIVATE_S3_KEY', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('PRIVATE_S3_SECRET', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('PRIVATE_S3_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
            'bucket' => env('PRIVATE_S3_BUCKET'),
            'endpoint' => env('PRIVATE_S3_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('PRIVATE_S3_PATH_STYLE', false),
            'visibility' => 'private',
            'options' => ['ServerSideEncryption' => env('PRIVATE_S3_SSE', 'AES256')],
            'throw' => true,
            'report' => true,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
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
