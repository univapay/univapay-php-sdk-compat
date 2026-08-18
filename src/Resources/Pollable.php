<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources;

use Univapay\Compat\Support\DeprecationNotifier;

/**
 * Port of the old SDK's `Resources\Pollable` trait. Old `awaitResult()` issued a raw GET with
 * `polling=true` through `Utility\RequesterUtils` against a hand-built `RequestContext`. The new
 * transport engine's generated controllers each expose their own polling GET argument instead
 * (e.g. `ChargesApi::getCharge($storeId, $id, true)`), so this asks the concrete resource for its
 * own held-GET call via the abstract `fetchWithPolling()` -- which wraps `Support\ApiCaller` around
 * that controller call and hydrates a NEW instance, exactly like `Resource::fetch()` -- instead of
 * building a URL itself.
 *
 * Deliberately does NOT use the generated SDK's own `pollCharge()`/`pollRefund()`/`pollCancel()`/
 * `pollSubscription()` helpers: those have different terminal-status and `maxAttempts` semantics
 * than the per-status transition maps below (compare `ChargesApi::pollCharge()`'s hardcoded
 * transition table to `pollableStatuses()`), and using them here would silently change when
 * `awaitResult()` returns relative to the old SDK.
 *
 * No client-side `sleep()` between retries, matching old-SDK parity: every held GET already
 * blocks server-side for the polling window, so a client-side sleep on top of that would double
 * the wait for no benefit.
 */
trait Pollable
{
    /**
     * Map of [(string) $this->status => array of statuses that count as "transitioned away from
     * $this->status"]. Implemented per-resource (Charge, Refund, Cancel, Subscription); pinned
     * against fixtures copied verbatim from the old SDK in
     * tests/Fixtures/PollableStatusMaps.php + tests/Unit/Resources/PollableTransitionMapsTest.php
     * so a future port cannot silently drift from the old semantics.
     *
     * @return array<string, array>
     */
    abstract protected function pollableStatuses();

    /**
     * Issues one held GET (`polling=true`) via the concrete resource's own `Support\ApiCaller`
     * call and returns a NEW hydrated instance -- the "response" `awaitResult()` inspects for a
     * status transition, analogous to `Resource::fetch()`.
     *
     * @return static
     */
    abstract protected function fetchWithPolling();

    /**
     * @return string Native-SDK equivalent for `awaitResult()`, e.g. `'ChargesApi::pollCharge()'`
     *         -- see `Support\DeprecationNotifier`'s class doc. Implemented per-class (Charge,
     *         Refund, Cancel, Subscription each has its own generated `pollX()` helper;
     *         `TransactionToken` also implements it, for the handful of `abstract` methods this
     *         trait requires, even though its own `awaitResult()` override never calls it -- see
     *         that class's own implementation).
     */
    abstract protected function nativePollEquivalent(): string;

    /**
     * @param int $retry Maximum number of ADDITIONAL held-GET attempts beyond the first, once a
     *        transition out of the resource's current status has not yet been observed.
     * @return static
     */
    public function awaitResult($retry = 0)
    {
        $deprecationNotice = DeprecationNotifier::notify(
            $this->getBridge()->deprecationNoticesEnabled(),
            static::class . '::awaitResult()',
            $this->nativePollEquivalent()
        );
        $pollableStatuses = $this->pollableStatuses();
        $response = $this->fetchWithPolling();
        $retryCount = 0;
        while (
            $retryCount < $retry &&
            array_key_exists($this->status->__toString(), $pollableStatuses) &&
            !in_array($response->status, $pollableStatuses[$this->status->__toString()])
        ) {
            $retryCount++;
            $response = $this->fetchWithPolling();
        }
        return $response;
    }
}
