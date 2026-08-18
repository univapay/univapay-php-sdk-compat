<?php

namespace Univapay\Compat\Resources\PaymentToken;

use Univapay\Compat\Enums\CallMethod;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace line only) of the old SDK's
 * `Resources\PaymentToken\ThreeDSIssuerToken`. Response-side hydration only, returned by
 * `TransactionToken::threeDSIssuerToken()` (`TransactionTokensApi::getTokenThreeDsIssuerToken` --
 * see that method) and `Charge::threeDSIssuerToken()`. Property order (callMethod,
 * contentType, issuerToken, payload, paymentType) already matches the constructor.
 */
class ThreeDSIssuerToken
{
    use Jsonable;

    public $callMethod;
    public $contentType;
    public $issuerToken;
    public $payload;
    public $paymentType;

    /**
     * @param mixed $contentType
     * @param mixed $issuerToken
     * @param mixed $payload
     */
    public function __construct(
        ?CallMethod $callMethod = null,
        $contentType = null,
        $issuerToken = null,
        $payload = null,
        ?PaymentType $paymentType = null
    ) {
        $this->callMethod = $callMethod;
        $this->contentType = $contentType;
        $this->issuerToken = $issuerToken;
        $this->payload = $payload;
        $this->paymentType = $paymentType;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('call_method', true, FormatterUtils::getTypedEnum(CallMethod::class))
            ->upsert('payment_type', true, FormatterUtils::getTypedEnum(PaymentType::class));
    }
}
