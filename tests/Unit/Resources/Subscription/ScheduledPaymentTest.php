<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources\Subscription;

use DateTimeZone;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use UnivaPay\Apis\SubscriptionsApi;
use UnivaPay\Http\ApiResponse;
use UnivaPay\Models\SubscriptionPatchPaymentRequest;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Resources\Charge;
use Univapay\Compat\Resources\Subscription\ScheduledPayment;
use Univapay\Compat\Support\ApiCaller;
use Univapay\Compat\Support\Bridge;
use Univapay\Compat\Support\CompatContext;

/**
 * Covers `Subscription\ScheduledPayment`: hydration (JSON fixture lifted verbatim from
 * the old SDK's `tests/Univapay/Integration/ScheduledPaymentTest.php::testScheduledPaymentParse`),
 * `fetch()`/`update()` against `SubscriptionsApi::getSubscriptionPayment()`/
 * `updateSubscriptionPayment()`, and the `listCharges()` narrow-override seam dispatching to
 * `Support\ListDispatcher::listChargesForSubscriptionPayment()` with every
 * OTHER `Mixins\GetCharges::listCharges()` filter forced to null (verbatim old-SDK behavior --
 * see that class's own doc).
 */
class ScheduledPaymentTest extends TestCase
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

    /** JSON lifted verbatim from the old SDK's ScheduledPaymentTest::testScheduledPaymentParse. */
    private function scheduledPaymentJson(array $overrides = []): array
    {
        return array_replace([
            'id' => '11e8960f-dd31-28ca-a8a8-ab5fd4c72b70',
            'subscription_id' => 'sub-1',
            'due_date' => '2019-05-02',
            'zone_id' => 'Asia/Tokyo',
            'amount' => 560,
            'currency' => 'JPY',
            'amount_formatted' => 560,
            'is_paid' => false,
            'is_last_payment' => true,
            'created_on' => '2018-08-02T04:52:31.250338Z'
        ], $overrides);
    }

    private function parsePayment(array $json, ?CompatContext $context = null): ScheduledPayment
    {
        return ScheduledPayment::getSchema()->parse($json, [$context ?? $this->context()]);
    }

    // --- hydration --------------------------------------------------------------------------------

    public function testHydratesAScheduledPayment()
    {
        $payment = $this->parsePayment($this->scheduledPaymentJson());

        $this->assertSame('11e8960f-dd31-28ca-a8a8-ab5fd4c72b70', $payment->id);
        $this->assertEquals(date_create('2019-05-02'), $payment->dueDate);
        $this->assertEquals(new DateTimeZone('Asia/Tokyo'), $payment->zoneId);
        $this->assertEquals(new Money(560, new Currency('JPY')), $payment->amount);
        $this->assertSame(560, $payment->amountFormatted);
        $this->assertFalse($payment->isPaid);
        $this->assertTrue($payment->isLastPayment);
        $this->assertEquals(date_create('2018-08-02T04:52:31.250338Z'), $payment->createdOn);
    }

    public function testDefaultsCreatedOnToNowWhenAbsent()
    {
        // Old-SDK-verbatim quirk: `/subscriptions/simulate_plan` does not return `created_on` at
        // all -- the constructor defaults it to 'now' rather than leaving it null.
        $json = $this->scheduledPaymentJson();
        unset($json['created_on']);

        $payment = $this->parsePayment($json);

        $this->assertInstanceOf(\DateTime::class, $payment->createdOn);
    }

    // --- fetch()/update() ----------------------------------------------------------------------

    public function testFetchCallsGetSubscriptionPaymentWithContextStoreIdSubscriptionIdAndPaymentId()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $payment = $this->parsePayment($this->scheduledPaymentJson(), $context);

        $fake = new FakeSubscriptionsApiForScheduledPaymentTest(
            $bridge->caller(),
            [(string) json_encode($this->scheduledPaymentJson())]
        );
        $this->injectFakeSubscriptionsApi($bridge, $fake);

        $fetched = $payment->fetch();

        $this->assertInstanceOf(ScheduledPayment::class, $fetched);
        $this->assertSame('getSubscriptionPayment', $fake->calls[0]['method']);
        $this->assertSame(['store-1', 'sub-1', '11e8960f-dd31-28ca-a8a8-ab5fd4c72b70'], $fake->calls[0]['args']);
    }

    public function testUpdateBuildsATypedRequestAndCallsUpdateSubscriptionPayment()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $payment = $this->parsePayment($this->scheduledPaymentJson(), $context);

        $updatedJson = $this->scheduledPaymentJson(['is_paid' => true]);
        $fake = new FakeSubscriptionsApiForScheduledPaymentTest(
            $bridge->caller(),
            [(string) json_encode($updatedJson)]
        );
        $this->injectFakeSubscriptionsApi($bridge, $fake);

        $updated = $payment->update(['is_paid' => true]);

        $this->assertSame('updateSubscriptionPayment', $fake->calls[0]['method']);
        $this->assertSame(
            ['store-1', 'sub-1', '11e8960f-dd31-28ca-a8a8-ab5fd4c72b70'],
            array_slice($fake->calls[0]['args'], 0, 3)
        );
        $this->assertInstanceOf(SubscriptionPatchPaymentRequest::class, $fake->calls[0]['args'][3]);
        $this->assertTrue($fake->calls[0]['args'][3]->getIsPaid());
        $this->assertTrue($updated->isPaid);
    }

    // --- listCharges(): narrow override ----------------------------------------------------------

    public function testListChargesDispatchesToListChargesForSubscriptionPaymentWithOnlyCursorLimitDirection()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $payment = $this->parsePayment($this->scheduledPaymentJson(), $context);

        $chargeJson = [
            'id' => 'charge-1',
            'store_id' => 'store-1',
            'transaction_token_id' => 'token-1',
            'transaction_token_type' => 'one_time',
            'subscription_id' => 'sub-1',
            'requested_amount' => 1000,
            'requested_currency' => 'JPY',
            'requested_amount_formatted' => 1000,
            'status' => 'successful',
            'mode' => 'test',
            'created_on' => '2022-07-26T10:33:12.934225Z',
            'metadata' => []
        ];
        $fake = new FakeSubscriptionsApiForScheduledPaymentTest(
            $bridge->caller(),
            [(string) json_encode(['items' => [$chargeJson], 'has_more' => false])]
        );
        $this->injectFakeSubscriptionsApi($bridge, $fake);

        $page = $payment->listCharges('cursor-1', 5);

        $this->assertSame('listChargesForSubscriptionPayment', $fake->calls[0]['method']);
        $this->assertSame(
            ['store-1', 'sub-1', '11e8960f-dd31-28ca-a8a8-ab5fd4c72b70', 5, 'cursor-1', null],
            $fake->calls[0]['args']
        );
        $this->assertCount(1, $page->items);
        $this->assertInstanceOf(Charge::class, $page->items[0]);
    }
}

/**
 * Hand-written double for the generated `SubscriptionsApi`, same rationale as
 * `TransactionTokenTest::FakeTransactionTokensApi`.
 */
class FakeSubscriptionsApiForScheduledPaymentTest extends SubscriptionsApi
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

    public function getSubscriptionPayment(string $storeId, string $subscriptionId, string $paymentId): ApiResponse
    {
        return $this->respond('getSubscriptionPayment', [$storeId, $subscriptionId, $paymentId]);
    }

    public function updateSubscriptionPayment(
        string $storeId,
        string $subscriptionId,
        string $paymentId,
        SubscriptionPatchPaymentRequest $body,
        ?string $idempotencyKey = null
    ): ApiResponse {
        return $this->respond(
            'updateSubscriptionPayment',
            [$storeId, $subscriptionId, $paymentId, $body, $idempotencyKey]
        );
    }

    public function listChargesForSubscriptionPayment(
        string $storeId,
        string $subscriptionId,
        string $paymentId,
        ?int $limit = 10,
        ?string $cursor = null,
        ?string $cursorDirection = 'desc'
    ): ApiResponse {
        return $this->respond(
            'listChargesForSubscriptionPayment',
            [$storeId, $subscriptionId, $paymentId, $limit, $cursor, $cursorDirection]
        );
    }

    private function respond(string $method, array $args): ApiResponse
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }
}
