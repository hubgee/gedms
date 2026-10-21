<?php

namespace Tests\Feature\Uploads;

use App\Models\UploadSession;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UploadInitTest extends UploadTestCase
{
    public function test_init_creates_a_pending_session_with_correct_chunk_math(): void
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $session = $this->beginUpload($user, $space, null, 20);

        $this->assertSame(UploadSession::STATUS_PENDING, $session->status);
        $this->assertSame(3, $session->total_chunks); // 8 + 8 + 4
        $this->assertSame(8, $session->chunk_bytes);
        $this->assertSame(0, $session->received_bytes);
        $this->assertNotNull($session->uuid);
        $this->assertTrue($session->expires_at->isFuture());
        $this->assertDatabaseHas('upload_sessions', ['uuid' => $session->uuid, 'total_bytes' => 20]);
    }

    public function test_init_does_not_add_an_empty_trailing_chunk_for_exact_multiples(): void
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $session = $this->beginUpload($user, $space, null, 16);

        $this->assertSame(2, $session->total_chunks);
        $this->assertSame(8, $this->uploadService()->expectedChunkBytes($session, 1));
    }

    public function test_the_last_chunk_holds_the_remainder(): void
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $session = $this->beginUpload($user, $space, null, 20);

        $this->assertSame(8, $this->uploadService()->expectedChunkBytes($session, 0));
        $this->assertSame(4, $this->uploadService()->expectedChunkBytes($session, 2));
    }

    public function test_init_rejects_empty_files(): void
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $this->assertValidationFails(fn () => $this->beginUpload($user, $space, null, 0), 'size');

        $this->assertSame(0, UploadSession::query()->count());
    }

    public function test_init_rejects_files_over_the_configured_ceiling(): void
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $this->assertValidationFails(fn () => $this->beginUpload($user, $space, null, 1001), 'size');

        $this->assertSame(0, UploadSession::query()->count());
    }

    public function test_init_requires_access_to_the_space(): void
    {
        $owner = $this->makeUser();
        $space = $this->makePersonalSpace($owner);

        $this->expectException(HttpException::class);

        $this->beginUpload($this->makeUser(), $space, null, 20);
    }

    public function test_init_rejects_a_folder_from_another_space(): void
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);
        $otherSpace = $this->makePersonalSpace($this->makeUser());
        $foreignFolder = $this->makeFolder($otherSpace, 'Elsewhere');

        $this->assertValidationFails(
            fn () => $this->beginUpload($user, $space, $foreignFolder, 20),
            'folder_id',
        );

        $this->assertSame(0, UploadSession::query()->count());
    }

    public function test_init_requires_a_mandatory_folder_in_section_spaces(): void
    {
        $user = $this->makeUser();
        $space = $this->makeSectionSpace($user, ['Inbox']);

        $this->expectException(HttpException::class);

        $this->beginUpload($user, $space, null, 20);
    }

    public function test_init_allows_uploads_inside_a_mandatory_folder(): void
    {
        $user = $this->makeUser();
        $space = $this->makeSectionSpace($user, ['Inbox']);
        $folder = $this->makeFolder($space, 'Inbox');

        $session = $this->beginUpload($user, $space, $folder, 20);

        $this->assertSame($folder->id, $session->folder_id);
    }

    public function test_init_resumes_the_same_session_for_the_same_file_and_destination(): void
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $first = $this->beginUpload($user, $space, null, 20);
        $second = $this->beginUpload($user, $space, null, 20);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, UploadSession::query()->count());
    }

    public function test_init_starts_a_new_session_when_the_destination_folder_changes(): void
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);
        $folder = $this->makeFolder($space, 'Archive');

        $this->beginUpload($user, $space, null, 20);
        $this->beginUpload($user, $space, $folder, 20);

        $this->assertSame(2, UploadSession::query()->count());
    }

    public function test_init_returns_the_finished_session_instead_of_duplicating_a_completed_upload(): void
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $first = $this->beginUpload($user, $space, null, 20);
        $this->uploadService()->complete($this->uploadAllChunks($user, $first), $user);

        // Mark it finished without running the queue: the status is all init()
        // and the client care about.
        $first->forceFill([
            'status' => UploadSession::STATUS_COMPLETED,
            'file_id' => null,
        ])->save();

        $again = $this->beginUpload($user, $space, null, 20);

        $this->assertTrue($first->is($again), 'A finished upload must be reported back, not restarted.');
        $this->assertSame(UploadSession::STATUS_COMPLETED, $again->status);
        $this->assertSame(1, UploadSession::query()->count());
    }

    public function test_init_ignores_a_completed_upload_that_has_aged_out(): void
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $first = $this->beginUpload($user, $space, null, 20);
        $first->forceFill(['status' => UploadSession::STATUS_COMPLETED])->save();
        UploadSession::query()
            ->whereKey($first->id)
            ->update(['updated_at' => now()->subHours(49)]);

        $second = $this->beginUpload($user, $space, null, 20);

        $this->assertFalse($first->is($second));
        $this->assertSame(UploadSession::STATUS_PENDING, $second->status);
        $this->assertSame(2, UploadSession::query()->count());
    }

    public function test_init_still_resumes_a_finished_session_for_a_different_user(): void
    {
        // Guard the lookup: the completed branch must stay scoped to the owner.
        $owner = $this->makeUser();
        $space = $this->makePersonalSpace($owner);
        $first = $this->beginUpload($owner, $space, null, 20);
        $first->forceFill(['status' => UploadSession::STATUS_COMPLETED])->save();

        $otherUser = $this->makeUser();
        $otherSpace = $this->makePersonalSpace($otherUser);

        $second = $this->beginUpload($otherUser, $otherSpace, null, 20);

        $this->assertFalse($first->is($second));
        $this->assertSame($otherUser->id, $second->user_id);
        $this->assertSame(2, UploadSession::query()->count());
    }
}
