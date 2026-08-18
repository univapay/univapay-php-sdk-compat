<?php

namespace Univapay\Compat\Resources\PaymentData;

use JsonSerializable;
use Univapay\Compat\Enums\QrBrandMerchant;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\PaymentData\QrMerchantData`.
 * Response-side hydration only. Single-property constructor, trivially schema-order-safe.
 */
class QrMerchantData
{
    use Jsonable;

    public $brand;

    public function __construct(QrBrandMerchant $brand)
    {
        $this->brand = $brand;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('brand', true, FormatterUtils::getTypedEnum(QrBrandMerchant::class));
    }
}
