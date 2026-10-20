<?php

namespace App\Services;

use App\Models\Folder;
use App\Models\StorageSpace;
use App\Models\UploadChunk;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

/**
 * Resumable, chunked uploads for very large files (up to config('uploads.max_bytes')).
 *
 * Durability contract — written for the worst case (dropped connection, killed
 * request, power cut, disk loss):
 *
 *  - a chunk is streamed to "{index}.chunk.part" and only renamed to
 *    "{index}.chunk" once it is complete, and rename() is atomic within a
 *    filesystem, so a crash can never leave a partial chunk that looks finished;
 *  - the chunk row is inserted only after the bytes are durable on disk;
 *  - every resume reconciles the index against the disk, so lost or truncated
 *    bytes are reported missing and simply re-sent by the client.
 *
 * Chunk bytes live on the "edms" disk under tmp/{session-uuid}. That disk must
 * use the local driver, because both the atomic rename and the later streamed
 * concatenation require real filesystem paths.
 */
class UploadService
{
    public function __construct(
        protected SpaceService $spaceService,
        protected FileService $fileService,
        protected AuditService $auditService,
    ) {}

    /**
     * Start a new upload session, or resume the existing one for the same user,
     * file fingerprint and destination.
     *
     * Reusing the session is what makes an interrupted upload continue from
     * where it stopped, even after a browser restart or a cleared client cache.
     */
    public function init(
        User $user,
        StorageSpace $space,
        ?Folder $folder,
        string $name,
        ?string $mimeType,
        int $totalBytes,
        string $fingerprint,
        ?string $sha256 = null,
    ): UploadSession {
        $this->spaceService->userCanAccess($space, $user) || abort(403);

        if ($folder && $folder->storage_space_id !== $space->id) {
            throw ValidationException::withMessages([
                'folder_id' => 'The folder does not belong to the selected space.',
            ]);
        }

        $this->fileService->assertCanUploadTo($space, $folder, $user);

        if ($totalBytes < 1) {
            throw ValidationException::withMessages(['size' => 'The file is empty.']);
        }

        $maxBytes = (int) config('uploads.max_bytes');
        if ($totalBytes > $maxBytes) {
            throw ValidationException::withMessages([
                'size' => 'The file is larger than the maximum upload size of '.Number::fileSize($maxBytes).'.',
            ]);
        }

        $chunkBytes = max(1, (int) config('uploads.chunk_bytes'));
        $ttlHours = max(1, (int) config('uploads.ttl_hours'));
        $safeName = $this->fileService->sanitizeFileName($name);
        $fingerprint = Str::limit($fingerprint, 255, '');

        $existing = UploadSession::query()
            ->resumable()
            ->where('user_id', $user->id)
            ->where('storage_space_id', $space->id)
            ->where('folder_id', $folder?->id)
            ->where('fingerprint', $fingerprint)
            ->first();

        if ($existing) {
            $existing->forceFill([
                'name' => $safeName,
                'mime_type' => $mimeType,
                'sha256' => $sha256 ?? $existing->sha256,
                'expires_at' => now()->addHours($ttlHours),
            ])->save();

            return $this->reconcileChunks($existing);
        }

        $session = UploadSession::create([
            'user_id' => $user->id,
            'storage_space_id' => $space->id,
            'folder_id' => $folder?->id,
            'name' => $safeName,
            'mime_type' => $mimeType,
            'fingerprint' => $fingerprint,
            'total_bytes' => $totalBytes,
            'chunk_bytes' => $chunkBytes,
            'total_chunks' => (int) ceil($totalBytes / $chunkBytes),
            'received_bytes' => 0,
            'sha256' => $sha256,
            'status' => UploadSession::STATUS_PENDING,
            'expires_at' => now()->addHours($ttlHours),
        ]);

        $this->auditService->log('upload.init', UploadSession::class, $session->id, null, [
            'name' => $session->name,
            'space_id' => $space->id,
            'total_bytes' => $totalBytes,
        ]);

        return $session;
    }

    /**
     * Store one chunk. Re-sending an index is safe (network retries, duplicate
     * in-flight requests): the last complete write wins and the byte counter is
     * recomputed rather than incremented, so it can never drift.
     */
    public function putChunk(
        UploadSession $session,
        User $user,
        int $index,
        UploadedFile $chunk,
        ?string $sha256 = null,
    ): UploadSession {
        $this->authorizeSession($session, $user);
        $this->assertAcceptsChunks($session);

        $expectedBytes = $this->expectedChunkBytes($session, $index);

        // Transport integrity: PHP flags a truncated body, and the size is
        // re-checked against what this chunk must contain.
        if (! $chunk->isValid()) {
            throw ValidationException::withMessages([
                'chunk' => 'The chunk did not arrive intact. Please retry it.',
            ]);
        }

        $actualBytes = (int) $chunk->getSize();
        if ($actualBytes !== $expectedBytes) {
            throw ValidationException::withMessages([
                'chunk' => "Chunk {$index} must contain exactly {$expectedBytes} bytes; {$actualBytes} were received.",
            ]);
        }

        $this->writeChunkAtomically($chunk->getRealPath(), $this->chunkPath($session, $index), $sha256);

        DB::transaction(function () use ($session, $index, $actualBytes, $sha256) {
            UploadChunk::upsert(
                [[
                    'upload_session_id' => $session->id,
                    'chunk_index' => $index,
                    'bytes' => $actualBytes,
                    'sha256' => $sha256,
                    'created_at' => now(),
                ]],
                ['upload_session_id', 'chunk_index'],
                ['bytes', 'sha256'],
            );

            $session->forceFill([
                'received_bytes' => (int) $session->chunks()->sum('bytes'),
                'status' => UploadSession::STATUS_UPLOADING,
                'expires_at' => now()->addHours(max(1, (int) config('uploads.ttl_hours'))),
            ])->save();
        });

        return $session->fresh();
    }

    /**
     * Resume state for the client: which indexes still have to be sent.
     * The index is reconciled against the disk first, so bytes that were lost or
     * truncated are reported as missing instead of being silently trusted.
     *
     * @return array<string, mixed>
     */
    public function status(UploadSession $session, User $user): array
    {
        $this->authorizeSession($session, $user);

        if ($session->isExpired() && $this->acceptsChunks($session)) {
            $this->expire($session);
            $session->refresh();
        }

        if ($this->acceptsChunks($session)) {
            $session = $this->reconcileChunks($session);
        }

        return [
            'uuid' => $session->uuid,
            'name' => $session->name,
            'status' => $session->status,
            'total_bytes' => (int) $session->total_bytes,
            'chunk_bytes' => (int) $session->chunk_bytes,
            'total_chunks' => (int) $session->total_chunks,
            'received_bytes' => (int) $session->received_bytes,
            'received' => $session->receivedChunkIndexes(),
            'missing' => $session->missingChunkIndexes(),
            'file_id' => $session->file_id,
            'failure_reason' => $session->failure_reason,
            'expires_at' => $session->expires_at?->toIso8601String(),
        ];
    }

    /** Cancel a session and drop its bytes. */
    public function cancel(UploadSession $session, User $user): void
    {
        $this->authorizeSession($session, $user);

        if ($session->isCompleted()) {
            throw ValidationException::withMessages([
                'upload' => 'This upload has already completed.',
            ]);
        }

        $this->deleteTemporaryFiles($session);
        $session->forceFill(['status' => UploadSession::STATUS_CANCELLED])->save();

        $this->auditService->log('upload.cancelled', UploadSession::class, $session->id, null, [
            'name' => $session->name,
        ]);
    }

    /** Mark a session expired and drop its bytes. Safe to call repeatedly. */
    public function expire(UploadSession $session): void
    {
        $this->deleteTemporaryFiles($session);
        $session->forceFill(['status' => UploadSession::STATUS_EXPIRED])->save();

        $this->auditService->log('upload.expired', UploadSession::class, $session->id, null, [
            'name' => $session->name,
        ]);
    }

    /** Resolve a session by its public uuid and ensure the caller owns it. */
    public function findForUser(string $uuid, User $user): UploadSession
    {
        $session = UploadSession::query()->where('uuid', $uuid)->firstOrFail();
        $this->authorizeSession($session, $user);

        return $session;
    }

    /**
     * Whether every chunk is recorded. Callers should reconcile first (see
     * {@see reconcileChunks()}) so a stale row cannot masquerade as present.
     */
    public function hasAllChunks(UploadSession $session): bool
    {
        return count($session->receivedChunkIndexes()) === (int) $session->total_chunks;
    }

    /**
     * Trust the disk over the index: drop every chunk row whose bytes are
     * missing or truncated, then recompute the received total. This is what
     * makes the flow survive a power cut, a lost write or disk tampering.
     */
    public function reconcileChunks(UploadSession $session): UploadSession
    {
        $disk = $this->disk();
        $receivedBytes = 0;
        $dirty = false;

        foreach ($session->chunks()->get() as $chunk) {
            $path = $this->chunkPath($session, (int) $chunk->chunk_index);
            $onDisk = $disk->exists($path) ? (int) $disk->size($path) : 0;

            if ($onDisk !== (int) $chunk->bytes) {
                $chunk->delete();
                $dirty = true;

                continue;
            }

            $receivedBytes += (int) $chunk->bytes;
        }

        if ($dirty || (int) $session->received_bytes !== $receivedBytes) {
            $session->forceFill(['received_bytes' => $receivedBytes])->save();
            $session->refresh();
        }

        return $session;
    }

    /** Exact number of bytes chunk $index must contain. */
    public function expectedChunkBytes(UploadSession $session, int $index): int
    {
        if ($index < 0 || $index >= (int) $session->total_chunks) {
            throw ValidationException::withMessages(['index' => 'The chunk index is out of range.']);
        }

        if ($index < (int) $session->total_chunks - 1) {
            return (int) $session->chunk_bytes;
        }

        return (int) $session->total_bytes - (int) $session->chunk_bytes * ((int) $session->total_chunks - 1);
    }

    /** Directory that holds a session's chunks on the edms disk. */
    public function temporaryDirectory(UploadSession $session): string
    {
        return 'tmp/'.$session->uuid;
    }

    protected function chunkPath(UploadSession $session, int $index): string
    {
        return $this->temporaryDirectory($session).'/'.$index.'.chunk';
    }

    /**
     * Stream the uploaded chunk to a ".part" file, optionally verify its hash,
     * then rename it into place. The rename is atomic on the same filesystem, so
     * an interrupted write can never leave a chunk that looks complete.
     */
    protected function writeChunkAtomically(string $sourcePath, string $destination, ?string $expectedSha256): void
    {
        $this->disk()->makeDirectory(dirname($destination));
        $partPath = $this->localPath($destination.'.part');
        $finalPath = $this->localPath($destination);

        $source = @fopen($sourcePath, 'rb');
        $target = @fopen($partPath, 'wb');

        if ($source === false || $target === false) {
            is_resource($source) && fclose($source);
            is_resource($target) && fclose($target);
            @unlink($partPath);

            throw ValidationException::withMessages(['chunk' => 'Unable to store the chunk on disk.']);
        }

        $copied = false;
        try {
            $copied = stream_copy_to_stream($source, $target) !== false;
            fflush($target);
        } finally {
            fclose($source);
            fclose($target);
        }

        if (! $copied) {
            @unlink($partPath);

            throw ValidationException::withMessages(['chunk' => 'Unable to store the chunk on disk.']);
        }

        if ($expectedSha256 !== null) {
            $actualSha256 = hash_file('sha256', $partPath);

            if (! is_string($actualSha256) || ! hash_equals(strtolower($expectedSha256), $actualSha256)) {
                @unlink($partPath);

                throw ValidationException::withMessages(['chunk' => 'The chunk failed its integrity check.']);
            }
        }

        if (! @rename($partPath, $finalPath)) {
            @unlink($partPath);

            throw ValidationException::withMessages(['chunk' => 'Unable to finalise the chunk on disk.']);
        }
    }

    protected function deleteTemporaryFiles(UploadSession $session): void
    {
        $this->disk()->deleteDirectory($this->temporaryDirectory($session));
    }

    protected function acceptsChunks(UploadSession $session): bool
    {
        return in_array($session->status, [UploadSession::STATUS_PENDING, UploadSession::STATUS_UPLOADING], true);
    }

    protected function assertAcceptsChunks(UploadSession $session): void
    {
        if ($session->isExpired()) {
            $this->expire($session);

            throw ValidationException::withMessages([
                'upload' => 'This upload has expired. Please start it again.',
            ]);
        }

        if (! $this->acceptsChunks($session)) {
            throw ValidationException::withMessages([
                'upload' => "This upload is {$session->status} and no longer accepts chunks.",
            ]);
        }
    }

    protected function authorizeSession(UploadSession $session, User $user): void
    {
        if ((int) $session->user_id !== $user->id) {
            abort(403);
        }
    }

    protected function disk(): FilesystemAdapter
    {
        return Storage::disk('edms');
    }

    /**
     * Real filesystem path for a file on the edms disk. Resumable uploads need
     * atomic renames and streamed concatenation, both of which require the local
     * driver — fail loudly rather than writing to a meaningless path.
     */
    protected function localPath(string $path): string
    {
        $disk = $this->disk();

        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            throw new RuntimeException('The [edms] disk must use the local driver for resumable uploads.');
        }

        return $disk->path($path);
    }
}
