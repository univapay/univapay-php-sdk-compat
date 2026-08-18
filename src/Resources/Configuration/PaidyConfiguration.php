<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use UnivaPay\Models\MerchantWebhookPaidyConfiguration;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\PaidyConfiguration`. Nested inside BOTH `Configuration` (Merchant/
 * Store, typed-first) and `CheckoutInfo` (own `Checkout*` model family, still raw-primary) -- see
 * `ConvenienceConfiguration`'s doc for why `hydrateFromTyped()` only needs to recognize one.
 */
class PaidyConfiguration
{
    use Jsonable;

    public $enabled;

    public function __construct($enabled)
    {
        $this->enabled = $enabled;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }

    /**
     * Called directly by `Configuration::hydrateFromTyped()`.
     *
     * @param mixed $typed
     * @return self|null
     */
    public static function hydrateFromTyped($typed)
    {
        if (!($typed instanceof MerchantWebhookPaidyConfiguration)) {
            return null;
        }
        return new self($typed->getEnabled());
    }
}
