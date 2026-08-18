<?php

namespace Univapay\Compat\Tests\Unit\Requests\Handlers;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Errors\UnivapayNetworkError;
use Univapay\Compat\Requests\Handlers\BasicRetryHandler;
use Univapay\Compat\Requests\Handlers\NetworkRetryHandler;

/**
 * See plan blocker 4: this handler targets `Univapay\Compat\Errors\UnivapayNetworkError`, NOT
 * the old SDK's `WpOrg\Requests\Exception` (a class that does not exist post-migration and, if
 * targeted, would leave this handler's retry loop silently dead).
 */
class NetworkRetryHandlerTest extends TestCase
{
    public function testIsABasicRetryHandlerTargetingUnivapayNetworkError()
    {
        $this->assertInstanceOf(BasicRetryHandler::class, new NetworkRetryHandler());
    }

    public function testRetriesOnUnivapayNetworkErrorThenSucceeds()
    {
        $handler = new NetworkRetryHandler(2, 0);
        $calls = 0;

        $result = $handler->handle(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw new UnivapayNetworkError('https://api.univapay.com/charges', 'connection refused');
            }
            return 'ok';
        }, []);

        $this->assertSame('ok', $result);
        $this->assertSame(3, $calls);
    }

    public function testExhaustingRetriesLetsTheFinalNetworkErrorPropagate()
    {
        $handler = new NetworkRetryHandler(1, 0);
        $calls = 0;

        $this->expectException(UnivapayNetworkError::class);

        try {
            $handler->handle(function () use (&$calls) {
                $calls++;
                throw new UnivapayNetworkError('https://api.univapay.com/charges', 'still down');
            }, []);
        } finally {
            // 1 retried attempt + the mandatory final attempt = 2 total invocations.
            $this->assertSame(2, $calls);
        }
    }

    public function testDoesNotCatchUnrelatedErrors()
    {
        $handler = new NetworkRetryHandler(3, 0);

        $this->expectException(\RuntimeException::class);

        $handler->handle(function () {
            throw new \RuntimeException('not a network error');
        }, []);
    }
}
