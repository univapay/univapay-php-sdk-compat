<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use UnivaPay\Models\CheckoutQrScanConfiguration;
use UnivaPay\Models\MerchantWebhookQrScanConfiguration;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\QrScanConfiguration`. Nested inside BOTH `Configuration` (Merchant/
 * Store, backed by `MerchantWebhookQrScanConfiguration`) and `CheckoutInfo` (backed by the
 * separate `CheckoutQrScanConfiguration`) -- both typed-first, `hydrateFromTyped()` recognizes
 * either.
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
     * Called directly by `Configuration::hydrateFromTyped()`/`CheckoutInfo::hydrateFromTyped()`.
     *
     * PRE-EXISTING WIRE-KEY MISMATCH (found during the Merchant/Store Configuration audit, not
     * introduced by it): this class's property is `forbiddenQrScanGateway` (singular), so the
     * auto-derived raw schema reads `forbidden_qr_scan_gateway` (singular) -- but BOTH generated
     * model families' own `@maps` annotation for the equivalent field is
     * `forbidden_qr_scan_gateways` (PLURAL; see `MerchantWebhookQrScanConfiguration`/
     * `CheckoutQrScanConfiguration::setForbiddenQrScanGateways()`). The raw path has therefore
     * always read the wrong key and this field has always been null in practice, regardless of
     * which endpoint hydrated it. Reading it from $body with the SAME (singular) key here
     * preserves that existing behavior exactly -- using either typed model's own
     * (correctly-keyed) getter would silently start returning real data, a behavior change
     * typed-first hydration must not introduce.
     *
     * @param mixed $typed
     * @param array $body
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body)
    {
        $isMerchantWebhook = $typed instanceof MerchantWebhookQrScanConfiguration;
        $isCheckout = $typed instanceof CheckoutQrScanConfiguration;
        if (!$isMerchantWebhook && !$isCheckout) {
            return null;
        }
        return new self(
            $typed->getEnabled(),
            array_key_exists('forbidden_qr_scan_gateway', $body) ? $body['forbidden_qr_scan_gateway'] : null
        );
    }
}
