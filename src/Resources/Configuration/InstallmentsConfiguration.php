<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

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
}
