<?php

namespace App\Services\Uploads;

use App\Contracts\UploadStorage;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * Object-storage implementation of the upload byte store.
 *
 * Works with any S3-compatible endpoint: AWS S3 itself, MinIO, Cloudflare R2,
 * DigitalOcean Spaces, Wasabi, Backblaze B2. Only the disk configuration
 * changes; the contract does not.
 *
 * Durability
 * ----------
 * The local implementation needs a ".part" file plus a rename, because a
 * crashed write can leave a truncated file that looks finished. S3 has no such
 * failure mode: a PUT either publishes the object complete or not at all. So
 * the chunk is written straight to its final key and no second copy is made.
 *
 * Assembly
 * --------
 * Chunks are joined with a multipart upload whose parts are server-side copies
 * ("UploadPartCopy"). The bytes never travel back through the application, so
 * assembling a 10 GB file costs one server-side copy per chunk instead of 20 GB
 * of download plus 10 GB of upload.
 *
 * When the parts are too small for a multipart copy (S3 refuses parts under
 * 5 MiB except the last one) the implementation falls back to streaming the
 * chunks back through the application and uploading the result. That path is
 * correct but expensive, and it is the path the test suite takes, because the
 * tests use deliberately tiny chunks.
 */
class S3UploadStorage implements UploadStorage
{
    /** S3 refuses parts below 5 MiB, except for the last part. */
    private const MIN_PART_BYTES = 5242880;

    /** A single server-side copy handles at most 5 GB. */
    private const MAX_COPY_BYTES = 5368709120;

    /** One multipart upload holds at most 10 000 parts. */
    private const MAX_PARTS = 10000;

    /** 1 MiB buffer: assembly of a 10 GB file never loads it into memory. */
    private const BUFFER_BYTES = 1048576;

    public function __construct(
        protected string $diskName = 'edms',
    ) {}

    public function putChunk(string $path, string $sourcePath, ?string $expectedSha256 = null): void
    {
        // The source file is the only copy of these bytes at this point, so it
        // is verified before it is sent. The object that lands in the bucket
        // holds exactly these bytes, so verifying the source verifies the
        // stored object without paying for a read-back.
        if ($expectedSha256 !== null && ! $this->hashMatches($sourcePath, $expectedSha256)) {
            throw new RuntimeException('The chunk failed its integrity check.');
        }

        $handle = @fopen($sourcePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read the chunk before uploading it.');
        }

        try {
            $this->disk()->writeStream($path, $handle);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to store the chunk on the object store.', 0, $e);
        } finally {
            fclose($handle);
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
        if ($sourcePaths === []) {
            throw new RuntimeException('A chunk is missing from the assembly set.');
        }

        // Idempotency: an assembly that already landed is left alone, so a
        // retried job does not copy a multi-gigabyte file a second time.
        if ($this->exists($destination)) {
            $existingBytes = $this->size($destination);

            if ($existingBytes === $expectedBytes
                && ($expectedSha256 === null || $this->hashMatches($destination, $expectedSha256))) {
                return;
            }

            $this->delete($destination);
        }

        if (count($sourcePaths) === 1) {
            $this->assembleSingle($destination, $sourcePaths[0], $expectedBytes, $expectedSha256);

            return;
        }

        if ($this->canMultipartCopy($sourcePaths)) {
            $this->assembleWithMultipartCopy($destination, $sourcePaths, $expectedBytes, $expectedSha256);

            return;
        }

        $this->assembleByStreaming($destination, $sourcePaths, $expectedBytes, $expectedSha256);
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function size(string $path): ?int
    {
        try {
            return $this->disk()->exists($path)
                ? (int) $this->disk()->size($path)
                : null;
        } catch (Throwable) {
            // A read-after-write lag or a throttled HEAD must not take the
            // whole request down: "no size" is the honest answer here.
            return null;
        }
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
        $names = [];

        foreach ($this->disk()->directories($directory) as $found) {
            $names[] = basename($found);
        }

        return array_values(array_unique($names));
    }

    public function isEmpty(string $directory): bool
    {
        try {
            return $this->disk()->allFiles($directory) === [];
        } catch (Throwable) {
            return false;
        }
    }

    public function copy(string $source, string $destination): void
    {
        try {
            // A server-side copy: the bytes never travel through the app.
            $this->disk()->copy($source, $destination);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to copy "'.$source.'".', 0, $e);
        }
    }

    public function readStream(string $path, int $offset = 0, ?int $length = null)
    {
        if ($offset < 0) {
            return null;
        }

        $options = [
            'Bucket' => $this->bucket(),
            'Key' => $this->key($path),
        ];

        // A ranged read keeps a preview of a 10 GB object cheap.
        if ($length !== null && $length > 0) {
            $options['Range'] = 'bytes='.$offset.'-'.($offset + $length - 1);
        } elseif ($offset > 0) {
            $options['Range'] = 'bytes='.$offset.'-';
        }

        try {
            $result = $this->client()->getObject($options);
        } catch (Throwable) {
            return null;
        }

        $body = $result['Body'] ?? null;

        if ($body === null) {
            return null;
        }

        // The AWS SDK returns a PSR-7 stream. A PHP resource is what the
        // callers expect, and the PSR-7 stream is already backed by the HTTP
        // response, so it is exposed as a resource instead of being copied
        // into memory: downloading a 10 GB object must not need 10 GB of RAM.
        return $this->resourceFor($body);
    }

    public function readHead(string $path, int $bytes): ?string
    {
        if ($bytes < 1 || ! $this->exists($path)) {
            return null;
        }

        // A ranged read, so inspecting the head of a 10 GB object costs one
        // small request instead of downloading the whole thing.
        try {
            $result = $this->client()->getObject([
                'Bucket' => $this->bucket(),
                'Key' => $this->key($path),
                'Range' => 'bytes=0-'.max(0, $bytes - 1),
            ]);
        } catch (Throwable) {
            return null;
        }

        $body = $result['Body'] ?? null;

        if ($body === null) {
            return null;
        }

        $head = $body->read($bytes);

        return is_string($head) ? $head : null;
    }

    public function localPath(string $path): ?string
    {
        // An object store has no local path. The pipeline must not depend on
        // one; see UploadStorage::readHead().
        return null;
    }

    /**
     * Expose a PSR-7 stream as a PHP resource.
     *
     * Guzzle's StreamWrapper does this without copying anything, so the
     * response is consumed as it arrives. When the helper is unavailable the
     * stream is copied into a temp file, which is correct but slower.
     *
     * @return resource|null
     */
    protected function resourceFor(StreamInterface $body)
    {
        if (class_exists(StreamWrapper::class)) {
            try {
                return StreamWrapper::getResource($body);
            } catch (Throwable) {
                // Fall through to the copying implementation below.
            }
        }

        $stream = @fopen('php://temp', 'w+b');

        if ($stream === false) {
            return null;
        }

        try {
            while (! $body->eof()) {
                $buffer = $body->read(self::BUFFER_BYTES);

                if ($buffer === false || $buffer === '') {
                    break;
                }

                fwrite($stream, $buffer);
            }
        } catch (Throwable) {
            fclose($stream);

            return null;
        }

        rewind($stream);

        return $stream;
    }

    /**
     * A single chunk is the whole file: copy it server-side.
     */
    protected function assembleSingle(
        string $destination,
        string $source,
        int $expectedBytes,
        ?string $expectedSha256,
    ): void {
        $sourceBytes = $this->size($source);

        if ($sourceBytes === null) {
            throw new RuntimeException('A chunk is missing from the assembly set.');
        }

        if ($sourceBytes !== $expectedBytes) {
            throw new UploadVerificationException(
                "The assembled file is {$sourceBytes} bytes but {$expectedBytes} were expected."
            );
        }

        if ($expectedSha256 !== null && ! $this->hashMatches($source, $expectedSha256)) {
            throw new UploadVerificationException('The assembled file failed its integrity check.');
        }

        try {
            // copy() on an S3 disk is a server-side CopyObject: the bytes are
            // never routed through the application.
            $this->disk()->copy($source, $destination);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to assemble the uploaded file.', 0, $e);
        }
    }

    /**
     * Join the chunks with a multipart upload made of server-side copies.
     */
    protected function assembleWithMultipartCopy(
        string $destination,
        array $sourcePaths,
        int $expectedBytes,
        ?string $expectedSha256,
    ): void {
        $bucket = $this->bucket();
        $client = $this->client();

        $created = $client->createMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $this->key($destination),
        ]);

        $uploadId = $created['UploadId'];

        try {
            $parts = [];

            foreach (array_values($sourcePaths) as $index => $source) {
                $part = $client->uploadPartCopy([
                    'Bucket' => $bucket,
                    'Key' => $this->key($destination),
                    'UploadId' => $uploadId,
                    'CopySource' => $bucket.'/'.$this->key($source),
                    'PartNumber' => $index + 1,
                ]);

                $parts[] = [
                    'PartNumber' => $index + 1,
                    'ETag' => $part['CopyPartResult']['ETag'],
                ];
            }

            $client->completeMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $this->key($destination),
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => $parts],
            ]);
        } catch (Throwable $e) {
            // Never leave an orphaned multipart upload behind: it keeps
            // costing storage until it is aborted.
            $this->abortMultipartUpload($client, $bucket, $destination, $uploadId);

            throw new RuntimeException('Unable to assemble the uploaded file.', 0, $e);
        }

        $this->verifyAssembled($destination, $expectedBytes, $expectedSha256);
    }

    /**
     * Fallback for chunk sizes S3 will not accept as multipart parts: read
     * the chunks back and upload the concatenation in one request.
     */
    protected function assembleByStreaming(
        string $destination,
        array $sourcePaths,
        int $expectedBytes,
        ?string $expectedSha256,
    ): void {
        $temporary = tempnam(sys_get_temp_dir(), 'edms-assemble-');

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
                $stream = $this->disk()->readStream($source);

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

            fflush($target);
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

            $handle = @fopen($temporary, 'rb');

            if ($handle === false) {
                throw new RuntimeException('Unable to read the assembled file.');
            }

            try {
                $this->disk()->writeStream($destination, $handle);
            } finally {
                fclose($handle);
            }
        } catch (Throwable $e) {
            @unlink($temporary);

            throw $e;
        }

        @unlink($temporary);
    }

    /**
     * Whether the chunks can be joined with a multipart copy.
     *
     * S3 requires every part but the last to be at least 5 MiB, and allows at
     * most 10 000 parts.
     */
    protected function canMultipartCopy(array $sourcePaths): bool
    {
        if (count($sourcePaths) > self::MAX_PARTS) {
            return false;
        }

        $last = count($sourcePaths) - 1;

        foreach (array_values($sourcePaths) as $index => $source) {
            $bytes = $this->size($source);

            if ($bytes === null) {
                return false;
            }

            if ($bytes > self::MAX_COPY_BYTES) {
                return false;
            }

            if ($index !== $last && $bytes < self::MIN_PART_BYTES) {
                return false;
            }
        }

        return true;
    }

    protected function verifyAssembled(string $destination, int $expectedBytes, ?string $expectedSha256): void
    {
        $actual = $this->size($destination);

        if ($actual !== $expectedBytes) {
            $this->delete($destination);

            throw new UploadVerificationException(
                "The assembled file is {$actual} bytes but {$expectedBytes} were expected."
            );
        }

        if ($expectedSha256 !== null && ! $this->hashMatches($destination, $expectedSha256)) {
            $this->delete($destination);

            throw new UploadVerificationException('The assembled file failed its integrity check.');
        }
    }

    protected function abortMultipartUpload(
        S3Client $client,
        string $bucket,
        string $destination,
        string $uploadId,
    ): void {
        try {
            $client->abortMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $this->key($destination),
                'UploadId' => $uploadId,
            ]);
        } catch (Throwable) {
            // Nothing useful can be done here; the multipart upload will be
            // reclaimed by the bucket's lifecycle rules.
        }
    }

    /**
     * Hash a stored object by streaming it back. Only used when the caller
     * supplied a hash, so the extra read is worth it.
     */
    protected function hashMatches(string $path, string $expectedSha256): bool
    {
        $stream = $this->disk()->readStream($path);

        if ($stream === null) {
            return false;
        }

        $context = hash_init('sha256');

        try {
            while (! feof($stream)) {
                $buffer = fread($stream, self::BUFFER_BYTES);

                if ($buffer === false) {
                    return false;
                }

                if ($buffer === '') {
                    break;
                }

                hash_update($context, $buffer);
            }
        } finally {
            fclose($stream);
        }

        return hash_equals(strtolower($expectedSha256), hash_final($context));
    }

    protected function hashMatchesFile(string $absolutePath, string $expectedSha256): bool
    {
        $actual = hash_file('sha256', $absolutePath);

        return is_string($actual) && hash_equals(strtolower($expectedSha256), $actual);
    }

    protected function disk(): FilesystemAdapter
    {
        $disk = Storage::disk($this->diskName);

        if (! method_exists($disk, 'getClient')) {
            throw new RuntimeException(
                'The ['.$this->diskName.'] disk must use the s3 driver for object-storage uploads.'
            );
        }

        return $disk;
    }

    protected function client(): S3Client
    {
        $client = $this->disk()->getClient();

        if (! $client instanceof S3Client) {
            throw new RuntimeException(
                'The ['.$this->diskName.'] disk must use the s3 driver for object-storage uploads.'
            );
        }

        return $client;
    }

    protected function bucket(): string
    {
        $bucket = $this->disk()->getConfig()['bucket'] ?? null;

        if (! is_string($bucket) || $bucket === '') {
            throw new RuntimeException('The ['.$this->diskName.'] disk has no bucket configured.');
        }

        return $bucket;
    }

    /** Full object key, including the disk's root prefix. */
    protected function key(string $path): string
    {
        $root = trim((string) ($this->disk()->getConfig()['root'] ?? ''), '/');

        return $root === '' ? ltrim($path, '/') : $root.'/'.ltrim($path, '/');
    }
}
