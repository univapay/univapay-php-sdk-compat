<?php

namespace Univapay\Compat\Resources\PaymentToken;

use UnivaPay\Models\ThreeDsIssuerToken as GeneratedThreeDsIssuerToken;
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

    /**
     * Typed-first hydration entry point for `Support\TypedHydrator`. `payload` is read from $body
     * (this response's raw decoded body) rather than the typed `IssuerTokenPayload` model: compat
     * has always stored it as the raw decoded value verbatim (no `Jsonable` hydration step), same
     * as `Charge`'s `error`/`metadata`. `call_method`/`payment_type` are declared as non-nullable
     * `string` on the generated model -- a genuinely missing required field throws a `TypeError`
     * calling the getter, which `Support\TypedHydrator::resolve()`'s catch already turns into a
     * raw-fallback, so no separate null-guard is needed here (unlike `Charge`/`Refund`/`Cancel`,
     * whose equivalent generated getters are nullable and would otherwise silently return null).
     *
     * @param mixed $typed
     * @param array $body
     * @param mixed $context Unused -- this class's constructor takes no context.
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body, $context)
    {
        if (!($typed instanceof GeneratedThreeDsIssuerToken)) {
            return null;
        }
        return new self(
            CallMethod::fromValue($typed->getCallMethod()),
            $typed->getContentType(),
            $typed->getIssuerToken(),
            array_key_exists('payload', $body) ? $body['payload'] : null,
            PaymentType::fromValue($typed->getPaymentType())
        );
    }
}
