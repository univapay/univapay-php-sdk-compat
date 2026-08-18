<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Support\DeprecationNotifier;
use Univapay\Compat\Support\DeprecationNotifierGuard;

/**
 * Unit-level coverage of `DeprecationNotifier` itself, isolated from any real `Bridge`/
 * `UnivapayClient`/resource -- every call below passes the `$enabled` flag and labels directly.
 * See `DeprecationNoticesIntegrationTest` for the same guarantees exercised through real compat
 * entry points (`UnivapayClient::getTransfer()`, the `createCharge()` two-step cascade, `native()`).
 */
class DeprecationNotifierTest extends TestCase
{
    protected function setUp(): void
    {
        DeprecationNotifier::reset();
    }

    protected function tearDown(): void
    {
        DeprecationNotifier::reset();
    }

    /**
     * @param callable $fn
     * @return string[] Every `E_USER_DEPRECATED` message raised while $fn ran.
     */
    private function captureDeprecationMessages(callable $fn): array
    {
        $messages = [];
        set_error_handler(function (int $errno, string $errstr) use (&$messages): bool {
            if ($errno === E_USER_DEPRECATED) {
                $messages[] = $errstr;
                return true;
            }
            return false;
        });
        try {
            $fn();
        } finally {
            restore_error_handler();
        }
        return $messages;
    }

    // --- disabled: zero overhead --------------------------------------------------------------

    public function testDisabledReturnsNullAndNeverTouchesTheRegistry()
    {
        $messages = $this->captureDeprecationMessages(function () {
            $guard = DeprecationNotifier::notify(false, 'Compat::method()', 'Native::equivalent()');
            $this->assertNull($guard);
        });

        $this->assertSame([], $messages);
        // The registry a working (enabled) call would have populated stays completely empty --
        // the observable proof that the disabled path never reached the backtrace/dedup logic at
        // all, not just that it happened not to notify this particular site.
        $this->assertSame([], DeprecationNotifier::notifiedSites());
    }

    public function testDisabledManyCallsFromManyDistinctSitesStillRecordNothing()
    {
        $this->captureDeprecationMessages(function () {
            for ($i = 0; $i < 5; $i++) {
                DeprecationNotifier::notify(false, "Compat::method$i()", "Native::equivalent$i()");
            }
        });

        $this->assertSame([], DeprecationNotifier::notifiedSites());
    }

    // --- enabled: dedup by call site -----------------------------------------------------------

    public function testEnabledEmitsOnceForASingleCallSiteEvenWhenCalledTwice()
    {
        $messages = $this->captureDeprecationMessages(function () {
            for ($i = 0; $i < 2; $i++) {
                // Both iterations call notify() from this SAME source line -- the dedup key.
                DeprecationNotifier::notify(true, 'Compat::method()', 'Native::equivalent()');
            }
        });

        $this->assertCount(1, $messages);
    }

    public function testSecondCallFromTheSameSiteAfterTheFirstHasAlreadyReturnedEmitsNothing()
    {
        $messages = $this->captureDeprecationMessages(function () {
            $this->callFromOneLine();
            $this->callFromOneLine();
        });

        $this->assertCount(1, $messages);
    }

    private function callFromOneLine()
    {
        DeprecationNotifier::notify(true, 'Compat::method()', 'Native::equivalent()');
    }

    public function testTwoDistinctCallSitesEachEmitTheirOwnNotice()
    {
        $messages = $this->captureDeprecationMessages(function () {
            DeprecationNotifier::notify(true, 'Compat::method()', 'Native::equivalent()');
            DeprecationNotifier::notify(true, 'Compat::method()', 'Native::equivalent()');
        });

        $this->assertCount(2, $messages);
    }

    // --- message content -------------------------------------------------------------------------

    public function testMessageNamesTheCompatMethodTheNativeEquivalentAndTheGuideUrl()
    {
        $messages = $this->captureDeprecationMessages(function () {
            DeprecationNotifier::notify(
                true,
                'Univapay\Compat\UnivapayClient::createCharge()',
                'ChargesApi::createCharge()'
            );
        });

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Univapay\Compat\UnivapayClient::createCharge()', $messages[0]);
        $this->assertStringContainsString('ChargesApi::createCharge()', $messages[0]);
        $this->assertStringContainsString('native()', $messages[0]);
        $this->assertStringContainsString(
            'https://univapay.com/docs/#/http/onboarding-guides/guides/php-sdk-migration',
            $messages[0]
        );
    }

    // --- re-entrancy: cascade collapses to the outer call only -----------------------------------

    public function testInternalCascadeEmitsOnlyTheOuterNotice()
    {
        $messages = $this->captureDeprecationMessages(function () {
            $this->outerCompatCall();
        });

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Outer::call()', $messages[0]);
    }

    /**
     * Stands in for e.g. `UnivapayClient::createCharge()` calling `getTransactionToken()` then
     * `TransactionToken::createCharge()` internally -- both of those are themselves hooked public
     * entry points, but must not notify separately while nested inside this one.
     */
    private function outerCompatCall(): void
    {
        $guard = DeprecationNotifier::notify(true, 'Outer::call()', 'Native::outer()');
        $this->innerCompatCallOne();
        $this->innerCompatCallTwo();
    }

    private function innerCompatCallOne(): void
    {
        DeprecationNotifier::notify(true, 'Inner::callOne()', 'Native::innerOne()');
    }

    private function innerCompatCallTwo(): void
    {
        DeprecationNotifier::notify(true, 'Inner::callTwo()', 'Native::innerTwo()');
    }

    public function testGuardReleasesAfterTheOuterCallReturnsSoALaterIndependentCallCanNotifyOnItsOwn()
    {
        $messages = $this->captureDeprecationMessages(function () {
            $this->outerCompatCall();
            // A wholly separate, later top-level call (not nested inside the one above) -- the
            // guard must have released by now, or this would be silently swallowed too.
            $this->innerCompatCallOne();
        });

        $this->assertCount(2, $messages);
        $this->assertStringContainsString('Outer::call()', $messages[0]);
        $this->assertStringContainsString('Inner::callOne()', $messages[1]);
    }

    public function testNotifyReturnsAGuardInstanceWhenItActuallyEngages()
    {
        $guard = null;
        $this->captureDeprecationMessages(function () use (&$guard) {
            $guard = DeprecationNotifier::notify(true, 'Compat::method()', 'Native::equivalent()');
        });

        $this->assertInstanceOf(DeprecationNotifierGuard::class, $guard);
    }

    public function testNotifyReturnsNullWhenAlreadyNestedInsideAnotherGuard()
    {
        $nestedGuard = 'not-yet-set';
        $this->captureDeprecationMessages(function () use (&$nestedGuard) {
            $outerGuard = DeprecationNotifier::notify(true, 'Outer::call()', 'Native::outer()');
            $nestedGuard = DeprecationNotifier::notify(true, 'Inner::call()', 'Native::inner()');
        });

        $this->assertNull($nestedGuard);
    }
}
