<?php

namespace Univapay\Compat;

use Univapay\Compat\Requests\Handlers\NetworkRetryHandler;
use Univapay\Compat\Requests\Handlers\RateLimitHandler;
use Univapay\Compat\Utility\FunctionalUtils;

/**
 * Verbatim port (namespace lines + handler FQCNs only) of the old SDK's `UnivapayClientOptions`.
 */
class UnivapayClientOptions
{
    /**
     * [String] Sets the endpoint the SDK connects to
     */
    public $endpoint;

    /**
     * [RateLimitHandler] The instance of the rate limit handler to use
     */
    public $rateLimitHandler;

    /**
     * [NetworkRetryHandler] The instance of the network retry handler to use
     */
    public $networkRetryHandler;

    public function __construct(
        $endpoint = 'https://api.univapay.com'
    ) {
        $this->endpoint = $endpoint;
        $this->rateLimitHandler = new RateLimitHandler();
        $this->networkRetryHandler = new NetworkRetryHandler();
    }

    public function getRequestHandlers()
    {
        return FunctionalUtils::stripNulls([
            $this->rateLimitHandler,
            $this->networkRetryHandler
        ]);
    }
}
