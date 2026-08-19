<?php

declare(strict_types=1);

namespace Univapay\Compat\Support;

/**
 * @internal
 *
 * Minimal stand-in `Resources\Resource::getBridge()` returns for a resource that has no
 * `$context` at all -- `Merchant`, `BankAccount`, `Transfer`, and `TransferStatusChange` can all
 * be constructed directly with a null context (their `fetch()`/`update()` throw
 * `Errors\UnivapayUnsupportedFeatureError` unconditionally and never touch a real `Bridge`
 * regardless), and so can any test fixture that only needs `fetchCall()`/`updateCall()` in
 * isolation. Answers every deprecation-notice hook call site's only question -- are notices
 * enabled -- with a firm "no": there is no `UnivapayClientOptions` to read at all without a real
 * client behind this instance.
 */
final class NullBridge
{
    public function deprecationNoticesEnabled(): bool
    {
        return false;
    }
}
