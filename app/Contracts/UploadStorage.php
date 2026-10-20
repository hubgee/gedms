<?php

namespace App\Contracts;

/**
 * Byte-level store for resumable uploads.
 *
 * Implementations must guarantee that a write is all-or-nothing: a crash or a
 * failed stream must never leave a file at the destination path that looks
 * complete. The local implementation uses a ".part" file plus rename(); an
 * object-storage implementation would use multipart upload completion.
 *
 * The rest of the upload module (UploadService, AssembleUpload) depends only on
 * this contract, so the byte store can be swapped without touching the state
 * machine.
 */
interface UploadStorage
{
    /**
     * Store one chunk atomically, verifying its SHA-256 when one is supplied.
     *
     * @throws \RuntimeException when the chunk cannot be written or fails verification
     */
    public function putChunk(string $path, string $sourcePath, ?string $expectedSha256 = null): void;

    /**
     * Concatenate the given sources into the destination atomically and verify
     * the result (byte count and optional SHA-256).
     *
     * Idempotent: when the destination already holds the expected bytes it is
     * left untouched, which makes a retried assembly cheap instead of rewriting
     * a multi-gigabyte file.
     *
     * @param  array<int, string>  $sourcePaths
     *
     * @throws \RuntimeException when assembly or verification fails
     */
    public function assemble(
        string $destination,
        array $sourcePaths,
        int $expectedBytes,
        ?string $expectedSha256 = null,
    ): void;

    public function exists(string $path): bool;

    public function size(string $path): ?int;

    public function delete(string $path): void;

    public function deleteDirectory(string $directory): void;

    /**
     * Physical path to the bytes when the backend has one, or null for stores
     * that cannot expose a local path (for example S3).
     */
    public function localPath(string $path): ?string;
}
