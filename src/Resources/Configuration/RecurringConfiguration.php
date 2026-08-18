<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use UnivaPay\Models\MerchantWebhookRecurringTokenConfiguration;
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

    /**
     * Called directly by `Configuration::hydrateFromTyped()`. Declines when
     * `card_charge_cvv_confirmation` (required=true in this class's own schema) is absent from
     * the typed model, or when `CardChargeCvvConfirmation::hydrateFromTyped()` itself declines.
     *
     * @param mixed $typed
     * @param array $body
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body)
    {
        if (!($typed instanceof MerchantWebhookRecurringTokenConfiguration)) {
            return null;
        }
        $cvvTyped = $typed->getCardChargeCvvConfirmation();
        if ($cvvTyped === null) {
            return null;
        }
        $cvvBody = isset($body['card_charge_cvv_confirmation']) && is_array($body['card_charge_cvv_confirmation'])
            ? $body['card_charge_cvv_confirmation']
            : [];
        $cvvConfirmation = CardChargeCvvConfirmation::hydrateFromTyped($cvvTyped, $cvvBody);
        if ($cvvConfirmation === null) {
            return null;
        }
        return new self($typed->getRecurringType(), $typed->getChargeWaitPeriod(), $cvvConfirmation);
    }
}
