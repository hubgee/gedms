<?php

namespace App\Console\Commands;

use App\Services\Uploads\UploadStorageFactory;
use Illuminate\Console\Command;

/**
 * Liveness check for the configured byte store (and optionally every driver).
 *
 *   php artisan edms:storage-health
 *   php artisan edms:storage-health --all
 */
class EdmsStorageHealth extends Command
{
    protected $signature = 'edms:storage-health
        {--all : Check every documented driver, not just the configured one}
        {--driver= : Override the driver to check}';

    protected $description = 'Check whether the upload byte store can be reached';

    public function handle(UploadStorageFactory $factory): int
    {
        $drivers = $this->option('all')
            ? ['local', 's3', 'dropbox']
            : [is_string($this->option('driver')) && $this->option('driver') !== ''
                ? $this->option('driver')
                : (string) config('uploads.storage_driver', 'local')];

        $rows = [];
        $allOk = true;

        foreach ($drivers as $driver) {
            $ok = $factory->check($driver);
            $allOk = $allOk && $ok;

            $rows[] = [$driver, $ok ? '<fg=green>ok</>' : '<fg=red>unreachable</>'];
        }

        $this->table(['Driver', 'Status'], $rows);

        return $allOk ? self::SUCCESS : self::FAILURE;
    }
}
