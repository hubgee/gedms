<?php

namespace App\Services\Uploads;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * The provider could not be reached, or asked us to slow down.
 *
 * This is deliberately distinct from a validation failure. A rate limit, a
 * timeout or a 5xx is transient: the browser should retry the same chunk. The
 * upload controller used to turn every storage RuntimeException into a 422,
 * which the client treats as permanent, so a momentary Dropbox rate limit
 * killed the whole upload. Rendering 503 keeps the retry behaviour honest.
 */
class StorageUnavailableException extends RuntimeException
{
    public function render(Request $request): JsonResponse|Response
    {
        $message = $this->getMessage() !== ''
            ? $this->getMessage()
            : 'The storage provider is temporarily unavailable. Please retry.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 503);
        }

        return response($message, 503);
    }
}
