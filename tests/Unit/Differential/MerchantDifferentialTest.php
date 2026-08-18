<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Differential;

use PHPUnit\Framework\TestCase;
use UnivaPay\Models\Merchant as GeneratedMerchant;
use Univapay\Compat\Resources\Merchant;
use Univapay\Compat\Support\FallbackRegistry;
use Univapay\Compat\Tests\Support\DifferentialHydration;

/**
 * Differential hydration harness (see tests/Support/DifferentialHydration.php) for `Merchant`,
 * including its required nested `Configuration` tree (see `ConfigurationDifferentialTest`, whose
 * fixture-building shapes -- spec-correct `MerchantWebhookMoneyAmount` objects instead of the bare
 * ints `tests/Unit/Resources/MerchantTest.php`'s raw-only fixture uses -- are reused here so the
 * generated jsonmapper can actually deserialize the whole tree).
 */
class MerchantDifferentialTest extends TestCase
{
    use DifferentialHydration;

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
                'min_transfer_payout' => ['amount' => 1000, 'currency' => 'JPY'],
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
                    'card_charge_cvv_confirmation' => [
                        'enabled' => true,
                        'threshold' => [['amount' => 5000, 'currency' => 'JPY']],
                    ],
                ],
                'security_configuration' => [
                    'inspect_suspicious_login_after' => 3, 'refund_percent_limit' => 100,
                    'limit_charge_by_card_configuration' => null, 'confirmation_required' => false,
                ],
                'installments_configuration' => [
                    'enabled' => true,
                    'min_charge_amount' => ['amount' => 1000, 'currency' => 'JPY'],
                    'max_payout_period' => 12,
                ],
                'card_brand_percent_fees' => [
                    'visa' => 3.5, 'american_express' => 4.0, 'mastercard' => 3.5, 'maestro' => 3.5,
                    'discover' => 3.5, 'jcb' => 3.5, 'diners_club' => 3.5, 'union_pay' => 3.5,
                ],
            ],
            'created_on' => '2020-01-01T00:00:00.000000Z',
        ], $overrides);
    }

    public function testMerchantWithConfigurationMatches(): void
    {
        $this->assertTypedMatchesRaw(Merchant::class, GeneratedMerchant::class, $this->merchantJson());
    }

    /**
     * `configuration` is required=true on `Merchant` (unlike `Store`'s optional one) -- a
     * genuinely missing one declines and falls back to the same raw exception.
     */
    public function testMissingRequiredConfigurationDeclinesAndFallsBackToTheSameRawException(): void
    {
        FallbackRegistry::reset();
        $json = $this->merchantJson();
        unset($json['configuration']);
        $context = $this->differentialContext();

        $wireJson = (string) json_encode($json);
        $rawDecoded = json_decode($wireJson, true);
        $typed = \UnivaPay\ApiHelper::getJsonHelper()->mapClass(json_decode($wireJson), GeneratedMerchant::class);
        $result = new \Univapay\Compat\Support\TypedResult($rawDecoded, $typed, false);

        $threw = null;
        try {
            \Univapay\Compat\Support\TypedHydrator::resolve(Merchant::class, $result, $context);
        } catch (\Univapay\Compat\Utility\Json\NoSuchPathException $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw);
        $this->assertSame(FallbackRegistry::REASON_HYDRATION_DECLINED, FallbackRegistry::occurrences()[0]['reason']);
    }
}
