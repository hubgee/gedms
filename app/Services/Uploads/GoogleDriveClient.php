<?php

namespace App\Services\Uploads;

use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Google Drive API v3 client.
 *
 * Drive is id-based rather than path-based, so every operation resolves the
 * EDMS path into a folder id first. Folder ids are cached for the lifetime of
 * the request, which turns a multi-chunk upload into one lookup instead of one
 * lookup per chunk.
 *
 * Large uploads use Drive's resumable upload protocol: a session is opened
 * once, then the bytes are streamed from the local file. Downloads use a
 * ranged stream so previewing a large object does not download all of it.
 */
class GoogleDriveClient
{
    protected const FOLDER_MIME = 'application/vnd.google-apps.folder';

    protected const TOKEN_CACHE_PREFIX = 'edms.google.access_token.';

    /** @var array<string, string> folder path => folder id */
    protected array $folderCache = [];

    public function __construct(
        protected string $clientId,
        protected string $clientSecret,
        protected string $refreshToken,
        protected string $root = '/edms',
        protected int $timeout = 120,
        protected string $apiUrl = 'https://www.googleapis.com/drive/v3',
        protected string $uploadUrl = 'https://www.googleapis.com/upload/drive/v3',
        protected string $tokenUrl = 'https://oauth2.googleapis.com/token',
    ) {}

    public function rootFolderId(): string
    {
        $root = trim($this->root, '/');

        return $root === '' ? 'root' : $this->folderId($root);
    }

    /**
     * Resolve a directory to a folder id, creating missing folders.
     */
    public function folderId(string $directory): string
    {
        $directory = trim($this->drivePath($directory), '/');

        if ($directory === '') {
            return 'root';
        }

        $current = 'root';
        $soFar = '';

        foreach (explode('/', $directory) as $segment) {
            $soFar .= '/'.$segment;

            if (isset($this->folderCache[$soFar])) {
                $current = $this->folderCache[$soFar];

                continue;
            }

            $found = $this->findChild($current, $segment, self::FOLDER_MIME);

            if ($found === null) {
                $found = $this->createFolder($current, $segment);
            }

            $this->folderCache[$soFar] = $found;
            $current = $found;
        }

        return $current;
    }

    /**
     * Metadata for a stored file, or null when it does not exist.
     *
     * @return array{id: string, size: int|null, mimeType: string|null}|null
     */
    public function find(string $path): ?array
    {
        $directory = $this->folderId(dirname($path));
        $id = $this->findChild($directory, basename($path), null, true);

        if ($id === null) {
            return null;
        }

        return $this->metadata($id);
    }

    /**
     * @return array{id: string, size: int|null, mimeType: string|null}|null
     */
    public function metadata(string $id): ?array
    {
        $response = Http::withToken($this->accessToken())
            ->withOptions(['timeout' => $this->timeout])
            ->get($this->apiUrl.'/files/'.$id, [
                'fields' => 'id,name,size,mimeType',
                'supportsAllDrives' => 'true',
            ]);

        if (! $response->successful()) {
            return null;
        }

        $decoded = $response->json();

        if (! is_array($decoded) || ! isset($decoded['id'])) {
            return null;
        }

        return [
            'id' => (string) $decoded['id'],
            'size' => isset($decoded['size']) ? (int) $decoded['size'] : null,
            'mimeType' => isset($decoded['mimeType']) ? (string) $decoded['mimeType'] : null,
        ];
    }

    /**
     * Upload a local file to a storage path, replacing any existing file.
     */
    public function uploadFile(string $path, string $localFile): void
    {
        $bytes = @filesize($localFile);

        if ($bytes === false) {
            throw new RuntimeException('Unable to read the file for the Google Drive upload.');
        }

        $existing = $this->find($path);
        $directory = $this->folderId(dirname($path));

        $metadata = $existing === null
            ? ['name' => basename($path), 'parents' => [$directory]]
            : ['name' => basename($path)];

        $endpoint = $existing === null
            ? $this->uploadUrl.'/files?uploadType=resumable&fields=id,size'
            : $this->uploadUrl.'/files/'.$existing['id'].'?uploadType=resumable&fields=id,size';

        $request = Http::withToken($this->accessToken())
            ->withHeaders([
                'Content-Type' => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type' => 'application/octet-stream',
                'X-Upload-Content-Length' => (string) $bytes,
            ])
            ->withOptions(['timeout' => $this->timeout]);

        $response = $existing === null
            ? $request->post($endpoint, $metadata)
            : $request->patch($endpoint, $metadata);

        if (! $response->successful()) {
            throw new RuntimeException('Google Drive refused the upload: '.$this->errorMessage($response));
        }

        $location = $response->header('Location');

        if (! is_string($location) || $location === '') {
            throw new RuntimeException('Google Drive did not return a resumable upload URL.');
        }

        $handle = @fopen($localFile, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read the file for the Google Drive upload.');
        }

        try {
            $upload = Http::withHeaders([
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => (string) $bytes,
            ])
                ->withOptions(['timeout' => $this->timeout])
                ->withBody($handle, 'application/octet-stream')
                ->put($location);
        } finally {
            fclose($handle);
        }

        if (! $upload->successful()) {
            throw new RuntimeException('Google Drive refused the upload bytes: '.$this->errorMessage($upload));
        }
    }

    /**
     * Stream a stored file into a local file, optionally a byte range.
     */
    public function downloadTo(string $path, string $localFile, int $offset = 0, ?int $length = null): void
    {
        $item = $this->find($path);

        if ($item === null) {
            throw new RuntimeException('The file does not exist on Google Drive.');
        }

        $headers = [];

        if ($offset > 0 || $length !== null) {
            $end = $length !== null ? $offset + max(0, $length - 1) : '';
            $headers['Range'] = 'bytes='.$offset.'-'.$end;
        }

        $response = Http::withToken($this->accessToken())
            ->withHeaders($headers)
            ->withOptions(['sink' => $localFile, 'timeout' => $this->timeout])
            ->get($this->apiUrl.'/files/'.$item['id'], ['alt' => 'media', 'supportsAllDrives' => 'true']);

        $this->guard($response, 'download');
    }

    /**
     * @return resource|null
     */
    public function readStream(string $path, int $offset = 0, ?int $length = null)
    {
        $item = $this->find($path);

        if ($item === null) {
            return null;
        }

        $headers = [];

        if ($offset > 0 || $length !== null) {
            $end = $length !== null ? $offset + max(0, $length - 1) : '';
            $headers['Range'] = 'bytes='.$offset.'-'.$end;
        }

        try {
            $response = Http::withToken($this->accessToken())
                ->withHeaders($headers)
                ->withOptions(['stream' => true, 'timeout' => $this->timeout])
                ->get($this->apiUrl.'/files/'.$item['id'], ['alt' => 'media', 'supportsAllDrives' => 'true']);

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

        $item = $this->find($path);

        if ($item === null) {
            return null;
        }

        $response = Http::withToken($this->accessToken())
            ->withHeaders(['Range' => 'bytes=0-'.max(0, $bytes - 1)])
            ->withOptions(['timeout' => $this->timeout])
            ->get($this->apiUrl.'/files/'.$item['id'], ['alt' => 'media', 'supportsAllDrives' => 'true']);

        return $response->successful() ? $response->body() : null;
    }

    public function copy(string $source, string $destination): void
    {
        $item = $this->find($source);

        if ($item === null) {
            throw new RuntimeException('The source file does not exist on Google Drive.');
        }

        if ($source !== $destination) {
            $this->delete($destination);
        }

        $directory = $this->folderId(dirname($destination));

        $response = Http::withToken($this->accessToken())
            ->withOptions(['timeout' => $this->timeout])
            ->post($this->apiUrl.'/files/'.$item['id'].'/copy', [
                'name' => basename($destination),
                'parents' => [$directory],
            ]);

        $this->guard($response, 'copy');
    }

    public function delete(string $path): void
    {
        $item = $this->find($path);

        if ($item === null) {
            return;
        }

        $response = Http::withToken($this->accessToken())
            ->withOptions(['timeout' => $this->timeout])
            ->delete($this->apiUrl.'/files/'.$item['id'], ['supportsAllDrives' => 'true']);

        if ($response->status() !== 404) {
            $this->guard($response, 'delete');
        }
    }

    public function deleteDirectory(string $directory): void
    {
        $path = trim($this->drivePath($directory), '/');

        // Never delete the configured root or the Drive root itself.
        if ($path === '' || $path === trim($this->root, '/')) {
            return;
        }

        $parent = $this->folderId(dirname($path));
        $id = $this->findChild($parent, basename($path), self::FOLDER_MIME);

        if ($id === null) {
            return;
        }

        $response = Http::withToken($this->accessToken())
            ->withOptions(['timeout' => $this->timeout])
            ->delete($this->apiUrl.'/files/'.$id, ['supportsAllDrives' => 'true']);

        if ($response->status() !== 404) {
            $this->guard($response, 'delete folder');
        }
    }

    /**
     * Names of the folders directly below a directory.
     *
     * @return array<int, string>
     */
    public function directories(string $directory): array
    {
        $id = $this->folderId($directory);

        return array_values(array_map(
            static fn (array $entry): string => (string) $entry['name'],
            array_filter($this->listChildren($id), static fn (array $entry): bool => ($entry['mimeType'] ?? '') === self::FOLDER_MIME),
        ));
    }

    /**
     * Whether a directory holds no files or folders.
     */
    public function isEmpty(string $directory): bool
    {
        return $this->listChildren($this->folderId($directory)) === [];
    }

    public function healthCheck(): bool
    {
        try {
            $response = Http::withToken($this->accessToken())
                ->withOptions(['timeout' => $this->timeout])
                ->get($this->apiUrl.'/about', ['fields' => 'user']);

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
                ->get($this->apiUrl.'/about', ['fields' => 'storageQuota']);

            if (! $response->successful()) {
                return null;
            }

            $quota = $response->json('storageQuota');

            if (! is_array($quota)) {
                return null;
            }

            return [
                'used_bytes' => isset($quota['usage']) ? (int) $quota['usage'] : null,
                'allocated_bytes' => isset($quota['limit']) ? (int) $quota['limit'] : null,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * List the immediate children of a folder id.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function listChildren(string $folderId): array
    {
        $response = Http::withToken($this->accessToken())
            ->withOptions(['timeout' => $this->timeout])
            ->get($this->apiUrl.'/files', [
                'q' => "'".$this->escape($folderId)."' in parents and trashed = false",
                'fields' => 'files(id,name,mimeType,size)',
                'pageSize' => 1000,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ]);

        if (! $response->successful()) {
            return [];
        }

        $decoded = $response->json();
        $files = is_array($decoded) && isset($decoded['files']) && is_array($decoded['files'])
            ? $decoded['files']
            : [];

        return array_values(array_filter($files, 'is_array'));
    }

    /**
     * Find a child by name, optionally restricted to files or folders.
     */
    protected function findChild(string $parentId, string $name, ?string $mimeType = null, bool $fileOnly = false): ?string
    {
        $clauses = [
            "name = '".$this->escape($name)."'",
            "'".$this->escape($parentId)."' in parents",
            'trashed = false',
        ];

        if ($mimeType !== null) {
            $clauses[] = "mimeType = '".$this->escape($mimeType)."'";
        }

        if ($fileOnly) {
            $clauses[] = "mimeType != '".self::FOLDER_MIME."'";
        }

        $response = Http::withToken($this->accessToken())
            ->withOptions(['timeout' => $this->timeout])
            ->get($this->apiUrl.'/files', [
                'q' => implode(' and ', $clauses),
                'fields' => 'files(id)',
                'pageSize' => 1,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ]);

        if (! $response->successful()) {
            return null;
        }

        $decoded = $response->json();
        $id = is_array($decoded) ? ($decoded['files'][0]['id'] ?? null) : null;

        return is_string($id) ? $id : null;
    }

    protected function createFolder(string $parentId, string $name): string
    {
        $response = Http::withToken($this->accessToken())
            ->withOptions(['timeout' => $this->timeout])
            ->post($this->apiUrl.'/files', [
                'name' => $name,
                'mimeType' => self::FOLDER_MIME,
                'parents' => [$parentId],
            ], ['fields' => 'id', 'supportsAllDrives' => 'true']);

        $this->guard($response, 'create folder');

        $decoded = $response->json();
        $id = is_array($decoded) ? ($decoded['id'] ?? null) : null;

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Google Drive did not return a folder id.');
        }

        return $id;
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
            ->post($this->tokenUrl, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Google refused the refresh token: '.$this->errorMessage($response));
        }

        $decoded = $response->json();
        $token = is_array($decoded) ? ($decoded['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Google did not return an access token.');
        }

        $expiresIn = is_array($decoded) ? (int) ($decoded['expires_in'] ?? 3600) : 3600;
        Cache::put($key, $token, now()->addSeconds(max(60, $expiresIn - 60)));

        return $token;
    }

    protected function drivePath(string $path): string
    {
        $root = trim($this->root, '/');
        $relative = trim($path, '/');

        return trim($root.'/'.$relative, '/');
    }

    protected function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    protected function guard(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException('Google Drive '.$operation.' failed: '.$this->errorMessage($response));
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
