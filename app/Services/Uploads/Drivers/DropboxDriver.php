<?php

namespace App\Services\Uploads\Drivers;

use App\Contracts\CloudOAuthProvider;
use App\Contracts\UploadStorage;
use App\Services\Uploads\DropboxClient;
use App\Services\Uploads\DropboxOAuth;
use App\Services\Uploads\DropboxUploadStorage;

class DropboxDriver extends AbstractStorageDriver
{
    public function key(): string
    {
        return 'dropbox';
    }

    public function label(): string
    {
        return 'Dropbox';
    }

    public function description(): string
    {
        return 'A folder in a Dropbox account. Connect with Dropbox once and the refresh token is stored encrypted.';
    }

    public function oauthProvider(): ?CloudOAuthProvider
    {
        return new DropboxOAuth;
    }

    public function fields(): array
    {
        return [
            [
                'key' => 'app_key',
                'label' => 'App key',
                'type' => 'text',
                'default' => config('uploads.dropbox.app_key', ''),
                'help' => 'From the Dropbox app console. Leave blank to use DROPBOX_APP_KEY.',
            ],
            [
                'key' => 'app_secret',
                'label' => 'App secret',
                'type' => 'password',
                'secret' => true,
                'help' => 'Leave blank to use DROPBOX_APP_SECRET or the stored secret.',
            ],
            [
                'key' => 'root',
                'label' => 'Root folder',
                'type' => 'text',
                'default' => '/edms',
                'help' => 'Folder inside the Dropbox account that holds EDMS files.',
            ],
            [
                'key' => 'timeout',
                'label' => 'Timeout (seconds)',
                'type' => 'number',
                'default' => (int) config('uploads.dropbox.timeout', 300),
                'help' => 'Seconds before a Dropbox request is abandoned. Keep it below the web server timeout.',
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
                'placeholder' => route('admin.storage-spaces.external-storage.oauth.callback', ['driver' => 'dropbox']),
                'help' => 'The exact URL registered on the Dropbox app. Leave blank to use the URL shown above.',
            ],
        ];
    }

    public function build(array $config): UploadStorage
    {
        return new DropboxUploadStorage(
            $this->client($config),
            (int) $this->value($config, 'verify_read_back_limit', config('uploads.dropbox.verify_read_back_limit', 67108864)),
        );
    }

    public function validate(array $config): array
    {
        $key = $this->firstFilled(
            $config['app_key'] ?? null,
            $this->secretFromFile('uploads.dropbox.app_key_file'),
            config('uploads.dropbox.app_key'),
        );

        $secret = $this->firstFilled(
            $config['app_secret'] ?? null,
            $this->secretFromFile('uploads.dropbox.app_secret_file'),
            config('uploads.dropbox.app_secret'),
        );

        $errors = [];

        if ($key === '') {
            $errors[] = 'A Dropbox app key is required.';
        }

        if ($secret === '') {
            $errors[] = 'A Dropbox app secret is required.';
        }

        // The refresh token is deliberately not required here: the interactive
        // connection obtains it after the credentials are saved. build() fails
        // with a clear message if the space is used before it is connected.

        return $errors;
    }

    protected function client(array $config): DropboxClient
    {
        $key = $this->firstFilled($config['app_key'] ?? null, $this->secretFromFile('uploads.dropbox.app_key_file'), config('uploads.dropbox.app_key'));
        $secret = $this->firstFilled($config['app_secret'] ?? null, $this->secretFromFile('uploads.dropbox.app_secret_file'), config('uploads.dropbox.app_secret'));
        $refresh = $this->firstFilled($config['refresh_token'] ?? null, $this->secretFromFile('uploads.dropbox.refresh_token_file'), config('uploads.dropbox.refresh_token'));

        if ($key === '' || $secret === '' || $refresh === '') {
            throw new \RuntimeException(
                'The dropbox upload driver needs DROPBOX_APP_KEY, DROPBOX_APP_SECRET and DROPBOX_REFRESH_TOKEN. '
                .'Connect Dropbox from the admin UI to obtain them.'
            );
        }

        return new DropboxClient(
            $key,
            $secret,
            $refresh,
            (string) $this->value($config, 'root', config('uploads.dropbox.root', '/edms')),
            (int) $this->value($config, 'timeout', config('uploads.dropbox.timeout', 300)),
            (string) $this->value($config, 'api_url', config('uploads.dropbox.api_url', 'https://api.dropboxapi.com/2')),
            (string) $this->value($config, 'content_url', config('uploads.dropbox.content_url', 'https://content.dropboxapi.com/2')),
        );
    }

    /**
     * Honour Docker-style *_FILE secret mounts.
     */
    protected function secretFromFile(string $configKey): string
    {
        $file = (string) config($configKey, '');

        if ($file !== '' && is_readable($file)) {
            $contents = @file_get_contents($file);

            if (is_string($contents)) {
                return trim($contents);
            }
        }

        return '';
    }
}
