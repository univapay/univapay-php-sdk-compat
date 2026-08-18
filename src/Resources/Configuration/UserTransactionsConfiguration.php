<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use UnivaPay\Models\MerchantWebhookUserTransactionsConfiguration;
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

    /**
     * Called directly by `Configuration::hydrateFromTyped()`. Clean 1:1 match against the
     * generated `UnivaPay\Models\MerchantWebhookUserTransactionsConfiguration`.
     *
     * @param mixed $typed
     * @return self|null
     */
    public static function hydrateFromTyped($typed)
    {
        if (!($typed instanceof MerchantWebhookUserTransactionsConfiguration)) {
            return null;
        }
        return new self($typed->getEnabled(), $typed->getNotifyCustomer());
    }
}
