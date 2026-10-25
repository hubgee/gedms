<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Resumable Uploads
    |--------------------------------------------------------------------------
    |
    | Chunked, restart-safe uploads for files up to 10 GB. A single logical
    | upload is split into chunks and tracked server-side, so a dropped
    | connection, a page reload or a server restart only costs the chunks that
    | were actually in flight when it happened.
    |
    | Request limits therefore only need to cover ONE chunk, never the whole
    | file. Required server configuration:
    |
    |   PHP   : upload_max_filesize = 20M, post_max_size = 25M,
    |           max_execution_time = 60
    |   Nginx : client_max_body_size = 25m
    |
    | The final assembly runs on the dedicated "uploads" queue connection
    | (config/queue.php) whose retry_after is deliberately long, so a worker is
    | never interrupted mid-concatenation. Run it with:
    |
    |   php artisan queue:work uploads --queue=uploads --timeout=3600
    |
    */

    // Hard ceiling for a single logical file, in bytes (default: 10 GB).
    'max_bytes' => (int) env('UPLOAD_MAX_BYTES', 10737418240),

    // Size of one chunk in bytes (default: 8 MB). Small enough that resending
    // a lost chunk after an outage is cheap, large enough for throughput.
    'chunk_bytes' => (int) env('UPLOAD_CHUNK_BYTES', 8388608),

    // Number of chunks the client may upload in parallel.
    'concurrency' => (int) env('UPLOAD_CONCURRENCY', 3),

    // Hours an inactive session stays resumable before it expires.
    'ttl_hours' => (int) env('UPLOAD_TTL_HOURS', 48),

    // Queue connection and queue name used to assemble completed uploads.
    'queue_connection' => env('UPLOAD_QUEUE_CONNECTION', 'uploads'),
    'queue' => env('UPLOAD_QUEUE', 'uploads'),

    // Hours a completed session's temporary directory is retained before the
    // prune command may remove it.
    'prune_retention_hours' => (int) env('UPLOAD_PRUNE_RETENTION_HOURS', 6),

    /*
    |--------------------------------------------------------------------------
    | Recovery
    |--------------------------------------------------------------------------
    |
    | A session can be left in "assembling" forever if its worker dies mid-run
    | (deploy, OOM kill, power cut). The recovery command looks for sessions that
    | have been assembling for longer than "stale_assembling_minutes" and either
    | requeues the assembly or fails the session, so nothing is ever stranded.
    |
    | "stale_assembling_attempts" bounds how many times recovery will requeue
    | before giving up, so a file that genuinely cannot be assembled (for example
    | its chunks are gone) fails loudly instead of looping forever.
    |
    */

    // Minutes a session may sit in "assembling" before recovery steps in. Keep
    // this longer than the worker's timeout so a slow-but-live assembly is not
    // requeued out from under the worker that is still running it.
    'stale_assembling_minutes' => (int) env('UPLOAD_STALE_ASSEMBLING_MINUTES', 70),

    // How many recovery requeues are allowed before the session is failed.
    'stale_assembling_attempts' => (int) env('UPLOAD_STALE_ASSEMBLING_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Byte store
    |--------------------------------------------------------------------------
    |
    | Where the bytes live while they are being uploaded. The pipeline talks
    | to the UploadStorage contract, so the driver can be swapped without
    | touching the state machine.
    |
    |   local   : the "edms" disk on this machine (the default)
    |   s3      : any S3-compatible bucket (AWS, MinIO, R2, Spaces, Wasabi)
    |   dropbox : a Dropbox folder, through the Dropbox API
    |   google  : Google Drive, through the Drive API
    |   onedrive: Microsoft OneDrive (personal or work/school)
    |
    | A space may override this driver with its own encrypted configuration
    | (see the Storage Spaces admin screen). A space with no configuration
    | inherits the driver below.
    |
    | The driver decides where *everything* ends up, not just the transient
    | chunks: published files, downloads, previews and copies all go through
    | the same contract. So UPLOAD_STORAGE_DRIVER=s3 with
    | UPLOAD_CLOUD_DISK=edms-cloud moves the whole application's storage into
    | the bucket.
    |
    */

    'storage_driver' => env('UPLOAD_STORAGE_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Storage drivers
    |--------------------------------------------------------------------------
    |
    | Every provider the admin UI can offer. A driver supplies its own settings
    | schema and, when it supports interactive OAuth, an authorisation flow.
    | Add a class here and it appears in the UI with no other changes.
    |
    */

    'drivers' => [
        'local' => \App\Services\Uploads\Drivers\LocalDriver::class,
        's3' => \App\Services\Uploads\Drivers\S3Driver::class,
        'dropbox' => \App\Services\Uploads\Drivers\DropboxDriver::class,
        'google' => \App\Services\Uploads\Drivers\GoogleDriveDriver::class,
        'onedrive' => \App\Services\Uploads\Drivers\OneDriveDriver::class,
    ],

    // The disk used by the "local" driver.
    'local_disk' => env('UPLOAD_LOCAL_DISK', 'edms'),

    // The disk used by the "s3" driver (see config/filesystems.php).
    'cloud_disk' => env('UPLOAD_CLOUD_DISK', 'edms-cloud'),

    // Optional application-level quota, in bytes. Used by the usage panel when
    // the provider does not report its own allocation. Null means "unknown".
    'quota_bytes' => env('UPLOAD_QUOTA_BYTES') === null
        ? null
        : (int) env('UPLOAD_QUOTA_BYTES'),

    /*
    |--------------------------------------------------------------------------
    | Cloud fault injection
    |--------------------------------------------------------------------------
    |
    | Makes the byte store behave like a cloud provider on a bad day. Every
    | knob is off by default, so a normal run is completely unaffected.
    |
    | This is the application-level half of the network testing: it fails the
    | same way a provider fails when the link falls over. The wire-level half
    | (resetting TCP connections, delaying packets) belongs to a proxy in
    | front of the provider.
    |
    |   php artisan edms:upload-chaos --preset=flaky
    |
    | The named presets are listed by:
    |
    |   php artisan edms:upload-chaos --list
    |
    */

    'cloud_fault' => [
        'latency_ms' => (int) env('UPLOAD_FAULT_LATENCY_MS', 0),
        'jitter_ms' => (int) env('UPLOAD_FAULT_JITTER_MS', 0),
        'bandwidth_bps' => env('UPLOAD_FAULT_BANDWIDTH_BPS') === null
            ? null
            : (int) env('UPLOAD_FAULT_BANDWIDTH_BPS'),
        'failure_rate' => (float) env('UPLOAD_FAULT_FAILURE_RATE', 0),
        'drop_after_bytes' => env('UPLOAD_FAULT_DROP_AFTER_BYTES') === null
            ? null
            : (int) env('UPLOAD_FAULT_DROP_AFTER_BYTES'),
        'quota_exceeded' => (bool) env('UPLOAD_FAULT_QUOTA_EXCEEDED', false),
        'eventual_consistency_reads' => (int) env('UPLOAD_FAULT_EVENTUAL_CONSISTENCY_READS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dropbox
    |--------------------------------------------------------------------------
    |
    | Credentials for the "dropbox" driver. The refresh token is the
    | long-lived one; the short-lived access token is renewed and cached
    | automatically.
    |
    */

    'dropbox' => [
        'app_key' => env('DROPBOX_APP_KEY', ''),
        'app_secret' => env('DROPBOX_APP_SECRET', ''),
        'refresh_token' => env('DROPBOX_REFRESH_TOKEN', ''),

        // Docker-style secret files. A path here takes precedence over the
        // inline value above, which keeps secrets out of the environment.
        'app_key_file' => env('DROPBOX_APP_KEY_FILE', ''),
        'app_secret_file' => env('DROPBOX_APP_SECRET_FILE', ''),
        'refresh_token_file' => env('DROPBOX_REFRESH_TOKEN_FILE', ''),

        // Folder in the Dropbox account that holds the EDMS files.
        'root' => env('DROPBOX_ROOT', '/edms'),

        // The exact URL registered as the OAuth redirect on the Dropbox app.
        // Leave empty to derive it from the named callback route.
        'redirect_uri' => env('DROPBOX_REDIRECT_URI', ''),

        // Seconds before a Dropbox request is abandoned.
        'timeout' => (int) env('DROPBOX_TIMEOUT', 120),

        // Override only for testing/proxying; production uses the defaults.
        'api_url' => env('DROPBOX_API_URL', 'https://api.dropboxapi.com/2'),
        'content_url' => env('DROPBOX_CONTENT_URL', 'https://content.dropboxapi.com/2'),

        // Objects larger than this are trusted on their size alone when an
        // assembly is retried, because hashing them means reading them back.
        'verify_read_back_limit' => (int) env('DROPBOX_VERIFY_READ_BACK_LIMIT', 67108864),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Drive
    |--------------------------------------------------------------------------
    |
    | OAuth client credentials from Google Cloud Console. The refresh token is
    | obtained through the admin UI; it is stored encrypted on the space.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET', ''),
        'refresh_token' => env('GOOGLE_DRIVE_REFRESH_TOKEN', ''),
        'root' => env('GOOGLE_DRIVE_ROOT', '/edms'),
        'redirect_uri' => env('GOOGLE_DRIVE_REDIRECT_URI', ''),
        'api_url' => env('GOOGLE_DRIVE_API_URL', 'https://www.googleapis.com/drive/v3'),
        'upload_url' => env('GOOGLE_DRIVE_UPLOAD_URL', 'https://www.googleapis.com/upload/drive/v3'),
        'token_url' => env('GOOGLE_DRIVE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'timeout' => (int) env('GOOGLE_DRIVE_TIMEOUT', 120),

        // Files larger than this are trusted on size alone when an assembly is
        // retried, because hashing them means downloading them again.
        'verify_read_back_limit' => (int) env('GOOGLE_DRIVE_VERIFY_READ_BACK_LIMIT', 67108864),
    ],

    /*
    |--------------------------------------------------------------------------
    | Microsoft OneDrive
    |--------------------------------------------------------------------------
    |
    | OAuth client credentials from the Microsoft Entra (Azure AD) app
    | registration. "tenant" is "common" for personal accounts and a tenant id
    | (or domain) for an organisation.
    |
    */

    'onedrive' => [
        'client_id' => env('ONEDRIVE_CLIENT_ID', ''),
        'client_secret' => env('ONEDRIVE_CLIENT_SECRET', ''),
        'refresh_token' => env('ONEDRIVE_REFRESH_TOKEN', ''),
        'tenant' => env('ONEDRIVE_TENANT', 'common'),
        'root' => env('ONEDRIVE_ROOT', '/edms'),
        'redirect_uri' => env('ONEDRIVE_REDIRECT_URI', ''),
        'graph_url' => env('ONEDRIVE_GRAPH_URL', 'https://graph.microsoft.com/v1.0'),
        'auth_url' => env('ONEDRIVE_AUTH_URL', 'https://login.microsoftonline.com'),
        'timeout' => (int) env('ONEDRIVE_TIMEOUT', 120),

        // Files larger than this are trusted on size alone when an assembly is
        // retried, because hashing them means downloading them again.
        'verify_read_back_limit' => (int) env('ONEDRIVE_VERIFY_READ_BACK_LIMIT', 67108864),
    ],

];
