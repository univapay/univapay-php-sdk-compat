<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\UserTransactionsConfiguration`.
 */
class UserTransactionsConfiguration
{
    use Jsonable;

    public $enabled;
    public $notifyCustomer;

    public function __construct($enabled, $notifyCustomer)
    {
        $this->enabled = $enabled;
        $this->notifyCustomer = $notifyCustomer;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }
}
