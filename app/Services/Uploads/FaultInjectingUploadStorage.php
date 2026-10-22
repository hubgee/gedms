<?php

namespace App\Services\Uploads;

use App\Contracts\UploadStorage;
use RuntimeException;

/**
 * Wraps a storage backend and makes the network misbehave on purpose.
 *
 * This is the application-level half of the fault injection. It sits between
 * the upload pipeline and the real backend (local disk, S3 or Dropbox) and
 * injects the conditions a cloud storage provider really produces: added
 * latency, jitter, a thin pipe, transient failures, connections that die in
 * the middle of a transfer, a full bucket, and reads that lag behind writes.
 *
 * Because it is a decorator, the same faults can be applied to a real
 * provider: point the backend at a local MinIO or a Dropbox app folder and
 * wrap it, and the pipeline cannot tell the difference.
 *
 * The network-level half of the plan (resetting TCP connections, delaying
 * packets, dropping bytes on the wire) belongs to a proxy in front of the
 * provider, not here. What this class can do is fail the same way the
 * provider would have failed when the network fell over.
 */
class FaultInjectingUploadStorage implements UploadStorage
{
    /**
     * Paths that were written but cannot be seen yet, and how many more
     * reads have to report them as missing.
     *
     * @var array<string, int>
     */
    protected array $staleReads = [];

    public function __construct(
        protected UploadStorage $inner,
        protected CloudFaultProfile $profile,
    ) {}

    public function putChunk(string $path, string $sourcePath, ?string $expectedSha256 = null): void
    {
        $this->beforeOperation();

        $bytes = $this->sizeOf($sourcePath);

        $this->throttle($bytes);

        $dropAfter = $this->profile->dropAfterBytes;

        if ($dropAfter !== null && $bytes > $dropAfter) {
            // The connection dies mid-transfer: the provider only receives
            // the first N bytes. The truncated chunk is stored on purpose, so
            // the pipeline has to notice that the bytes on disk are short and
            // ask for that chunk again. That is exactly what happens when a
            // real connection is cut.
            $truncated = $this->truncatedCopy($sourcePath, $dropAfter);

            try {
                $this->inner->putChunk($path, $truncated, null);
            } finally {
                @unlink($truncated);
            }

            throw new RuntimeException('The connection dropped while the chunk was being transferred.');
        }

        $this->inner->putChunk($path, $sourcePath, $expectedSha256);

        $this->markWritten($path);
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
        $this->beforeOperation();

        $this->throttle($expectedBytes);

        $this->inner->assemble($destination, $sourcePaths, $expectedBytes, $expectedSha256);

        $this->markWritten($destination);
    }

    public function exists(string $path): bool
    {
        $this->beforeOperation();

        if ($this->isStale($path)) {
            return false;
        }

        return $this->inner->exists($path);
    }

    public function size(string $path): ?int
    {
        $this->beforeOperation();

        if ($this->isStale($path)) {
            return null;
        }

        return $this->inner->size($path);
    }

    public function delete(string $path): void
    {
        $this->beforeOperation();

        $this->inner->delete($path);

        unset($this->staleReads[$path]);
    }

    public function deleteDirectory(string $directory): void
    {
        $this->beforeOperation();

        $this->inner->deleteDirectory($directory);
    }

    /**
     * @return array<int, string>
     */
    public function directories(string $directory): array
    {
        $this->beforeOperation();

        return $this->inner->directories($directory);
    }

    public function isEmpty(string $directory): bool
    {
        $this->beforeOperation();

        return $this->inner->isEmpty($directory);
    }

    public function copy(string $source, string $destination): void
    {
        $this->beforeOperation();

        $this->inner->copy($source, $destination);

        $this->markWritten($destination);
    }

    public function readStream(string $path, int $offset = 0, ?int $length = null)
    {
        $this->beforeOperation();

        if ($this->isStale($path)) {
            return null;
        }

        return $this->inner->readStream($path, $offset, $length);
    }

    public function readHead(string $path, int $bytes): ?string
    {
        $this->beforeOperation();

        if ($this->isStale($path)) {
            return null;
        }

        return $this->inner->readHead($path, $bytes);
    }

    public function localPath(string $path): ?string
    {
        return $this->inner->localPath($path);
    }

    /**
     * The backend underneath, for callers that need the real thing.
     */
    public function inner(): UploadStorage
    {
        return $this->inner;
    }

    /**
     * Delay, throttle and fail, according to the profile.
     */
    protected function beforeOperation(): void
    {
        $profile = $this->profile;

        if ($profile->latencyMs > 0 || $profile->jitterMs > 0) {
            $delay = $profile->latencyMs + ($profile->jitterMs > 0 ? random_int(0, $profile->jitterMs) : 0);

            usleep($delay * 1000);
        }

        if ($profile->quotaExceeded) {
            throw new RuntimeException('The storage provider reported that the quota is exhausted.');
        }

        if ($profile->failureRate > 0.0 && (random_int(1, 1000000) / 1000000) < $profile->failureRate) {
            throw new RuntimeException('The storage provider is temporarily unavailable.');
        }
    }

    /**
     * Sleep so the transfer takes at least as long as the bandwidth ceiling
     * allows.
     */
    protected function throttle(int $bytes): void
    {
        $bps = $this->profile->bandwidthBytesPerSecond;

        if ($bps === null || $bps < 1 || $bytes < 1) {
            return;
        }

        usleep((int) round(($bytes / $bps) * 1000000));
    }

    /**
     * Whether a path is still hidden behind the provider's read lag.
     */
    protected function isStale(string $path): bool
    {
        if (($this->staleReads[$path] ?? 0) <= 0) {
            return false;
        }

        $this->staleReads[$path]--;

        return true;
    }

    protected function markWritten(string $path): void
    {
        if ($this->profile->eventualConsistencyReads > 0) {
            $this->staleReads[$path] = $this->profile->eventualConsistencyReads;
        }
    }

    /**
     * A copy of the first $bytes of a file.
     */
    protected function truncatedCopy(string $sourcePath, int $bytes): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'edms-fault-');

        if ($temporary === false) {
            throw new RuntimeException('Unable to create a temporary file for the fault injection.');
        }

        $source = @fopen($sourcePath, 'rb');
        $target = @fopen($temporary, 'wb');

        if ($source === false || $target === false) {
            is_resource($source) && fclose($source);
            is_resource($target) && fclose($target);
            @unlink($temporary);

            throw new RuntimeException('Unable to prepare the fault injection.');
        }

        $written = 0;

        try {
            while ($written < $bytes && ! feof($source)) {
                $buffer = fread($source, min(65536, $bytes - $written));

                if ($buffer === false || $buffer === '') {
                    break;
                }

                fwrite($target, $buffer);
                $written += strlen($buffer);
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        return $temporary;
    }

    public function healthCheck(): bool
    {
        return $this->inner->healthCheck();
    }

    protected function sizeOf(string $path): int
    {
        $size = @filesize($path);

        return is_int($size) ? $size : 0;
    }
}
