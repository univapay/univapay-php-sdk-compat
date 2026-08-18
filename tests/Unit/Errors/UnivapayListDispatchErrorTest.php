<?php

namespace Univapay\Compat\Tests\Unit\Errors;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Errors\UnivapayListDispatchError;
use Univapay\Compat\Errors\UnivapaySDKError;

class UnivapayListDispatchErrorTest extends TestCase
{
    public function testExtendsUnivapaySdkError()
    {
        $error = new UnivapayListDispatchError('anything');

        $this->assertInstanceOf(UnivapaySDKError::class, $error);
        $this->assertSame('anything', $error->getMessage());
    }

    public function testUnmappableKeyMentionsTheKeyAndTheEndpoint()
    {
        $error = UnivapayListDispatchError::unmappableKey('card_number', 'listAllCharges');

        $this->assertStringContainsString('card_number', $error->getMessage());
        $this->assertStringContainsString('listAllCharges', $error->getMessage());
    }

    public function testPendingSpecExtensionMentionsTheKeyEndpointAndSpecTask()
    {
        $error = UnivapayListDispatchError::pendingSpecExtension(
            'search',
            'listAllTransactionTokens',
            'example-extension'
        );

        $this->assertStringContainsString('search', $error->getMessage());
        $this->assertStringContainsString('listAllTransactionTokens', $error->getMessage());
        $this->assertStringContainsString('example-extension', $error->getMessage());
    }

    public function testPendingSpecExtensionEndpointMentionsTheEndpointAndSpecTask()
    {
        $error = UnivapayListDispatchError::pendingSpecExtensionEndpoint('listTransactions', 'example-extension');

        $this->assertStringContainsString('listTransactions', $error->getMessage());
        $this->assertStringContainsString('example-extension', $error->getMessage());
    }
}
