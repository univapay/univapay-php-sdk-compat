<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use UnivaPay\Apis\RefundsApi;
use UnivaPay\Http\ApiResponse;
use UnivaPay\Models\RefundUpdateRequest;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\RefundReason;
use Univapay\Compat\Enums\RefundStatus;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Resources\Refund;
use Univapay\Compat\Support\ApiCaller;
use Univapay\Compat\Support\Bridge;
use Univapay\Compat\Support\CompatContext;
use Univapay\Compat\Tests\Fixtures\PollableStatusMaps;

/**
 * Covers `Refund`: hydration (synthesized from `RefundsApi`'s `Refund` response schema
 * -- no raw-JSON parse test exists in the old SDK's suite for this class, unlike Charge/
 * Subscription/ScheduledPayment), `fetch()`/`update()` driven through a REAL `Support\ApiCaller`
 * via a hand-written `FakeRefundsApi` double, and `pollableStatuses()` pinned against
 * `tests/Fixtures/PollableStatusMaps::refund()`.
 */
class RefundTest extends TestCase
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

    private function injectFakeRefundsApi(Bridge $bridge, RefundsApi $fake): void
    {
        $property = new ReflectionProperty(Bridge::class, 'refundsApi');
        $property->setAccessible(true);
        $property->setValue($bridge, $fake);
    }

    private function refundJson(array $overrides = []): array
    {
        return array_replace([
            'id' => 'refund-1',
            'store_id' => 'store-1',
            'charge_id' => 'charge-1',
            'status' => 'successful',
            'currency' => 'JPY',
            'amount' => 500,
            'amount_formatted' => 500,
            'mode' => 'test',
            'created_on' => '2022-07-26T10:33:12.934225Z',
            'reason' => 'customer_request',
            'message' => 'requested by customer',
            'error' => null,
            'metadata' => ['order_id' => 'order-1']
        ], $overrides);
    }

    private function parseRefund(array $json, ?CompatContext $context = null): Refund
    {
        return Refund::getSchema()->parse($json, [$context ?? $this->context()]);
    }

    // --- hydration --------------------------------------------------------------------------------

    public function testHydratesARefund()
    {
        $refund = $this->parseRefund($this->refundJson());

        $this->assertSame('refund-1', $refund->id);
        $this->assertSame('store-1', $refund->storeId);
        $this->assertSame('charge-1', $refund->chargeId);
        $this->assertEquals(RefundStatus::SUCCESSFUL(), $refund->status);
        $this->assertEquals(new \Money\Money(500, new \Money\Currency('JPY')), $refund->amount);
        $this->assertEquals(AppTokenMode::TEST(), $refund->mode);
        $this->assertEquals(RefundReason::CUSTOMER_REQUEST(), $refund->reason);
        $this->assertSame('requested by customer', $refund->message);
        $this->assertSame('order-1', $refund->metadata['order_id']);
    }

    // --- fetch()/update() ----------------------------------------------------------------------

    public function testFetchCallsGetRefundWithStoreChargeAndId()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $refund = $this->parseRefund($this->refundJson(), $context);

        $fake = new FakeRefundsApiForRefundTest($bridge->caller(), [(string) json_encode($this->refundJson())]);
        $this->injectFakeRefundsApi($bridge, $fake);

        $fetched = $refund->fetch();

        $this->assertInstanceOf(Refund::class, $fetched);
        $this->assertNotSame($refund, $fetched);
        $this->assertSame('getRefund', $fake->calls[0]['method']);
        $this->assertSame(['store-1', 'charge-1', 'refund-1'], array_slice($fake->calls[0]['args'], 0, 3));
        $this->assertNull($fake->calls[0]['args'][3]); // no polling -- plain fetch(), not awaitResult()
    }

    public function testUpdateCallsUpdateRefundWithATypedRequestBuiltFromTheArray()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $refund = $this->parseRefund($this->refundJson(), $context);

        $updatedJson = array_replace($this->refundJson(), ['message' => 'changed']);
        $fake = new FakeRefundsApiForRefundTest($bridge->caller(), [(string) json_encode($updatedJson)]);
        $this->injectFakeRefundsApi($bridge, $fake);

        $updated = $refund->update(['message' => 'changed']);

        $this->assertSame('updateRefund', $fake->calls[0]['method']);
        $this->assertSame(['store-1', 'charge-1', 'refund-1'], array_slice($fake->calls[0]['args'], 0, 3));
        $this->assertInstanceOf(RefundUpdateRequest::class, $fake->calls[0]['args'][3]);
        $this->assertSame('changed', $fake->calls[0]['args'][3]->getMessage());
        $this->assertSame('changed', $updated->message);
    }

    // --- pollableStatuses() pinned against the fixture -------------------------------------------

    public function testPollableStatusesMatchesThePinnedFixture()
    {
        $refund = $this->parseRefund($this->refundJson());

        $method = new ReflectionMethod(Refund::class, 'pollableStatuses');
        $method->setAccessible(true);

        $this->assertEquals(PollableStatusMaps::refund(), $method->invoke($refund));
    }

    public function testGetRefundSignatureMatchesWhatFetchAssumes()
    {
        $method = new ReflectionMethod(RefundsApi::class, 'getRefund');
        $names = array_map(function ($p) {
            return $p->getName();
        }, $method->getParameters());
        $this->assertSame(['storeId', 'chargeId', 'id', 'polling'], $names);
    }
}

/**
 * Hand-written double for the generated `RefundsApi`. Same shape/rationale as
 * `TransactionTokenTest::FakeTransactionTokensApi`.
 */
class FakeRefundsApiForRefundTest extends RefundsApi
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

    public function getRefund(string $storeId, string $chargeId, string $id, ?bool $polling = null): ApiResponse
    {
        return $this->respond('getRefund', [$storeId, $chargeId, $id, $polling]);
    }

    public function updateRefund(
        string $storeId,
        string $chargeId,
        string $id,
        RefundUpdateRequest $body,
        ?string $idempotencyKey = null
    ): ApiResponse {
        return $this->respond('updateRefund', [$storeId, $chargeId, $id, $body, $idempotencyKey]);
    }

    private function respond(string $method, array $args): ApiResponse
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }
}
