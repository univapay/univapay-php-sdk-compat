<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use UnivaPay\Models\MerchantWebhookRecurringCvvConfirmationConfig;
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

    /**
     * Called directly by `RecurringConfiguration::hydrateFromTyped()`. `threshold` is read from
     * $body (this same raw sub-object), not the generated model's `getThreshold(): ?array` --
     * compat stores it as the raw decoded value verbatim (no formatter in this class's own
     * schema), same treatment as `Charge`'s `metadata`.
     *
     * @param mixed $typed
     * @param array $body
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body)
    {
        if (!($typed instanceof MerchantWebhookRecurringCvvConfirmationConfig)) {
            return null;
        }
        return new self($typed->getEnabled(), array_key_exists('threshold', $body) ? $body['threshold'] : null);
    }
}
