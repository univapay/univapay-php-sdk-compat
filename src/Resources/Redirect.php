<?php

namespace Univapay\Compat\Resources;

use Univapay\Compat\Utility\FunctionalUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\Redirect`. Property order
 * (endpoint, redirectId) already matches the constructor. `jsonSerialize()` deliberately emits
 * only `endpoint` -- `redirectId` is response-only and was never sent on create -- which is why
 * `Support\RequestModelFactory::chargeCreate()` can build the new SDK's typed
 * `ChargeCreateRequestRedirect` (which only HAS an `endpoint` field) instead of needing an
 * additionalProperties passthrough, unlike `PaymentThreeDS` (see that class's docblock).
 */
class Redirect
{
    use Jsonable;

    public $endpoint;
    public $redirectId;

    public function __construct(
        $endpoint,
        $redirectId = null
    ) {
        $this->endpoint = $endpoint;
        $this->redirectId = $redirectId;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }

    public function jsonSerialize()
    {
        $data = [
            'endpoint' => $this->endpoint
        ];
        return FunctionalUtils::stripNulls($data);
    }
}
