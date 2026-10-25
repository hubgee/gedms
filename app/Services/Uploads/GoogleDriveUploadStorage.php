<?php

namespace App\Services\Uploads;

use App\Contracts\ReportsStorageQuota;
use App\Contracts\UploadStorage;
use RuntimeException;
use Throwable;

/**
 * Google Drive implementation of the upload byte store.
 *
 * Drive has no concept of a key, so a chunk is an ordinary file under
 * "tmp/{uuid}/{index}.chunk" and the assembled file is one more file at the
 * published path. Assembly downloads the chunks and uploads the concatenation;
 * Google cannot join two files server-side.
 */
class GoogleDriveUploadStorage implements ReportsStorageQuota, UploadStorage
{
    private const BUFFER_BYTES = 1048576;

    public function __construct(
        protected GoogleDriveClient $client,
        protected int $verifyReadBackLimit = 67108864,
    ) {}

    public function putChunk(string $path, string $sourcePath, ?string $expectedSha256 = null): void
    {
        if ($expectedSha256 !== null && ! $this->hashMatchesFile($sourcePath, $expectedSha256)) {
            throw new RuntimeException('The chunk failed its integrity check.');
        }

        $this->client->uploadFile($path, $sourcePath);
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

        if (count($sourcePaths) === 1) {
            $this->client->copy($sourcePaths[0], $destination);
            $this->verifySize($destination, $expectedBytes, $expectedSha256);

            return;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'edms-google-');

        if ($temporary === false) {
            throw new RuntimeException('Unable to create a temporary file for the assembly.');
        }

        $target = @fopen($temporary, 'wb');

        if ($target === false) {
            @unlink($temporary);

            throw new RuntimeException('Unable to create a temporary file for the assembly.');
        }

        $context = hash_init('sha256');
        $written = 0;

        try {
            foreach ($sourcePaths as $source) {
                $stream = $this->client->readStream($source);

                if ($stream === null) {
                    throw new RuntimeException('A chunk is missing from the assembly set.');
                }

                try {
                    while (! feof($stream)) {
                        $buffer = fread($stream, self::BUFFER_BYTES);

                        if ($buffer === false) {
                            throw new RuntimeException('Unable to read a chunk during assembly.');
                        }

                        if ($buffer === '') {
                            break;
                        }

                        if (fwrite($target, $buffer) !== strlen($buffer)) {
                            throw new RuntimeException('Unable to write the assembled file.');
                        }

                        hash_update($context, $buffer);
                        $written += strlen($buffer);
                    }
                } finally {
                    fclose($stream);
                }
            }
        } catch (Throwable $e) {
            fclose($target);
            @unlink($temporary);

            throw $e instanceof RuntimeException
                ? $e
                : new RuntimeException('Unable to assemble the uploaded file.', 0, $e);
        }

        fclose($target);

        try {
            if ($written !== $expectedBytes) {
                throw new UploadVerificationException(
                    "The assembled file is {$written} bytes but {$expectedBytes} were expected."
                );
            }

            if ($expectedSha256 !== null && ! hash_equals(strtolower($expectedSha256), hash_final($context))) {
                throw new UploadVerificationException('The assembled file failed its integrity check.');
            }

            $this->client->uploadFile($destination, $temporary);
        } finally {
            @unlink($temporary);
        }

        $this->verifySize($destination, $expectedBytes, $expectedSha256);
    }

    public function exists(string $path): bool
    {
        return $this->client->find($path) !== null;
    }

    public function size(string $path): ?int
    {
        return $this->client->find($path)['size'] ?? null;
    }

    public function delete(string $path): void
    {
        $this->client->delete($path);
    }

    public function deleteDirectory(string $directory): void
    {
        $this->client->deleteDirectory($directory);
    }

    public function directories(string $directory): array
    {
        return $this->client->directories($directory);
    }

    public function isEmpty(string $directory): bool
    {
        return $this->client->isEmpty($directory);
    }

    public function copy(string $source, string $destination): void
    {
        $this->client->copy($source, $destination);
    }

    public function readStream(string $path, int $offset = 0, ?int $length = null)
    {
        return $this->client->readStream($path, $offset, $length);
    }

    public function readHead(string $path, int $bytes): ?string
    {
        return $this->client->readHead($path, $bytes);
    }

    public function healthCheck(): bool
    {
        return $this->client->healthCheck();
    }

    /**
     * @return array{used_bytes: int|null, allocated_bytes: int|null}|null
     */
    public function spaceUsage(): ?array
    {
        return $this->client->spaceUsage();
    }

    protected function alreadyAssembled(string $destination, int $expectedBytes, ?string $expectedSha256): bool
    {
        $existing = $this->client->find($destination);

        if ($existing === null || ($existing['size'] ?? null) !== $expectedBytes) {
            return false;
        }

        if ($expectedSha256 === null) {
            return true;
        }

        if ($expectedBytes > $this->verifyReadBackLimit) {
            // Reading a multi-gigabyte file back to hash it costs as much as
            // uploading it again. The size already rules out a partial
            // assembly, and the bytes were hashed locally before upload.
            return true;
        }

        $head = $this->client->readHead($destination, $expectedBytes);

        return is_string($head) && hash_equals(strtolower($expectedSha256), hash('sha256', $head));
    }

    protected function verifySize(string $destination, int $expectedBytes, ?string $expectedSha256): void
    {
        $actual = $this->size($destination);

        if ($actual !== $expectedBytes) {
            $this->delete($destination);

            throw new UploadVerificationException(
                "The assembled file is {$actual} bytes but {$expectedBytes} were expected."
            );
        }

        if ($expectedSha256 !== null && ! $this->alreadyAssembled($destination, $expectedBytes, $expectedSha256)) {
            $this->delete($destination);

            throw new UploadVerificationException('The assembled file failed its integrity check.');
        }
    }

    protected function hashMatchesFile(string $absolutePath, string $expectedSha256): bool
    {
        $actual = hash_file('sha256', $absolutePath);

        return is_string($actual) && hash_equals(strtolower($expectedSha256), $actual);
    }
}
