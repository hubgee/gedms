<?php

namespace Tests\Feature\Uploads;

use App\Models\User;

/**
 * The upload UI is JS-driven, so the contract between Blade and
 * resources/js/uploads.js is what can silently break: endpoint templates,
 * limits, and the chunked-upload marker on every toolbar variant.
 */
class UploadFrontendTest extends UploadTestCase
{
    public function test_the_file_manager_exposes_the_upload_endpoint_contract(): void
    {
        $this->actingAs($this->makeUploader())->get('/files')
            ->assertOk()
            ->assertSee('id="gedms-upload-config"', false)
            ->assertSee(route('uploads.store'), false)
            ->assertSee('__UUID__', false)
            ->assertSee('__INDEX__', false)
            ->assertSee((string) config('uploads.chunk_bytes'), false)
            ->assertSee((string) config('uploads.max_bytes'), false);
    }

    public function test_every_drive_style_renders_the_chunked_upload_form(): void
    {
        foreach (['drive', 'dropbox', 'nextcloud', 'sharepoint'] as $style) {
            $user = $this->makeUploader();
            $this->giveDriveStyle($user, $style);
            $space = $this->makePersonalSpace($user);

            $response = $this->actingAs($user)->get('/files?space='.$space->id);

            $response->assertOk();
            $response->assertSee('data-chunked-upload', false);

            // The legacy endpoint stays as the no-JavaScript fallback.
            $response->assertSee(route('files.store'), false);
        }
    }

    public function test_the_uploader_is_shipped_in_the_built_bundle(): void
    {
        $manifest = json_decode(
            file_get_contents(public_path('build/manifest.json')),
            true,
        );

        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('resources/js/app.js', $manifest);

        $bundle = file_get_contents(public_path('build/'.$manifest['resources/js/app.js']['file']));

        $this->assertStringContainsString('gedms-uploads-panel', $bundle);
        $this->assertStringContainsString('/chunks/', $bundle);
    }

    /** Point the user's department at a drive style so the right UI renders. */
    private function giveDriveStyle(User $user, string $style): void
    {
        $user->department?->update(['drive_style' => $style]);
    }
}
