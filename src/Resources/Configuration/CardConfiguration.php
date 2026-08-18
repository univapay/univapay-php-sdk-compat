<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

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
}
