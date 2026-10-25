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
    | The disk EDMS stores its files on
    |--------------------------------------------------------------------------
    |
    | Every file the application publishes lives on a disk, and every
    | download, preview and copy goes through the UploadStorage contract.
    | The "edms" disk is the local one; "edms-cloud" is an S3-compatible
    | bucket.
    |
    | Which of the two is in force is decided by "uploads.storage_driver"
    | (UPLOAD_STORAGE_DRIVER=local|s3|dropbox) together with the disk names
    | in config/uploads.php. The two are meant to point at the same place:
    | the upload pipeline and the rest of the application share one byte
    | store, so a file can never be uploaded to a bucket and downloaded from
    | the local disk.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and
    | you may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    | Dropbox, Google Drive and OneDrive are deliberately absent: those
    | backends are reached through the UploadStorage contract, not through a
    | Laravel disk, because they have no keys and no partial writes and
    | therefore cannot be mapped onto Flysystem without losing the guarantees
    | the upload pipeline relies on.
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

        'edms' => [
            'driver' => 'local',
            'root' => storage_path('app/private/edms'),
            'visibility' => 'private',
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

        /*
        | A bucket for the "s3" upload driver. UPLOAD_STORAGE_DRIVER=s3 and
        | UPLOAD_CLOUD_DISK=edms-cloud make the whole application store its
        | files in this bucket.
        |
        | "throw" is on: a failing provider must surface as an error the
        | pipeline can retry, not as a silent false.
        */
        'edms-cloud' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may specify the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
