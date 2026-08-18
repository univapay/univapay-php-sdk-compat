<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources\PaymentToken;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\CallMethod;
use Univapay\Compat\Resources\PaymentToken\OnlineToken;

class OnlineTokenTest extends TestCase
{
    public function testHydratesFromJson()
    {
        $token = OnlineToken::getSchema()->parse([
            'issuer_token' => 'issuer-token-1',
            'call_method' => 'web'
        ]);

        $this->assertSame('issuer-token-1', $token->issuerToken);
        $this->assertEquals(CallMethod::WEB(), $token->callMethod);
    }
}
