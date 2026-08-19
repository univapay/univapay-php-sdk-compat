<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use Money\Currency;
use UnivaPay\Models\CheckoutSupportedBrand;
use Univapay\Compat\Enums\CardBrand;
use Univapay\Compat\Enums\OnlineBrand;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\SupportedBrand`. The old file's unused `Utility\FunctionalUtils as fp`
 * import is dropped (dead in the upstream class body, same rationale as `CardConfiguration`).
 *
 * `supportedCurrencies` is nullable: hydrating `CheckoutInfo` (or `Merchant`/`Store`
 * configuration, which nests this same class) against a payload with
 * `supported_currencies: null` yields `null`, not a `TypeError`. Same category as `CardData`'s
 * billing/threeDS nullability and `PaidyData`'s phone_number handling (see those classes' docs).
 */
class SupportedBrand
{
    use Jsonable;

    public $supportAuthCapture;
    public $requiresFullName;
    public $requiresCvv;
    public $supportedCurrencies;
    public $countriesAllowed;
    public $cardBrand;
    public $onlineBrand;

    public function __construct(
        $supportAuthCapture,
        $requiresFullName,
        $requiresCvv,
        ?array $supportedCurrencies = null,
        ?array $countriesAllowed = null,
        ?CardBrand $cardBrand = null,
        ?OnlineBrand $onlineBrand = null
    ) {
        $this->supportAuthCapture = $supportAuthCapture;
        $this->requiresFullName = $requiresFullName;
        $this->requiresCvv = $requiresCvv;
        $this->supportedCurrencies = $supportedCurrencies;
        $this->countriesAllowed = $countriesAllowed;
        $this->cardBrand = $cardBrand;
        $this->onlineBrand = $onlineBrand;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('supported_currencies', false, FormatterUtils::getListOf(FormatterUtils::of('getCurrency')))
            ->upsert('countries_allowed', false, FormatterUtils::getListOf())
            ->upsert('card_brand', false, FormatterUtils::getTypedEnum(CardBrand::class))
            ->upsert('online_brand', false, FormatterUtils::getTypedEnum(OnlineBrand::class));
    }

    /**
     * Called directly by `Resources\CheckoutInfo::hydrateFromTyped()`. Clean 1:1 match against
     * the generated `UnivaPay\Models\CheckoutSupportedBrand` -- `supported_currencies` is mapped
     * through `new Currency()` per entry and `card_brand`/`online_brand` through their TypedEnum
     * `fromValue()`, mirroring `initSchema()`'s own formatters exactly.
     *
     * @param mixed $typed
     * @return self|null
     */
    public static function hydrateFromTyped($typed)
    {
        if (!($typed instanceof CheckoutSupportedBrand)) {
            return null;
        }
        $supportedCurrencies = $typed->getSupportedCurrencies();
        return new self(
            $typed->getSupportAuthCapture(),
            $typed->getRequiresFullName(),
            $typed->getRequiresCvv(),
            $supportedCurrencies !== null ? array_map(function ($code) {
                return new Currency($code);
            }, $supportedCurrencies) : null,
            $typed->getCountriesAllowed(),
            CardBrand::fromValue($typed->getCardBrand()),
            OnlineBrand::fromValue($typed->getOnlineBrand())
        );
    }
}
