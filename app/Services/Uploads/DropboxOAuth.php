<?php

namespace App\Services\Uploads;

use App\Contracts\CloudOAuthProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Dropbox OAuth 2.0 authorisation-code flow with offline access.
 *
 * Before this class existed an administrator had to open the authorise URL by
 * hand, copy the code out of the browser, exchange it for a refresh token with
 * curl and paste that token into a form. That is four error-prone steps for
 * something the application can do itself.
 */
class DropboxOAuth implements CloudOAuthProvider
{
    public const AUTHORIZE_URL = 'https://www.dropbox.com/oauth2/authorize';

    public const TOKEN_URL = 'https://api.dropboxapi.com/oauth2/token';

    /**
     * Scopes the byte store needs: write and read content, and read metadata
     * for existence checks and listings.
     *
     * @var array<int, string>
     */
    public const SCOPES = [
        'files.content.write',
        'files.content.read',
        'files.metadata.read',
    ];

    public function usesPkce(): bool
    {
        return true;
    }

    public function authorizeUrl(
        array $config,
        string $state,
        string $redirectUri,
        ?string $codeVerifier = null,
    ): string {
        $appKey = $this->appKey($config);

        $parameters = [
            'client_id' => $appKey,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'token_access_type' => 'offline',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
        ];

        if ($codeVerifier !== null && $codeVerifier !== '') {
            $parameters += Pkce::parameters($codeVerifier);
        }

        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        return self::AUTHORIZE_URL.'?'.$query;
    }

    public function exchange(
        array $config,
        string $code,
        string $redirectUri,
        ?string $codeVerifier = null,
    ): array {
        $appKey = $this->appKey($config);
        $appSecret = $this->appSecret($config);

        $parameters = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $appKey,
            'client_secret' => $appSecret,
            'redirect_uri' => $redirectUri,
        ];

        if ($codeVerifier !== null && $codeVerifier !== '') {
            $parameters['code_verifier'] = $codeVerifier;
        }

        $response = Http::asForm()
            ->withOptions(['timeout' => 30])
            ->post(self::TOKEN_URL, $parameters);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status()));
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new RuntimeException('Dropbox returned an unreadable token response.');
        }

        if (! isset($decoded['refresh_token']) || ! is_string($decoded['refresh_token'])
            || $decoded['refresh_token'] === '') {
            throw new RuntimeException(
                'Dropbox did not return a refresh token. Re-authorise with offline access enabled.'
            );
        }

        return [
            // Persist the credentials too, so the space keeps working even if
            // the environment changes later.
            'app_key' => $appKey,
            'app_secret' => $appSecret,
            'refresh_token' => $decoded['refresh_token'],
        ];
    }

    protected function appKey(array $config): string
    {
        $key = trim((string) ($config['app_key'] ?? ''));

        if ($key === '') {
            $key = trim((string) config('uploads.dropbox.app_key', ''));
        }

        if ($key === '') {
            throw new RuntimeException('A Dropbox app key is required.');
        }

        return $key;
    }

    protected function appSecret(array $config): string
    {
        $secret = trim((string) ($config['app_secret'] ?? ''));

        if ($secret === '') {
            $secret = trim((string) config('uploads.dropbox.app_secret', ''));
        }

        if ($secret === '') {
            throw new RuntimeException('A Dropbox app secret is required.');
        }

        return $secret;
    }

    /**
     * @param  array<string, mixed>|null  $decoded
     */
    protected function errorMessage(?array $decoded, int $status): string
    {
        if (is_array($decoded)) {
            $description = $decoded['error_description'] ?? null;

            if (is_string($description) && $description !== '') {
                return 'Dropbox refused the authorisation: '.$description;
            }

            $error = $decoded['error'] ?? null;

            if (is_string($error) && $error !== '') {
                return 'Dropbox refused the authorisation: '.$error;
            }
        }

        return 'Dropbox refused the authorisation (HTTP '.$status.').';
    }
}
