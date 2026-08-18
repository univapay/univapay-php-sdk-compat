<?php

namespace Univapay\Compat\Requests\Handlers;

use Univapay\Compat\Errors\UnivapayNetworkError;

/**
 * Port of the old SDK's `Requests\Handlers\NetworkRetryHandler` with ONE deliberate delta (see
 * plan "Compat semantic-parity amendments" blocker 4): the old class targeted
 * `WpOrg\Requests\Exception`, the transport-level exception thrown by rmccue/requests on a
 * network failure. That class does not exist post-migration -- the new transport engine's HTTP
 * client instead surfaces a network failure as `\UnivaPay\Exceptions\ApiException` with
 * `getCode() === 0` and `hasResponse() === false`, which `Support\ExceptionMapper` translates
 * into `Univapay\Compat\Errors\UnivapayNetworkError`. Targeting the old, migrated-away class
 * here would leave this handler's retry loop silently dead (never matches, so every network
 * failure escapes on the first attempt) -- targeting `UnivapayNetworkError` instead keeps the
 * "retry a network hiccup `$tries` times" behavior integrators already rely on.
 */
class NetworkRetryHandler extends BasicRetryHandler
{
    public function __construct($tries = 3, $interval = 1)
    {
        parent::__construct(UnivapayNetworkError::class, $tries, $interval);
    }
}
