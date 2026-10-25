<?php

namespace App\Services\Uploads;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Thin Dropbox API v2 client for the upload byte store.
 *
 * Only the handful of endpoints the upload pipeline needs are implemented:
 * simple upload, upload sessions (for the assembly), ranged download and
 * metadata. Everything else is out of scope.
 *
 * Authentication uses the refresh-token flow: the app secret and a long-lived
 * refresh token live in the environment, and the short-lived access token is
 * cached and renewed on demand. That keeps a long-running queue worker from
 * asking Dropbox for a new token on every single chunk.
 */
class DropboxClient
{
    protected const CONTENT = 'https://content.dropboxapi.com/2';

    protected const API = 'https://api.dropboxapi.com/2';

    protected const TOKEN_URL = 'https://api.dropboxapi.com/oauth2/token';

    protected const TOKEN_CACHE_KEY = 'edms.dropbox.access_token';

    /** @var array<string, true> Folders already created or verified in this request. */
    protected array $ensuredFolders = [];

    public function __construct(
        protected string $appKey,
        protected string $appSecret,
        protected string $refreshToken,
        protected string $root = '/edms',
        protected int $timeout = 120,
        protected string $apiUrl = 'https://api.dropboxapi.com/2',
        protected string $contentUrl = 'https://content.dropboxapi.com/2',
    ) {}

    /**
     * Translate an EDMS storage path into a Dropbox path.
     *
     * Dropbox paths always start with a slash and are case-insensitive but
     * case-preserving.
     */
    public function dropboxPath(string $path): string
    {
        $root = '/'.trim($this->root, '/');
        $relative = ltrim($path, '/');

        return $relative === '' ? $root : $root.'/'.$relative;
    }

    /**
     * Translate a Dropbox path back into an EDMS storage path.
     *
     * Directory listings return full Dropbox paths, so anything that turns a
     * listed entry back into a client call has to strip the root first or the
     * root ends up applied twice.
     */
    public function storagePath(string $path): string
    {
        $root = '/'.trim($this->root, '/');
        $path = '/'.ltrim($path, '/');

        if ($path === $root) {
            return '';
        }

        if (str_starts_with($path, $root.'/')) {
            return substr($path, strlen($root) + 1);
        }

        return ltrim($path, '/');
    }

    /**
     * Upload a local file in one request.
     *
     * Dropbox accepts up to 150 MB this way, which covers the default 8 MB
     * chunk size with room to spare.
     */
    public function upload(string $path, string $localFile): void
    {
        $this->ensureParentFolder($path);

        $handle = @fopen($localFile, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read the file for the Dropbox upload.');
        }

        try {
            $this->content('files/upload', [
                'path' => $this->dropboxPath($path),
                'mode' => 'overwrite',
                'autorename' => false,
                'mute' => true,
                'strict_conflict' => false,
            ], $handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Download an object into a local file.
     *
     * A range is requested when an offset or a length is given, so reading a
     * slice of a large file does not download all of it.
     */
    public function downloadTo(string $path, string $localFile, int $offset = 0, ?int $length = null): void
    {
        $headers = [
            'Dropbox-API-Arg' => $this->apiArg(['path' => $this->dropboxPath($path)]),
        ];

        if ($offset > 0 || $length !== null) {
            $end = $length !== null ? $offset + max(0, $length - 1) : null;

            $headers['Range'] = 'bytes='.$offset.'-'.($end ?? '');
        }

        $response = Http::withToken($this->accessToken())
            ->withHeaders($headers)
            ->withBody('', 'application/octet-stream')
            ->withOptions(['sink' => $localFile, 'timeout' => $this->timeout])
            ->post($this->contentUrl.'/files/download');

        $this->guard($response, 'download');
    }

    /**
     * Copy a file inside the account. Dropbox does the copy server-side.
     */
    public function copy(string $source, string $destination): void
    {
        $this->ensureParentFolder($destination);

        $response = $this->json('files/copy_v2', [
            'from_path' => $this->dropboxPath($source),
            'to_path' => $this->dropboxPath($destination),
            'autorename' => false,
        ]);

        $this->guard($response, 'copy');
    }

    /**
     * Read the first bytes of an object without downloading all of it.
     */
    public function readHead(string $path, int $bytes): ?string
    {
        $response = Http::withToken($this->accessToken())
            ->withHeaders([
                'Dropbox-API-Arg' => $this->apiArg(['path' => $this->dropboxPath($path)]),
                'Range' => 'bytes=0-'.max(0, $bytes - 1),
            ])
            ->withBody('', 'application/octet-stream')
            ->withOptions(['timeout' => $this->timeout])
            ->post($this->contentUrl.'/files/download');

        if (! $response->successful()) {
            return null;
        }

        return $response->body();
    }

    /**
     * Metadata for one path, or null when it does not exist.
     */
    public function metadata(string $path): ?array
    {
        return $this->metadataAbsolute($this->dropboxPath($path));
    }

    /**
     * Metadata for an absolute Dropbox path.
     *
     * @return array<string, mixed>|null
     */
    protected function metadataAbsolute(string $path): ?array
    {
        $response = $this->json('files/get_metadata', [
            'path' => $path,
            'include_deleted' => false,
        ]);

        if ($response->status() === 409 && $this->isNotFound($response)) {
            return null;
        }

        $this->guard($response, 'get_metadata');

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : null;
    }

    public function delete(string $path): void
    {
        $response = $this->json('files/delete_v2', [
            'path' => $this->dropboxPath($path),
        ]);

        if ($response->status() === 409 && $this->isNotFound($response)) {
            return;
        }

        $this->guard($response, 'delete');
    }

    /**
     * Immediate children of a folder.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listChildren(string $path): array
    {
        $response = $this->json('files/list_folder', [
            'path' => $this->dropboxPath($path),
            'recursive' => false,
            'include_deleted' => false,
        ]);

        if ($response->status() === 409 && $this->isNotFound($response)) {
            return [];
        }

        $this->guard($response, 'list_folder');

        $decoded = $response->json();
        $entries = is_array($decoded) && isset($decoded['entries']) && is_array($decoded['entries'])
            ? $decoded['entries']
            : [];

        return array_values(array_filter($entries, 'is_array'));
    }

    /**
     * Liveness probe: can we reach the API and renew the access token?
     */
    public function healthCheck(): bool
    {
        try {
            $this->ensureRootFolder();
            $this->listChildren('');

            return true;
        } catch (Throwable $e) {
            Log::warning('Dropbox health check failed', [
                'root' => $this->root,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function startUploadSession(): string
    {
        $response = Http::withToken($this->accessToken())
            ->withHeaders([
                'Dropbox-API-Arg' => '{}',
                'Content-Type' => 'application/octet-stream',
            ])
            ->withBody('', 'application/octet-stream')
            ->withOptions(['timeout' => $this->timeout])
            ->post($this->contentUrl.'/files/upload_session/start');

        $this->guard($response, 'upload_session/start');

        $decoded = $response->json();
        $sessionId = is_array($decoded) ? ($decoded['session_id'] ?? null) : null;

        if (! is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('Dropbox did not return an upload session id.');
        }

        return $sessionId;
    }

    /**
     * Append bytes to an open upload session.
     *
     * Dropbox requires the offset to match the number of bytes already sent,
     * so the chunks have to be appended strictly in order.
     */
    public function appendToSession(string $sessionId, int $offset, string $localFile): void
    {
        $handle = @fopen($localFile, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read the chunk for the Dropbox upload session.');
        }

        try {
            $this->content('files/upload_session/append_v2', [
                'session_id' => $sessionId,
                'offset' => $offset,
                'close' => false,
            ], $handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Append the last bytes and give the session its final path.
     */
    public function finishUploadSession(string $sessionId, int $offset, string $path, string $localFile): void
    {
        $this->ensureParentFolder($path);

        $handle = @fopen($localFile, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read the chunk for the Dropbox upload session.');
        }

        try {
            $this->content('files/upload_session/finish', [
                'session_id' => $sessionId,
                'offset' => $offset,
                'path' => $this->dropboxPath($path),
                'mode' => 'overwrite',
                'autorename' => false,
                'mute' => true,
                'strict_conflict' => false,
            ], $handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * How much of the Dropbox account is in use, and what it is allowed to
     * use.
     *
     * Returns null when the API cannot answer; a quota read must never take a
     * page down.
     *
     * @return array{used_bytes: int|null, allocated_bytes: int|null}|null
     */
    public function spaceUsage(): ?array
    {
        try {
            $response = $this->json('users/get_space_usage');

            if (! $response->successful()) {
                return null;
            }

            $decoded = $response->json();

            if (! is_array($decoded)) {
                return null;
            }

            return [
                'used_bytes' => $this->integerOrNull($decoded['used'] ?? null),
                'allocated_bytes' => $this->allocatedBytes($decoded['allocation'] ?? null),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  mixed  $allocation  Dropbox's tagged union (individual or team)
     */
    protected function allocatedBytes(mixed $allocation): ?int
    {
        if (! is_array($allocation)) {
            return null;
        }

        $allocated = $this->integerOrNull($allocation['allocated'] ?? null);

        if ($allocated !== null) {
            return $allocated;
        }

        // ".tag": "individual" nests the allocation one level down in some
        // API versions.
        foreach (['individual', 'team'] as $tag) {
            $nested = $allocation[$tag] ?? null;

            if (is_array($nested) && ($value = $this->integerOrNull($nested['allocated'] ?? null)) !== null) {
                return $value;
            }
        }

        return null;
    }

    protected function integerOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }

    /**
     * The current access token, renewed from the refresh token when needed.
     */
    public function accessToken(): string
    {
        $cached = Cache::get($this->tokenCacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->withOptions(['timeout' => $this->timeout])
            ->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'client_id' => $this->appKey,
                'client_secret' => $this->appSecret,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Dropbox refused the refresh token: '.$this->errorMessage($response)
            );
        }

        $decoded = $response->json();
        $token = is_array($decoded) ? ($decoded['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Dropbox did not return an access token.');
        }

        $expiresIn = is_array($decoded) ? (int) ($decoded['expires_in'] ?? 14400) : 14400;

        Cache::put($this->tokenCacheKey(), $token, now()->addSeconds(max(60, $expiresIn - 60)));

        return $token;
    }

    /**
     * Cache key for this account's access token.
     *
     * The key includes the app key and refresh token because two spaces may be
     * connected to two different Dropbox accounts. A single shared key would
     * hand one account's token to the other, which is both a correctness and a
     * privacy problem.
     */
    protected function tokenCacheKey(): string
    {
        return self::TOKEN_CACHE_KEY.'.'.sha1($this->appKey.'|'.$this->refreshToken);
    }

    /**
     * Send a request whose body is a binary stream.
     */
    protected function content(string $endpoint, array $argument, $body): void
    {
        $response = Http::withToken($this->accessToken())
            ->withHeaders([
                'Dropbox-API-Arg' => $this->apiArg($argument),
                'Content-Type' => 'application/octet-stream',
            ])
            ->withBody($body, 'application/octet-stream')
            ->withOptions(['timeout' => $this->timeout])
            ->post($this->contentUrl.'/'.$endpoint);

        $this->guard($response, $endpoint);
    }

    protected function guard(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(
            'Dropbox '.$operation.' failed: '.$this->errorMessage($response)
        );
    }

    protected function errorMessage(Response $response): string
    {
        $body = $response->body();

        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);

            if (is_array($decoded)) {
                $summary = $decoded['error_summary'] ?? null;

                if (is_string($summary) && $summary !== '') {
                    return $summary;
                }
            }

            return substr($body, 0, 250);
        }

        return 'HTTP '.$response->status();
    }

    protected function isNotFound(Response $response): bool
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            return false;
        }

        $summary = $decoded['error_summary'] ?? '';

        return is_string($summary) && str_contains($summary, 'not_found');
    }

    /**
     * The Dropbox-API-Arg header: compact JSON on a single line.
     */
    protected function apiArg(array $argument): string
    {
        $encoded = json_encode($argument, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode the Dropbox API argument.');
        }

        return $encoded;
    }

    /**
     * Ensure the configured root folder exists.
     */
    public function ensureRootFolder(): void
    {
        $this->ensureAbsoluteFolder('/'.trim($this->root, '/'));
    }

    /**
     * Ensure the parent of an EDMS storage path exists.
     */
    public function ensureParentFolder(string $path): void
    {
        $path = trim($path, '/');

        if (! str_contains($path, '/')) {
            $this->ensureRootFolder();

            return;
        }

        $parent = substr($path, 0, strrpos($path, '/'));

        $this->ensureAbsoluteFolder($this->dropboxPath($parent));
    }

    /**
     * Create every missing segment of an absolute Dropbox folder path.
     */
    protected function ensureAbsoluteFolder(string $absolute): void
    {
        $absolute = '/'.trim($absolute, '/');

        if ($absolute === '' || $absolute === '/') {
            return;
        }

        if (isset($this->ensuredFolders[$absolute])) {
            return;
        }

        $cacheKey = $this->folderCacheKey($absolute);

        if (Cache::get($cacheKey) === true) {
            $this->ensuredFolders[$absolute] = true;

            return;
        }

        $current = '';

        foreach (explode('/', trim($absolute, '/')) as $segment) {
            $current .= '/'.$segment;

            if (isset($this->ensuredFolders[$current])) {
                continue;
            }

            $segmentCacheKey = $this->folderCacheKey($current);

            if (Cache::get($segmentCacheKey) === true) {
                $this->ensuredFolders[$current] = true;

                continue;
            }

            if ($this->metadataAbsolute($current) === null) {
                $this->createFolderAbsolute($current);
            }

            Cache::put($segmentCacheKey, true, now()->addDays(30));
            $this->ensuredFolders[$current] = true;
        }

        Cache::put($cacheKey, true, now()->addDays(30));
        $this->ensuredFolders[$absolute] = true;
    }

    protected function folderCacheKey(string $path): string
    {
        return 'edms.dropbox.folder.'.sha1($this->appKey.'|'.$this->refreshToken.'|'.$path);
    }

    protected function createFolderAbsolute(string $path): void
    {
        $response = $this->json('files/create_folder_v2', [
            'path' => $path,
            'autorename' => false,
        ]);

        if ($response->status() === 409 && $this->isFolderConflict($response)) {
            return;
        }

        $this->guard($response, 'create_folder_v2');
    }

    /**
     * Send an RPC request with its argument as a JSON object.
     *
     * Laravel's post() sends an empty PHP array as "[]", which Dropbox rejects
     * with "expected object, got array". A JSON body is also the documented
     * format for the RPC endpoints; the content endpoints keep using the
     * Dropbox-API-Arg header and a binary body.
     */
    protected function json(string $endpoint, array $argument = []): Response
    {
        $body = json_encode((object) $argument, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($body)) {
            throw new RuntimeException('Unable to encode the Dropbox API request.');
        }

        return Http::withToken($this->accessToken())
            ->withHeaders(['Content-Type' => 'application/json'])
            ->withBody($body, 'application/json')
            ->withOptions(['timeout' => $this->timeout])
            ->post($this->apiUrl.'/'.$endpoint);
    }

    protected function isFolderConflict(Response $response): bool
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            return false;
        }

        $summary = $decoded['error_summary'] ?? '';

        return is_string($summary) && str_contains($summary, 'conflict/folder');
    }
}
