<?php

namespace App\Services\Uploads;

/**
 * Proof Key for Code Exchange (RFC 7636) helpers.
 *
 * A verifier is generated once per authorisation attempt and kept in the
 * session. Only its SHA-256 challenge travels to the provider, so an
 * intercepted callback code cannot be exchanged by another client.
 */
final class Pkce
{
    public const METHOD = 'S256';

    /**
     * A high-entropy verifier: 64 random bytes, base64url encoded.
     */
    public static function verifier(): string
    {
        return self::base64Url(random_bytes(64));
    }

    public static function challenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    /**
     * The query parameters that advertise the challenge to the provider.
     *
     * @return array{code_challenge: string, code_challenge_method: string}
     */
    public static function parameters(string $verifier): array
    {
        return [
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => self::METHOD,
        ];
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
