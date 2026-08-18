<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use UnivaPay\Models\MerchantWebhookQrScanConfiguration;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\QrScanConfiguration`. Nested inside BOTH `Configuration` (Merchant/
 * Store, typed-first) and `CheckoutInfo` (own `Checkout*` model family, still raw-primary) -- see
 * `ConvenienceConfiguration`'s doc for why `hydrateFromTyped()` only needs to recognize one.
 */
class QrScanConfiguration
{
    use Jsonable;

    public $enabled;
    public $forbiddenQrScanGateway;

    public function __construct($enabled, $forbiddenQrScanGateway)
    {
        $this->enabled = $enabled;
        $this->forbiddenQrScanGateway = $forbiddenQrScanGateway;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }

    /**
     * Called directly by `Configuration::hydrateFromTyped()`.
     *
     * PRE-EXISTING WIRE-KEY MISMATCH (found during this audit, not introduced by it): this class's
     * property is `forbiddenQrScanGateway` (singular), so the auto-derived raw schema reads
     * `forbidden_qr_scan_gateway` (singular) -- but the generated model's own `@maps` annotation
     * for the equivalent field is `forbidden_qr_scan_gateways` (PLURAL; see
     * `UnivaPay\Models\MerchantWebhookQrScanConfiguration::setForbiddenQrScanGateways()`). The raw
     * path has therefore always read the wrong key and this field has always been null in
     * practice. Reading it from $body with the SAME (singular) key here preserves that existing
     * behavior exactly -- using the typed model's own (correctly-keyed) getter would silently
     * start returning real data, a behavior change typed-first hydration must not introduce.
     *
     * @param mixed $typed
     * @param array $body
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body)
    {
        if (!($typed instanceof MerchantWebhookQrScanConfiguration)) {
            return null;
        }
        return new self(
            $typed->getEnabled(),
            array_key_exists('forbidden_qr_scan_gateway', $body) ? $body['forbidden_qr_scan_gateway'] : null
        );
    }
}
