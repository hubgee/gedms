<?php

namespace App\Services\Uploads;

use App\Contracts\CloudOAuthProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Microsoft identity platform OAuth 2.0 flow for OneDrive.
 *
 * "common" works for personal Microsoft accounts and for work/school accounts
 * that have not restricted the application; a tenant id or domain can be set
 * per space when the organisation requires it.
 */
class OneDriveOAuth implements CloudOAuthProvider
{
    public const SCOPE = 'offline_access Files.ReadWrite.All';

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
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => self::SCOPE,
            'state' => $state,
        ];

        if ($codeVerifier !== null && $codeVerifier !== '') {
            $parameters += Pkce::parameters($codeVerifier);
        }

        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        return $this->authorizeEndpoint($config).'?'.$query;
    }

    public function exchange(
        array $config,
        string $code,
        string $redirectUri,
        ?string $codeVerifier = null,
    ): array {
        $clientId = $this->clientId($config);
        $clientSecret = $this->clientSecret($config);
        $tenant = $this->tenant($config);

        $parameters = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPE,
        ];

        if ($codeVerifier !== null && $codeVerifier !== '') {
            $parameters['code_verifier'] = $codeVerifier;
        }

        $response = Http::asForm()
            ->withOptions(['timeout' => 30])
            ->post($this->tokenEndpoint($tenant), $parameters);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status()));
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new RuntimeException('Microsoft returned an unreadable token response.');
        }

        if (! isset($decoded['refresh_token']) || ! is_string($decoded['refresh_token'])
            || $decoded['refresh_token'] === '') {
            throw new RuntimeException(
                'Microsoft did not return a refresh token. Make sure offline_access is consented.'
            );
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'tenant' => $tenant,
            'refresh_token' => $decoded['refresh_token'],
        ];
    }

    protected function clientId(array $config): string
    {
        $id = trim((string) ($config['client_id'] ?? ''));

        if ($id === '') {
            $id = trim((string) config('uploads.onedrive.client_id', ''));
        }

        if ($id === '') {
            throw new RuntimeException('A Microsoft application (client) id is required.');
        }

        return $id;
    }

    protected function clientSecret(array $config): string
    {
        $secret = trim((string) ($config['client_secret'] ?? ''));

        if ($secret === '') {
            $secret = trim((string) config('uploads.onedrive.client_secret', ''));
        }

        if ($secret === '') {
            throw new RuntimeException('A Microsoft client secret is required.');
        }

        return $secret;
    }

    protected function tenant(array $config): string
    {
        $tenant = trim((string) ($config['tenant'] ?? ''));

        return $tenant !== '' ? $tenant : (string) config('uploads.onedrive.tenant', 'common');
    }

    protected function authorizeEndpoint(array $config): string
    {
        $base = rtrim((string) config('uploads.onedrive.auth_url', 'https://login.microsoftonline.com'), '/');

        return $base.'/'.$this->tenant($config).'/oauth2/v2.0/authorize';
    }

    protected function tokenEndpoint(string $tenant): string
    {
        $base = rtrim((string) config('uploads.onedrive.auth_url', 'https://login.microsoftonline.com'), '/');

        return $base.'/'.$tenant.'/oauth2/v2.0/token';
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
                    return 'Microsoft refused the authorisation: '.$value;
                }
            }
        }

        return 'Microsoft refused the authorisation (HTTP '.$status.').';
    }
}
