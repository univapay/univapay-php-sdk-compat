<?php

namespace Univapay\Compat\Resources\PaymentData;

use Money\Currency;
use UnivaPay\Models\TokenResponseCardData;
use Univapay\Compat\Enums\CvvAuthorizationStatus;
use Univapay\Compat\Enums\ThreeDSStatus;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Port of the old SDK's `Resources\PaymentData\CardData`.
 * Response-side hydration only. Property order (card, billing, cvvAuthorize, threeDS) already
 * matches the constructor.
 *
 * `billing`/`threeDS` are nullable: absent on the wire yields `null`, not a `TypeError` (see
 * README behavior-delta table).
 */
class CardData
{
    use Jsonable;

    public $card;
    public $billing;
    public $cvvAuthorize;
    public $threeDS;

    public function __construct(
        Card $card,
        ?BillingData $billing,
        CvvAuthorize $cvvAuthorize,
        ?TokenThreeDS $threeDS
    ) {
        $this->card = $card;
        $this->billing = $billing;
        $this->cvvAuthorize = $cvvAuthorize;
        $this->threeDS = $threeDS;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('card', true, Card::getSchema()->getParser())
            ->upsert('billing', false, BillingData::getSchema()->getParser())
            ->upsert('cvv_authorize', true, CvvAuthorize::getSchema()->getParser())
            // TODO: add cvv_authorize_check
            ->upsert('three_ds', false, TokenThreeDS::getSchema()->getParser());
    }

    /**
     * Called directly by `Resources\TransactionToken::hydrateFromTyped()` (this class is never
     * independently fetched via `Support\TypedHydrator::resolve()` -- it only ever exists nested
     * inside a `TransactionToken`'s `data` union). `$dataBody` is the raw `data` sub-object from
     * that same response, used only for `three_ds.error` (compat stores it as the raw decoded
     * value verbatim, never the typed `PaymentError`, same as `Charge`'s `error`/`metadata`).
     *
     * Declines (null) when `card`/`cvv_authorize` (both required=true in this class's own schema)
     * are absent from the typed model -- the raw path would throw `NoSuchPathException` for that
     * same response.
     *
     * @param mixed $typed
     * @param array $dataBody
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $dataBody)
    {
        if (!($typed instanceof TokenResponseCardData)) {
            return null;
        }
        $cardTyped = $typed->getCard();
        $cvvTyped = $typed->getCvvAuthorize();
        if ($cardTyped === null || $cvvTyped === null) {
            return null;
        }

        $card = new Card(
            $cardTyped->getCardholder(),
            $cardTyped->getExpMonth(),
            $cardTyped->getExpYear(),
            $cardTyped->getLastFour(),
            $cardTyped->getBrand(),
            $cardTyped->getCountry(),
            $cardTyped->getCardType(),
            $cardTyped->getCategory(),
            $cardTyped->getIssuer(),
            $cardTyped->getSubBrand()
        );

        $billingTyped = $typed->getBilling();
        $billing = $billingTyped !== null ? new BillingData(
            $billingTyped->getLine1(),
            $billingTyped->getLine2(),
            $billingTyped->getState(),
            $billingTyped->getCity(),
            $billingTyped->getCountry(),
            $billingTyped->getZip(),
            self::phoneNumberFromTyped($billingTyped->getPhoneNumber())
        ) : null;

        $cvvAuthorize = new CvvAuthorize(
            $cvvTyped->getEnabled(),
            $cvvTyped->getCurrency() !== null ? new Currency($cvvTyped->getCurrency()) : null,
            CvvAuthorizationStatus::fromValue($cvvTyped->getStatus()),
            $cvvTyped->getChargeId(),
            $cvvTyped->getCredentialsId()
        );

        $threeDsTyped = $typed->getThreeDs();
        $threeDS = null;
        if ($threeDsTyped !== null) {
            $raw = isset($dataBody['three_ds']) && is_array($dataBody['three_ds']) ? $dataBody['three_ds'] : [];
            $threeDS = new TokenThreeDS(
                $threeDsTyped->getEnabled(),
                $threeDsTyped->getRedirectEndpoint(),
                ThreeDSStatus::fromValue($threeDsTyped->getStatus()),
                $threeDsTyped->getRedirectId(),
                array_key_exists('error', $raw) ? $raw['error'] : null
            );
        }

        return new self($card, $billing, $cvvAuthorize, $threeDS);
    }

    /** @return PhoneNumber|null */
    private static function phoneNumberFromTyped($typedPhone)
    {
        if ($typedPhone === null) {
            return null;
        }
        return new PhoneNumber($typedPhone->getCountryCode(), $typedPhone->getLocalNumber());
    }
}
