<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Support;

/**
 * Minimal stand-in for `Support\Bridge` used by trait fixtures (`GetCharges`/`GetSubscriptions`/
 * `GetTransactions`/`Pollable`, ...) that mix a trait in directly rather than extending
 * `Resources\Resource`/`UnivapayClient`. Every hook call site added by
 * `Support\DeprecationNotifier`'s integration reads only `deprecationNoticesEnabled()` off
 * whatever `getBridge()` returns, so these fixtures share this one trivial double instead of each
 * constructing a real `Support\Bridge` (which needs a real `AppJWT` + HTTP client) just to answer
 * "no, notices are off" -- keeping every trait unit test unaffected by (and not asserting
 * anything about) the deprecation-notice feature itself.
 */
class NoticesDisabledBridgeStub
{
    public function deprecationNoticesEnabled(): bool
    {
        return false;
    }
}
