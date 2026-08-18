<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use UnivaPay\Apis\ChargesApi;
use UnivaPay\Apis\StoresApi;
use UnivaPay\Apis\SubscriptionsApi;
use UnivaPay\Apis\TransactionHistoryApi;
use UnivaPay\Http\ApiResponse;
use UnivaPay\Models\CreateCustomerIdRequest;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Resources\Charge;
use Univapay\Compat\Resources\Configuration\Configuration;
use Univapay\Compat\Resources\Store;
use Univapay\Compat\Resources\Subscription;
use Univapay\Compat\Support\ApiCaller;
use Univapay\Compat\Support\Bridge;
use Univapay\Compat\Support\CompatContext;

/**
 * Covers `Store`: hydration (incl. nested `Configuration`), `getCharge()`/
 * `getSubscription()` routing through the store's OWN id (not `$this->context->storeId`),
 * `getCustomerId()`'s real `POST .../create_customer_id` call, `update()`'s
 * permanent-unsupported throw (no store update endpoint ever existed), and the
 * `GetCharges`/`GetSubscriptions`/`GetTransactions` mixin hooks' dispatcher routing
 * (`listTransactionsPage()` dispatches to the real `Support\ListDispatcher::
 * listStoreTransactions()`).
 */
class StoreTest extends TestCase
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

    private function storeJson(array $overrides = []): array
    {
        return array_replace([
            'id' => 'store-1',
            'name' => 'My Store',
            'created_on' => '2020-01-01T00:00:00.000000Z',
        ], $overrides);
    }

    private function parseStore(array $json, ?CompatContext $context = null): Store
    {
        return Store::getSchema()->parse($json, [$context ?? $this->context()]);
    }

    public function testHydratesAStoreWithoutConfiguration()
    {
        $store = $this->parseStore($this->storeJson());

        $this->assertSame('store-1', $store->id);
        $this->assertSame('My Store', $store->name);
        $this->assertNull($store->configuration);
    }

    public function testHydratesAStoreWithConfiguration()
    {
        $store = $this->parseStore($this->storeJson([
            'configuration' => [
                'percent_fee' => 3.5,
                'flat_fees' => [],
                'logo_url' => null,
                'country' => 'JP',
                'language' => 'en',
                'display_time_zone' => 'Asia/Tokyo',
                'min_transfer_payout' => 1000,
                'maximum_charge_amounts' => [],
                'transfer_schedule' => null,
                'user_transactions_configuration' => ['enabled' => true, 'notify_customer' => false],
                'card_configuration' => [
                    'enabled' => true, 'debit_enabled' => true, 'prepaid_enabled' => false,
                    'only_direct_currency' => false, 'forbidden_card_brands' => [],
                    'allowed_countries_by_ip' => null, 'foreign_cards_allowed' => true,
                    'fail_on_new_email' => false, 'card_limit' => null, 'allow_empty_cvv' => false,
                ],
                'qr_scan_configuration' => ['enabled' => true, 'forbidden_qr_scan_gateway' => []],
                'convenience_configuration' => ['enabled' => true],
                'paidy_configuration' => ['enabled' => false],
                'recurring_token_configuration' => [
                    'recurring_type' => 'bounded', 'charge_wait_period' => 'P1D',
                    'card_charge_cvv_confirmation' => ['enabled' => true, 'threshold' => 5000],
                ],
                'security_configuration' => [
                    'inspect_suspicious_login_after' => 3, 'refund_percent_limit' => 100,
                    'limit_charge_by_card_configuration' => null, 'confirmation_required' => false,
                ],
                'installments_configuration' => [
                    'enabled' => true, 'min_charge_amount' => 1000, 'max_payout_period' => 12,
                ],
                'card_brand_percent_fees' => [
                    'visa' => 3.5, 'american_express' => 4.0, 'mastercard' => 3.5, 'maestro' => 3.5,
                    'discover' => 3.5, 'jcb' => 3.5, 'diners_club' => 3.5, 'union_pay' => 3.5,
                ],
            ],
        ]));

        $this->assertInstanceOf(Configuration::class, $store->configuration);
        $this->assertSame('JP', $store->configuration->country);
    }

    public function testGetChargeUsesTheStoresOwnIdAsTheStoreIdArgument()
    {
        $bridge = $this->bridge();
        $store = $this->parseStore($this->storeJson(['id' => 'store-XYZ']), $this->context($bridge));

        $chargeJson = [
            'id' => 'charge-1', 'store_id' => 'store-XYZ', 'transaction_token_id' => 'token-1',
            'transaction_token_type' => 'one_time', 'subscription_id' => null,
            'requested_amount' => 1000, 'requested_currency' => 'JPY',
            'requested_amount_formatted' => 1000, 'status' => 'successful', 'mode' => 'test',
            'created_on' => '2022-07-26T10:33:12.934225Z', 'metadata' => [],
        ];
        $fake = new FakeChargesApiForStoreTest($bridge->caller(), [(string) json_encode($chargeJson)]);
        $this->injectFakeChargesApi($bridge, $fake);

        $charge = $store->getCharge('charge-1');

        $this->assertInstanceOf(Charge::class, $charge);
        $this->assertSame(['store-XYZ', 'charge-1'], $fake->calls[0]['args']);
    }

    public function testGetSubscriptionUsesTheStoresOwnIdAsTheStoreIdArgument()
    {
        $bridge = $this->bridge();
        $store = $this->parseStore($this->storeJson(['id' => 'store-XYZ']), $this->context($bridge));

        $subscriptionJson = [
            'id' => 'sub-1', 'store_id' => 'store-XYZ', 'transaction_token_id' => 'token-1',
            'amount' => 1000, 'currency' => 'JPY', 'amount_formatted' => 1000, 'period' => 'monthly',
            'schedule_settings' => ['zone_id' => 'Asia/Tokyo'], 'payments_left' => 12,
            'status' => 'unverified', 'metadata' => [], 'mode' => 'test', 'amount_left' => 12000,
            'amount_left_formatted' => 12000, 'created_on' => '2022-07-26T10:33:12.934225Z',
        ];
        $fake = new FakeSubscriptionsApiForStoreTest($bridge->caller(), [(string) json_encode($subscriptionJson)]);
        $this->injectFakeSubscriptionsApi($bridge, $fake);

        $subscription = $store->getSubscription('sub-1');

        $this->assertInstanceOf(Subscription::class, $subscription);
        $this->assertSame(['store-XYZ', 'sub-1'], $fake->calls[0]['args']);
    }

    public function testGetCustomerIdCallsCreateCustomerIdAndReturnsTheDerivedUuid()
    {
        $bridge = $this->bridge();
        $store = $this->parseStore($this->storeJson(), $this->context($bridge));

        $fake = new FakeStoresApiForStoreTest(
            $bridge->caller(),
            [(string) json_encode(['customer_id' => 'derived-uuid-1'])]
        );
        $this->injectFakeStoresApi($bridge, $fake);

        $customerId = $store->getCustomerId('local-customer-1');

        $this->assertSame('derived-uuid-1', $customerId);
        $this->assertSame('createCustomerId', $fake->calls[0]['method']);
        $this->assertSame('store-1', $fake->calls[0]['args'][0]);
        $this->assertInstanceOf(CreateCustomerIdRequest::class, $fake->calls[0]['args'][1]);
        $this->assertSame('local-customer-1', $fake->calls[0]['args'][1]->getCustomerId());
    }

    public function testUpdateThrowsUnsupportedNoStoreUpdateEndpointEverExisted()
    {
        $store = $this->parseStore($this->storeJson());

        $this->expectException(UnivapayUnsupportedFeatureError::class);

        $store->update(['name' => 'New Name']);
    }

    public function testListTransactionsPageDispatchesToStoreScopedTransactionHistoryApi()
    {
        $bridge = $this->bridge();
        $store = $this->parseStore($this->storeJson(['id' => 'store-XYZ']), $this->context($bridge));

        $fake = new FakeTransactionHistoryApiForStoreTest(
            $bridge->caller(),
            [(string) json_encode(['items' => [], 'has_more' => false])]
        );
        $this->injectFakeTransactionHistoryApi($bridge, $fake);

        $page = $store->listTransactions();

        $this->assertSame('listStoreTransactionHistory', $fake->calls[0]['method']);
        $this->assertSame('store-XYZ', $fake->calls[0]['args'][0]);
        $this->assertSame([], $page->items);
        $this->assertFalse($page->hasMore);
    }

    public function testListChargesPageDelegatesToStoreScopedListDispatcherEndpoint()
    {
        $bridge = $this->bridge();
        $store = $this->parseStore($this->storeJson(['id' => 'store-XYZ']), $this->context($bridge));

        $fake = new FakeChargesApiForStoreTest(
            $bridge->caller(),
            [(string) json_encode(['items' => [], 'has_more' => false])]
        );
        $this->injectFakeChargesApi($bridge, $fake);

        $page = $store->listCharges();

        $this->assertSame('listStoreCharges', $fake->calls[0]['method']);
        $this->assertSame('store-XYZ', $fake->calls[0]['args'][0]);
        $this->assertSame([], $page->items);
        $this->assertFalse($page->hasMore);
    }

    // --- fixture plumbing ------------------------------------------------------------------------

    private function injectFakeChargesApi(Bridge $bridge, ChargesApi $fake): void
    {
        $property = new ReflectionProperty(Bridge::class, 'chargesApi');
        $property->setAccessible(true);
        $property->setValue($bridge, $fake);
    }

    private function injectFakeSubscriptionsApi(Bridge $bridge, SubscriptionsApi $fake): void
    {
        $property = new ReflectionProperty(Bridge::class, 'subscriptionsApi');
        $property->setAccessible(true);
        $property->setValue($bridge, $fake);
    }

    private function injectFakeStoresApi(Bridge $bridge, StoresApi $fake): void
    {
        $property = new ReflectionProperty(Bridge::class, 'storesApi');
        $property->setAccessible(true);
        $property->setValue($bridge, $fake);
    }

    private function injectFakeTransactionHistoryApi(Bridge $bridge, TransactionHistoryApi $fake): void
    {
        $property = new ReflectionProperty(Bridge::class, 'transactionHistoryApi');
        $property->setAccessible(true);
        $property->setValue($bridge, $fake);
    }
}

class FakeChargesApiForStoreTest extends ChargesApi
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

    public function getCharge(string $storeId, string $id, ?bool $polling = null): ApiResponse
    {
        return $this->respond('getCharge', [$storeId, $id]);
    }

    public function listStoreCharges(
        string $storeId,
        ?int $limit = 10,
        ?string $cursor = null,
        ?string $cursorDirection = null,
        $lastFour = null,
        $name = null,
        $expMonth = null,
        $expYear = null,
        $from = null,
        $to = null,
        $email = null,
        $phone = null,
        $amountFrom = null,
        $amountTo = null,
        $currency = null,
        $mode = null,
        $metadata = null,
        $transactionTokenId = null
    ): ApiResponse {
        return $this->respond('listStoreCharges', [$storeId]);
    }

    private function respond(string $method, array $args): ApiResponse
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }
}

class FakeSubscriptionsApiForStoreTest extends SubscriptionsApi
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
        $this->calls[] = ['method' => 'getSubscription', 'args' => [$storeId, $id]];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }
}

class FakeStoresApiForStoreTest extends StoresApi
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

    public function createCustomerId(string $storeId, CreateCustomerIdRequest $body): ApiResponse
    {
        $this->calls[] = ['method' => 'createCustomerId', 'args' => [$storeId, $body]];
        $responseBody = array_shift($this->responses);
        $this->apiCaller->recordResponse($responseBody ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $responseBody);
    }
}

class FakeTransactionHistoryApiForStoreTest extends TransactionHistoryApi
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
        $this->calls[] = ['method' => 'listStoreTransactionHistory', 'args' => [$storeId]];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }
}
