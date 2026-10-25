<?php

namespace App\Services\Uploads\Drivers;

use App\Contracts\UploadStorage;
use App\Services\Uploads\LocalUploadStorage;

class LocalDriver extends AbstractStorageDriver
{
    public function key(): string
    {
        return 'local';
    }

    public function label(): string
    {
        return 'Local disk';
    }

    public function description(): string
    {
        return 'Files stay on this server. This is the default when a space has no external storage.';
    }

    public function fields(): array
    {
        return [
            [
                'key' => 'local_disk',
                'label' => 'Disk name',
                'type' => 'text',
                'default' => config('uploads.local_disk', 'edms'),
                'help' => 'A disk from config/filesystems.php. The default "edms" disk is correct for almost every installation.',
                'advanced' => true,
            ],
        ];
    }

    public function build(array $config): UploadStorage
    {
        return new LocalUploadStorage(
            (string) $this->value($config, 'local_disk', config('uploads.local_disk', 'edms'))
        );
    }
}
