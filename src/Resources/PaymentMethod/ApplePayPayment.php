<?php

namespace Univapay\Compat\Resources\PaymentMethod;

use JsonSerializable;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Enums\UsageLimit;
use Univapay\Compat\Resources\PaymentData\Address;
use Univapay\Compat\Resources\PaymentData\PhoneNumber;
use Univapay\Compat\Utility\FunctionalUtils;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\PaymentMethod\ApplePayPayment`.
 * The class itself (and its `jsonSerialize()`) is preserved and constructible, but
 * `Support\RequestModelFactory::tokenCreate()` always throws `UnivapayUnsupportedFeatureError` for
 * it -- Apple Pay token creation has no equivalent in the new transport engine.
 */
class ApplePayPayment extends PaymentMethod implements JsonSerializable
{
    private $cardholder;
    private $applePayToken;
    private $address;
    private $phoneNumber;

    public function __construct(
        $email,
        $cardholder,
        $applePayToken,
        ?TokenType $type = null,
        ?UsageLimit $usageLimit = null,
        ?Address $address = null,
        ?PhoneNumber $phoneNumber = null,
        ?array $metadata = null,
        $ipAddress = null
    ) {
        parent::__construct(PaymentType::APPLE_PAY(), $type, $email, $ipAddress, $usageLimit, $metadata);
        $this->cardholder = $cardholder;
        $this->applePayToken = $applePayToken;
        $this->address = $address;
        $this->phoneNumber = $phoneNumber;
    }

    // Accepts all types
    protected function acceptsTokenType(?TokenType $tokenType = null)
    {
    }

    public function jsonSerialize(): array
    {
        $parentData = parent::jsonSerialize();
        $parentData['data'] = [
            'applepay_token' => $this->applePayToken,
            'cardholder' => $this->cardholder
        ] + (isset($this->address)
            ? $this->address->jsonSerialize()
            : [])
        + (isset($this->phoneNumber)
            ? $this->phoneNumber->jsonSerialize()
            : []);

        return FunctionalUtils::stripNulls($parentData);
    }
}
