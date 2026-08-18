<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources\Configuration;

use Money\Currency;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Univapay\Compat\Enums\CardBrand;
use Univapay\Compat\Resources\Configuration\CardBrandPercentFees;
use Univapay\Compat\Resources\Configuration\CardChargeCvvConfirmation;
use Univapay\Compat\Resources\Configuration\CardConfiguration;
use Univapay\Compat\Resources\Configuration\ColorsConfiguration;
use Univapay\Compat\Resources\Configuration\Configuration;
use Univapay\Compat\Resources\Configuration\ConvenienceConfiguration;
use Univapay\Compat\Resources\Configuration\InstallmentsConfiguration;
use Univapay\Compat\Resources\Configuration\LimitChargeByCardConfiguration;
use Univapay\Compat\Resources\Configuration\OnlineConfiguration;
use Univapay\Compat\Resources\Configuration\PaidyConfiguration;
use Univapay\Compat\Resources\Configuration\QrScanConfiguration;
use Univapay\Compat\Resources\Configuration\RecurringConfiguration;
use Univapay\Compat\Resources\Configuration\SecurityConfiguration;
use Univapay\Compat\Resources\Configuration\SubscriptionConfiguration;
use Univapay\Compat\Resources\Configuration\SupportedBrand;
use Univapay\Compat\Resources\Configuration\ThemeConfiguration;
use Univapay\Compat\Resources\Configuration\TransferSchedule;
use Univapay\Compat\Resources\Configuration\UserTransactionsConfiguration;

/**
 * Covers the 18-class `Resources\Configuration` tree.
 *
 * - `testConstructorParameterOrderMatchesDeclaredPublicPropertyOrder()` is a single mechanical
 *   check standing in for "property order check each" across all 18 classes: `Utility\Json\
 *   JsonSchema::fromClass()` derives its component list from `Utility\FunctionalUtils::
 *   getClassVarsAssoc()`'s reflection-based property order, which the constructor's POSITIONAL
 *   argument list must match exactly (see `Charge`'s class doc for why this matters generally in
 *   this repo) -- a per-class reflection assertion catches a reordering typo far more reliably
 *   than 18 separate hand-written hydration tests would.
 * - `testHydratesTheFullNestedConfigurationTree()` hydrates one realistic `Configuration` payload
 *   (as embedded in a `Store`/`Merchant` response) end-to-end through every nested class, pinning
 *   the whole tree's wiring in one fixture.
 */
class ConfigurationTest extends TestCase
{
    private const CLASSES = [
        CardBrandPercentFees::class,
        CardChargeCvvConfirmation::class,
        CardConfiguration::class,
        ColorsConfiguration::class,
        Configuration::class,
        ConvenienceConfiguration::class,
        InstallmentsConfiguration::class,
        LimitChargeByCardConfiguration::class,
        OnlineConfiguration::class,
        PaidyConfiguration::class,
        QrScanConfiguration::class,
        RecurringConfiguration::class,
        SecurityConfiguration::class,
        SubscriptionConfiguration::class,
        SupportedBrand::class,
        ThemeConfiguration::class,
        TransferSchedule::class,
        UserTransactionsConfiguration::class,
    ];

    public function testConstructorParameterOrderMatchesDeclaredPublicPropertyOrder()
    {
        foreach (self::CLASSES as $class) {
            $reflection = new ReflectionClass($class);
            $publicProps = array_map(
                function ($p) {
                    return $p->getName();
                },
                $reflection->getProperties(\ReflectionProperty::IS_PUBLIC)
            );
            $ctorParams = array_map(
                function ($p) {
                    return $p->getName();
                },
                $reflection->getConstructor()->getParameters()
            );

            $this->assertSame(
                $publicProps,
                $ctorParams,
                "$class: constructor parameter order must match declared public property order "
                . '(JsonSchema::fromClass() derives its component order from property reflection)'
            );
        }
    }

    public function testHydratesTheFullNestedConfigurationTree()
    {
        $json = [
            'percent_fee' => 3.5,
            'flat_fees' => [],
            'logo_url' => 'https://example.com/logo.png',
            'country' => 'JP',
            'language' => 'en',
            'display_time_zone' => 'Asia/Tokyo',
            'min_transfer_payout' => 1000,
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
                'card_charge_cvv_confirmation' => ['enabled' => true, 'threshold' => 5000],
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
                'min_charge_amount' => 1000,
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

        $configuration = Configuration::getSchema()->parse($json);

        $this->assertSame('https://example.com/logo.png', $configuration->logoUrl);
        $this->assertInstanceOf(TransferSchedule::class, $configuration->transferSchedule);
        $this->assertSame(3, $configuration->transferSchedule->waitPeriod);
        $this->assertInstanceOf(
            UserTransactionsConfiguration::class,
            $configuration->userTransactionsConfiguration
        );
        $this->assertTrue($configuration->userTransactionsConfiguration->enabled);
        $this->assertInstanceOf(CardConfiguration::class, $configuration->cardConfiguration);
        $this->assertTrue($configuration->cardConfiguration->debitEnabled);
        $this->assertInstanceOf(QrScanConfiguration::class, $configuration->qrScanConfiguration);
        $this->assertInstanceOf(ConvenienceConfiguration::class, $configuration->convenienceConfiguration);
        $this->assertInstanceOf(PaidyConfiguration::class, $configuration->paidyConfiguration);
        $this->assertInstanceOf(RecurringConfiguration::class, $configuration->recurringTokenConfiguration);
        $this->assertInstanceOf(
            CardChargeCvvConfirmation::class,
            $configuration->recurringTokenConfiguration->cardChargeCvvConfirmation
        );
        $this->assertSame(5000, $configuration->recurringTokenConfiguration->cardChargeCvvConfirmation->threshold);
        $this->assertInstanceOf(SecurityConfiguration::class, $configuration->securityConfiguration);
        $this->assertInstanceOf(
            LimitChargeByCardConfiguration::class,
            $configuration->securityConfiguration->limitChargeByCardConfiguration
        );
        $this->assertSame(5, $configuration->securityConfiguration->limitChargeByCardConfiguration->quantityOfCharges);
        $this->assertInstanceOf(InstallmentsConfiguration::class, $configuration->installmentsConfiguration);
        $this->assertInstanceOf(CardBrandPercentFees::class, $configuration->cardBrandPercentFees);
        $this->assertEquals(4.0, $configuration->cardBrandPercentFees->americanExpress);
    }

    public function testThemeConfigurationHydratesNestedColors()
    {
        $theme = ThemeConfiguration::getSchema()->parse([
            'colors' => [
                'main_background' => '#ffffff',
                'secondary_background' => '#eeeeee',
                'main_color' => '#000000',
                'main_text' => '#111111',
                'primary_text' => '#222222',
                'secondary_text' => '#333333',
                'base_text' => '#444444',
            ],
        ]);

        $this->assertInstanceOf(ColorsConfiguration::class, $theme->colors);
        $this->assertSame('#ffffff', $theme->colors->mainBackground);
        $this->assertSame('#444444', $theme->colors->baseText);
    }

    public function testSupportedBrandHydratesCurrenciesAndCardBrandEnum()
    {
        $brand = SupportedBrand::getSchema()->parse([
            'support_auth_capture' => true,
            'requires_full_name' => false,
            'requires_cvv' => true,
            'supported_currencies' => ['JPY', 'USD'],
            'countries_allowed' => ['JP', 'US'],
            'card_brand' => 'visa',
        ]);

        $this->assertTrue($brand->supportAuthCapture);
        $this->assertEquals([new Currency('JPY'), new Currency('USD')], $brand->supportedCurrencies);
        $this->assertEquals(CardBrand::VISA(), $brand->cardBrand);
        $this->assertNull($brand->onlineBrand);
    }
}
