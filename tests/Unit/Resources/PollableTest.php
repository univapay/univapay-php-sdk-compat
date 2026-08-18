<?php

namespace Univapay\Compat\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\ChargeStatus;
use Univapay\Compat\Resources\Pollable;
use Univapay\Compat\Tests\Support\NoticesDisabledBridgeStub;

/**
 * `Pollable::awaitResult()` -- verbatim old-SDK loop semantics (see class doc), exercised
 * against a fixture that queues canned "held GET" responses instead of making real calls, and
 * asserting: no client-side delay is introduced (`fetchWithPolling()` is called exactly as many
 * times as the loop's own bookkeeping predicts, nothing more), a transition ends the loop early,
 * and exhausting `$retry` returns the last-seen (still non-transitioned) response rather than
 * throwing.
 */
class PollableTest extends TestCase
{
    public function testRetryZeroCallsFetchWithPollingExactlyOnce()
    {
        $resource = new PollableFixture(ChargeStatus::PENDING(), [
            new PollResponse(ChargeStatus::PENDING())
        ]);

        $result = $resource->awaitResult(0);

        $this->assertSame(1, $resource->callCount());
        $this->assertSame(ChargeStatus::PENDING(), $result->status);
    }

    public function testStopsAsSoonAsATransitionIsObserved()
    {
        $resource = new PollableFixture(ChargeStatus::PENDING(), [
            new PollResponse(ChargeStatus::PENDING()),
            new PollResponse(ChargeStatus::PENDING()),
            new PollResponse(ChargeStatus::AUTHORIZED())
        ]);

        $result = $resource->awaitResult(5);

        // 1 initial + 2 retries = 3 calls, even though up to 5 retries were allowed.
        $this->assertSame(3, $resource->callCount());
        $this->assertSame(ChargeStatus::AUTHORIZED(), $result->status);
    }

    public function testExhaustingRetryReturnsTheLastResponseEvenIfStillNotTransitioned()
    {
        $resource = new PollableFixture(ChargeStatus::PENDING(), [
            new PollResponse(ChargeStatus::PENDING()),
            new PollResponse(ChargeStatus::PENDING()),
            new PollResponse(ChargeStatus::PENDING())
        ]);

        $result = $resource->awaitResult(2);

        // 1 initial + 2 retries = 3 calls (the retry budget), not a failure.
        $this->assertSame(3, $resource->callCount());
        $this->assertSame(ChargeStatus::PENDING(), $result->status);
    }

    public function testAStatusAbsentFromPollableStatusesNeverLoopsRegardlessOfRetry()
    {
        $resource = new PollableFixture(ChargeStatus::SUCCESSFUL(), [
            new PollResponse(ChargeStatus::SUCCESSFUL())
        ], []); // empty pollableStatuses() map -- SUCCESSFUL is a final status, not pollable

        $result = $resource->awaitResult(10);

        $this->assertSame(1, $resource->callCount());
        $this->assertSame(ChargeStatus::SUCCESSFUL(), $result->status);
    }
}

/**
 * Test-only value holder standing in for a hydrated resource instance returned by
 * `fetchWithPolling()` -- only needs a public `$status`, same as a real ported resource.
 */
class PollResponse
{
    public $status;

    public function __construct($status)
    {
        $this->status = $status;
    }
}

/**
 * Test-only Pollable user. Queues canned responses instead of calling Support\ApiCaller, so the
 * loop mechanics in Pollable itself can be asserted in isolation.
 */
class PollableFixture
{
    use Pollable;

    public $status;

    /** @var PollResponse[] */
    private $queue;

    /** @var array */
    private $statuses;

    private $calls = 0;

    public function __construct($status, array $queue, ?array $statuses = null)
    {
        $this->status = $status;
        $this->queue = $queue;
        $this->statuses = $statuses ?? [
            (string) ChargeStatus::PENDING() => array_diff(ChargeStatus::findValues(), [ChargeStatus::PENDING()])
        ];
    }

    public function callCount(): int
    {
        return $this->calls;
    }

    protected function getBridge()
    {
        return new NoticesDisabledBridgeStub();
    }

    protected function nativePollEquivalent(): string
    {
        return 'ChargesApi::pollCharge()';
    }

    protected function pollableStatuses()
    {
        return $this->statuses;
    }

    protected function fetchWithPolling()
    {
        $this->calls++;
        return array_shift($this->queue);
    }
}
