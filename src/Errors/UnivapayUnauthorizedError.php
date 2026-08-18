<?php

namespace Univapay\Compat\Errors;

use Throwable;

class UnivapayUnauthorizedError extends UnivapayRequestError
{
    /**
     * See UnivapayRequestError::fromJson()'s doc: value-neutral `??` guard against a malformed
     * body missing a key, not a behavior change.
     */
    public function __construct($url = "", $json = [])
    {
        parent::__construct(
            $url,
            $json['status'] ?? null,
            $json['code'] ?? null,
            $json['errors'] ?? null,
            401
        );
    }
}
