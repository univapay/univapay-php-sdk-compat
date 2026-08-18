<?php

namespace Univapay\Compat\Resources\PaymentToken;

use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\PaymentToken\QrMerchantToken`.
 * Response-side hydration only (returned by old `Charge::qrMerchantToken()`, permanently
 * unsupported -- the charge-level `/qr` endpoint is deprecated upstream and will not be added to
 * the spec; see `Errors\UnivapayUnsupportedFeatureError` on `Charge`). This class itself remains a
 * real, working data model -- e.g. still needed to hydrate webhook payloads referencing a QR
 * merchant token -- only the HTTP-touching `qrMerchantToken()` method is unsupported.
 */
class QrMerchantToken
{
    use Jsonable;

    public $ready;
    public $qrImageUrl;

    /**
     * @param mixed $ready
     * @param mixed $qrImageUrl
     */
    public function __construct(
        $ready,
        $qrImageUrl = null
    ) {
        $this->ready = $ready;
        $this->qrImageUrl = $qrImageUrl;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }
}
