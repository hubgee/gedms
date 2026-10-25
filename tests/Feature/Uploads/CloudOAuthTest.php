<?php

namespace Tests\Feature\Uploads;

use App\Services\Uploads\DropboxOAuth;
use App\Services\Uploads\GoogleDriveOAuth;
use App\Services\Uploads\OneDriveOAuth;
use App\Services\Uploads\Pkce;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The OAuth handshake is pure URL building on the way out and one HTTP post on
 * the way back, so the provider can be faked and no account is needed.
 */
class CloudOAuthTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_dropbox_authorize_url_requests_offline_access_and_pkce(): void
    {
        $verifier = Pkce::verifier();

        $url = (new DropboxOAuth)->authorizeUrl(
            ['app_key' => 'app-key'],
            'the-state',
            'https://edms.test/callback',
            $verifier,
        );

        $this->assertStringStartsWith(DropboxOAuth::AUTHORIZE_URL, $url);

        $query = $this->query($url);

        $this->assertSame('app-key', $query['client_id']);
        $this->assertSame('https://edms.test/callback', $query['redirect_uri']);
        $this->assertSame('the-state', $query['state']);
        $this->assertSame('offline', $query['token_access_type']);
        $this->assertStringContainsString('files.content.write', $query['scope']);
        $this->assertSame(Pkce::challenge($verifier), $query['code_challenge']);
        $this->assertSame('S256', $query['code_challenge_method']);
    }

    public function test_dropbox_exchange_sends_the_verifier_and_returns_the_refresh_token(): void
    {
        Http::fake([
            DropboxOAuth::TOKEN_URL => Http::response([
                'access_token' => 'short-lived',
                'refresh_token' => 'refresh-token',
            ]),
        ]);

        $result = (new DropboxOAuth)->exchange(
            ['app_key' => 'app-key', 'app_secret' => 'app-secret'],
            'the-code',
            'https://edms.test/callback',
            'the-verifier',
        );

        $this->assertSame('refresh-token', $result['refresh_token']);
        $this->assertSame('app-key', $result['app_key']);
        $this->assertSame('app-secret', $result['app_secret']);

        Http::assertSent(fn ($request) => $request->url() === DropboxOAuth::TOKEN_URL
            && $request['code'] === 'the-code'
            && $request['redirect_uri'] === 'https://edms.test/callback'
            && $request['code_verifier'] === 'the-verifier');
    }

    public function test_google_authorize_url_requests_offline_consent_and_pkce(): void
    {
        $verifier = Pkce::verifier();

        $url = (new GoogleDriveOAuth)->authorizeUrl(
            ['client_id' => 'client-id'],
            'the-state',
            'https://edms.test/callback',
            $verifier,
        );

        $query = $this->query($url);

        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame(Pkce::challenge($verifier), $query['code_challenge']);
        $this->assertSame('S256', $query['code_challenge_method']);
    }

    public function test_google_exchange_sends_the_verifier(): void
    {
        Http::fake([
            GoogleDriveOAuth::TOKEN_URL => Http::response(['refresh_token' => 'google-refresh']),
        ]);

        $result = (new GoogleDriveOAuth)->exchange(
            ['client_id' => 'client-id', 'client_secret' => 'client-secret'],
            'the-code',
            'https://edms.test/callback',
            'the-verifier',
        );

        $this->assertSame('google-refresh', $result['refresh_token']);
        $this->assertSame('client-id', $result['client_id']);
        $this->assertSame('client-secret', $result['client_secret']);

        Http::assertSent(fn ($request) => $request->url() === GoogleDriveOAuth::TOKEN_URL
            && $request['code_verifier'] === 'the-verifier');
    }

    public function test_onedrive_authorize_url_uses_the_configured_tenant(): void
    {
        config(['uploads.onedrive.auth_url' => 'https://login.microsoftonline.com']);

        $url = (new OneDriveOAuth)->authorizeUrl(
            ['client_id' => 'client-id', 'tenant' => 'contoso.onmicrosoft.com'],
            'the-state',
            'https://edms.test/callback',
            'the-verifier',
        );

        $this->assertStringStartsWith(
            'https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize',
            $url,
        );

        $query = $this->query($url);

        $this->assertStringContainsString('offline_access', $query['scope']);
        $this->assertStringContainsString('Files.ReadWrite.All', $query['scope']);
        $this->assertSame(Pkce::challenge('the-verifier'), $query['code_challenge']);
    }

    public function test_onedrive_exchange_sends_the_verifier_and_scope(): void
    {
        config(['uploads.onedrive.auth_url' => 'https://login.microsoftonline.com']);

        Http::fake([
            'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response([
                'refresh_token' => 'microsoft-refresh',
            ]),
        ]);

        $result = (new OneDriveOAuth)->exchange(
            ['client_id' => 'client-id', 'client_secret' => 'client-secret'],
            'the-code',
            'https://edms.test/callback',
            'the-verifier',
        );

        $this->assertSame('microsoft-refresh', $result['refresh_token']);
        $this->assertSame('common', $result['tenant']);

        Http::assertSent(fn ($request) => $request['code_verifier'] === 'the-verifier'
            && $request['scope'] === OneDriveOAuth::SCOPE);
    }

    public function test_a_provider_error_is_surfaced_rather_than_swallowed(): void
    {
        Http::fake([
            DropboxOAuth::TOKEN_URL => Http::response([
                'error_description' => 'code has expired',
            ], 400),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('code has expired');

        (new DropboxOAuth)->exchange(
            ['app_key' => 'app-key', 'app_secret' => 'app-secret'],
            'the-code',
            'https://edms.test/callback',
        );
    }

    public function test_a_response_without_a_refresh_token_is_rejected(): void
    {
        Http::fake([
            GoogleDriveOAuth::TOKEN_URL => Http::response(['access_token' => 'short-lived']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not return a refresh token');

        (new GoogleDriveOAuth)->exchange(
            ['client_id' => 'client-id', 'client_secret' => 'client-secret'],
            'the-code',
            'https://edms.test/callback',
        );
    }
}
