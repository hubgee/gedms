<?php

namespace App\Contracts;

/**
 * An interactive OAuth 2.0 authorisation-code flow for a storage provider.
 *
 * The admin UI only knows these two operations, so Dropbox, Google Drive,
 * OneDrive or a future provider are connected the same way: click a button,
 * approve on the provider, come back with a refresh token.
 */
interface CloudOAuthProvider
{
    /**
     * Whether the provider should use PKCE (RFC 7636).
     *
     * PKCE binds the authorisation code to the browser that started the flow
     * and is required by several providers for public or localhost clients.
     */
    public function usesPkce(): bool;

    /**
     * Build the provider URL the browser is sent to.
     *
     * @param  array<string, mixed>  $config  the driver settings entered so far
     */
    public function authorizeUrl(
        array $config,
        string $state,
        string $redirectUri,
        ?string $codeVerifier = null,
    ): string;

    /**
     * Exchange the one-time code for long-lived credentials.
     *
     * The returned array is merged into the driver configuration. A driver
     * that receives a refresh token stores it encrypted with the rest of the
     * configuration.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the provider rejects the code
     */
    public function exchange(
        array $config,
        string $code,
        string $redirectUri,
        ?string $codeVerifier = null,
    ): array;
}
