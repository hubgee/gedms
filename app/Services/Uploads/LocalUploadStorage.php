<?php

namespace App\Services\Uploads;

use App\Contracts\UploadStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

/**
 * Local-disk implementation of the upload byte store.
 *
 * Every write goes to "<target>.part" first and is renamed into place only
 * after it has been fully written, flushed and (when available) fsync'd. The
 * rename is atomic within a filesystem, so a power cut can never produce a
 * chunk or assembled file that looks complete but is not.
 */
class LocalUploadStorage implements UploadStorage
{
    /** 1 MiB read buffer: assembly of a 10 GB file never loads it into memory. */
    private const BUFFER_BYTES = 1048576;

    public function __construct(
        protected string $diskName = 'edms',
    ) {}

    public function putChunk(string $path, string $sourcePath, ?string $expectedSha256 = null): void
    {
        $this->makeDirectory(dirname($path));

        $partPath = $this->localPath($path.'.part');
        $finalPath = $this->localPath($path);

        $source = @fopen($sourcePath, 'rb');
        $target = @fopen($partPath, 'wb');

        if ($source === false || $target === false) {
            is_resource($source) && fclose($source);
            is_resource($target) && fclose($target);
            @unlink($partPath);

            throw new RuntimeException('Unable to store the chunk on disk.');
        }

        $copied = false;
        $failed = null;

        try {
            $copied = stream_copy_to_stream($source, $target) !== false;

            if ($copied) {
                fflush($target);
                function_exists('fsync') && @fsync($target);
            }
        } catch (\Throwable $e) {
            $failed = $e;
        } finally {
            fclose($source);
            fclose($target);
        }

        if ($failed !== null || ! $copied) {
            @unlink($partPath);

            throw new RuntimeException('Unable to store the chunk on disk.', 0, $failed);
        }

        if ($expectedSha256 !== null && ! $this->hashMatches($partPath, $expectedSha256)) {
            @unlink($partPath);

            throw new RuntimeException('The chunk failed its integrity check.');
        }

        if (! @rename($partPath, $finalPath)) {
            @unlink($partPath);

            throw new RuntimeException('Unable to finalise the chunk on disk.');
        }
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
        $this->makeDirectory(dirname($destination));

        $finalPath = $this->localPath($destination);

        if ($this->exists($destination)) {
            $existingBytes = $this->size($destination);

            if ($existingBytes === $expectedBytes
                && ($expectedSha256 === null || $this->hashMatches($finalPath, $expectedSha256))) {
                return;
            }

            // A leftover from an earlier, failed attempt: rewrite it.
            $this->delete($destination);
        }

        $partPath = $finalPath.'.part';
        $target = @fopen($partPath, 'wb');

        if ($target === false) {
            throw new RuntimeException('Unable to create the assembled file on disk.');
        }

        $context = hash_init('sha256');
        $written = 0;
        $failed = null;

        try {
            foreach ($sourcePaths as $sourcePath) {
                $source = @fopen($this->localPath($sourcePath), 'rb');

                if ($source === false) {
                    throw new RuntimeException('A chunk is missing from the assembly set.');
                }

                try {
                    while (! feof($source)) {
                        $buffer = fread($source, self::BUFFER_BYTES);

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
                    fclose($source);
                }
            }

            fflush($target);
            function_exists('fsync') && @fsync($target);
        } catch (\Throwable $e) {
            $failed = $e;
        } finally {
            fclose($target);
        }

        if ($failed !== null) {
            @unlink($partPath);

            throw $failed instanceof RuntimeException
                ? $failed
                : new RuntimeException('Unable to assemble the uploaded file.', 0, $failed);
        }

        if ($written !== $expectedBytes) {
            @unlink($partPath);

            throw new UploadVerificationException("The assembled file is {$written} bytes but {$expectedBytes} were expected.");
        }

        if ($expectedSha256 !== null && ! hash_equals(strtolower($expectedSha256), hash_final($context))) {
            @unlink($partPath);

            throw new UploadVerificationException('The assembled file failed its integrity check.');
        }

        if (! @rename($partPath, $finalPath)) {
            @unlink($partPath);

            throw new RuntimeException('Unable to finalise the assembled file.');
        }
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function size(string $path): ?int
    {
        return $this->disk()->exists($path)
            ? (int) $this->disk()->size($path)
            : null;
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    public function deleteDirectory(string $directory): void
    {
        $this->disk()->deleteDirectory($directory);
    }

    /**
     * @return array<int, string>
     */
    public function directories(string $directory): array
    {
        return array_values(array_map(
            'basename',
            $this->disk()->directories($directory)
        ));
    }

    public function isEmpty(string $directory): bool
    {
        return $this->disk()->allFiles($directory) === [];
    }

    public function copy(string $source, string $destination): void
    {
        $this->disk()->copy($source, $destination);
    }

    public function readStream(string $path, int $offset = 0, ?int $length = null)
    {
        $absolute = $this->localPath($path);

        if ($absolute === null || ! is_file($absolute)) {
            return null;
        }

        $handle = @fopen($absolute, 'rb');

        if ($handle === false) {
            return null;
        }

        if ($offset > 0 && fseek($handle, $offset, SEEK_SET) !== 0) {
            fclose($handle);

            return null;
        }

        if ($length !== null) {
            // Trim the stream to the requested slice so the caller can read it
            // without knowing the length up front.
            $remaining = $length;

            $stream = @fopen('php://temp', 'w+b');

            if ($stream === false) {
                fclose($handle);

                return null;
            }

            while ($remaining > 0 && ! feof($handle)) {
                $buffer = fread($handle, (int) min(self::BUFFER_BYTES, $remaining));

                if ($buffer === false || $buffer === '') {
                    break;
                }

                fwrite($stream, $buffer);
                $remaining -= strlen($buffer);
            }

            fclose($handle);
            rewind($stream);

            return $stream;
        }

        return $handle;
    }

    public function readHead(string $path, int $bytes): ?string
    {
        $handle = $this->readStream($path, 0, $bytes);

        if (! is_resource($handle)) {
            return null;
        }

        try {
            $head = $bytes > 0 ? fread($handle, $bytes) : '';
        } finally {
            fclose($handle);
        }

        return is_string($head) ? $head : null;
    }

    public function localPath(string $path): ?string
    {
        $disk = $this->disk();

        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            throw new RuntimeException('The ['.$this->diskName.'] disk must use the local driver for resumable uploads.');
        }

        return $disk->path($path);
    }

    protected function makeDirectory(string $directory): void
    {
        $this->disk()->makeDirectory($directory);
    }

    protected function hashMatches(string $absolutePath, string $expectedSha256): bool
    {
        $actual = hash_file('sha256', $absolutePath);

        return is_string($actual) && hash_equals(strtolower($expectedSha256), $actual);
    }

    protected function disk(): FilesystemAdapter
    {
        return Storage::disk($this->diskName);
    }
}
