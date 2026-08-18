<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use UnivaPay\Models\MerchantWebhookConvenienceConfiguration;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\ConvenienceConfiguration`. Nested inside BOTH `Configuration`
 * (Merchant/Store, typed-first) and `CheckoutInfo` (its own separate `Checkout*` generated model
 * family, still raw-primary) -- `hydrateFromTyped()` only recognizes the former; fed a
 * `CheckoutConvenienceConfiguration` it declines like any other unrecognized type, which is fine
 * since `CheckoutInfo` never calls it (it has no `hydrateFromTyped()` of its own -- see
 * docs/ARCHITECTURE.md).
 */
class ConvenienceConfiguration
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
        if (!($typed instanceof MerchantWebhookConvenienceConfiguration)) {
            return null;
        }
        return new self($typed->getEnabled());
    }
}
