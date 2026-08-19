<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use UnivaPay\Models\CheckoutCardConfiguration;
use UnivaPay\Models\MerchantWebhookCardConfiguration;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\CardConfiguration`. The old file's unused `Utility\FunctionalUtils as
 * fp` import is dropped here -- it was dead in the upstream class body (no `fp::` call anywhere),
 * not something this port relies on.
 */
class CardConfiguration
{
    use Jsonable;

    public $enabled;
    public $debitEnabled;
    public $prepaidEnabled;
    public $onlyDirectCurrency;
    public $forbiddenCardBrands;
    public $allowedCountriesByIp;
    public $foreignCardsAllowed;
    public $failOnNewEmail;
    public $cardLimit;
    public $allowEmptyCvv;

    public function __construct(
        $enabled,
        $debitEnabled,
        $prepaidEnabled,
        $onlyDirectCurrency,
        $forbiddenCardBrands,
        $allowedCountriesByIp,
        $foreignCardsAllowed,
        $failOnNewEmail,
        $cardLimit,
        $allowEmptyCvv
    ) {
        $this->enabled = $enabled;
        $this->debitEnabled = $debitEnabled;
        $this->prepaidEnabled = $prepaidEnabled;
        $this->onlyDirectCurrency = $onlyDirectCurrency;
        $this->forbiddenCardBrands = $forbiddenCardBrands;
        $this->allowedCountriesByIp = $allowedCountriesByIp;
        $this->foreignCardsAllowed = $foreignCardsAllowed;
        $this->failOnNewEmail = $failOnNewEmail;
        $this->cardLimit = $cardLimit;
        $this->allowEmptyCvv = $allowEmptyCvv;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }

    /**
     * Called directly by `Configuration::hydrateFromTyped()` (Merchant/Store, backed by
     * `MerchantWebhookCardConfiguration`) AND `CheckoutInfo::hydrateFromTyped()` (backed by the
     * separate `CheckoutCardConfiguration`) -- this class is nested under both, see its own class
     * doc. Every field but `card_limit` is a clean 1:1 match against EITHER generated model.
     *
     * `card_limit` is read from $body (this same raw sub-object), not either typed model's own
     * `getCardLimit()` -- compat stores it as the raw decoded value verbatim (no formatter in this
     * class's own schema), and the two generated families disagree on its very TYPE
     * (`MerchantWebhookCardConfiguration::getCardLimit(): ?int` vs
     * `CheckoutCardConfiguration::getCardLimit(): ?CardLimit`, a nested object) -- reading it
     * typed would mean two different shapes depending on which endpoint hydrated this class,
     * something the raw-passthrough contract has never done.
     *
     * @param mixed $typed
     * @param array $body
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body)
    {
        if (!($typed instanceof MerchantWebhookCardConfiguration) && !($typed instanceof CheckoutCardConfiguration)) {
            return null;
        }
        return new self(
            $typed->getEnabled(),
            $typed->getDebitEnabled(),
            $typed->getPrepaidEnabled(),
            $typed->getOnlyDirectCurrency(),
            $typed->getForbiddenCardBrands(),
            $typed->getAllowedCountriesByIp(),
            $typed->getForeignCardsAllowed(),
            $typed->getFailOnNewEmail(),
            array_key_exists('card_limit', $body) ? $body['card_limit'] : null,
            $typed->getAllowEmptyCvv()
        );
    }
}
