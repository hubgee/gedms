<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Throwable;

class File extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'storage_space_id',
        'folder_id',
        'upload_session_id',
        'created_by',
        'name',
        'mime_type',
        'size',
        'version',
        'locked_by',
        'locked_at',
    ];

    protected $casts = [
        'locked_at' => 'datetime',
    ];

    /**
     * Storage paths captured immediately before a permanent delete.
     *
     * file_versions rows cascade with the file, so once the DELETE statement
     * has run the database no longer knows where the bytes live. The paths are
     * read in the forceDeleting hook and used after the row is gone.
     *
     * @var list<string>
     */
    protected array $storagePathsToPurge = [];

    protected static function booted(): void
    {
        static::creating(function (File $model) {
            if (empty($model->uuid)) {
                $model->uuid = \Illuminate\Support\Str::uuid()->toString();
            }
        });

        static::forceDeleting(function (File $file) {
            $file->storagePathsToPurge = $file->versions()->pluck('storage_path')->all();
        });

        static::forceDeleted(function (File $file) {
            $file->purgeStoredBytes();
        });
    }

    /**
     * Remove the bytes belonging to this file after a permanent delete.
     *
     * A soft delete (move to trash) deliberately keeps the bytes so the file
     * can be restored; only forceDelete() reaches this method. Failures are
     * reported rather than thrown: the database rows are already gone, and
     * leftover bytes are recoverable by the orphan sweep, while a failed delete
     * would leave the file stuck in the trash.
     */
    public function purgeStoredBytes(): void
    {
        $paths = array_values(array_unique(array_filter($this->storagePathsToPurge)));
        $this->storagePathsToPurge = [];

        if ($paths === []) {
            return;
        }

        $disk = Storage::disk('edms');

        foreach ($paths as $path) {
            try {
                $disk->delete($path);
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Drop the now-empty {space}/{uuid} folder, but never a space root:
        // legacy rows may store bytes directly under it.
        foreach (array_unique(array_map('dirname', $paths)) as $directory) {
            if ($directory === '' || $directory === '.' || $directory === (string) $this->storage_space_id) {
                continue;
            }

            try {
                if ($disk->allFiles($directory) === []) {
                    $disk->deleteDirectory($directory);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    public function storageSpace(): BelongsTo
    {
        return $this->belongsTo(StorageSpace::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lockedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FileVersion::class)->orderByDesc('version');
    }

    public function uploadSession(): BelongsTo
    {
        return $this->belongsTo(UploadSession::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(FileShare::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_by !== null;
    }

    public function document(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Document::class);
    }

    public function favoritedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'file_favorites')->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'file_tag')->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FileComment::class)->orderBy('created_at');
    }
}
