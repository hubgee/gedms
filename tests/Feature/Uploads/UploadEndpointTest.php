<?php

namespace Tests\Feature\Uploads;

use App\Models\StorageSpace;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Contract tests for the resumable-upload HTTP surface.
 *
 * The controller is a thin adapter, so these tests focus on the things it owns:
 * routing, authentication/authorization, validation and response shape.
 */
class UploadEndpointTest extends UploadTestCase
{
    public function test_init_returns_a_resumable_session(): void
    {
        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);

        $response = $this->actingAs($user)->postJson(route('uploads.store'), $this->initPayload($space));

        $response->assertCreated()
            ->assertJsonPath('name', 'archive.bin')
            ->assertJsonPath('status', UploadSession::STATUS_PENDING)
            ->assertJsonPath('total_bytes', 20)
            ->assertJsonPath('chunk_bytes', 8)
            ->assertJsonPath('total_chunks', 3)
            ->assertJsonPath('received_bytes', 0)
            ->assertJsonPath('file_id', null)
            ->assertJsonStructure(['uuid', 'expires_at']);

        $this->assertSame([], $response->json('received'));
        $this->assertSame([0, 1, 2], $response->json('missing'));
        $this->assertDatabaseCount('upload_sessions', 1);
    }

    public function test_init_resumes_the_same_session_for_a_repeated_request(): void
    {
        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);

        $first = $this->actingAs($user)->postJson(route('uploads.store'), $this->initPayload($space));
        $second = $this->actingAs($user)->postJson(route('uploads.store'), $this->initPayload($space));

        $first->assertCreated();
        $second->assertCreated();

        $this->assertSame($first->json('uuid'), $second->json('uuid'));
        $this->assertDatabaseCount('upload_sessions', 1);
    }

    public function test_init_requires_authentication(): void
    {
        $owner = $this->makeUser();
        $space = $this->makePersonalSpace($owner);

        $this->postJson(route('uploads.store'), $this->initPayload($space))
            ->assertUnauthorized();
    }

    public function test_init_requires_the_create_files_permission(): void
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $this->actingAs($user)
            ->postJson(route('uploads.store'), $this->initPayload($space))
            ->assertForbidden();
    }

    public function test_init_validates_the_declared_file(): void
    {
        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);

        $this->actingAs($user)
            ->postJson(route('uploads.store'), [
                'space_id' => $space->id,
                'name' => '',
                'size' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'size', 'fingerprint']);
    }

    public function test_init_rejects_a_file_over_the_configured_ceiling(): void
    {
        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);

        $this->actingAs($user)
            ->postJson(route('uploads.store'), $this->initPayload($space, ['size' => 1001]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['size']);
    }

    public function test_status_returns_the_resume_point(): void
    {
        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        foreach ([0, 1] as $index) {
            $this->putChunk($user, $session, $index, 8, $index)->assertOk();
        }

        $response = $this->actingAs($user)->getJson(route('uploads.show', $session->uuid));

        $response->assertOk()
            ->assertJsonPath('uuid', $session->uuid)
            ->assertJsonPath('received_bytes', 16)
            ->assertJsonPath('status', UploadSession::STATUS_UPLOADING);

        $this->assertSame([0, 1], $response->json('received'));
        $this->assertSame([2], $response->json('missing'));
    }

    public function test_a_chunk_upload_stores_the_bytes_and_reports_progress(): void
    {
        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);
        $content = $this->chunkContent(8, 0);

        $response = $this->putChunk($user, $session, 0, 8, 0);

        $response->assertOk()
            ->assertJsonPath('uuid', $session->uuid)
            ->assertJsonPath('status', UploadSession::STATUS_UPLOADING)
            ->assertJsonPath('received_bytes', 8)
            ->assertJsonPath('total_bytes', 20)
            ->assertJsonPath('total_chunks', 3)
            ->assertJsonStructure(['expires_at']);

        $this->assertSame($content, $this->edmsDisk()->get("tmp/{$session->uuid}/0.chunk"));
    }

    public function test_a_chunk_upload_can_be_verified_with_a_hash(): void
    {
        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);
        $content = $this->chunkContent(8, 0);

        $this->actingAs($user)->put(
            route('uploads.chunks.store', ['uuid' => $session->uuid, 'index' => 0]),
            ['chunk' => $this->makeChunk(8, 0), 'sha256' => $this->sha256For($content)],
            ['Accept' => 'application/json'],
        )->assertOk();

        $this->assertSame(8, $session->fresh()->received_bytes);
    }

    public function test_a_chunk_upload_rejects_a_wrong_size(): void
    {
        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        $this->putChunk($user, $session, 0, 7, 0)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['chunk']);

        $this->assertSame(0, $session->fresh()->chunks()->count());
    }

    public function test_a_chunk_upload_rejects_an_out_of_range_index(): void
    {
        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);

        $this->putChunk($user, $session, 3, 8, 0)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['index']);
    }

    public function test_another_user_cannot_touch_the_session(): void
    {
        $owner = $this->makeUploader();
        $space = $this->makePersonalSpace($owner);
        $session = $this->beginUpload($owner, $space, null, 20);

        $intruder = $this->makeUploader();

        $this->putChunk($intruder, $session, 0, 8, 0)->assertForbidden();
        $this->actingAs($intruder)->getJson(route('uploads.show', $session->uuid))->assertForbidden();
        $this->actingAs($intruder)->deleteJson(route('uploads.destroy', $session->uuid))->assertForbidden();
    }

    public function test_an_unknown_upload_returns_404(): void
    {
        $user = $this->makeUploader();

        $this->actingAs($user)
            ->getJson(route('uploads.show', (string) Str::uuid()))
            ->assertNotFound();
    }

    public function test_a_non_uuid_upload_path_returns_404(): void
    {
        $user = $this->makeUploader();

        $this->actingAs($user)
            ->getJson('/uploads/not-a-uuid')
            ->assertNotFound();
    }

    public function test_cancel_drops_the_bytes_and_blocks_further_chunks(): void
    {
        $user = $this->makeUploader();
        $space = $this->makePersonalSpace($user);
        $session = $this->beginUpload($user, $space, null, 20);
        $this->putChunk($user, $session, 0, 8, 0)->assertOk();

        $this->actingAs($user)
            ->deleteJson(route('uploads.destroy', $session->uuid))
            ->assertOk()
            ->assertJsonPath('uuid', $session->uuid)
            ->assertJsonPath('status', UploadSession::STATUS_CANCELLED);

        $this->assertEmpty($this->edmsDisk()->allFiles("tmp/{$session->uuid}"));

        $this->putChunk($user, $session, 1, 8, 1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['upload']);
    }

    /** @return array<string, mixed> */
    private function initPayload(StorageSpace $space, array $overrides = []): array
    {
        return array_merge([
            'space_id' => $space->id,
            'folder_id' => null,
            'name' => 'archive.bin',
            'mime_type' => 'application/octet-stream',
            'size' => 20,
            'fingerprint' => 'archive.bin:20:1',
        ], $overrides);
    }

    private function putChunk(User $user, UploadSession $session, int $index, int $bytes, int $seed)
    {
        return $this->actingAs($user)->put(
            route('uploads.chunks.store', ['uuid' => $session->uuid, 'index' => $index]),
            ['chunk' => $this->makeChunk($bytes, $seed)],
            ['Accept' => 'application/json'],
        );
    }
}
