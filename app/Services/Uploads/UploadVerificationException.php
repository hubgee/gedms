<?php

namespace App\Services\Uploads;

use RuntimeException;

/**
 * Thrown when the assembled bytes fail a policy check (integrity, destination
 * no longer valid, executable payload).
 *
 * Not recoverable by retrying: the job marks the session failed instead of
 * reading a multi-gigabyte file again.
 */
class UploadVerificationException extends RuntimeException {}
