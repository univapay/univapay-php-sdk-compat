<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources\PaymentToken;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Resources\PaymentToken\QrMerchantToken;

class QrMerchantTokenTest extends TestCase
{
    /**
     * `qr_image_url` is stored verbatim, whatever shape the wire sends -- despite its name
     * (spec `TokenResponseQrMerchantData.qr_image_url`), it's not guaranteed to be a URL (some
     * brands return an opaque numeric payload instead); this fixture deliberately uses a non-URL
     * value so the test doesn't itself encode the wrong assumption.
     */
    public function testHydratesFromJson()
    {
        $token = QrMerchantToken::getSchema()->parse([
            'ready' => true,
            'qr_image_url' => '71001234567890202604141200450'
        ]);

        $this->assertTrue($token->ready);
        $this->assertSame('71001234567890202604141200450', $token->qrImageUrl);
    }

    public function testQrImageUrlIsOptional()
    {
        $token = QrMerchantToken::getSchema()->parse(['ready' => false]);

        $this->assertFalse($token->ready);
        $this->assertNull($token->qrImageUrl);
    }
}
