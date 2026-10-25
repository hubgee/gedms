<?php

namespace Tests\Unit\Uploads;

use App\Services\Uploads\Pkce;
use PHPUnit\Framework\TestCase;

/**
 * PKCE is arithmetic and encoding, so it needs no application, no network and
 * no provider account.
 */
class PkceTest extends TestCase
{
    public function test_a_verifier_is_a_base64url_string_of_rfc_length(): void
    {
        $verifier = Pkce::verifier();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier);
        $this->assertGreaterThanOrEqual(43, strlen($verifier));
        $this->assertLessThanOrEqual(128, strlen($verifier));
    }

    public function test_each_verifier_is_unique(): void
    {
        $this->assertNotSame(Pkce::verifier(), Pkce::verifier());
    }

    public function test_the_challenge_is_the_base64url_sha256_of_the_verifier(): void
    {
        $verifier = 'a-verifier-with-enough-entropy-for-the-test';
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->assertSame($expected, Pkce::challenge($verifier));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]{43}$/', Pkce::challenge($verifier));
    }

    public function test_parameters_advertise_the_s256_method(): void
    {
        $parameters = Pkce::parameters('a-verifier');

        $this->assertSame([
            'code_challenge' => Pkce::challenge('a-verifier'),
            'code_challenge_method' => 'S256',
        ], $parameters);
    }
}
