<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\CardBrandPercentFees`. Pure data class -- no formatter overrides, every
 * field is a bare scalar so the default `JsonSchema::fromClass()` identity formatter suffices.
 */
class CardBrandPercentFees
{
    use Jsonable;

    public $visa;
    public $americanExpress;
    public $mastercard;
    public $maestro;
    public $discover;
    public $jcb;
    public $dinersClub;
    public $unionPay;

    public function __construct(
        $visa,
        $americanExpress,
        $mastercard,
        $maestro,
        $discover,
        $jcb,
        $dinersClub,
        $unionPay
    ) {
        $this->visa = $visa;
        $this->americanExpress = $americanExpress;
        $this->mastercard = $mastercard;
        $this->maestro = $maestro;
        $this->discover = $discover;
        $this->jcb = $jcb;
        $this->dinersClub = $dinersClub;
        $this->unionPay = $unionPay;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }
}
