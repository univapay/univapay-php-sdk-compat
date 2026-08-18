<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources;

use DateInterval;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use UnivaPay\Apis\ChargesApi;
use UnivaPay\Apis\SubscriptionsApi;
use UnivaPay\Apis\TransactionTokensApi;
use UnivaPay\Http\ApiResponse;
use UnivaPay\Models\ChargeCreateRequest;
use UnivaPay\Models\EnableTokenThreeDsRequest;
use UnivaPay\Models\SubscriptionCreateRequest;
use UnivaPay\Models\TransactionTokenUpdateRequest;
use Univapay\Compat\Enums\CallMethod;
use Univapay\Compat\Enums\CardBrand;
use Univapay\Compat\Enums\CardCategory;
use Univapay\Compat\Enums\CardSubBrand;
use Univapay\Compat\Enums\CardType;
use Univapay\Compat\Enums\ChargeStatus;
use Univapay\Compat\Enums\ConvenienceStore;
use Univapay\Compat\Enums\CvvAuthorizationStatus;
use Univapay\Compat\Enums\OnlineBrand;
use Univapay\Compat\Enums\OsType;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Enums\Period;
use Univapay\Compat\Enums\QrBrand;
use Univapay\Compat\Enums\QrBrandMerchant;
use Univapay\Compat\Enums\SubscriptionStatus;
use Univapay\Compat\Enums\ThreeDSStatus;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Errors\UnivapayLogicError;
use Univapay\Compat\Errors\UnivapayValidationError;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Resources\Charge;
use Univapay\Compat\Resources\PaymentMethod\CardPaymentPatch;
use Univapay\Compat\Resources\PaymentMethod\PaymentMethodPatch;
use Univapay\Compat\Resources\Subscription;
use Univapay\Compat\Resources\TransactionToken;
use Univapay\Compat\Support\ApiCaller;
use Univapay\Compat\Support\Bridge;
use Univapay\Compat\Support\CompatContext;

/**
 * Covers `TransactionToken`:
 *  - Hydration fixtures for every payment-type `data` union variant (CARD/APPLE_PAY -> CardData,
 *    KONBINI -> ConvenienceStoreData, QR_SCAN -> QrScanData, QR_MERCHANT -> QrMerchantData,
 *    PAIDY -> PaidyData, ONLINE -> OnlineData), lifted from the old SDK's
 *    `tests/Unit/Resources/PaymentData/CardDataTest.php` fixture shape and
 *    `tests/Univapay/Integration/TransactionTokenTest.php`'s assertions.
 *  - patch()/deactivate()/threeDSIssuerToken() driven through a REAL `Support\ApiCaller` (exactly
 *    like `tests/Unit/Support/ApiCallerTest.php`'s own style -- a genuine ApiCaller instance, not a
 *    mock, fed a closure that manually calls `recordResponse()`) via a hand-written
 *    `FakeTransactionTokensApi` substituted onto a real `Bridge` by reflection (same
 *    `ReflectionProperty::setAccessible()` technique `Support\RequestModelFactory::
 *    setPrivateProperty()` already uses in this codebase). `Bridge`/`ApiCaller` are both `final`
 *    and `Bridge::tokens(): TransactionTokensApi` is return-type-checked at runtime, so the fake
 *    must be a real `TransactionTokensApi` subclass -- see `FakeTransactionTokensApi`'s doc below.
 *  - Guard tests (RECURRING-only for enableThreeDS, CVV/capture/subscription preflight guards) --
 *    error class + `Reason`/`Field` message parity.
 *  - `awaitResult()`/`fetchWithPolling()`: `TransactionToken` overrides the generic `Pollable`
 *    implementation entirely, since it has no `$this->status` for the generic trait to key a
 *    transition map off of -- see `Pollable::awaitResult()`'s call order.
 *  - `createCharge()`/`createSubscription()`'s SUCCESS path, hydrating a real
 *    `Univapay\Compat\Resources\Charge`/`Subscription` through `callAndHydrate()` (see the
 *    dedicated section further down), plus their preflight-guard FAILURE paths.
 */
class TransactionTokenTest extends TestCase
{
    // --- fixture plumbing -----------------------------------------------------------------------

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

    /**
     * Substitutes a real Bridge's memoized TransactionTokensApi with $fake, so a resource method
     * calling `$this->context->bridge()->tokens()` reaches $fake instead of ever performing a
     * real HTTP request. Mirrors `Support\RequestModelFactory::setPrivateProperty()`'s established
     * technique in this codebase.
     */
    private function injectFakeTokensApi(Bridge $bridge, TransactionTokensApi $fake): void
    {
        $property = new ReflectionProperty(Bridge::class, 'tokensApi');
        $property->setAccessible(true);
        $property->setValue($bridge, $fake);
    }

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

    private function cardTokenJson(array $overrides = []): array
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
            'metadata' => ['customer_id' => 'PHP TEST'],
            'data' => [
                'card' => [
                    'cardholder' => 'PHP TEST',
                    'exp_month' => 2,
                    'exp_year' => 2030,
                    'last_four' => '1831',
                    'brand' => 'mastercard',
                    'country' => 'JP',
                    'card_type' => 'credit',
                    'category' => 'signature',
                    'issuer' => 'BANCO SANTANDER S.A.',
                    'sub_brand' => 'none'
                ],
                'billing' => [
                    'line1' => 'test line 1',
                    'line2' => 'test line 2',
                    'state' => 'tokyo',
                    'city' => 'test city',
                    'country' => 'JP',
                    'zip' => '101-1111',
                    'phone_number' => ['country_code' => 81, 'local_number' => '12910298309128']
                ],
                'cvv_authorize' => [
                    'enabled' => false,
                    'status' => null,
                    'charge_id' => null,
                    'credentials_id' => null,
                    'currency' => null
                ],
                'three_ds' => [
                    'enabled' => false,
                    'redirect_endpoint' => null,
                    'status' => null,
                    'redirect_id' => null,
                    'error' => null
                ]
            ]
        ], $overrides);
    }

    /**
     * Full CARD `data` sub-object (card/billing/cvv_authorize/three_ds), with `three_ds`/
     * `cvv_authorize` overridable -- `cardTokenJson()`'s own `array_replace()` only shallow-merges
     * at the top level, so passing a partial `'data' => [...]` override there would wholesale
     * replace `card`/`billing` too; this builds the complete shape instead.
     */
    private function cardDataJson(array $overrides = []): array
    {
        return array_replace([
            'card' => [
                'cardholder' => 'PHP TEST', 'exp_month' => 2, 'exp_year' => 2030,
                'last_four' => '1831', 'brand' => 'mastercard', 'country' => 'JP',
                'card_type' => 'credit', 'category' => 'signature',
                'issuer' => 'BANCO SANTANDER S.A.', 'sub_brand' => 'none'
            ],
            'billing' => [
                'line1' => 'test line 1', 'line2' => 'test line 2', 'state' => 'tokyo',
                'city' => 'test city', 'country' => 'JP', 'zip' => '101-1111',
                'phone_number' => ['country_code' => 81, 'local_number' => '12910298309128']
            ],
            'cvv_authorize' => [
                'enabled' => false, 'status' => null, 'charge_id' => null,
                'credentials_id' => null, 'currency' => null
            ],
            'three_ds' => [
                'enabled' => false, 'redirect_endpoint' => null, 'status' => null,
                'redirect_id' => null, 'error' => null
            ]
        ], $overrides);
    }

    private function parseToken(array $json, ?CompatContext $context = null): TransactionToken
    {
        return TransactionToken::getSchema()->parse($json, [$context ?? $this->context()]);
    }

    // --- hydration: CARD / APPLE_PAY (-> CardData) ---------------------------------------------

    public function testHydratesCardTokenIntoCardData()
    {
        $token = $this->parseToken($this->cardTokenJson());

        $this->assertSame('token-1', $token->id);
        $this->assertSame('store-1', $token->storeId);
        $this->assertSame('test@test.com', $token->email);
        $this->assertTrue($token->active);
        $this->assertEquals(PaymentType::CARD(), $token->paymentType);
        $this->assertEquals(TokenType::ONE_TIME(), $token->type);
        $this->assertNull($token->confirmed);
        $this->assertSame('PHP TEST', $token->metadata['customer_id']);

        $this->assertSame('PHP TEST', $token->data->card->cardholder);
        $this->assertEquals(CardBrand::MASTERCARD(), $token->data->card->brand);
        $this->assertEquals(CardType::CREDIT(), $token->data->card->cardType);
        $this->assertEquals(CardCategory::SIGNATURE(), $token->data->card->category);
        $this->assertEquals(CardSubBrand::NONE(), $token->data->card->subBrand);
        $this->assertSame('test line 1', $token->data->billing->line1);
        $this->assertSame('81', $token->data->billing->phoneNumber->countryCode);
        $this->assertFalse($token->data->cvvAuthorize->enabled);
        $this->assertFalse($token->data->threeDS->enabled);
    }

    public function testHydratesApplePayTokenIntoCardDataToo()
    {
        // Old-SDK-verbatim behavior: APPLE_PAY shares CardData's schema for `data`, distinct from
        // its paymentType -- see TransactionToken::initSchema()'s switch.
        $token = $this->parseToken($this->cardTokenJson(['payment_type' => 'apple_pay']));

        $this->assertEquals(PaymentType::APPLE_PAY(), $token->paymentType);
        $this->assertSame('PHP TEST', $token->data->card->cardholder);
    }

    public function testHydratesRecurringTokenWithCvvAuthorizeAndThreeDs()
    {
        $token = $this->parseToken($this->cardTokenJson([
            'type' => 'recurring',
            'data' => array_replace($this->cardTokenJson()['data'], [
                'cvv_authorize' => [
                    'enabled' => true,
                    'status' => 'pending',
                    'charge_id' => null,
                    'credentials_id' => null,
                    'currency' => 'USD'
                ],
                'three_ds' => [
                    'enabled' => true,
                    'redirect_endpoint' => 'https://test.int/endpoint?foo=bar',
                    'status' => 'pending',
                    'redirect_id' => '11efbdb4-6820-12dc-8246-6f01ed1243a9',
                    'error' => null
                ]
            ])
        ]));

        $this->assertEquals(TokenType::RECURRING(), $token->type);
        $this->assertTrue($token->data->cvvAuthorize->enabled);
        $this->assertEquals(CvvAuthorizationStatus::PENDING(), $token->data->cvvAuthorize->status);
        $this->assertEquals(new Currency('USD'), $token->data->cvvAuthorize->currency);
        $this->assertTrue($token->data->threeDS->enabled);
        $this->assertEquals(ThreeDSStatus::PENDING(), $token->data->threeDS->status);
        $this->assertSame('11efbdb4-6820-12dc-8246-6f01ed1243a9', $token->data->threeDS->redirectId);
    }

    // --- hydration: KONBINI (-> ConvenienceStoreData) -------------------------------------------

    public function testHydratesKonbiniTokenIntoConvenienceStoreData()
    {
        $token = $this->parseToken($this->cardTokenJson([
            'payment_type' => 'konbini',
            'data' => [
                'customer_name' => 'PHP test',
                'phone_number' => ['country_code' => 81, 'local_number' => '12910298309128'],
                'convenience_store' => 'seven_eleven',
                'expiration_period' => 'PT168H'
            ]
        ]));

        $this->assertEquals(PaymentType::KONBINI(), $token->paymentType);
        $this->assertSame('PHP test', $token->data->customerName);
        $this->assertSame('81', $token->data->phoneNumber->countryCode);
        $this->assertEquals(ConvenienceStore::SEVEN_ELEVEN(), $token->data->convenienceStore);
        $this->assertEquals(new DateInterval('PT168H'), $token->data->expirationPeriod);
    }

    // --- hydration: QR_SCAN / QR_MERCHANT ---------------------------------------------------------

    public function testHydratesQrScanTokenIntoQrScanData()
    {
        $token = $this->parseToken($this->cardTokenJson([
            'payment_type' => 'qr_scan',
            'data' => ['brand' => 'pay_pay']
        ]));

        $this->assertEquals(PaymentType::QR_SCAN(), $token->paymentType);
        $this->assertEquals(QrBrand::PAY_PAY(), $token->data->brand);
    }

    public function testHydratesQrMerchantTokenIntoQrMerchantData()
    {
        $token = $this->parseToken($this->cardTokenJson([
            'payment_type' => 'qr_merchant',
            'data' => ['brand' => 'alipay_merchant_qr']
        ]));

        $this->assertEquals(PaymentType::QR_MERCHANT(), $token->paymentType);
        $this->assertEquals(QrBrandMerchant::ALIPAY_MERCHANT_QR(), $token->data->brand);
    }

    // --- hydration: PAIDY (-> PaidyData) ----------------------------------------------------------

    public function testHydratesPaidyTokenIntoPaidyData()
    {
        $token = $this->parseToken($this->cardTokenJson([
            'payment_type' => 'paidy',
            'type' => 'recurring',
            'confirmed' => true,
            'data' => [
                'paidy_token' => 'paidy-token-1',
                'shipping_address' => [
                    'line1' => 'Address Line 1',
                    'line2' => 'Address Line 2',
                    'state' => 'State',
                    'city' => 'City',
                    'country' => 'JP',
                    'zip' => '1001000'
                ],
                'phone_number' => '08012345678'
            ]
        ]));

        $this->assertEquals(PaymentType::PAIDY(), $token->paymentType);
        $this->assertTrue($token->confirmed);
        $this->assertSame('Address Line 1', $token->data->shippingAddress->line1);
        // The wire's `phone_number` is a plain string (matching `TokenResponsePaidyData` in the
        // spec), not a nested `{country_code, local_number}` object -- unlike
        // CardData/ConvenienceStoreData's phone_number, which stay structured. See PaidyData's
        // class doc.
        $this->assertSame('08012345678', $token->data->phoneNumber);
    }

    // --- hydration: ONLINE (-> OnlineData) ---------------------------------------------------------

    public function testHydratesOnlineTokenIntoOnlineData()
    {
        $token = $this->parseToken($this->cardTokenJson([
            'payment_type' => 'online',
            'ip_address' => '127.0.0.1',
            'data' => [
                'brand' => 'we_chat_online',
                'call_method' => 'web',
                'user_identifier' => 'PHP TEST',
                'os_type' => 'android'
            ]
        ]));

        $this->assertEquals(PaymentType::ONLINE(), $token->paymentType);
        $this->assertSame('127.0.0.1', $token->ipAddress);
        $this->assertEquals(OnlineBrand::WE_CHAT_ONLINE(), $token->data->brand);
        $this->assertEquals(CallMethod::WEB(), $token->data->callMethod);
        $this->assertEquals(OsType::ANDROID(), $token->data->osType);
    }

    // --- patch()/deactivate()/threeDSIssuerToken(): fake-API-recording via a real ApiCaller ------

    public function testPatchSendsUpdateThenRefetchesAndBothCallsCarryTheStoreIdAndTokenId()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $token = $this->parseToken($this->cardTokenJson(), $context);

        $patchedJson = $this->cardTokenJson(['email' => 'changed@example.com']);
        $refetchedJson = $this->cardTokenJson([
            'email' => 'changed@example.com',
            'metadata' => ['customer_id' => 'PHP TESTER']
        ]);
        $fake = new FakeTransactionTokensApi($bridge->caller(), [
            (string) json_encode($patchedJson),
            (string) json_encode($refetchedJson)
        ]);
        $this->injectFakeTokensApi($bridge, $fake);

        $patch = new PaymentMethodPatch('changed@example.com', ['customer_id' => 'PHP TESTER']);
        $result = $token->patch($patch);

        $this->assertCount(2, $fake->calls);
        $this->assertSame('updateTransactionToken', $fake->calls[0]['method']);
        $this->assertSame(['store-1', 'token-1'], array_slice($fake->calls[0]['args'], 0, 2));
        $this->assertNotEmpty($fake->calls[0]['args'][2]); // idempotency key
        $this->assertInstanceOf(TransactionTokenUpdateRequest::class, $fake->calls[0]['args'][3]);
        $this->assertSame('changed@example.com', $fake->calls[0]['args'][3]->getEmail());

        $this->assertSame('getTransactionToken', $fake->calls[1]['method']);
        $this->assertSame(['store-1', 'token-1', null], $fake->calls[1]['args']);

        // update()->fetch() -- a NEW instance from the follow-up GET, not the PATCH response.
        $this->assertSame('PHP TESTER', $result->metadata['customer_id']);
    }

    public function testPatchWithCardPaymentPatchBuildsTheCvvRequest()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $token = $this->parseToken($this->cardTokenJson(['type' => 'recurring']), $context);

        $fake = new FakeTransactionTokensApi($bridge->caller(), [
            (string) json_encode($this->cardTokenJson(['type' => 'recurring'])),
            (string) json_encode($this->cardTokenJson(['type' => 'recurring']))
        ]);
        $this->injectFakeTokensApi($bridge, $fake);

        $token->patch(new CardPaymentPatch('999'));

        $request = $fake->calls[0]['args'][3];
        $this->assertInstanceOf(TransactionTokenUpdateRequest::class, $request);
        $this->assertSame('999', $request->getData()->getCvv());
    }

    public function testDeactivateReturnsTrueOnAnEmptyBodyAndCallsDeleteWithStoreIdAndId()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $token = $this->parseToken($this->cardTokenJson(), $context);

        $fake = new FakeTransactionTokensApi($bridge->caller(), ['']); // 204, empty body
        $this->injectFakeTokensApi($bridge, $fake);

        $result = $token->deactivate();

        $this->assertTrue($result);
        $this->assertCount(1, $fake->calls);
        $this->assertSame('deleteTransactionToken', $fake->calls[0]['method']);
        $this->assertSame(['store-1', 'token-1'], $fake->calls[0]['args']);
    }

    public function testThreeDSIssuerTokenHydratesAThreeDSIssuerTokenAndCallsTheRightRoute()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $token = $this->parseToken($this->cardTokenJson(['type' => 'recurring']), $context);

        $body = [
            'call_method' => 'http_post',
            'content_type' => 'application/x-www-form-urlencoded',
            'issuer_token' => 'issuer-token-1',
            'payload' => 'PAReq=abc',
            'payment_type' => 'card'
        ];
        $fake = new FakeTransactionTokensApi($bridge->caller(), [(string) json_encode($body)]);
        $this->injectFakeTokensApi($bridge, $fake);

        $issuerToken = $token->threeDSIssuerToken();

        $this->assertSame('getTokenThreeDsIssuerToken', $fake->calls[0]['method']);
        $this->assertSame(['store-1', 'token-1'], $fake->calls[0]['args']);
        $this->assertEquals(CallMethod::HTTP_POST(), $issuerToken->callMethod);
        $this->assertSame('issuer-token-1', $issuerToken->issuerToken);
        $this->assertEquals(PaymentType::CARD(), $issuerToken->paymentType);
    }

    // --- enableThreeDS(): guard is real, the call is a documented spec gap -----------------------

    public function testEnableThreeDSRejectsNonRecurringTokensWithOldErrorParity()
    {
        $token = $this->parseToken($this->cardTokenJson(['type' => 'one_time']));

        try {
            $token->enableThreeDS(true, 'https://example.com/return');
            $this->fail('Expected a UnivapayLogicError');
        } catch (UnivapayLogicError $e) {
            // Reason::TRANSACTION_TOKEN_IS_NOT_RECURRING()->getValue() lowercases the case name --
            // old-SDK-identical (TypedEnum::create()'s default-value convention).
            $this->assertSame('transaction_token_is_not_recurring', $e->code);
        }
    }

    public function testEnableThreeDSOnARecurringTokenCallsEnableTokenThreeDsAndHydratesANewToken()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $token = $this->parseToken($this->cardTokenJson(['type' => 'recurring']), $context);

        $fake = new FakeTransactionTokensApi(
            $bridge->caller(),
            [(string) json_encode($this->cardTokenJson(['type' => 'recurring']))]
        );
        $this->injectFakeTokensApi($bridge, $fake);

        $updated = $token->enableThreeDS(true, 'https://example.com/return');

        $this->assertInstanceOf(TransactionToken::class, $updated);
        $this->assertNotSame($token, $updated);
        $this->assertSame('enableTokenThreeDs', $fake->calls[0]['method']);
        $this->assertSame('store-1', $fake->calls[0]['args'][0]);
        $this->assertSame('token-1', $fake->calls[0]['args'][1]);
        $this->assertInstanceOf(EnableTokenThreeDsRequest::class, $fake->calls[0]['args'][3]);
        $this->assertSame('https://example.com/return', $fake->calls[0]['args'][3]->getRedirectEndpoint());
    }

    public function testDisableThreeDSOnARecurringTokenCallsDisableTokenThreeDsAndReturnsTrue()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $token = $this->parseToken($this->cardTokenJson(['type' => 'recurring']), $context);

        $fake = new FakeTransactionTokensApi($bridge->caller(), ['']);
        $this->injectFakeTokensApi($bridge, $fake);

        $result = $token->enableThreeDS(false);

        $this->assertTrue($result);
        $this->assertSame('disableTokenThreeDs', $fake->calls[0]['method']);
        $this->assertSame(['store-1', 'token-1'], $fake->calls[0]['args']);
    }

    // --- awaitResult()/fetchWithPolling() -----------------------------------------------------

    public function testFetchWithPollingSendsPollingTrueAndHydratesANewInstance()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $token = $this->parseToken($this->cardTokenJson(), $context);

        $fake = new FakeTransactionTokensApi(
            $bridge->caller(),
            [(string) json_encode($this->cardTokenJson())]
        );
        $this->injectFakeTokensApi($bridge, $fake);

        $method = new ReflectionMethod(TransactionToken::class, 'fetchWithPolling');
        $method->setAccessible(true);
        $result = $method->invoke($token);

        $this->assertInstanceOf(TransactionToken::class, $result);
        $this->assertNotSame($token, $result);
        $this->assertSame('getTransactionToken', $fake->calls[0]['method']);
        // polling=true literal serialization (plan "Minor: assert polling=true literal
        // serialization in integration test").
        $this->assertSame(['store-1', 'token-1', true], $fake->calls[0]['args']);
    }

    public function testAwaitResultStopsAsSoonAsThreeDsStatusTransitionsOutOfPending()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        // Starting point: threeDS PENDING, cvvAuthorize disabled (no sub-status to track at all).
        $token = $this->parseToken($this->cardTokenJson([
            'data' => $this->cardDataJson(['three_ds' => ['enabled' => true, 'status' => 'pending']]),
        ]), $context);

        $fake = new FakeTransactionTokensApi($bridge->caller(), [
            // First held GET: still pending -- must keep polling.
            (string) json_encode($this->cardTokenJson([
                'data' => $this->cardDataJson(['three_ds' => ['enabled' => true, 'status' => 'pending']]),
            ])),
            // Second held GET: transitioned to awaiting -- must stop here.
            (string) json_encode($this->cardTokenJson([
                'data' => $this->cardDataJson(['three_ds' => ['enabled' => true, 'status' => 'awaiting']]),
            ])),
        ]);
        $this->injectFakeTokensApi($bridge, $fake);

        $result = $token->awaitResult(5);

        $this->assertEquals(ThreeDSStatus::AWAITING(), $result->data->threeDS->status);
        $this->assertCount(2, $fake->calls);
    }

    public function testAwaitResultStopsImmediatelyWhenNeitherSubStatusIsPollable()
    {
        // Neither three_ds nor cvv_authorize has a status this class's pollableStatuses() map
        // tracks (both null/disabled) -- must return after exactly ONE held GET, never looping.
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $token = $this->parseToken($this->cardTokenJson(), $context);

        $fake = new FakeTransactionTokensApi(
            $bridge->caller(),
            [(string) json_encode($this->cardTokenJson())]
        );
        $this->injectFakeTokensApi($bridge, $fake);

        $result = $token->awaitResult(5);

        $this->assertInstanceOf(TransactionToken::class, $result);
        $this->assertCount(1, $fake->calls);
    }

    // --- createCharge()/createSubscription(): guard-failure paths only (see class doc) ----------

    public function testCreateChargeOnASubscriptionTokenIsRejectedBeforeAnyRequestIsBuilt()
    {
        $token = $this->parseToken($this->cardTokenJson(['type' => 'subscription']));

        try {
            $token->createCharge(new Money(1000, new Currency('JPY')));
            $this->fail('Expected a UnivapayLogicError');
        } catch (UnivapayLogicError $e) {
            $this->assertSame('non_subscription_payment', $e->code);
        }
    }

    public function testCreateChargeCaptureOnNonCardPaymentTypeIsRejected()
    {
        $token = $this->parseToken($this->cardTokenJson([
            'payment_type' => 'konbini',
            'type' => 'one_time',
            'data' => [
                'customer_name' => 'x',
                'phone_number' => ['country_code' => 81, 'local_number' => '123'],
                'convenience_store' => 'seven_eleven',
                'expiration_period' => 'PT168H'
            ]
        ]));

        $this->expectException(UnivapayLogicError::class);

        $token->createCharge(new Money(1000, new Currency('JPY')), true);
    }

    public function testCreateChargeRequiresCurrentCvvAuthorizationWhenEnabled()
    {
        $token = $this->parseToken($this->cardTokenJson([
            'type' => 'recurring',
            'data' => array_replace($this->cardTokenJson()['data'], [
                'cvv_authorize' => [
                    'enabled' => true,
                    'status' => 'pending',
                    'charge_id' => null,
                    'credentials_id' => null,
                    'currency' => null
                ]
            ])
        ]));

        try {
            $token->createCharge(new Money(1000, new Currency('JPY')));
            $this->fail('Expected a UnivapayLogicError');
        } catch (UnivapayLogicError $e) {
            $this->assertSame('cvv_authorization_required', $e->code);
        }
    }

    public function testCreateSubscriptionOnAOneTimeTokenIsRejected()
    {
        $token = $this->parseToken($this->cardTokenJson(['type' => 'one_time']));

        try {
            $token->createSubscription(new Money(1000, new Currency('JPY')), Period::MONTHLY());
            $this->fail('Expected a UnivapayLogicError');
        } catch (UnivapayLogicError $e) {
            $this->assertSame('not_subscription_payment', $e->code);
        }
    }

    public function testCreateSubscriptionRequiresEitherPeriodOrCyclicalPeriod()
    {
        $token = $this->parseToken($this->cardTokenJson(['type' => 'subscription']));

        try {
            $token->createSubscription(new Money(1000, new Currency('JPY')));
            $this->fail('Expected a UnivapayValidationError');
        } catch (UnivapayValidationError $e) {
            // Field::PERIOD()'s value is '' (empty string), not 'period' -- a verbatim old-SDK
            // quirk (see Enums\Field.php / scratchpad old SDK's identical Field.php), not a
            // porting bug introduced here.
            $this->assertSame('', $e->errors['field']);
            $this->assertSame('period_or_cyclical_period_must_be_set', $e->errors['reason']);
        }
    }

    public function testCreateSubscriptionRejectsNonPositiveAmount()
    {
        $token = $this->parseToken($this->cardTokenJson(['type' => 'subscription']));

        $this->expectException(UnivapayValidationError::class);

        $token->createSubscription(new Money(0, new Currency('JPY')), Period::MONTHLY());
    }

    // --- createCharge()/createSubscription(): SUCCESS path -- `Charge`/`Subscription` exist, so
    // `callAndHydrate()`'s cross-resource reference is exercised for real here -----------------

    public function testCreateChargeSuccessHydratesARealChargeAndCallsCreateChargeWithTheTokenStoreId()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $token = $this->parseToken($this->cardTokenJson(), $context);

        $chargeJson = [
            'id' => 'charge-1',
            'store_id' => 'store-1',
            'transaction_token_id' => 'token-1',
            'transaction_token_type' => 'one_time',
            'subscription_id' => null,
            'requested_amount' => 1000,
            'requested_currency' => 'JPY',
            'requested_amount_formatted' => 1000,
            'status' => 'successful',
            'mode' => 'test',
            'created_on' => '2022-07-26T10:33:12.934225Z',
            'metadata' => []
        ];
        $fake = new FakeChargesApiForTransactionTokenTest($bridge->caller(), [(string) json_encode($chargeJson)]);
        $this->injectFakeChargesApi($bridge, $fake);

        $charge = $token->createCharge(new Money(1000, new Currency('JPY')));

        $this->assertInstanceOf(Charge::class, $charge);
        $this->assertSame('createCharge', $fake->calls[0]['method']);
        $this->assertNotEmpty($fake->calls[0]['args'][0]); // idempotency key
        $this->assertInstanceOf(ChargeCreateRequest::class, $fake->calls[0]['args'][1]);
        $this->assertSame('token-1', $fake->calls[0]['args'][1]->getTransactionTokenId());
        // callAndHydrate() hydrates with the token's OWN storeId, not the Bridge's JWT store id --
        // both happen to be 'store-1' here, but the field itself must be the wire value.
        $this->assertSame('store-1', $charge->storeId);
        $this->assertEquals(ChargeStatus::SUCCESSFUL(), $charge->status);
    }

    public function testCreateSubscriptionSuccessHydratesARealSubscriptionAndCallsCreateSubscription()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $token = $this->parseToken($this->cardTokenJson(['type' => 'subscription']), $context);

        $subscriptionJson = [
            'id' => 'sub-1',
            'store_id' => 'store-1',
            'transaction_token_id' => 'token-1',
            'amount' => 1000,
            'currency' => 'JPY',
            'amount_formatted' => 1000,
            'period' => 'monthly',
            'schedule_settings' => ['zone_id' => 'Asia/Tokyo'],
            'payments_left' => 12,
            'status' => 'unverified',
            'metadata' => [],
            'mode' => 'test',
            'amount_left' => 12000,
            'amount_left_formatted' => 12000,
            'created_on' => '2022-07-26T10:33:12.934225Z'
        ];
        $fake = new FakeSubscriptionsApiForTransactionTokenTest(
            $bridge->caller(),
            [(string) json_encode($subscriptionJson)]
        );
        $this->injectFakeSubscriptionsApi($bridge, $fake);

        $subscription = $token->createSubscription(new Money(1000, new Currency('JPY')), Period::MONTHLY());

        $this->assertInstanceOf(Subscription::class, $subscription);
        $this->assertSame('createSubscription', $fake->calls[0]['method']);
        $this->assertInstanceOf(SubscriptionCreateRequest::class, $fake->calls[0]['args'][1]);
        $this->assertSame('store-1', $subscription->storeId);
        $this->assertEquals(SubscriptionStatus::UNVERIFIED(), $subscription->status);
    }

    // --- reflection assertions on the generated TransactionTokensApi signatures this class relies on

    public function testUpdateTransactionTokenSignatureMatchesWhatUpdateCallAssumes()
    {
        $method = new ReflectionMethod(TransactionTokensApi::class, 'updateTransactionToken');
        $params = $method->getParameters();

        $this->assertSame(['storeId', 'id', 'idempotencyKey', 'body'], array_map(function ($p) {
            return $p->getName();
        }, $params));
        $this->assertTrue($params[2]->allowsNull());
        $this->assertTrue($params[2]->isOptional());
    }

    public function testDeleteTransactionTokenAndIssuerTokenSignaturesTakeStoreIdThenId()
    {
        foreach (['deleteTransactionToken', 'getTokenThreeDsIssuerToken'] as $methodName) {
            $method = new ReflectionMethod(TransactionTokensApi::class, $methodName);
            $names = array_map(function ($p) {
                return $p->getName();
            }, $method->getParameters());
            $this->assertSame(['storeId', 'id'], $names, "$methodName parameter order changed");
        }
    }

    /**
     * `getTransactionToken()` takes a `polling` query param -- THREE parameters, not two
     * (`fetchWithPolling()`/`awaitResult()` depend on it).
     */
    public function testGetTransactionTokenSignatureTakesStoreIdThenIdThenPolling()
    {
        $method = new ReflectionMethod(TransactionTokensApi::class, 'getTransactionToken');
        $params = $method->getParameters();
        $names = array_map(function ($p) {
            return $p->getName();
        }, $params);
        $this->assertSame(['storeId', 'id', 'polling'], $names);
        $this->assertTrue($params[2]->allowsNull());
        $this->assertTrue($params[2]->isOptional());
    }

    /**
     * `enableTokenThreeDs()`/`disableTokenThreeDs()` -- verify the argument order
     * `TransactionToken::enableThreeDS()` assumes.
     */
    public function testEnableAndDisableTokenThreeDsSignatures()
    {
        $enable = new ReflectionMethod(TransactionTokensApi::class, 'enableTokenThreeDs');
        $this->assertSame(
            ['storeId', 'id', 'idempotencyKey', 'body'],
            array_map(function ($p) {
                return $p->getName();
            }, $enable->getParameters())
        );

        $disable = new ReflectionMethod(TransactionTokensApi::class, 'disableTokenThreeDs');
        $this->assertSame(
            ['storeId', 'id'],
            array_map(function ($p) {
                return $p->getName();
            }, $disable->getParameters())
        );
    }
}

/**
 * Hand-written double for the generated `TransactionTokensApi`, standing in for a real HTTP round
 * trip. `Bridge::tokens(): TransactionTokensApi` is return-type-checked at runtime, so this MUST be
 * a genuine subclass -- a loosely-typed stand-in object would fail with a TypeError the moment
 * `Bridge::tokens()` tried to return it. Deliberately never calls `parent::__construct()` (which
 * requires a real `Core\Client`) since every method used by `TransactionToken` is overridden below
 * and none of them touch `$this->client`.
 *
 * Each overridden method calls the REAL `Support\ApiCaller::recordResponse()` (the same one the
 * `Bridge` under test already owns) to simulate what the generated client's `httpCallback` hook
 * would have captured from an actual HTTP response -- exactly the manual-`recordResponse()` style
 * `tests/Unit/Support/ApiCallerTest.php` already uses with a REAL `ApiCaller` (not a mock of it).
 * $responses is consumed in call order, one entry per expected call, so a test asserting a
 * multi-call sequence (e.g. patch() -> update() then fetch()) can give each step its own canned
 * body.
 */
class FakeTransactionTokensApi extends TransactionTokensApi
{
    /** @var array<int, array{method: string, args: array}> */
    public $calls = [];

    /** @var ApiCaller */
    private $apiCaller;

    /** @var string[] */
    private $responses;

    /**
     * @param string[] $responses Raw JSON bodies (or '' for an empty/204 body), one per expected
     *        call, consumed in order.
     */
    public function __construct(ApiCaller $apiCaller, array $responses)
    {
        $this->apiCaller = $apiCaller;
        $this->responses = $responses;
    }

    public function getTransactionToken(string $storeId, string $id, ?bool $polling = null): ApiResponse
    {
        return $this->respond('getTransactionToken', [$storeId, $id, $polling]);
    }

    public function updateTransactionToken(
        string $storeId,
        string $id,
        ?string $idempotencyKey = null,
        ?TransactionTokenUpdateRequest $body = null
    ): ApiResponse {
        return $this->respond('updateTransactionToken', [$storeId, $id, $idempotencyKey, $body]);
    }

    public function deleteTransactionToken(string $storeId, string $id): ApiResponse
    {
        return $this->respond('deleteTransactionToken', [$storeId, $id]);
    }

    public function getTokenThreeDsIssuerToken(string $storeId, string $id): ApiResponse
    {
        return $this->respond('getTokenThreeDsIssuerToken', [$storeId, $id]);
    }

    public function enableTokenThreeDs(
        string $storeId,
        string $id,
        ?string $idempotencyKey = null,
        ?EnableTokenThreeDsRequest $body = null
    ): ApiResponse {
        return $this->respond('enableTokenThreeDs', [$storeId, $id, $idempotencyKey, $body]);
    }

    public function disableTokenThreeDs(string $storeId, string $id): ApiResponse
    {
        return $this->respond('disableTokenThreeDs', [$storeId, $id]);
    }

    private function respond(string $method, array $args): ApiResponse
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        // The generated method's own typed result is never inspected by ApiCaller::call() (it
        // always answers from the captured raw body -- see its class doc), so an inert instance
        // with no real request/result is sufficient here.
        return new ApiResponse(null, 200, null, [], null, $body);
    }
}

/**
 * Hand-written double for the generated `ChargesApi`, standing in only for
 * `createCharge()` -- the one call `TransactionToken::createCharge()`'s success path drives.
 * Same rationale as `FakeTransactionTokensApi` above.
 */
class FakeChargesApiForTransactionTokenTest extends ChargesApi
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

    public function createCharge(?string $idempotencyKey = null, ?ChargeCreateRequest $body = null): ApiResponse
    {
        $this->calls[] = ['method' => 'createCharge', 'args' => [$idempotencyKey, $body]];
        $responseBody = array_shift($this->responses);
        $this->apiCaller->recordResponse($responseBody ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $responseBody);
    }
}

/**
 * Hand-written double for the generated `SubscriptionsApi`, standing in only for
 * `createSubscription()` -- the one call `TransactionToken::createSubscription()`'s success path
 * drives. Same rationale as `FakeTransactionTokensApi` above.
 */
class FakeSubscriptionsApiForTransactionTokenTest extends SubscriptionsApi
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

    public function createSubscription(
        ?string $idempotencyKey = null,
        ?SubscriptionCreateRequest $body = null
    ): ApiResponse {
        $this->calls[] = ['method' => 'createSubscription', 'args' => [$idempotencyKey, $body]];
        $responseBody = array_shift($this->responses);
        $this->apiCaller->recordResponse($responseBody ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $responseBody);
    }
}
