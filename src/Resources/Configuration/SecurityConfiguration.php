<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use UnivaPay\Models\MerchantWebhookSecurityConfiguration;
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

    /**
     * Called directly by `Configuration::hydrateFromTyped()`. `limit_charge_by_card_configuration`
     * is optional (required=false) in this class's own schema -- unlike `RecurringConfiguration`'s
     * required nested confirmation, a missing/unmappable one here resolves to null rather than
     * declining the whole object.
     *
     * @param mixed $typed
     * @param array $body
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body)
    {
        if (!($typed instanceof MerchantWebhookSecurityConfiguration)) {
            return null;
        }
        $limitTyped = $typed->getLimitChargeByCardConfiguration();
        $limitConfiguration = null;
        if ($limitTyped !== null) {
            $limitConfiguration = LimitChargeByCardConfiguration::hydrateFromTyped($limitTyped);
        }
        return new self(
            $typed->getInspectSuspiciousLoginAfter(),
            $typed->getRefundPercentLimit(),
            $limitConfiguration,
            $typed->getConfirmationRequired()
        );
    }
}
