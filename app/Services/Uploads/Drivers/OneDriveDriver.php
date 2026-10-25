<?php

namespace App\Services\Uploads\Drivers;

use App\Contracts\CloudOAuthProvider;
use App\Contracts\UploadStorage;
use App\Services\Uploads\OneDriveClient;
use App\Services\Uploads\OneDriveOAuth;
use App\Services\Uploads\OneDriveUploadStorage;
use RuntimeException;

class OneDriveDriver extends AbstractStorageDriver
{
    public function key(): string
    {
        return 'onedrive';
    }

    public function label(): string
    {
        return 'Microsoft OneDrive';
    }

    public function description(): string
    {
        return 'A folder in OneDrive (personal or work/school). Connect with Microsoft once; the refresh token is stored encrypted.';
    }

    public function oauthProvider(): ?CloudOAuthProvider
    {
        return new OneDriveOAuth;
    }

    public function fields(): array
    {
        return [
            [
                'key' => 'client_id',
                'label' => 'Application (client) id',
                'type' => 'text',
                'default' => config('uploads.onedrive.client_id', ''),
                'help' => 'From Microsoft Entra → App registrations.',
            ],
            [
                'key' => 'client_secret',
                'label' => 'Client secret',
                'type' => 'password',
                'secret' => true,
                'help' => 'Leave blank to use ONEDRIVE_CLIENT_SECRET or the stored secret.',
            ],
            [
                'key' => 'tenant',
                'label' => 'Tenant',
                'type' => 'text',
                'default' => config('uploads.onedrive.tenant', 'common'),
                'help' => '"common" for personal accounts, or the tenant id/domain for an organisation.',
            ],
            [
                'key' => 'root',
                'label' => 'Root folder',
                'type' => 'text',
                'default' => '/edms',
                'help' => 'Folder inside the drive that holds EDMS files. It is created when missing.',
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
        ];
    }

    public function build(array $config): UploadStorage
    {
        return new OneDriveUploadStorage(
            $this->client($config),
            (int) $this->value(
                $config,
                'verify_read_back_limit',
                config('uploads.onedrive.verify_read_back_limit', 67108864),
            ),
        );
    }

    public function validate(array $config): array
    {
        $errors = [];

        if ($this->firstFilled($config['client_id'] ?? null, config('uploads.onedrive.client_id')) === '') {
            $errors[] = 'A Microsoft application (client) id is required.';
        }

        if ($this->firstFilled($config['client_secret'] ?? null, config('uploads.onedrive.client_secret')) === '') {
            $errors[] = 'A Microsoft client secret is required.';
        }

        // The refresh token is obtained by the interactive connection after the
        // credentials are saved; build() fails clearly if it is still missing.

        return $errors;
    }

    protected function client(array $config): OneDriveClient
    {
        $clientId = $this->firstFilled($config['client_id'] ?? null, config('uploads.onedrive.client_id'));
        $clientSecret = $this->firstFilled($config['client_secret'] ?? null, config('uploads.onedrive.client_secret'));
        $refresh = $this->firstFilled($config['refresh_token'] ?? null, config('uploads.onedrive.refresh_token'));

        if ($clientId === '' || $clientSecret === '' || $refresh === '') {
            throw new RuntimeException(
                'OneDrive is not connected: an application id, client secret and refresh token are required.'
            );
        }

        return new OneDriveClient(
            $clientId,
            $clientSecret,
            $refresh,
            (string) $this->value($config, 'tenant', config('uploads.onedrive.tenant', 'common')),
            (string) $this->value($config, 'root', config('uploads.onedrive.root', '/edms')),
            (int) $this->value($config, 'timeout', config('uploads.onedrive.timeout', 120)),
            (string) $this->value($config, 'graph_url', config('uploads.onedrive.graph_url', 'https://graph.microsoft.com/v1.0')),
            (string) $this->value($config, 'auth_url', config('uploads.onedrive.auth_url', 'https://login.microsoftonline.com')),
        );
    }
}
