<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

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
}
