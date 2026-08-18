<?php

namespace Univapay\Compat\Tests\Unit\Requests\Handlers;

use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Univapay\Compat\Requests\Handlers\BasicRetryHandler;

/**
 * Ported (namespace-only) old-SDK BasicRetryHandler. The key invariant under test is the
 * "$tries retries PLUS one final, uncaught attempt" shape (see plan blocker 1 note): with
 * $tries = N, the request closure is invoked up to N+1 times, and the (N+1)th invocation's
 * exception (if it also matches) is NOT caught -- it propagates.
 */
class BasicRetryHandlerTest extends TestCase
{
    public function testSucceedsWithoutRetryWhenFirstAttemptSucceeds()
    {
        $handler = new BasicRetryHandler(RuntimeException::class, 3, 0);
        $calls = 0;

        $result = $handler->handle(function () use (&$calls) {
            $calls++;
            return 'ok';
        }, []);

        $this->assertSame('ok', $result);
        $this->assertSame(1, $calls);
    }

    public function testRetriesUpToTriesThenSucceedsOnFinalAttempt()
    {
        // tries=2: attempts 1 and 2 fail and are caught+retried; the 3rd call (the "+1 final
        // attempt" outside the while loop) succeeds.
        $handler = new BasicRetryHandler(RuntimeException::class, 2, 0);
        $calls = 0;

        $result = $handler->handle(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw new RuntimeException('transient');
            }
            return 'ok';
        }, []);

        $this->assertSame('ok', $result);
        $this->assertSame(3, $calls);
    }

    public function testFinalAttemptExceptionIsNotCaughtAndPropagates()
    {
        // tries=1: attempt 1 fails and is retried; the "+1 final attempt" (attempt 2) also
        // fails, and that failure is NOT caught by the loop -- it must propagate to the caller.
        $handler = new BasicRetryHandler(RuntimeException::class, 1, 0);
        $calls = 0;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('still failing');

        try {
            $handler->handle(function () use (&$calls) {
                $calls++;
                throw new RuntimeException('still failing');
            }, []);
        } finally {
            $this->assertSame(2, $calls);
        }
    }

    public function testNonMatchingExceptionIsNeverCaught()
    {
        $handler = new BasicRetryHandler(RuntimeException::class, 3, 0);
        $calls = 0;

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('unrelated');

        try {
            $handler->handle(function () use (&$calls) {
                $calls++;
                throw new Exception('unrelated');
            }, []);
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testFilterCanVetoARetryEvenForAMatchingException()
    {
        $handler = new BasicRetryHandler(RuntimeException::class, 3, 0, function () {
            return false;
        });
        $calls = 0;

        $this->expectException(RuntimeException::class);

        try {
            $handler->handle(function () use (&$calls) {
                $calls++;
                throw new RuntimeException('vetoed');
            }, []);
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testExceptionClassMayBeGivenAsAString()
    {
        // Old SDK's ctor accepts a string OR a ::class constant -- both must work identically,
        // since the migrate package's Rector ruleset has a dedicated string-FQCN rename rule
        // precisely because consumer code sometimes passes the class name as a literal string.
        $handler = new BasicRetryHandler('RuntimeException', 1, 0);
        $calls = 0;

        $result = $handler->handle(function () use (&$calls) {
            $calls++;
            if ($calls < 2) {
                throw new RuntimeException('transient');
            }
            return 'ok';
        }, []);

        $this->assertSame('ok', $result);
    }

    public function testPassesRequestDataThroughUnchanged()
    {
        $handler = new BasicRetryHandler(RuntimeException::class, 1, 0);
        $seen = null;

        $handler->handle(function (array $requestData) use (&$seen) {
            $seen = $requestData;
            return 'ok';
        }, ['a', 'b']);

        $this->assertSame(['a', 'b'], $seen);
    }
}
