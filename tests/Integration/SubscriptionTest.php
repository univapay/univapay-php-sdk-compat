<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Integration;

use DateTime;
use Money\Currency;
use Money\Money;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Enums\Period;
use Univapay\Compat\Enums\SubscriptionStatus;
use Univapay\Compat\Resources\Charge;
use Univapay\Compat\Resources\Paginated;
use Univapay\Compat\Resources\SimpleList;
use Univapay\Compat\Resources\Subscription;
use Univapay\Compat\Resources\Subscription\ScheduledPayment;

/**
 * @group integration
 *
 * Round-trips: createSubscription() (client-level two-step + token-level direct), getSubscription(),
 * getLatestChargeForSubscription(), patch(), cancel(), createSubscriptionSimulation() (its
 * bare-array response), listCharges(), listScheduledPayments().
 */
class SubscriptionTest extends IntegrationTestCase
{
    private function assertCommonSubscriptionShape(Subscription $subscription): void
    {
        $this->assertNotNull($subscription->id);
        $this->assertInstanceOf(Currency::class, $subscription->currency);
        $this->assertInstanceOf(Money::class, $subscription->amount);
        $this->assertInstanceOf(SubscriptionStatus::class, $subscription->status);
        $this->assertInstanceOf(AppTokenMode::class, $subscription->mode);
        $this->assertInstanceOf(DateTime::class, $subscription->createdOn);
    }

    public function testCreateSubscriptionViaClientTwoStepFlow(): void
    {
        $subscription = $this->storeClient()->createSubscription(
            self::TOKEN_ID,
            Money::JPY(1000),
            Period::MONTHLY()
        );

        $this->assertCommonSubscriptionShape($subscription);
    }

    public function testCreateSubscriptionViaTokenDirectly(): void
    {
        $token = $this->storeClient()->getTransactionToken(self::TOKEN_ID);

        $subscription = $token->createSubscription(Money::JPY(1000), Period::MONTHLY());

        $this->assertCommonSubscriptionShape($subscription);
    }

    public function testGetSubscriptionReturnsATypedSubscription(): void
    {
        $subscription = $this->storeClient()->getSubscription(self::STORE_ID, self::SUBSCRIPTION_ID);

        $this->assertInstanceOf(Subscription::class, $subscription);
        $this->assertSame(self::SUBSCRIPTION_ID, $subscription->id);
        $this->assertCommonSubscriptionShape($subscription);
    }

    public function testGetLatestChargeForSubscriptionReturnsATypedCharge(): void
    {
        $charge = $this->storeClient()->getLatestChargeForSubscription(self::STORE_ID, self::SUBSCRIPTION_ID);

        $this->assertInstanceOf(Charge::class, $charge);
        $this->assertInstanceOf(Money::class, $charge->requestedAmount);
    }

    public function testPatchMetadataOnlyReturnsANewInstance(): void
    {
        $subscription = $this->storeClient()->getSubscription(self::STORE_ID, self::SUBSCRIPTION_ID);

        // Metadata-only patch: every other patch() guard is gated on its OWN argument being set
        // (isTokenPatchable()/isEditable()/status-transition/plan-already-set all `isset(...)`
        // gated -- see Subscription::patch()'s own source), so passing null for everything except
        // metadata exercises the method without depending on this subscription's current
        // isEditable()/isTokenPatchable() state.
        $patched = $subscription->patch(null, null, null, null, null, ['note' => 'integration-test']);

        $this->assertInstanceOf(Subscription::class, $patched);
        $this->assertNotSame($subscription, $patched);
    }

    public function testCancelReturnsTrueOnANonTerminalSubscription(): void
    {
        $subscription = $this->storeClient()->getSubscription(self::STORE_ID, self::SUBSCRIPTION_ID);
        $this->assertFalse($subscription->isTerminal(), 'fixture subscription is expected non-terminal ("current")');

        $result = $subscription->cancel();

        $this->assertTrue($result);
    }

    /**
     * Bare JSON ARRAY response (no `{items, has_more}` envelope) -- hydrated into a
     * `Resources\SimpleList` of `ScheduledPayment`, matching old-SDK parity (no cursor for this
     * endpoint at all).
     */
    public function testCreateSubscriptionSimulationReturnsASimpleListOfScheduledPayments(): void
    {
        $simulation = $this->storeClient()->createSubscriptionSimulation(
            PaymentType::CARD(),
            Money::JPY(1000),
            Period::MONTHLY()
        );

        $this->assertInstanceOf(SimpleList::class, $simulation);
        $this->assertIsArray($simulation->items);
        foreach ($simulation->items as $item) {
            $this->assertInstanceOf(ScheduledPayment::class, $item);
            $this->assertInstanceOf(Money::class, $item->amount);
        }
    }

    public function testListChargesReturnsAPaginatedPageOfTypedCharges(): void
    {
        $subscription = $this->storeClient()->getSubscription(self::STORE_ID, self::SUBSCRIPTION_ID);

        $page = $subscription->listCharges();

        $this->assertInstanceOf(Paginated::class, $page);
        foreach ($page->items as $item) {
            $this->assertInstanceOf(Charge::class, $item);
        }
    }

    public function testListScheduledPaymentsReturnsAPaginatedPageOfTypedScheduledPayments(): void
    {
        $subscription = $this->storeClient()->getSubscription(self::STORE_ID, self::SUBSCRIPTION_ID);

        $page = $subscription->listScheduledPayments();

        $this->assertInstanceOf(Paginated::class, $page);
        foreach ($page->items as $item) {
            $this->assertInstanceOf(ScheduledPayment::class, $item);
        }
    }
}
