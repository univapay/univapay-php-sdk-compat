<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources;

use DateInterval;
use DateTimeZone;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use UnivaPay\Apis\SubscriptionsApi;
use UnivaPay\Http\ApiResponse;
use UnivaPay\Models\SubscriptionUpdateRequest;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\Period;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Enums\SubscriptionPlanType;
use Univapay\Compat\Enums\SubscriptionStatus;
use Univapay\Compat\Enums\InstallmentPlanType;
use Univapay\Compat\Errors\UnivapayLogicError;
use Univapay\Compat\Errors\UnivapayValidationError;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Resources\Subscription;
use Univapay\Compat\Resources\Subscription\InstallmentPlan;
use Univapay\Compat\Resources\Subscription\ScheduledPayment;
use Univapay\Compat\Support\ApiCaller;
use Univapay\Compat\Support\Bridge;
use Univapay\Compat\Support\CompatContext;
use Univapay\Compat\Tests\Fixtures\PollableStatusMaps;

/**
 * Covers `Subscription`: hydration (JSON fixture lifted from the old SDK's
 * `tests/Univapay/Integration/SubscriptionTest.php::testSubscriptionWithPeriodParse`),
 * `patch()`'s full preflight-guard suite + the `RequestModelFactory::subscriptionPatch()`
 * spec-gap passthrough it drives, `cancel()`'s SUBSCRIPTION_ALREADY_ENDED guard, the pure
 * `isEditable()`/`isProcessing()`/`isTokenPatchable()`/`isTerminal()`/`isSubscribable()` ports, and
 * `pollableStatuses()` pinned against `tests/Fixtures/PollableStatusMaps::subscription()` (this
 * map is carried over unchanged from the old SDK and has not been re-verified against the
 * current backend -- see `Subscription`'s own class doc).
 */
class SubscriptionTest extends TestCase
{
    private function token(array $payload): string
    {
        $header = base64_encode((string) json_encode(['alg' => 'none']));
        $body = base64_encode((string) json_encode($payload));
        return "$header.$body.sig";
    }

    private function bridge(): Bridge
    {
        $jwt = $this->token([
            'sub' => 'app_token',
            'iat' => 1,
            'merchant_id' => 'merchant-1',
            'store_id' => 'store-1',
            'domains' => [],
            'mode' => 'test',
            'creator_id' => 'creator-1',
            'version' => 1,
            'jti' => 'jti-1'
        ]);
        return new Bridge(AppJWT::createToken($jwt, 'secret-1'));
    }

    private function context(?Bridge $bridge = null): CompatContext
    {
        return new CompatContext($bridge ?? $this->bridge(), 'store-1');
    }

    private function injectFakeSubscriptionsApi(Bridge $bridge, SubscriptionsApi $fake): void
    {
        $property = new ReflectionProperty(Bridge::class, 'subscriptionsApi');
        $property->setAccessible(true);
        $property->setValue($bridge, $fake);
    }

    // JSON payload lifted verbatim (values only -- heredoc-with-indented-closing-marker is a
    // PHP 7.3+ feature, unusable on this package's 7.2 floor) from the old SDK's
    // SubscriptionTest::testSubscriptionWithPeriodParse fixture.
    private function subscriptionJson(array $overrides = []): array
    {
        $json = [
            'id' => '11111111-1111-1111-1111-111111111111',
            'store_id' => '22222222-2222-2222-2222-222222222222',
            'transaction_token_id' => '33333333-3333-3333-3333-333333333333',
            'amount' => 1000,
            'currency' => 'JPY',
            'amount_formatted' => 1000,
            'period' => 'monthly',
            'initial_amount' => 100,
            'initial_amount_formatted' => 100,
            'schedule_settings' => [
                'start_on' => '2017-07-31',
                'zone_id' => 'Asia/Tokyo',
                'preserve_end_of_month' => true,
                'retry_interval' => 'P5D'
            ],
            'next_payment' => [
                'id' => '11e893e1-2842-3cea-b0a8-47819043c1eb',
                'due_date' => '2018-08-30',
                'zone_id' => 'Asia/Tokyo',
                'amount' => 1000,
                'currency' => 'JPY',
                'amount_formatted' => 1000,
                'is_paid' => false,
                'is_last_payment' => false,
                'created_on' => '2018-07-31T10:13:08.715295Z'
            ],
            'first_charge_authorization_only' => true,
            'first_charge_capture_after' => 'PT5H',
            'payments_left' => 9,
            'status' => 'current',
            'subscription_plan' => [
                'plan_type' => 'fixed_cycle_amount',
                'fixed_cycle_amount' => 1000
            ],
            'amount_left' => 5000,
            'amount_left_formatted' => 5000,
            'metadata' => [],
            'mode' => 'test',
            'created_on' => '2017-07-04T06:06:05.580391Z'
        ];
        return array_replace($json, $overrides);
    }

    private function parseSubscription(array $json, ?CompatContext $context = null): Subscription
    {
        return Subscription::getSchema()->parse($json, [$context ?? $this->context()]);
    }

    // --- hydration --------------------------------------------------------------------------------

    public function testHydratesASubscriptionWithPeriodAndNestedScheduledPaymentAndPlan()
    {
        $subscription = $this->parseSubscription($this->subscriptionJson());

        $this->assertSame('11111111-1111-1111-1111-111111111111', $subscription->id);
        $this->assertSame('22222222-2222-2222-2222-222222222222', $subscription->storeId);
        $this->assertEquals(new Money(1000, new Currency('JPY')), $subscription->amount);
        $this->assertEquals(Period::MONTHLY(), $subscription->period);
        $this->assertEquals(new Money(100, new Currency('JPY')), $subscription->initialAmount);
        $this->assertEquals(date_create('2017-07-31'), $subscription->scheduleSettings->startOn);
        $this->assertEquals(new DateTimeZone('Asia/Tokyo'), $subscription->scheduleSettings->zoneId);
        $this->assertTrue($subscription->scheduleSettings->preserveEndOfMonth);
        $this->assertEquals(new DateInterval('P5D'), $subscription->scheduleSettings->retryInterval);
        $this->assertEquals(SubscriptionStatus::CURRENT(), $subscription->status);
        $this->assertEquals(AppTokenMode::TEST(), $subscription->mode);
        $this->assertInstanceOf(ScheduledPayment::class, $subscription->nextPayment);
        $this->assertEquals(SubscriptionPlanType::FIXED_CYCLE_AMOUNT(), $subscription->subscriptionPlan->planType);
        $this->assertEquals(new Money(1000, new Currency('JPY')), $subscription->subscriptionPlan->fixedCycleAmount);
        $this->assertTrue($subscription->firstChargeAuthorizationOnly);
        $this->assertEquals(new DateInterval('PT5H'), $subscription->firstChargeCaptureAfter);
    }

    public function testHydratesASubscriptionWithCyclicalPeriod()
    {
        $json = $this->subscriptionJson(['period' => null, 'cyclical_period' => 'P15D']);
        unset($json['period']);
        $subscription = $this->parseSubscription($json);

        $this->assertNull($subscription->period);
        $this->assertEquals(new DateInterval('P15D'), $subscription->cyclicalPeriod);
    }

    // --- patch(): guards ---------------------------------------------------------------------------

    public function testPatchOnACanceledSubscriptionAlwaysThrows()
    {
        $subscription = $this->parseSubscription($this->subscriptionJson(['status' => 'canceled']));

        $this->expectException(UnivapayLogicError::class);

        $subscription->patch();
    }

    public function testPatchingTransactionTokenWhenNotTokenPatchableThrows()
    {
        // COMPLETED is neither UNCONFIRMED/UNPAID/CURRENT/SUSPENDED -> not token-patchable.
        $subscription = $this->parseSubscription($this->subscriptionJson(['status' => 'completed']));

        try {
            $subscription->patch('new-token-id');
            $this->fail('Expected a UnivapayLogicError');
        } catch (UnivapayLogicError $e) {
            $this->assertSame('cannot_change_token', $e->code);
        }
    }

    public function testSettingPeriodWhenNotEditableThrows()
    {
        // CURRENT is not UNVERIFIED/UNCONFIRMED -> not editable.
        $subscription = $this->parseSubscription($this->subscriptionJson(['status' => 'current']));

        try {
            $subscription->patch(null, null, Period::WEEKLY());
            $this->fail('Expected a UnivapayLogicError');
        } catch (UnivapayLogicError $e) {
            $this->assertSame('cannot_set_after_subscription_started', $e->code);
        }
    }

    public function testStatusTransitionFromCurrentMustBeSuspended()
    {
        $subscription = $this->parseSubscription($this->subscriptionJson(['status' => 'current']));

        try {
            $subscription->patch(null, null, null, null, SubscriptionStatus::CANCELED());
            $this->fail('Expected a UnivapayValidationError');
        } catch (UnivapayValidationError $e) {
            $this->assertSame('status', $e->errors['field']);
            $this->assertSame('forbidden_parameter', $e->errors['reason']);
        }
    }

    public function testStatusTransitionFromSuspendedMustBeUnpaid()
    {
        $subscription = $this->parseSubscription($this->subscriptionJson(['status' => 'suspended']));

        try {
            $subscription->patch(null, null, null, null, SubscriptionStatus::CURRENT());
            $this->fail('Expected a UnivapayValidationError');
        } catch (UnivapayValidationError $e) {
            $this->assertSame('forbidden_parameter', $e->errors['reason']);
        }
    }

    public function testStatusChangeFromAnyOtherStatusIsAlwaysForbidden()
    {
        // UNCONFIRMED hits the switch's `default` branch (only UNPAID/CURRENT/SUSPENDED have their
        // own case) -- any status change attempt from here is unconditionally forbidden.
        $subscription = $this->parseSubscription($this->subscriptionJson(['status' => 'unconfirmed']));

        $this->expectException(UnivapayValidationError::class);

        $subscription->patch(null, null, null, null, SubscriptionStatus::SUSPENDED());
    }

    public function testSettingPlanWhenNotEditableThrows()
    {
        $subscription = $this->parseSubscription($this->subscriptionJson(['status' => 'current']));

        try {
            $subscription->patch(
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                new InstallmentPlan(InstallmentPlanType::REVOLVING())
            );
            $this->fail('Expected a UnivapayLogicError');
        } catch (UnivapayLogicError $e) {
            $this->assertSame('plan_already_set', $e->code);
        }
    }

    // --- patch(): success path, builds SubscriptionUpdateRequest + spec-gap passthrough ----------

    public function testPatchSuccessBuildsRequestAndHydratesTheResponse()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        // UNCONFIRMED is both editable and token-patchable, so every guard passes.
        $subscription = $this->parseSubscription($this->subscriptionJson(['status' => 'unconfirmed']), $context);

        $patchedJson = $this->subscriptionJson(['status' => 'current']);
        $fake = new FakeSubscriptionsApiForSubscriptionTest(
            $bridge->caller(),
            [(string) json_encode($patchedJson)]
        );
        $this->injectFakeSubscriptionsApi($bridge, $fake);

        $result = $subscription->patch(
            'new-token-id',
            new Money(200, new Currency('JPY')),
            Period::WEEKLY(),
            null,
            null,
            ['order_id' => 'order-1']
        );

        $this->assertSame('updateSubscription', $fake->calls[0]['method']);
        $this->assertSame(
            ['22222222-2222-2222-2222-222222222222', '11111111-1111-1111-1111-111111111111'],
            array_slice($fake->calls[0]['args'], 0, 2)
        );
        $request = $fake->calls[0]['args'][3];
        $this->assertInstanceOf(SubscriptionUpdateRequest::class, $request);
        $this->assertSame('new-token-id', $request->getTransactionTokenId());
        // SubscriptionUpdateRequest declares typed setters for these (see
        // RequestModelFactory::subscriptionPatch()'s class doc) -- not additionalProperties.
        $this->assertSame(200, $request->getInitialAmount());
        $this->assertSame('weekly', $request->getPeriod());
        $this->assertInstanceOf(Subscription::class, $result);
    }

    // --- cancel() ------------------------------------------------------------------------------

    public function testCancelOnATerminalSubscriptionThrows()
    {
        $subscription = $this->parseSubscription($this->subscriptionJson(['status' => 'canceled']));

        try {
            $subscription->cancel();
            $this->fail('Expected a UnivapayLogicError');
        } catch (UnivapayLogicError $e) {
            $this->assertSame('subscription_already_ended', $e->code);
        }
    }

    public function testCancelCallsCancelSubscriptionAndReturnsTrueOnAnEmptyBody()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $subscription = $this->parseSubscription($this->subscriptionJson(['status' => 'current']), $context);

        $fake = new FakeSubscriptionsApiForSubscriptionTest($bridge->caller(), ['']);
        $this->injectFakeSubscriptionsApi($bridge, $fake);

        $result = $subscription->cancel();

        $this->assertTrue($result);
        $this->assertSame('cancelSubscription', $fake->calls[0]['method']);
        $this->assertSame(
            ['22222222-2222-2222-2222-222222222222', '11111111-1111-1111-1111-111111111111'],
            $fake->calls[0]['args']
        );
    }

    // --- pure status-predicate ports ------------------------------------------------------------

    public function testIsEditableTrueOnlyForUnverifiedAndUnconfirmed()
    {
        $this->assertTrue($this->parseSubscription($this->subscriptionJson(['status' => 'unverified']))->isEditable());
        $this->assertTrue($this->parseSubscription($this->subscriptionJson(['status' => 'unconfirmed']))->isEditable());
        $this->assertFalse($this->parseSubscription($this->subscriptionJson(['status' => 'current']))->isEditable());
    }

    public function testIsProcessingTrueForUnpaidCurrentSuspended()
    {
        foreach (['unpaid', 'current', 'suspended'] as $status) {
            $this->assertTrue($this->parseSubscription($this->subscriptionJson(['status' => $status]))->isProcessing());
        }
        $this->assertFalse($this->parseSubscription($this->subscriptionJson(['status' => 'canceled']))->isProcessing());
    }

    public function testIsTerminalTrueForCanceledAndCompleted()
    {
        $this->assertTrue($this->parseSubscription($this->subscriptionJson(['status' => 'canceled']))->isTerminal());
        $this->assertTrue($this->parseSubscription($this->subscriptionJson(['status' => 'completed']))->isTerminal());
        $this->assertFalse($this->parseSubscription($this->subscriptionJson(['status' => 'current']))->isTerminal());
    }

    public function testIsSubscribableForCardKonbiniAndApplePayOnly()
    {
        $this->assertTrue(Subscription::isSubscribable(PaymentType::CARD()));
        $this->assertTrue(Subscription::isSubscribable(PaymentType::KONBINI()));
        $this->assertTrue(Subscription::isSubscribable(PaymentType::APPLE_PAY()));
        $this->assertFalse(Subscription::isSubscribable(PaymentType::PAIDY()));
    }

    // --- pollableStatuses() pinned against the fixture -------------------------------------------

    public function testPollableStatusesMatchesThePinnedFixture()
    {
        $subscription = $this->parseSubscription($this->subscriptionJson());

        $method = new ReflectionMethod(Subscription::class, 'pollableStatuses');
        $method->setAccessible(true);

        $this->assertEquals(PollableStatusMaps::subscription(), $method->invoke($subscription));
    }
}

/**
 * Hand-written double for the generated `SubscriptionsApi`. Same shape/rationale as
 * `TransactionTokenTest::FakeTransactionTokensApi`.
 */
class FakeSubscriptionsApiForSubscriptionTest extends SubscriptionsApi
{
    /** @var array<int, array{method: string, args: array}> */
    public $calls = [];

    /** @var ApiCaller */
    private $apiCaller;

    /** @var string[] */
    private $responses;

    public function __construct(ApiCaller $apiCaller, array $responses)
    {
        $this->apiCaller = $apiCaller;
        $this->responses = $responses;
    }

    public function getSubscription(string $storeId, string $id, ?bool $polling = null): ApiResponse
    {
        return $this->respond('getSubscription', [$storeId, $id, $polling]);
    }

    public function updateSubscription(
        string $storeId,
        string $id,
        ?string $idempotencyKey = null,
        ?SubscriptionUpdateRequest $body = null
    ): ApiResponse {
        return $this->respond('updateSubscription', [$storeId, $id, $idempotencyKey, $body]);
    }

    public function cancelSubscription(string $storeId, string $id): ApiResponse
    {
        return $this->respond('cancelSubscription', [$storeId, $id]);
    }

    private function respond(string $method, array $args): ApiResponse
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }
}
