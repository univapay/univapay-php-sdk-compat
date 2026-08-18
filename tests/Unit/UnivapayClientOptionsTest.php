<?php

namespace Univapay\Compat\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Requests\Handlers\NetworkRetryHandler;
use Univapay\Compat\Requests\Handlers\RateLimitHandler;
use Univapay\Compat\UnivapayClientOptions;

class UnivapayClientOptionsTest extends TestCase
{
    public function testDefaultsMatchOldSdk()
    {
        $options = new UnivapayClientOptions();

        $this->assertSame('https://api.univapay.com', $options->endpoint);
        $this->assertInstanceOf(RateLimitHandler::class, $options->rateLimitHandler);
        $this->assertInstanceOf(NetworkRetryHandler::class, $options->networkRetryHandler);
    }

    public function testDeprecationNoticesDefaultsToFalse()
    {
        $options = new UnivapayClientOptions();

        $this->assertFalse($options->deprecationNotices);
    }

    public function testDeprecationNoticesIsSettable()
    {
        $options = new UnivapayClientOptions();

        $options->deprecationNotices = true;

        $this->assertTrue($options->deprecationNotices);
    }

    public function testEndpointIsOverridable()
    {
        $options = new UnivapayClientOptions('https://staging.example.com');

        $this->assertSame('https://staging.example.com', $options->endpoint);
    }

    public function testGetRequestHandlersOrderIsRateLimitInnerThenNetworkRetryOuter()
    {
        // ApiCaller::encapsulate()/old HttpRequester::encapsulate() both treat the LAST array
        // element as outermost -- this order is what makes NetworkRetryHandler outermost /
        // RateLimitHandler inner (see plan "Handler cascade contract").
        $options = new UnivapayClientOptions();

        $handlers = $options->getRequestHandlers();

        $this->assertCount(2, $handlers);
        $this->assertSame($options->rateLimitHandler, $handlers[0]);
        $this->assertSame($options->networkRetryHandler, $handlers[1]);
    }

    public function testGetRequestHandlersStripsNullHandlers()
    {
        $options = new UnivapayClientOptions();
        $options->rateLimitHandler = null;

        $handlers = $options->getRequestHandlers();

        $this->assertCount(1, $handlers);
        $this->assertSame($options->networkRetryHandler, array_values($handlers)[0]);
    }
}
