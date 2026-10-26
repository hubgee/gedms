<?php

namespace App\Services\Uploads;

use App\Contracts\ReportsStorageQuota;
use App\Contracts\UploadStorage;
use RuntimeException;
use Throwable;

/**
 * Dropbox implementation of the upload byte store.
 *
 * Dropbox is not an object store with named keys, so the mapping onto the
 * UploadStorage contract is:
 *
 *  - a chunk is an ordinary file at "tmp/{uuid}/{index}.chunk";
 *  - the assembled file is one more file at the published path.
 *
 * Assembly is the expensive part. Dropbox upload sessions append strictly in
 * order, so the chunks have to be fed back through the application one at a
 * time: each chunk is downloaded and appended to the open session. A 10 GB
 * assembly therefore moves roughly 20 GB (10 GB down, 10 GB up) instead of
 * the single server-side copy that S3 can do.
 *
 * That cost is the price of Dropbox's API, and it is also exactly the kind of
 * behaviour the test plan wants to exercise: a slow, chatty, order-sensitive
 * cloud round trip in the middle of the upload.
 */
class DropboxUploadStorage implements ReportsStorageQuota, UploadStorage
{
    /** 1 MiB buffer: hashing a chunk never loads it into memory. */
    private const BUFFER_BYTES = 1048576;

    public function __construct(
        protected DropboxClient $client,
        /**
         * Objects larger than this are trusted on their size alone when an
         * assembly is retried: reading them back to hash them would cost as
         * much as assembling them again.
         */
        protected int $verifyReadBackLimit = 67108864,
    ) {}

    public function putChunk(string $path, string $sourcePath, ?string $expectedSha256 = null): void
    {
        // Verify before sending: the local file is the only copy of the bytes
        // at this point, and the object that lands in Dropbox holds exactly
        // these bytes.
        if ($expectedSha256 !== null && ! $this->hashMatchesFile($sourcePath, $expectedSha256)) {
            throw new RuntimeException('The chunk failed its integrity check.');
        }

        $this->client->upload($path, $sourcePath);
    }

    public function putFile(string $path, string $sourcePath, ?string $expectedSha256 = null): void
    {
        $this->putChunk($path, $sourcePath, $expectedSha256);
    }

    public function assemble(
        string $destination,
        array $sourcePaths,
        int $expectedBytes,
        ?string $expectedSha256 = null,
    ): void {
        if ($sourcePaths === []) {
            throw new RuntimeException('A chunk is missing from the assembly set.');
        }

        if ($this->alreadyAssembled($destination, $expectedBytes, $expectedSha256)) {
            return;
        }

        $this->client->delete($destination);

        $sessionId = $this->client->startUploadSession();
        $offset = 0;
        $context = hash_init('sha256');
        $temporary = null;

        try {
            $sources = array_values($sourcePaths);

            foreach ($sources as $source) {
                $temporary = $this->downloadChunkToTempFile($source);

                $this->client->appendToSession($sessionId, $offset, $temporary);

                $offset += $this->sizeOf($temporary);

                if ($expectedSha256 !== null) {
                    // The hash is accumulated over the bytes in order, so it
                    // covers exactly what was appended.
                    $this->hashInto($context, $temporary);
                }

                @unlink($temporary);
                $temporary = null;
            }

            if ($offset !== $expectedBytes) {
                throw new UploadVerificationException(
                    "The assembled file is {$offset} bytes but {$expectedBytes} were expected."
                );
            }

            if ($expectedSha256 !== null && ! hash_equals(strtolower($expectedSha256), hash_final($context))) {
                throw new UploadVerificationException('The assembled file failed its integrity check.');
            }

            // The session is closed with the final path. The bytes were
            // already appended, so the closing call carries no body.
            $empty = $this->emptyFile();

            try {
                $this->client->finishUploadSession($sessionId, $offset, $destination, $empty);
            } finally {
                @unlink($empty);
            }
        } catch (Throwable $e) {
            if ($temporary !== null) {
                @unlink($temporary);
            }

            $this->client->delete($destination);

            throw $e instanceof RuntimeException
                ? $e
                : new RuntimeException('Unable to assemble the uploaded file.', 0, $e);
        }
    }

    public function exists(string $path): bool
    {
        return $this->client->metadata($path) !== null;
    }

    public function size(string $path): ?int
    {
        $metadata = $this->client->metadata($path);

        return $metadata === null ? null : (int) ($metadata['size'] ?? 0);
    }

    public function delete(string $path): void
    {
        $this->client->delete($path);
    }

    public function deleteDirectory(string $directory): void
    {
        foreach ($this->client->listChildren($directory) as $entry) {
            $path = $entry['path_lower'] ?? $entry['path'] ?? null;

            if (! is_string($path) || $path === '') {
                continue;
            }

            $this->client->delete($this->client->storagePath($path));
        }

        $this->client->delete($directory);
        $this->client->forgetFolder($directory);
    }

    /**
     * @return array<int, string>
     */
    public function directories(string $directory): array
    {
        $names = [];

        foreach ($this->client->listChildren($directory) as $entry) {
            if (($entry['.tag'] ?? null) !== 'folder') {
                continue;
            }

            $name = $entry['name'] ?? null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    public function copy(string $source, string $destination): void
    {
        $this->client->copy($source, $destination);
    }

    public function isEmpty(string $directory): bool
    {
        return $this->directoryIsEmpty($directory, 0);
    }

    /**
     * Whether the folder, and everything below it, holds no files.
     */
    protected function directoryIsEmpty(string $directory, int $depth): bool
    {
        // Dropbox folders cannot nest deeply in practice; the guard keeps a
        // malformed account from turning this into an endless walk.
        if ($depth > 16) {
            return false;
        }

        foreach ($this->client->listChildren($directory) as $entry) {
            $tag = $entry['.tag'] ?? null;

            if ($tag === 'folder') {
                $name = $entry['name'] ?? null;

                if (! is_string($name) || $name === '') {
                    continue;
                }

                if (! $this->directoryIsEmpty(rtrim($directory, '/').'/'.$name, $depth + 1)) {
                    return false;
                }

                continue;
            }

            // Any file at all means the directory is not empty.
            return false;
        }

        return true;
    }

    public function readStream(string $path, int $offset = 0, ?int $length = null)
    {
        if ($offset < 0) {
            return null;
        }

        $temporary = $this->downloadChunkToTempFile($path, $offset, $length);

        if ($temporary === null) {
            return null;
        }

        $handle = @fopen($temporary, 'rb');

        if ($handle === false) {
            @unlink($temporary);

            return null;
        }

        // The temporary file is unlinked immediately: the handle keeps it
        // alive on POSIX, and Windows is not a supported server platform for
        // this application.
        @unlink($temporary);

        return $handle;
    }

    public function readHead(string $path, int $bytes): ?string
    {
        if ($bytes < 1) {
            return null;
        }

        return $this->client->readHead($path, $bytes);
    }

    public function localPath(string $path): ?string
    {
        // Dropbox has no local path. The pipeline reads the head through the
        // contract instead; see UploadStorage::readHead().
        return null;
    }

    protected function alreadyAssembled(string $destination, int $expectedBytes, ?string $expectedSha256): bool
    {
        $metadata = $this->client->metadata($destination);

        if ($metadata === null) {
            return false;
        }

        if ((int) ($metadata['size'] ?? -1) !== $expectedBytes) {
            return false;
        }

        if ($expectedSha256 === null) {
            return true;
        }

        if ($expectedBytes > $this->verifyReadBackLimit) {
            // Reading a 10 GB object back to hash it costs as much as
            // assembling it again. The size already rules out a partial
            // assembly, and the assembly itself verified the hash.
            return true;
        }

        return $this->hashMatches($destination, $expectedSha256);
    }

    public function healthCheck(): bool
    {
        return $this->client->healthCheck();
    }

    /**
     * Account-level usage and allocation, when Dropbox reports it.
     *
     * @return array{used_bytes: int|null, allocated_bytes: int|null}|null
     */
    public function spaceUsage(): ?array
    {
        return $this->client->spaceUsage();
    }

    /**
     * Whether the stored object hashes to the expected value.
     */
    protected function hashMatches(string $path, string $expectedSha256): bool
    {
        $temporary = $this->downloadChunkToTempFile($path);

        if ($temporary === null) {
            return false;
        }

        try {
            $actual = hash_file('sha256', $temporary);
        } finally {
            @unlink($temporary);
        }

        return is_string($actual) && hash_equals(strtolower($expectedSha256), $actual);
    }

    protected function hashMatchesFile(string $absolutePath, string $expectedSha256): bool
    {
        $actual = hash_file('sha256', $absolutePath);

        return is_string($actual) && hash_equals(strtolower($expectedSha256), $actual);
    }

    /**
     * Download a chunk into a temporary file so it can be hashed and then
     * appended to the upload session.
     */
    protected function downloadChunkToTempFile(string $path, int $offset = 0, ?int $length = null): ?string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'edms-dropbox-');

        if ($temporary === false) {
            throw new RuntimeException('Unable to create a temporary file for the Dropbox download.');
        }

        try {
            $this->client->downloadTo($path, $temporary, $offset, $length);
        } catch (Throwable $e) {
            @unlink($temporary);

            throw $e;
        }

        return $temporary;
    }

    protected function sizeOf(string $path): int
    {
        $size = filesize($path);

        return is_int($size) ? $size : 0;
    }

    /**
     * Feed a file into an open hash context.
     */
    protected function hashInto(\HashContext $context, string $path): void
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read the chunk while hashing it.');
        }

        try {
            while (! feof($handle)) {
                $buffer = fread($handle, self::BUFFER_BYTES);

                if ($buffer === false) {
                    throw new RuntimeException('Unable to read the chunk while hashing it.');
                }

                if ($buffer === '') {
                    break;
                }

                hash_update($context, $buffer);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * An empty file, used as the body of the call that closes an upload
     * session.
     */
    protected function emptyFile(): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'edms-empty-');

        if ($temporary === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }

        return $temporary;
    }
}
