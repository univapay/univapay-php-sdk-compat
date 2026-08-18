<?php

namespace Univapay\Compat\Resources\PaymentData;

use Univapay\Compat\Enums\CardBrand;
use Univapay\Compat\Enums\CardCategory;
use Univapay\Compat\Enums\CardSubBrand;
use Univapay\Compat\Enums\CardType;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\PaymentData\Card`. Response-side
 * hydration only (no `jsonSerialize()` upstream either -- this is never sent on the wire, only
 * parsed from `CardData`'s nested `card` field). Property order (cardholder..subBrand) already
 * matches the constructor.
 */
class Card
{
    use Jsonable;

    public $cardholder;
    public $expMonth;
    public $expYear;
    public $lastFour;
    public $brand;
    public $country;
    public $cardType;
    public $category;
    public $issuer;
    public $subBrand;

    public function __construct(
        $cardholder,
        $expMonth,
        $expYear,
        $lastFour,
        $brand,
        $country,
        $cardType,
        $category,
        $issuer,
        $subBrand
    ) {
        $this->cardholder = $cardholder;
        $this->expMonth = $expMonth;
        $this->expYear = $expYear;
        $this->lastFour = $lastFour;
        $this->brand = CardBrand::fromValue($brand);
        $this->country = $country;
        $this->cardType = CardType::fromValue($cardType);
        $this->category = CardCategory::fromValue($category);
        $this->issuer = $issuer;
        $this->subBrand = CardSubBrand::fromValue($subBrand);
    }

    public static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }
}
