<?php

namespace App\Services\Uploads;

use App\Models\UploadSession;
use RuntimeException;

/**
 * Thrown when an assembly starts but chunks have disappeared since the client
 * completed the upload (lost write, disk failure, manual cleanup).
 *
 * Recoverable: the session is put back to "uploading" so the client can send
 * only the missing chunks again.
 */
class UploadIncompleteException extends RuntimeException
{
    /**
     * @param  array<int, int>  $missing
     */
    public function __construct(
        public readonly UploadSession $session,
        public readonly array $missing,
    ) {
        parent::__construct(
            'The upload is missing '.count($missing).' chunk(s) and cannot be assembled.',
        );
    }
}
