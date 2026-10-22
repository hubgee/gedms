<?php

namespace App\Console\Commands;

use App\Contracts\UploadStorage;
use App\Models\UploadSession;
use App\Services\UploadService;
use Illuminate\Console\Command;

/**
 * Housekeeping for resumable uploads.
 *
 * Three jobs, all designed for the "process died" case:
 *
 *  1. requeue (or fail) sessions stuck in "assembling" whose worker is gone;
 *  2. expire sessions that ran past their TTL and drop their chunk bytes;
 *  3. remove leftover temporary directories for finished sessions.
 *
 * Safe to run as often as you like: every step is idempotent, and nothing here
 * deletes a file that has actually been published.
 */
class PruneUploads extends Command
{
    protected $signature = 'edms:prune-uploads
        {--dry-run : Report what would happen without changing anything}
        {--ttl : Also expire sessions past their TTL and delete their chunk bytes}';

    protected $description = 'Recover stuck upload assemblies and prune expired upload sessions';

    public function handle(UploadService $uploads): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $recovered = $this->recoverStaleAssemblies($uploads, $dryRun);

        $expired = $this->option('ttl')
            ? $this->expireOverdueSessions($uploads, $dryRun)
            : 0;

        $pruned = $this->pruneTemporaryDirectories($dryRun);

        if ($dryRun) {
            $this->warn('Dry run: nothing was changed.');
        }

        $this->info("Recovery: {$recovered['requeued']} requeued, {$recovered['reverted']} reverted, {$recovered['failed']} failed.");
        $this->info("Expired: {$expired} session(s).");
        $this->info("Temporary directories removed: {$pruned}.");

        return self::SUCCESS;
    }

    /**
     * @return array{requeued: int, reverted: int, failed: int}
     */
    private function recoverStaleAssemblies(UploadService $uploads, bool $dryRun): array
    {
        $counts = ['requeued' => 0, 'reverted' => 0, 'failed' => 0];

        $stale = UploadSession::query()->staleAssembling()->orderBy('id')->get();

        foreach ($stale as $session) {
            if ($dryRun) {
                $this->line("Would recover session {$session->uuid} ({$session->name}).");

                continue;
            }

            $action = $uploads->recoverStale($session);

            if (isset($counts[$action])) {
                $counts[$action]++;
                $this->line(ucfirst($action).": {$session->uuid} ({$session->name})");
            }
        }

        return $counts;
    }

    private function expireOverdueSessions(UploadService $uploads, bool $dryRun): int
    {
        // Only sessions that still accept chunks are worth expiring; finished
        // ones are handled by the temporary-directory sweep below.
        $overdue = UploadSession::query()
            ->whereIn('status', [UploadSession::STATUS_PENDING, UploadSession::STATUS_UPLOADING])
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->get();

        if ($dryRun) {
            foreach ($overdue as $session) {
                $this->line("Would expire session {$session->uuid} ({$session->name}).");
            }

            return $overdue->count();
        }

        foreach ($overdue as $session) {
            $uploads->expire($session);
        }

        return $overdue->count();
    }

    /**
     * Drop the chunk directory of anything that is no longer in flight, and of
     * sessions whose bytes simply outlived their welcome. Published files live
     * elsewhere on the disk and are never touched here.
     */
    private function pruneTemporaryDirectories(bool $dryRun): int
    {
        $storage = app(UploadStorage::class);
        $retentionHours = max(1, (int) config('uploads.prune_retention_hours'));
        $cutoff = now()->subHours($retentionHours);

        $removed = 0;

        foreach ($storage->directories('tmp') as $name) {
            $uuid = basename($name);
            $directory = 'tmp/'.ltrim($name, '/');
            $session = UploadSession::query()->where('uuid', $uuid)->first();

            // A directory with no session row at all is orphaned debris.
            $finished = $session === null
                || $session->isCompleted()
                || in_array($session->status, [
                    UploadSession::STATUS_CANCELLED,
                    UploadSession::STATUS_EXPIRED,
                    UploadSession::STATUS_FAILED,
                ], true);

            if (! $finished) {
                continue;
            }

            // Keep recently-finished directories around briefly so a debugging
            // session can still inspect them.
            if ($session !== null && $session->updated_at !== null && $session->updated_at->gt($cutoff)) {
                continue;
            }

            if ($dryRun) {
                $this->line("Would remove temporary directory {$directory}.");
                $removed++;

                continue;
            }

            $storage->deleteDirectory($directory);
            $this->line("Removed temporary directory {$directory}.");
            $removed++;
        }

        return $removed;
    }
}
