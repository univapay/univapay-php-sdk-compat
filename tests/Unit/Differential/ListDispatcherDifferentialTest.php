<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Differential;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use UnivaPay\ApiHelper;
use UnivaPay\Apis\ChargesApi;
use UnivaPay\Http\ApiResponse;
use UnivaPay\Models\ChargeList;
use Univapay\Compat\Resources\Charge;
use Univapay\Compat\Support\ApiCaller;
use Univapay\Compat\Support\Bridge;
use Univapay\Compat\Support\ListDispatcher;
use Univapay\Compat\Support\TypedHydrator;
use Univapay\Compat\Support\TypedResult;
use Univapay\Compat\Tests\Support\DifferentialHydration;

/**
 * Differential hydration harness for `Support\ListDispatcher`'s per-item typed dispatch
 * (`wrapPage()`'s $typedItems zipping + `typedListItems()`). `ChargeList::getItems()` returns
 * `Charge[]` directly (see `UnivaPay\Models\ChargeList::getItems()`'s own `@return` doc) -- there
 * is no separate, thinner `ChargeListItem`/`RefundListItem`/`CancelListItem` type for these three
 * resources, unlike `TransactionTokenListItem` (see docs/ARCHITECTURE.md) -- so the SAME
 * `Charge::hydrateFromTyped()`/`Refund::hydrateFromTyped()`/`Cancel::hydrateFromTyped()` already
 * proven correct for single-fetch responses applies unchanged per list item.
 *
 * Drives `ListDispatcher::listStoreCharges()` through a REAL `Support\ApiCaller` (not a
 * hand-rolled TypedResult) via a fake `ChargesApi` that returns an `ApiResponse` carrying a REAL
 * jsonmapper-deserialized `ChargeList`, so this also proves `callTyped()`'s result reaches
 * `wrapPage()`'s per-item zip correctly end to end -- not just that `TypedHydrator::resolve()`
 * itself is correct (already covered per-item by `ChargeDifferentialTest`).
 */
class ListDispatcherDifferentialTest extends TestCase
{
    use DifferentialHydration;

    private function chargeJson(string $id, string $status): array
    {
        return [
            'id' => $id,
            'store_id' => 'store-1',
            'transaction_token_id' => 'token-1',
            'transaction_token_type' => 'one_time',
            'subscription_id' => null,
            'requested_amount' => 1000,
            'requested_currency' => 'JPY',
            'requested_amount_formatted' => 1000,
            'charged_amount' => null,
            'charged_currency' => null,
            'charged_amount_formatted' => null,
            'only_direct_currency' => false,
            'capture_at' => null,
            'status' => $status,
            'error' => null,
            'metadata' => (object) [],
            'mode' => 'test',
            'created_on' => '2024-06-25T07:12:15.164520Z',
            'redirect' => null,
            'three_ds' => null,
        ];
    }

    private function injectFakeChargesApi(Bridge $bridge, ChargesApi $fake): void
    {
        $property = new ReflectionProperty(Bridge::class, 'chargesApi');
        $property->setAccessible(true);
        $property->setValue($bridge, $fake);
    }

    public function testListStoreChargesHydratesEveryItemTypedAndMatchesTheRawPath(): void
    {
        $context = $this->differentialContext();
        $bridge = $context->bridge();

        $items = [
            $this->chargeJson('charge-1', 'successful'),
            $this->chargeJson('charge-2', 'pending'),
        ];
        $listBody = ['items' => $items, 'has_more' => false, 'total_hits' => 2];
        $wireJson = (string) json_encode($listBody);

        $typedList = ApiHelper::getJsonHelper()->mapClass(json_decode($wireJson), ChargeList::class);
        $fake = new FakeChargesApiForListDispatcherTest($bridge->caller(), $wireJson, $typedList);
        $this->injectFakeChargesApi($bridge, $fake);

        $page = ListDispatcher::listStoreCharges(
            $bridge,
            'store-1',
            [],
            function ($raw, $typed = null) use ($context) {
                return TypedHydrator::resolve(Charge::class, new TypedResult($raw, $typed, false), $context);
            }
        );

        $this->assertCount(2, $page->items);
        foreach ($page->items as $index => $hydrated) {
            $expected = Charge::getSchema()->parse(json_decode($wireJson, true)['items'][$index], [$context]);
            $this->assertEquals($expected, $hydrated);
        }
    }

    /**
     * When the generated list wrapper's own jsonmapper fails (mapperFailed), every item must
     * still fall back to the raw path individually -- `wrapPage()` receives $typedItems = null.
     */
    public function testListStoreChargesFallsBackToRawForEveryItemWhenTheListWrapperMapperFails(): void
    {
        $context = $this->differentialContext();
        $bridge = $context->bridge();

        $items = [$this->chargeJson('charge-1', 'successful')];
        $listBody = ['items' => $items, 'has_more' => false, 'total_hits' => 1];
        $wireJson = (string) json_encode($listBody);

        // No typed result at all -- simulates ApiCaller's own jsonmapper-bypass path.
        $fake = new FakeChargesApiForListDispatcherTest($bridge->caller(), $wireJson, null);
        $this->injectFakeChargesApi($bridge, $fake);

        $page = ListDispatcher::listStoreCharges(
            $bridge,
            'store-1',
            [],
            function ($raw, $typed = null) use ($context) {
                return TypedHydrator::resolve(Charge::class, new TypedResult($raw, $typed, false), $context);
            }
        );

        $this->assertCount(1, $page->items);
        $rawDecodedItem = json_decode($wireJson, true)['items'][0];
        $expected = Charge::getSchema()->parse($rawDecodedItem, [$context]);
        $this->assertEquals($expected, $page->items[0]);
    }
}

/**
 * Hand-written double for the generated `ChargesApi`, returning an `ApiResponse` carrying a REAL
 * typed result (unlike `tests/Unit/Resources/ChargeTest.php`'s `FakeChargesApiForChargeTest`,
 * which always passes `null` for `$result` -- sufficient there since those tests exercise the raw
 * path only, but this test specifically needs a real typed `ChargeList` to reach `wrapPage()`).
 */
class FakeChargesApiForListDispatcherTest extends ChargesApi
{
    /** @var ApiCaller */
    private $apiCaller;

    /** @var string */
    private $rawBody;

    /** @var mixed|null */
    private $typedResult;

    public function __construct(ApiCaller $apiCaller, string $rawBody, $typedResult)
    {
        $this->apiCaller = $apiCaller;
        $this->rawBody = $rawBody;
        $this->typedResult = $typedResult;
    }

    public function listStoreCharges(
        string $storeId,
        ?int $limit = 10,
        ?string $cursor = null,
        ?string $cursorDirection = 'desc',
        ?string $lastFour = null,
        ?string $name = null,
        ?int $expMonth = null,
        ?int $expYear = null,
        ?string $from = null,
        ?string $to = null,
        ?string $email = null,
        ?string $phone = null,
        ?int $amountFrom = null,
        ?int $amountTo = null,
        ?string $currency = null,
        ?string $mode = null,
        ?string $metadata = null,
        ?string $transactionTokenId = null
    ): ApiResponse {
        $this->apiCaller->recordResponse($this->rawBody, 200);
        return new ApiResponse(null, 200, null, [], $this->typedResult, $this->rawBody);
    }
}
