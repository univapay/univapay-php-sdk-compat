<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Support;

use Univapay\Compat\Resources\Authentication\AppJWT;

/**
 * Shared helper for tests that need a real `Univapay\Compat\Resources\Authentication\AppJWT`
 * without a real signed JWT -- `AppJWT::createToken()` never verifies its input's signature
 * (matching the old SDK exactly, see that class's own doc), so a hand-built, unsigned
 * three-segment JWT-shaped string is sufficient, same technique
 * tests/Unit/Resources/Authentication/AppJWTTest.php already uses. Used by both
 * tests/Integration/IntegrationTestCase.php (against a real Prism) and tests/Hostile/
 * (against tests/Hostile/Support/FakeServer.php's real local HTTP server) so both suites build
 * auth contexts identically.
 */
trait FakeJwtBuilder
{
    protected function buildStoreAppToken(
        string $storeId,
        string $merchantId,
        string $secret = 'test-secret'
    ): AppJWT {
        return AppJWT::createToken($this->buildJwtString([
            'sub' => 'app_token',
            'iat' => 1700000000,
            'merchant_id' => $merchantId,
            'store_id' => $storeId,
            'domains' => ['example.com'],
            'mode' => 'test',
            'creator_id' => 'test',
            'version' => 1,
            'jti' => 'test-store-jti',
        ]), $secret);
    }

    protected function buildMerchantAppToken(string $merchantId, string $secret = 'test-secret'): AppJWT
    {
        return AppJWT::createToken($this->buildJwtString([
            'sub' => 'app_token',
            'iat' => 1700000000,
            'merchant_id' => $merchantId,
            'creator_id' => 'test',
            'version' => 1,
            'jti' => 'test-merchant-jti',
        ]), $secret);
    }

    protected function buildJwtString(array $payload): string
    {
        $header = base64_encode(json_encode(['alg' => 'none']));
        $body = base64_encode(json_encode($payload));
        return "$header.$body.sig";
    }
}
