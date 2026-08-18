<?php

namespace Univapay\Compat\Resources\PaymentData;

use UnivaPay\Models\TokenResponseOnlineData;
use Univapay\Compat\Enums\CallMethod;
use Univapay\Compat\Enums\OnlineBrand;
use Univapay\Compat\Enums\OsType;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\PaymentData\OnlineData`.
 * Response-side hydration only (no `jsonSerialize()` upstream -- the wire truth for creating an
 * online token lives in `PaymentMethod\OnlinePayment::jsonSerialize()`). Property order
 * (brand, callMethod, userIdentifier, osType, issuerToken) already matches the constructor.
 */
class OnlineData
{
    use Jsonable;

    public $brand;
    public $callMethod;
    public $userIdentifier;
    public $osType;
    public $issuerToken;

    public function __construct(
        OnlineBrand $brand,
        ?CallMethod $callMethod = null,
        $userIdentifier = null,
        ?OsType $osType = null,
        $issuerToken = null
    ) {
        $this->brand = $brand;
        $this->callMethod = $callMethod;
        $this->userIdentifier = $userIdentifier;
        $this->osType = $osType;
        $this->issuerToken = $issuerToken;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('brand', true, FormatterUtils::getTypedEnum(OnlineBrand::class))
            ->upsert('call_method', true, FormatterUtils::getTypedEnum(CallMethod::class))
            ->upsert('os_type', false, FormatterUtils::getTypedEnum(OsType::class));
    }

    /**
     * Called directly by `Resources\TransactionToken::hydrateFromTyped()` -- see `CardData::
     * hydrateFromTyped()`'s doc for the general shape. Declines when `brand`/`call_method` (both
     * required=true) are absent from the typed model.
     *
     * @param mixed $typed
     * @param array $dataBody Unused -- every field this class reads has a typed counterpart.
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $dataBody)
    {
        if (!($typed instanceof TokenResponseOnlineData)) {
            return null;
        }
        if ($typed->getBrand() === null || $typed->getCallMethod() === null) {
            return null;
        }
        return new self(
            OnlineBrand::fromValue($typed->getBrand()),
            CallMethod::fromValue($typed->getCallMethod()),
            $typed->getUserIdentifier(),
            OsType::fromValue($typed->getOsType()),
            $typed->getIssuerToken()
        );
    }
}
