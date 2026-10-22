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

    /** @var array<string, array{offset: int, bytes: string}> Upload sessions. */
    protected array $sessions = [];

    /** @var array<int, array{endpoint: string, path: string, offset: int, bytes: int, range: string}> */
    protected array $calls = [];

    protected int $sessionCounter = 0;

    public function __construct(
        protected string $root = '/edms',
    ) {}

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

        switch ($endpoint) {
            case 'files/upload':
                $this->files[$this->normalise($argument['path'])] = (string) $request->body();

                $this->record($endpoint, $argument['path'] ?? '', 0, strlen((string) $request->body()));

                return $this->response($this->metadata($argument['path']));

            case 'files/upload_session/start':
                $id = 'session-'.(++$this->sessionCounter);
                $this->sessions[$id] = ['offset' => 0, 'bytes' => ''];

                $this->record($endpoint, $id, 0, 0);

                return $this->response(['session_id' => $id]);

            case 'files/upload_session/append_v2':
                $id = $argument['session_id'] ?? '';
                $offset = (int) ($argument['offset'] ?? 0);
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
                $id = $argument['session_id'] ?? '';
                $offset = (int) ($argument['offset'] ?? 0);

                if (! isset($this->sessions[$id])) {
                    return $this->conflict('path/not_found');
                }

                if ($this->sessions[$id]['offset'] !== $offset) {
                    return $this->response(['error_summary' => 'incorrect_offset/'], 409);
                }

                $path = $argument['path'] ?? '';
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

                if (! $this->has($path)) {
                    return $this->conflict('path/not_found');
                }

                return $this->response($this->metadata($path));

            case 'files/delete_v2':
                $path = $argument['path'] ?? '';

                if (! $this->has($path)) {
                    return $this->conflict('path/not_found');
                }

                unset($this->files[$this->normalise($path)]);

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

                $this->files[$this->normalise($to)] = $content;

                $this->record($endpoint, $to, 0, strlen($content));

                return $this->response($this->metadata($to));
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
        $header = $request->header('Dropbox-API-Arg')[0] ?? '{}';

        $decoded = json_decode($header, true);

        return is_array($decoded) ? $decoded : [];
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
