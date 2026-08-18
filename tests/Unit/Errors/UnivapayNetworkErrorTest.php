<?php

namespace Univapay\Compat\Tests\Unit\Errors;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Errors\UnivapayError;
use Univapay\Compat\Errors\UnivapayNetworkError;

class UnivapayNetworkErrorTest extends TestCase
{
    public function testExtendsUnivapayErrorAndCarriesUrl()
    {
        $error = new UnivapayNetworkError('https://api.univapay.com/charges', 'Connection refused');

        $this->assertInstanceOf(UnivapayError::class, $error);
        $this->assertSame('https://api.univapay.com/charges', $error->url);
        $this->assertStringContainsString('https://api.univapay.com/charges', $error->getMessage());
        $this->assertStringContainsString('Connection refused', $error->getMessage());
    }

    public function testMessageOmitsColonWhenNoUnderlyingMessageGiven()
    {
        $error = new UnivapayNetworkError('https://api.univapay.com/charges');

        $this->assertSame('Network error while requesting https://api.univapay.com/charges', $error->getMessage());
    }
}
