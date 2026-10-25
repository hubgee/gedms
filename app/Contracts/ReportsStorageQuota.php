<?php

namespace App\Contracts;

/**
 * A backend that can report the provider's own usage and allocation.
 *
 * Implementing this is optional: local disks and S3 have no portable quota
 * API, while Dropbox, Google Drive and OneDrive do. Keeping it separate from
 * UploadStorage means the upload contract does not grow provider-specific
 * methods.
 */
interface ReportsStorageQuota
{
    /**
     * @return array{used_bytes: int|null, allocated_bytes: int|null}|null
     */
    public function spaceUsage(): ?array;
}
