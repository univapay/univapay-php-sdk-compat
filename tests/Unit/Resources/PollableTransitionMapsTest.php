<?php

namespace Univapay\Compat\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\CancelStatus;
use Univapay\Compat\Enums\ChargeStatus;
use Univapay\Compat\Enums\RefundStatus;
use Univapay\Compat\Enums\SubscriptionStatus;
use Univapay\Compat\Tests\Fixtures\PollableStatusMaps;

/**
 * Pins the transition maps `Charge`/`Refund`/`Cancel`/`Subscription`'s own `pollableStatuses()`
 * reproduce -- see tests/Fixtures/PollableStatusMaps.php. This asserts the pinned fixture's own
 * structure against the old SDK's exact hand-transcribed expressions, so a mistake made in THIS
 * transcription would be caught here rather than silently propagating into whatever imports it.
 */
class PollableTransitionMapsTest extends TestCase
{
    public function testChargeMapKeysAndPendingExcludesItself()
    {
        $map = PollableStatusMaps::charge();

        $this->assertSame(
            [(string) ChargeStatus::PENDING(), (string) ChargeStatus::AUTHORIZED(), (string) ChargeStatus::AWAITING()],
            array_keys($map)
        );

        $pendingTargets = $map[(string) ChargeStatus::PENDING()];
        $this->assertCount(6, $pendingTargets);
        $this->assertNotContains(ChargeStatus::PENDING(), $pendingTargets);
    }

    public function testChargeAuthorizedTargetsExcludeAwaitingAndItself()
    {
        // Hand-transcribed as an explicit list in the old SDK (not array_diff), and deliberately
        // does NOT include AWAITING -- an authorized charge cannot regress to awaiting.
        $map = PollableStatusMaps::charge();
        $targets = $map[(string) ChargeStatus::AUTHORIZED()];

        $this->assertEquals([
            ChargeStatus::SUCCESSFUL(),
            ChargeStatus::FAILED(),
            ChargeStatus::ERROR(),
            ChargeStatus::CANCELED()
        ], array_values($targets));
    }

    public function testChargeAwaitingTargetsIncludeAuthorized()
    {
        $map = PollableStatusMaps::charge();
        $targets = $map[(string) ChargeStatus::AWAITING()];

        $this->assertEquals([
            ChargeStatus::AUTHORIZED(),
            ChargeStatus::SUCCESSFUL(),
            ChargeStatus::FAILED(),
            ChargeStatus::ERROR(),
            ChargeStatus::CANCELED()
        ], array_values($targets));
    }

    public function testRefundMapHasOnlyAPendingKeyExcludingItself()
    {
        $map = PollableStatusMaps::refund();

        $this->assertSame([(string) RefundStatus::PENDING()], array_keys($map));
        $targets = $map[(string) RefundStatus::PENDING()];
        $this->assertCount(3, $targets);
        $this->assertNotContains(RefundStatus::PENDING(), $targets);
    }

    public function testCancelMapHasOnlyAPendingKeyExcludingItself()
    {
        $map = PollableStatusMaps::cancel();

        $this->assertSame([(string) CancelStatus::PENDING()], array_keys($map));
        $targets = $map[(string) CancelStatus::PENDING()];
        $this->assertCount(3, $targets);
        $this->assertNotContains(CancelStatus::PENDING(), $targets);
    }

    public function testSubscriptionMapKeysAreUnverifiedAndAuthorized()
    {
        $map = PollableStatusMaps::subscription();

        $this->assertSame(
            [(string) SubscriptionStatus::UNVERIFIED(), (string) SubscriptionStatus::AUTHORIZED()],
            array_keys($map)
        );
    }

    public function testSubscriptionUnverifiedTargetsExcludeOnlyItself()
    {
        $map = PollableStatusMaps::subscription();
        $targets = $map[(string) SubscriptionStatus::UNVERIFIED()];

        $this->assertCount(7, $targets);
        $this->assertNotContains(SubscriptionStatus::UNVERIFIED(), $targets);
        $this->assertContains(SubscriptionStatus::AUTHORIZED(), $targets);
    }

    public function testSubscriptionAuthorizedTargetsExcludeUnverifiedAndItself()
    {
        $map = PollableStatusMaps::subscription();
        $targets = $map[(string) SubscriptionStatus::AUTHORIZED()];

        $this->assertCount(6, $targets);
        $this->assertNotContains(SubscriptionStatus::UNVERIFIED(), $targets);
        $this->assertNotContains(SubscriptionStatus::AUTHORIZED(), $targets);
    }
}
