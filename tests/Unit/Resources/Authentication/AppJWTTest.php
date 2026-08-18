<?php

namespace Univapay\Compat\Tests\Unit\Resources\Authentication;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Resources\Authentication\InvalidJWTFormat;
use Univapay\Compat\Resources\Authentication\MerchantAppJWT;
use Univapay\Compat\Resources\Authentication\StoreAppJWT;

/**
 * Ported (namespace-only) old-SDK AppJWT parser, exercised against hand-built JWT-shaped strings
 * (no real signing -- `createToken()` never verifies the signature, exactly like upstream).
 */
class AppJWTTest extends TestCase
{
    private function buildToken(array $payload): string
    {
        $header = base64_encode(json_encode(['alg' => 'none']));
        $body = base64_encode(json_encode($payload));
        return "$header.$body.sig";
    }

    public function testCreateTokenParsesStoreAppJWT()
    {
        $token = $this->buildToken([
            'sub' => 'app_token',
            'iat' => 1610000000,
            'merchant_id' => 'merchant-1',
            'store_id' => 'store-1',
            'domains' => ['example.com'],
            'mode' => 'test',
            'creator_id' => 'creator-1',
            'version' => 1,
            'jti' => 'jti-1'
        ]);

        $jwt = AppJWT::createToken($token, 'secret-1');

        $this->assertInstanceOf(StoreAppJWT::class, $jwt);
        $this->assertSame($token, $jwt->token);
        $this->assertSame('secret-1', $jwt->secret);
        $this->assertSame('merchant-1', $jwt->merchantId);
        $this->assertSame('store-1', $jwt->storeId);
        $this->assertSame(['example.com'], $jwt->domains);
        $this->assertSame(AppTokenMode::TEST(), $jwt->mode);
        $this->assertSame('creator-1', $jwt->creatorId);
        $this->assertSame('jti-1', $jwt->jti);
    }

    public function testCreateTokenParsesMerchantAppJWTWhenStoreIdAbsent()
    {
        // See MerchantAppJWT's class doc: the old SDK declared its properties out of sync with
        // its own constructor (plus a dead `$issuedAt` field), which silently scrambled
        // token/secret/iat/jti for every merchant-level JWT via the schema-reflection mechanism.
        // This port corrects the property order so all fields -- not just merchantId, which
        // happened to land correctly by coincidence -- parse correctly.
        $token = $this->buildToken([
            'sub' => 'app_token',
            'iat' => 1610000000,
            'merchant_id' => 'merchant-1',
            'creator_id' => 'creator-1',
            'version' => 1,
            'jti' => 'jti-1'
        ]);

        $jwt = AppJWT::createToken($token, 'secret-1');

        $this->assertInstanceOf(MerchantAppJWT::class, $jwt);
        $this->assertSame('merchant-1', $jwt->merchantId);
        $this->assertSame(1610000000, $jwt->iat);
        $this->assertSame('creator-1', $jwt->creatorId);
        $this->assertSame(1, $jwt->version);
        $this->assertSame('jti-1', $jwt->jti);
        $this->assertSame($token, $jwt->token);
        $this->assertSame('secret-1', $jwt->secret);
    }

    public function testCreateTokenRejectsWrongSubject()
    {
        $token = $this->buildToken([
            'sub' => 'not_app_token',
            'iat' => 1,
            'merchant_id' => 'merchant-1',
            'creator_id' => 'creator-1',
            'version' => 1,
            'jti' => 'jti-1'
        ]);

        $this->expectException(InvalidJWTFormat::class);
        $this->expectExceptionMessage('Invalid subject');

        AppJWT::createToken($token, 'secret-1');
    }

    public function testCreateTokenRejectsNonJsonBody()
    {
        $this->expectException(InvalidJWTFormat::class);

        // Well-formed three-segment shape (avoids a PHP notice on the missing second segment)
        // whose middle segment does not decode to JSON.
        AppJWT::createToken('abc.def.ghi', 'secret-1');
    }
}
