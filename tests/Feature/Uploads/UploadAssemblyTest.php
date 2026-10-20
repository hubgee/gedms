<?php

namespace Tests\Feature\Uploads;

use App\Jobs\AssembleUpload;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\UploadSession;

/**
 * Behaviour of the assembly job: verify, publish, clean up — and recover
 * gracefully when something went wrong between "complete" and assembly.
 */
class UploadAssemblyTest extends UploadTestCase
{
    public function test_the_job_assembles_and_publishes_the_file(): void
    {
        [, $session] = $this->readySession();
        $expected = $this->expectedFileContent($session);

        AssembleUpload::dispatchSync($session->id);

        $session->refresh();

        $this->assertSame(UploadSession::STATUS_COMPLETED, $session->status);
        $this->assertNull($session->failure_reason);
        $this->assertNotNull($session->file_id);

        $file = $session->file;

        $this->assertSame('file.bin', $file->name);
        $this->assertSame(20, (int) $file->size);
        $this->assertSame($session->storage_space_id, $file->storage_space_id);
        $this->assertSame($session->id, $file->upload_session_id);
        $this->assertSame($session->uuid, $file->uuid);

        $path = "{$session->storage_space_id}/{$session->uuid}/v1.bin";

        $this->assertTrue($this->edmsDisk()->exists($path));
        $this->assertFalse($this->edmsDisk()->exists($path.'.part'), 'No partial assembly may survive.');
        $this->assertSame($expected, $this->edmsDisk()->get($path));

        $version = FileVersion::query()->where('file_id', $file->id)->firstOrFail();
        $this->assertSame($path, $version->storage_path);
        $this->assertSame(20, (int) $version->size);

        // The chunks are only dropped once the file is safely published.
        $this->assertEmpty($this->edmsDisk()->allFiles("tmp/{$session->uuid}"));
    }

    public function test_it_verifies_the_declared_full_file_hash(): void
    {
        $content = $this->chunkContent(8, 0).$this->chunkContent(8, 1).$this->chunkContent(4, 2);

        [, $session] = $this->readySession(['sha256' => hash('sha256', $content)]);

        AssembleUpload::dispatchSync($session->id);

        $this->assertSame(UploadSession::STATUS_COMPLETED, $session->fresh()->status);
    }

    public function test_it_rejects_a_file_whose_full_hash_does_not_match(): void
    {
        [, $session] = $this->readySession(['sha256' => hash('sha256', 'something else')]);

        AssembleUpload::dispatchSync($session->id);

        $session->refresh();
        $path = "{$session->storage_space_id}/{$session->uuid}/v1.bin";

        $this->assertSame(UploadSession::STATUS_FAILED, $session->status);
        $this->assertStringContainsString('integrity', (string) $session->failure_reason);
        $this->assertSame(0, File::query()->count());
        $this->assertFalse($this->edmsDisk()->exists($path));
    }

    public function test_it_stores_the_mime_type_it_actually_detected(): void
    {
        [, $session] = $this->readySession(['name' => 'notes.txt', 'mime_type' => 'application/octet-stream']);

        AssembleUpload::dispatchSync($session->id);

        $this->assertSame('text/plain', $session->fresh()->file->mime_type);
    }

    public function test_it_rejects_an_executable_php_payload(): void
    {
        $payload = '<?php echo "pwned";';

        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->uploadService()->init($user, $space, null, 'innocent.bin', null, strlen($payload), 'php:1:1');
        $session = $this->uploadContent($user, $session, $payload);

        AssembleUpload::dispatchSync($session->id);

        $session->refresh();

        $this->assertSame(UploadSession::STATUS_FAILED, $session->status);
        $this->assertStringContainsString('PHP', (string) $session->failure_reason);
        $this->assertSame(0, File::query()->count());
        $this->assertFalse($this->edmsDisk()->exists("{$space->id}/{$session->uuid}/v1.bin"));
    }

    public function test_it_publishes_into_the_destination_folder(): void
    {
        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $folder = $this->makeFolder($space, 'Inbox');

        $session = $this->beginUpload($user, $space, $folder, 20);
        $session = $this->uploadAllChunks($user, $session);

        $this->assertSame(20, $session->received_bytes, 'Fixture chunks were not uploaded.');
        $this->assertSame([], $session->missingChunkIndexes());

        AssembleUpload::dispatchSync($session->id);

        $fresh = $session->fresh();

        $this->assertNotNull(
            $fresh->file,
            'Assembly did not publish a file: '.$fresh->status.' — '.(string) $fresh->failure_reason,
        );
        $this->assertSame($folder->id, $fresh->file->folder_id);
    }

    public function test_running_the_job_twice_publishes_only_once(): void
    {
        [, $session] = $this->readySession();

        AssembleUpload::dispatchSync($session->id);
        AssembleUpload::dispatchSync($session->id);

        $this->assertSame(1, File::query()->count());
        $this->assertSame(1, FileVersion::query()->count());
        $this->assertSame(UploadSession::STATUS_COMPLETED, $session->fresh()->status);
    }

    public function test_missing_chunks_revert_the_session_to_uploading(): void
    {
        [, $session] = $this->readySession();

        // Simulates bytes lost between "complete" and assembly.
        $this->edmsDisk()->delete("tmp/{$session->uuid}/1.chunk");

        AssembleUpload::dispatchSync($session->id);

        $session->refresh();

        $this->assertSame(UploadSession::STATUS_UPLOADING, $session->status);
        $this->assertSame(0, File::query()->count());
        $this->assertSame(2, $session->chunks()->count());
        $this->assertContains(1, $session->missingChunkIndexes());
        $this->assertSame(0, FileVersion::query()->count());
    }

    public function test_it_fails_when_the_destination_folder_was_deleted(): void
    {
        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $folder = $this->makeFolder($space, 'Inbox');

        $session = $this->beginUpload($user, $space, $folder, 20);
        $this->uploadAllChunks($user, $session);

        $folder->delete();

        AssembleUpload::dispatchSync($session->id);

        $session->refresh();

        $this->assertSame(UploadSession::STATUS_FAILED, $session->status);
        $this->assertStringContainsString('folder', (string) $session->failure_reason);
        $this->assertSame(0, File::query()->count());
    }

    /**
     * @param  array{folder?: \App\Models\Folder, name?: string, mime_type?: string, sha256?: string}  $overrides
     * @return array{0: \App\Models\User, 1: UploadSession}
     */
    private function readySession(array $overrides = []): array
    {
        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);

        $session = $this->uploadService()->init(
            $user,
            $space,
            $overrides['folder'] ?? null,
            $overrides['name'] ?? 'file.bin',
            $overrides['mime_type'] ?? 'text/plain',
            20,
            'file.bin:20:1',
            $overrides['sha256'] ?? null,
        );

        return [$user, $this->uploadAllChunks($user, $session)];
    }
}
