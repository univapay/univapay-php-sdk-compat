<?php

declare(strict_types=1);

namespace Univapay\Compat\Support;

use Money\Currency;
use Money\Money;

/**
 * @internal
 *
 * Thin adapter over moneyphp/money (retained as a compat dependency -- see plan "Two sibling
 * packages" table) so the rest of the compat layer never has to care about the exact return type
 * of `Money::getAmount()`. moneyphp v3 always returns it as a numeric *string* (so amounts stay
 * safe past PHP's native int range on 32-bit builds); casting defensively here means neither this
 * class nor its callers need moneyphp-major-version-detection logic to work against both v3 and
 * v4 (composer.json allows `^3.3 || ^4.0`).
 */
final class MoneyHelper
{
    /**
     * The new SDK's generated request/response models use a flat `int $amount` -- this is the
     * boundary conversion from a `Money\Money` value object to that shape.
     */
    public static function amount(Money $money): int
    {
        return (int) $money->getAmount();
    }

    /**
     * The new SDK's generated models use a flat `string $currency` (ISO-4217 code) -- this is the
     * boundary conversion from a `Money\Money` value object to that shape.
     */
    public static function currency(Money $money): string
    {
        return $money->getCurrency()->getCode();
    }

    /**
     * Reconstructs a `Money\Money` from the new SDK's flat `int|string $amount` + `string
     * $currency` response fields -- the reverse of amount()/currency(), used when hydrating a
     * ported old-SDK resource (whose public properties are `Money\Money`, per old-SDK parity)
     * from a decoded raw response body.
     *
     * @param int|string|null $amount
     * @param string|null $currency
     */
    public static function from($amount, $currency): ?Money
    {
        if ($amount === null || $currency === null) {
            return null;
        }
        return new Money($amount, new Currency($currency));
    }
}
