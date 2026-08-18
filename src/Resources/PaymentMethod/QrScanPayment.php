<?php

namespace Univapay\Compat\Resources\PaymentMethod;

use JsonSerializable;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Enums\TokenType;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\PaymentMethod\QrScanPayment`.
 *
 * `Support\RequestModelFactory::tokenCreate()` routes this class through
 * `RequestModelFactory::buildQrScanData()`, which builds a real `TokenCreateQrScanData`. See
 * `tests/Integration/TokenTest.php` for a live round-trip.
 */
class QrScanPayment extends PaymentMethod implements JsonSerializable
{
    private $scannedQr;

    public function __construct(
        $email,
        $scannedQr,
        ?array $metadata = null,
        $ipAddress = null
    ) {
        parent::__construct(PaymentType::QR_SCAN(), null, $email, $ipAddress, null, $metadata);
        $this->scannedQr = $scannedQr;
    }

    // Does not take in a token type
    protected function acceptsTokenType(?TokenType $tokenType = null)
    {
    }

    public function jsonSerialize(): array
    {
        $parentData = parent::jsonSerialize();
        $parentData['data'] = ['scanned_qr' => $this->scannedQr];

        return $parentData;
    }
}
