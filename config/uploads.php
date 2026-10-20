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
    |   php artisan queue:work --connection=uploads --queue=uploads --timeout=3600
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

];
