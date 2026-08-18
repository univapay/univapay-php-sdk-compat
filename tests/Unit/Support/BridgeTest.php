<?php

namespace Univapay\Compat\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use UnivaPay\Apis\ChargesApi;
use Univapay\Compat\Errors\UnivapaySDKError;
use Univapay\Compat\Requests\Handlers\NetworkRetryHandler;
use Univapay\Compat\Requests\Handlers\RateLimitHandler;
use Univapay\Compat\Requests\Handlers\RequestHandler;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Resources\Authentication\MerchantAppJWT;
use Univapay\Compat\Resources\Authentication\StoreAppJWT;
use Univapay\Compat\Support\ApiCaller;
use Univapay\Compat\Support\Bridge;
use Univapay\Compat\UnivapayClientOptions;
use Closure;

class BridgeTest extends TestCase
{
    private function token(array $payload): string
    {
        $header = base64_encode(json_encode(['alg' => 'none']));
        $body = base64_encode(json_encode($payload));
        return "$header.$body.sig";
    }

    private function storeJwt(): StoreAppJWT
    {
        $token = $this->token([
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
        return AppJWT::createToken($token, 'secret-1');
    }

    private function merchantJwt(): MerchantAppJWT
    {
        $token = $this->token([
            'sub' => 'app_token',
            'iat' => 1,
            'merchant_id' => 'merchant-1',
            'creator_id' => 'creator-1',
            'version' => 1,
            'jti' => 'jti-1'
        ]);
        return AppJWT::createToken($token, 'secret-1');
    }

    public function testStoreIdAndMerchantIdComeFromTheStoreJwt()
    {
        $bridge = new Bridge($this->storeJwt());

        $this->assertSame('store-1', $bridge->storeId());
        $this->assertSame('merchant-1', $bridge->merchantId());
    }

    public function testStoreIdIsNullForAMerchantLevelJwt()
    {
        $bridge = new Bridge($this->merchantJwt());

        $this->assertNull($bridge->storeId());
        $this->assertSame('merchant-1', $bridge->merchantId());
    }

    public function testDefaultHandlersAreRateLimitInnerThenNetworkRetryOuter()
    {
        $bridge = new Bridge($this->storeJwt());

        $handlers = $bridge->handlers();

        $this->assertCount(2, $handlers);
        $this->assertInstanceOf(RateLimitHandler::class, $handlers[0]);
        $this->assertInstanceOf(NetworkRetryHandler::class, $handlers[1]);
    }

    public function testAddHandlersAppendsAsNewOutermostLayers()
    {
        $bridge = new Bridge($this->storeJwt());
        $custom = $this->fakeHandler();

        $bridge->addHandlers($custom);

        $handlers = $bridge->handlers();
        $this->assertCount(3, $handlers);
        $this->assertInstanceOf(RateLimitHandler::class, $handlers[0]);
        $this->assertInstanceOf(NetworkRetryHandler::class, $handlers[1]);
        $this->assertSame($custom, $handlers[2]);
    }

    public function testSetHandlersReplacesTheCascadeWithDefaultsFollowedByGiven()
    {
        $bridge = new Bridge($this->storeJwt());
        $bridge->addHandlers($this->fakeHandler()); // should be discarded by setHandlers()
        $custom = $this->fakeHandler();

        $bridge->setHandlers($custom);

        $handlers = $bridge->handlers();
        $this->assertCount(3, $handlers);
        $this->assertInstanceOf(RateLimitHandler::class, $handlers[0]);
        $this->assertInstanceOf(NetworkRetryHandler::class, $handlers[1]);
        $this->assertSame($custom, $handlers[2]);
    }

    public function testConstructsSuccessfullyWithCustomOptionsAndExposesTheSameJwtAndCaller()
    {
        $options = new UnivapayClientOptions('https://staging.example.com');
        $jwt = $this->storeJwt();
        $bridge = new Bridge($jwt, $options);

        // Constructing without throwing, plus jwt()/caller() wiring, is part of the
        // externally-observable contract here; client()'s own config parity (baseUrl/timeout/
        // bearer credentials) is covered by testClientReturnsTheConfiguredGeneratedSdkClient()
        // and testClientReturnsTheSameMemoizedInstanceAcrossCalls() below.
        $this->assertSame($jwt, $bridge->jwt());
        $this->assertInstanceOf(ApiCaller::class, $bridge->caller());
    }

    // --- client(): the underlying accessor behind UnivapayClient::native()'s off-ramp escape
    // hatch -----------------------------------------------------------------------------------

    public function testClientReturnsTheConfiguredGeneratedSdkClient()
    {
        $options = new UnivapayClientOptions('https://staging.example.com');
        $jwt = $this->storeJwt();
        $bridge = new Bridge($jwt, $options);

        $client = $bridge->client();

        $this->assertInstanceOf(\UnivaPay\UnivapayClientSdkClient::class, $client);
        $this->assertSame('https://staging.example.com', $client->getBaseUrl());
        $this->assertSame(10, $client->getTimeout());
        $credentials = $client->getBearerAuthCredentials();
        $this->assertSame('secret-1', $credentials->getSecretKey());
        $this->assertSame($jwt->token, $credentials->getJwtToken());
    }

    public function testClientReturnsTheSameMemoizedInstanceAcrossCalls()
    {
        $bridge = new Bridge($this->storeJwt());

        $this->assertSame($bridge->client(), $bridge->client());
    }

    public function testControllerAccessorsReturnMemoizedInstances()
    {
        $bridge = new Bridge($this->storeJwt());

        $this->assertInstanceOf(ChargesApi::class, $bridge->charges());
        $this->assertSame($bridge->charges(), $bridge->charges());
    }

    // --- requireStoreId(): the client-side createToken()/getCheckoutInfo()/getTransactionToken()
    // preflight guard seam, consumed by Charge/Subscription creation and UnivapayClient. --------

    public function testRequireStoreIdReturnsTheStoreIdForAStoreJwt()
    {
        $bridge = new Bridge($this->storeJwt());

        $this->assertSame('store-1', $bridge->requireStoreId());
    }

    public function testRequireStoreIdThrowsWithOldErrorParityForAMerchantJwt()
    {
        $bridge = new Bridge($this->merchantJwt());

        try {
            $bridge->requireStoreId();
            $this->fail('Expected a UnivapaySDKError');
        } catch (UnivapaySDKError $e) {
            // Reason::REQUIRES_STORE_APP_TOKEN()'s explicit message override -- old-SDK-identical,
            // matching UnivapayClient::getStoreBasedContext()'s guard.
            $this->assertSame(
                'A store app token is required and has not been included during client creation',
                $e->getMessage()
            );
        }
    }

    private function fakeHandler(): RequestHandler
    {
        return new class implements RequestHandler {
            public function handle(Closure $request, array $requestData)
            {
                return $request($requestData);
            }
        };
    }
}
