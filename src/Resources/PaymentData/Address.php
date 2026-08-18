<?php

namespace Univapay\Compat\Resources\PaymentData;

use JsonSerializable;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\FunctionalUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\PaymentData\Address`.
 *
 * Property declaration order (line1, line2, state, city, country, zip) already matches the
 * constructor's parameter order exactly, so `JsonSchema::fromClass()` reflection (which derives
 * schema paths from declared property order -- see `Resources\Authentication\MerchantAppJWT` for
 * a case where mismatched order breaks this) resolves correctly as-is -- no reordering needed for
 * this class.
 */
class Address implements JsonSerializable
{
    use Jsonable;

    public $line1;
    public $line2;
    public $state;
    public $city;
    public $country;
    public $zip;

    public function __construct(
        $line1 = null,
        $line2 = null,
        $state = null,
        $city = null,
        $country = null,
        $zip = null
    ) {
        $this->line1 = $line1;
        $this->line2 = $line2;
        $this->state = $state;
        $this->city = $city;
        $this->country = $country;
        $this->zip = $zip;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }

    public function jsonSerialize(): array
    {
        $data = [
            'line1' => $this->line1,
            'line2' => $this->line2,
            'state' => $this->state,
            'city' => $this->city,
            'country' => $this->country,
            'zip' => $this->zip
        ];
        return FunctionalUtils::stripNulls($data);
    }
}
