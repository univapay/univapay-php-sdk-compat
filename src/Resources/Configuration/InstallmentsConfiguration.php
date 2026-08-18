<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use UnivaPay\Models\MerchantWebhookInstallmentPlanConfiguration;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\InstallmentsConfiguration`.
 */
class InstallmentsConfiguration
{
    use Jsonable;

    public $enabled;
    public $minChargeAmount;
    public $maxPayoutPeriod;

    public function __construct($enabled, $minChargeAmount, $maxPayoutPeriod)
    {
        $this->enabled = $enabled;
        $this->minChargeAmount = $minChargeAmount;
        $this->maxPayoutPeriod = $maxPayoutPeriod;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }

    /**
     * Called directly by `Configuration::hydrateFromTyped()`. `min_charge_amount` is read from
     * $body (this same raw sub-object), not the generated model's `getMinChargeAmount():
     * ?MerchantWebhookMoneyAmount` -- compat stores it as the raw decoded value verbatim (no Money
     * conversion; no formatter in this class's own schema at all), same treatment as `Charge`'s
     * `metadata`.
     *
     * @param mixed $typed
     * @param array $body
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body)
    {
        if (!($typed instanceof MerchantWebhookInstallmentPlanConfiguration)) {
            return null;
        }
        return new self(
            $typed->getEnabled(),
            array_key_exists('min_charge_amount', $body) ? $body['min_charge_amount'] : null,
            $typed->getMaxPayoutPeriod()
        );
    }
}
