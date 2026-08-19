<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources;

use UnivaPay\Models\CheckoutInfo as GeneratedCheckoutInfo;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\RecurringTokenPrivilege;
use Univapay\Compat\Resources\Configuration\CardConfiguration;
use Univapay\Compat\Resources\Configuration\ConvenienceConfiguration;
use Univapay\Compat\Resources\Configuration\OnlineConfiguration;
use Univapay\Compat\Resources\Configuration\PaidyConfiguration;
use Univapay\Compat\Resources\Configuration\QrScanConfiguration;
use Univapay\Compat\Resources\Configuration\SubscriptionConfiguration;
use Univapay\Compat\Resources\Configuration\SupportedBrand;
use Univapay\Compat\Resources\Configuration\ThemeConfiguration;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Port of the old SDK's `Resources\CheckoutInfo` (namespace lines only -- pure data class, exactly
 * as upstream: old `CheckoutInfo` never extended `Resource` at all, no `$id`/`fetch()`/`update()`).
 *
 * This class and its full `Configuration\*` nested tree hydrate the response of
 * `UnivapayClient::getCheckoutInfo()` (`GET /checkout_info`).
 *
 * Typed-first hydration: `GET /checkout_info` has its own separate generated model family
 * (`UnivaPay\Models\CheckoutInfo` + `Checkout*` nested types), distinct from the
 * `MerchantWebhookConfiguration` family `Merchant`/`Store` share. Every `Configuration\*` class
 * this tree nests already has (or, for the 5 `CheckoutInfo`-only ones, now has) a
 * `hydrateFromTyped()` recognizing the `Checkout*` typed source -- see each class's own doc.
 */
class CheckoutInfo
{
    use Jsonable;

    public $mode;
    public $recurringTokenPrivilege;
    public $name;
    public $subscriptionConfiguration;
    public $cardConfiguration;
    public $qrScanConfiguration;
    public $convenienceConfiguration;
    public $onlineConfiguration;
    public $paidyConfiguration;
    public $paidyPublicKey;
    public $supportedBrands;
    public $logoImage;
    public $theme;

    public function __construct(
        AppTokenMode $mode,
        RecurringTokenPrivilege $recurringTokenPrivilege,
        $name,
        SubscriptionConfiguration $subscriptionConfiguration,
        CardConfiguration $cardConfiguration,
        QrScanConfiguration $qrScanConfiguration,
        ConvenienceConfiguration $convenienceConfiguration,
        OnlineConfiguration $onlineConfiguration,
        PaidyConfiguration $paidyConfiguration,
        $paidyPublicKey,
        array $supportedBrands,
        $logoImage,
        ThemeConfiguration $theme
    ) {
        $this->mode = $mode;
        $this->recurringTokenPrivilege = $recurringTokenPrivilege;
        $this->name = $name;
        $this->subscriptionConfiguration = $subscriptionConfiguration;
        $this->cardConfiguration = $cardConfiguration;
        $this->qrScanConfiguration = $qrScanConfiguration;
        $this->convenienceConfiguration = $convenienceConfiguration;
        $this->onlineConfiguration = $onlineConfiguration;
        $this->paidyConfiguration = $paidyConfiguration;
        $this->paidyPublicKey = $paidyPublicKey;
        $this->supportedBrands = $supportedBrands;
        $this->logoImage = $logoImage;
        $this->theme = $theme;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('mode', true, FormatterUtils::getTypedEnum(AppTokenMode::class))
            ->upsert(
                'recurring_token_privilege',
                true,
                FormatterUtils::getTypedEnum(RecurringTokenPrivilege::class)
            )
            ->upsert('subscription_configuration', true, SubscriptionConfiguration::getSchema()->getParser())
            ->upsert('card_configuration', true, CardConfiguration::getSchema()->getParser())
            ->upsert('qr_scan_configuration', true, QrScanConfiguration::getSchema()->getParser())
            ->upsert('convenience_configuration', true, ConvenienceConfiguration::getSchema()->getParser())
            ->upsert('online_configuration', true, OnlineConfiguration::getSchema()->getParser())
            ->upsert('paidy_configuration', true, PaidyConfiguration::getSchema()->getParser())
            ->upsert('supported_brands', true, FormatterUtils::getListOf(SupportedBrand::getSchema()->getParser()))
            ->upsert('theme', true, ThemeConfiguration::getSchema()->getParser());
    }

    /**
     * Typed-first hydration entry point for `Support\TypedHydrator`. Every top-level field has a
     * clean 1:1 match against the generated `UnivaPay\Models\CheckoutInfo`; each nested
     * configuration is dispatched to its own `hydrateFromTyped()` (`CardConfiguration`/
     * `QrScanConfiguration` also need this response's raw sub-body -- see their own docs for why).
     *
     * Declines (null) when `mode`/`recurring_token_privilege` (required=true) are missing, when
     * any required nested configuration is missing or its own `hydrateFromTyped()` declines, or
     * when any `supported_brands` entry fails to hydrate.
     *
     * @param mixed $typed
     * @param array $body
     * @param mixed $context Unused -- this class's constructor takes no context.
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body, $context = null)
    {
        if (!($typed instanceof GeneratedCheckoutInfo)) {
            return null;
        }
        $mode = $typed->getMode();
        $recurringTokenPrivilege = $typed->getRecurringTokenPrivilege();
        if ($mode === null || $recurringTokenPrivilege === null) {
            return null;
        }

        $subscriptionTyped = $typed->getSubscriptionConfiguration();
        $subscriptionConfiguration = $subscriptionTyped !== null
            ? SubscriptionConfiguration::hydrateFromTyped($subscriptionTyped)
            : null;

        $cardTyped = $typed->getCardConfiguration();
        $cardBody = isset($body['card_configuration']) && is_array($body['card_configuration'])
            ? $body['card_configuration']
            : [];
        $cardConfiguration = $cardTyped !== null ? CardConfiguration::hydrateFromTyped($cardTyped, $cardBody) : null;

        $qrScanTyped = $typed->getQrScanConfiguration();
        $qrScanBody = isset($body['qr_scan_configuration']) && is_array($body['qr_scan_configuration'])
            ? $body['qr_scan_configuration']
            : [];
        $qrScanConfiguration = $qrScanTyped !== null
            ? QrScanConfiguration::hydrateFromTyped($qrScanTyped, $qrScanBody)
            : null;

        $convenienceTyped = $typed->getConvenienceConfiguration();
        $convenienceConfiguration = $convenienceTyped !== null
            ? ConvenienceConfiguration::hydrateFromTyped($convenienceTyped)
            : null;

        $onlineTyped = $typed->getOnlineConfiguration();
        $onlineConfiguration = $onlineTyped !== null ? OnlineConfiguration::hydrateFromTyped($onlineTyped) : null;

        $paidyTyped = $typed->getPaidyConfiguration();
        $paidyConfiguration = $paidyTyped !== null ? PaidyConfiguration::hydrateFromTyped($paidyTyped) : null;

        $themeTyped = $typed->getTheme();
        $theme = $themeTyped !== null ? ThemeConfiguration::hydrateFromTyped($themeTyped) : null;

        $supportedBrandsTyped = $typed->getSupportedBrands();
        $supportedBrands = null;
        if ($supportedBrandsTyped !== null) {
            $supportedBrands = [];
            foreach ($supportedBrandsTyped as $brandTyped) {
                $brand = SupportedBrand::hydrateFromTyped($brandTyped);
                if ($brand === null) {
                    return null;
                }
                $supportedBrands[] = $brand;
            }
        }

        if (
            $subscriptionConfiguration === null || $cardConfiguration === null || $qrScanConfiguration === null
            || $convenienceConfiguration === null || $onlineConfiguration === null || $paidyConfiguration === null
            || $theme === null || $supportedBrands === null
        ) {
            return null;
        }

        return new self(
            AppTokenMode::fromValue($mode),
            RecurringTokenPrivilege::fromValue($recurringTokenPrivilege),
            $typed->getName(),
            $subscriptionConfiguration,
            $cardConfiguration,
            $qrScanConfiguration,
            $convenienceConfiguration,
            $onlineConfiguration,
            $paidyConfiguration,
            $typed->getPaidyPublicKey(),
            $supportedBrands,
            $typed->getLogoImage(),
            $theme
        );
    }
}
