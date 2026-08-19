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

    /**
     * [bool] Opt-in runtime deprecation notices for the compat layer's own public surface. `false`
     * by default -- zero overhead (see `Support\DeprecationNotifier`'s class doc). When `true`,
     * every public compat API entry point emits one `E_USER_DEPRECATED` `trigger_error()` per
     * distinct consumer call site, naming that method's native-SDK equivalent, to help a team find
     * its remaining compat call sites ahead of a phase-2 migration onto `native()`. Not a typed
     * property (`bool $deprecationNotices = false`) -- this package still supports PHP 7.2, which
     * has no typed properties.
     */
    public $deprecationNotices;

    public function __construct(
        $endpoint = 'https://api.univapay.com'
    ) {
        $this->endpoint = $endpoint;
        $this->rateLimitHandler = new RateLimitHandler();
        $this->networkRetryHandler = new NetworkRetryHandler();
        $this->deprecationNotices = false;
    }

    public function getRequestHandlers()
    {
        return FunctionalUtils::stripNulls([
            $this->rateLimitHandler,
            $this->networkRetryHandler
        ]);
    }
}
