<?php

namespace Univapay\Compat\Resources\PaymentMethod;

use JsonSerializable;
use Univapay\Compat\Enums\CallMethod;
use Univapay\Compat\Enums\OnlineBrand;
use Univapay\Compat\Enums\OsType;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Utility\FunctionalUtils;

/**
 * Verbatim port (namespace line only) of the old SDK's
 * `Resources\PaymentMethod\OnlinePayment`. Note (see `Support\RequestModelFactory::tokenCreate()`
 * doc): unlike every other payment method here, `brand`/`call_method`/`os_type` are serialized via
 * `->getName()` (the enum's ORIGINAL call-site method name, lowercased) rather than `->getValue()`
 * -- preserved exactly, since it is genuine old-SDK wire truth, not a porting slip.
 */
class OnlinePayment extends PaymentMethod implements JsonSerializable
{
    private $brand;
    private $callMethod;
    private $userIdentifier;
    private $osType;

    public function __construct(
        $email,
        OnlineBrand $brand,
        ?array $metadata = null,
        $ipAddress = null,
        ?CallMethod $callMethod = null,
        $userIdentifier = null,
        ?OsType $osType = null
    ) {
        parent::__construct(PaymentType::ONLINE(), null, $email, $ipAddress, null, $metadata);
        $this->brand = $brand;
        $this->callMethod = $callMethod;
        $this->userIdentifier = $userIdentifier;
        $this->osType = $osType;
    }

    // Does not take in a token type
    protected function acceptsTokenType(?TokenType $tokenType = null)
    {
    }

    public function jsonSerialize(): array
    {
        $parentData = parent::jsonSerialize();
        $parentData['data'] = FunctionalUtils::stripNulls([
            'brand' => $this->brand->getName(),
            'call_method' => isset($this->callMethod)
                ? $this->callMethod->getName()
                : null,
            'user_identifier' => isset($this->userIdentifier)
                ? $this->userIdentifier
                : null,
            'os_type' => isset($this->osType)
                ? $this->osType->getName()
                : null
        ]);

        return $parentData;
    }
}
