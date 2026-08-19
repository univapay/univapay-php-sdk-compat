<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Differential;

use PHPUnit\Framework\TestCase;
use UnivaPay\Models\CheckoutInfo as GeneratedCheckoutInfo;
use Univapay\Compat\Resources\CheckoutInfo;
use Univapay\Compat\Support\FallbackRegistry;
use Univapay\Compat\Tests\Support\DifferentialHydration;

/**
 * Differential hydration harness (see tests/Support/DifferentialHydration.php) for `CheckoutInfo`
 * and its own separate `Checkout*` generated model family (distinct from the
 * `MerchantWebhookConfiguration` family `Merchant`/`Store` share -- see docs/ARCHITECTURE.md).
 * Fixture reused from `tests/Unit/Resources/CheckoutInfoTest.php`.
 */
class CheckoutInfoDifferentialTest extends TestCase
{
    use DifferentialHydration;

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

    public function testFullCheckoutInfoMatches(): void
    {
        $this->assertTypedMatchesRaw(CheckoutInfo::class, GeneratedCheckoutInfo::class, $this->checkoutInfoJson());
    }

    /**
     * A real `card_limit` value (present on the CHECKOUT typed family as a nested object, unlike
     * the plain int the MerchantWebhookConfiguration family uses for the same compat field --
     * see CardConfiguration's own doc): proves the raw-body patch survives regardless of the
     * typed shape.
     */
    public function testCardLimitObjectShapeSurvivesViaTheRawPatch(): void
    {
        $json = $this->checkoutInfoJson();
        $json['card_configuration']['card_limit'] = ['amount' => 500000, 'currency' => 'JPY', 'duration' => 'P1M'];
        $this->assertTypedMatchesRaw(CheckoutInfo::class, GeneratedCheckoutInfo::class, $json);

        $rawObject = CheckoutInfo::getSchema()->parse($json);
        $expected = ['amount' => 500000, 'currency' => 'JPY', 'duration' => 'P1M'];
        $this->assertSame($expected, $rawObject->cardConfiguration->cardLimit);
    }

    /**
     * Same pre-existing wire-key bug as the Merchant/Store audit (see QrScanConfiguration's own
     * doc): the CHECKOUT typed family also uses the plural `forbidden_qr_scan_gateways` key, so
     * this must stay null on both paths here too.
     */
    public function testQrScanForbiddenGatewaysWireKeyMismatchIsPreservedAsNullOnBothPaths(): void
    {
        $json = $this->checkoutInfoJson();
        $json['qr_scan_configuration'] = [
            'enabled' => true,
            'forbidden_qr_scan_gateways' => ['some_gateway'],
        ];
        $this->assertTypedMatchesRaw(CheckoutInfo::class, GeneratedCheckoutInfo::class, $json);

        $rawObject = CheckoutInfo::getSchema()->parse($json);
        $this->assertNull($rawObject->qrScanConfiguration->forbiddenQrScanGateway);
    }

    public function testMultipleSupportedBrandsMatch(): void
    {
        $json = $this->checkoutInfoJson();
        $json['supported_brands'] = [
            [
                'support_auth_capture' => true,
                'requires_full_name' => false,
                'requires_cvv' => true,
                'supported_currencies' => ['JPY', 'USD'],
                'countries_allowed' => ['JP', 'US'],
                'card_brand' => 'visa',
            ],
            [
                'support_auth_capture' => false,
                'requires_full_name' => true,
                'requires_cvv' => false,
                'supported_currencies' => null,
                'online_brand' => 'we_chat_online',
            ],
        ];
        $this->assertTypedMatchesRaw(CheckoutInfo::class, GeneratedCheckoutInfo::class, $json);
    }

    // --- fallback regression: a required field genuinely missing --------------------------------

    public function testMissingRequiredModeDeclinesAndFallsBackToTheSameRawException(): void
    {
        FallbackRegistry::reset();
        $json = $this->checkoutInfoJson();
        unset($json['mode']);
        $context = $this->differentialContext();

        $wireJson = (string) json_encode($json);
        $rawDecoded = json_decode($wireJson, true);
        $typed = \UnivaPay\ApiHelper::getJsonHelper()->mapClass(json_decode($wireJson), GeneratedCheckoutInfo::class);
        $result = new \Univapay\Compat\Support\TypedResult($rawDecoded, $typed, false);

        $threw = null;
        try {
            \Univapay\Compat\Support\TypedHydrator::resolve(CheckoutInfo::class, $result, $context);
        } catch (\Univapay\Compat\Utility\Json\NoSuchPathException $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw);
        $this->assertSame(FallbackRegistry::REASON_HYDRATION_DECLINED, FallbackRegistry::occurrences()[0]['reason']);
    }

    public function testMissingRequiredCardConfigurationDeclinesAndFallsBackToTheSameRawException(): void
    {
        FallbackRegistry::reset();
        $json = $this->checkoutInfoJson();
        unset($json['card_configuration']);
        $context = $this->differentialContext();

        $wireJson = (string) json_encode($json);
        $rawDecoded = json_decode($wireJson, true);
        $typed = \UnivaPay\ApiHelper::getJsonHelper()->mapClass(json_decode($wireJson), GeneratedCheckoutInfo::class);
        $result = new \Univapay\Compat\Support\TypedResult($rawDecoded, $typed, false);

        $threw = null;
        try {
            \Univapay\Compat\Support\TypedHydrator::resolve(CheckoutInfo::class, $result, $context);
        } catch (\Univapay\Compat\Utility\Json\NoSuchPathException $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw);
        $this->assertSame(FallbackRegistry::REASON_HYDRATION_DECLINED, FallbackRegistry::occurrences()[0]['reason']);
    }
}
