<?php

namespace Univapay\Compat\Resources\PaymentData;

use JsonSerializable;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\FunctionalUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\PaymentData\PaidyData`.
 * Property order (paidyToken, shippingAddress, phoneNumber) already matches the constructor.
 *
 * Deviation from the old SDK (found via the example round-trip harness, tools/example-roundtrip,
 * against `TokenResponsePaidyData` in the spec): the old constructor type-hinted
 * `?PhoneNumber $phoneNumber` and the old schema routed this field through
 * `PhoneNumber::getSchema()->getParser()`, a NESTED-OBJECT parser (`{country_code, local_number}`)
 * -- but `TokenResponsePaidyData.properties.phone_number` (both the create-request AND the
 * response schema) declares this field a plain `string` (e.g. `"08012341234"`), matching every
 * captured example. Feeding that string into `PhoneNumber`'s object-shaped parser previously
 * exploded with `array_key_exists(): parameter 2 must be array` warnings followed by
 * `PhoneNumber`'s own `UnivapayValidationError` (its constructor guards against a null
 * `$countryCode`, which is exactly what a string-keyed lookup on a string produces). The type hint
 * is dropped and the field now passes through untouched (the schema's default identity formatter,
 * same as every other auto-detected `JsonSchema::fromClass()` property) -- same category of fix as
 * `CardData`'s billing/threeDS relaxation.
 */
class PaidyData implements JsonSerializable
{
    use Jsonable;

    public $paidyToken;
    public $shippingAddress;
    public $phoneNumber;

    /**
     * @param mixed $phoneNumber A plain string per the wire contract (see class doc) -- kept
     *        untyped (not `?PhoneNumber`) so a real `PhoneNumber` instance built by other code is
     *        still accepted, e.g. if a future spec item restores the nested-object shape.
     */
    public function __construct(
        $paidyToken,
        Address $shippingAddress,
        $phoneNumber = null
    ) {
        $this->paidyToken = $paidyToken;
        $this->shippingAddress = $shippingAddress;
        $this->phoneNumber = $phoneNumber;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('shipping_address', true, Address::getSchema()->getParser());
    }

    public function jsonSerialize(): array
    {
        return FunctionalUtils::stripNulls([
            'paidy_token' => $this->paidyToken,
            'shipping_address' => $this->shippingAddress->jsonSerialize(),
            'phone_number' => isset($this->phoneNumber)
                ? (is_object($this->phoneNumber) ? $this->phoneNumber->jsonSerialize() : $this->phoneNumber)
                : null
        ]);
    }
}
