<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

class StorageSpace extends Model
{
    public const TYPE_PERSONAL = 'personal';

    public const TYPE_SECTION = 'section';

    public const TYPE_DEPARTMENT = 'department';

    public const TYPE_MINISTRY = 'ministry';

    protected $fillable = [
        'type',
        'owner_user_id',
        'owner_department_id',
        'owner_section_id',
        'owner_ministry_id',
        'name',
        'is_active',
        'storage_driver',
        'storage_config',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * The decoded external-storage configuration, or null when the space uses
     * the global driver.
     *
     * @return array<string, mixed>|null
     */
    public function storageConfig(): ?array
    {
        $raw = $this->attributes['storage_config'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decrypted = decrypt($raw);
        } catch (Throwable $e) {
            // Fall back to plain JSON for legacy/unencrypted rows.
            $decrypted = $raw;
        }

        $decoded = json_decode($decrypted, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Persist an external-storage configuration, encrypted at rest.
     *
     * @param  array<string, mixed>|null  $config
     */
    public function setStorageConfig(?array $config): void
    {
        if ($config === null) {
            $this->attributes['storage_config'] = null;

            return;
        }

        $this->attributes['storage_config'] = encrypt(json_encode($config));
    }

    public function usesExternalStorage(): bool
    {
        return is_string($this->storage_driver) && $this->storage_driver !== ''
            && $this->storage_driver !== 'local';
    }

    protected static function booted(): void
    {
        static::creating(function (StorageSpace $model) {
            if (empty($model->uuid)) {
                $model->uuid = \Illuminate\Support\Str::uuid()->toString();
            }
        });
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function ownerDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'owner_department_id');
    }

    public function ownerSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'owner_section_id');
    }

    public function ownerMinistry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class, 'owner_ministry_id');
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class)->whereNull('parent_id');
    }

    public function allFolders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function scopeForUser($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('type', self::TYPE_PERSONAL)
                ->where('owner_user_id', $user->id);
        })->orWhere(function ($q) use ($user) {
            $q->where('type', self::TYPE_SECTION)
                ->where('owner_section_id', $user->section_id);
        })->orWhere(function ($q) use ($user) {
            $q->where('type', self::TYPE_DEPARTMENT)
                ->where('owner_department_id', $user->department_id);
        })->orWhere(function ($q) use ($user) {
            $q->where('type', self::TYPE_MINISTRY)
                ->where('owner_ministry_id', $user->ministry_id);
        });
    }
}
