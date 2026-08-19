<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use UnivaPay\Models\MerchantWebhookCardBrandPercentFees;
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

    /**
     * Called directly by `Configuration::hydrateFromTyped()` (this class is only ever nested,
     * never independently fetched). Clean 1:1 match against the generated
     * `UnivaPay\Models\MerchantWebhookCardBrandPercentFees` -- no gap, no raw patch needed.
     *
     * @param mixed $typed
     * @return self|null
     */
    public static function hydrateFromTyped($typed)
    {
        if (!($typed instanceof MerchantWebhookCardBrandPercentFees)) {
            return null;
        }
        return new self(
            $typed->getVisa(),
            $typed->getAmericanExpress(),
            $typed->getMastercard(),
            $typed->getMaestro(),
            $typed->getDiscover(),
            $typed->getJcb(),
            $typed->getDinersClub(),
            $typed->getUnionPay()
        );
    }
}
