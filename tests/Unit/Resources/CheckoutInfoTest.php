<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\RecurringTokenPrivilege;
use Univapay\Compat\Resources\CheckoutInfo;
use Univapay\Compat\Resources\Configuration\SupportedBrand;
use Univapay\Compat\Resources\Configuration\ThemeConfiguration;

/**
 * Covers `CheckoutInfo`: hydration-only (no `Resource` inheritance, matching upstream
 * exactly -- see class doc). `UnivapayClient::getCheckoutInfo()`'s own dispatch is
 * covered in `UnivapayClientTest`; this class's shape/parser are independently testable here.
 */
class CheckoutInfoTest extends TestCase
{
    private function checkoutInfoJson(array $overrides = []): array
    {
        return array_replace([
            'mode' => 'test',
            'recurring_token_privilege' => 'bounded',
            'name' => 'My Store',
            'subscription_configuration' => ['enabled' => true],
            'card_configuration' => [
                'enabled' => true, 'debit_enabled' => true, 'prepaid_enabled' => false,
                'only_direct_currency' => false, 'forbidden_card_brands' => [],
                'allowed_countries_by_ip' => null, 'foreign_cards_allowed' => true,
                'fail_on_new_email' => false, 'card_limit' => null, 'allow_empty_cvv' => false,
            ],
            'qr_scan_configuration' => ['enabled' => true, 'forbidden_qr_scan_gateway' => []],
            'convenience_configuration' => ['enabled' => true],
            'online_configuration' => ['enabled' => true],
            'paidy_configuration' => ['enabled' => false],
            'paidy_public_key' => null,
            'supported_brands' => [
                [
                    'support_auth_capture' => true,
                    'requires_full_name' => false,
                    'requires_cvv' => true,
                    'supported_currencies' => ['JPY'],
                    'card_brand' => 'visa',
                ],
            ],
            'logo_image' => 'data:image/png;base64,abc',
            'theme' => [
                'colors' => [
                    'main_background' => '#fff', 'secondary_background' => '#eee',
                    'main_color' => '#000', 'main_text' => '#111', 'primary_text' => '#222',
                    'secondary_text' => '#333', 'base_text' => '#444',
                ],
            ],
        ], $overrides);
    }

    public function testHydratesACheckoutInfoWithItsFullConfigurationTree()
    {
        $checkoutInfo = CheckoutInfo::getSchema()->parse($this->checkoutInfoJson());

        $this->assertEquals(AppTokenMode::TEST(), $checkoutInfo->mode);
        $this->assertEquals(RecurringTokenPrivilege::BOUNDED(), $checkoutInfo->recurringTokenPrivilege);
        $this->assertSame('My Store', $checkoutInfo->name);
        $this->assertTrue($checkoutInfo->subscriptionConfiguration->enabled);
        $this->assertTrue($checkoutInfo->cardConfiguration->debitEnabled);
        $this->assertTrue($checkoutInfo->qrScanConfiguration->enabled);
        $this->assertTrue($checkoutInfo->convenienceConfiguration->enabled);
        $this->assertTrue($checkoutInfo->onlineConfiguration->enabled);
        $this->assertFalse($checkoutInfo->paidyConfiguration->enabled);
        $this->assertNull($checkoutInfo->paidyPublicKey);
        $this->assertCount(1, $checkoutInfo->supportedBrands);
        $this->assertInstanceOf(SupportedBrand::class, $checkoutInfo->supportedBrands[0]);
        $this->assertSame('data:image/png;base64,abc', $checkoutInfo->logoImage);
        $this->assertInstanceOf(ThemeConfiguration::class, $checkoutInfo->theme);
        $this->assertSame('#fff', $checkoutInfo->theme->colors->mainBackground);
    }
}
