<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single received chunk of an {@see UploadSession}. The bytes live on disk;
 * this row records that the chunk arrived intact so status/assembly can trust it.
 */
class UploadChunk extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['upload_session_id', 'chunk_index', 'bytes', 'sha256'];

    protected $casts = [
        'chunk_index' => 'integer',
        'bytes' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(UploadSession::class, 'upload_session_id');
    }
}
