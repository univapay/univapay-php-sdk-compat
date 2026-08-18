<?php

namespace Univapay\Compat\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Support\Bridge;
use Univapay\Compat\Support\CompatContext;

class CompatContextTest extends TestCase
{
    private function bridge(): Bridge
    {
        $header = base64_encode(json_encode(['alg' => 'none']));
        $body = base64_encode(json_encode([
            'sub' => 'app_token',
            'iat' => 1,
            'merchant_id' => 'merchant-1',
            'store_id' => 'store-1',
            'domains' => [],
            'mode' => 'test',
            'creator_id' => 'creator-1',
            'version' => 1,
            'jti' => 'jti-1'
        ]));
        $jwt = AppJWT::createToken("$header.$body.sig", 'secret-1');
        return new Bridge($jwt);
    }

    public function testConstructorStoresBridgeStoreIdAndId()
    {
        $bridge = $this->bridge();
        $context = new CompatContext($bridge, 'store-1', 'charge-1');

        $this->assertSame($bridge, $context->bridge());
        $this->assertSame('store-1', $context->storeId);
        $this->assertSame('charge-1', $context->id);
    }

    public function testDefaultsStoreIdAndIdToNull()
    {
        $bridge = $this->bridge();
        $context = new CompatContext($bridge);

        $this->assertNull($context->storeId);
        $this->assertNull($context->id);
    }

    public function testWithStoreIdReturnsANewInstanceWithoutMutatingTheOriginal()
    {
        $bridge = $this->bridge();
        $original = new CompatContext($bridge, 'store-1', 'charge-1');

        $updated = $original->withStoreId('store-2');

        $this->assertNotSame($original, $updated);
        $this->assertSame('store-1', $original->storeId);
        $this->assertSame('store-2', $updated->storeId);
        $this->assertSame('charge-1', $updated->id);
        $this->assertSame($bridge, $updated->bridge());
    }

    public function testWithIdReturnsANewInstanceWithoutMutatingTheOriginal()
    {
        $bridge = $this->bridge();
        $original = new CompatContext($bridge, 'store-1', 'charge-1');

        $updated = $original->withId('charge-2');

        $this->assertNotSame($original, $updated);
        $this->assertSame('charge-1', $original->id);
        $this->assertSame('charge-2', $updated->id);
        $this->assertSame('store-1', $updated->storeId);
    }
}
