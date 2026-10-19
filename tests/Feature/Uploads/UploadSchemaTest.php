<?php

namespace Tests\Feature\Uploads;

use App\Models\StorageSpace;
use App\Models\UploadChunk;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 sanity checks: the resumable-upload tables exist, the models default
 * correctly, and the chunk index is unique per session.
 */
class UploadSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(array $attributes = []): UploadSession
    {
        $user = User::factory()->create();
        $space = StorageSpace::create([
            'type' => StorageSpace::TYPE_PERSONAL,
            'owner_user_id' => $user->id,
            'name' => 'Personal',
            'is_active' => true,
        ]);

        return UploadSession::create(array_merge([
            'user_id' => $user->id,
            'storage_space_id' => $space->id,
            'name' => 'archive.iso',
            'mime_type' => 'application/octet-stream',
            'fingerprint' => 'archive.iso:1000:123',
            'total_bytes' => 1000,
            'chunk_bytes' => 100,
            'total_chunks' => 10,
            'received_bytes' => 0,
            'status' => UploadSession::STATUS_UPLOADING,
            'expires_at' => now()->addHours(48),
        ], $attributes));
    }

    private function chunk(UploadSession $session, int $index, int $bytes = 100): UploadChunk
    {
        return UploadChunk::create([
            'upload_session_id' => $session->id,
            'chunk_index' => $index,
            'bytes' => $bytes,
            'sha256' => str_repeat('a', 64),
        ]);
    }

    public function test_new_session_defaults_to_pending_before_persisting(): void
    {
        $session = new UploadSession();

        $this->assertSame(UploadSession::STATUS_PENDING, $session->status);
        $this->assertNull($session->uuid);
    }

    public function test_creating_a_session_assigns_a_uuid(): void
    {
        $session = $this->makeSession();

        $this->assertNotNull($session->uuid);
        $this->assertSame(36, strlen($session->uuid));
    }

    public function test_missing_chunk_indexes_are_reported_in_order(): void
    {
        $session = $this->makeSession(['total_chunks' => 10]);

        foreach ([0, 1, 2] as $index) {
            $this->chunk($session, $index);
        }

        $this->assertSame([0, 1, 2], $session->receivedChunkIndexes());
        $this->assertSame([3, 4, 5, 6, 7, 8, 9], $session->missingChunkIndexes());
    }

    public function test_chunk_index_is_unique_per_session(): void
    {
        $session = $this->makeSession();
        $this->chunk($session, 0);

        $this->expectException(QueryException::class);

        $this->chunk($session, 0);
    }

    public function test_resumable_scope_excludes_expired_sessions(): void
    {
        $active = $this->makeSession();
        $expired = $this->makeSession(['expires_at' => now()->subHour()]);

        $resumableIds = UploadSession::query()->resumable()->pluck('id');

        $this->assertTrue($resumableIds->contains($active->id));
        $this->assertFalse($resumableIds->contains($expired->id));
        $this->assertTrue($expired->isExpired());
        $this->assertFalse($active->isExpired());
    }
}
