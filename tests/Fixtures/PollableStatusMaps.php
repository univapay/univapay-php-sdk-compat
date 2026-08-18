<?php

namespace Univapay\Compat\Tests\Fixtures;

use Univapay\Compat\Enums\CancelStatus;
use Univapay\Compat\Enums\ChargeStatus;
use Univapay\Compat\Enums\RefundStatus;
use Univapay\Compat\Enums\SubscriptionStatus;

/**
 * Pins the exact `pollableStatuses()` map-building expressions from the old SDK's `Charge`,
 * `Refund`, `Cancel`, and `Subscription` resources, transcribed verbatim and reproduced here
 * against the already-ported enum classes.
 *
 * `Charge`, `Refund`, `Cancel`, and `Subscription`'s own `pollableStatuses()` implementations are
 * each asserted equal to what is pinned here (see their respective
 * `testPollableStatusesMatchesThePinnedFixture()` tests), so the map cannot silently drift from
 * old-SDK semantics. See tests/Unit/Resources/PollableTransitionMapsTest.php for the invariants
 * pinned against these maps directly.
 */
final class PollableStatusMaps
{
    public static function charge(): array
    {
        return [
            (string) ChargeStatus::PENDING() => array_diff(ChargeStatus::findValues(), [ChargeStatus::PENDING()]),
            (string) ChargeStatus::AUTHORIZED() => [
                ChargeStatus::SUCCESSFUL(),
                ChargeStatus::FAILED(),
                ChargeStatus::ERROR(),
                ChargeStatus::CANCELED()
            ],
            (string) ChargeStatus::AWAITING() => [
                ChargeStatus::AUTHORIZED(),
                ChargeStatus::SUCCESSFUL(),
                ChargeStatus::FAILED(),
                ChargeStatus::ERROR(),
                ChargeStatus::CANCELED()
            ]
        ];
    }

    public static function refund(): array
    {
        return [
            (string) RefundStatus::PENDING() => array_diff(RefundStatus::findValues(), [RefundStatus::PENDING()])
        ];
    }

    public static function cancel(): array
    {
        return [
            (string) CancelStatus::PENDING() => array_diff(CancelStatus::findValues(), [CancelStatus::PENDING()])
        ];
    }

    public static function subscription(): array
    {
        return [
            (string) SubscriptionStatus::UNVERIFIED() => array_diff(
                SubscriptionStatus::findValues(),
                [SubscriptionStatus::UNVERIFIED()]
            ),
            (string) SubscriptionStatus::AUTHORIZED() => array_diff(
                SubscriptionStatus::findValues(),
                [SubscriptionStatus::UNVERIFIED(), SubscriptionStatus::AUTHORIZED()]
            )
        ];
    }
}
