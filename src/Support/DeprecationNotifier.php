<?php

declare(strict_types=1);

namespace Univapay\Compat\Support;

/**
 * @internal
 *
 * Backs `UnivapayClientOptions::$deprecationNotices` (default `false`). When a consumer opts in,
 * every public compat API entry point (`UnivapayClient` methods, resource methods like
 * `fetch()`/`patch()`/`capture()`/`cancel()`/`awaitResult()`/`createRefund()`, list mixin methods,
 * `parseWebhookData()`) calls `notify()` as its very first statement -- a single, uniform,
 * trivially greppable (`DeprecationNotifier::notify(`) line, never a wrapper around the method
 * itself. `native()` never calls it at all.
 *
 * ## Zero overhead when disabled
 *
 * `notify()`'s first check is the `$enabled` flag a call site reads off its own `Bridge` (see
 * `Resource::getBridge()`/`UnivapayClient::getBridge()`). `false` (the default) returns
 * immediately -- no backtrace, no registry read or write, nothing recorded (`notifiedSites()`
 * stays empty forever; see `tests/Unit/Support/DeprecationNotifierTest.php`'s disabled-path test).
 *
 * ## Dedup: once per CONSUMER call site
 *
 * `callerSite()` walks `debug_backtrace()` outward from `notify()`'s own call site until it finds
 * the first frame whose `file` is NOT inside this package's `src/` tree -- i.e. the first frame
 * that actually belongs to the CONSUMER, not to any compat-internal delegation in between. The
 * resulting `file:line` string is the dedup key: a second call from the exact same line never
 * notifies again, but two different lines each get their own notice, even for the same method.
 *
 * ## Re-entrancy: one notice per OUTER call, not a cascade
 *
 * Compat methods routinely call other compat methods internally (`UnivapayClient::createCharge()`
 * -> `getTransactionToken()` -> `TransactionToken::createCharge()`, `TransactionToken::patch()` ->
 * `Resource::update()` -> `Resource::fetch()`, ...). Without a guard, one consumer call would emit
 * a notice for every layer of that cascade. `notify()` guards against this with a single static
 * `$inFlight` flag, set for the duration of the OUTERMOST call and released only when that call's
 * own stack frame ends -- not when `notify()` itself returns, since the cascade happens later in
 * the same method body. The release is wired through `DeprecationNotifierGuard`'s destructor:
 * `notify()` returns a guard object (or `null` when there is nothing to release) that the call
 * site assigns to a local variable; PHP destroys that variable -- and so releases the guard --
 * exactly when the enclosing method returns or throws, bracketing the entire outer call for free
 * without a second "end" line at the call site. Any `notify()` call made while `$inFlight` is
 * already `true` (i.e. from inside that bracket) returns `null` immediately, before touching the
 * backtrace or the registry -- no whitelist of "internal" methods needed.
 */
final class DeprecationNotifier
{
    /**
     * Same migration-guide URL the README's "Migrating off the compat layer" section links, and
     * the section every generated notice points a consumer at for the full construct-by-construct
     * table.
     */
    private const GUIDE_URL = 'https://univapay.com/docs/#/http/onboarding-guides/guides/'
        . 'php-sdk-migration#migrating-further-to-the-native-sdk';

    private const BACKTRACE_LIMIT = 20;

    /** @var array<string, true> Call sites ("file:line") already notified. */
    private static $notifiedSites = [];

    /** @var bool */
    private static $inFlight = false;

    /**
     * @param bool $enabled Read by the call site off its own `Bridge` --
     *        `UnivapayClientOptions::$deprecationNotices`.
     * @param string $compatMethod Fully-qualified, human-readable label for the compat method
     *        being entered, e.g. `'Univapay\Compat\UnivapayClient::createCharge()'`. Always a
     *        literal string (or `static::class`-prefixed literal) at the call site -- never
     *        derived from `__FUNCTION__`/`__METHOD__`, which would report a trait's private alias
     *        name (e.g. `ScheduledPayment`'s `fullListCharges`) instead of the public name a
     *        consumer actually called.
     * @param string $nativeEquivalent Short label for the native-SDK construct that replaces it,
     *        e.g. `'ChargesApi::createCharge()'`, or a plain-English note when none exists (a
     *        handful of permanently-unsupported methods -- see the compat README's supported
     *        surface matrix).
     * @return DeprecationNotifierGuard|null Assign this to a local variable in the calling method
     *         (e.g. `$deprecationNotice = DeprecationNotifier::notify(...);`). Its destructor
     *         releases the re-entrancy guard when that variable goes out of scope, i.e. when the
     *         calling method returns or throws. `null` when disabled or already nested inside
     *         another call's guard -- nothing to release.
     */
    public static function notify(bool $enabled, string $compatMethod, string $nativeEquivalent)
    {
        if (!$enabled || self::$inFlight) {
            return null;
        }
        self::$inFlight = true;

        $site = self::callerSite();
        if (!isset(self::$notifiedSites[$site])) {
            self::$notifiedSites[$site] = true;
            trigger_error(
                sprintf(
                    '%s is a compatibility-layer method; the native equivalent is %s via native(). See %s',
                    $compatMethod,
                    $nativeEquivalent,
                    self::GUIDE_URL
                ),
                E_USER_DEPRECATED
            );
        }

        return new DeprecationNotifierGuard();
    }

    /**
     * @internal Called only by `DeprecationNotifierGuard::__destruct()`.
     */
    public static function release(): void
    {
        self::$inFlight = false;
    }

    /**
     * @return string The first `debug_backtrace()` frame whose `file` is outside this package's
     *         own `src/` directory, formatted `"$file:$line"` -- i.e. the consumer's own call
     *         site, however many compat-internal frames sit between it and this method. Frames
     *         missing a `file` (rare -- some closure/callback invocations) are skipped.
     *         `'unknown'` if every captured frame is inside the package (should not happen in
     *         practice -- `notify()` is always called from a compat method that was, in turn,
     *         called from somewhere outside it).
     */
    private static function callerSite(): string
    {
        $packageSrcDir = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::BACKTRACE_LIMIT);

        foreach ($frames as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }
            if (strpos($frame['file'], $packageSrcDir) !== 0) {
                return $frame['file'] . ':' . ($frame['line'] ?? 0);
            }
        }

        return 'unknown';
    }

    /**
     * Test-only introspection seam (mirrors `FallbackRegistry::occurrences()`/`reset()`) -- lets
     * tests assert the disabled path never touches the registry, without reflecting into private
     * statics.
     *
     * @return string[] Call sites ("file:line") notified so far, in insertion order.
     */
    public static function notifiedSites(): array
    {
        return array_keys(self::$notifiedSites);
    }

    public static function reset(): void
    {
        self::$notifiedSites = [];
        self::$inFlight = false;
    }
}
