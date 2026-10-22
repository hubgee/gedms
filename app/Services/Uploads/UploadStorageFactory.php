<?php

namespace App\Services\Uploads;

use App\Contracts\UploadStorage;
use App\Models\StorageSpace;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;
use Throwable;

/**
 * Builds the upload byte store that the pipeline will use.
 *
 * The driver is a configuration choice, so the same code runs against a local
 * disk, an S3-compatible bucket or Dropbox without a code change. When a cloud
 * fault profile is active, the real backend is wrapped in a fault injector, so
 * the pipeline cannot tell the difference between "the provider is slow" and
 * "the provider is broken".
 *
 * A space may carry its own configuration (storage_driver + storage_config);
 * makeForSpace() honours it, which is what makes per-space external storage
 * possible without a global switch.
 */
class UploadStorageFactory
{
    public function __construct(
        protected Application $app,
    ) {}

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
            return false;
        }
    }

    protected function driver(string $driver, array $overrides = []): UploadStorage
    {
        $get = fn (string $key, $default = null) => array_key_exists($key, $overrides)
            ? $overrides[$key]
            : config('uploads.'.$key, $default);

        return match ($driver) {
            'local' => new LocalUploadStorage((string) $get('local_disk', config('uploads.local_disk', 'edms'))),
            's3' => new S3UploadStorage((string) $get('cloud_disk', config('uploads.cloud_disk', 'edms-cloud'))),
            'dropbox' => $this->dropbox($overrides),
            default => throw new RuntimeException(
                'Unknown upload storage driver ["'.$driver.'"]. Use local, s3 or dropbox.'
            ),
        };
    }

    protected function dropbox(array $overrides = []): UploadStorage
    {
        $get = fn (string $key, $default = '') => array_key_exists($key, $overrides)
            ? $overrides[$key]
            : config('uploads.dropbox.'.$key, $default);

        $key = (string) $get('app_key', '');
        $secret = (string) $get('app_secret', '');
        $refresh = (string) $get('refresh_token', '');

        // Honour Docker-style *_FILE secret mounts.
        $key = $key !== '' ? $key : $this->secretFromFile('DROPBOX_APP_KEY');
        $secret = $secret !== '' ? $secret : $this->secretFromFile('DROPBOX_APP_SECRET');
        $refresh = $refresh !== '' ? $refresh : $this->secretFromFile('DROPBOX_REFRESH_TOKEN');

        if ($key === '' || $secret === '' || $refresh === '') {
            throw new RuntimeException(
                'The dropbox upload driver needs DROPBOX_APP_KEY, DROPBOX_APP_SECRET and DROPBOX_REFRESH_TOKEN.'
            );
        }

        $client = new DropboxClient(
            $key,
            $secret,
            $refresh,
            (string) $get('root', config('uploads.dropbox.root', '/edms')),
            (int) $get('timeout', config('uploads.dropbox.timeout', 120)),
            (string) $get('api_url', config('uploads.dropbox.api_url', 'https://api.dropboxapi.com/2')),
            (string) $get('content_url', config('uploads.dropbox.content_url', 'https://content.dropboxapi.com/2')),
        );

        return new DropboxUploadStorage(
            $client,
            (int) $get('verify_read_back_limit', config('uploads.dropbox.verify_read_back_limit', 67108864)),
        );
    }

    protected function secretFromFile(string $envKey): string
    {
        $file = env($envKey.'_FILE');

        if (is_string($file) && $file !== '' && is_readable($file)) {
            $contents = @file_get_contents($file);

            if (is_string($contents)) {
                return trim($contents);
            }
        }

        return '';
    }
}
