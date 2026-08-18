<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use UnivaPay\Models\CheckoutOnlineConfiguration;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\OnlineConfiguration`. `CheckoutInfo`-only (not nested under
 * `Configuration`/Merchant/Store) -- backed by the generated `CheckoutOnlineConfiguration`.
 */
class OnlineConfiguration
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
     * Called directly by `Resources\CheckoutInfo::hydrateFromTyped()`. Clean 1:1 match against
     * the generated `UnivaPay\Models\CheckoutOnlineConfiguration`.
     *
     * @param mixed $typed
     * @return self|null
     */
    public static function hydrateFromTyped($typed)
    {
        if (!($typed instanceof CheckoutOnlineConfiguration)) {
            return null;
        }
        return new self($typed->getEnabled());
    }
}
