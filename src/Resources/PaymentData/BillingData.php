<?php

namespace Univapay\Compat\Resources\PaymentData;

use JsonSerializable;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\FunctionalUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\PaymentData\BillingData`.
 * Property order (line1..zip, phoneNumber) already matches the constructor.
 */
class BillingData implements JsonSerializable
{
    use Jsonable;

    public $line1;
    public $line2;
    public $state;
    public $city;
    public $country;
    public $zip;
    public $phoneNumber;

    public function __construct(
        $line1 = null,
        $line2 = null,
        $state = null,
        $city = null,
        $country = null,
        $zip = null,
        ?PhoneNumber $phoneNumber = null
    ) {
        $this->line1 = $line1;
        $this->line2 = $line2;
        $this->state = $state;
        $this->city = $city;
        $this->country = $country;
        $this->zip = $zip;
        $this->phoneNumber = $phoneNumber;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('phone_number', false, PhoneNumber::getSchema()->getParser());
    }

    public function jsonSerialize(): array
    {
        $data = [
            'line1' => $this->line1,
            'line2' => $this->line2,
            'state' => $this->state,
            'city' => $this->city,
            'country' => $this->country,
            'zip' => $this->zip,
            'phone_number' => isset($this->phoneNumber) ? $this->phoneNumber->jsonSerialize() : null
        ];
        return FunctionalUtils::stripNulls($data);
    }
}
