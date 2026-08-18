<?php

namespace Univapay\Compat\Resources\PaymentMethod;

use JsonSerializable;

/**
 * Verbatim port (namespace line only) of the old SDK's
 * `Resources\PaymentMethod\PaymentMethodPatch`.
 */
class PaymentMethodPatch implements JsonSerializable
{
    private $email;
    public $metadata;

    public function __construct($email = null, ?array $metadata = null)
    {
        $this->email = $email;
        $this->metadata = $metadata;
    }

    public function jsonSerialize(): array
    {
        $values = [];
        if (isset($this->email)) {
            $values['email'] = $this->email;
        }
        if (isset($this->metadata)) {
            $values['metadata'] = $this->metadata;
        }
        return $values;
    }
}
