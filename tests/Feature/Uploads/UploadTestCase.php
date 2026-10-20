<?php

namespace Tests\Feature\Uploads;

use App\Models\Department;
use App\Models\Folder;
use App\Models\Ministry;
use App\Models\Section;
use App\Models\StorageSpace;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\SpaceService;
use App\Services\UploadService;
use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Shared fixtures for the resumable-upload tests.
 *
 * Chunk size is dropped to 8 bytes so chunk arithmetic, the final remainder and
 * multi-chunk scenarios can be exercised with a handful of bytes.
 */
abstract class UploadTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('edms');

        config([
            'uploads.chunk_bytes' => 8,
            'uploads.max_bytes' => 1000,
            'uploads.ttl_hours' => 48,
        ]);
    }

    protected function uploadService(): UploadService
    {
        return app(UploadService::class);
    }

    protected function edmsDisk(): FilesystemAdapter
    {
        return Storage::disk('edms');
    }

    protected function makeUser(): User
    {
        return User::factory()->create();
    }

    /** A user allowed through the `can:create-files` gate. */
    protected function makeUploader(): User
    {
        $user = $this->makeUser();

        Permission::findOrCreate('create-files', 'web');
        Permission::findOrCreate('view-files', 'web');
        $user->givePermissionTo(['create-files', 'view-files']);

        return $user;
    }

    protected function makePersonalSpace(User $user): StorageSpace
    {
        return app(SpaceService::class)->getOrCreatePersonalSpace($user);
    }

    protected function makeFolder(StorageSpace $space, string $name, ?Folder $parent = null): Folder
    {
        return Folder::create([
            'storage_space_id' => $space->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'created_by' => $space->owner_user_id,
        ]);
    }

    /** A section space with mandatory folders, plus a user assigned to it. */
    protected function makeSectionSpace(User $user, array $mandatoryFolders): StorageSpace
    {
        $ministry = Ministry::create(['name' => 'Ministry']);
        $department = Department::create(['ministry_id' => $ministry->id, 'name' => 'Department']);
        $section = Section::create([
            'department_id' => $department->id,
            'name' => 'Section',
            'mandatory_folders' => $mandatoryFolders,
        ]);

        $user->update([
            'section_id' => $section->id,
            'department_id' => $department->id,
            'ministry_id' => $ministry->id,
        ]);

        return app(SpaceService::class)->getOrCreateSectionSpace($section);
    }

    protected function makeChunk(int $bytes, int $seed = 0): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('chunk.part', $this->chunkContent($bytes, $seed));
    }

    protected function chunkContent(int $bytes, int $seed = 0): string
    {
        return str_repeat(chr(65 + ($seed % 26)), $bytes);
    }

    protected function sha256For(string $content): string
    {
        return hash('sha256', $content);
    }

    protected function beginUpload(
        User $user,
        StorageSpace $space,
        ?Folder $folder = null,
        int $totalBytes = 20,
        string $fingerprint = 'file.bin:20:1',
    ): UploadSession {
        return $this->uploadService()->init(
            $user,
            $space,
            $folder,
            'file.bin',
            'application/octet-stream',
            $totalBytes,
            $fingerprint,
        );
    }

    /** Send every chunk of a session, using this fixture's deterministic content. */
    protected function uploadAllChunks(User $user, UploadSession $session): UploadSession
    {
        for ($index = 0; $index < $session->total_chunks; $index++) {
            $bytes = $this->uploadService()->expectedChunkBytes($session, $index);
            $session = $this->uploadService()->putChunk($session, $user, $index, $this->makeChunk($bytes, $index));
        }

        return $session;
    }

    /** Byte-for-byte content that uploadAllChunks() stores. */
    protected function expectedFileContent(UploadSession $session): string
    {
        $content = '';

        for ($index = 0; $index < $session->total_chunks; $index++) {
            $content .= $this->chunkContent($this->uploadService()->expectedChunkBytes($session, $index), $index);
        }

        return $content;
    }

    /** Upload arbitrary bytes, split across the session's chunk sizes. */
    protected function uploadContent(User $user, UploadSession $session, string $content): UploadSession
    {
        $offset = 0;

        for ($index = 0; $index < $session->total_chunks; $index++) {
            $bytes = $this->uploadService()->expectedChunkBytes($session, $index);
            $piece = substr($content, $offset, $bytes);
            $offset += $bytes;

            $session = $this->uploadService()->putChunk(
                $session,
                $user,
                $index,
                UploadedFile::fake()->createWithContent("chunk-{$index}.part", $piece),
            );
        }

        return $session;
    }

    protected function assertValidationFails(Closure $callback, ?string $field = null): ValidationException
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            if ($field !== null) {
                $this->assertArrayHasKey($field, $exception->errors(), "Expected a validation error on [{$field}].");
            }

            return $exception;
        }

        $this->fail('Expected a ValidationException to be thrown.');
    }
}
