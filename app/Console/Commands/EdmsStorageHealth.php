<?php

namespace App\Console\Commands;

use App\Models\StorageSpace;
use App\Services\Uploads\StorageDriverRegistry;
use App\Services\Uploads\UploadStorageFactory;
use Illuminate\Console\Command;
use Throwable;

/**
 * Liveness check for the configured byte store (and optionally every driver
 * or every space with external storage).
 *
 *   php artisan edms:storage-health
 *   php artisan edms:storage-health --all
 *   php artisan edms:storage-health --spaces
 */
class EdmsStorageHealth extends Command
{
    protected $signature = 'edms:storage-health
        {--all : Check every documented driver, not just the configured one}
        {--spaces : Check every space that has external storage configured}
        {--driver= : Override the driver to check}';

    protected $description = 'Check whether the upload byte store can be reached';

    public function handle(UploadStorageFactory $factory, StorageDriverRegistry $registry): int
    {
        $allOk = true;

        if ($this->option('spaces')) {
            $allOk = $this->checkSpaces($factory);
        } else {
            $drivers = $this->option('all')
                ? $registry->keys()
                : [is_string($this->option('driver')) && $this->option('driver') !== ''
                    ? $this->option('driver')
                    : (string) config('uploads.storage_driver', 'local')];

            $rows = [];

            foreach ($drivers as $driver) {
                $ok = $factory->check($driver);
                $allOk = $allOk && $ok;

                $rows[] = [$driver, $ok ? '<fg=green>ok</>' : '<fg=red>unreachable</>'];
            }

            $this->table(['Driver', 'Status'], $rows);
        }

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    protected function checkSpaces(UploadStorageFactory $factory): bool
    {
        $spaces = StorageSpace::query()
            ->whereNotNull('storage_driver')
            ->where('storage_driver', '!=', 'local')
            ->orderBy('name')
            ->get();

        if ($spaces->isEmpty()) {
            $this->info('No spaces have external storage configured.');

            return true;
        }

        $rows = [];
        $allOk = true;

        foreach ($spaces as $space) {
            try {
                $ok = $factory->makeForSpace($space)->healthCheck();
            } catch (Throwable) {
                $ok = false;
            }

            $allOk = $allOk && $ok;

            $rows[] = [
                $space->id,
                $space->name ?? 'Space #'.$space->id,
                (string) $space->storage_driver,
                $ok ? '<fg=green>ok</>' : '<fg=red>unreachable</>',
            ];
        }

        $this->table(['ID', 'Space', 'Driver', 'Status'], $rows);

        return $allOk;
    }
}
