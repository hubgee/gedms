<?php

namespace App\Models;

use App\Contracts\UploadStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileVersion extends Model
{
    protected $fillable = ['file_id', 'created_by', 'version', 'storage_path', 'mime_type', 'size'];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getStoragePath(): string
    {
        return $this->storage_path;
    }

    /**
     * Whether the bytes of this version are still in the store.
     *
     * The store is reached through the UploadStorage contract, so the answer
     * is the same whether the file lives on a local disk or in a bucket.
     */
    public function exists(): bool
    {
        return app(UploadStorage::class)->exists($this->storage_path);
    }
}
