<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\StorageSpace;
use App\Models\User;
use App\Services\SpaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Exercises the browser bulk-delete flow: POST files.bulk-destroy with
 * items[][type] + items[][id], as the Alpine submitBulkDelete() helper sends.
 */
class BulkDeleteTest extends TestCase
{
    use RefreshDatabase;

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

    private function makeFile(StorageSpace $space, User $user, string $name = 'report.pdf'): File
    {
        return File::create([
            'storage_space_id' => $space->id,
            'created_by' => $user->id,
            'name' => $name,
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'version' => 1,
        ]);
    }

    private function makeFolder(StorageSpace $space, User $user, string $name = 'Reports'): Folder
    {
        return Folder::create([
            'storage_space_id' => $space->id,
            'created_by' => $user->id,
            'name' => $name,
        ]);
    }

    public function test_bulk_soft_deletes_active_files(): void
    {
        $user = $this->makeUser();
        $space = $this->makeSpace($user);

        $first = $this->makeFile($space, $user, 'one.pdf');
        $second = $this->makeFile($space, $user, 'two.pdf');

        $response = $this->actingAs($user)->post(route('files.bulk-destroy'), [
            'items' => [
                ['type' => 'file', 'id' => $first->id],
                ['type' => 'file', 'id' => $second->id],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', '2 item(s) deleted.');

        $this->assertSoftDeleted('files', ['id' => $first->id]);
        $this->assertSoftDeleted('files', ['id' => $second->id]);
    }

    public function test_bulk_permanently_deletes_trashed_files(): void
    {
        $user = $this->makeUser();
        $space = $this->makeSpace($user);

        $file = $this->makeFile($space, $user);
        $file->delete();

        $this->assertSoftDeleted('files', ['id' => $file->id]);

        $response = $this->actingAs($user)->post(route('files.bulk-destroy'), [
            'items' => [
                ['type' => 'file', 'id' => $file->id],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', '1 item(s) deleted.');

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_bulk_delete_handles_mixed_files_and_folders(): void
    {
        $user = $this->makeUser();
        $space = $this->makeSpace($user);

        $file = $this->makeFile($space, $user);
        $folder = $this->makeFolder($space, $user);

        $response = $this->actingAs($user)->post(route('files.bulk-destroy'), [
            'items' => [
                ['type' => 'file', 'id' => $file->id],
                ['type' => 'folder', 'id' => $folder->id],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', '2 item(s) deleted.');

        $this->assertSoftDeleted('files', ['id' => $file->id]);
        $this->assertSoftDeleted('folders', ['id' => $folder->id]);
    }

    public function test_bulk_delete_ignores_unknown_ids_without_failing(): void
    {
        $user = $this->makeUser();
        $this->makeSpace($user);

        $response = $this->actingAs($user)->post(route('files.bulk-destroy'), [
            'items' => [
                ['type' => 'file', 'id' => 999999],
                ['type' => 'folder', 'id' => 999998],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', '0 item(s) deleted.');
    }

    public function test_bulk_delete_requires_items_payload(): void
    {
        $user = $this->makeUser();
        $this->makeSpace($user);

        $response = $this->actingAs($user)->post(route('files.bulk-destroy'), [
            'ids' => [1, 2],
        ]);

        $response->assertSessionHasErrors('items');
    }

    public function test_file_manager_renders_the_reactivity_safe_bulk_delete_form(): void
    {
        $user = $this->makeUser();
        $space = $this->makeSpace($user);

        $response = $this->actingAs($user)->get('/files?space='.$space->id);

        $response->assertOk()
            // Alpine owns the submit, so the method is resolved from the
            // component scope instead of a non-existent global function.
            ->assertSee('id="bulk-delete-form"', false)
            ->assertSee('@submit.prevent="submitBulkDelete()"', false)
            ->assertSee('submitBulkDelete()', false)
            ->assertDontSee('onsubmit="return deleteSelected()"', false);
    }
}
