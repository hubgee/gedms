<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * An in-memory stand-in for the Dropbox API.
 *
 * It implements just enough of the API for the Dropbox byte store to be
 * exercised without an account: simple upload, upload sessions (start,
 * append, finish), ranged download, metadata, listing, copy and delete.
 *
 * The point is not to reimplement Dropbox. The point is to let the test
 * suite assert the things that are specific to Dropbox as a storage backend:
 * that the chunks are appended to the upload session strictly in order, that
 * a ranged download asks for a range, and that the assembled file comes back
 * byte-identical.
 */
class FakeDropbox
{
    /** @var array<string, string> Dropbox path => content. */
    protected array $files = [];

    /** @var array<string, true> Dropbox folder paths that exist. */
    protected array $folders = [];

    /** @var array<string, array{offset: int, bytes: string}> Upload sessions. */
    protected array $sessions = [];

    /** @var array<int, array{endpoint: string, path: string, offset: int, bytes: int, range: string}> */
    protected array $calls = [];

    /** @var array<int, array{endpoint: string, body: array<string, mixed>, status: int}> */
    protected array $failures = [];

    /** @var array<string, array{body: array<string, mixed>, status: int}> */
    protected array $alwaysFailures = [];

    protected int $sessionCounter = 0;

    public function __construct(
        protected string $root = '/edms',
    ) {
        // The root is created by the client on first use; the fake mirrors
        // that so a missing ensureParentFolder() call is caught.
    }

    /**
     * Register the fake with the HTTP client.
     */
    public static function install(string $root = '/edms'): self
    {
        $fake = new self($root);

        Http::fake(function (Request $request) use ($fake) {
            return $fake->handle($request);
        });

        return $fake;
    }

    /**
     * The content stored under a path relative to the Dropbox root.
     */
    public function contentAt(string $relativePath): ?string
    {
        return $this->content(rtrim($this->root, '/').'/'.ltrim($relativePath, '/'));
    }

    /**
     * Whether a path relative to the Dropbox root exists.
     */
    public function hasAt(string $relativePath): bool
    {
        return $this->has(rtrim($this->root, '/').'/'.ltrim($relativePath, '/'));
    }

    /**
     * Replace the stored bytes, to model a provider that kept less than it
     * acknowledged.
     */
    public function overwriteAt(string $relativePath, string $content): void
    {
        $this->files[$this->normalise(rtrim($this->root, '/').'/'.ltrim($relativePath, '/'))] = $content;
    }

    /**
     * The content stored under a path.
     */
    public function content(string $path): ?string
    {
        return $this->files[$this->normalise($path)] ?? null;
    }

    /**
     * Whether a path exists.
     */
    public function has(string $path): bool
    {
        return array_key_exists($this->normalise($path), $this->files);
    }

    /**
     * Whether a folder exists.
     */
    public function hasFolder(string $path): bool
    {
        $normalised = $this->normalise($path);

        return $normalised === '/' || isset($this->folders[$normalised]);
    }

    /**
     * Every call made against the API, in order.
     *
     * @return array<int, array{endpoint: string, path: string, offset: int, bytes: int, range: string}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * The calls made against one endpoint.
     *
     * @return array<int, array{endpoint: string, path: string, offset: int, bytes: int, range: string}>
     */
    public function callsTo(string $endpoint): array
    {
        return array_values(array_filter(
            $this->calls,
            fn (array $call) => $call['endpoint'] === $endpoint,
        ));
    }

    /**
     * Make the next call to an endpoint fail, then behave normally again.
     *
     * @param  array<string, mixed>  $body
     */
    public function failNext(string $endpoint, array $body = [], int $status = 409): void
    {
        $this->failures[] = compact('endpoint', 'body', 'status');
    }

    /**
     * Make every call to an endpoint fail.
     *
     * @param  array<string, mixed>  $body
     */
    public function failAlways(string $endpoint, array $body = [], int $status = 429): void
    {
        $this->alwaysFailures[$endpoint] = compact('body', 'status');
    }

    /**
     * Answer a request.
     *
     * The HTTP client's fake handler expects a promise (or an array), so the
     * responses are built as promises rather than concrete responses.
     */
    public function handle(Request $request): PromiseInterface
    {
        $url = (string) $request->url();
        $method = strtolower($request->method());

        if (str_contains($url, '/oauth2/token')) {
            return $this->response([
                'access_token' => 'fake-access-token',
                'expires_in' => 14400,
            ]);
        }

        $argument = $this->argument($request);
        $endpoint = $this->endpoint($url);

        if (isset($this->alwaysFailures[$endpoint])) {
            $failure = $this->alwaysFailures[$endpoint];

            return $this->response($failure['body'], $failure['status']);
        }

        foreach ($this->failures as $index => $failure) {
            if ($failure['endpoint'] === $endpoint) {
                array_splice($this->failures, $index, 1);

                return $this->response($failure['body'], $failure['status']);
            }
        }

        switch ($endpoint) {
            case 'files/upload':
                $path = $argument['path'] ?? '';

                if (! $this->parentExists($path)) {
                    return $this->conflict('path/not_found');
                }

                $this->files[$this->normalise($path)] = (string) $request->body();

                $this->record($endpoint, $path, 0, strlen((string) $request->body()));

                return $this->response($this->metadata($path));

            case 'files/upload_session/start':
                $id = 'session-'.(++$this->sessionCounter);
                $this->sessions[$id] = ['offset' => 0, 'bytes' => ''];

                $this->record($endpoint, $id, 0, 0);

                return $this->response(['session_id' => $id]);

            case 'files/upload_session/append_v2':
                $cursor = $argument['cursor'] ?? [];
                $id = $cursor['session_id'] ?? '';
                $offset = (int) ($cursor['offset'] ?? 0);
                $body = (string) $request->body();

                if (! isset($this->sessions[$id])) {
                    return $this->conflict('path/not_found');
                }

                if ($this->sessions[$id]['offset'] !== $offset) {
                    // Dropbox answers with the correct offset when the caller
                    // appends out of order.
                    return $this->response([
                        'error_summary' => 'incorrect_offset/',
                    ], 409);
                }

                $this->sessions[$id]['bytes'] .= $body;
                $this->sessions[$id]['offset'] += strlen($body);

                $this->record($endpoint, $id, $offset, strlen($body));

                return $this->response([]);

            case 'files/upload_session/finish':
                $cursor = $argument['cursor'] ?? [];
                $id = $cursor['session_id'] ?? '';
                $offset = (int) ($cursor['offset'] ?? 0);

                if (! isset($this->sessions[$id])) {
                    return $this->conflict('path/not_found');
                }

                if ($this->sessions[$id]['offset'] !== $offset) {
                    return $this->response(['error_summary' => 'incorrect_offset/'], 409);
                }

                $commit = $argument['commit'] ?? [];
                $path = $commit['path'] ?? '';

                if (! $this->parentExists($path)) {
                    return $this->conflict('path/not_found');
                }

                $this->files[$this->normalise($path)] = $this->sessions[$id]['bytes'];
                unset($this->sessions[$id]);

                $this->record($endpoint, $path, $offset, 0);

                return $this->response($this->metadata($path));

            case 'files/download':
                $path = $argument['path'] ?? '';
                $content = $this->content($path);

                if ($content === null) {
                    return $this->conflict('path/not_found');
                }

                $range = $request->header('Range')[0] ?? '';
                $slice = $this->slice($content, $range);

                $this->record($endpoint, $path, 0, strlen($slice), $range);

                return $this->response($slice, 200);

            case 'files/get_metadata':
                $path = $argument['path'] ?? '';
                $normalised = $this->normalise($path);

                if (isset($this->folders[$normalised])) {
                    return $this->response($this->folderMetadata($normalised));
                }

                if (! $this->has($normalised)) {
                    return $this->conflict('path/not_found');
                }

                return $this->response($this->metadata($path));

            case 'files/create_folder_v2':
                $path = $argument['path'] ?? '';
                $normalised = $this->normalise($path);

                if ($this->has($normalised)) {
                    return $this->conflict('path/conflict/file');
                }

                if (isset($this->folders[$normalised])) {
                    return $this->conflict('path/conflict/folder');
                }

                if (! $this->parentExists($path)) {
                    return $this->conflict('path/not_found');
                }

                $this->folders[$normalised] = true;

                $this->record($endpoint, $path, 0, 0);

                return $this->response(['metadata' => $this->folderMetadata($normalised)]);

            case 'files/delete_v2':
                $path = $argument['path'] ?? '';
                $normalised = $this->normalise($path);

                if (isset($this->folders[$normalised])) {
                    foreach (array_keys($this->files) as $filePath) {
                        if ($filePath === $normalised || str_starts_with($filePath, $normalised.'/')) {
                            unset($this->files[$filePath]);
                        }
                    }

                    foreach (array_keys($this->folders) as $folderPath) {
                        if ($folderPath === $normalised || str_starts_with($folderPath, $normalised.'/')) {
                            unset($this->folders[$folderPath]);
                        }
                    }

                    $this->record($endpoint, $path, 0, 0);

                    return $this->response($this->folderMetadata($normalised));
                }

                if (! $this->has($normalised)) {
                    return $this->conflict('path/not_found');
                }

                unset($this->files[$normalised]);

                $this->record($endpoint, $path, 0, 0);

                return $this->response($this->metadata($path));

            case 'files/list_folder':
                $path = $argument['path'] ?? '';
                $prefix = rtrim($this->normalise($path), '/').'/';

                $entries = [];
                $folders = [];

                foreach ($this->files as $filePath => $content) {
                    if (! str_starts_with($filePath, $prefix)) {
                        continue;
                    }

                    $remainder = substr($filePath, strlen($prefix));

                    if ($remainder === '') {
                        continue;
                    }

                    $slash = strpos($remainder, '/');

                    if ($slash === false) {
                        $entries[] = [
                            '.tag' => 'file',
                            'name' => $remainder,
                            'path_lower' => $filePath,
                            'size' => strlen($content),
                        ];

                        continue;
                    }

                    $folders[substr($remainder, 0, $slash)] = true;
                }

                foreach (array_keys($this->folders) as $folderPath) {
                    if (! str_starts_with($folderPath, $prefix)) {
                        continue;
                    }

                    $remainder = substr($folderPath, strlen($prefix));

                    if ($remainder === '' || str_contains($remainder, '/')) {
                        continue;
                    }

                    $folders[$remainder] = true;
                }

                foreach (array_keys($folders) as $name) {
                    $entries[] = [
                        '.tag' => 'folder',
                        'name' => $name,
                        'path_lower' => $prefix.$name,
                    ];
                }

                return $this->response(['entries' => $entries, 'has_more' => false]);

            case 'files/copy_v2':
                $from = $argument['from_path'] ?? '';
                $to = $argument['to_path'] ?? '';
                $content = $this->content($from);

                if ($content === null) {
                    return $this->conflict('path/not_found');
                }

                if (! $this->parentExists($to)) {
                    return $this->conflict('path/not_found');
                }

                $this->files[$this->normalise($to)] = $content;

                $this->record($endpoint, $to, 0, strlen($content));

                return $this->response($this->metadata($to));

            case 'users/get_space_usage':
                return $this->response([
                    'used' => 0,
                    'allocation' => ['allocated' => 0],
                ]);
        }

        return $this->response(['error_summary' => 'unknown_endpoint/'], 400);
    }

    protected function slice(string $content, string $range): string
    {
        if ($range === '' || ! str_starts_with($range, 'bytes=')) {
            return $content;
        }

        $spec = substr($range, 6);

        if (str_contains($spec, '-')) {
            [$start, $end] = explode('-', $spec, 2);
            $start = (int) $start;
            $end = $end === '' ? strlen($content) - 1 : (int) $end;
        } else {
            $start = (int) $spec;
            $end = strlen($content) - 1;
        }

        $start = max(0, min($start, strlen($content)));
        $end = max($start - 1, min($end, strlen($content) - 1));

        return substr($content, $start, $end - $start + 1);
    }

    protected function metadata(string $path): array
    {
        $normalised = $this->normalise($path);
        $content = $this->files[$normalised] ?? '';

        return [
            '.tag' => 'file',
            'name' => basename($normalised),
            'path_lower' => $normalised,
            'size' => strlen($content),
        ];
    }

    protected function folderMetadata(string $path): array
    {
        $normalised = $this->normalise($path);

        return [
            '.tag' => 'folder',
            'name' => basename($normalised),
            'path_lower' => $normalised,
        ];
    }

    protected function parentExists(string $path): bool
    {
        $parent = $this->parentPath($path);

        return $parent === '/' || isset($this->folders[$parent]);
    }

    protected function parentPath(string $path): string
    {
        $normalised = $this->normalise($path);
        $slash = strrpos($normalised, '/');

        return $slash === false || $slash === 0 ? '/' : substr($normalised, 0, $slash);
    }

    protected function conflict(string $summary): PromiseInterface
    {
        return $this->response(['error_summary' => $summary], 409);
    }

    /**
     * Build a stubbed response promise.
     *
     * @param  array<string, mixed>|string|null  $body
     */
    protected function response($body = null, int $status = 200): PromiseInterface
    {
        return Factory::response($body, $status);
    }

    protected function argument(Request $request): array
    {
        $header = $request->header('Dropbox-API-Arg')[0] ?? null;

        if (is_string($header) && $header !== '') {
            $decoded = json_decode($header, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $body = (string) $request->body();

        if ($body !== '' && $body !== '[]') {
            $decoded = json_decode($body, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    protected function endpoint(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $path = Str::afterLast($path, '/2/');

        return $path;
    }

    protected function record(string $endpoint, string $path, int $offset, int $bytes, string $range = ''): void
    {
        $this->calls[] = [
            'endpoint' => $endpoint,
            'path' => $path,
            'offset' => $offset,
            'bytes' => $bytes,
            'range' => $range,
        ];
    }

    protected function normalise(string $path): string
    {
        $path = '/'.ltrim(str_replace('\\', '/', $path), '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
