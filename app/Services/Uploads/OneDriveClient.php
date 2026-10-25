<?php

namespace App\Services\Uploads;

use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Microsoft Graph client for OneDrive (personal or business).
 *
 * Graph is path-addressable, which maps cleanly onto the EDMS storage paths.
 * Large files use an upload session because Graph refuses single-request
 * uploads above 4 MiB; small files use the simple content endpoint.
 */
class OneDriveClient
{
    protected const SIMPLE_UPLOAD_LIMIT = 4194304; // 4 MiB

    protected const UPLOAD_CHUNK = 10485760; // 10 MiB, a multiple of 320 KiB

    protected const TOKEN_CACHE_PREFIX = 'edms.onedrive.access_token.';

    /** @var array<string, bool> folder path => exists */
    protected array $folderCache = [];

    public function __construct(
        protected string $clientId,
        protected string $clientSecret,
        protected string $refreshToken,
        protected string $tenant = 'common',
        protected string $root = '/edms',
        protected int $timeout = 120,
        protected string $graphUrl = 'https://graph.microsoft.com/v1.0',
        protected string $authUrl = 'https://login.microsoftonline.com',
    ) {}

    /**
     * Metadata for a stored item, or null when it does not exist.
     *
     * @return array{id: string, size: int|null, is_folder: bool}|null
     */
    public function find(string $path): ?array
    {
        $response = Http::withToken($this->accessToken())
            ->withOptions(['timeout' => $this->timeout])
            ->get($this->itemUrl($path), ['$select' => 'id,name,size,file,folder']);

        if ($response->status() === 404) {
            return null;
        }

        $this->guard($response, 'get item');

        $decoded = $response->json();

        if (! is_array($decoded) || ! isset($decoded['id'])) {
            return null;
        }

        return [
            'id' => (string) $decoded['id'],
            'size' => isset($decoded['size']) ? (int) $decoded['size'] : null,
            'is_folder' => isset($decoded['folder']),
        ];
    }

    /**
     * Upload a local file to a storage path, replacing any existing file.
     */
    public function uploadFile(string $path, string $localFile): void
    {
        $bytes = @filesize($localFile);

        if ($bytes === false) {
            throw new RuntimeException('Unable to read the file for the OneDrive upload.');
        }

        $this->ensureFolder(dirname($path));

        if ($bytes <= self::SIMPLE_UPLOAD_LIMIT) {
            $handle = @fopen($localFile, 'rb');

            if ($handle === false) {
                throw new RuntimeException('Unable to read the file for the OneDrive upload.');
            }

            try {
                $response = Http::withToken($this->accessToken())
                    ->withHeaders(['Content-Type' => 'application/octet-stream'])
                    ->withOptions(['timeout' => $this->timeout])
                    ->withBody($handle, 'application/octet-stream')
                    ->put($this->contentUrl($path));
            } finally {
                fclose($handle);
            }

            $this->guard($response, 'upload');

            return;
        }

        $this->uploadLargeFile($path, $localFile, $bytes);
    }

    protected function uploadLargeFile(string $path, string $localFile, int $bytes): void
    {
        $session = Http::withToken($this->accessToken())
            ->withOptions(['timeout' => $this->timeout])
            ->post($this->itemUrl($path).':/createUploadSession', [
                'item' => ['@microsoft.graph.conflictBehavior' => 'replace'],
            ]);

        $this->guard($session, 'create upload session');

        $uploadUrl = $session->json('uploadUrl');

        if (! is_string($uploadUrl) || $uploadUrl === '') {
            throw new RuntimeException('OneDrive did not return an upload URL.');
        }

        $handle = @fopen($localFile, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read the file for the OneDrive upload.');
        }

        try {
            for ($start = 0; $start < $bytes; $start += self::UPLOAD_CHUNK) {
                $length = min(self::UPLOAD_CHUNK, $bytes - $start);
                $end = $start + $length - 1;

                if (fseek($handle, $start) !== 0) {
                    throw new RuntimeException('Unable to seek within the file for the OneDrive upload.');
                }

                $data = fread($handle, $length);

                if ($data === false || strlen($data) !== $length) {
                    throw new RuntimeException('Unable to read a chunk for the OneDrive upload.');
                }

                $response = Http::withHeaders([
                    'Content-Length' => (string) $length,
                    'Content-Range' => 'bytes '.$start.'-'.$end.'/'.$bytes,
                ])
                    ->withOptions(['timeout' => $this->timeout])
                    ->withBody($data, 'application/octet-stream')
                    ->put($uploadUrl);

                if (! $response->successful()) {
                    throw new RuntimeException('OneDrive refused the upload chunk: '.$this->errorMessage($response));
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Stream a stored file into a local file, optionally a byte range.
     */
    public function downloadTo(string $path, string $localFile, int $offset = 0, ?int $length = null): void
    {
        $response = Http::withToken($this->accessToken())
            ->withHeaders($this->rangeHeaders($offset, $length))
            ->withOptions(['sink' => $localFile, 'timeout' => $this->timeout])
            ->get($this->contentUrl($path));

        $this->guard($response, 'download');
    }

    /**
     * @return resource|null
     */
    public function readStream(string $path, int $offset = 0, ?int $length = null)
    {
        try {
            $response = Http::withToken($this->accessToken())
                ->withHeaders($this->rangeHeaders($offset, $length))
                ->withOptions(['stream' => true, 'timeout' => $this->timeout])
                ->get($this->contentUrl($path));

            if (! $response->successful()) {
                return null;
            }

            return StreamWrapper::getResource($response->toPsrResponse()->getBody());
        } catch (Throwable) {
            return null;
        }
    }

    public function readHead(string $path, int $bytes): ?string
    {
        if ($bytes < 1) {
            return null;
        }

        $response = Http::withToken($this->accessToken())
            ->withHeaders(['Range' => 'bytes=0-'.max(0, $bytes - 1)])
            ->withOptions(['timeout' => $this->timeout])
            ->get($this->contentUrl($path));

        return $response->successful() ? $response->body() : null;
    }

    public function copy(string $source, string $destination): void
    {
        if ($source !== $destination) {
            $this->delete($destination);
        }

        $this->ensureFolder(dirname($destination));

        $destinationDir = trim($this->itemPath(dirname($destination)), '/');

        $response = Http::withToken($this->accessToken())
            ->withOptions(['timeout' => $this->timeout])
            ->post($this->itemUrl($source).':/copy', [
                'parentReference' => [
                    'path' => '/drive/root:'.($destinationDir === '' ? '' : '/'.$destinationDir),
                ],
                'name' => basename($destination),
            ]);

        if ($response->status() === 202) {
            $monitor = $response->header('Location');

            if (is_string($monitor) && $monitor !== '') {
                $this->awaitCopy($monitor);
            }

            return;
        }

        $this->guard($response, 'copy');
    }

    protected function awaitCopy(string $monitor): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            usleep(200000);

            try {
                $response = Http::withToken($this->accessToken())
                    ->withOptions(['timeout' => $this->timeout])
                    ->get($monitor);
            } catch (Throwable) {
                continue;
            }

            if ($response->status() === 200) {
                return;
            }

            if ($response->status() >= 400) {
                throw new RuntimeException('OneDrive copy failed: '.$this->errorMessage($response));
            }
        }

        throw new RuntimeException('OneDrive copy did not finish in time.');
    }

    public function delete(string $path): void
    {
        $response = Http::withToken($this->accessToken())
            ->withOptions(['timeout' => $this->timeout])
            ->delete($this->itemUrl($path));

        if ($response->status() === 404) {
            return;
        }

        $this->guard($response, 'delete');
    }

    public function deleteDirectory(string $directory): void
    {
        $path = trim($this->itemPath($directory), '/');

        // Never delete the configured root.
        if ($path === '' || $path === trim($this->root, '/')) {
            return;
        }

        $this->delete($directory);
    }

    /**
     * Names of the folders directly below a directory.
     *
     * @return array<int, string>
     */
    public function directories(string $directory): array
    {
        $response = Http::withToken($this->accessToken())
            ->withOptions(['timeout' => $this->timeout])
            ->get($this->childrenUrl($directory), ['$select' => 'name,folder', '$top' => 999]);

        if (! $response->successful()) {
            return [];
        }

        $decoded = $response->json();
        $entries = is_array($decoded) && isset($decoded['value']) && is_array($decoded['value'])
            ? $decoded['value']
            : [];

        $names = [];

        foreach ($entries as $entry) {
            if (is_array($entry) && isset($entry['folder']) && isset($entry['name'])) {
                $names[] = (string) $entry['name'];
            }
        }

        return $names;
    }

    public function isEmpty(string $directory): bool
    {
        $response = Http::withToken($this->accessToken())
            ->withOptions(['timeout' => $this->timeout])
            ->get($this->childrenUrl($directory), ['$select' => 'name', '$top' => 1]);

        if (! $response->successful()) {
            return false;
        }

        $value = $response->json('value');

        return is_array($value) && $value === [];
    }

    public function healthCheck(): bool
    {
        try {
            $response = Http::withToken($this->accessToken())
                ->withOptions(['timeout' => $this->timeout])
                ->get($this->graphUrl.'/me/drive', ['$select' => 'id']);

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{used_bytes: int|null, allocated_bytes: int|null}|null
     */
    public function spaceUsage(): ?array
    {
        try {
            $response = Http::withToken($this->accessToken())
                ->withOptions(['timeout' => $this->timeout])
                ->get($this->graphUrl.'/me/drive', ['$select' => 'quota']);

            if (! $response->successful()) {
                return null;
            }

            $quota = $response->json('quota');

            if (! is_array($quota)) {
                return null;
            }

            return [
                'used_bytes' => isset($quota['used']) ? (int) $quota['used'] : null,
                'allocated_bytes' => isset($quota['total']) ? (int) $quota['total'] : null,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function accessToken(): string
    {
        $key = self::TOKEN_CACHE_PREFIX.sha1($this->clientId.'|'.$this->refreshToken);
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->withOptions(['timeout' => 30])
            ->post($this->tokenUrl(), [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'offline_access Files.ReadWrite.All',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Microsoft refused the refresh token: '.$this->errorMessage($response));
        }

        $decoded = $response->json();
        $token = is_array($decoded) ? ($decoded['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Microsoft did not return an access token.');
        }

        $expiresIn = is_array($decoded) ? (int) ($decoded['expires_in'] ?? 3600) : 3600;
        Cache::put($key, $token, now()->addSeconds(max(60, $expiresIn - 120)));

        return $token;
    }

    public function tokenUrl(): string
    {
        return rtrim($this->authUrl, '/').'/'.trim($this->tenant, '/').'/oauth2/v2.0/token';
    }

    protected function ensureFolder(string $directory): void
    {
        $directory = trim($this->itemPath($directory), '/');

        if ($directory === '') {
            return;
        }

        $current = '';

        foreach (explode('/', $directory) as $segment) {
            $current = $current === '' ? $segment : $current.'/'.$segment;

            if (isset($this->folderCache[$current])) {
                continue;
            }

            if ($this->find($current) === null) {
                $response = Http::withToken($this->accessToken())
                    ->withOptions(['timeout' => $this->timeout])
                    ->put($this->itemUrl($current), [
                        'folder' => (object) [],
                        '@microsoft.graph.conflictBehavior' => 'replace',
                    ]);

                if (! $response->successful() && $response->status() !== 409) {
                    $this->guard($response, 'create folder');
                }
            }

            $this->folderCache[$current] = true;
        }
    }

    protected function itemPath(string $path): string
    {
        $root = trim($this->root, '/');
        $relative = trim($path, '/');

        return trim($root.'/'.$relative, '/');
    }

    protected function itemUrl(string $path): string
    {
        $path = $this->itemPath($path);

        return $path === ''
            ? $this->graphUrl.'/me/drive/root'
            : $this->graphUrl.'/me/drive/root:/'.$this->encodePath($path);
    }

    protected function childrenUrl(string $path): string
    {
        $path = $this->itemPath($path);

        return $path === ''
            ? $this->graphUrl.'/me/drive/root/children'
            : $this->graphUrl.'/me/drive/root:/'.$this->encodePath($path).':/children';
    }

    protected function contentUrl(string $path): string
    {
        $path = $this->itemPath($path);

        return $path === ''
            ? $this->graphUrl.'/me/drive/root/content'
            : $this->graphUrl.'/me/drive/root:/'.$this->encodePath($path).':/content';
    }

    protected function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }

    /**
     * @return array<string, string>
     */
    protected function rangeHeaders(int $offset, ?int $length): array
    {
        if ($offset === 0 && $length === null) {
            return [];
        }

        $end = $length !== null ? $offset + max(0, $length - 1) : '';

        return ['Range' => 'bytes='.$offset.'-'.$end];
    }

    protected function guard(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException('OneDrive '.$operation.' failed: '.$this->errorMessage($response));
    }

    protected function errorMessage(Response $response): string
    {
        $decoded = $response->json();

        if (is_array($decoded)) {
            $message = $decoded['error']['message'] ?? $decoded['error_description'] ?? null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        $body = $response->body();

        return is_string($body) && $body !== ''
            ? substr($body, 0, 250)
            : 'HTTP '.$response->status();
    }
}
