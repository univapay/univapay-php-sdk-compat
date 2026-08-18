<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\TransferSchedule`.
 */
class TransferSchedule
{
    use Jsonable;

    public $waitPeriod;
    public $period;
    public $dayOfWeek;
    public $weekOfMonth;
    public $dayOfMonth;

    public function __construct($waitPeriod, $period, $dayOfWeek, $weekOfMonth, $dayOfMonth)
    {
        $this->waitPeriod = $waitPeriod;
        $this->period = $period;
        $this->dayOfWeek = $dayOfWeek;
        $this->weekOfMonth = $weekOfMonth;
        $this->dayOfMonth = $dayOfMonth;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }
}
