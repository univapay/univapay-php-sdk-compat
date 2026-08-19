<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use UnivaPay\Models\MerchantWebhookLimitChargeByCardConfiguration;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\LimitChargeByCardConfiguration`.
 */
class LimitChargeByCardConfiguration
{
    use Jsonable;

    public $quantityOfCharges;
    public $durationWindow;

    public function __construct($quantityOfCharges, $durationWindow)
    {
        $this->quantityOfCharges = $quantityOfCharges;
        $this->durationWindow = $durationWindow;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }

    /**
     * Called directly by `SecurityConfiguration::hydrateFromTyped()`. Clean 1:1 match against the
     * generated `UnivaPay\Models\MerchantWebhookLimitChargeByCardConfiguration`.
     *
     * @param mixed $typed
     * @return self|null
     */
    public static function hydrateFromTyped($typed)
    {
        if (!($typed instanceof MerchantWebhookLimitChargeByCardConfiguration)) {
            return null;
        }
        return new self($typed->getQuantityOfCharges(), $typed->getDurationWindow());
    }
}
