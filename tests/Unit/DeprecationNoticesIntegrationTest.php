<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit;

use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use UnivaPay\Apis\ChargesApi;
use UnivaPay\Apis\TransactionTokensApi;
use UnivaPay\Http\ApiResponse;
use UnivaPay\Models\ChargeCreateRequest;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Resources\Charge;
use Univapay\Compat\Support\ApiCaller;
use Univapay\Compat\Support\Bridge;
use Univapay\Compat\Support\DeprecationNotifier;
use Univapay\Compat\UnivapayClient;
use Univapay\Compat\UnivapayClientOptions;

/**
 * End-to-end coverage of `UnivapayClientOptions::$deprecationNotices` through REAL compat entry
 * points (as opposed to `DeprecationNotifierTest`, which exercises `Support\DeprecationNotifier`
 * directly): off-by-default, on emits once per call site, `native()` never notifies, and the
 * `createCharge()` two-step cascade (`getTransactionToken()` -> `TransactionToken::createCharge()`
 * both fire internally) still emits exactly the one outer notice.
 */
class DeprecationNoticesIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        // DeprecationNotifier's dedup registry is static/process-wide -- reset between tests so
        // one test's notified call sites can't suppress another's.
        DeprecationNotifier::reset();
    }

    protected function tearDown(): void
    {
        DeprecationNotifier::reset();
    }

    /**
     * @param callable $fn
     * @return string[] Every `E_USER_DEPRECATED` message raised while $fn ran.
     */
    private function captureDeprecationMessages(callable $fn): array
    {
        $messages = [];
        set_error_handler(function (int $errno, string $errstr) use (&$messages): bool {
            if ($errno === E_USER_DEPRECATED) {
                $messages[] = $errstr;
                return true;
            }
            return false;
        });
        try {
            $fn();
        } finally {
            restore_error_handler();
        }
        return $messages;
    }

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
            'jti' => 'jti-1',
        ]);
    }

    private function client(bool $deprecationNotices): UnivapayClient
    {
        $options = new UnivapayClientOptions();
        $options->deprecationNotices = $deprecationNotices;
        return new UnivapayClient(AppJWT::createToken($this->storeJwt(), 'secret-1'), $options);
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

    // --- off by default / on emits once per call site, via a real permanently-unsupported entry
    //     point (UnivapayClient::getTransfer()) -- no HTTP fake needed, it always throws. -------

    public function testOffByDefaultEmitsNothingForARealEntryPoint()
    {
        $client = $this->client(false);

        $messages = $this->captureDeprecationMessages(function () use ($client) {
            $this->callGetTransfer($client);
        });

        $this->assertSame([], $messages);
    }

    public function testEnabledEmitsExactlyOneNoticeNamingTheNativeEquivalent()
    {
        $client = $this->client(true);

        $messages = $this->captureDeprecationMessages(function () use ($client) {
            $this->callGetTransfer($client);
        });

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Univapay\Compat\UnivapayClient::getTransfer()', $messages[0]);
    }

    public function testSecondCallFromTheSameSiteEmitsNothingMore()
    {
        $client = $this->client(true);

        $messages = $this->captureDeprecationMessages(function () use ($client) {
            for ($i = 0; $i < 2; $i++) {
                // Both iterations call getTransfer() from this SAME source line.
                $this->callGetTransfer($client);
            }
        });

        $this->assertCount(1, $messages);
    }

    public function testTwoDifferentCallSitesEachEmitTheirOwnNotice()
    {
        $client = $this->client(true);

        $messages = $this->captureDeprecationMessages(function () use ($client) {
            // Two textually DISTINCT call sites (unlike callGetTransfer(), which is one shared
            // line reused by other tests above) -- each must notify independently.
            try {
                $client->getTransfer('transfer-1');
            } catch (UnivapayUnsupportedFeatureError $e) {
            }
            try {
                $client->getTransfer('transfer-1');
            } catch (UnivapayUnsupportedFeatureError $e) {
            }
        });

        $this->assertCount(2, $messages);
    }

    private function callGetTransfer(UnivapayClient $client): void
    {
        try {
            $client->getTransfer('transfer-1');
        } catch (UnivapayUnsupportedFeatureError $e) {
            // Expected -- Transfers are permanently unsupported; the notice fires regardless.
        }
    }

    // --- native() never notifies, even when enabled ----------------------------------------------

    public function testNativeNeverEmitsANoticeEvenWhenEnabled()
    {
        $client = $this->client(true);

        $messages = $this->captureDeprecationMessages(function () use ($client) {
            $client->native();
            $client->native();
        });

        $this->assertSame([], $messages);
    }

    // --- internal cascade: createCharge()'s two-step flow emits exactly one notice ---------------

    public function testCreateChargeTwoStepCascadeEmitsExactlyOneNotice()
    {
        $client = $this->client(true);
        $tokenJson = (string) json_encode([
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
                    'issuer' => 'BANCO', 'sub_brand' => 'none',
                ],
                'billing' => null,
                'cvv_authorize' => ['enabled' => false, 'status' => null, 'charge_id' => null,
                    'credentials_id' => null, 'currency' => null],
                'three_ds' => ['enabled' => false, 'redirect_endpoint' => null, 'status' => null,
                    'redirect_id' => null, 'error' => null],
            ],
        ]);
        $chargeJson = (string) json_encode([
            'id' => 'charge-1', 'store_id' => 'store-1', 'transaction_token_id' => 'token-1',
            'transaction_token_type' => 'one_time', 'subscription_id' => null,
            'requested_amount' => 1000, 'requested_currency' => 'JPY',
            'requested_amount_formatted' => 1000, 'status' => 'successful', 'mode' => 'test',
            'created_on' => '2022-07-26T10:33:12.934225Z', 'metadata' => [],
        ]);
        $tokensFake = new FakeTransactionTokensApiForDeprecationTest($this->bridgeOf($client)->caller(), [$tokenJson]);
        $chargesFake = new FakeChargesApiForDeprecationTest($this->bridgeOf($client)->caller(), [$chargeJson]);
        $this->injectFake($client, 'tokensApi', $tokensFake);
        $this->injectFake($client, 'chargesApi', $chargesFake);

        $messages = $this->captureDeprecationMessages(function () use ($client) {
            // Internally: UnivapayClient::createCharge() -> getTransactionToken() ->
            // TransactionToken::createCharge() -- three hooked entry points, one consumer call site.
            $charge = $client->createCharge('token-1', new Money(1000, new Currency('JPY')));
            $this->assertInstanceOf(Charge::class, $charge);
        });

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Univapay\Compat\UnivapayClient::createCharge()', $messages[0]);
    }
}

class FakeTransactionTokensApiForDeprecationTest extends TransactionTokensApi
{
    private $apiCaller;
    private $responses;

    public function __construct(ApiCaller $apiCaller, array $responses)
    {
        $this->apiCaller = $apiCaller;
        $this->responses = $responses;
    }

    public function getTransactionToken(string $storeId, string $id, ?bool $polling = null): ApiResponse
    {
        $body = array_shift($this->responses);
        $this->apiCaller->recordResponse($body ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $body);
    }
}

class FakeChargesApiForDeprecationTest extends ChargesApi
{
    private $apiCaller;
    private $responses;

    public function __construct(ApiCaller $apiCaller, array $responses)
    {
        $this->apiCaller = $apiCaller;
        $this->responses = $responses;
    }

    public function createCharge(?string $idempotencyKey = null, ?ChargeCreateRequest $body = null): ApiResponse
    {
        $responseBody = array_shift($this->responses);
        $this->apiCaller->recordResponse($responseBody ?? '', 200);
        return new ApiResponse(null, 200, null, [], null, $responseBody);
    }
}
