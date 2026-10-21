<?php

namespace App\Services;

use App\Models\File;
use App\Models\FileComment;
use App\Models\FileFavorite;
use App\Models\FileShare;
use App\Models\FolderShare;
use App\Models\ShareLink;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\StorageSpace;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileService
{
    public function __construct(
        protected SpaceService $spaceService,
        protected AuditService $auditService
    ) {}

    public function upload(UploadedFile $uploadedFile, StorageSpace $space, ?Folder $folder, User $user): File
    {
        $this->spaceService->userCanAccess($space, $user) || abort(403);
        $this->enforceMandatoryFolderRestriction($space, $folder, $user, 'upload files');

        $name = $this->sanitizeFileName($uploadedFile->getClientOriginalName());
        $mimeType = $uploadedFile->getMimeType() ?: 'application/octet-stream';
        $size = $uploadedFile->getSize();

        return DB::transaction(function () use ($uploadedFile, $space, $folder, $user, $name, $mimeType, $size) {
            $file = File::create([
                'storage_space_id' => $space->id,
                'folder_id' => $folder?->id,
                'created_by' => $user->id,
                'name' => $name,
                'mime_type' => $mimeType,
                'size' => $size,
                'version' => 1,
            ]);

            $storagePath = $this->buildStoragePath($space, $file, 1, $name);
            $uploadedFile->storeAs(dirname($storagePath), basename($storagePath), ['disk' => 'edms']);

            FileVersion::create([
                'file_id' => $file->id,
                'created_by' => $user->id,
                'version' => 1,
                'storage_path' => $storagePath,
                'mime_type' => $mimeType,
                'size' => $size,
            ]);

            $this->auditService->log('file.upload', File::class, $file->id, null, [
                'name' => $name,
                'space_id' => $space->id,
            ]);

            return $file->fresh();
        });
    }

    /**
     * Publish a file whose bytes are already on the edms disk.
     *
     * Used by the resumable upload pipeline after the assembled file has been
     * verified. The caller supplies the UUID so a retried assembly reuses the
     * same path and the same file identity instead of creating a second copy.
     */
    public function publishAssembled(
        StorageSpace $space,
        ?Folder $folder,
        User $user,
        string $fileUuid,
        string $storagePath,
        string $name,
        ?string $mimeType,
        int $size,
        ?int $uploadSessionId = null,
    ): File {
        return DB::transaction(function () use ($space, $folder, $user, $fileUuid, $storagePath, $name, $mimeType, $size, $uploadSessionId) {
            // uuid is not mass-assignable, so it is set explicitly to keep it in
            // sync with the storage path chosen by the caller.
            $file = new File([
                'storage_space_id' => $space->id,
                'folder_id' => $folder?->id,
                'upload_session_id' => $uploadSessionId,
                'created_by' => $user->id,
                'name' => $name,
                'mime_type' => $mimeType,
                'size' => $size,
                'version' => 1,
            ]);
            $file->uuid = $fileUuid;
            $file->save();

            FileVersion::create([
                'file_id' => $file->id,
                'created_by' => $user->id,
                'version' => 1,
                'storage_path' => $storagePath,
                'mime_type' => $mimeType,
                'size' => $size,
            ]);

            $this->auditService->log('file.upload', File::class, $file->id, null, [
                'name' => $name,
                'space_id' => $space->id,
                'upload_session_id' => $uploadSessionId,
            ]);

            return $file->fresh();
        });
    }

    public function authorizeView(File $file, User $user): void
    {
        $this->authorizeFileAccess($file, $user, true);
    }

    public function userCanAccessFile(File $file, User $user, bool $viewOnly = true): bool
    {
        if ($this->spaceService->userCanAccess($file->storageSpace, $user)) {
            return true;
        }
        $share = FileShare::where('file_id', $file->id)->where('shared_with_user_id', $user->id)->first();
        if ($share && ($viewOnly || $share->canEdit())) {
            return true;
        }
        return $this->userHasFolderShareAccess($file->folder_id, $user->id, $viewOnly);
    }

    public function download(File $file, User $user): StreamedResponse
    {
        $this->authorizeFileAccess($file, $user, true);

        $version = $file->versions()->where('version', $file->version)->first();
        if (! $version || ! $version->exists()) {
            abort(404, 'File content not found');
        }

        $this->auditService->log('file.download', File::class, $file->id, null, ['name' => $file->name]);

        return Storage::disk('edms')->download(
            $version->storage_path,
            $file->name,
            ['Content-Type' => $file->mime_type]
        );
    }

    public function preview(File $file, User $user, ?\Illuminate\Http\Request $request = null): StreamedResponse
    {
        $this->authorizeFileAccess($file, $user, true);

        $version = $file->versions()->where('version', $file->version)->first();
        if (! $version || ! Storage::disk('edms')->exists($version->storage_path)) {
            abort(404, 'File content not found');
        }

        $this->auditService->log('file.preview', File::class, $file->id, null, ['name' => $file->name]);

        $path = Storage::disk('edms')->path($version->storage_path);
        $size = (int) filesize($path);

        $start = 0;
        $length = $size;
        $status = 200;
        $headers = [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => 'inline; filename="' . basename($file->name) . '"',
            'Accept-Ranges' => 'bytes',
            'Content-Length' => $size,
        ];

        $rangeHeader = $request?->server('HTTP_RANGE');
        if ($rangeHeader && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
            $start = $m[1] !== '' ? (int) $m[1] : 0;
            $end = $m[2] !== '' ? (int) $m[2] : $size - 1;
            $end = min($end, $size - 1);
            $start = min($start, $end);
            $length = $end - $start + 1;
            $status = 206;
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
            $headers['Content-Length'] = $length;
        }

        $finalStart = $start;
        $finalLength = $length;

        return response()->stream(function () use ($path, $finalStart, $finalLength) {
            $stream = fopen($path, 'rb');
            if ($finalStart > 0) {
                fseek($stream, $finalStart, SEEK_SET);
            }
            $bytesLeft = $finalLength;
            while ($bytesLeft > 0 && ! feof($stream)) {
                $chunk = fread($stream, min(8192, $bytesLeft));
                if ($chunk === false) {
                    break;
                }
                $bytesLeft -= strlen($chunk);
                echo $chunk;
                flush();
            }
            fclose($stream);
        }, $status, $headers);
    }

    public function updateVersion(File $file, UploadedFile $uploadedFile, User $user): File
    {
        $this->authorizeFileAccess($file, $user, false);
        $file->locked_by && $file->locked_by !== $user->id && abort(403, 'File is locked');

        $newVersion = $file->version + 1;
        $mimeType = $uploadedFile->getMimeType() ?: $file->mime_type;
        $size = $uploadedFile->getSize();
        $name = $file->name;

        return DB::transaction(function () use ($file, $uploadedFile, $user, $newVersion, $mimeType, $size, $name) {
            $storagePath = $this->buildStoragePath($file->storageSpace, $file, $newVersion, $name);
            $uploadedFile->storeAs(dirname($storagePath), basename($storagePath), ['disk' => 'edms']);

            FileVersion::create([
                'file_id' => $file->id,
                'created_by' => $user->id,
                'version' => $newVersion,
                'storage_path' => $storagePath,
                'mime_type' => $mimeType,
                'size' => $size,
            ]);

            $file->update(['version' => $newVersion, 'mime_type' => $mimeType, 'size' => $size]);

            $this->auditService->log('file.version', File::class, $file->id, [
                'version' => $newVersion - 1,
            ], ['version' => $newVersion]);

            return $file->fresh();
        });
    }

    public function rename(File $file, string $newName, User $user): File
    {
        $this->authorizeFileAccess($file, $user, false);
        $file->update(['name' => $this->sanitizeFileName($newName)]);
        $this->auditService->log('file.rename', File::class, $file->id, ['name' => $file->getOriginal('name')], ['name' => $newName]);
        return $file->fresh();
    }

    public function move(File $file, ?Folder $targetFolder, User $user): File
    {
        $this->authorizeFileAccess($file, $user, false);
        $targetFolder && $this->spaceService->userCanAccess($targetFolder->storageSpace, $user) || abort(403);
        $targetFolder && $targetFolder->storage_space_id !== $file->storage_space_id && abort(400, 'Cannot move across spaces');
        $this->enforceMandatoryFolderRestriction($file->storageSpace, $targetFolder, $user, 'move files');

        $file->update(['folder_id' => $targetFolder?->id]);
        $this->auditService->log('file.move', File::class, $file->id, null, ['folder_id' => $targetFolder?->id]);
        return $file->fresh();
    }

    public function copy(File $file, ?Folder $targetFolder, StorageSpace $targetSpace, User $user): File
    {
        $this->authorizeFileAccess($file, $user, true);
        $this->spaceService->userCanAccess($targetSpace, $user) || abort(403);
        $targetFolder && $targetFolder->storage_space_id !== $targetSpace->id && abort(400, 'Folder must belong to target space');
        $this->enforceMandatoryFolderRestriction($targetSpace, $targetFolder, $user, 'copy files');

        $version = $file->versions()->where('version', $file->version)->first();
        if (! $version) {
            abort(404, 'File content not found');
        }

        return DB::transaction(function () use ($file, $targetFolder, $targetSpace, $version, $user) {
            $copy = File::create([
                'storage_space_id' => $targetSpace->id,
                'folder_id' => $targetFolder?->id,
                'created_by' => $user->id,
                'name' => $file->name,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
                'version' => 1,
            ]);

            $storagePath = $this->buildStoragePath($targetSpace, $copy, 1, $file->name);
            Storage::disk('edms')->copy($version->storage_path, $storagePath);

            FileVersion::create([
                'file_id' => $copy->id,
                'created_by' => $user->id,
                'version' => 1,
                'storage_path' => $storagePath,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
            ]);

            $this->auditService->log('file.copy', File::class, $copy->id, null, ['source_file_id' => $file->id]);
            return $copy->fresh();
        });
    }

    public function delete(File $file, User $user): void
    {
        $this->authorizeFileAccess($file, $user, false);
        if ($file->trashed()) {
            $file->forceDelete();
            $this->auditService->log('file.force_delete', File::class, $file->id, ['name' => $file->name], null);
        } else {
            $file->delete();
            $this->auditService->log('file.delete', File::class, $file->id, ['name' => $file->name], null);
        }
    }

    public function restore(File $file, User $user): File
    {
        $this->authorizeFileAccess($file, $user, false);
        // Restore parent folder chain so the file becomes visible in Drive navigation
        $folder = $file->folder_id ? \App\Models\Folder::withTrashed()->find($file->folder_id) : null;
        while ($folder && $folder->trashed()) {
            $folder->restore();
            $this->auditService->log('folder.restore', \App\Models\Folder::class, $folder->id, null, ['name' => $folder->name]);
            $folder = $folder->parent_id ? \App\Models\Folder::withTrashed()->find($folder->parent_id) : null;
        }
        $file->restore();
        $this->auditService->log('file.restore', File::class, $file->id, null, ['name' => $file->name]);
        return $file->fresh();
    }

    public function share(File $file, User $shareWith, string $permission, User $user): FileShare
    {
        $this->authorizeFileAccess($file, $user, false);
        $permission = in_array($permission, [FileShare::PERMISSION_VIEW, FileShare::PERMISSION_EDIT]) ? $permission : FileShare::PERMISSION_VIEW;

        $share = FileShare::updateOrCreate(
            ['file_id' => $file->id, 'shared_with_user_id' => $shareWith->id],
            ['shared_by_user_id' => $user->id, 'permission' => $permission]
        );
        $this->auditService->log('file.share', File::class, $file->id, null, ['shared_with' => $shareWith->email, 'permission' => $permission]);
        return $share;
    }

    public function unshare(File $file, User $shareWith, User $user): void
    {
        $this->authorizeFileAccess($file, $user, false);
        FileShare::where('file_id', $file->id)->where('shared_with_user_id', $shareWith->id)->delete();
        $this->auditService->log('file.unshare', File::class, $file->id, null, ['shared_with' => $shareWith->email]);
    }

    public function createOrGetShareLink(File $file, string $permission, User $user): ShareLink
    {
        $this->authorizeFileAccess($file, $user, false);
        $permission = in_array($permission, [ShareLink::PERMISSION_VIEW, ShareLink::PERMISSION_EDIT]) ? $permission : ShareLink::PERMISSION_VIEW;

        $link = ShareLink::where('shareable_type', File::class)->where('shareable_id', $file->id)->first();
        if ($link) {
            return $link;
        }

        $link = ShareLink::create([
            'shareable_type' => File::class,
            'shareable_id' => $file->id,
            'token' => ShareLink::generateToken(),
            'permission' => $permission,
            'created_by_user_id' => $user->id,
        ]);
        $this->auditService->log('file.share_link', File::class, $file->id, null, []);
        return $link;
    }

    public function createOrGetFolderShareLink(Folder $folder, string $permission, User $user): ShareLink
    {
        $this->authorizeFolderAccess($folder, $user, false);
        $permission = in_array($permission, [ShareLink::PERMISSION_VIEW, ShareLink::PERMISSION_EDIT]) ? $permission : ShareLink::PERMISSION_VIEW;

        $link = ShareLink::where('shareable_type', Folder::class)->where('shareable_id', $folder->id)->first();
        if ($link) {
            return $link;
        }

        $link = ShareLink::create([
            'shareable_type' => Folder::class,
            'shareable_id' => $folder->id,
            'token' => ShareLink::generateToken(),
            'permission' => $permission,
            'created_by_user_id' => $user->id,
        ]);
        $this->auditService->log('folder.share_link', Folder::class, $folder->id, null, []);
        return $link;
    }

    public function createFolder(StorageSpace $space, ?Folder $parent, string $name, User $user): Folder
    {
        $this->spaceService->userCanAccess($space, $user) || abort(403);
        $parent && $parent->storage_space_id !== $space->id && abort(400, 'Parent must be in same space');

        $this->enforceMandatoryFolderRestriction($space, $parent, $user, 'create folders');

        $folder = Folder::create([
            'storage_space_id' => $space->id,
            'parent_id' => $parent?->id,
            'name' => $this->sanitizeFileName($name),
            'created_by' => $user->id,
        ]);
        $this->auditService->log('folder.create', Folder::class, $folder->id, null, ['name' => $folder->name]);
        return $folder;
    }

    public function renameFolder(Folder $folder, string $newName, User $user): Folder
    {
        $this->spaceService->userCanAccess($folder->storageSpace, $user) || abort(403);
        $folder->update(['name' => $this->sanitizeFileName($newName)]);
        $this->auditService->log('folder.rename', Folder::class, $folder->id, ['name' => $folder->getOriginal('name')], ['name' => $newName]);
        return $folder->fresh();
    }

    public function moveFolder(Folder $folder, ?Folder $targetParent, User $user): Folder
    {
        $this->spaceService->userCanAccess($folder->storageSpace, $user) || abort(403);
        $targetParent && $targetParent->storage_space_id !== $folder->storage_space_id && abort(400, 'Cannot move across spaces');
        $targetParent && $this->wouldCreateCycle($folder, $targetParent) && abort(400, 'Cannot move folder into itself or descendants');
        $this->enforceMandatoryFolderRestriction($folder->storageSpace, $targetParent, $user, 'move folders');

        $folder->update(['parent_id' => $targetParent?->id]);
        $this->auditService->log('folder.move', Folder::class, $folder->id, null, ['parent_id' => $targetParent?->id]);
        return $folder->fresh();
    }

    public function deleteFolder(Folder $folder, User $user): void
    {
        $this->authorizeFolderAccess($folder, $user, false);
        if ($folder->locked_by !== null && $folder->created_by !== $user->id) {
            abort(403, 'Only the folder creator can delete a locked folder.');
        }
        $folder->delete();
        $this->auditService->log('folder.delete', Folder::class, $folder->id, ['name' => $folder->name], null);
    }

    public function lockFile(File $file, User $user): File
    {
        $this->authorizeFileAccess($file, $user, false);
        $file->update(['locked_by' => $user->id, 'locked_at' => now()]);
        $this->auditService->log('file.lock', File::class, $file->id, null, []);
        return $file->fresh();
    }

    public function unlockFile(File $file, User $user): File
    {
        $this->authorizeFileAccess($file, $user, false);
        if ($file->locked_by !== null && $file->locked_by !== $user->id) {
            abort(403, 'Only the user who locked the file can unlock it.');
        }
        $file->update(['locked_by' => null, 'locked_at' => null]);
        $this->auditService->log('file.unlock', File::class, $file->id, null, []);
        return $file->fresh();
    }

    public function lockFolder(Folder $folder, User $user): Folder
    {
        $this->authorizeFolderAccess($folder, $user, false);
        $folder->update(['locked_by' => $user->id, 'locked_at' => now()]);
        $this->auditService->log('folder.lock', Folder::class, $folder->id, null, []);
        return $folder->fresh();
    }

    public function unlockFolder(Folder $folder, User $user): Folder
    {
        $this->authorizeFolderAccess($folder, $user, false);
        if ($folder->locked_by !== null && $folder->locked_by !== $user->id) {
            abort(403, 'Only the user who locked the folder can unlock it.');
        }
        $folder->update(['locked_by' => null, 'locked_at' => null]);
        $this->auditService->log('folder.unlock', Folder::class, $folder->id, null, []);
        return $folder->fresh();
    }

    public function shareFolder(Folder $folder, User $shareWith, string $permission, User $user): FolderShare
    {
        $this->authorizeFolderAccess($folder, $user, false);
        $permission = in_array($permission, [FolderShare::PERMISSION_VIEW, FolderShare::PERMISSION_EDIT]) ? $permission : FolderShare::PERMISSION_VIEW;

        $share = \App\Models\FolderShare::updateOrCreate(
            ['folder_id' => $folder->id, 'shared_with_user_id' => $shareWith->id],
            ['shared_by_user_id' => $user->id, 'permission' => $permission]
        );
        $this->auditService->log('folder.share', Folder::class, $folder->id, null, ['shared_with' => $shareWith->email, 'permission' => $permission]);
        return $share;
    }

    public function toggleFavorite(File $file, User $user): bool
    {
        $this->authorizeFileAccess($file, $user, true);
        $exists = FileFavorite::where('user_id', $user->id)->where('file_id', $file->id)->exists();
        if ($exists) {
            FileFavorite::where('user_id', $user->id)->where('file_id', $file->id)->delete();
            return false;
        }
        FileFavorite::create(['user_id' => $user->id, 'file_id' => $file->id]);
        return true;
    }

    public function findOrCreateTag(StorageSpace $space, string $name, ?string $color, User $user): Tag
    {
        $this->spaceService->userCanAccess($space, $user) || abort(403);
        return Tag::firstOrCreate(
            ['storage_space_id' => $space->id, 'name' => trim($name)],
            ['color' => $color ?? '#64748b']
        );
    }

    public function addTag(File $file, Tag $tag, User $user): void
    {
        $this->authorizeFileAccess($file, $user, false);
        if ($tag->storage_space_id !== $file->storage_space_id) {
            abort(400, 'Tag must belong to same storage space as file');
        }
        $file->tags()->syncWithoutDetaching([$tag->id]);
    }

    public function removeTag(File $file, Tag $tag, User $user): void
    {
        $this->authorizeFileAccess($file, $user, false);
        $file->tags()->detach($tag->id);
    }

    public function syncTags(File $file, array $tagIds, User $user): void
    {
        $this->authorizeFileAccess($file, $user, false);
        $tags = Tag::whereIn('id', $tagIds)->where('storage_space_id', $file->storage_space_id)->pluck('id');
        $file->tags()->sync($tags);
    }

    public function addComment(File $file, string $body, User $user): FileComment
    {
        $this->authorizeFileAccess($file, $user, true);
        $comment = FileComment::create([
            'file_id' => $file->id,
            'user_id' => $user->id,
            'body' => $body,
        ]);
        $this->auditService->log('file.comment', File::class, $file->id, null, ['comment_id' => $comment->id]);
        return $comment;
    }

    public function bulkDelete(array $items, User $user): int
    {
        $count = 0;
        foreach ($items as $idx => $item) {
            $id = $item['id'] ?? null;
            $type = $item['type'] ?? 'file';

            if (is_null($id)) {
                continue;
            }

            $model = null;

            if ($type === 'folder') {
                $model = Folder::withTrashed()->find($id);
            } else {
                $model = File::withTrashed()->find($id);
            }

            if ($model) {
                try {
                    if ($model->trashed()) {
                        if ($model instanceof File) {
                            $model->forceDelete();
                            $this->auditService->log('file.force_delete', File::class, $model->id, ['name' => $model->name], null);
                        } else {
                            // For folders, force delete if trashed
                            $model->forceDelete();
                            $this->auditService->log('folder.force_delete', Folder::class, $model->id, ['name' => $model->name], null);
                        }
                    } else {
                        if ($model instanceof File) {
                            $model->delete();
                            $this->auditService->log('file.delete', File::class, $model->id, ['name' => $model->name], null);
                        } else {
                            $model->delete();
                            $this->auditService->log('folder.delete', Folder::class, $model->id, ['name' => $model->name], null);
                        }
                    }
                    $count++;
                } catch (\AuthorizationException $e) {
                    // skip unauthorized
                } catch (\Throwable $e) {
                    // log unexpected error but continue
                    \Log::error('Bulk delete error', ['id' => $id, 'model' => get_class($model), 'error' => $e->getMessage()]);
                }
            }
        }
        return $count;
    }

    public function bulkMove(array $fileIds, ?int $folderId, User $user): int
    {
        $folder = $folderId ? Folder::find($folderId) : null;
        $count = 0;
        foreach ($fileIds as $id) {
            $file = File::withoutTrashed()->find($id);
            if ($file) {
                try {
                    $this->move($file, $folder, $user);
                    $count++;
                } catch (\Throwable) {
                    // skip
                }
            }
        }
        return $count;
    }

    protected function authorizeFileAccess(File $file, User $user, bool $viewOnly): bool
    {
        if ($this->spaceService->userCanAccess($file->storageSpace, $user)) {
            return true;
        }
        $share = FileShare::where('file_id', $file->id)->where('shared_with_user_id', $user->id)->first();
        if ($share && ($viewOnly || $share->canEdit())) {
            return true;
        }
        if ($this->userHasFolderShareAccess($file->folder_id, $user->id, $viewOnly)) {
            return true;
        }
        abort(403);
    }

    protected function userHasFolderShareAccess(?int $folderId, int $userId, bool $viewOnly): bool
    {
        $folder = $folderId ? Folder::find($folderId) : null;
        while ($folder) {
            $share = FolderShare::where('folder_id', $folder->id)->where('shared_with_user_id', $userId)->first();
            if ($share && ($viewOnly || $share->canEdit())) {
                return true;
            }
            $folder = $folder->parent;
        }
        return false;
    }

    public function userCanAccessFolder(Folder $folder, User $user, bool $viewOnly = true): bool
    {
        if ($this->spaceService->userCanAccess($folder->storageSpace, $user)) {
            return true;
        }
        $f = $folder;
        while ($f) {
            $share = FolderShare::where('folder_id', $f->id)->where('shared_with_user_id', $user->id)->first();
            if ($share && ($viewOnly || $share->canEdit())) {
                return true;
            }
            $f = $f->parent;
        }
        return false;
    }

    protected function authorizeFolderAccess(Folder $folder, User $user, bool $viewOnly): bool
    {
        if ($this->userCanAccessFolder($folder, $user, $viewOnly)) {
            return true;
        }
        abort(403);
    }

    protected function wouldCreateCycle(Folder $folder, Folder $target): bool
    {
        $current = $target;
        while ($current) {
            if ($current->id === $folder->id) {
                return true;
            }
            $current = $current->parent;
        }
        return false;
    }

    /**
     * Storage path for a file that does not have a model instance yet (the
     * resumable upload writes the bytes before creating the rows).
     */
    public function buildStoragePathFor(StorageSpace $space, string $uuid, int $version, string $name): string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION) ?: 'bin';

        return sprintf('%s/%s/v%d.%s', $space->id, $uuid, $version, $ext);
    }

    protected function buildStoragePath(StorageSpace $space, File $file, int $version, string $name): string
    {
        return $this->buildStoragePathFor($space, $file->uuid, $version, $name);
    }

    public function sanitizeFileName(string $name): string
    {
        return Str::limit(preg_replace('/[^\p{L}\p{N}\s._-]/u', '', $name) ?: 'file', 255);
    }

    /**
     * Ensure mandatory root folders exist in section, department and ministry spaces.
     * Called when viewing the file manager for such spaces.
     */
    public function ensureMandatoryFolders(StorageSpace $space, User $user): void
    {
        $names = $this->getMandatoryFolderNamesForSpace($space, $user);
        if (empty($names)) {
            return;
        }

        $deptId = $space->ownerSection?->department_id ?? $space->owner_department_id ?? $user->department_id;
        $lockUserId = User::where('department_id', $deptId)
            ->whereHas('roles.permissions', fn ($q) => $q->where('name', 'manage-department'))
            ->value('id');

        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $existing = Folder::where('storage_space_id', $space->id)
                ->whereNull('parent_id')
                ->where('name', $name)
                ->first();
            if ($existing) {
                if (! $existing->is_mandatory) {
                    $existing->update(['is_mandatory' => true]);
                }
                continue;
            }
            Folder::create([
                'storage_space_id' => $space->id,
                'parent_id' => null,
                'name' => $name,
                'created_by' => $lockUserId ?? $user->id,
                'locked_by' => $lockUserId,
                'locked_at' => $lockUserId ? now() : null,
                'is_mandatory' => true,
            ]);
        }
    }

    protected function getMandatoryFolderNamesForSpace(StorageSpace $space, User $user): array
    {
        if ($space->type !== StorageSpace::TYPE_SECTION) {
            return [];
        }
        $section = $space->ownerSection;
        return $section ? $section->getMandatoryFolderNames() : [];
    }

    /**
     * Public entry point for the create-file rules, so the resumable upload flow
     * can enforce exactly the same placement policy before a single byte lands
     * (see {@see UploadService::init()}).
     */
    public function assertCanUploadTo(StorageSpace $space, ?Folder $folder, User $user): void
    {
        $this->enforceMandatoryFolderRestriction($space, $folder, $user, 'upload files');
    }

    protected function enforceMandatoryFolderRestriction(StorageSpace $space, ?Folder $parent, User $user, string $action): void
    {
        if ($space->type === StorageSpace::TYPE_PERSONAL) {
            return;
        }
        if (in_array($space->type, [StorageSpace::TYPE_DEPARTMENT, StorageSpace::TYPE_MINISTRY])) {
            abort(403, 'Department and Ministry spaces are read-only. Create files and folders in section spaces.');
        }
        $names = $this->getMandatoryFolderNamesForSpace($space, $user);
        if (empty($names)) {
            return;
        }
        if ($parent === null) {
            abort(403, "In this space you can only {$action} inside the mandatory folders. Please select a folder.");
        }
        if (! $this->folderIsInsideMandatoryTree($parent, $space, $names)) {
            abort(403, "In this space you can only {$action} inside the mandatory folders.");
        }
    }

    protected function folderIsInsideMandatoryTree(Folder $folder, StorageSpace $space, array $mandatoryNames): bool
    {
        $current = $folder;
        while ($current) {
            if ($current->storage_space_id !== $space->id) {
                return false;
            }
            if ($current->parent_id === null && in_array($current->name, $mandatoryNames)) {
                return true;
            }
            $current = $current->parent;
        }
        return false;
    }

    public function canCreateInLocation(StorageSpace $space, ?Folder $folder, User $user): bool
    {
        if ($space->type === StorageSpace::TYPE_PERSONAL) {
            return true;
        }
        if (in_array($space->type, [StorageSpace::TYPE_DEPARTMENT, StorageSpace::TYPE_MINISTRY])) {
            return false;
        }
        if ($space->type !== StorageSpace::TYPE_SECTION) {
            return false;
        }
        $names = $this->getMandatoryFolderNamesForSpace($space, $user);
        if (empty($names)) {
            return true;
        }
        if ($folder === null) {
            return false;
        }
        return $this->folderIsInsideMandatoryTree($folder, $space, $names);
    }
}
