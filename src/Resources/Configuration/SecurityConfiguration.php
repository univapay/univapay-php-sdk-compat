<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\SecurityConfiguration`.
 */
class SecurityConfiguration
{
    use Jsonable;

    public $inspectSuspiciousLoginAfter;
    public $refundPercentLimit;
    public $limitChargeByCardConfiguration;
    public $confirmationRequired;

    public function __construct(
        $inspectSuspiciousLoginAfter,
        $refundPercentLimit,
        $limitChargeByCardConfiguration,
        $confirmationRequired
    ) {
        $this->inspectSuspiciousLoginAfter = $inspectSuspiciousLoginAfter;
        $this->refundPercentLimit = $refundPercentLimit;
        $this->limitChargeByCardConfiguration = $limitChargeByCardConfiguration;
        $this->confirmationRequired = $confirmationRequired;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert(
                'limit_charge_by_card_configuration',
                false,
                LimitChargeByCardConfiguration::getSchema()->getParser()
            );
    }
}
