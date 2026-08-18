<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Resources\Configuration\Configuration;
use Univapay\Compat\Resources\Merchant;

/**
 * Covers `Merchant`: hydration (incl. nested `Configuration`, required unlike
 * `Store`'s optional one) and the permanent `fetchCall()`/`updateCall()` throws (no per-id
 * merchant GET/PATCH endpoint exists in either SDK -- `UnivapayClient::getMe()` is the only way a
 * `Merchant` is ever obtained).
 */
class MerchantTest extends TestCase
{
    private function merchantJson(array $overrides = []): array
    {
        return array_replace([
            'id' => 'merchant-1',
            'verification_data_id' => 'vdid-1',
            'name' => 'My Merchant',
            'email' => 'merchant@example.com',
            'verified' => true,
            'configuration' => [
                'percent_fee' => 3.5,
                'flat_fees' => [],
                'logo_url' => null,
                'country' => 'JP',
                'language' => 'en',
                'display_time_zone' => 'Asia/Tokyo',
                'min_transfer_payout' => 1000,
                'maximum_charge_amounts' => [],
                'transfer_schedule' => null,
                'user_transactions_configuration' => ['enabled' => true, 'notify_customer' => false],
                'card_configuration' => [
                    'enabled' => true, 'debit_enabled' => true, 'prepaid_enabled' => false,
                    'only_direct_currency' => false, 'forbidden_card_brands' => [],
                    'allowed_countries_by_ip' => null, 'foreign_cards_allowed' => true,
                    'fail_on_new_email' => false, 'card_limit' => null, 'allow_empty_cvv' => false,
                ],
                'qr_scan_configuration' => ['enabled' => true, 'forbidden_qr_scan_gateway' => []],
                'convenience_configuration' => ['enabled' => true],
                'paidy_configuration' => ['enabled' => false],
                'recurring_token_configuration' => [
                    'recurring_type' => 'bounded', 'charge_wait_period' => 'P1D',
                    'card_charge_cvv_confirmation' => ['enabled' => true, 'threshold' => 5000],
                ],
                'security_configuration' => [
                    'inspect_suspicious_login_after' => 3, 'refund_percent_limit' => 100,
                    'limit_charge_by_card_configuration' => null, 'confirmation_required' => false,
                ],
                'installments_configuration' => [
                    'enabled' => true, 'min_charge_amount' => 1000, 'max_payout_period' => 12,
                ],
                'card_brand_percent_fees' => [
                    'visa' => 3.5, 'american_express' => 4.0, 'mastercard' => 3.5, 'maestro' => 3.5,
                    'discover' => 3.5, 'jcb' => 3.5, 'diners_club' => 3.5, 'union_pay' => 3.5,
                ],
            ],
            'created_on' => '2020-01-01T00:00:00.000000Z',
        ], $overrides);
    }

    public function testHydratesAMerchantWithItsConfigurationTree()
    {
        $merchant = Merchant::getSchema()->parse($this->merchantJson());

        $this->assertSame('merchant-1', $merchant->id);
        $this->assertSame('vdid-1', $merchant->verificationDataId);
        $this->assertSame('My Merchant', $merchant->name);
        $this->assertSame('merchant@example.com', $merchant->email);
        $this->assertTrue($merchant->verified);
        $this->assertInstanceOf(Configuration::class, $merchant->configuration);
        $this->assertSame('JP', $merchant->configuration->country);
    }

    public function testFetchThrowsUnsupportedNoPerIdMerchantEndpointExists()
    {
        $merchant = Merchant::getSchema()->parse($this->merchantJson());

        $this->expectException(UnivapayUnsupportedFeatureError::class);

        $merchant->fetch();
    }

    public function testUpdateThrowsUnsupportedNoMerchantUpdateEndpointExists()
    {
        $merchant = Merchant::getSchema()->parse($this->merchantJson());

        $this->expectException(UnivapayUnsupportedFeatureError::class);

        $merchant->update(['name' => 'New Name']);
    }
}
