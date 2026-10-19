<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    protected static function booted(): void
    {
        static::creating(function (File $model) {
            if (empty($model->uuid)) {
                $model->uuid = \Illuminate\Support\Str::uuid()->toString();
            }
        });
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
