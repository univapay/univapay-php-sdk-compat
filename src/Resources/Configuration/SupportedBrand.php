<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

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
}
