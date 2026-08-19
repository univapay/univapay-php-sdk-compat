<?php

namespace Univapay\Compat\Resources\PaymentData;

use UnivaPay\Models\TokenResponseQrScanData;
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

    /**
     * Called directly by `Resources\TransactionToken::hydrateFromTyped()` -- see `CardData::
     * hydrateFromTyped()`'s doc for the general shape. Declines when `brand` (required=true) is
     * absent from the typed model.
     *
     * @param mixed $typed
     * @param array $dataBody Unused -- this class's only field has a typed counterpart.
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $dataBody)
    {
        if (!($typed instanceof TokenResponseQrScanData) || $typed->getBrand() === null) {
            return null;
        }
        return new self(QrBrand::fromValue($typed->getBrand()));
    }
}
