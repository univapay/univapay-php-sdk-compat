<?php

namespace Univapay\Compat\Resources\PaymentMethod;

use JsonSerializable;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Enums\UsageLimit;
use Univapay\Compat\Utility\FunctionalUtils;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\PaymentMethod\PaymentMethod`
 * abstract base. Request-side only (no `Jsonable`/`JsonSchema::fromClass()` use anywhere in this
 * class hierarchy -- these are never hydrated from a response, only serialized when creating a
 * token), so the property-order-matters-for-reflection concern does not apply here; `jsonSerialize()`
 * is the only wire-truth surface and is preserved exactly, including the two private fields
 * (`email`, `ipAddress`) that stay off the public property list, matching upstream.
 */
abstract class PaymentMethod implements JsonSerializable
{
    private $email;
    private $ipAddress;
    public $paymentType;
    public $type;
    public $usageLimit;
    public $metadata;

    protected function __construct(
        PaymentType $paymentType,
        ?TokenType $type = null,
        $email = null,
        $ipAddress = null,
        ?UsageLimit $usageLimit = null,
        ?array $metadata = null
    ) {
        $this->acceptsTokenType($type);

        $this->email = $email;
        $this->ipAddress = $ipAddress;
        $this->paymentType = $paymentType;
        $this->type = $type;
        $this->usageLimit = $usageLimit;
        $this->metadata = $metadata;
    }

    // Returns void if this payment method accepts the token type
    // Throws UnivapayValidationError if not valid
    abstract protected function acceptsTokenType(?TokenType $type = null);

    public function jsonSerialize(): array
    {
        return FunctionalUtils::stripNulls([
            'email' => $this->email,
            'ip_address' => $this->ipAddress,
            'payment_type' => $this->paymentType->getValue(),
            'type' => isset($this->type) ? $this->type->getValue() : null,
            'usage_limit' => isset($this->usageLimit) ? $this->usageLimit->getValue() : null,
            'metadata' => $this->metadata
        ]);
    }
}
