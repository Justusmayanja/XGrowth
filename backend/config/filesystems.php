<?php

$mediaDriver = env('MEDIA_DRIVER', env('TIKTOK_VIDEO_DRIVER', 'local'));

$mediaDisk = $mediaDriver === 's3' ? [
    'driver' => 's3',
    'key' => env('WASABI_KEY'),
    'secret' => env('WASABI_SECRET'),
    'region' => env('WASABI_REGION', 'eu-central-1'),
    'bucket' => env('WASABI_BUCKET'),
    'endpoint' => env('WASABI_ENDPOINT', 'https://s3.eu-central-1.wasabisys.com'),
    'use_path_style_endpoint' => true,
    'visibility' => 'private',
    'throw' => true,
    'report' => false,
    'http' => [
        'connect_timeout' => 10,
        'timeout' => 120,
    ],
] : [
    'driver' => 'local',
    'root' => env('MEDIA_LOCAL_ROOT', storage_path('app/private/media')),
    'visibility' => 'private',
    'throw' => true,
    'report' => false,
];

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

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'media' => $mediaDisk,

        // TikTok objects live under the tiktok/ prefix of the shared media disk.
        'tiktok_videos' => [
            'driver' => 'strict_scoped',
            'disk' => 'media',
            'prefix' => 'tiktok',
            'visibility' => 'private',
            'throw' => true,
        ],

        'youtube_media' => [
            'driver' => 'strict_scoped',
            'disk' => 'media',
            'prefix' => 'youtube',
            'visibility' => 'private',
            'throw' => true,
        ],

        // Legacy local root, used only by tiktok:migrate-videos.
        'tiktok_videos_local' => [
            'driver' => 'local',
            'root' => env('TIKTOK_VIDEO_ROOT', storage_path('app/private/tiktok')),
            'visibility' => 'private',
            'throw' => true,
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
