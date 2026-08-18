<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use UnivaPay\Models\CheckoutConvenienceConfiguration;
use UnivaPay\Models\MerchantWebhookConvenienceConfiguration;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\ConvenienceConfiguration`. Nested inside BOTH `Configuration`
 * (Merchant/Store, backed by `MerchantWebhookConvenienceConfiguration`) and `CheckoutInfo` (backed
 * by the separate `CheckoutConvenienceConfiguration`) -- both typed-first, `hydrateFromTyped()`
 * recognizes either.
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
        if (
            !($typed instanceof MerchantWebhookConvenienceConfiguration)
            && !($typed instanceof CheckoutConvenienceConfiguration)
        ) {
            return null;
        }
        return new self($typed->getEnabled());
    }
}
