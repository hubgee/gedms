<?php

namespace Tests\Feature\Uploads;

use App\Services\Uploads\GoogleDriveClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Google Drive client is exercised against a fake HTTP layer. Drive's
 * folder resolution is id-based, so the tests override the two lookup methods
 * and assert the wire calls that carry bytes and metadata.
 */
class GoogleDriveClientTest extends TestCase
{
    private function client(callable $find): GoogleDriveClient
    {
        return new class($find) extends GoogleDriveClient
        {
            public function __construct(private $finder)
            {
                parent::__construct('client-id', 'client-secret', 'refresh-token', '/edms');
            }

            public function accessToken(): string
            {
                return 'access-token';
            }

            public function find(string $path): ?array
            {
                return ($this->finder)($path);
            }

            public function folderId(string $directory): string
            {
                return 'folder-id';
            }
        };
    }

    public function test_a_small_upload_opens_a_resumable_session_and_sends_the_bytes(): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'edms-google-test-');
        file_put_contents($temporary, 'hello');

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'uploadType=resumable')) {
                return Http::response('', 200, ['Location' => 'https://upload.example/session']);
            }

            if ($request->method() === 'PUT' && $request->url() === 'https://upload.example/session') {
                return Http::response(['id' => 'new-id', 'size' => 5], 200);
            }

            return Http::response('unexpected request', 500);
        });

        $this->client(fn () => null)->uploadFile('tmp/file.bin', $temporary);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/files?uploadType=resumable'));
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === 'https://upload.example/session');

        @unlink($temporary);
    }

    public function test_copy_replaces_the_destination_and_uses_its_folder(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/files/source-id')) {
                return Http::response(['id' => 'source-id', 'size' => 5]);
            }

            if ($request->method() === 'DELETE' && str_contains($request->url(), '/files/dest-id')) {
                return Http::response('', 204);
            }

            if ($request->method() === 'POST' && str_contains($request->url(), '/files/source-id/copy')) {
                return Http::response(['id' => 'copy-id'], 200);
            }

            return Http::response('unexpected request', 500);
        });

        $client = $this->client(fn (string $path) => match ($path) {
            'tmp/source.bin' => ['id' => 'source-id', 'size' => 5, 'mimeType' => null],
            'tmp/dest.bin' => ['id' => 'dest-id', 'size' => 5, 'mimeType' => null],
            default => null,
        });

        $client->copy('tmp/source.bin', 'tmp/dest.bin');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/files/dest-id'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/files/source-id/copy')
            && $request['name'] === 'dest.bin'
            && $request['parents'] === ['folder-id']);
    }

    public function test_read_head_uses_a_range_request(): void
    {
        Http::fake([
            'https://www.googleapis.com/drive/v3/files/file-id*' => Http::response('abcd', 206),
        ]);

        $client = $this->client(fn () => ['id' => 'file-id', 'size' => 4, 'mimeType' => null]);

        $this->assertSame('abcd', $client->readHead('tmp/file.bin', 4));

        Http::assertSent(fn (Request $request) => in_array('bytes=0-3', $request->header('Range'), true));
    }

    public function test_space_usage_is_read_from_the_about_endpoint(): void
    {
        Http::fake([
            'https://www.googleapis.com/drive/v3/about*' => Http::response([
                'storageQuota' => ['usage' => '123', 'limit' => '1000'],
            ]),
        ]);

        $client = $this->client(fn () => null);

        $this->assertSame([
            'used_bytes' => 123,
            'allocated_bytes' => 1000,
        ], $client->spaceUsage());
    }

    public function test_missing_items_are_reported_as_null(): void
    {
        Http::fake([
            'https://www.googleapis.com/drive/v3/files*' => Http::response('', 404),
        ]);

        $client = $this->client(fn () => null);

        $this->assertNull($client->readHead('tmp/missing.bin', 8));
        $this->assertNull($client->spaceUsage()['used_bytes'] ?? null);
    }
}
