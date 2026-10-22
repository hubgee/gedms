<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

/**
 * Runs the upload chaos harness across every fault profile in one pass and
 * writes a single evidence report.
 *
 * This is the Phase 2 soak entry point: one command produces the JSON artifact
 * a release or a nightly job can archive, instead of running each preset by
 * hand and stitching the output together.
 *
 *   php artisan edms:upload-soak --driver=s3 --bytes=67108864 --report=soak.json
 */
class EdmsUploadSoak extends Command
{
    protected $signature = 'edms:upload-soak
        {--driver= : Byte store to drive (local, s3, dropbox); defaults to the configured driver}
        {--bytes=67108864 : Size of the synthetic file}
        {--chunk-bytes=8388608 : Size of one chunk}
        {--attempts=3 : Retries per failing phase}
        {--presets= : Comma-separated preset names; defaults to every preset}
        {--report= : File to write the JSON report to (stdout when omitted)}
        {--keep : Leave the uploaded bytes in the store afterwards}';

    protected $description = 'Run the upload chaos harness across every fault profile and capture an evidence report';

    public function handle(): int
    {
        $presets = $this->resolvePresets();

        if ($presets === []) {
            $this->error('No presets to run.');

            return self::FAILURE;
        }

        $driver = $this->option('driver');
        $driver = is_string($driver) && $driver !== '' ? $driver : (string) config('uploads.storage_driver', 'local');

        $report = [
            'driver' => $driver,
            'total_bytes' => (int) $this->option('bytes'),
            'chunk_bytes' => (int) $this->option('chunk-bytes'),
            'attempts' => (int) $this->option('attempts'),
            'started_at' => now()->toIso8601String(),
            'presets' => [],
        ];

        $overallOk = true;

        foreach ($presets as $preset) {
            $this->info("Running preset: {$preset}");

            $arguments = [
                '--preset' => $preset,
                '--bytes' => (int) $this->option('bytes'),
                '--chunk-bytes' => (int) $this->option('chunk-bytes'),
                '--attempts' => (int) $this->option('attempts'),
                '--json' => true,
            ];

            if (is_string($driver) && $driver !== '') {
                $arguments['--driver'] = $driver;
            }

            if ($this->option('keep')) {
                $arguments['--keep'] = true;
            }

            try {
                $exitCode = \Illuminate\Support\Facades\Artisan::call('edms:upload-chaos', $arguments);
                $output = \Illuminate\Support\Facades\Artisan::output();
            } catch (Throwable $e) {
                $output = \Illuminate\Support\Facades\Artisan::output();
                file_put_contents(base_path('soak-raw-buffer.txt'), $e->getMessage()."\n".$output);
                $decoded = ['ok' => false, 'error' => $e->getMessage()];
                $report['presets'][$preset] = $decoded;
                $overallOk = false;
                $this->line(sprintf('  %s: FAIL (%s)', $preset, $e->getMessage()));

                continue;
            }

            $decoded = json_decode($output, true);

            if (! is_array($decoded)) {
                file_put_contents(base_path('soak-raw-buffer.txt'), $output);
                $decoded = ['ok' => false, 'error' => 'Unparseable harness output.', 'raw' => $output];
            }

            $report['presets'][$preset] = $decoded;

            $expectedFailure = $preset === 'quota-exhausted';
            $presetOk = $expectedFailure
                ? (($decoded['ok'] ?? false) === false)
                : ($decoded['ok'] ?? false);

            if (! $presetOk) {
                $overallOk = false;
            }

            $this->line(sprintf(
                '  %s: %s',
                $preset,
                $presetOk ? 'PASS' : 'FAIL',
            ));
        }

        $report['finished_at'] = now()->toIso8601String();
        $report['ok'] = $overallOk;

        $json = (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $reportPath = $this->option('report');

        if (is_string($reportPath) && $reportPath !== '') {
            $absolute = $this->absolutePath($reportPath);
            @mkdir(dirname($absolute), 0777, true);
            file_put_contents($absolute, $json);
            $this->info("Report written to: {$absolute}");
        } else {
            $this->line($json);
        }

        return $overallOk ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, string>
     */
    protected function resolvePresets(): array
    {
        $option = $this->option('presets');

        if (is_string($option) && $option !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $option))));
        }

        return array_keys(\App\Services\Uploads\CloudFaultProfile::presets());
    }

    protected function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return $path;
        }

        return base_path($path);
    }
}
