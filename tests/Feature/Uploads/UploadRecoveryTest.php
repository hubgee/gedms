<?php

namespace Tests\Feature\Uploads;

use App\Jobs\AssembleUpload;
use App\Models\File;
use App\Models\UploadSession;
use Illuminate\Support\Facades\Queue;

/**
 * Phase 6: recovery and housekeeping.
 *
 * The case that matters is a session stranded in "assembling" because its
 * worker died. It accepts no chunks, never expires on its own, and no request
 * would ever move it — so only the recovery path can rescue it.
 */
class UploadRecoveryTest extends UploadTestCase
{
    public function test_recovery_requeues_a_stale_assembling_session(): void
    {
        Queue::fake();

        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $session = $this->uploadAllChunks($user, $this->beginUpload($user, $space, null, 20));
        $this->strandAssembling($session);

        $result = $this->uploadService()->recoverStale($session->fresh());

        $this->assertSame('requeued', $result);
        Queue::assertPushed(AssembleUpload::class, fn ($job) => $job->uploadSessionId === $session->id);

        $session->refresh();
        $this->assertSame(UploadSession::STATUS_ASSEMBLING, $session->status);
        $this->assertSame(2, $session->assembling_attempts);
        $this->assertTrue($session->assembling_since->gt(now()->subMinute()));
    }

    public function test_recovery_leaves_a_fresh_assembly_alone(): void
    {
        Queue::fake();

        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $session = $this->uploadAllChunks($user, $this->beginUpload($user, $space, null, 20));
        $session->forceFill([
            'status' => UploadSession::STATUS_ASSEMBLING,
            'assembling_attempts' => 1,
            'assembling_since' => now(),
        ])->save();

        $result = $this->uploadService()->recoverStale($session->fresh());

        $this->assertSame('skipped', $result);
        Queue::assertNothingPushed();
    }

    public function test_recovery_fails_a_session_that_exhausted_its_attempts(): void
    {
        Queue::fake();

        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $session = $this->uploadAllChunks($user, $this->beginUpload($user, $space, null, 20));
        $this->strandAssembling($session, attempts: 3);

        $result = $this->uploadService()->recoverStale($session->fresh());

        $this->assertSame('failed', $result);
        Queue::assertNothingPushed();

        $session->refresh();
        $this->assertSame(UploadSession::STATUS_FAILED, $session->status);
        $this->assertStringContainsString('3 attempts', (string) $session->failure_reason);
    }

    public function test_recovery_reverts_a_session_whose_chunks_vanished(): void
    {
        Queue::fake();

        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $session = $this->uploadAllChunks($user, $this->beginUpload($user, $space, null, 20));
        $this->strandAssembling($session);

        // Bytes lost after the last chunk was indexed.
        $this->edmsDisk()->deleteDirectory("tmp/{$session->uuid}");

        $result = $this->uploadService()->recoverStale($session->fresh());

        $this->assertSame('reverted', $result);
        Queue::assertNothingPushed();

        $session->refresh();
        $this->assertSame(UploadSession::STATUS_UPLOADING, $session->status);
        $this->assertNull($session->assembling_since);
        $this->assertSame(0, $session->received_bytes);
    }

    public function test_the_command_requeues_and_reports_a_stuck_session(): void
    {
        Queue::fake();

        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $session = $this->uploadAllChunks($user, $this->beginUpload($user, $space, null, 20));
        $this->strandAssembling($session);

        $this->artisan('edms:prune-uploads')
            ->expectsOutputToContain('1 requeued')
            ->assertSuccessful();

        Queue::assertPushed(AssembleUpload::class);
    }

    public function test_the_command_expires_overdue_sessions_and_drops_their_bytes(): void
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $session = $this->uploadAllChunks($user, $this->beginUpload($user, $space, null, 20));
        $session->forceFill(['expires_at' => now()->subHour()])->save();

        $this->assertTrue($this->edmsDisk()->exists("tmp/{$session->uuid}"));

        $this->artisan('edms:prune-uploads --ttl')
            ->expectsOutputToContain('Expired: 1')
            ->assertSuccessful();

        $session->refresh();
        $this->assertSame(UploadSession::STATUS_EXPIRED, $session->status);
        $this->assertFalse($this->edmsDisk()->exists("tmp/{$session->uuid}"));
    }

    public function test_the_command_does_not_expire_overdue_sessions_without_the_flag(): void
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $session = $this->beginUpload($user, $space, null, 20);
        $session->forceFill(['expires_at' => now()->subHour()])->save();

        $this->artisan('edms:prune-uploads')->assertSuccessful();

        $this->assertSame(UploadSession::STATUS_PENDING, $session->fresh()->status);
    }

    public function test_the_command_removes_the_temporary_directory_of_a_finished_session(): void
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $session = $this->uploadAllChunks($user, $this->beginUpload($user, $space, null, 20));

        // Simulate a crash between publishing the file and cleaning up.
        $session->forceFill(['status' => UploadSession::STATUS_COMPLETED])->save();
        UploadSession::query()
            ->whereKey($session->id)
            ->update(['updated_at' => now()->subHours(7)]);

        $this->assertTrue($this->edmsDisk()->exists("tmp/{$session->uuid}"));

        $this->artisan('edms:prune-uploads')
            ->expectsOutputToContain('Temporary directories removed: 1')
            ->assertSuccessful();

        $this->assertFalse($this->edmsDisk()->exists("tmp/{$session->uuid}"));
    }

    public function test_the_command_keeps_the_directory_of_a_session_still_uploading(): void
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $session = $this->uploadAllChunks($user, $this->beginUpload($user, $space, null, 20));
        $session->forceFill(['updated_at' => now()->subHours(7)])->save();

        $this->artisan('edms:prune-uploads')->assertSuccessful();

        $this->assertTrue($this->edmsDisk()->exists("tmp/{$session->uuid}"));
    }

    public function test_the_command_prunes_an_orphaned_temporary_directory(): void
    {
        $this->edmsDisk()->put('tmp/orphan-session/0.chunk', 'left behind');

        $this->artisan('edms:prune-uploads')
            ->expectsOutputToContain('Temporary directories removed: 1')
            ->assertSuccessful();

        $this->assertFalse($this->edmsDisk()->exists('tmp/orphan-session'));
    }

    public function test_dry_run_changes_nothing(): void
    {
        Queue::fake();

        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $session = $this->uploadAllChunks($user, $this->beginUpload($user, $space, null, 20));
        $this->strandAssembling($session);

        $this->artisan('edms:prune-uploads --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(UploadSession::STATUS_ASSEMBLING, $session->fresh()->status);
    }

    public function test_recovery_never_touches_a_published_file(): void
    {
        $user = $this->makeUser();
        $space = $this->makePersonalSpace($user);

        $session = $this->uploadAllChunks($user, $this->beginUpload($user, $space, null, 20));
        $first = $session;
        AssembleUpload::dispatchSync($first->id);
        $first->refresh();

        $this->assertSame(UploadSession::STATUS_COMPLETED, $first->status);
        $path = "{$first->storage_space_id}/{$first->uuid}/v1.bin";

        $this->artisan('edms:prune-uploads --ttl')->assertSuccessful();

        $this->assertTrue($this->edmsDisk()->exists($path), 'A published file must survive every prune run.');
        $this->assertSame(1, File::query()->count());
    }

    /** Leave a session looking like its worker died mid-assembly. */
    private function strandAssembling(UploadSession $session, int $attempts = 1): void
    {
        $session->forceFill([
            'status' => UploadSession::STATUS_ASSEMBLING,
            'assembling_attempts' => $attempts,
            'assembling_since' => now()->subMinutes(
                (int) config('uploads.stale_assembling_minutes') + 5,
            ),
        ])->save();
    }
}
