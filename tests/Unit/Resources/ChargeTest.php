<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources;

use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use UnivaPay\Apis\CancelsApi;
use UnivaPay\Apis\ChargesApi;
use UnivaPay\Apis\RefundsApi;
use UnivaPay\Http\ApiResponse;
use UnivaPay\Models\CancelCreateRequest;
use UnivaPay\Models\ChargeCaptureRequest;
use UnivaPay\Models\ChargeUpdateRequest;
use UnivaPay\Models\RefundCreateRequest;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\ChargeStatus;
use Univapay\Compat\Enums\RefundReason;
use Univapay\Compat\Enums\ThreeDSMode;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Errors\UnivapayValidationError;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Resources\Cancel;
use Univapay\Compat\Resources\Charge;
use Univapay\Compat\Resources\Refund;
use Univapay\Compat\Support\ApiCaller;
use Univapay\Compat\Support\Bridge;
use Univapay\Compat\Support\CompatContext;
use Univapay\Compat\Tests\Fixtures\PollableStatusMaps;

/**
 * Covers `Charge`: hydration (JSON fixture lifted verbatim from the old SDK's
 * `tests/Univapay/Integration/ChargeTest.php::testParseChargeWithError`),
 * `patch()`/`createRefund()`/`capture()`/`cancel()`/`onlineToken()`/`threeDSIssuerToken()` driven
 * through a REAL `Support\ApiCaller` via hand-written Fake*Api doubles (same technique
 * `TransactionTokenTest` established), the `capture(null)`/`qrMerchantToken()` unsupported
 * guards, the CHARGEBACK refund-reason guard, and `pollableStatuses()` pinned against
 * `tests/Fixtures/PollableStatusMaps::charge()`.
 */
class ChargeTest extends TestCase
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

    private function injectFakeChargesApi(Bridge $bridge, ChargesApi $fake): void
    {
        $property = new ReflectionProperty(Bridge::class, 'chargesApi');
        $property->setAccessible(true);
        $property->setValue($bridge, $fake);
    }

    private function injectFakeRefundsApi(Bridge $bridge, RefundsApi $fake): void
    {
        $property = new ReflectionProperty(Bridge::class, 'refundsApi');
        $property->setAccessible(true);
        $property->setValue($bridge, $fake);
    }

    private function injectFakeCancelsApi(Bridge $bridge, CancelsApi $fake): void
    {
        $property = new ReflectionProperty(Bridge::class, 'cancelsApi');
        $property->setAccessible(true);
        $property->setValue($bridge, $fake);
    }

    /** JSON lifted verbatim from the old SDK's ChargeTest::testParseChargeWithError fixture. */
    // JSON payload lifted verbatim (values only -- heredoc-with-indented-closing-marker is a
    // PHP 7.3+ feature, unusable on this package's 7.2 floor) from the old SDK's
    // ChargeTest::testParseChargeWithError fixture.
    private function chargeErrorJson(): array
    {
        return [
            'id' => '11ed0cce-59e5-795a-b95c-fb1234567890',
            'store_id' => '11e99edf-6075-c71c-b6d5-ef1237890',
            'transaction_token_id' => '11ed0cce-589a-5584-b959-631234567890',
            'transaction_token_type' => 'one_time',
            'subscription_id' => '12ed0cce-59e5-795a-b95c-fb1234567890',
            'requested_amount' => 100,
            'requested_currency' => 'JPY',
            'requested_amount_formatted' => 100,
            'charged_amount' => 100,
            'charged_currency' => 'JPY',
            'charged_amount_formatted' => 100,
            'only_direct_currency' => false,
            'capture_at' => '2022-07-26T10:33:16.308043Z',
            'status' => 'failed',
            'error' => [
                'code' => 301,
                'message' => 'The card number is not valid'
            ],
            'metadata' => [],
            'mode' => 'live',
            'redirect' => [
                'endpoint' => 'https://test.int/endpoint?foo=bar',
                'redirect_id' => '11ed0cce-59e5-795a-b95c-rd1234567890'
            ],
            'three_ds' => [
                'redirect_endpoint' => 'https://ec-site.example.com/3ds/complete',
                'redirect_id' => '11efbdb4-6820-12dc-8246-6f01ed1243a9',
                'mode' => 'normal'
            ],
            'created_on' => '2022-07-26T10:33:12.934225Z'
        ];
    }

    private function parseCharge(array $json, ?CompatContext $context = null): Charge
    {
        return Charge::getSchema()->parse($json, [$context ?? $this->context()]);
    }

    // --- hydration --------------------------------------------------------------------------------

    public function testHydratesChargeWithErrorRedirectAndThreeDS()
    {
        $charge = $this->parseCharge($this->chargeErrorJson());

        $this->assertSame('11ed0cce-59e5-795a-b95c-fb1234567890', $charge->id);
        $this->assertSame('11e99edf-6075-c71c-b6d5-ef1237890', $charge->storeId);
        $this->assertSame('11ed0cce-589a-5584-b959-631234567890', $charge->transactionTokenId);
        $this->assertSame('12ed0cce-59e5-795a-b95c-fb1234567890', $charge->subscriptionId);
        $this->assertEquals(TokenType::ONE_TIME(), $charge->transactionTokenType);
        $this->assertEquals(new Money(100, new Currency('JPY')), $charge->requestedAmount);
        $this->assertEquals(new Currency('JPY'), $charge->requestedCurrency);
        $this->assertEquals(new Money(100, new Currency('JPY')), $charge->chargedAmount);
        $this->assertFalse($charge->onlyDirectCurrency);
        $this->assertEquals(date_create('2022-07-26T10:33:16.308043Z'), $charge->captureAt);
        $this->assertEquals(date_create('2022-07-26T10:33:12.934225Z'), $charge->createdOn);
        $this->assertSame(301, $charge->error['code']);
        $this->assertSame('The card number is not valid', $charge->error['message']);
        $this->assertEquals(ChargeStatus::FAILED(), $charge->status);
        $this->assertEquals(AppTokenMode::LIVE(), $charge->mode);
        $this->assertSame('https://test.int/endpoint?foo=bar', $charge->redirect->endpoint);
        $this->assertSame('11ed0cce-59e5-795a-b95c-rd1234567890', $charge->redirect->redirectId);
        $this->assertSame('https://ec-site.example.com/3ds/complete', $charge->threeDS->redirectEndpoint);
        $this->assertEquals(ThreeDSMode::NORMAL(), $charge->threeDS->mode);
    }

    // --- patch() -----------------------------------------------------------------------------------

    public function testPatchSendsUpdateChargeAndHydratesTheResponse()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $charge = $this->parseCharge($this->chargeErrorJson(), $context);

        $patchedJson = array_replace($this->chargeErrorJson(), ['metadata' => ['testId' => 12345]]);
        $fake = new FakeChargesApiForChargeTest($bridge->caller(), [(string) json_encode($patchedJson)]);
        $this->injectFakeChargesApi($bridge, $fake);

        $result = $charge->patch(['testId' => 12345]);

        $this->assertCount(1, $fake->calls);
        $this->assertSame('updateCharge', $fake->calls[0]['method']);
        $this->assertSame(
            ['11e99edf-6075-c71c-b6d5-ef1237890', '11ed0cce-59e5-795a-b95c-fb1234567890'],
            array_slice($fake->calls[0]['args'], 0, 2)
        );
        $this->assertInstanceOf(ChargeUpdateRequest::class, $fake->calls[0]['args'][3]);
        $this->assertInstanceOf(Charge::class, $result);
        $this->assertNotSame($charge, $result);
        $this->assertSame(12345, $result->metadata['testId']);
    }

    // --- createRefund() ------------------------------------------------------------------------

    public function testCreateRefundHydratesARefundAndCallsCreateRefundWithStoreAndChargeId()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $charge = $this->parseCharge($this->chargeErrorJson(), $context);

        $refundJson = [
            'id' => 'refund-1',
            'store_id' => $charge->storeId,
            'charge_id' => $charge->id,
            'status' => 'successful',
            'currency' => 'JPY',
            'amount' => 100,
            'amount_formatted' => 100,
            'mode' => 'live',
            'created_on' => '2022-07-26T10:33:12.934225Z'
        ];
        $fake = new FakeRefundsApiForChargeTest($bridge->caller(), [(string) json_encode($refundJson)]);
        $this->injectFakeRefundsApi($bridge, $fake);

        $refund = $charge->createRefund(new Money(100, new Currency('JPY')), RefundReason::CUSTOMER_REQUEST());

        $this->assertInstanceOf(Refund::class, $refund);
        $this->assertSame('createRefund', $fake->calls[0]['method']);
        $this->assertSame($charge->storeId, $fake->calls[0]['args'][0]);
        $this->assertSame($charge->id, $fake->calls[0]['args'][1]);
        $this->assertInstanceOf(RefundCreateRequest::class, $fake->calls[0]['args'][2]);
        $this->assertNotEmpty($fake->calls[0]['args'][3]); // idempotency key
    }

    public function testCreateRefundRejectsChargebackReasonBeforeAnyRequestIsBuilt()
    {
        $charge = $this->parseCharge($this->chargeErrorJson());

        $this->expectException(UnivapayValidationError::class);

        $charge->createRefund(new Money(100, new Currency('JPY')), RefundReason::CHARGEBACK());
    }

    // --- capture() ---------------------------------------------------------------------------------

    public function testCaptureWithAnAmountCallsCaptureChargeAndReturnsTrue()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $charge = $this->parseCharge($this->chargeErrorJson(), $context);

        $fake = new FakeChargesApiForChargeTest($bridge->caller(), ['']);
        $this->injectFakeChargesApi($bridge, $fake);

        $result = $charge->capture(new Money(50, new Currency('JPY')));

        $this->assertTrue($result);
        $this->assertSame('captureCharge', $fake->calls[0]['method']);
        $this->assertInstanceOf(ChargeCaptureRequest::class, $fake->calls[0]['args'][3]);
    }

    /**
     * `ChargesApi::captureCharge()`'s own `$body` is optional (see class doc) --
     * `capture(null)` sends NO body at all (server captures the outstanding authorized
     * amount), matching the old wire exactly.
     */
    public function testCaptureWithoutAnAmountSendsNoBodyAndReturnsTrue()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $charge = $this->parseCharge($this->chargeErrorJson(), $context);

        $fake = new FakeChargesApiForChargeTest($bridge->caller(), ['']);
        $this->injectFakeChargesApi($bridge, $fake);

        $result = $charge->capture();

        $this->assertTrue($result);
        $this->assertSame('captureCharge', $fake->calls[0]['method']);
        $this->assertNull($fake->calls[0]['args'][3]);
    }

    // --- cancel() ------------------------------------------------------------------------------

    public function testCancelHydratesACancelAndCallsCreateCancel()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $charge = $this->parseCharge($this->chargeErrorJson(), $context);

        $cancelJson = [
            'id' => 'cancel-1',
            'charge_id' => $charge->id,
            'store_id' => $charge->storeId,
            'status' => 'successful',
            'error' => null,
            'metadata' => ['something' => 'anything'],
            'mode' => 'live',
            'created_on' => '2022-07-26T10:33:12.934225Z'
        ];
        $fake = new FakeCancelsApiForChargeTest($bridge->caller(), [(string) json_encode($cancelJson)]);
        $this->injectFakeCancelsApi($bridge, $fake);

        $cancel = $charge->cancel(['something' => 'anything']);

        $this->assertInstanceOf(Cancel::class, $cancel);
        $this->assertSame('createCancel', $fake->calls[0]['method']);
        $this->assertSame($charge->storeId, $fake->calls[0]['args'][0]);
        $this->assertSame($charge->id, $fake->calls[0]['args'][1]);
        $this->assertInstanceOf(CancelCreateRequest::class, $fake->calls[0]['args'][3]);
        $this->assertSame('anything', $cancel->metadata['something']);
    }

    // --- qrMerchantToken()/onlineToken()/threeDSIssuerToken() ---------------------------------------

    public function testQrMerchantTokenAlwaysThrowsUnsupported()
    {
        $charge = $this->parseCharge($this->chargeErrorJson());

        $this->expectException(UnivapayUnsupportedFeatureError::class);
        $this->expectExceptionMessageMatches('/qrMerchantToken/');

        $charge->qrMerchantToken();
    }

    public function testOnlineTokenHydratesAnOnlineTokenAndCallsTheIssuerTokenRoute()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $charge = $this->parseCharge($this->chargeErrorJson(), $context);

        $body = ['issuer_token' => 'https://issuer.example.com/redirect', 'call_method' => 'web'];
        $fake = new FakeChargesApiForChargeTest($bridge->caller(), [(string) json_encode($body)]);
        $this->injectFakeChargesApi($bridge, $fake);

        $onlineToken = $charge->onlineToken();

        $this->assertSame('getChargeIssuerToken', $fake->calls[0]['method']);
        $this->assertSame([$charge->storeId, $charge->id], $fake->calls[0]['args']);
        $this->assertSame('https://issuer.example.com/redirect', $onlineToken->issuerToken);
    }

    public function testThreeDSIssuerTokenHydratesAndCallsTheRightRoute()
    {
        $bridge = $this->bridge();
        $context = $this->context($bridge);
        $charge = $this->parseCharge($this->chargeErrorJson(), $context);

        $body = [
            'call_method' => 'http_post',
            'content_type' => 'application/x-www-form-urlencoded',
            'issuer_token' => 'issuer-token-1',
            'payload' => 'PAReq=abc',
            'payment_type' => 'card'
        ];
        $fake = new FakeChargesApiForChargeTest($bridge->caller(), [(string) json_encode($body)]);
        $this->injectFakeChargesApi($bridge, $fake);

        $issuerToken = $charge->threeDSIssuerToken();

        $this->assertSame('getChargeThreeDsIssuerToken', $fake->calls[0]['method']);
        $this->assertSame('issuer-token-1', $issuerToken->issuerToken);
    }

    // --- pollableStatuses() pinned against the fixture -------------------------------------------

    public function testPollableStatusesMatchesThePinnedFixture()
    {
        $charge = $this->parseCharge($this->chargeErrorJson());

        $method = new ReflectionMethod(Charge::class, 'pollableStatuses');
        $method->setAccessible(true);

        $this->assertEquals(PollableStatusMaps::charge(), $method->invoke($charge));
    }

    // --- reflection assertions on generated signatures this class relies on ----------------------

    public function testCaptureChargeSignatureMatchesWhatCaptureAssumes()
    {
        $method = new ReflectionMethod(ChargesApi::class, 'captureCharge');
        $params = $method->getParameters();
        $names = array_map(function ($p) {
            return $p->getName();
        }, $params);
        // The capture request body is optional and idempotencyKey comes before it (this
        // codebase's "idempotency key BEFORE body when body optional" convention) -- verify both
        // the order and that body is genuinely nullable/optional, since Charge::capture(null)
        // depends on it.
        $this->assertSame(['storeId', 'id', 'idempotencyKey', 'body'], $names);
        $this->assertTrue($params[3]->allowsNull());
        $this->assertTrue($params[3]->isOptional());
    }

    public function testCreateCancelSignatureMatchesWhatCancelAssumes()
    {
        $method = new ReflectionMethod(CancelsApi::class, 'createCancel');
        $names = array_map(function ($p) {
            return $p->getName();
        }, $method->getParameters());
        $this->assertSame(['storeId', 'chargeId', 'idempotencyKey', 'body'], $names);
    }
}

/**
 * Hand-written double for the generated `ChargesApi`. Same shape/rationale as
 * `TransactionTokenTest::FakeTransactionTokensApi` -- see that class's doc.
 */
class FakeChargesApiForChargeTest extends ChargesApi
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
        return $this->respond('getCharge', [$storeId, $id, $polling]);
    }

    public function updateCharge(
        string $storeId,
        string $id,
        ?string $idempotencyKey = null,
        ?ChargeUpdateRequest $body = null
    ): ApiResponse {
        return $this->respond('updateCharge', [$storeId, $id, $idempotencyKey, $body]);
    }

    public function captureCharge(
        string $storeId,
        string $id,
        ?string $idempotencyKey = null,
        ?ChargeCaptureRequest $body = null
    ): ApiResponse {
        return $this->respond('captureCharge', [$storeId, $id, $idempotencyKey, $body]);
    }

    public function getChargeIssuerToken(string $storeId, string $id): ApiResponse
    {
        return $this->respond('getChargeIssuerToken', [$storeId, $id]);
    }

    public function getChargeThreeDsIssuerToken(string $storeId, string $id): ApiResponse
    {
        return $this->respond('getChargeThreeDsIssuerToken', [$storeId, $id]);
    }

    private function respond(string $method, array $args): ApiResponse
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }
}

/**
 * Hand-written double for the generated `RefundsApi`, same rationale as above.
 */
class FakeRefundsApiForChargeTest extends RefundsApi
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

    public function createRefund(
        string $storeId,
        string $chargeId,
        RefundCreateRequest $body,
        ?string $idempotencyKey = null
    ): ApiResponse {
        return $this->respond('createRefund', [$storeId, $chargeId, $body, $idempotencyKey]);
    }

    public function getRefund(string $storeId, string $chargeId, string $id, ?bool $polling = null): ApiResponse
    {
        return $this->respond('getRefund', [$storeId, $chargeId, $id, $polling]);
    }

    private function respond(string $method, array $args): ApiResponse
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }
}

/**
 * Hand-written double for the generated `CancelsApi`, same rationale as above.
 */
class FakeCancelsApiForChargeTest extends CancelsApi
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

    public function createCancel(
        string $storeId,
        string $chargeId,
        ?string $idempotencyKey = null,
        ?CancelCreateRequest $body = null
    ): ApiResponse {
        return $this->respond('createCancel', [$storeId, $chargeId, $idempotencyKey, $body]);
    }

    public function getCancel(string $storeId, string $chargeId, string $id, ?bool $polling = false): ApiResponse
    {
        return $this->respond('getCancel', [$storeId, $chargeId, $id, $polling]);
    }

    private function respond(string $method, array $args): ApiResponse
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }
}
