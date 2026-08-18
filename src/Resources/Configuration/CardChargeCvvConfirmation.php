<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\CardChargeCvvConfirmation`.
 */
class CardChargeCvvConfirmation
{
    use Jsonable;

    public $enabled;
    public $threshold;

    public function __construct($enabled, $threshold)
    {
        $this->enabled = $enabled;
        $this->threshold = $threshold;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }
}
