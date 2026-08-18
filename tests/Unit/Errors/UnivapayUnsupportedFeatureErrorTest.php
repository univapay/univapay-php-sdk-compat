<?php

namespace Univapay\Compat\Tests\Unit\Errors;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Errors\UnivapaySDKError;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;

class UnivapayUnsupportedFeatureErrorTest extends TestCase
{
    public function testExtendsUnivapaySdkErrorAndMentionsFeatureAndGuideUrl()
    {
        $error = new UnivapayUnsupportedFeatureError('Transfer ledgers');

        $this->assertInstanceOf(UnivapaySDKError::class, $error);
        $this->assertStringContainsString('Transfer ledgers', $error->getMessage());
        $this->assertStringContainsString(UnivapayUnsupportedFeatureError::GUIDE_URL, $error->getMessage());
    }
}
