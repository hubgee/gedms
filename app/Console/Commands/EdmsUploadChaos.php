<?php

namespace App\Console\Commands;

use App\Contracts\UploadStorage;
use App\Services\Uploads\CloudFaultProfile;
use App\Services\Uploads\CloudFaults;
use App\Services\Uploads\UploadStorageFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drives a real multi-chunk upload against the configured byte store while a
 * fault profile is in force.
 *
 * The command is the executable half of the cloud storage test plan: it does
 * not mock anything, it performs the same sequence of operations the browser
 * uploader performs (write a chunk, assemble, read the head, read a range,
 * copy) against whatever the "UploadStorage" contract is currently bound to.
 *
 * Pointed at a local disk it proves the pipeline works. Pointed at MinIO,
 * AWS, R2 or Dropbox with a fault profile applied, it shows how the very same
 * code path behaves when the provider is slow, flaky, half-broken or full.
 *
 * Examples:
 *
 *   php artisan edms:upload-chaos --list
 *   php artisan edms:upload-chaos --preset=clean --bytes=1048576
 *   php artisan edms:upload-chaos --preset=flaky --driver=s3
 *   php artisan edms:upload-chaos --preset=drop-mid-transfer --json
 */
class EdmsUploadChaos extends Command
{
    protected $signature = 'edms:upload-chaos
        {--preset= : Fault profile to apply (see --list)}
        {--list : List the available fault profiles and exit}
        {--driver= : Byte store to drive (local, s3, dropbox); defaults to the configured driver}
        {--bytes=1048576 : Size of the synthetic file}
        {--chunk-bytes=65536 : Size of one chunk}
        {--attempts=3 : How often a failing operation is retried before it is reported}
        {--keep : Leave the uploaded bytes in the store afterwards}
        {--json : Print the report as JSON}';

    protected $description = 'Upload a synthetic file through the byte store under a simulated cloud fault profile';

    public function handle(UploadStorageFactory $factory): int
    {
        if ($this->option('list')) {
            $this->listPresets();

            return self::SUCCESS;
        }

        $profile = $this->resolveProfile();
        $driver = $this->resolveDriver();

        // The profile is pushed before the store is built, because the store
        // is wrapped in the fault injector at construction time.
        CloudFaults::push($profile);

        try {
            $storage = $factory->make($driver);
        } catch (Throwable $e) {
            $this->error('Could not build the byte store: '.$e->getMessage());

            return self::FAILURE;
        }

        $report = $this->runRoundTrip($storage, $profile, $driver);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($report);
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The whole round trip: write every chunk, assemble, verify, read back.
     *
     * @return array<string, mixed>
     */
    protected function runRoundTrip(UploadStorage $storage, CloudFaultProfile $profile, string $driver): array
    {
        $totalBytes = max(1, (int) $this->option('bytes'));
        $chunkBytes = max(1, (int) $this->option('chunk-bytes'));
        $attempts = max(1, (int) $this->option('attempts'));

        $runId = 'chaos-'.Str::lower(Str::random(10));
        $prefix = 'chaos/'.$runId;
        $assembled = $prefix.'/assembled.bin';
        $copy = $prefix.'/copy.bin';

        $content = $this->syntheticContent($totalBytes);
        $expectedSha = hash('sha256', $content);

        $report = [
            'ok' => false,
            'driver' => $driver,
            'profile' => $profile->toArray(),
            'total_bytes' => $totalBytes,
            'chunk_bytes' => $chunkBytes,
            'chunks' => (int) ceil($totalBytes / $chunkBytes),
            'expected_sha256' => $expectedSha,
            'phases' => [],
        ];

        // 1. Write every chunk.
        $chunkPaths = [];
        $chunkFailures = 0;
        $chunkAttempts = 0;
        $chunkError = null;

        for ($index = 0; $index < $report['chunks']; $index++) {
            $piece = substr($content, $index * $chunkBytes, $chunkBytes);
            $path = $prefix.'/chunk-'.$index.'.chunk';

            $source = $this->temporaryFile($piece);
            $result = $this->attempt(
                fn () => $storage->putChunk($path, $source, hash('sha256', $piece)),
                $attempts,
            );

            @unlink($source);

            $chunkAttempts += $result['attempts'];

            if (! $result['ok']) {
                $chunkFailures++;
                $chunkError = $result['error'];

                continue;
            }

            $chunkPaths[] = $path;
        }

        $report['phases']['put_chunk'] = [
            'ok' => $chunkFailures === 0,
            'written' => count($chunkPaths),
            'failed' => $chunkFailures,
            'attempts' => $chunkAttempts,
            'error' => $chunkError,
        ];

        if ($chunkFailures > 0) {
            $report['error'] = "{$chunkFailures} chunk(s) could not be stored after {$attempts} attempt(s)."
                .($chunkError ? ' Last error: '.$chunkError : '');

            $this->cleanup($storage, $prefix, $chunkPaths, $assembled, $copy);

            return $report;
        }

        // 2. Assemble the chunks into one object.
        $assemble = $this->attempt(
            fn () => $storage->assemble($assembled, $chunkPaths, $totalBytes, $expectedSha),
            $attempts,
        );

        $report['phases']['assemble'] = [
            'ok' => $assemble['ok'],
            'attempts' => $assemble['attempts'],
            'error' => $assemble['error'],
        ];

        if (! $assemble['ok']) {
            $report['error'] = $assemble['error'] ?? 'The assembly failed.';

            $this->cleanup($storage, $prefix, $chunkPaths, $assembled, $copy);

            return $report;
        }

        // 3. Read the head back: this is what the executable-payload check and
        //    the MIME sniff use, so it has to work on a cloud store too.
        $head = $storage->readHead($assembled, 8192);

        $report['phases']['read_head'] = [
            'ok' => is_string($head) && $head !== '',
            'bytes' => is_string($head) ? strlen($head) : 0,
        ];

        // 4. Read a slice: previewing a 10 GB object must not download it.
        $slice = $storage->readStream($assembled, 1024, 4096);
        $sliceBytes = 0;

        if (is_resource($slice)) {
            while (! feof($slice)) {
                $buffer = fread($slice, 8192);

                if ($buffer === false || $buffer === '') {
                    break;
                }

                $sliceBytes += strlen($buffer);
            }

            fclose($slice);
        }

        $report['phases']['read_range'] = [
            'ok' => $sliceBytes === 4096,
            'bytes' => $sliceBytes,
        ];

        // 5. Copy the finished file and confirm the copy is identical.
        $copied = $this->attempt(
            fn () => $storage->copy($assembled, $copy),
            $attempts,
        );

        $copyMatches = false;

        if ($copied['ok']) {
            $copyMatches = $this->hashOf($storage, $copy) === $expectedSha;
        }

        $report['phases']['copy'] = [
            'ok' => $copied['ok'] && $copyMatches,
            'attempts' => $copied['attempts'],
            'identical' => $copyMatches,
            'error' => $copied['error'],
        ];

        // 6. The assembled bytes must be the bytes that were sent.
        $actualSha = $this->hashOf($storage, $assembled);
        $report['actual_sha256'] = $actualSha;
        $report['verified'] = $actualSha === $expectedSha;

        $report['ok'] = $report['verified']
            && ($report['phases']['read_head']['ok'] ?? false)
            && ($report['phases']['read_range']['ok'] ?? false)
            && ($report['phases']['copy']['ok'] ?? false);

        if (! $report['ok']) {
            $report['error'] = $report['error'] ?? 'The round trip did not come back intact.';
        }

        if (! $this->option('keep')) {
            $this->cleanup($storage, $prefix, $chunkPaths, $assembled, $copy);
        } else {
            $report['kept'] = $prefix;
        }

        return $report;
    }

    /**
     * Run an operation, retrying it the way the browser uploader does.
     *
     * @param  callable(): mixed  $operation
     * @return array{ok: bool, attempts: int, error: ?string}
     */
    protected function attempt(callable $operation, int $attempts): array
    {
        $error = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $operation();

                return ['ok' => true, 'attempts' => $attempt, 'error' => null];
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return ['ok' => false, 'attempts' => $attempts, 'error' => $error];
    }

    /**
     * The SHA-256 of a stored object, read back through the contract.
     */
    protected function hashOf(UploadStorage $storage, string $path): ?string
    {
        $stream = $storage->readStream($path);

        if (! is_resource($stream)) {
            return null;
        }

        $context = hash_init('sha256');

        try {
            while (! feof($stream)) {
                $buffer = fread($stream, 1048576);

                if ($buffer === false || $buffer === '') {
                    break;
                }

                hash_update($context, $buffer);
            }
        } finally {
            fclose($stream);
        }

        return hash_final($context);
    }

    /**
     * Deterministic content, so two runs are comparable.
     */
    protected function syntheticContent(int $bytes): string
    {
        $block = str_repeat('edms-chaos-', 1024);
        $content = '';

        while (strlen($content) < $bytes) {
            $content .= $block;
        }

        return substr($content, 0, $bytes);
    }

    /**
     * Write bytes to a temporary file the store can read from.
     */
    protected function temporaryFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'edms-chaos-');

        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary file.');
        }

        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Remove everything the run created.
     *
     * @param  array<int, string>  $chunkPaths
     */
    protected function cleanup(UploadStorage $storage, string $prefix, array $chunkPaths, string $assembled, string $copy): void
    {
        foreach (array_merge($chunkPaths, [$assembled, $copy]) as $path) {
            try {
                $storage->delete($path);
            } catch (Throwable) {
                // Leftovers are harmless; the run is over either way.
            }
        }

        try {
            $storage->deleteDirectory($prefix);
        } catch (Throwable) {
            // Same.
        }
    }

    /**
     * The profile to apply: the named preset, or whatever the configuration
     * says when no preset is given.
     */
    protected function resolveProfile(): CloudFaultProfile
    {
        $preset = $this->option('preset');

        if (is_string($preset) && $preset !== '') {
            return CloudFaults::preset($preset);
        }

        return CloudFaults::fromConfig();
    }

    protected function resolveDriver(): string
    {
        $driver = $this->option('driver');

        if (is_string($driver) && $driver !== '') {
            return $driver;
        }

        return (string) config('uploads.storage_driver', 'local');
    }

    protected function listPresets(): void
    {
        $this->line('Available fault profiles:');
        $this->newLine();

        foreach (CloudFaultProfile::presets() as $name => $settings) {
            $profile = CloudFaultProfile::fromArray($settings);

            $this->line(sprintf(
                '  <options=bold>%s</>  %s',
                $name,
                $this->describe($profile),
            ));
        }

        $this->newLine();
        $this->line('Run one with: php artisan edms:upload-chaos --preset=<name>');
    }

    protected function describe(CloudFaultProfile $profile): string
    {
        $parts = [];

        if ($profile->latencyMs > 0) {
            $parts[] = "{$profile->latencyMs}ms latency";
        }

        if ($profile->jitterMs > 0) {
            $parts[] = "{$profile->jitterMs}ms jitter";
        }

        if ($profile->bandwidthBytesPerSecond !== null) {
            $parts[] = round($profile->bandwidthBytesPerSecond / 1024).' KB/s';
        }

        if ($profile->failureRate > 0.0) {
            $parts[] = round($profile->failureRate * 100).'% failures';
        }

        if ($profile->dropAfterBytes !== null) {
            $parts[] = 'cut at '.$profile->dropAfterBytes.' bytes';
        }

        if ($profile->quotaExceeded) {
            $parts[] = 'quota exhausted';
        }

        if ($profile->eventualConsistencyReads > 0) {
            $parts[] = "{$profile->eventualConsistencyReads} stale read(s)";
        }

        return $parts === [] ? 'no faults' : implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function render(array $report): void
    {
        $this->line("Driver:  {$report['driver']}");
        $this->line('Profile: '.$this->describe(CloudFaultProfile::fromArray($report['profile'])));
        $this->line(sprintf(
            'Payload: %d bytes in %d chunk(s) of %d bytes',
            $report['total_bytes'],
            $report['chunks'],
            $report['chunk_bytes'],
        ));
        $this->newLine();

        $rows = [];

        foreach ($report['phases'] as $phase => $result) {
            $rows[] = [
                $phase,
                ($result['ok'] ?? false) ? '<fg=green>ok</>' : '<fg=red>failed</>',
                (string) ($result['attempts'] ?? '-'),
                (string) ($result['error'] ?? ''),
            ];
        }

        $this->table(['Phase', 'Result', 'Attempts', 'Detail'], $rows);

        if ($report['verified'] ?? false) {
            $this->info('The assembled file matches the bytes that were sent.');
        } else {
            $this->error('The assembled file does NOT match the bytes that were sent.');
        }

        if (isset($report['error'])) {
            $this->error($report['error']);
        }

        if (isset($report['kept'])) {
            $this->line('Kept the bytes at: '.$report['kept']);
        }
    }
}
