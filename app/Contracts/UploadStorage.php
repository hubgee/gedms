<?php

namespace App\Contracts;

/**
 * Byte-level store for the files EDMS keeps.
 *
 * Implementations must guarantee that a write is all-or-nothing: a crash or a
 * failed stream must never leave a file at the destination path that looks
 * complete. The local implementation uses a ".part" file plus rename(); an
 * object-storage implementation would use multipart upload completion.
 *
 * The rest of the upload module (UploadService, AssembleUpload) depends only on
 * this contract, so the byte store can be swapped without touching the state
 * machine.
 *
 * The contract also covers reading, because the rest of the application reads
 * the same bytes back: a download, a preview and a copy must work whether the
 * file lives on a local disk, in a bucket or in a Dropbox folder. Keeping
 * those operations behind the same seam is what stops "the upload goes to the
 * cloud but the download still looks at the local disk" from happening.
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
     * Store a complete file atomically, verifying its SHA-256 when one is
     * supplied.
     *
     * This is the same operation as {@see putChunk()}; it exists so that code
     * which is not dealing with chunks (the classic single-request upload, a
     * new file version) does not have to pretend to be a chunk.
     *
     * @throws \RuntimeException when the file cannot be written or fails verification
     */
    public function putFile(string $path, string $sourcePath, ?string $expectedSha256 = null): void;

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
     * Names of the directories directly below the given one.
     *
     * @return array<int, string>
     */
    public function directories(string $directory): array;

    /**
     * Whether the directory holds no files at all.
     *
     * Used to tidy up the "{space}/{uuid}" folder once the last version of a
     * file has been deleted. A local disk answers this from the filesystem; an
     * object store has no real directories, so the answer is derived from the
     * keys under the prefix.
     */
    public function isEmpty(string $directory): bool;

    /**
     * Copy one stored object onto another path in the same store.
     *
     * @throws \RuntimeException when the source is missing or the copy fails
     */
    public function copy(string $source, string $destination): void;

    /**
     * Open the stored bytes for reading.
     *
     * $offset and $length describe the slice to read; a null $length means "to
     * the end". Implementations that can read a range should do so, so that
     * previewing the first 8 KiB of a 10 GB object does not download 10 GB.
     *
     * The caller owns the returned resource and must close it.
     *
     * @return resource|null
     */
    public function readStream(string $path, int $offset = 0, ?int $length = null);

    /**
     * First bytes of the stored object, or null when it does not exist.
     *
     * Object stores have no local path, so the pipeline cannot open the file
     * with fopen(). Reading the head keeps the executable-payload check and the
     * MIME sniffing working on every backend instead of silently degrading to
     * "no local path, so no checks" once the disk stops being local.
     */
    public function readHead(string $path, int $bytes): ?string;

    /**
     * Cheap liveness probe for the backend.
     *
     * Returns true when the store can be reached and is usable, false
     * otherwise. Used by health checks and connection tests; it must not
     * throw.
     */
    public function healthCheck(): bool;
}
