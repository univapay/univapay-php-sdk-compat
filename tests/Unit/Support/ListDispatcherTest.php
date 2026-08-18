<?php

namespace Univapay\Compat\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use UnivaPay\Apis\SubscriptionsApi;
use UnivaPay\Apis\TransactionHistoryApi;
use UnivaPay\Http\ApiResponse;
use Univapay\Compat\Errors\UnivapayListDispatchError;
use Univapay\Compat\Errors\UnivapaySDKError;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Support\ApiCaller;
use Univapay\Compat\Support\Bridge;
use Univapay\Compat\Support\ListDispatcher;

/**
 * Covers `ListDispatcher`'s fail-loud semantics: unknown/un-mappable
 * query keys throw instead of being silently forwarded or dropped (old SDK's
 * `Utility\OptionsValidator::validate()` passed anything it had no rule for straight through).
 * All assertions here throw BEFORE `ListDispatcher` ever touches `Bridge::caller()`/HTTP (argument
 * mapping is validated first, dispatch second -- see `ListDispatcher::buildArgs()`), so a real
 * (but never network-touched) `Bridge` is enough; no Prism/mock server needed. The successful
 * HTTP-dispatch path itself is exercised by the integration suite (against Prism), not here.
 */
class ListDispatcherTest extends TestCase
{
    private function bridge(): Bridge
    {
        $header = base64_encode(json_encode(['alg' => 'none']));
        $body = base64_encode(json_encode([
            'sub' => 'app_token',
            'iat' => 1,
            'merchant_id' => 'merchant-1',
            'store_id' => 'store-1',
            'domains' => [],
            'mode' => 'test',
            'creator_id' => 'creator-1',
            'version' => 1,
            'jti' => 'jti-1',
        ]));
        $jwt = AppJWT::createToken("$header.$body.sig", 'secret-1');
        return new Bridge($jwt);
    }

    private function itemParser(): callable
    {
        return function ($raw) {
            return (object) $raw;
        };
    }

    // --- Fail-loud: unknown/un-mappable keys --------------------------------------------------

    public function testListAllChargesThrowsOnAKeyWithNoGeneratedEquivalent()
    {
        // card_number has no generated ChargesApi::listAllCharges() parameter at all -- permanent
        // spec-backlog gap, not a temporary omission.
        $this->expectException(UnivapayListDispatchError::class);
        $this->expectExceptionMessageMatches('/card_number/');

        ListDispatcher::listAllCharges($this->bridge(), ['card_number' => '4242'], $this->itemParser());
    }

    public function testUnmappableKeyErrorIsAUnivapaySdkError()
    {
        try {
            ListDispatcher::listAllCharges($this->bridge(), ['card_number' => '4242'], $this->itemParser());
            $this->fail('Expected an exception');
        } catch (UnivapaySDKError $e) {
            $this->assertStringContainsString('card_number', $e->getMessage());
            $this->assertStringContainsString('listAllCharges', $e->getMessage());
        }
    }

    public function testListAllSubscriptionsAcceptsStatusFilterSinceS13()
    {
        // The generated SubscriptionsApi::listAllSubscriptions() previously had ONLY
        // limit/cursor/cursorDirection -- unlike listStoreSubscriptions(), which already had
        // search/status/mode. listAllSubscriptions() now has the identical shape, so 'status' is
        // not unmappable here -- see ListDispatcherArgOrderTest's reflection assertion for the
        // authoritative parameter-order check; this just confirms the fail-loud path doesn't
        // reject it.
        $bridge = $this->bridge();
        $fake = new FakeSubscriptionsApiForListDispatcherTest($bridge->caller(), ['{"items":[],"has_more":false}']);
        $this->injectFake($bridge, 'subscriptionsApi', $fake);

        $result = ListDispatcher::listAllSubscriptions($bridge, ['status' => 'current'], $this->itemParser());

        $this->assertSame([], $result->items);
        $this->assertSame('current', $fake->calls[0]['args'][1]);
    }

    public function testListBankTransferLedgersRejectsAnyQueryKeyAtAllIncludingCursor()
    {
        // The generated ChargesApi::listBankTransferLedgers() takes NO query parameters
        // whatsoever -- not even pagination -- despite its response looking paginated.
        $this->expectException(UnivapayListDispatchError::class);
        $this->expectExceptionMessageMatches('/cursor/');

        ListDispatcher::listBankTransferLedgers(
            $this->bridge(),
            'store-1',
            'charge-1',
            ['cursor' => 'abc'],
            $this->itemParser()
        );
    }

    // --- Transaction history ---------------------------------------------------------------------

    public function testListTransactionsDispatchesToTransactionHistoryApiAndMapsFilters()
    {
        $bridge = $this->bridge();
        $fake = new FakeTransactionHistoryApiForListDispatcherTest(
            $bridge->caller(),
            ['{"items":[{"id":"t-1"}],"has_more":true}']
        );
        $this->injectFake($bridge, 'transactionHistoryApi', $fake);

        $result = ListDispatcher::listTransactions(
            $bridge,
            ['status' => 'successful', 'from' => '1600000000000'],
            $this->itemParser()
        );

        $this->assertSame('listTransactionHistory', $fake->calls[0]['method']);
        $this->assertTrue($result->hasMore);
        $this->assertSame('t-1', $result->items[0]->id);
        // TRANSACTION_HISTORY_ORDER: [mode, short_id, from, to, status, ...] -- 'from' is index 2,
        // 'status' is index 4.
        $this->assertSame('1600000000000', $fake->calls[0]['args'][2]);
        $this->assertSame('successful', $fake->calls[0]['args'][4]);
    }

    public function testListStoreTransactionsDispatchesToStoreScopedTransactionHistoryApi()
    {
        $bridge = $this->bridge();
        $fake = new FakeTransactionHistoryApiForListDispatcherTest(
            $bridge->caller(),
            ['{"items":[],"has_more":false}']
        );
        $this->injectFake($bridge, 'transactionHistoryApi', $fake);

        ListDispatcher::listStoreTransactions($bridge, 'store-1', [], $this->itemParser());

        $this->assertSame('listStoreTransactionHistory', $fake->calls[0]['method']);
        $this->assertSame('store-1', $fake->calls[0]['args'][0]);
    }

    public function testListTransactionsStillRejectsAKeyWithNoGeneratedEquivalent()
    {
        // Genuine remaining spec-backlog gap: TRANSACTION_HISTORY_ORDER has no old-SDK-reachable
        // equivalent for e.g. 'gateway' (admin-only per spec authoring appendix, excluded
        // entirely) -- still fails loud, same as any other unmappable key.
        $this->expectException(UnivapayListDispatchError::class);
        $this->expectExceptionMessageMatches('/gateway/');

        ListDispatcher::listTransactions($this->bridge(), ['gateway' => 'stripe'], $this->itemParser());
    }

    private function injectFake(Bridge $bridge, string $apiProperty, $fake): void
    {
        $property = new ReflectionProperty(Bridge::class, $apiProperty);
        $property->setAccessible(true);
        $property->setValue($bridge, $fake);
    }

    // --- buildArgs(): null-fill + positional mapping (reflected; no HTTP involved either way) -

    public function testBuildArgsNullFillsEveryKeyTheCallerNeverSet()
    {
        $args = $this->invokeBuildArgs(['limit' => 5], ['limit', 'cursor', 'cursor_direction'], 'x');

        // Explicit null for the keys the caller never set -- NOT the generated method's own PHP
        // default (10 / 'desc') -- see class doc: passing the default instead of null would put a
        // limit/cursor_direction on the wire the caller never asked for.
        $this->assertSame([5, null, null], $args);
    }

    public function testBuildArgsPreservesOrderRegardlessOfInputArrayOrder()
    {
        $args = $this->invokeBuildArgs(
            ['cursor_direction' => 'asc', 'limit' => 5, 'cursor' => 'c1'],
            ['limit', 'cursor', 'cursor_direction'],
            'x'
        );

        $this->assertSame([5, 'c1', 'asc'], $args);
    }

    public function testBuildArgsThrowsNamingTheFirstUnknownKeyAndTheEndpoint()
    {
        $this->expectException(UnivapayListDispatchError::class);
        $this->expectExceptionMessageMatches('/unknown_filter/');
        $this->expectExceptionMessageMatches('/someEndpoint/');

        $this->invokeBuildArgs(['limit' => 5, 'unknown_filter' => 'x'], ['limit'], 'someEndpoint');
    }

    // --- merchantId resolution (listSubscriptionCharges) --------------------------------------

    public function testResolveMerchantIdUsesTheBridgeJwtClaimWithoutTouchingTheNetwork()
    {
        // storeJwt in bridge() carries merchant_id => 'merchant-1'; this must be used directly
        // (no MerchantsApi::getCurrentMerchant() HTTP call) since Bridge::merchantId() is present.
        $reflected = new ReflectionMethod(ListDispatcher::class, 'resolveMerchantId');
        $reflected->setAccessible(true);

        $merchantId = $reflected->invoke(null, $this->bridge());

        $this->assertSame('merchant-1', $merchantId);
    }

    /**
     * @param array $query
     * @param string[] $order
     * @param string $endpoint
     * @return array
     */
    private function invokeBuildArgs(array $query, array $order, string $endpoint): array
    {
        $reflected = new ReflectionMethod(ListDispatcher::class, 'buildArgs');
        $reflected->setAccessible(true);
        return $reflected->invoke(null, $query, $order, $endpoint);
    }
}

/**
 * Hand-written double for the generated `SubscriptionsApi`, standing in only for
 * `listAllSubscriptions()` -- same rationale/technique as every other Fake*Api in this suite
 * (see e.g. `TransactionTokenTest::FakeTransactionTokensApi`'s class doc).
 */
class FakeSubscriptionsApiForListDispatcherTest extends SubscriptionsApi
{
    /** @var array<int, array{method: string, args: array}> */
    public $calls = [];

    private $apiCaller;
    private $responses;

    public function __construct(ApiCaller $apiCaller, array $responses)
    {
        $this->apiCaller = $apiCaller;
        $this->responses = $responses;
    }

    public function listAllSubscriptions(
        ?string $search = null,
        ?string $status = null,
        ?string $mode = null,
        ?int $limit = 10,
        ?string $cursor = null,
        ?string $cursorDirection = null
    ): ApiResponse {
        $this->calls[] = [
            'method' => 'listAllSubscriptions',
            'args' => [$search, $status, $mode, $limit, $cursor, $cursorDirection],
        ];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }
}

/**
 * Hand-written double for the generated `TransactionHistoryApi`.
 */
class FakeTransactionHistoryApiForListDispatcherTest extends TransactionHistoryApi
{
    /** @var array<int, array{method: string, args: array}> */
    public $calls = [];

    private $apiCaller;
    private $responses;

    public function __construct(ApiCaller $apiCaller, array $responses)
    {
        $this->apiCaller = $apiCaller;
        $this->responses = $responses;
    }

    public function listTransactionHistory(
        ?string $mode = null,
        ?string $shortId = null,
        ?string $from = null,
        ?string $to = null,
        ?string $status = null,
        ?string $type = null,
        ?string $search = null,
        ?string $email = null,
        ?string $id = null,
        ?string $metadata = null,
        ?string $cardExp = null,
        ?string $cardLastFour = null,
        ?string $cardholder = null,
        ?array $cardBrand = null,
        ?array $brand = null,
        ?array $brands = null,
        ?string $currency = null,
        ?string $serviceProvider = null,
        ?array $serviceProviders = null,
        ?string $gatewayTransactionId = null,
        ?array $bankTransferPaymentStatuses = null,
        ?string $bankTransferLatestDepositDateFrom = null,
        ?string $bankTransferLatestDepositDateTo = null,
        ?int $limit = 10,
        ?string $cursor = null,
        ?string $cursorDirection = null
    ): ApiResponse {
        return $this->respond('listTransactionHistory', [
            $mode, $shortId, $from, $to, $status, $type, $search, $email, $id, $metadata, $cardExp,
            $cardLastFour, $cardholder, $cardBrand, $brand, $brands, $currency, $serviceProvider,
            $serviceProviders, $gatewayTransactionId, $bankTransferPaymentStatuses,
            $bankTransferLatestDepositDateFrom, $bankTransferLatestDepositDateTo, $limit, $cursor,
            $cursorDirection,
        ]);
    }

    public function listStoreTransactionHistory(
        string $storeId,
        ?string $mode = null,
        ?string $shortId = null,
        ?string $from = null,
        ?string $to = null,
        ?string $status = null,
        ?string $type = null,
        ?string $search = null,
        ?string $email = null,
        ?string $id = null,
        ?string $metadata = null,
        ?string $cardExp = null,
        ?string $cardLastFour = null,
        ?string $cardholder = null,
        ?array $cardBrand = null,
        ?array $brand = null,
        ?array $brands = null,
        ?string $currency = null,
        ?string $serviceProvider = null,
        ?array $serviceProviders = null,
        ?string $gatewayTransactionId = null,
        ?array $bankTransferPaymentStatuses = null,
        ?string $bankTransferLatestDepositDateFrom = null,
        ?string $bankTransferLatestDepositDateTo = null,
        ?int $limit = 10,
        ?string $cursor = null,
        ?string $cursorDirection = null
    ): ApiResponse {
        return $this->respond('listStoreTransactionHistory', [$storeId]);
    }

    private function respond(string $method, array $args): ApiResponse
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }
}
