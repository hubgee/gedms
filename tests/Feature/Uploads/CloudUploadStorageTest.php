<?php

namespace Tests\Feature\Uploads;

use App\Contracts\UploadStorage;
use App\Models\File;
use App\Models\UploadSession;
use App\Services\FileService;
use App\Services\Uploads\CloudFaultProfile;
use App\Services\Uploads\CloudFaults;
use App\Services\Uploads\DropboxClient;
use App\Services\Uploads\DropboxUploadStorage;
use App\Services\Uploads\StorageUnavailableException;
use App\Services\Uploads\UploadStorageFactory;
use App\Services\Uploads\UploadVerificationException;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDropbox;

/**
 * The cloud-specific half of the upload test suite.
 *
 * Two things are exercised here:
 *
 *  1. the fault injector, which lets the local disk behave like a cloud
 *     provider on a bad day (latency, dropped connections, stale reads, a full
 *     bucket), so the recovery logic can be tested without an account;
 *
 *  2. the Dropbox backend, driven against an in-memory reproduction of the
 *     Dropbox API, so the code path that has no local file at all - the whole
 *     point of the cloud integration - is covered by tests and not only by
 *     manual runs.
 */
class CloudUploadStorageTest extends UploadTestCase
{
    protected function tearDown(): void
    {
        CloudFaults::clear();

        parent::tearDown();
    }

    /**
     * Put a fault profile in force before the byte store is built.
     */
    protected function useFaultProfile(CloudFaultProfile $profile): void
    {
        CloudFaults::push($profile);
        $this->app->forgetScopedInstances();
    }

    /**
     * Stop simulating faults.
     */
    protected function stopFaults(): void
    {
        $this->useFaultProfile(CloudFaultProfile::none());
    }

    /**
     * Bind the Dropbox backend as the application's byte store.
     */
    protected function useDropbox(): FakeDropbox
    {
        $fake = FakeDropbox::install('/edms');

        config([
            'cache.default' => 'array',
            'uploads.dropbox.app_key' => 'test-key',
            'uploads.dropbox.app_secret' => 'test-secret',
            'uploads.dropbox.refresh_token' => 'test-refresh',
            'uploads.dropbox.root' => '/edms',
        ]);

        $storage = new DropboxUploadStorage(new DropboxClient(
            'test-key',
            'test-secret',
            'test-refresh',
            '/edms',
            30,
        ));

        $this->app->instance(UploadStorage::class, $storage);

        return $fake;
    }

    protected function chunkPathFor(UploadSession $session, int $index): string
    {
        return $this->uploadService()->temporaryDirectory($session).'/'.$index.'.chunk';
    }

    // ---------------------------------------------------------------------
    // Application-level fault injection
    // ---------------------------------------------------------------------

    public function test_a_transient_provider_failure_is_retryable(): void
    {
        $this->useFaultProfile(new CloudFaultProfile(failureRate: 1.0));

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space);

        try {
            $this->uploadService()->putChunk($session, $user, 0, $this->makeChunk(8));
            $this->fail('A transient provider failure must be reported.');
        } catch (StorageUnavailableException $e) {
            // A 503, not a 422: the browser retries this chunk.
            $this->assertStringContainsString('temporarily unavailable', $e->getMessage());
        }

        // The failure happened before the index was touched, so a retry starts
        // from a clean state instead of a half-recorded chunk.
        $this->assertSame(0, $session->fresh()->received_bytes);
        $this->assertSame([], $session->fresh()->receivedChunkIndexes());
        $this->assertDatabaseCount('upload_chunks', 0);
    }

    public function test_a_transient_storage_failure_returns_503_so_the_browser_retries(): void
    {
        $this->useFaultProfile(new CloudFaultProfile(failureRate: 1.0));

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        $this->actingAs($user)->put(
            route('uploads.chunks.store', ['uuid' => $session->uuid, 'index' => 0]),
            ['chunk' => $this->makeChunk(8, 0)],
            ['Accept' => 'application/json'],
        )->assertStatus(503);
    }

    public function test_an_exhausted_quota_fails_the_operation_without_corrupting_the_session(): void
    {
        $this->useFaultProfile(new CloudFaultProfile(quotaExceeded: true));

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space);

        $exception = $this->assertValidationFails(
            fn () => $this->uploadService()->putChunk($session, $user, 0, $this->makeChunk(8)),
            'chunk',
        );

        $this->assertStringContainsString('quota is exhausted', $exception->errors()['chunk'][0]);

        $session->refresh();
        $this->assertSame(UploadSession::STATUS_PENDING, $session->status);
        $this->assertSame(0, $session->received_bytes);
        $this->assertSame([0, 1, 2], $session->missingChunkIndexes());
    }

    public function test_a_chunk_cut_mid_transfer_is_refused_and_the_retry_completes_the_upload(): void
    {
        $this->useFaultProfile(new CloudFaultProfile(dropAfterBytes: 4));

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        try {
            $this->uploadService()->putChunk($session, $user, 0, $this->makeChunk(8));
            $this->fail('A dropped connection must be reported.');
        } catch (StorageUnavailableException $e) {
            $this->assertStringContainsString('connection dropped', $e->getMessage());
        }

        // The connection dropped after 4 bytes: a short object is on the store,
        // and the client was told to send the chunk again.
        $this->assertSame(4, app(UploadStorage::class)->size($this->chunkPathFor($session, 0)));
        $this->assertSame([0, 1, 2], $session->fresh()->missingChunkIndexes());

        // The link recovers. The client re-sends every chunk, which overwrites
        // the short object rather than appending to it.
        $this->stopFaults();

        $session = $this->uploadAllChunks($user, $session->fresh());

        // The retried chunk replaced the short object instead of appending to
        // it, so the temporary chunk is exactly the size the client declared.
        $this->assertSame(8, app(UploadStorage::class)->size($this->chunkPathFor($session, 0)));

        $this->uploadService()->complete($session, $user);

        $file = File::query()->where('upload_session_id', $session->id)->firstOrFail();
        $version = $file->versions()->where('version', 1)->firstOrFail();

        $this->assertSame(
            $this->expectedFileContent($session->fresh()),
            app(UploadStorage::class)->readHead($version->storage_path, 1024),
        );
    }

    public function test_an_eventually_consistent_store_reports_a_chunk_as_missing_until_it_catches_up(): void
    {
        $this->useFaultProfile(new CloudFaultProfile(eventualConsistencyReads: 1));

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        $session = $this->uploadService()->putChunk($session, $user, 0, $this->makeChunk(8));

        $this->assertSame(8, $session->received_bytes);

        // The provider accepted the write but still answers "not there" the
        // first time it is read. The index trusts the store over itself, so the
        // chunk is reported missing and the client sends it again.
        $status = $this->uploadService()->status($session, $user);

        $this->assertContains(0, $status['missing']);
        $this->assertSame(0, $status['received_bytes']);

        // Nothing was actually lost: once the read lag clears, the bytes are
        // there with the right size.
        $this->stopFaults();

        $path = $this->chunkPathFor($session, 0);

        $this->assertTrue(app(UploadStorage::class)->exists($path));
        $this->assertSame(8, app(UploadStorage::class)->size($path));
    }

    public function test_a_chunk_with_a_wrong_hash_is_never_indexed(): void
    {
        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space);

        $exception = $this->assertValidationFails(
            fn () => $this->uploadService()->putChunk(
                $session,
                $user,
                0,
                $this->makeChunk(8),
                str_repeat('0', 64),
            ),
            'chunk',
        );

        $this->assertStringContainsString('integrity check', $exception->errors()['chunk'][0]);
        $this->assertDatabaseCount('upload_chunks', 0);
    }

    public function test_assembly_rejects_bytes_that_do_not_match_the_declared_hash(): void
    {
        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);

        $session = $this->uploadService()->init(
            $user,
            $space,
            null,
            'file.bin',
            'application/octet-stream',
            20,
            'assembly-hash-mismatch',
            str_repeat('a', 64),
        );

        $session = $this->uploadAllChunks($user, $session);

        $path = app(FileService::class)->buildStoragePathFor($space, $session->uuid, 1, $session->name);

        try {
            $this->uploadService()->assemble($session);
            $this->fail('A mismatching assembly must not be published.');
        } catch (UploadVerificationException $e) {
            $this->assertStringContainsString('integrity check', $e->getMessage());
        }

        $this->assertFalse(app(UploadStorage::class)->exists($path));
        $this->assertNull(File::query()->where('upload_session_id', $session->id)->first());
    }

    // ---------------------------------------------------------------------
    // Dropbox backend
    // ---------------------------------------------------------------------

    public function test_the_dropbox_backend_appends_the_chunks_to_the_session_in_order(): void
    {
        $fake = $this->useDropbox();

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        $session = $this->uploadAllChunks($user, $session);
        $this->uploadService()->assemble($session);

        $appends = $fake->callsTo('files/upload_session/append_v2');

        $this->assertCount(3, $appends);
        $this->assertSame([0, 8, 16], array_column($appends, 'offset'));
        $this->assertSame([8, 8, 4], array_column($appends, 'bytes'));

        $file = File::query()->where('upload_session_id', $session->id)->firstOrFail();
        $version = $file->versions()->where('version', 1)->firstOrFail();

        $this->assertSame($this->expectedFileContent($session->fresh()), $fake->contentAt($version->storage_path));
    }

    public function test_the_dropbox_backend_serves_a_ranged_read_without_downloading_the_whole_object(): void
    {
        $fake = $this->useDropbox();

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        $session = $this->uploadAllChunks($user, $session);
        $this->uploadService()->assemble($session);

        $file = File::query()->where('upload_session_id', $session->id)->firstOrFail();
        $version = $file->versions()->where('version', 1)->firstOrFail();
        $content = $this->expectedFileContent($session->fresh());

        $stream = app(UploadStorage::class)->readStream($version->storage_path, 4, 6);
        $this->assertIsResource($stream);

        $slice = stream_get_contents($stream);
        fclose($stream);

        $this->assertSame(substr($content, 4, 6), $slice);

        $downloads = $fake->callsTo('files/download');
        $last = end($downloads);

        $this->assertSame('bytes=4-9', $last['range']);
    }

    public function test_the_dropbox_backend_rejects_an_executable_payload(): void
    {
        $fake = $this->useDropbox();

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);

        $payload = '<?php phpinfo();?>'.str_repeat(' ', 3);

        $session = $this->beginUpload($user, $space, null, strlen($payload), 'php-payload');
        $session = $this->uploadContent($user, $session, $payload);

        $path = app(FileService::class)->buildStoragePathFor($space, $session->uuid, 1, $session->name);

        try {
            $this->uploadService()->assemble($session);
            $this->fail('An executable payload must not be published.');
        } catch (UploadVerificationException $e) {
            $this->assertStringContainsString('executable PHP', $e->getMessage());
        }

        // The check worked even though Dropbox has no local path to inspect,
        // and the rejected bytes were removed from the provider.
        $this->assertFalse($fake->hasAt($path));
        $this->assertNull(File::query()->where('upload_session_id', $session->id)->first());
    }

    public function test_a_download_follows_the_configured_byte_store(): void
    {
        $fake = $this->useDropbox();

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        $session = $this->uploadAllChunks($user, $session);
        $this->uploadService()->assemble($session);

        $file = File::query()->where('upload_session_id', $session->id)->firstOrFail();
        $content = $this->expectedFileContent($session->fresh());

        $response = app(FileService::class)->download($file, $user);

        $this->assertSame('attachment; filename="file.bin"', $response->headers->get('Content-Disposition'));
        $this->assertSame((string) strlen($content), $response->headers->get('Content-Length'));

        ob_start();
        $response->sendContent();
        $body = ob_get_clean();

        $this->assertSame($content, $body);
        $this->assertTrue($fake->hasAt('1/'.$file->uuid.'/v1.bin'));
    }

    public function test_the_dropbox_backend_copies_a_published_file(): void
    {
        $fake = $this->useDropbox();

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        $session = $this->uploadAllChunks($user, $session);
        $this->uploadService()->assemble($session);

        $file = File::query()->where('upload_session_id', $session->id)->firstOrFail();
        $version = $file->versions()->where('version', 1)->firstOrFail();

        $destination = 'copies/'.$file->uuid.'/v1.bin';

        app(UploadStorage::class)->copy($version->storage_path, $destination);

        $this->assertSame($this->expectedFileContent($session->fresh()), $fake->contentAt($destination));
        $this->assertCount(1, $fake->callsTo('files/copy_v2'));
    }

    public function test_a_truncated_chunk_in_the_cloud_is_reported_as_missing(): void
    {
        $fake = $this->useDropbox();

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        $session = $this->uploadService()->putChunk($session, $user, 0, $this->makeChunk(8));

        // A provider that acknowledged 8 bytes but kept 4 is exactly the kind
        // of silent truncation the index has to survive.
        $fake->overwriteAt($this->chunkPathFor($session, 0), 'ABCD');

        $session = $this->uploadService()->reconcileChunks($session->fresh());

        $this->assertSame([0, 1, 2], $session->missingChunkIndexes());
        $this->assertSame(0, $session->received_bytes);
    }

    public function test_the_dropbox_backend_deletes_the_whole_temporary_directory(): void
    {
        $fake = $this->useDropbox();

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        $session = $this->uploadAllChunks($user, $session);
        $this->uploadService()->assemble($session);

        $directory = $this->uploadService()->temporaryDirectory($session);

        $this->assertFalse($fake->hasAt($directory.'/0.chunk'));
        $this->assertFalse($fake->hasAt($directory.'/1.chunk'));
        $this->assertFalse($fake->hasAt($directory.'/2.chunk'));
        $this->assertTrue(app(UploadStorage::class)->isEmpty($directory));
    }

    public function test_the_dropbox_backend_reports_healthy(): void
    {
        $this->useDropbox();

        $this->assertTrue(app(UploadStorage::class)->healthCheck());
    }

    public function test_the_dropbox_backend_creates_missing_parent_folders_before_writing(): void
    {
        $fake = $this->useDropbox();

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 8);

        $this->uploadService()->putChunk($session, $user, 0, $this->makeChunk(8));

        $created = array_column($fake->callsTo('files/create_folder_v2'), 'path');

        $this->assertContains('/edms/tmp', $created);
        $this->assertContains('/edms/tmp/'.$session->uuid, $created);
    }

    public function test_the_dropbox_backend_creates_each_folder_only_once_per_session(): void
    {
        $fake = $this->useDropbox();

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        // The three chunks arrive as three separate requests in production.
        // Simulating them sequentially is enough to prove the folder result is
        // cached: the second request must not create anything again.
        $this->uploadService()->putChunk($session, $user, 0, $this->makeChunk(8));
        $this->uploadService()->putChunk($session, $user, 1, $this->makeChunk(8));

        $created = array_column($fake->callsTo('files/create_folder_v2'), 'path');
        $counts = array_count_values($created);

        $this->assertSame(1, $counts['/edms/tmp'] ?? 0);
        $this->assertSame(1, $counts['/edms/tmp/'.$session->uuid] ?? 0);
    }

    public function test_a_rate_limited_folder_create_is_retried_instead_of_failing_the_chunk(): void
    {
        $fake = $this->useDropbox();

        $fake->failNext('files/create_folder_v2', [
            'error' => [
                'reason' => ['.tag' => 'too_many_write_operations'],
                'retry_after' => 0,
            ],
            'error_summary' => '',
        ], 409);

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        $this->uploadService()->putChunk($session, $user, 0, $this->makeChunk(8));

        $this->assertTrue($fake->hasAt($this->chunkPathFor($session, 0)));
        $this->assertSame(8, $session->fresh()->received_bytes);
    }

    public function test_a_persistent_rate_limit_is_reported_as_retryable(): void
    {
        $fake = $this->useDropbox();

        $fake->failAlways('files/create_folder_v2', [
            'error' => [
                'reason' => ['.tag' => 'too_many_write_operations'],
                'retry_after' => 0,
            ],
            'error_summary' => '',
        ], 409);

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        try {
            $this->uploadService()->putChunk($session, $user, 0, $this->makeChunk(8));
            $this->fail('A persistent rate limit must be reported.');
        } catch (StorageUnavailableException $e) {
            // The admin sees the tag, not the raw JSON body.
            $this->assertStringContainsString('too_many_write_operations', $e->getMessage());
        }

        $this->assertSame(0, $session->fresh()->received_bytes);
        $this->assertDatabaseCount('upload_chunks', 0);
    }

    public function test_dropbox_upload_sessions_use_the_cursor_and_commit_arguments(): void
    {
        $arguments = [];

        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$arguments) {
            $url = $request->url();

            if (str_contains($url, '/oauth2/token')) {
                return Http::response(['access_token' => 'token', 'expires_in' => 3600]);
            }

            $header = $request->header('Dropbox-API-Arg')[0] ?? '{}';
            $argument = json_decode($header, true);
            $endpoint = \Illuminate\Support\Str::afterLast(parse_url($url, PHP_URL_PATH) ?: '', '/2/');
            $arguments[$endpoint] = is_array($argument) ? $argument : [];

            if ($endpoint === 'files/get_metadata') {
                return Http::response(['.tag' => 'folder', 'name' => 'edms']);
            }

            if ($endpoint === 'files/upload_session/start') {
                return Http::response(['session_id' => 'session-1']);
            }

            return Http::response([]);
        });

        $chunk = tempnam(sys_get_temp_dir(), 'edms-chunk-');
        $empty = tempnam(sys_get_temp_dir(), 'edms-empty-');
        file_put_contents($chunk, 'abc');

        try {
            $client = new DropboxClient('key', 'secret', 'refresh', '/edms', 30);

            $client->startUploadSession();
            $client->appendToSession('session-1', 0, $chunk);
            $client->finishUploadSession('session-1', 3, 'final.bin', $empty);
        } finally {
            @unlink($chunk);
            @unlink($empty);
        }

        $this->assertSame('session-1', $arguments['files/upload_session/append_v2']['cursor']['session_id']);
        $this->assertSame(0, $arguments['files/upload_session/append_v2']['cursor']['offset']);

        $this->assertSame('session-1', $arguments['files/upload_session/finish']['cursor']['session_id']);
        $this->assertSame(3, $arguments['files/upload_session/finish']['cursor']['offset']);
        $this->assertSame('/edms/final.bin', $arguments['files/upload_session/finish']['commit']['path']);
    }

    public function test_dropbox_rpc_requests_send_a_json_object_body(): void
    {
        config([
            'cache.default' => 'array',
            'uploads.dropbox.app_key' => 'test-key',
            'uploads.dropbox.app_secret' => 'test-secret',
            'uploads.dropbox.refresh_token' => 'test-refresh',
            'uploads.dropbox.root' => '/edms',
        ]);

        Http::fake([
            'api.dropboxapi.com/oauth2/token' => Http::response([
                'access_token' => 'fake-token',
                'expires_in' => 3600,
            ]),
            'api.dropboxapi.com/2/files/list_folder' => Http::response([
                'entries' => [],
                'has_more' => false,
            ]),
        ]);

        $client = new DropboxClient('test-key', 'test-secret', 'test-refresh', '/edms', 30);
        $client->listChildren('');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/files/list_folder')) {
                return false;
            }

            $decoded = json_decode($request->body(), true);

            return is_array($decoded) && ! array_is_list($decoded);
        });
    }

    public function test_the_local_backend_reports_healthy(): void
    {
        $this->assertTrue(app(UploadStorage::class)->healthCheck());
    }

    public function test_the_factory_builds_every_documented_driver(): void
    {
        config([
            'cache.default' => 'array',
            'uploads.dropbox.app_key' => 'test-key',
            'uploads.dropbox.app_secret' => 'test-secret',
            'uploads.dropbox.refresh_token' => 'test-refresh',
        ]);

        $factory = app(UploadStorageFactory::class);

        $this->assertInstanceOf(
            \App\Services\Uploads\LocalUploadStorage::class,
            $factory->makeBackend('local'),
        );

        $this->assertInstanceOf(
            \App\Services\Uploads\S3UploadStorage::class,
            $factory->makeBackend('s3'),
        );

        $this->assertInstanceOf(
            DropboxUploadStorage::class,
            $factory->makeBackend('dropbox'),
        );
    }

    public function test_an_unknown_driver_is_rejected_loudly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown upload storage driver');

        app(UploadStorageFactory::class)->makeBackend('ftp');
    }

    public function test_the_dropbox_driver_refuses_to_start_without_credentials(): void
    {
        config([
            'uploads.dropbox.app_key' => '',
            'uploads.dropbox.app_secret' => '',
            'uploads.dropbox.refresh_token' => '',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DROPBOX_APP_KEY');

        app(UploadStorageFactory::class)->makeBackend('dropbox');
    }

    public function test_the_http_api_is_only_reached_when_the_dropbox_driver_is_selected(): void
    {
        // Anything that leaves the machine would raise instead of silently
        // passing through a real network call.
        Http::preventStrayRequests();

        // The local driver is the default, and it must not touch the network.
        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        $this->assertInstanceOf(
            \App\Services\Uploads\LocalUploadStorage::class,
            app(UploadStorage::class),
        );

        $this->uploadAllChunks($user, $session);
    }
}
