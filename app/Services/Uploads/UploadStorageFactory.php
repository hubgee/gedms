<?php

namespace App\Services\Uploads;

use App\Contracts\UploadStorage;
use App\Models\StorageSpace;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Builds the upload byte store that the pipeline will use.
 *
 * The driver is a configuration choice, so the same code runs against a local
 * disk, an S3-compatible bucket, Dropbox, Google Drive, OneDrive or any driver
 * registered in config/uploads.php. When a cloud fault profile is active, the
 * real backend is wrapped in a fault injector, so the pipeline cannot tell the
 * difference between "the provider is slow" and "the provider is broken".
 *
 * A space may carry its own configuration (storage_driver + storage_config);
 * makeForSpace() honours it, which is what makes per-space external storage
 * possible without a global switch.
 */
class UploadStorageFactory
{
    protected StorageDriverRegistry $registry;

    public function __construct(
        protected Application $app,
        ?StorageDriverRegistry $registry = null,
    ) {
        $this->registry = $registry ?? $app->make(StorageDriverRegistry::class);
    }

    public function make(?string $driver = null): UploadStorage
    {
        $storage = $this->driver($driver ?? (string) config('uploads.storage_driver', 'local'));

        $profile = CloudFaults::current();

        return $profile->isActive()
            ? new FaultInjectingUploadStorage($storage, $profile)
            : $storage;
    }

    /**
     * The backend without the fault injector.
     */
    public function makeBackend(?string $driver = null): UploadStorage
    {
        return $this->driver($driver ?? (string) config('uploads.storage_driver', 'local'));
    }

    /**
     * Build the byte store for a specific space, honouring any external
     * storage configuration attached to it.
     */
    public function makeForSpace(?StorageSpace $space = null): UploadStorage
    {
        $hasConfig = $space && ($space->storage_driver || $space->storageConfig());

        if (! $hasConfig && $this->app->resolved(UploadStorage::class)) {
            return $this->app->make(UploadStorage::class);
        }

        $driver = (string) ($space?->storage_driver ?: config('uploads.storage_driver', 'local'));
        $overrides = $space?->storageConfig() ?? [];

        $storage = $this->driver($driver, $overrides);

        $profile = CloudFaults::current();

        return $profile->isActive()
            ? new FaultInjectingUploadStorage($storage, $profile)
            : $storage;
    }

    /**
     * Liveness probe for a driver: build the backend and ask it whether it
     * can be reached. Never throws.
     */
    public function check(?string $driver = null, array $overrides = []): bool
    {
        try {
            return $this->driver($driver ?? (string) config('uploads.storage_driver', 'local'), $overrides)->healthCheck();
        } catch (Throwable $e) {
            Log::warning('Upload storage health check failed', [
                'driver' => $driver,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function driver(string $driver, array $overrides = []): UploadStorage
    {
        if (! $this->registry->has($driver)) {
            throw new RuntimeException(
                'Unknown upload storage driver ["'.$driver.'"]. Configure it under uploads.drivers.'
            );
        }

        return $this->registry->get($driver)->build($overrides);
    }
}
