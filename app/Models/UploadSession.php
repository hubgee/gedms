<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A resumable, chunked upload. Chunks themselves live on the "edms" disk under
 * tmp/{uuid}; this record is the index the client and the assembly job agree on.
 */
class UploadSession extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_ASSEMBLING = 'assembling';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'storage_space_id',
        'folder_id',
        'file_id',
        'name',
        'mime_type',
        'fingerprint',
        'total_bytes',
        'chunk_bytes',
        'total_chunks',
        'received_bytes',
        'sha256',
        'status',
        'failure_reason',
        'expires_at',
        'assembling_attempts',
        'assembling_since',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'received_bytes' => 0,
    ];

    protected $casts = [
        'total_bytes' => 'integer',
        'chunk_bytes' => 'integer',
        'total_chunks' => 'integer',
        'received_bytes' => 'integer',
        'expires_at' => 'datetime',
        'assembling_attempts' => 'integer',
        'assembling_since' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (UploadSession $session) {
            if (empty($session->uuid)) {
                $session->uuid = Str::uuid()->toString();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function storageSpace(): BelongsTo
    {
        return $this->belongsTo(StorageSpace::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(UploadChunk::class)->orderBy('chunk_index');
    }

    /** Sessions that may still receive chunks. */
    public function scopeResumable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_UPLOADING])
            ->where('expires_at', '>', now());
    }

    /**
     * Sessions whose assembly looks abandoned: still "assembling" but untouched
     * for longer than the configured threshold.
     */
    public function scopeStaleAssembling(Builder $query): Builder
    {
        $minutes = max(1, (int) config('uploads.stale_assembling_minutes'));

        return $query->where('status', self::STATUS_ASSEMBLING)
            ->whereRaw('COALESCE(assembling_since, updated_at) <= ?', [now()->subMinutes($minutes)]);
    }

    /** Sessions that may be pruned: finished, cancelled, expired or failed. */
    public function scopeFinished(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
            self::STATUS_FAILED,
        ]);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isResumable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_UPLOADING], true)
            && ! $this->isExpired();
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isAssembling(): bool
    {
        return $this->status === self::STATUS_ASSEMBLING;
    }

    /**
     * Whether this session has been assembling long enough that its worker must
     * be presumed dead. Recovery uses this to decide what to requeue.
     */
    public function isStaleAssembling(): bool
    {
        if (! $this->isAssembling()) {
            return false;
        }

        $since = $this->assembling_since ?? $this->updated_at;
        $minutes = max(1, (int) config('uploads.stale_assembling_minutes'));

        return $since !== null && $since->lte(now()->subMinutes($minutes));
    }

    /** @return array<int, int> */
    public function receivedChunkIndexes(): array
    {
        return array_map('intval', $this->chunks()->pluck('chunk_index')->all());
    }

    /** @return array<int, int> */
    public function missingChunkIndexes(): array
    {
        if ($this->total_chunks < 1) {
            return [];
        }

        return array_values(array_diff(range(0, $this->total_chunks - 1), $this->receivedChunkIndexes()));
    }
}
