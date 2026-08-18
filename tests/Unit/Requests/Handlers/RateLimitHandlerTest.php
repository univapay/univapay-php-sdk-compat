<?php

namespace Univapay\Compat\Tests\Unit\Requests\Handlers;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Errors\UnivapayRateLimitedError;
use Univapay\Compat\Requests\Handlers\BasicRetryHandler;
use Univapay\Compat\Requests\Handlers\RateLimitHandler;

class RateLimitHandlerTest extends TestCase
{
    public function testIsABasicRetryHandlerTargetingUnivapayRateLimitedError()
    {
        $this->assertInstanceOf(BasicRetryHandler::class, new RateLimitHandler());
    }

    public function testRetriesOnUnivapayRateLimitedErrorThenSucceeds()
    {
        $handler = new RateLimitHandler(1, 0);
        $calls = 0;

        $result = $handler->handle(function () use (&$calls) {
            $calls++;
            if ($calls < 2) {
                throw new UnivapayRateLimitedError('https://api.univapay.com/charges');
            }
            return 'ok';
        }, []);

        $this->assertSame('ok', $result);
        $this->assertSame(2, $calls);
    }

    public function testDoesNotCatchUnrelatedErrors()
    {
        $handler = new RateLimitHandler(3, 0);

        $this->expectException(\RuntimeException::class);

        $handler->handle(function () {
            throw new \RuntimeException('not a rate limit error');
        }, []);
    }
}
