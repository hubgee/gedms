<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\FileVersion;
use App\Models\StorageSpace;
use App\Models\User;
use App\Services\FileService;
use App\Services\SpaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Deleting a file must keep or remove the stored bytes to match its lifecycle:
 * a soft delete (trash) keeps them so the file can be restored, while a
 * permanent delete must remove them. file_versions rows cascade with the file,
 * so the paths have to be captured before the row disappears.
 */
class FileDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('edms');
    }

    private function makeUser(): User
    {
        $user = User::factory()->create();

        Permission::findOrCreate('delete-files', 'web');
        Permission::findOrCreate('view-files', 'web');
        $user->givePermissionTo(['delete-files', 'view-files']);

        return $user;
    }

    private function makeSpace(User $user): StorageSpace
    {
        return app(SpaceService::class)->getOrCreatePersonalSpace($user);
    }

    private function makeFile(StorageSpace $space, User $user, string $name = 'report.pdf', int $versions = 1): File
    {
        $file = File::create([
            'storage_space_id' => $space->id,
            'created_by' => $user->id,
            'name' => $name,
            'mime_type' => 'application/pdf',
            'size' => 11 * $versions,
            'version' => $versions,
        ]);

        for ($version = 1; $version <= $versions; $version++) {
            $path = $this->pathFor($space, $file, $version);
            Storage::disk('edms')->put($path, 'hello world');

            FileVersion::create([
                'file_id' => $file->id,
                'created_by' => $user->id,
                'version' => $version,
                'storage_path' => $path,
                'mime_type' => 'application/pdf',
                'size' => 11,
            ]);
        }

        return $file->fresh();
    }

    private function pathFor(StorageSpace $space, File $file, int $version = 1): string
    {
        return "{$space->id}/{$file->uuid}/v{$version}.pdf";
    }

    private function permanentlyDelete(File $file, User $user): void
    {
        $service = app(FileService::class);

        $service->delete($file, $user);
        $service->delete(File::withTrashed()->findOrFail($file->id), $user);
    }

    public function test_soft_delete_keeps_the_bytes_so_the_file_can_be_restored(): void
    {
        $user = $this->makeUser();
        $space = $this->makeSpace($user);
        $file = $this->makeFile($space, $user);
        $path = $this->pathFor($space, $file);

        app(FileService::class)->delete($file, $user);

        $this->assertSoftDeleted('files', ['id' => $file->id]);
        Storage::disk('edms')->assertExists($path);
    }

    public function test_restoring_from_the_trash_still_has_its_bytes(): void
    {
        $user = $this->makeUser();
        $space = $this->makeSpace($user);
        $file = $this->makeFile($space, $user);
        $path = $this->pathFor($space, $file);

        $service = app(FileService::class);
        $service->delete($file, $user);
        $service->restore(File::withTrashed()->findOrFail($file->id), $user);

        $this->assertNotSoftDeleted('files', ['id' => $file->id]);
        Storage::disk('edms')->assertExists($path);
    }

    public function test_permanent_delete_purges_the_bytes_and_the_file_directory(): void
    {
        $user = $this->makeUser();
        $space = $this->makeSpace($user);
        $file = $this->makeFile($space, $user);
        $path = $this->pathFor($space, $file);

        $this->permanentlyDelete($file, $user);

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        $this->assertDatabaseMissing('file_versions', ['file_id' => $file->id]);
        Storage::disk('edms')->assertMissing($path);
        $this->assertFalse(Storage::disk('edms')->directoryExists("{$space->id}/{$file->uuid}"));
    }

    public function test_permanent_delete_purges_every_version(): void
    {
        $user = $this->makeUser();
        $space = $this->makeSpace($user);
        $file = $this->makeFile($space, $user, 'report.pdf', 3);

        $this->permanentlyDelete($file, $user);

        for ($version = 1; $version <= 3; $version++) {
            Storage::disk('edms')->assertMissing($this->pathFor($space, $file, $version));
        }
    }

    public function test_permanent_delete_leaves_other_files_untouched(): void
    {
        $user = $this->makeUser();
        $space = $this->makeSpace($user);
        $doomed = $this->makeFile($space, $user, 'doomed.pdf');
        $kept = $this->makeFile($space, $user, 'kept.pdf');
        $keptPath = $this->pathFor($space, $kept);

        $this->permanentlyDelete($doomed, $user);

        Storage::disk('edms')->assertMissing($this->pathFor($space, $doomed));
        Storage::disk('edms')->assertExists($keptPath);
    }

    public function test_permanent_delete_never_removes_sibling_bytes_in_the_space_root(): void
    {
        $user = $this->makeUser();
        $space = $this->makeSpace($user);
        $file = $this->makeFile($space, $user);

        // Legacy layout: bytes stored directly under the space root. Removing
        // the "empty" directory here would wipe the whole space.
        $legacyPath = "{$space->id}/legacy.pdf";
        $siblingPath = "{$space->id}/keep-me.txt";
        Storage::disk('edms')->put($legacyPath, 'legacy bytes');
        Storage::disk('edms')->put($siblingPath, 'important');
        Storage::disk('edms')->delete($this->pathFor($space, $file));
        FileVersion::where('file_id', $file->id)->update(['storage_path' => $legacyPath]);

        $this->permanentlyDelete($file, $user);

        Storage::disk('edms')->assertMissing($legacyPath);
        Storage::disk('edms')->assertExists($siblingPath);
    }

    public function test_empty_trash_route_purges_the_bytes(): void
    {
        $user = $this->makeUser();
        $space = $this->makeSpace($user);
        $file = $this->makeFile($space, $user);
        $path = $this->pathFor($space, $file);

        app(FileService::class)->delete($file, $user);

        $this->actingAs($user)
            ->post(route('files.bulk-destroy'), [
                'items' => [
                    ['type' => 'file', 'id' => $file->id],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        Storage::disk('edms')->assertMissing($path);
    }
}
