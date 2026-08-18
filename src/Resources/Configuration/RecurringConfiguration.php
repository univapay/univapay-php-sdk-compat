<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\RecurringConfiguration`.
 */
class RecurringConfiguration
{
    use Jsonable;

    public $recurringType;
    public $chargeWaitPeriod;
    public $cardChargeCvvConfirmation;

    public function __construct($recurringType, $chargeWaitPeriod, $cardChargeCvvConfirmation)
    {
        $this->recurringType = $recurringType;
        $this->chargeWaitPeriod = $chargeWaitPeriod;
        $this->cardChargeCvvConfirmation = $cardChargeCvvConfirmation;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert(
                'card_charge_cvv_confirmation',
                true,
                CardChargeCvvConfirmation::getSchema()->getParser()
            );
    }
}
