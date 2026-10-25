<?php

namespace Tests\Feature\Admin;

use App\Models\StorageSpace;
use App\Models\User;
use App\Services\Uploads\DropboxOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The admin UI is driver-agnostic: it renders from the registry and stores a
 * per-space configuration. These tests exercise that seam end to end without
 * contacting a real provider.
 */
class ExternalStorageControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Permission::findOrCreate('manage-storage-spaces', 'web');

        $user = User::factory()->create();
        $user->givePermissionTo('manage-storage-spaces');

        return $user;
    }

    private function space(?User $owner = null): StorageSpace
    {
        return StorageSpace::create([
            'type' => StorageSpace::TYPE_PERSONAL,
            'owner_user_id' => $owner?->id,
            'name' => 'Test Space',
        ]);
    }

    public function test_the_edit_page_lists_every_registered_driver(): void
    {
        $space = $this->space();

        $response = $this->actingAs($this->admin())
            ->get(route('admin.storage-spaces.external-storage.edit', $space));

        $response->assertOk();
        $response->assertSee('Local disk');
        $response->assertSee('S3-compatible object storage');
        $response->assertSee('Dropbox');
        $response->assertSee('Google Drive');
        $response->assertSee('Microsoft OneDrive');
        $response->assertSee('Inherited from global settings');
        $response->assertDontSee('Use global default');
    }

    public function test_the_edit_page_shows_the_callback_url_for_oauth_drivers(): void
    {
        $space = $this->space();

        $response = $this->actingAs($this->admin())
            ->get(route('admin.storage-spaces.external-storage.edit', $space));

        $response->assertOk();
        $response->assertSee(
            route('admin.storage-spaces.external-storage.oauth.callback', ['driver' => 'dropbox']),
            false,
        );
    }

    public function test_a_driver_configuration_is_saved_encrypted_on_the_space(): void
    {
        $space = $this->space();

        $response = $this->actingAs($this->admin())
            ->post(route('admin.storage-spaces.external-storage.update', $space), [
                'storage_driver' => 'dropbox',
                'settings' => [
                    'dropbox' => [
                        'app_key' => 'app-key',
                        'app_secret' => 'app-secret',
                        'root' => '/edms',
                        'timeout' => '120',
                    ],
                ],
                'quota_bytes' => '1000',
            ]);

        $response->assertRedirect(route('admin.storage-spaces.external-storage.edit', $space));

        $space->refresh();
        $config = $space->storageConfig();

        $this->assertSame('dropbox', $space->storage_driver);
        $this->assertSame('app-key', $config['app_key']);
        $this->assertSame('app-secret', $config['app_secret']);
        $this->assertSame('/edms', $config['root']);
        $this->assertSame(120, $config['timeout']);
        $this->assertSame(1000, $config['quota_bytes']);

        // The stored column is encrypted, not plain JSON.
        $this->assertStringNotContainsString('app-secret', (string) $space->getRawOriginal('storage_config'));

        $this->actingAs($this->admin())
            ->get(route('admin.storage-spaces.external-storage.edit', $space))
            ->assertOk()
            ->assertSee('Not connected yet')
            ->assertSee('Use global default')
            ->assertDontSee('Inherited from global settings');
    }

    public function test_a_space_can_return_to_the_global_driver(): void
    {
        $space = $this->space();
        $space->storage_driver = 'dropbox';
        $space->setStorageConfig([
            'app_key' => 'app-key',
            'app_secret' => 'app-secret',
            'refresh_token' => 'refresh',
        ]);
        $space->save();

        $this->actingAs($this->admin())
            ->delete(route('admin.storage-spaces.external-storage.destroy', $space))
            ->assertRedirect(route('admin.storage-spaces.external-storage.edit', $space));

        $space->refresh();

        $this->assertNull($space->storage_driver);
        $this->assertNull($space->storageConfig());
        $this->assertNull($space->getRawOriginal('storage_config'));
    }

    public function test_switching_providers_does_not_retain_the_previous_secrets(): void
    {
        $space = $this->space();
        $space->storage_driver = 'dropbox';
        $space->setStorageConfig([
            'app_key' => 'dropbox-key',
            'app_secret' => 'dropbox-secret',
            'refresh_token' => 'dropbox-refresh',
            'root' => '/edms',
        ]);
        $space->save();

        $this->actingAs($this->admin())
            ->post(route('admin.storage-spaces.external-storage.update', $space), [
                'storage_driver' => 'google',
                'settings' => [
                    'google' => [
                        'client_id' => 'google-id',
                        'client_secret' => 'google-secret',
                        'refresh_token' => 'google-refresh',
                        'root' => '/google',
                        'timeout' => '120',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.storage-spaces.external-storage.edit', $space));

        $space->refresh();
        $config = $space->storageConfig();

        $this->assertSame('google', $space->storage_driver);
        $this->assertSame('google-id', $config['client_id']);
        $this->assertSame('google-refresh', $config['refresh_token']);
        $this->assertArrayNotHasKey('app_key', $config);
        $this->assertArrayNotHasKey('app_secret', $config);
    }

    public function test_a_blank_secret_keeps_the_stored_secret(): void
    {
        $space = $this->space();
        $space->storage_driver = 'dropbox';
        $space->setStorageConfig([
            'app_key' => 'app-key',
            'app_secret' => 'stored-secret',
            'root' => '/edms',
            'timeout' => 120,
        ]);
        $space->save();

        $this->actingAs($this->admin())
            ->post(route('admin.storage-spaces.external-storage.update', $space), [
                'storage_driver' => 'dropbox',
                'settings' => [
                    'dropbox' => [
                        'app_key' => 'new-key',
                        'app_secret' => '',
                        'root' => '/other',
                        'timeout' => '60',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.storage-spaces.external-storage.edit', $space));

        $config = $space->fresh()->storageConfig();

        $this->assertSame('new-key', $config['app_key']);
        $this->assertSame('stored-secret', $config['app_secret']);
        $this->assertSame('/other', $config['root']);
        $this->assertSame(60, $config['timeout']);
    }

    public function test_switching_to_local_stores_an_explicit_local_override_and_drops_cloud_secrets(): void
    {
        $space = $this->space();
        $space->storage_driver = 'dropbox';
        $space->setStorageConfig(['app_key' => 'app-key', 'app_secret' => 'secret', 'refresh_token' => 'refresh']);
        $space->save();

        $this->actingAs($this->admin())
            ->post(route('admin.storage-spaces.external-storage.update', $space), [
                'storage_driver' => 'local',
                'settings' => ['local' => ['local_disk' => 'edms']],
            ])
            ->assertRedirect(route('admin.storage-spaces.external-storage.edit', $space));

        $space->refresh();
        $config = $space->storageConfig();

        $this->assertSame('local', $space->storage_driver);
        $this->assertSame('edms', $config['local_disk']);
        $this->assertArrayNotHasKey('app_secret', $config);
        $this->assertArrayNotHasKey('refresh_token', $config);
    }

    public function test_oauth_credentials_can_be_saved_before_the_account_is_connected(): void
    {
        config([
            'uploads.google.client_id' => '',
            'uploads.google.client_secret' => '',
            'uploads.google.refresh_token' => '',
        ]);

        $space = $this->space();

        $this->actingAs($this->admin())
            ->post(route('admin.storage-spaces.external-storage.update', $space), [
                'storage_driver' => 'google',
                'settings' => [
                    'google' => [
                        'client_id' => 'google-id',
                        'client_secret' => 'google-secret',
                        'root' => '/edms',
                        'timeout' => '120',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.storage-spaces.external-storage.edit', $space));

        $space->refresh();
        $config = $space->storageConfig();

        $this->assertSame('google', $space->storage_driver);
        $this->assertSame('google-id', $config['client_id']);
        $this->assertSame('google-secret', $config['client_secret']);
        $this->assertArrayNotHasKey('refresh_token', $config);
    }

    public function test_an_incomplete_configuration_is_rejected_with_validation_messages(): void
    {
        config([
            'uploads.dropbox.app_key' => '',
            'uploads.dropbox.app_secret' => '',
            'uploads.dropbox.refresh_token' => '',
        ]);

        $space = $this->space();

        $response = $this->actingAs($this->admin())
            ->from(route('admin.storage-spaces.external-storage.edit', $space))
            ->post(route('admin.storage-spaces.external-storage.update', $space), [
                'storage_driver' => 'dropbox',
                'settings' => ['dropbox' => ['root' => '/custom']],
            ]);

        $response->assertRedirect(route('admin.storage-spaces.external-storage.edit', $space));
        $response->assertSessionHasErrors('storage_config');

        $this->assertNull($space->fresh()->storage_driver);

        // A failed save keeps the non-secret fields the admin typed.
        $this->get(route('admin.storage-spaces.external-storage.edit', $space))
            ->assertOk()
            ->assertSee('value="/custom"', false);
    }

    public function test_starting_an_oauth_flow_redirects_to_the_provider_with_pkce(): void
    {
        config([
            'uploads.dropbox.app_key' => 'app-key',
            'uploads.dropbox.app_secret' => 'app-secret',
            'uploads.dropbox.redirect_uri' => 'https://edms.test/callback',
        ]);

        $space = $this->space();

        $response = $this->actingAs($this->admin())
            ->post(route('admin.storage-spaces.external-storage.oauth.connect', [
                'space' => $space,
                'driver' => 'dropbox',
            ]), [
                'storage_driver' => 'dropbox',
                'settings' => [
                    'dropbox' => [
                        'app_key' => 'app-key',
                        'app_secret' => 'app-secret',
                        'root' => '/edms',
                        'timeout' => '120',
                    ],
                ],
            ]);

        $response->assertRedirect();

        $location = (string) $response->headers->get('Location');

        $this->assertStringStartsWith(DropboxOAuth::AUTHORIZE_URL, $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('app-key', $query['client_id']);
        $this->assertSame('https://edms.test/callback', $query['redirect_uri']);
        $this->assertSame('offline', $query['token_access_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_an_oauth_callback_stores_the_refresh_token_on_the_space(): void
    {
        Http::fake([
            DropboxOAuth::TOKEN_URL => Http::response(['refresh_token' => 'refresh-token']),
        ]);

        $space = $this->space();
        $state = 'state-value';

        $response = $this->actingAs($this->admin())
            ->withSession([
                'storage_oauth.'.$state => [
                    'space_id' => $space->id,
                    'driver' => 'dropbox',
                    'config' => [
                        'app_key' => 'app-key',
                        'app_secret' => 'app-secret',
                        'root' => '/edms',
                        'timeout' => 120,
                    ],
                    'redirect_uri' => 'https://edms.test/callback',
                    'code_verifier' => 'verifier-value',
                    'started_at' => now()->getTimestamp(),
                ],
            ])
            ->get(route('admin.storage-spaces.external-storage.oauth.callback', [
                'driver' => 'dropbox',
                'state' => $state,
                'code' => 'the-code',
            ]));

        $response->assertRedirect(route('admin.storage-spaces.external-storage.edit', $space));

        $space->refresh();

        $this->assertSame('dropbox', $space->storage_driver);
        $this->assertSame('refresh-token', $space->storageConfig()['refresh_token']);

        Http::assertSent(fn ($request) => $request['code'] === 'the-code'
            && $request['code_verifier'] === 'verifier-value');
    }

    public function test_an_oauth_callback_with_an_unknown_state_is_rejected(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(route('admin.storage-spaces.external-storage.oauth.callback', [
                'driver' => 'dropbox',
                'state' => 'forged',
                'code' => 'the-code',
            ]));

        $response->assertRedirect(route('admin.storage-spaces.index'));
        $response->assertSessionHas('error');
    }

    public function test_a_user_without_an_admin_permission_is_forbidden(): void
    {
        $space = $this->space();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.storage-spaces.external-storage.edit', $space))
            ->assertForbidden();
    }
}
