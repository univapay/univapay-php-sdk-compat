<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Differential;

use PHPUnit\Framework\TestCase;
use UnivaPay\Models\MerchantWebhookConfiguration;
use Univapay\Compat\Resources\Configuration\Configuration;
use Univapay\Compat\Support\FallbackRegistry;
use Univapay\Compat\Tests\Support\DifferentialHydration;

/**
 * Differential hydration harness (see tests/Support/DifferentialHydration.php) for the
 * `Configuration` tree -- the nested `Configuration\*` audit `docs/ARCHITECTURE.md` records.
 * Fixture reused from `tests/Unit/Resources/Configuration/ConfigurationTest.php::
 * testHydratesTheFullNestedConfigurationTree()`.
 */
class ConfigurationDifferentialTest extends TestCase
{
    use DifferentialHydration;

    private function fullConfigurationJson(): array
    {
        return [
            'percent_fee' => 3.5,
            'flat_fees' => [],
            'logo_url' => 'https://example.com/logo.png',
            'country' => 'JP',
            'language' => 'en',
            'display_time_zone' => 'Asia/Tokyo',
            'min_transfer_payout' => ['amount' => 1000, 'currency' => 'JPY'],
            'maximum_charge_amounts' => [],
            'transfer_schedule' => [
                'wait_period' => 3,
                'period' => 'monthly',
                'day_of_week' => null,
                'week_of_month' => null,
                'day_of_month' => 1,
            ],
            'user_transactions_configuration' => ['enabled' => true, 'notify_customer' => false],
            'card_configuration' => [
                'enabled' => true,
                'debit_enabled' => true,
                'prepaid_enabled' => false,
                'only_direct_currency' => false,
                'forbidden_card_brands' => [],
                'allowed_countries_by_ip' => null,
                'foreign_cards_allowed' => true,
                'fail_on_new_email' => false,
                'card_limit' => null,
                'allow_empty_cvv' => false,
            ],
            'qr_scan_configuration' => ['enabled' => true, 'forbidden_qr_scan_gateway' => []],
            'convenience_configuration' => ['enabled' => true],
            'paidy_configuration' => ['enabled' => false],
            'recurring_token_configuration' => [
                'recurring_type' => 'bounded',
                'charge_wait_period' => 'P1D',
                'card_charge_cvv_confirmation' => [
                    'enabled' => true,
                    'threshold' => [['amount' => 5000, 'currency' => 'JPY']],
                ],
            ],
            'security_configuration' => [
                'inspect_suspicious_login_after' => 3,
                'refund_percent_limit' => 100,
                'limit_charge_by_card_configuration' => [
                    'quantity_of_charges' => 5,
                    'duration_window' => 'P1D',
                ],
                'confirmation_required' => false,
            ],
            'installments_configuration' => [
                'enabled' => true,
                'min_charge_amount' => ['amount' => 1000, 'currency' => 'JPY'],
                'max_payout_period' => 12,
            ],
            'card_brand_percent_fees' => [
                'visa' => 3.5,
                'american_express' => 4.0,
                'mastercard' => 3.5,
                'maestro' => 3.5,
                'discover' => 3.5,
                'jcb' => 3.5,
                'diners_club' => 3.5,
                'union_pay' => 3.5,
            ],
        ];
    }

    public function testFullConfigurationTreeMatches(): void
    {
        $json = $this->fullConfigurationJson();
        $this->assertTypedMatchesRaw(Configuration::class, MerchantWebhookConfiguration::class, $json);
    }

    public function testConfigurationWithNullLimitChargeByCardMatches(): void
    {
        $json = $this->fullConfigurationJson();
        $json['security_configuration']['limit_charge_by_card_configuration'] = null;
        $this->assertTypedMatchesRaw(Configuration::class, MerchantWebhookConfiguration::class, $json);
    }

    public function testConfigurationWithoutTransferScheduleMatches(): void
    {
        // transfer_schedule is the one optional nested config (required=false).
        $json = $this->fullConfigurationJson();
        unset($json['transfer_schedule']);
        $this->assertTypedMatchesRaw(Configuration::class, MerchantWebhookConfiguration::class, $json);
    }

    /**
     * Pre-existing wire-key bug (see QrScanConfiguration's own doc): the raw path has always read
     * `forbidden_qr_scan_gateway` (singular), but the real spec's field is
     * `forbidden_qr_scan_gateways` (plural) -- so this has always been null in practice regardless
     * of what the (differently-keyed) real value is. Proves typed-first hydration preserves that,
     * rather than silently starting to return real data via the typed model's correctly-keyed
     * getter.
     */
    public function testQrScanForbiddenGatewaysWireKeyMismatchIsPreservedAsNullOnBothPaths(): void
    {
        $json = $this->fullConfigurationJson();
        $json['qr_scan_configuration'] = [
            'enabled' => true,
            'forbidden_qr_scan_gateways' => ['some_gateway'], // the REAL (plural) wire key
        ];

        $this->assertTypedMatchesRaw(Configuration::class, MerchantWebhookConfiguration::class, $json);

        $context = $this->differentialContext();
        $rawObject = Configuration::getSchema()->parse($json, [$context]);
        $this->assertNull($rawObject->qrScanConfiguration->forbiddenQrScanGateway);
    }

    // --- fallback regression: a required nested config genuinely missing ------------------------

    public function testMissingRequiredCardConfigurationDeclinesAndFallsBackToTheSameRawException(): void
    {
        FallbackRegistry::reset();
        $json = $this->fullConfigurationJson();
        unset($json['card_configuration']);
        $context = $this->differentialContext();

        $wireJson = (string) json_encode($json);
        $rawDecoded = json_decode($wireJson, true);
        $typed = \UnivaPay\ApiHelper::getJsonHelper()->mapClass(
            json_decode($wireJson),
            MerchantWebhookConfiguration::class
        );
        $result = new \Univapay\Compat\Support\TypedResult($rawDecoded, $typed, false);

        $threw = null;
        try {
            \Univapay\Compat\Support\TypedHydrator::resolve(Configuration::class, $result, $context);
        } catch (\Univapay\Compat\Utility\Json\NoSuchPathException $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw);
        $this->assertSame(FallbackRegistry::REASON_HYDRATION_DECLINED, FallbackRegistry::occurrences()[0]['reason']);
    }
}
