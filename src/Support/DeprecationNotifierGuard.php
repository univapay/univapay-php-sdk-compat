<?php

declare(strict_types=1);

namespace Univapay\Compat\Support;

/**
 * @internal
 *
 * RAII-style scope guard returned by `DeprecationNotifier::notify()`. PHP destroys a local
 * variable -- and so calls this class's destructor -- exactly when the method holding it returns
 * or throws, which is what lets `DeprecationNotifier`'s re-entrancy guard bracket an ENTIRE public
 * compat method call (including whatever compat-internal methods it calls before returning) using
 * only a single hook line at the top of that method, with no matching "end" line needed at the
 * bottom.
 */
final class DeprecationNotifierGuard
{
    public function __destruct()
    {
        DeprecationNotifier::release();
    }
}
