<?php

namespace Univapay\Compat\Requests\Handlers;

use Univapay\Compat\Errors\UnivapayRateLimitedError;

/**
 * Verbatim port (namespace lines only) of the old SDK's `Requests\Handlers\RateLimitHandler`.
 */
class RateLimitHandler extends BasicRetryHandler
{
    public function __construct($tries = 3, $interval = 1)
    {
        parent::__construct(UnivapayRateLimitedError::class, $tries, $interval);
    }
}
