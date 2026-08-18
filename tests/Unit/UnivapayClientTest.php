<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit;

use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use UnivaPay\Apis\ChargesApi;
use UnivaPay\Apis\CheckoutApi;
use UnivaPay\Apis\MerchantsApi;
use UnivaPay\Apis\StoresApi;
use UnivaPay\Apis\SubscriptionsApi;
use UnivaPay\Apis\TransactionTokensApi;
use UnivaPay\Exceptions\ApiException;
use UnivaPay\Http\ApiResponse;
use UnivaPay\Http\HttpRequest;
use UnivaPay\Http\HttpResponse;
use UnivaPay\Models\ChargeCreateRequest;
use UnivaPay\Models\SubscriptionCreateRequest;
use UnivaPay\Models\SubscriptionSimulationRequest;
use UnivaPay\Models\TransactionTokenCreateRequest;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Enums\Period;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Errors\UnivapayNotFoundError;
use Univapay\Compat\Errors\UnivapaySDKError;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Resources\Charge;
use Univapay\Compat\Resources\CheckoutInfo;
use Univapay\Compat\Resources\Merchant;
use Univapay\Compat\Resources\PaymentMethod\CardPayment;
use Univapay\Compat\Resources\SimpleList;
use Univapay\Compat\Resources\Store;
use Univapay\Compat\Resources\Subscription;
use Univapay\Compat\Resources\Subscription\ScheduleSettings;
use Univapay\Compat\Resources\TransactionToken;
use Univapay\Compat\Support\ApiCaller;
use Univapay\Compat\Support\Bridge;
use Univapay\Compat\UnivapayClient;

/**
 * Covers `UnivapayClient` facade: construction, `getMe()`/`getStore()` hydration,
 * `getCheckoutInfo()`/`createSubscriptionSimulation()` (both fully supported), the
 * `getBankAccount()`/`getTransfer()` permanent-unsupported throws, `createToken()`'s RECURRING +
 * local-customer-id gate (old-parity non-RECURRING silent-ignore), and the two-step
 * `createCharge()`/`createSubscription()` flows -- GET-then-POST call ORDER and 404-before-POST
 * propagation, with preflight guards ported verbatim from the original SDK.
 *
 * Webhook parsing (`parseWebhookData()`) is covered separately in `UnivapayClientWebhookTest`.
 */
class UnivapayClientTest extends TestCase
{
    // --- fixture plumbing ------------------------------------------------------------------------

    private function token(array $payload): string
    {
        $header = base64_encode((string) json_encode(['alg' => 'none']));
        $body = base64_encode((string) json_encode($payload));
        return "$header.$body.sig";
    }

    private function storeJwt(): string
    {
        return $this->token([
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
    }

    private function merchantJwt(): string
    {
        return $this->token([
            'sub' => 'app_token',
            'iat' => 1,
            'merchant_id' => 'merchant-1',
            'creator_id' => 'creator-1',
            'version' => 1,
            'jti' => 'jti-1'
        ]);
    }

    private function storeClient(): UnivapayClient
    {
        return new UnivapayClient(AppJWT::createToken($this->storeJwt(), 'secret-1'));
    }

    private function merchantClient(): UnivapayClient
    {
        return new UnivapayClient(AppJWT::createToken($this->merchantJwt(), 'secret-1'));
    }

    private function bridgeOf(UnivapayClient $client): Bridge
    {
        $property = new ReflectionProperty(UnivapayClient::class, 'bridge');
        $property->setAccessible(true);
        return $property->getValue($client);
    }

    private function injectFake(UnivapayClient $client, string $apiProperty, $fake): void
    {
        $bridge = $this->bridgeOf($client);
        $property = new ReflectionProperty(Bridge::class, $apiProperty);
        $property->setAccessible(true);
        $property->setValue($bridge, $fake);
    }

    // --- construction --------------------------------------------------------------------------

    public function testConstructsAStoreScopedClientFromAStoreAppToken()
    {
        $client = $this->storeClient();

        $this->assertInstanceOf(UnivapayClient::class, $client);
        $this->assertSame('store-1', $this->bridgeOf($client)->storeId());
    }

    public function testConstructsAMerchantScopedClientFromAMerchantAppToken()
    {
        $client = $this->merchantClient();

        $this->assertNull($this->bridgeOf($client)->storeId());
        $this->assertSame('merchant-1', $this->bridgeOf($client)->merchantId());
    }

    // --- native(): off-ramp escape hatch to the underlying SDK client -----------------------------

    public function testNativeReturnsTheExactSameInstanceTheBridgeHolds()
    {
        $client = $this->storeClient();

        $this->assertSame($this->bridgeOf($client)->client(), $client->native());
    }

    public function testNativeReturnsTheSameInstanceAcrossCalls()
    {
        $client = $this->storeClient();

        $this->assertSame($client->native(), $client->native());
    }

    public function testNativeConfigParityMatchesWhatAppJwtAndOptionsSupplied()
    {
        $client = $this->storeClient();

        $native = $client->native();

        $this->assertSame('https://api.univapay.com', $native->getBaseUrl());
        $this->assertSame(10, $native->getTimeout());
        $credentials = $native->getBearerAuthCredentials();
        $this->assertSame('secret-1', $credentials->getSecretKey());
        $this->assertSame($this->storeJwt(), $credentials->getJwtToken());
    }

    // --- getMe() / getStore() --------------------------------------------------------------------

    public function testGetMeHydratesAMerchant()
    {
        $client = $this->merchantClient();
        $fake = new FakeMerchantsApi($this->bridgeOf($client)->caller(), [(string) json_encode([
            'id' => 'merchant-1',
            'verification_data_id' => 'vdid-1',
            'name' => 'My Merchant',
            'email' => 'merchant@example.com',
            'verified' => true,
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
            'created_on' => '2020-01-01T00:00:00.000000Z',
        ])]);
        $this->injectFake($client, 'merchantsApi', $fake);

        $merchant = $client->getMe();

        $this->assertInstanceOf(Merchant::class, $merchant);
        $this->assertSame('merchant-1', $merchant->id);
        $this->assertSame(['getCurrentMerchant'], array_column($fake->calls, 'method'));
    }

    public function testGetStoreHydratesAStore()
    {
        $client = $this->storeClient();
        $fake = new FakeStoresApi($this->bridgeOf($client)->caller(), [(string) json_encode([
            'id' => 'store-1',
            'name' => 'My Store',
            'created_on' => '2020-01-01T00:00:00.000000Z',
        ])]);
        $this->injectFake($client, 'storesApi', $fake);

        $store = $client->getStore('store-1');

        $this->assertInstanceOf(Store::class, $store);
        $this->assertSame('getStore', $fake->calls[0]['method']);
        $this->assertSame(['store-1'], $fake->calls[0]['args']);
    }

    // --- getCheckoutInfo() / getBankAccount() / createSubscriptionSimulation() --------------------

    public function testGetCheckoutInfoRequiresStoreAppTokenBeforeCallingCheckoutApi()
    {
        $merchantClient = $this->merchantClient();

        try {
            $merchantClient->getCheckoutInfo();
            $this->fail('Expected a UnivapaySDKError (REQUIRES_STORE_APP_TOKEN)');
        } catch (UnivapaySDKError $e) {
            $this->assertNotInstanceOf(UnivapayUnsupportedFeatureError::class, $e);
        }

        $storeClient = $this->storeClient();
        $fake = new FakeCheckoutApi($this->bridgeOf($storeClient)->caller(), [(string) json_encode(
            $this->checkoutInfoJson()
        )]);
        $this->injectFake($storeClient, 'checkoutApi', $fake);

        $checkoutInfo = $storeClient->getCheckoutInfo();

        $this->assertInstanceOf(CheckoutInfo::class, $checkoutInfo);
        $this->assertSame(['getCheckoutInfo'], array_column($fake->calls, 'method'));
    }

    public function testGetBankAccountThrowsPermanently()
    {
        $client = $this->storeClient();

        $this->expectException(UnivapayUnsupportedFeatureError::class);
        $client->getBankAccount('bank-account-1');
    }

    public function testCreateSubscriptionSimulationDispatchesToTheStoreScopedEndpointAndHydratesASimpleList()
    {
        $client = $this->storeClient();
        $fake = new FakeSubscriptionsApiForClientTest($this->bridgeOf($client)->caller(), [(string) json_encode([
            [
                'due_date' => '2030-02-01', 'zone_id' => 'Asia/Tokyo', 'amount' => 1000,
                'currency' => 'JPY', 'is_paid' => false, 'is_last_payment' => false,
            ],
            [
                'due_date' => '2030-03-01', 'zone_id' => 'Asia/Tokyo', 'amount' => 1000,
                'currency' => 'JPY', 'is_paid' => false, 'is_last_payment' => true,
            ],
        ])]);
        $this->injectFake($client, 'subscriptionsApi', $fake);

        $simulation = $client->createSubscriptionSimulation(
            PaymentType::CARD(),
            new Money(1000, new Currency('JPY')),
            Period::MONTHLY(),
            null,
            new ScheduleSettings()
        );

        $this->assertInstanceOf(SimpleList::class, $simulation);
        $this->assertCount(2, $simulation->items);
        $this->assertTrue($simulation->items[1]->isLastPayment);
        $this->assertSame('simulateStoreSubscriptionPlan', $fake->calls[0]['method']);
        $this->assertSame('store-1', $fake->calls[0]['args'][0]);
        $this->assertInstanceOf(SubscriptionSimulationRequest::class, $fake->calls[0]['args'][2]);
    }

    private function checkoutInfoJson(): array
    {
        return [
            'mode' => 'test',
            'recurring_token_privilege' => 'bounded',
            'name' => 'My Store',
            'subscription_configuration' => ['enabled' => true],
            'card_configuration' => [
                'enabled' => true, 'debit_enabled' => true, 'prepaid_enabled' => false,
                'only_direct_currency' => false, 'forbidden_card_brands' => [],
                'allowed_countries_by_ip' => null, 'foreign_cards_allowed' => true,
                'fail_on_new_email' => false, 'card_limit' => null, 'allow_empty_cvv' => false,
            ],
            'qr_scan_configuration' => ['enabled' => true, 'forbidden_qr_scan_gateway' => []],
            'convenience_configuration' => ['enabled' => true],
            'online_configuration' => ['enabled' => true],
            'paidy_configuration' => ['enabled' => false],
            'paidy_public_key' => null,
            'supported_brands' => [],
            'logo_image' => null,
            'theme' => [
                'colors' => [
                    'main_background' => '#fff', 'secondary_background' => '#eee',
                    'main_color' => '#000', 'main_text' => '#111', 'primary_text' => '#222',
                    'secondary_text' => '#333', 'base_text' => '#444',
                ],
            ],
        ];
    }

    public function testGetTransferThrowsPermanentlyUnsupported()
    {
        $client = $this->storeClient();

        $this->expectException(UnivapayUnsupportedFeatureError::class);

        $client->getTransfer('transfer-1');
    }

    // --- createToken(): RECURRING + local-customer-id gate --------------------------------------

    public function testCreateTokenIgnoresLocalCustomerIdForNonRecurringTypesOldParity()
    {
        $client = $this->storeClient();
        $fake = new FakeTransactionTokensApiForClientTest($this->bridgeOf($client)->caller(), [(string) json_encode([
            'id' => 'token-1',
            'store_id' => 'store-1',
            'email' => 'test@test.com',
            'active' => true,
            'payment_type' => 'card',
            'mode' => 'test',
            'type' => 'one_time',
            'confirmed' => null,
            'created_on' => '2030-01-01T00:00:00.000000Z',
            'metadata' => null,
            'data' => [
                'card' => [
                    'cardholder' => 'PHP TEST', 'exp_month' => 2, 'exp_year' => 2030,
                    'last_four' => '1831', 'brand' => 'mastercard', 'country' => 'JP',
                    'card_type' => 'credit', 'category' => 'signature',
                    'issuer' => 'BANCO', 'sub_brand' => 'none'
                ],
                'billing' => [
                    'line1' => 'test line 1', 'line2' => null, 'state' => 'tokyo',
                    'city' => 'test city', 'country' => 'JP', 'zip' => '101-1111',
                    'phone_number' => null
                ],
                'cvv_authorize' => ['enabled' => false, 'status' => null, 'charge_id' => null,
                    'credentials_id' => null, 'currency' => null],
                'three_ds' => ['enabled' => false, 'redirect_endpoint' => null, 'status' => null,
                    'redirect_id' => null, 'error' => null]
            ],
        ])]);
        $this->injectFake($client, 'tokensApi', $fake);

        $payment = new CardPayment('test@test.com', 'PHP TEST', '4111111111111111', '02', '2030', '123');
        // ONE_TIME (the default token type -- see PaymentMethod ctor) + a non-null local customer
        // id: old code's RECURRING gate is not satisfied, so this must proceed WITHOUT ever
        // calling Store::getCustomerId() (no fake StoresApi is injected -- a real HTTP attempt
        // would fail/hang in this test environment, so reaching it would surface as a failure).
        $token = $client->createToken($payment, 'local-customer-1');

        $this->assertInstanceOf(TransactionToken::class, $token);
        $this->assertSame('createTransactionToken', $fake->calls[0]['method']);
        /** @var TransactionTokenCreateRequest $request */
        $request = $fake->calls[0]['args'][0];
        $this->assertInstanceOf(TransactionTokenCreateRequest::class, $request);
    }

    public function testCreateTokenRecurringWithLocalCustomerIdReachesStoreGetCustomerIdAndInjectsMetadata()
    {
        $client = $this->storeClient();
        $storeFake = new FakeStoresApi($this->bridgeOf($client)->caller(), [
            (string) json_encode([
                'id' => 'store-1',
                'name' => 'My Store',
                'created_on' => '2020-01-01T00:00:00.000000Z',
            ]),
            (string) json_encode(['customer_id' => 'derived-uuid-1']),
        ]);
        $this->injectFake($client, 'storesApi', $storeFake);

        $tokensFake = new FakeTransactionTokensApiForClientTest($this->bridgeOf($client)->caller(), [
            (string) json_encode($this->tokenJson(['type' => 'recurring'])),
        ]);
        $this->injectFake($client, 'tokensApi', $tokensFake);

        $payment = new CardPayment(
            'test@test.com',
            'PHP TEST',
            '4111111111111111',
            '02',
            '2030',
            '123',
            TokenType::RECURRING()
        );

        $token = $client->createToken($payment, 'local-customer-1');

        $this->assertInstanceOf(TransactionToken::class, $token);
        // The store GET (old parity: getStore($storeId)->getCustomerId(...)) DID happen before
        // createCustomerId(), which happened before the token create POST.
        $this->assertSame(['getStore', 'createCustomerId'], array_column($storeFake->calls, 'method'));
        /** @var TransactionTokenCreateRequest $request */
        $request = $tokensFake->calls[0]['args'][0];
        $this->assertInstanceOf(TransactionTokenCreateRequest::class, $request);
        // gopay-customer-id metadata injection (old-SDK RECURRING+local-customer-id parity, see
        // UnivapayClient::createToken()'s class doc).
        $this->assertSame('derived-uuid-1', $request->getMetadata()->findAdditionalProperty('gopay-customer-id'));
    }

    // --- two-step createCharge()/createSubscription() -------------------------------------------

    public function testCreateChargeIssuesTheTokenGetBeforeThePostAndHydratesACharge()
    {
        $client = $this->storeClient();
        $tokenJson = $this->tokenJson();
        $chargeJson = [
            'id' => 'charge-1', 'store_id' => 'store-1', 'transaction_token_id' => 'token-1',
            'transaction_token_type' => 'one_time', 'subscription_id' => null,
            'requested_amount' => 1000, 'requested_currency' => 'JPY',
            'requested_amount_formatted' => 1000, 'status' => 'successful', 'mode' => 'test',
            'created_on' => '2022-07-26T10:33:12.934225Z', 'metadata' => [],
        ];
        $tokensFake = new FakeTransactionTokensApiForClientTest(
            $this->bridgeOf($client)->caller(),
            [(string) json_encode($tokenJson)]
        );
        $chargesFake = new FakeChargesApiForClientTest(
            $this->bridgeOf($client)->caller(),
            [(string) json_encode($chargeJson)]
        );
        $this->injectFake($client, 'tokensApi', $tokensFake);
        $this->injectFake($client, 'chargesApi', $chargesFake);

        $charge = $client->createCharge('token-1', new Money(1000, new Currency('JPY')));

        $this->assertInstanceOf(Charge::class, $charge);
        // GET-then-POST order: the preflight token fetch must be recorded before the charge create.
        $this->assertSame('getTransactionToken', $tokensFake->calls[0]['method']);
        $this->assertSame(['store-1', 'token-1'], $tokensFake->calls[0]['args']);
        $this->assertSame('createCharge', $chargesFake->calls[0]['method']);
        $this->assertInstanceOf(ChargeCreateRequest::class, $chargesFake->calls[0]['args'][1]);
        $this->assertSame('token-1', $chargesFake->calls[0]['args'][1]->getTransactionTokenId());
    }

    public function testCreateChargePropagates404AsNotFoundBeforeAnyPostIsIssuedNoTokenAvailable()
    {
        $client = $this->storeClient();
        $tokensFake = new FakeTransactionTokensApiForClientTest($this->bridgeOf($client)->caller(), []);
        $tokensFake->throwNotFoundOnNextCall = true;
        $chargesFake = new FakeChargesApiForClientTest($this->bridgeOf($client)->caller(), []);
        $this->injectFake($client, 'tokensApi', $tokensFake);
        $this->injectFake($client, 'chargesApi', $chargesFake);

        try {
            $client->createCharge('missing-token', new Money(1000, new Currency('JPY')));
            $this->fail('Expected a UnivapayNotFoundError');
        } catch (UnivapayNotFoundError $e) {
            // fall through to the assertions below
        }

        $this->assertSame([], $chargesFake->calls, '404 on the preflight GET must precede any POST');
    }

    public function testCreateSubscriptionIssuesTheTokenGetBeforeThePostAndHydratesASubscription()
    {
        $client = $this->storeClient();
        $tokenJson = $this->tokenJson(['type' => 'subscription']);
        $subscriptionJson = [
            'id' => 'sub-1', 'store_id' => 'store-1', 'transaction_token_id' => 'token-1',
            'amount' => 1000, 'currency' => 'JPY', 'amount_formatted' => 1000, 'period' => 'monthly',
            'schedule_settings' => ['zone_id' => 'Asia/Tokyo'], 'payments_left' => 12,
            'status' => 'unverified', 'metadata' => [], 'mode' => 'test', 'amount_left' => 12000,
            'amount_left_formatted' => 12000, 'created_on' => '2022-07-26T10:33:12.934225Z',
        ];
        $tokensFake = new FakeTransactionTokensApiForClientTest(
            $this->bridgeOf($client)->caller(),
            [(string) json_encode($tokenJson)]
        );
        $subscriptionsFake = new FakeSubscriptionsApiForClientTest(
            $this->bridgeOf($client)->caller(),
            [(string) json_encode($subscriptionJson)]
        );
        $this->injectFake($client, 'tokensApi', $tokensFake);
        $this->injectFake($client, 'subscriptionsApi', $subscriptionsFake);

        $subscription = $client->createSubscription(
            'token-1',
            new Money(1000, new Currency('JPY')),
            Period::MONTHLY()
        );

        $this->assertInstanceOf(Subscription::class, $subscription);
        $this->assertSame('getTransactionToken', $tokensFake->calls[0]['method']);
        $this->assertSame('createSubscription', $subscriptionsFake->calls[0]['method']);
        $this->assertInstanceOf(SubscriptionCreateRequest::class, $subscriptionsFake->calls[0]['args'][1]);
    }

    public function testGetLatestChargeForSubscriptionRoutesThroughGetSubscriptionLatestCharge()
    {
        $client = $this->storeClient();
        $chargeJson = [
            'id' => 'charge-1', 'store_id' => 'store-1', 'transaction_token_id' => 'token-1',
            'transaction_token_type' => 'one_time', 'subscription_id' => 'sub-1',
            'requested_amount' => 1000, 'requested_currency' => 'JPY',
            'requested_amount_formatted' => 1000, 'status' => 'successful', 'mode' => 'test',
            'created_on' => '2022-07-26T10:33:12.934225Z', 'metadata' => [],
        ];
        $subscriptionsFake = new FakeSubscriptionsApiForClientTest(
            $this->bridgeOf($client)->caller(),
            [(string) json_encode($chargeJson)]
        );
        $this->injectFake($client, 'subscriptionsApi', $subscriptionsFake);

        $charge = $client->getLatestChargeForSubscription('store-1', 'sub-1');

        $this->assertInstanceOf(Charge::class, $charge);
        $this->assertSame('getSubscriptionLatestCharge', $subscriptionsFake->calls[0]['method']);
        $this->assertSame(['store-1', 'sub-1'], $subscriptionsFake->calls[0]['args']);
    }

    // --- fixtures --------------------------------------------------------------------------------

    private function tokenJson(array $overrides = []): array
    {
        return array_replace([
            'id' => 'token-1',
            'store_id' => 'store-1',
            'email' => 'test@test.com',
            'active' => true,
            'payment_type' => 'card',
            'mode' => 'test',
            'type' => 'one_time',
            'confirmed' => null,
            'created_on' => '2030-01-01T00:00:00.000000Z',
            'metadata' => null,
            'data' => [
                'card' => [
                    'cardholder' => 'PHP TEST', 'exp_month' => 2, 'exp_year' => 2030,
                    'last_four' => '1831', 'brand' => 'mastercard', 'country' => 'JP',
                    'card_type' => 'credit', 'category' => 'signature',
                    'issuer' => 'BANCO', 'sub_brand' => 'none'
                ],
                'billing' => [
                    'line1' => 'test line 1', 'line2' => null, 'state' => 'tokyo',
                    'city' => 'test city', 'country' => 'JP', 'zip' => '101-1111',
                    'phone_number' => null
                ],
                'cvv_authorize' => ['enabled' => false, 'status' => null, 'charge_id' => null,
                    'credentials_id' => null, 'currency' => null],
                'three_ds' => ['enabled' => false, 'redirect_endpoint' => null, 'status' => null,
                    'redirect_id' => null, 'error' => null]
            ],
        ], $overrides);
    }
}

// --- fake generated-controller doubles -----------------------------------------------------------

class FakeMerchantsApi extends MerchantsApi
{
    public $calls = [];
    private $apiCaller;
    private $responses;

    public function __construct(ApiCaller $apiCaller, array $responses)
    {
        $this->apiCaller = $apiCaller;
        $this->responses = $responses;
    }

    public function getCurrentMerchant(): ApiResponse
    {
        $this->calls[] = ['method' => 'getCurrentMerchant', 'args' => []];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }
}

class FakeStoresApi extends StoresApi
{
    public $calls = [];
    private $apiCaller;
    private $responses;

    public function __construct(ApiCaller $apiCaller, array $responses)
    {
        $this->apiCaller = $apiCaller;
        $this->responses = $responses;
    }

    public function getStore(string $id): ApiResponse
    {
        $this->calls[] = ['method' => 'getStore', 'args' => [$id]];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }

    public function createCustomerId(string $storeId, \UnivaPay\Models\CreateCustomerIdRequest $body): ApiResponse
    {
        $this->calls[] = ['method' => 'createCustomerId', 'args' => [$storeId, $body]];
        $responseBody = array_shift($this->responses);
        $this->apiCaller->recordResponse($responseBody ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $responseBody);
    }
}

class FakeTransactionTokensApiForClientTest extends TransactionTokensApi
{
    public $calls = [];
    public $throwNotFoundOnNextCall = false;
    private $apiCaller;
    private $responses;

    public function __construct(ApiCaller $apiCaller, array $responses)
    {
        $this->apiCaller = $apiCaller;
        $this->responses = $responses;
    }

    public function getTransactionToken(string $storeId, string $id, ?bool $polling = null): ApiResponse
    {
        if ($this->throwNotFoundOnNextCall) {
            $request = new HttpRequest('GET', [], "https://api.univapay.com/stores/$storeId/tokens/$id", []);
            throw new ApiException('Not Found', $request, new HttpResponse(404, [], ''));
        }
        $this->calls[] = ['method' => 'getTransactionToken', 'args' => [$storeId, $id]];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }

    public function createTransactionToken(
        TransactionTokenCreateRequest $body,
        ?string $idempotencyKey = null
    ): ApiResponse {
        $this->calls[] = ['method' => 'createTransactionToken', 'args' => [$body, $idempotencyKey]];
        $responseBody = array_shift($this->responses);
        $this->apiCaller->recordResponse($responseBody ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $responseBody);
    }
}

class FakeChargesApiForClientTest extends ChargesApi
{
    public $calls = [];
    private $apiCaller;
    private $responses;

    public function __construct(ApiCaller $apiCaller, array $responses)
    {
        $this->apiCaller = $apiCaller;
        $this->responses = $responses;
    }

    public function createCharge(?string $idempotencyKey = null, ?ChargeCreateRequest $body = null): ApiResponse
    {
        $this->calls[] = ['method' => 'createCharge', 'args' => [$idempotencyKey, $body]];
        $responseBody = array_shift($this->responses);
        $this->apiCaller->recordResponse($responseBody ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $responseBody);
    }

    public function getCharge(string $storeId, string $id, ?bool $polling = null): ApiResponse
    {
        $this->calls[] = ['method' => 'getCharge', 'args' => [$storeId, $id]];
        $responseBody = array_shift($this->responses);
        $this->apiCaller->recordResponse($responseBody ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $responseBody);
    }
}

class FakeSubscriptionsApiForClientTest extends SubscriptionsApi
{
    public $calls = [];
    private $apiCaller;
    private $responses;

    public function __construct(ApiCaller $apiCaller, array $responses)
    {
        $this->apiCaller = $apiCaller;
        $this->responses = $responses;
    }

    public function createSubscription(
        ?string $idempotencyKey = null,
        ?SubscriptionCreateRequest $body = null
    ): ApiResponse {
        $this->calls[] = ['method' => 'createSubscription', 'args' => [$idempotencyKey, $body]];
        $responseBody = array_shift($this->responses);
        $this->apiCaller->recordResponse($responseBody ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $responseBody);
    }

    public function getSubscriptionLatestCharge(string $storeId, string $subscriptionId): ApiResponse
    {
        $this->calls[] = ['method' => 'getSubscriptionLatestCharge', 'args' => [$storeId, $subscriptionId]];
        $responseBody = array_shift($this->responses);
        $this->apiCaller->recordResponse($responseBody ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $responseBody);
    }

    public function simulateStoreSubscriptionPlan(
        string $storeId,
        ?string $idempotencyKey = null,
        ?SubscriptionSimulationRequest $body = null
    ): ApiResponse {
        $this->calls[] = ['method' => 'simulateStoreSubscriptionPlan', 'args' => [$storeId, $idempotencyKey, $body]];
        $responseBody = array_shift($this->responses);
        $this->apiCaller->recordResponse($responseBody ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $responseBody);
    }
}

class FakeCheckoutApi extends CheckoutApi
{
    public $calls = [];
    private $apiCaller;
    private $responses;

    public function __construct(ApiCaller $apiCaller, array $responses)
    {
        $this->apiCaller = $apiCaller;
        $this->responses = $responses;
    }

    public function getCheckoutInfo(): ApiResponse
    {
        $this->calls[] = ['method' => 'getCheckoutInfo', 'args' => []];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }
}
