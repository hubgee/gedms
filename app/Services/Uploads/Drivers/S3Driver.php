<?php

namespace App\Services\Uploads\Drivers;

use App\Contracts\UploadStorage;
use App\Services\Uploads\S3UploadStorage;
use Illuminate\Support\Facades\Storage;

/**
 * Any S3-compatible object store: AWS S3, MinIO, Cloudflare R2,
 * DigitalOcean Spaces, Wasabi, Backblaze B2, Ceph, and so on.
 */
class S3Driver extends AbstractStorageDriver
{
    public function key(): string
    {
        return 's3';
    }

    public function label(): string
    {
        return 'S3-compatible object storage';
    }

    public function description(): string
    {
        return 'AWS S3, MinIO, Cloudflare R2, DigitalOcean Spaces, Wasabi, Backblaze B2 or any other S3 API. Credentials are encrypted per space.';
    }

    public function fields(): array
    {
        $defaults = $this->diskDefaults();

        return [
            [
                'key' => 'bucket',
                'label' => 'Bucket',
                'type' => 'text',
                'placeholder' => 'edms-files',
                'help' => 'The bucket must already exist.',
            ],
            [
                'key' => 'region',
                'label' => 'Region',
                'type' => 'text',
                'default' => $defaults['region'] ?? 'us-east-1',
                'placeholder' => 'us-east-1',
            ],
            [
                'key' => 'key',
                'label' => 'Access key',
                'type' => 'text',
                'placeholder' => 'Leave blank to use AWS_ACCESS_KEY_ID',
            ],
            [
                'key' => 'secret',
                'label' => 'Secret key',
                'type' => 'password',
                'secret' => true,
                'placeholder' => 'Leave blank to use AWS_SECRET_ACCESS_KEY',
            ],
            [
                'key' => 'endpoint',
                'label' => 'Endpoint',
                'type' => 'url',
                'placeholder' => 'https://s3.example.gov.mw',
                'help' => 'Leave blank for AWS. Required for MinIO, R2, Spaces and most self-hosted gateways.',
            ],
            [
                'key' => 'root',
                'label' => 'Root prefix',
                'type' => 'text',
                'placeholder' => 'edms',
                'help' => 'Optional prefix inside the bucket.',
            ],
            [
                'key' => 'use_path_style',
                'label' => 'Use path-style endpoint',
                'type' => 'checkbox',
                'help' => 'MinIO and most self-hosted gateways need this.',
            ],
            [
                'key' => 'cloud_disk',
                'label' => 'Existing disk name',
                'type' => 'text',
                'placeholder' => 'edms-cloud',
                'help' => 'Only used when the bucket above is empty. Lets an existing config/filesystems.php disk be reused.',
                'advanced' => true,
            ],
        ];
    }

    public function build(array $config): UploadStorage
    {
        $bucket = $this->firstFilled($config['bucket'] ?? null);
        $disk = $this->firstFilled($config['cloud_disk'] ?? null, config('uploads.cloud_disk', 'edms-cloud'));

        if ($bucket === '') {
            return new S3UploadStorage($disk);
        }

        $defaults = $this->diskDefaults();
        $endpoint = $this->firstFilled($config['endpoint'] ?? null, $defaults['endpoint'] ?? '');
        $root = trim((string) ($config['root'] ?? ''), '/');

        $built = Storage::build(array_filter([
            'driver' => 's3',
            'key' => $this->firstFilled($config['key'] ?? null, $defaults['key'] ?? ''),
            'secret' => $this->firstFilled($config['secret'] ?? null, $defaults['secret'] ?? ''),
            'region' => $this->firstFilled($config['region'] ?? null, $defaults['region'] ?? 'us-east-1'),
            'bucket' => $bucket,
            'endpoint' => $endpoint !== '' ? $endpoint : null,
            'use_path_style_endpoint' => (bool) ($config['use_path_style'] ?? $defaults['use_path_style_endpoint'] ?? false),
            'root' => $root,
            'throw' => true,
            'report' => false,
        ], static fn ($value) => $value !== null));

        return new S3UploadStorage($built);
    }

    public function validate(array $config): array
    {
        $bucket = $this->firstFilled($config['bucket'] ?? null);
        $disk = $this->firstFilled($config['cloud_disk'] ?? null);

        if ($bucket === '' && $disk === '') {
            return ['Enter a bucket name, or the name of an existing S3 disk.'];
        }

        return [];
    }

    /**
     * Defaults from the configured S3 disk, so a blank field reuses the
     * deployment's existing AWS configuration. Reading config rather than env()
     * also keeps the driver correct when the configuration is cached.
     *
     * @return array<string, mixed>
     */
    protected function diskDefaults(): array
    {
        $disk = (string) config('uploads.cloud_disk', 'edms-cloud');

        return (array) config('filesystems.disks.'.$disk, []);
    }
}
