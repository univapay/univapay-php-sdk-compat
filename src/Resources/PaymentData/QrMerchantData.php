<?php

namespace Univapay\Compat\Resources\PaymentData;

use JsonSerializable;
use UnivaPay\Models\TokenResponseQrMerchantData;
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

    /**
     * Called directly by `Resources\TransactionToken::hydrateFromTyped()` -- see `CardData::
     * hydrateFromTyped()`'s doc for the general shape. Declines when `brand` (required=true) is
     * absent from the typed model. `qr_image_url` is not read here -- a verbatim old-SDK gap (see
     * `Resources\Charge::qrMerchantToken()`'s own doc), unaffected by typed-first hydration.
     *
     * @param mixed $typed
     * @param array $dataBody Unused -- this class's only field has a typed counterpart.
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $dataBody)
    {
        if (!($typed instanceof TokenResponseQrMerchantData) || $typed->getBrand() === null) {
            return null;
        }
        return new self(QrBrandMerchant::fromValue($typed->getBrand()));
    }
}
