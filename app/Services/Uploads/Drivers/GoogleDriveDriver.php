<?php

namespace App\Services\Uploads\Drivers;

use App\Contracts\CloudOAuthProvider;
use App\Contracts\UploadStorage;
use App\Services\Uploads\GoogleDriveClient;
use App\Services\Uploads\GoogleDriveOAuth;
use App\Services\Uploads\GoogleDriveUploadStorage;
use RuntimeException;

class GoogleDriveDriver extends AbstractStorageDriver
{
    public function key(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google Drive';
    }

    public function description(): string
    {
        return 'A folder in Google Drive. Connect with Google once; the refresh token is stored encrypted.';
    }

    public function oauthProvider(): ?CloudOAuthProvider
    {
        return new GoogleDriveOAuth;
    }

    public function fields(): array
    {
        return [
            [
                'key' => 'client_id',
                'label' => 'OAuth client id',
                'type' => 'text',
                'default' => config('uploads.google.client_id', ''),
                'help' => 'From Google Cloud Console → APIs & Services → Credentials.',
            ],
            [
                'key' => 'client_secret',
                'label' => 'OAuth client secret',
                'type' => 'password',
                'secret' => true,
                'help' => 'Leave blank to use GOOGLE_DRIVE_CLIENT_SECRET or the stored secret.',
            ],
            [
                'key' => 'root',
                'label' => 'Root folder',
                'type' => 'text',
                'default' => '/edms',
                'help' => 'Folder inside My Drive that holds EDMS files. It is created when missing.',
            ],
            [
                'key' => 'timeout',
                'label' => 'Timeout (seconds)',
                'type' => 'number',
                'default' => 120,
            ],
            [
                'key' => 'refresh_token',
                'label' => 'Refresh token',
                'type' => 'password',
                'secret' => true,
                'advanced' => true,
                'help' => 'Only needed when the interactive connection cannot be used.',
            ],
            [
                'key' => 'redirect_uri',
                'label' => 'OAuth redirect URI',
                'type' => 'url',
                'placeholder' => route('admin.storage-spaces.external-storage.oauth.callback', ['driver' => 'google']),
                'help' => 'The exact URL registered on the Google OAuth client. Leave blank to use the URL shown above.',
            ],
        ];
    }

    public function build(array $config): UploadStorage
    {
        return new GoogleDriveUploadStorage(
            $this->client($config),
            (int) $this->value(
                $config,
                'verify_read_back_limit',
                config('uploads.google.verify_read_back_limit', 67108864),
            ),
        );
    }

    public function validate(array $config): array
    {
        $errors = [];

        if ($this->firstFilled($config['client_id'] ?? null, config('uploads.google.client_id')) === '') {
            $errors[] = 'A Google OAuth client id is required.';
        }

        if ($this->firstFilled($config['client_secret'] ?? null, config('uploads.google.client_secret')) === '') {
            $errors[] = 'A Google OAuth client secret is required.';
        }

        // The refresh token is obtained by the interactive connection after the
        // credentials are saved; build() fails clearly if it is still missing.

        return $errors;
    }

    protected function client(array $config): GoogleDriveClient
    {
        $clientId = $this->firstFilled($config['client_id'] ?? null, config('uploads.google.client_id'));
        $clientSecret = $this->firstFilled($config['client_secret'] ?? null, config('uploads.google.client_secret'));
        $refresh = $this->firstFilled($config['refresh_token'] ?? null, config('uploads.google.refresh_token'));

        if ($clientId === '' || $clientSecret === '' || $refresh === '') {
            throw new RuntimeException(
                'Google Drive is not connected: an OAuth client id, client secret and refresh token are required.'
            );
        }

        return new GoogleDriveClient(
            $clientId,
            $clientSecret,
            $refresh,
            (string) $this->value($config, 'root', config('uploads.google.root', '/edms')),
            (int) $this->value($config, 'timeout', config('uploads.google.timeout', 120)),
            (string) $this->value($config, 'api_url', config('uploads.google.api_url', 'https://www.googleapis.com/drive/v3')),
            (string) $this->value($config, 'upload_url', config('uploads.google.upload_url', 'https://www.googleapis.com/upload/drive/v3')),
            (string) $this->value($config, 'token_url', config('uploads.google.token_url', 'https://oauth2.googleapis.com/token')),
        );
    }
}
