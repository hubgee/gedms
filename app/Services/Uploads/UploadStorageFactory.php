<?php

namespace App\Services\Uploads;

use App\Contracts\UploadStorage;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * Builds the upload byte store that the pipeline will use.
 *
 * The driver is a configuration choice, so the same code runs against a local
 * disk, an S3-compatible bucket or Dropbox without a code change. When a cloud
 * fault profile is active, the real backend is wrapped in a fault injector, so
 * the pipeline cannot tell the difference between "the provider is slow" and
 * "the provider is broken".
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

    protected function driver(string $driver): UploadStorage
    {
        return match ($driver) {
            'local' => new LocalUploadStorage((string) config('uploads.local_disk', 'edms')),
            's3' => new S3UploadStorage((string) config('uploads.cloud_disk', 'edms-cloud')),
            'dropbox' => $this->dropbox(),
            default => throw new RuntimeException(
                'Unknown upload storage driver ["'.$driver.'"]. Use local, s3 or dropbox.'
            ),
        };
    }

    protected function dropbox(): UploadStorage
    {
        $key = (string) config('uploads.dropbox.app_key', '');
        $secret = (string) config('uploads.dropbox.app_secret', '');
        $refresh = (string) config('uploads.dropbox.refresh_token', '');

        if ($key === '' || $secret === '' || $refresh === '') {
            throw new RuntimeException(
                'The dropbox upload driver needs DROPBOX_APP_KEY, DROPBOX_APP_SECRET and DROPBOX_REFRESH_TOKEN.'
            );
        }

        $client = new DropboxClient(
            $key,
            $secret,
            $refresh,
            (string) config('uploads.dropbox.root', '/edms'),
            (int) config('uploads.dropbox.timeout', 120),
        );

        return new DropboxUploadStorage(
            $client,
            (int) config('uploads.dropbox.verify_read_back_limit', 67108864),
        );
    }
}
