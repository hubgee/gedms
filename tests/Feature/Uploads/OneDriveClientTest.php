<?php

namespace Tests\Feature\Uploads;

use App\Services\Uploads\OneDriveClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OneDrive maps storage paths directly onto Graph paths, so the tests can
 * assert the exact URLs without simulating Drive's folder-id lookups.
 */
class OneDriveClientTest extends TestCase
{
    private function client(): OneDriveClient
    {
        return new class extends OneDriveClient
        {
            public function __construct()
            {
                parent::__construct('client-id', 'client-secret', 'refresh-token', 'common', '/edms');
            }

            public function accessToken(): string
            {
                return 'access-token';
            }

            protected function ensureFolder(string $directory): void
            {
                // Folder creation is covered by the Graph contract; these tests
                // focus on the byte and metadata calls.
            }
        };
    }

    public function test_a_small_upload_uses_the_simple_content_endpoint(): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'edms-onedrive-test-');
        file_put_contents($temporary, 'hello');

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/drive/root:/edms/file.bin:/content' => Http::response(['id' => 'id'], 200),
        ]);

        $this->client()->uploadFile('file.bin', $temporary);

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/root:/edms/file.bin:/content'));

        @unlink($temporary);
    }

    public function test_find_returns_size_and_folder_flag(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/drive/root:/edms/file.bin*' => Http::response([
                'id' => 'item-id',
                'name' => 'file.bin',
                'size' => 5,
                'file' => [],
            ]),
        ]);

        $item = $this->client()->find('file.bin');

        $this->assertSame('item-id', $item['id']);
        $this->assertSame(5, $item['size']);
        $this->assertFalse($item['is_folder']);
    }

    public function test_space_usage_is_read_from_the_drive_quota(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/drive*' => Http::response([
                'quota' => ['used' => 123, 'total' => 1000],
            ]),
        ]);

        $this->assertSame([
            'used_bytes' => 123,
            'allocated_bytes' => 1000,
        ], $this->client()->spaceUsage());
    }

    public function test_copy_replaces_the_destination_and_names_the_parent_folder(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'DELETE' && str_contains($request->url(), '/root:/edms/tmp/dest.bin')) {
                return Http::response('', 204);
            }

            if ($request->method() === 'POST' && str_contains($request->url(), '/root:/edms/tmp/source.bin:/copy')) {
                return Http::response(['id' => 'copy-id'], 200);
            }

            return Http::response('unexpected request', 500);
        });

        $this->client()->copy('tmp/source.bin', 'tmp/dest.bin');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/root:/edms/tmp/dest.bin'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/root:/edms/tmp/source.bin:/copy')
            && $request['name'] === 'dest.bin'
            && $request['parentReference']['path'] === '/drive/root:/edms/tmp');
    }

    public function test_a_missing_item_returns_null(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/drive/root:/edms/missing.bin*' => Http::response('', 404),
        ]);

        $this->assertNull($this->client()->find('missing.bin'));
    }
}
