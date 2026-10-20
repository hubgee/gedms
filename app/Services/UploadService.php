<?php

namespace App\Services;

use App\Contracts\UploadStorage;
use App\Jobs\AssembleUpload;
use App\Models\Folder;
use App\Models\StorageSpace;
use App\Models\UploadChunk;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\Uploads\UploadIncompleteException;
use App\Services\Uploads\UploadVerificationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Resumable, chunked uploads for very large files (up to config('uploads.max_bytes')).
 *
 * Durability contract — written for the worst case (dropped connection, killed
 * request, power cut, disk loss):
 *
 *  - a chunk is streamed to "{index}.chunk.part" and only renamed to
 *    "{index}.chunk" once it is complete and fsync'd, so a crash can never
 *    leave a partial chunk that looks finished;
 *  - the chunk row is inserted only after the bytes are durable on disk;
 *  - every resume reconciles the index against the disk, so lost or truncated
 *    bytes are reported missing and simply re-sent by the client;
 *  - assembly is idempotent and only publishes File/FileVersion rows in the
 *    same transaction in which the session is marked completed.
 *
 * Byte handling is delegated to the UploadStorage contract, so the local disk
 * can later be replaced by object storage without touching this class.
 */
class UploadService
{
    public function __construct(
        protected SpaceService $spaceService,
        protected FileService $fileService,
        protected AuditService $auditService,
        protected UploadStorage $storage,
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

        try {
            $this->storage->putChunk($this->chunkPath($session, $index), $chunk->getRealPath(), $sha256);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['chunk' => $e->getMessage()]);
        }

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
     * Finalise an upload: check that every chunk is present, claim the session
     * and queue the assembly.
     *
     * The claim is a conditional UPDATE, so two concurrent "complete" requests
     * cannot both queue an assembly job.
     */
    public function complete(UploadSession $session, User $user): UploadSession
    {
        $this->authorizeSession($session, $user);

        if ($session->isCompleted() || $session->status === UploadSession::STATUS_ASSEMBLING) {
            return $session;
        }

        if (! in_array($session->status, [UploadSession::STATUS_PENDING, UploadSession::STATUS_UPLOADING], true)) {
            throw ValidationException::withMessages([
                'upload' => "This upload is {$session->status} and cannot be completed.",
            ]);
        }

        if ($session->isExpired()) {
            $this->expire($session);

            throw ValidationException::withMessages([
                'upload' => 'This upload has expired. Please start it again.',
            ]);
        }

        $session = $this->reconcileChunks($session);

        $missing = $session->missingChunkIndexes();
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'upload' => 'The upload is incomplete: '.count($missing).' of '.$session->total_chunks.' chunks are missing.',
            ]);
        }

        $claimed = UploadSession::query()
            ->whereKey($session->id)
            ->whereIn('status', [UploadSession::STATUS_PENDING, UploadSession::STATUS_UPLOADING])
            ->update([
                'status' => UploadSession::STATUS_ASSEMBLING,
                'failure_reason' => null,
            ]);

        if ($claimed === 0) {
            return $session->fresh();
        }

        AssembleUpload::dispatch($session->id);

        return $session->fresh();
    }

    /**
     * Assemble the chunks into the final file and publish it.
     *
     * Called by {@see AssembleUpload}; safe to run more than once. The final
     * path and the File UUID are derived from the session UUID, so a retry
     * reuses the same destination instead of scattering orphan copies.
     */
    public function assemble(UploadSession $session): void
    {
        if (in_array($session->status, [
            UploadSession::STATUS_COMPLETED,
            UploadSession::STATUS_CANCELLED,
            UploadSession::STATUS_EXPIRED,
            UploadSession::STATUS_FAILED,
        ], true)) {
            return;
        }

        $session = $this->reconcileChunks($session);

        $this->assertDestinationStillValid($session);

        $missing = $session->missingChunkIndexes();
        if ($missing !== []) {
            // Bytes vanished between "complete" and assembly. Put the session
            // back so the client re-sends only those chunks.
            $session->forceFill(['status' => UploadSession::STATUS_UPLOADING])->save();

            throw new UploadIncompleteException($session, $missing);
        }

        $fileUuid = $session->uuid;
        $storagePath = $this->fileService->buildStoragePathFor(
            $session->storageSpace,
            $fileUuid,
            1,
            $session->name,
        );

        $sources = array_map(
            fn (int $index) => $this->chunkPath($session, $index),
            range(0, (int) $session->total_chunks - 1),
        );

        $this->storage->assemble(
            $storagePath,
            $sources,
            (int) $session->total_bytes,
            $session->sha256,
        );

        $absolutePath = $this->storage->localPath($storagePath);

        try {
            $this->assertNoExecutablePayload($absolutePath);
        } catch (UploadVerificationException $e) {
            $this->storage->delete($storagePath);

            throw $e;
        }

        $mimeType = $this->detectMimeType($absolutePath) ?: ($session->mime_type ?: 'application/octet-stream');

        DB::transaction(function () use ($session, $fileUuid, $storagePath, $mimeType) {
            $file = $this->fileService->publishAssembled(
                $session->storageSpace,
                $session->folder,
                $session->user,
                $fileUuid,
                $storagePath,
                $session->name,
                $mimeType,
                (int) $session->total_bytes,
                (int) $session->id,
            );

            $session->forceFill([
                'status' => UploadSession::STATUS_COMPLETED,
                'file_id' => $file->id,
                'failure_reason' => null,
            ])->save();
        });

        $this->storage->deleteDirectory($this->temporaryDirectory($session));

        $this->auditService->log('upload.completed', UploadSession::class, $session->id, null, [
            'file_id' => $session->file_id,
            'name' => $session->name,
            'space_id' => $session->storage_space_id,
            'declared_mime' => $session->mime_type,
            'detected_mime' => $mimeType,
        ]);
    }

    /** Record a terminal failure. Idempotent; completed sessions are left alone. */
    public function fail(UploadSession $session, string $reason): void
    {
        if ($session->isCompleted() || $session->status === UploadSession::STATUS_FAILED) {
            return;
        }

        $session->forceFill([
            'status' => UploadSession::STATUS_FAILED,
            'failure_reason' => Str::limit($reason, 250),
        ])->save();

        $this->auditService->log('upload.failed', UploadSession::class, $session->id, null, [
            'name' => $session->name,
            'reason' => $reason,
        ]);
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
        $receivedBytes = 0;
        $dirty = false;

        foreach ($session->chunks()->get() as $chunk) {
            $path = $this->chunkPath($session, (int) $chunk->chunk_index);
            $onDisk = $this->storage->exists($path) ? (int) $this->storage->size($path) : 0;

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
     * Re-check at assembly time that the destination is still usable: the user
     * may have lost access and the folder may have been deleted while the
     * upload was in flight.
     */
    protected function assertDestinationStillValid(UploadSession $session): void
    {
        if ($session->user === null) {
            throw new UploadVerificationException('The uploading user no longer exists.');
        }

        if (! $this->spaceService->userCanAccess($session->storageSpace, $session->user)) {
            throw new UploadVerificationException('The uploading user no longer has access to the destination space.');
        }

        if ($session->folder_id !== null && $session->folder === null) {
            throw new UploadVerificationException('The destination folder no longer exists.');
        }
    }

    /**
     * Refuse PHP payloads. Stored files live outside the web root and are served
     * through a controller, but rejecting executable content here removes the
     * whole "uploaded script gets executed" class of bug for good.
     */
    protected function assertNoExecutablePayload(?string $absolutePath): void
    {
        if ($absolutePath === null) {
            return;
        }

        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The assembled file is not readable.');
        }

        $head = fread($handle, 512) ?: '';
        fclose($handle);

        $head = ltrim($head, "\xEF\xBB\xBF \t\r\n");

        if (str_starts_with($head, '<?php') || str_starts_with($head, '<?=')) {
            throw new UploadVerificationException('The file contains executable PHP code and cannot be stored.');
        }
    }

    protected function detectMimeType(?string $absolutePath): ?string
    {
        if ($absolutePath === null || ! is_readable($absolutePath)) {
            return null;
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath);

        return is_string($detected) && $detected !== '' ? $detected : null;
    }

    protected function deleteTemporaryFiles(UploadSession $session): void
    {
        $this->storage->deleteDirectory($this->temporaryDirectory($session));
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
}
