<?php

namespace App\Services\Uploads;

use App\Contracts\ReportsStorageQuota;
use App\Models\File;
use App\Models\StorageSpace;
use Throwable;

/**
 * Usage and quota reporting for a space.
 *
 * The application keeps the authoritative size of every file it has written
 * (files.size), so the used figure is a cheap database sum rather than a
 * provider-side walk of the bucket. Provider allocation is only consulted on
 * demand, because asking Dropbox costs a network round trip and S3 has no
 * portable quota API at all.
 */
class StorageUsageReporter
{
    public function __construct(
        protected UploadStorageFactory $factory,
    ) {}

    /**
     * @return array{
     *     files: int,
     *     used_bytes: int,
     *     quota_bytes: int|null,
     *     provider_used_bytes: int|null,
     *     provider_quota_bytes: int|null,
     *     percent: float|null,
     *     reachable: bool|null
     * }
     */
    public function forSpace(StorageSpace $space, bool $probeProvider = false): array
    {
        $query = File::query()->where('storage_space_id', $space->id);

        $files = (int) (clone $query)->count();
        $used = (int) (clone $query)->sum('size');

        $config = $space->storageConfig() ?? [];
        $quota = $this->positiveInteger($config['quota_bytes'] ?? null)
            ?? $this->positiveInteger(config('uploads.quota_bytes'));

        $providerUsed = null;
        $providerQuota = null;
        $reachable = null;

        if ($probeProvider) {
            try {
                $storage = $this->factory->makeForSpace($space);
                $reachable = $storage->healthCheck();

                if ($reachable && $storage instanceof ReportsStorageQuota) {
                    $usage = $storage->spaceUsage();
                    $providerUsed = $usage['used_bytes'] ?? null;
                    $providerQuota = $usage['allocated_bytes'] ?? null;
                }
            } catch (Throwable) {
                $reachable = false;
            }
        }

        $quota = $quota ?? $providerQuota;

        $percent = ($quota !== null && $quota > 0)
            ? round(min(100, ($used / $quota) * 100), 1)
            : null;

        return [
            'files' => $files,
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'provider_used_bytes' => $providerUsed,
            'provider_quota_bytes' => $providerQuota,
            'percent' => $percent,
            'reachable' => $reachable,
        ];
    }

    protected function positiveInteger(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
