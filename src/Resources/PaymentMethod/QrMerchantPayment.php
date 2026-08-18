<?php

namespace Univapay\Compat\Resources\PaymentMethod;

use JsonSerializable;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Enums\QrBrandMerchant;
use Univapay\Compat\Enums\TokenType;

/**
 * Verbatim port (namespace line only) of the old SDK's
 * `Resources\PaymentMethod\QrMerchantPayment`.
 *
 * `Support\RequestModelFactory::tokenCreate()` routes this class through
 * `RequestModelFactory::buildQrMerchantData()`, which builds a real `TokenCreateQrMerchantData`
 * (see that method's own doc for a still-open coverage gap: the `TOUCH_N_GO()`/`PUBLICBANK()`
 * brand cases specifically). See `tests/Integration/TokenTest.php` for a live round-trip.
 */
class QrMerchantPayment extends PaymentMethod implements JsonSerializable
{
    private $brand;

    public function __construct(
        $email,
        QrBrandMerchant $brand,
        ?array $metadata = null,
        $ipAddress = null
    ) {
        parent::__construct(PaymentType::QR_MERCHANT(), null, $email, $ipAddress, null, $metadata);
        $this->brand = $brand;
    }

    // Does not take in a token type
    protected function acceptsTokenType(?TokenType $tokenType = null)
    {
    }

    public function jsonSerialize(): array
    {
        $parentData = parent::jsonSerialize();
        $parentData['data'] = ['brand' => $this->brand->getName()];

        return $parentData;
    }
}
