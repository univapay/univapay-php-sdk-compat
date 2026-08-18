<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use UnivaPay\Apis\CancelsApi;
use UnivaPay\Http\ApiResponse;
use UnivaPay\Models\CancelUpdateRequest;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\CancelStatus;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Resources\Cancel;
use Univapay\Compat\Support\ApiCaller;
use Univapay\Compat\Support\Bridge;
use Univapay\Compat\Support\CompatContext;
use Univapay\Compat\Tests\Fixtures\PollableStatusMaps;

/**
 * Covers `Cancel`: hydration, `fetch()`/`update()` driven through a REAL
 * `Support\ApiCaller` via a hand-written `FakeCancelsApi` double (`getCancel()`'s generated
 * `$polling` default is `false`, unlike every other resource -- `fetchWithPolling()` passes a
 * literal `true` regardless, asserted below), and `pollableStatuses()` pinned against
 * `tests/Fixtures/PollableStatusMaps::cancel()`.
 */
class CancelTest extends TestCase
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

    private function injectFakeCancelsApi(Bridge $bridge, CancelsApi $fake): void
    {
        $property = new ReflectionProperty(Bridge::class, 'cancelsApi');
        $property->setAccessible(true);
        $property->setValue($bridge, $fake);
    }

    private function cancelJson(array $overrides = []): array
    {
        return array_replace([
            'id' => 'cancel-1',
            'charge_id' => 'charge-1',
            'store_id' => 'store-1',
            'status' => 'successful',
            'error' => null,
            'metadata' => ['something' => 'anything'],
            'mode' => 'test',
            'created_on' => '2022-07-26T10:33:12.934225Z'
        ], $overrides);
    }

    private function parseCancel(array $json, ?CompatContext $context = null): Cancel
    {
        return Cancel::getSchema()->parse($json, [$context ?? $this->context()]);
    }

    // --- hydration --------------------------------------------------------------------------------

    public function testHydratesACancel()
    {
        $cancel = $this->parseCancel($this->cancelJson());

        $this->assertSame('cancel-1', $cancel->id);
        $this->assertSame('charge-1', $cancel->chargeId);
        $this->assertSame('store-1', $cancel->storeId);
        $this->assertEquals(CancelStatus::SUCCESSFUL(), $cancel->status);
        $this->assertEquals(AppTokenMode::TEST(), $cancel->mode);
        $this->assertSame('anything', $cancel->metadata['something']);
    }

    // --- fetch()/update()/fetchWithPolling() -----------------------------------------------------

    public function testFetchCallsGetCancelWithStoreChargeAndIdAndNoPolling()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $cancel = $this->parseCancel($this->cancelJson(), $context);

        $fake = new FakeCancelsApiForCancelTest($bridge->caller(), [(string) json_encode($this->cancelJson())]);
        $this->injectFakeCancelsApi($bridge, $fake);

        $fetched = $cancel->fetch();

        $this->assertInstanceOf(Cancel::class, $fetched);
        $this->assertNotSame($cancel, $fetched);
        $this->assertSame('getCancel', $fake->calls[0]['method']);
        $this->assertSame(['store-1', 'charge-1', 'cancel-1'], array_slice($fake->calls[0]['args'], 0, 3));
        // fetchCall() passes no third argument at all -- the generated method's own default
        // (`false`, not `null` -- see class doc) is what fills the slot here, and this asserts
        // fetch() never coincidentally relies on it being any particular value.
        $this->assertFalse($fake->calls[0]['args'][3]);
    }

    public function testAwaitResultPassesLiteralTruePollingRegardlessOfTheGeneratedDefault()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $cancel = $this->parseCancel($this->cancelJson(['status' => 'pending']), $context);

        $fake = new FakeCancelsApiForCancelTest($bridge->caller(), [
            (string) json_encode($this->cancelJson(['status' => 'successful']))
        ]);
        $this->injectFakeCancelsApi($bridge, $fake);

        $result = $cancel->awaitResult(0);

        $this->assertSame('getCancel', $fake->calls[0]['method']);
        $this->assertTrue($fake->calls[0]['args'][3]);
        $this->assertEquals(CancelStatus::SUCCESSFUL(), $result->status);
    }

    public function testUpdateCallsUpdateCancelWithATypedRequestBuiltFromTheArray()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $cancel = $this->parseCancel($this->cancelJson(), $context);

        $updatedJson = array_replace($this->cancelJson(), ['metadata' => ['changed' => true]]);
        $fake = new FakeCancelsApiForCancelTest($bridge->caller(), [(string) json_encode($updatedJson)]);
        $this->injectFakeCancelsApi($bridge, $fake);

        $updated = $cancel->update(['metadata' => ['changed' => true]]);

        $this->assertSame('updateCancel', $fake->calls[0]['method']);
        $this->assertSame(['store-1', 'charge-1', 'cancel-1'], array_slice($fake->calls[0]['args'], 0, 3));
        $this->assertInstanceOf(CancelUpdateRequest::class, $fake->calls[0]['args'][3]);
        $this->assertTrue($updated->metadata['changed']);
    }

    // --- pollableStatuses() pinned against the fixture -------------------------------------------

    public function testPollableStatusesMatchesThePinnedFixture()
    {
        $cancel = $this->parseCancel($this->cancelJson());

        $method = new ReflectionMethod(Cancel::class, 'pollableStatuses');
        $method->setAccessible(true);

        $this->assertEquals(PollableStatusMaps::cancel(), $method->invoke($cancel));
    }

    public function testGetCancelSignatureMatchesWhatFetchAssumesAndDefaultsToFalse()
    {
        $method = new ReflectionMethod(CancelsApi::class, 'getCancel');
        $params = $method->getParameters();
        $names = array_map(function ($p) {
            return $p->getName();
        }, $params);
        $this->assertSame(['storeId', 'chargeId', 'id', 'polling'], $names);
        $this->assertFalse($params[3]->getDefaultValue());
    }
}

/**
 * Hand-written double for the generated `CancelsApi`. Same shape/rationale as
 * `TransactionTokenTest::FakeTransactionTokensApi`.
 */
class FakeCancelsApiForCancelTest extends CancelsApi
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

    public function getCancel(string $storeId, string $chargeId, string $id, ?bool $polling = false): ApiResponse
    {
        return $this->respond('getCancel', [$storeId, $chargeId, $id, $polling]);
    }

    public function updateCancel(
        string $storeId,
        string $chargeId,
        string $id,
        CancelUpdateRequest $body,
        ?string $idempotencyKey = null
    ): ApiResponse {
        return $this->respond('updateCancel', [$storeId, $chargeId, $id, $body, $idempotencyKey]);
    }

    private function respond(string $method, array $args): ApiResponse
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }
}
