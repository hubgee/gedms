<?php

namespace Tests\Feature\Uploads;

use App\Models\UploadSession;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UploadStatusTest extends UploadTestCase
{
    public function test_status_reports_exactly_the_missing_indexes(): void
    {
        [$user, $session] = $this->partialSession();

        $status = $this->uploadService()->status($session, $user);

        $this->assertSame([0, 1], $status['received']);
        $this->assertSame([2], $status['missing']);
        $this->assertSame(16, $status['received_bytes']);
        $this->assertSame(UploadSession::STATUS_UPLOADING, $status['status']);
        $this->assertSame(3, $status['total_chunks']);
    }

    public function test_status_heals_a_chunk_file_that_disappeared(): void
    {
        [$user, $session] = $this->partialSession();

        // Simulates bytes lost to a crash or disk failure: the index still has
        // the row, but the file is gone.
        $this->edmsDisk()->delete("tmp/{$session->uuid}/0.chunk");

        $status = $this->uploadService()->status($session, $user);

        $this->assertSame([1], $status['received']);
        $this->assertSame([0, 2], $status['missing']);
        $this->assertSame(8, $status['received_bytes']);
        $this->assertSame(1, $session->fresh()->chunks()->count());
    }

    public function test_status_heals_a_truncated_chunk_file(): void
    {
        [$user, $session] = $this->partialSession();

        // Simulates a partial write that somehow reached the final name.
        $this->edmsDisk()->put("tmp/{$session->uuid}/1.chunk", 'trunc');

        $status = $this->uploadService()->status($session, $user);

        $this->assertSame([0], $status['received']);
        $this->assertSame([1, 2], $status['missing']);
        $this->assertSame(8, $status['received_bytes']);
    }

    public function test_status_still_asks_for_a_chunk_that_was_written_but_never_indexed(): void
    {
        [$user, $session] = $this->partialSession();

        // Crash window: bytes were renamed into place but the index row was
        // never inserted. The stray file must not be trusted as received.
        $this->edmsDisk()->put("tmp/{$session->uuid}/2.chunk", $this->chunkContent(4, 2));

        $status = $this->uploadService()->status($session, $user);

        $this->assertContains(2, $status['missing']);
        $this->assertSame(16, $status['received_bytes']);
        $this->assertSame(2, $session->fresh()->chunks()->count());
    }

    public function test_cancel_drops_the_bytes_and_blocks_further_chunks(): void
    {
        [$user, $session] = $this->partialSession();

        $this->uploadService()->cancel($session, $user);

        $this->assertSame(UploadSession::STATUS_CANCELLED, $session->fresh()->status);
        $this->assertEmpty($this->edmsDisk()->allFiles("tmp/{$session->uuid}"));
        $this->assertValidationFails(
            fn () => $this->uploadService()->putChunk($session->fresh(), $user, 2, $this->makeChunk(4, 2)),
            'upload',
        );
    }

    public function test_status_is_owner_only(): void
    {
        [, $session] = $this->partialSession();

        $this->expectException(HttpException::class);

        $this->uploadService()->status($session, $this->makeUser());
    }

    /** @return array{0: \App\Models\User, 1: UploadSession} */
    private function partialSession(): array
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        foreach ([0, 1] as $index) {
            $session = $this->uploadService()->putChunk($session, $user, $index, $this->makeChunk(8, $index));
        }

        return [$user, $session];
    }
}
