<?php

namespace App\Services\Uploads;

use App\Contracts\CloudOAuthProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google OAuth 2.0 authorisation-code flow.
 *
 * "access_type=offline" plus "prompt=consent" is what makes Google return a
 * refresh token; without the prompt a second connection can silently return
 * only a short-lived access token.
 */
class GoogleDriveOAuth implements CloudOAuthProvider
{
    public const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const SCOPE = 'https://www.googleapis.com/auth/drive';

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
        $parameters = [
            'client_id' => $this->clientId($config),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
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
        $clientId = $this->clientId($config);
        $clientSecret = $this->clientSecret($config);

        $parameters = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
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
            throw new RuntimeException('Google returned an unreadable token response.');
        }

        if (! isset($decoded['refresh_token']) || ! is_string($decoded['refresh_token'])
            || $decoded['refresh_token'] === '') {
            throw new RuntimeException(
                'Google did not return a refresh token. Revoke the app in your Google account and connect again.'
            );
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $decoded['refresh_token'],
        ];
    }

    protected function clientId(array $config): string
    {
        $id = trim((string) ($config['client_id'] ?? ''));

        if ($id === '') {
            $id = trim((string) config('uploads.google.client_id', ''));
        }

        if ($id === '') {
            throw new RuntimeException('A Google OAuth client id is required.');
        }

        return $id;
    }

    protected function clientSecret(array $config): string
    {
        $secret = trim((string) ($config['client_secret'] ?? ''));

        if ($secret === '') {
            $secret = trim((string) config('uploads.google.client_secret', ''));
        }

        if ($secret === '') {
            throw new RuntimeException('A Google OAuth client secret is required.');
        }

        return $secret;
    }

    /**
     * @param  array<string, mixed>|null  $decoded
     */
    protected function errorMessage(?array $decoded, int $status): string
    {
        if (is_array($decoded)) {
            foreach (['error_description', 'error', 'message'] as $key) {
                $value = $decoded[$key] ?? null;

                if (is_string($value) && $value !== '') {
                    return 'Google refused the authorisation: '.$value;
                }
            }
        }

        return 'Google refused the authorisation (HTTP '.$status.').';
    }
}
