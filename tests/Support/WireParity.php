<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Support;

use PHPUnit\Framework\Assert;
use stdClass;
use Univapay\Compat\Enums\TokenType;

/**
 * The wire-parity oracle's JSON-semantic-equality comparer -- a strict `===` on two
 * `jsonSerialize()` outputs fails on several LEGITIMATE deltas between the old SDK's hand-rolled
 * wire format and the new SDK's generated one. This class encodes exactly the allowed-delta list
 * found while building `Support\RequestModelFactory` (see its class doc) and nothing else -- any
 * OTHER difference between the two sides still fails the assertion.
 *
 * Allowed deltas:
 *  - amount fields (`amount`, or any key ending in `_amount`, e.g. `fixed_cycle_amount`): old
 *    `Money::jsonSerialize()` emits the amount as a numeric STRING; the new generated models use
 *    `int`. Compared numerically (via string cast on both sides).
 *  - nested nulls: old `Utility\FunctionalUtils::stripNulls()` only strips the TOP-level keys of
 *    whatever array it is called on, so a nested optional sub-object left unset (e.g.
 *    `CardData`'s `phone_number`/`cvv_authorize`/`three_ds` when the caller didn't provide one)
 *    keeps an explicit `null` value on the old side; the new generated models' `isset()` guards
 *    omit the key entirely instead. A key present-as-`null` on one side and absent on the other
 *    (at any depth) is treated as equivalent.
 *  - `type`: when the old payment method leaves its `TokenType` unspecified, old wire omits the
 *    `type` key entirely (server-side default); `TransactionTokenCreateRequest`'s constructor
 *    requires a non-null `$type` string, so `RequestModelFactory::tokenCreate()` explicitly fills
 *    in `TokenType::ONE_TIME()->getValue()` as the default. This is a deliberate, documented
 *    default-fill, not a bug.
 *  - `exp_month`/`exp_year`: old passes the caller's value through untouched (may be int or
 *    numeric string); new coerces to string. Compared as strings.
 *  - `capture_at`: old emits the caller's own UTC offset (`DateTime::ATOM`); new always
 *    UTC-normalizes (`DateTimeHelper::toRfc3339DateTime`). Compared as the same time instant
 *    (`strtotime()`), not as equal strings.
 *  - `brand`/`call_method`/`os_type`: old `OnlinePayment`/`QrMerchantPayment`/`QrScanPayment`
 *    serialize these via `TypedEnum::getName()` (the ORIGINAL, uppercase enum-case method name,
 *    e.g. `WE_CHAT_ONLINE`), not `->getValue()` like every other payment method -- verbatim
 *    upstream behavior, discovered while building `RequestModelFactory::buildOnlineData()`. The
 *    generated `Base*Brand`/`Base*CallMethod`/`Base*OsType` enums only accept lowercase, so the
 *    factory case-folds when building these. Compared case-insensitively.
 */
final class WireParity
{
    /**
     * @param mixed $expected Old-SDK jsonSerialize() output (or a sub-value of it, for recursive
     *        calls).
     * @param mixed $actual New-SDK jsonSerialize() output (or a sub-value of it).
     */
    public static function assertEquivalent($expected, $actual, string $path = '$'): void
    {
        $expected = self::normalize($expected);
        $actual = self::normalize($actual);

        if (is_array($expected) && is_array($actual) && self::isAssoc($expected) && self::isAssoc($actual)) {
            $keys = array_unique(array_merge(array_keys($expected), array_keys($actual)));
            foreach ($keys as $key) {
                $expectedValue = array_key_exists($key, $expected) ? $expected[$key] : null;
                $actualValue = array_key_exists($key, $actual) ? $actual[$key] : null;
                $childPath = "$path.$key";

                // Nested-null-vs-absent delta (see class doc): a key missing entirely is folded
                // into `null` above, so this also covers the "one side has the key set to null,
                // the other omits it" case uniformly.
                if ($expectedValue === null && $actualValue === null) {
                    continue;
                }

                // `type` default-fill delta (see class doc).
                if ($key === 'type' && $expectedValue === null && $actualValue === TokenType::ONE_TIME()->getValue()) {
                    continue;
                }

                self::assertEquivalent($expectedValue, $actualValue, $childPath);
            }
            return;
        }

        if (is_array($expected) && is_array($actual)) {
            Assert::assertSame(count($expected), count($actual), "List size mismatch at $path");
            foreach (array_values($expected) as $i => $value) {
                self::assertEquivalent($value, array_values($actual)[$i], "$path\[$i]");
            }
            return;
        }

        $lastSegment = self::lastSegment($path);

        if (self::looksLikeAmountKey($lastSegment)) {
            Assert::assertEquals((string) $expected, (string) $actual, "Amount mismatch at $path");
            return;
        }

        if ($lastSegment === 'capture_at') {
            Assert::assertEquals(
                strtotime((string) $expected),
                strtotime((string) $actual),
                "capture_at instant mismatch at $path"
            );
            return;
        }

        if (in_array($lastSegment, ['exp_month', 'exp_year'], true)) {
            Assert::assertSame((string) $expected, (string) $actual, "$path mismatch");
            return;
        }

        if (in_array($lastSegment, ['brand', 'call_method', 'os_type'], true)) {
            // Case-fold delta (RequestModelFactory::buildOnlineData() discovery): old
            // `OnlinePayment`/`QrMerchantPayment`/`QrScanPayment` serialize these via
            // `TypedEnum::getName()` (uppercase, e.g. `WE_CHAT_ONLINE`), the generated SDK's
            // `Base*Brand`/`Base*CallMethod`/`Base*OsType` enums only accept lowercase --
            // RequestModelFactory case-folds when building these, so compare case-insensitively.
            Assert::assertSame(
                strtolower((string) $expected),
                strtolower((string) $actual),
                "$path mismatch (case-insensitive)"
            );
            return;
        }

        Assert::assertSame(
            $expected,
            $actual,
            "Mismatch at $path (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
        );
    }

    /**
     * @return mixed
     */
    private static function normalize($value)
    {
        // Both the generated SDK's models (all `implements \JsonSerializable`) and several
        // ported old-SDK classes (`Redirect`, `PaymentThreeDS`, `ThreeDSMPI` -- these declare a
        // public `jsonSerialize()` method via the `Jsonable` trait's convention but do NOT
        // formally `implements JsonSerializable`, matching upstream exactly) can show up as
        // still-boxed objects on either side of a comparison (e.g. a `data` field holding a
        // `TokenCreateKonbiniData` instance, or `metadata` holding a `GenericMetadata` instance).
        // Unwrap by duck-typing on the method rather than the interface so both cases normalize
        // the same way, recursively, until only plain scalars/arrays remain.
        if (is_object($value) && method_exists($value, 'jsonSerialize')) {
            return self::normalize($value->jsonSerialize());
        }
        if ($value instanceof stdClass) {
            return json_decode((string) json_encode($value), true);
        }
        return $value;
    }

    private static function isAssoc(array $array): bool
    {
        if ($array === []) {
            // An empty array serializes identically to an empty JSON object for our purposes --
            // there is nothing to iterate either way, so which branch handles it doesn't matter.
            return true;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }

    private static function lastSegment(string $path): string
    {
        $parts = preg_split('/[.\[]/', $path);
        $last = end($parts);
        return rtrim((string) $last, ']');
    }

    private static function looksLikeAmountKey(string $key): bool
    {
        return $key === 'amount' || substr($key, -7) === '_amount';
    }
}
