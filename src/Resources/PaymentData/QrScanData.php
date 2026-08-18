<?php

namespace Univapay\Compat\Resources\PaymentData;

use Univapay\Compat\Enums\QrBrand;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\PaymentData\QrScanData`.
 * Response-side hydration only. Single-property constructor, trivially schema-order-safe.
 */
class QrScanData
{
    use Jsonable;

    public $brand;

    public function __construct(QrBrand $brand)
    {
        $this->brand = $brand;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('brand', true, FormatterUtils::getTypedEnum(QrBrand::class));
    }
}
