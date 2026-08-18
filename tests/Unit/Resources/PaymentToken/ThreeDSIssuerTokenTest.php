<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources\PaymentToken;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\CallMethod;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Resources\PaymentToken\ThreeDSIssuerToken;

class ThreeDSIssuerTokenTest extends TestCase
{
    public function testHydratesFromJson()
    {
        $token = ThreeDSIssuerToken::getSchema()->parse([
            'call_method' => 'http_post',
            'content_type' => 'application/x-www-form-urlencoded',
            'issuer_token' => 'issuer-token-1',
            'payload' => 'PAReq=abc',
            'payment_type' => 'card'
        ]);

        $this->assertEquals(CallMethod::HTTP_POST(), $token->callMethod);
        $this->assertSame('application/x-www-form-urlencoded', $token->contentType);
        $this->assertSame('issuer-token-1', $token->issuerToken);
        $this->assertSame('PAReq=abc', $token->payload);
        $this->assertEquals(PaymentType::CARD(), $token->paymentType);
    }
}
