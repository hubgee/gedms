<?php

namespace Tests\Feature\Uploads;

use App\Models\UploadSession;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UploadChunkTest extends UploadTestCase
{
    public function test_a_chunk_is_stored_atomically_and_indexed(): void
    {
        [$user, $session] = $this->context();
        $content = $this->chunkContent(8, 0);

        $session = $this->uploadService()->putChunk($session, $user, 0, $this->makeChunk(8, 0));

        $path = "tmp/{$session->uuid}/0.chunk";

        $this->assertTrue($this->edmsDisk()->exists($path));
        $this->assertFalse($this->edmsDisk()->exists($path.'.part'), 'The temporary .part file must not survive a successful write.');
        $this->assertSame($content, $this->edmsDisk()->get($path));
        $this->assertSame(8, $session->received_bytes);
        $this->assertSame(UploadSession::STATUS_UPLOADING, $session->status);
        $this->assertSame([0], $session->receivedChunkIndexes());
        $this->assertDatabaseHas('upload_chunks', [
            'upload_session_id' => $session->id,
            'chunk_index' => 0,
            'bytes' => 8,
        ]);
    }

    public function test_re_sending_a_chunk_is_idempotent(): void
    {
        [$user, $session] = $this->context();

        $this->uploadService()->putChunk($session, $user, 0, $this->makeChunk(8, 0));
        $session = $this->uploadService()->putChunk($session, $user, 0, $this->makeChunk(8, 0));

        $this->assertSame(1, $session->chunks()->count());
        $this->assertSame(8, $session->received_bytes);
    }

    public function test_it_accepts_a_chunk_whose_hash_matches(): void
    {
        [$user, $session] = $this->context();
        $content = $this->chunkContent(8, 0);

        $session = $this->uploadService()->putChunk($session, $user, 0, $this->makeChunk(8, 0), $this->sha256For($content));

        $this->assertSame(8, $session->received_bytes);
        $this->assertSame($this->sha256For($content), $session->chunks()->first()->sha256);
    }

    public function test_it_rejects_an_out_of_range_index(): void
    {
        [$user, $session] = $this->context();

        $this->assertValidationFails(
            fn () => $this->uploadService()->putChunk($session, $user, 3, $this->makeChunk(8)),
            'index',
        );
    }

    public function test_it_rejects_a_chunk_with_the_wrong_size_without_leaving_residue(): void
    {
        [$user, $session] = $this->context();

        $this->assertValidationFails(
            fn () => $this->uploadService()->putChunk($session, $user, 0, $this->makeChunk(7)),
            'chunk',
        );

        $this->assertSame(0, $session->fresh()->chunks()->count());
        $this->assertSame(0, $session->fresh()->received_bytes);
        $this->assertFalse($this->edmsDisk()->exists("tmp/{$session->uuid}/0.chunk"));
        $this->assertFalse($this->edmsDisk()->exists("tmp/{$session->uuid}/0.chunk.part"));
    }

    public function test_it_rejects_a_chunk_that_fails_its_integrity_check(): void
    {
        [$user, $session] = $this->context();

        $this->assertValidationFails(
            fn () => $this->uploadService()->putChunk($session, $user, 0, $this->makeChunk(8, 0), $this->sha256For('something else')),
            'chunk',
        );

        $this->assertSame(0, $session->fresh()->chunks()->count());
        $this->assertFalse($this->edmsDisk()->exists("tmp/{$session->uuid}/0.chunk"));
    }

    public function test_it_rejects_a_chunk_that_did_not_arrive_intact(): void
    {
        [$user, $session] = $this->context();

        $path = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($path, $this->chunkContent(8));
        $truncated = new UploadedFile($path, 'chunk.part', null, UPLOAD_ERR_PARTIAL, true);

        $this->assertValidationFails(
            fn () => $this->uploadService()->putChunk($session, $user, 0, $truncated),
            'chunk',
        );

        $this->assertSame(0, $session->fresh()->chunks()->count());
    }

    public function test_a_user_cannot_upload_to_someone_elses_session(): void
    {
        [, $session] = $this->context();

        $this->expectException(HttpException::class);

        $this->uploadService()->putChunk($session, $this->makeUser(), 0, $this->makeChunk(8));
    }

    public function test_chunks_are_rejected_once_the_session_is_cancelled(): void
    {
        [$user, $session] = $this->context();
        $this->uploadService()->cancel($session, $user);

        $this->assertValidationFails(
            fn () => $this->uploadService()->putChunk($session->fresh(), $user, 0, $this->makeChunk(8)),
            'upload',
        );
    }

    public function test_chunks_are_rejected_once_the_session_has_expired(): void
    {
        [$user, $session] = $this->context();
        $session->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertValidationFails(
            fn () => $this->uploadService()->putChunk($session->fresh(), $user, 0, $this->makeChunk(8)),
            'upload',
        );

        $this->assertSame(UploadSession::STATUS_EXPIRED, $session->fresh()->status);
    }

    /** @return array{0: \App\Models\User, 1: UploadSession} */
    private function context(): array
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        return [$user, $this->beginUpload($user, $space, null, 20)];
    }
}
