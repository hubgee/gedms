<?php

namespace App\Jobs;

use App\Models\UploadSession;
use App\Services\Uploads\UploadIncompleteException;
use App\Services\Uploads\UploadVerificationException;
use App\Services\UploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Assembles the chunks of a completed upload session and publishes the file.
 *
 * Runs on the dedicated "uploads" connection (see config/queue.php), whose
 * retry_after is longer than this job's timeout so a worker is never killed and
 * re-reserved mid-assembly of a multi-gigabyte file.
 *
 * Failure semantics:
 *  - transient errors (I/O, database) throw, so the queue retries;
 *  - missing chunks are recoverable: the session goes back to "uploading";
 *  - policy/verification failures are terminal: the session is marked failed
 *    so the same bytes are not read over and over.
 */
class AssembleUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * 55 minutes: must stay below the uploads connection retry_after (3600s).
     * A 10 GB file needs roughly 200s at 50 MB/s, or 33 min at 5 MB/s.
     */
    public int $timeout = 3300;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $uploadSessionId,
    ) {
        $this->onConnection(config('uploads.queue_connection'));
        $this->onQueue(config('uploads.queue'));
    }

    public function handle(UploadService $uploads): void
    {
        $session = UploadSession::query()->find($this->uploadSessionId);

        if ($session === null) {
            return;
        }

        if (in_array($session->status, [
            UploadSession::STATUS_COMPLETED,
            UploadSession::STATUS_CANCELLED,
            UploadSession::STATUS_EXPIRED,
            UploadSession::STATUS_FAILED,
        ], true)) {
            return;
        }

        try {
            $uploads->assemble($session);
        } catch (UploadIncompleteException $e) {
            // Recoverable: the client has to re-send the listed chunks.
            Log::warning('Upload assembly deferred: chunks are missing.', [
                'upload_session_id' => $session->id,
                'missing' => count($e->missing),
            ]);
        } catch (UploadVerificationException $e) {
            // Terminal: retrying would read the same invalid bytes again.
            $uploads->fail($session, $e->getMessage());

            Log::error('Upload assembly rejected.', [
                'upload_session_id' => $session->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /** Called by the queue once all retries are exhausted. */
    public function failed(?Throwable $exception): void
    {
        $session = UploadSession::query()->find($this->uploadSessionId);

        if ($session === null || $session->isCompleted()) {
            return;
        }

        app(UploadService::class)->fail(
            $session,
            $exception?->getMessage() ?? 'Upload assembly failed after repeated attempts.',
        );
    }
}
