<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Integration;

use DateTime;
use Money\Currency;
use Money\Money;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\ChargeStatus;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Resources\Cancel;
use Univapay\Compat\Resources\Charge;
use Univapay\Compat\Resources\Paginated;
use Univapay\Compat\Resources\PaymentToken\OnlineToken;
use Univapay\Compat\Resources\PaymentToken\ThreeDSIssuerToken;
use Univapay\Compat\Resources\Refund;

/**
 * @group integration
 *
 * Round-trips: createCharge() (both the client-level two-step flow and the token-level direct
 * call), getCharge(), capture() (with and without a Money -- the body is optional), createRefund(),
 * cancel(), onlineToken(), threeDSIssuerToken(), awaitResult(), listRefunds()/listCancels().
 */
class ChargeTest extends IntegrationTestCase
{
    private function assertCommonChargeShape(Charge $charge): void
    {
        $this->assertNotNull($charge->id);
        $this->assertInstanceOf(TokenType::class, $charge->transactionTokenType);
        $this->assertInstanceOf(Currency::class, $charge->requestedCurrency);
        $this->assertInstanceOf(Money::class, $charge->requestedAmount);
        $this->assertInstanceOf(ChargeStatus::class, $charge->status);
        $this->assertInstanceOf(AppTokenMode::class, $charge->mode);
        $this->assertInstanceOf(DateTime::class, $charge->createdOn);
    }

    /**
     * `UnivapayClient::createCharge()`'s "two-step create flow" (see its own class doc): a real
     * `GET /stores/{storeId}/tokens/{id}` fires first (store-token guard + 404 timing parity),
     * THEN the token's own `createCharge()` delegates the `POST /charges`.
     */
    public function testCreateChargeViaClientTwoStepFlow(): void
    {
        $charge = $this->storeClient()->createCharge(self::TOKEN_ID, Money::JPY(1000));

        $this->assertCommonChargeShape($charge);
    }

    public function testCreateChargeViaTokenDirectly(): void
    {
        $token = $this->storeClient()->getTransactionToken(self::TOKEN_ID);

        $charge = $token->createCharge(Money::JPY(1000));

        $this->assertCommonChargeShape($charge);
    }

    public function testGetChargeReturnsATypedCharge(): void
    {
        $charge = $this->storeClient()->getCharge(self::STORE_ID, self::CHARGE_ID);

        $this->assertInstanceOf(Charge::class, $charge);
        // NOT asserted against self::CHARGE_ID: Prism's static mock returns its OWN example
        // body verbatim regardless of the path param value requested (it pattern-matches the
        // URL template, not the id's actual content) -- the response's `id` is whatever the
        // spec's own GetChargeResponse example hardcodes, not an echo of the path param.
        $this->assertNotEmpty($charge->id);
        $this->assertCommonChargeShape($charge);
    }

    public function testAwaitResultReturnsANewHydratedInstance(): void
    {
        $charge = $this->storeClient()->getCharge(self::STORE_ID, self::CHARGE_ID);

        $polled = $charge->awaitResult(0);

        $this->assertInstanceOf(Charge::class, $polled);
        $this->assertNotSame($charge, $polled);
    }

    /**
     * `ChargesApi::captureCharge()`'s own `$body` is optional -- a non-null
     * `Money` capture sends a real typed `ChargeCaptureRequest`.
     */
    public function testCaptureWithMoneyReturnsTrue(): void
    {
        $charge = $this->storeClient()->getCharge(self::STORE_ID, self::CHARGE_ID);

        $result = $charge->capture(Money::JPY(500));

        $this->assertTrue($result);
    }

    /**
     * `capture(null)` sends NO body at all (not a requestedAmount-substitution workaround) --
     * server captures the full outstanding amount.
     */
    public function testCaptureWithoutMoneyReturnsTrue(): void
    {
        $charge = $this->storeClient()->getCharge(self::STORE_ID, self::CHARGE_ID);

        $result = $charge->capture();

        $this->assertTrue($result);
    }

    public function testCreateRefundReturnsATypedRefund(): void
    {
        $charge = $this->storeClient()->getCharge(self::STORE_ID, self::CHARGE_ID);

        $refund = $charge->createRefund(Money::JPY(500));

        $this->assertInstanceOf(Refund::class, $refund);
        $this->assertInstanceOf(Money::class, $refund->amount);
    }

    public function testCancelReturnsATypedCancel(): void
    {
        $charge = $this->storeClient()->getCharge(self::STORE_ID, self::CHARGE_ID);

        $cancel = $charge->cancel();

        $this->assertInstanceOf(Cancel::class, $cancel);
    }

    public function testOnlineTokenReturnsATypedOnlineToken(): void
    {
        $charge = $this->storeClient()->getCharge(self::STORE_ID, self::CHARGE_ID);

        $onlineToken = $charge->onlineToken();

        $this->assertInstanceOf(OnlineToken::class, $onlineToken);
    }

    public function testThreeDSIssuerTokenReturnsATypedIssuerToken(): void
    {
        $charge = $this->storeClient()->getCharge(self::STORE_ID, self::CHARGE_ID);

        $issuerToken = $charge->threeDSIssuerToken();

        $this->assertInstanceOf(ThreeDSIssuerToken::class, $issuerToken);
    }

    public function testListRefundsReturnsAPaginatedPageOfTypedRefunds(): void
    {
        $charge = $this->storeClient()->getCharge(self::STORE_ID, self::CHARGE_ID);

        $page = $charge->listRefunds();

        $this->assertInstanceOf(Paginated::class, $page);
        foreach ($page->items as $item) {
            $this->assertInstanceOf(Refund::class, $item);
        }
    }

    public function testListCancelsReturnsAPaginatedPageOfTypedCancels(): void
    {
        $charge = $this->storeClient()->getCharge(self::STORE_ID, self::CHARGE_ID);

        $page = $charge->listCancels();

        $this->assertInstanceOf(Paginated::class, $page);
        foreach ($page->items as $item) {
            $this->assertInstanceOf(Cancel::class, $item);
        }
    }

    public function testQrMerchantTokenIsUnsupported(): void
    {
        $charge = $this->storeClient()->getCharge(self::STORE_ID, self::CHARGE_ID);

        $this->expectException(\Univapay\Compat\Errors\UnivapayUnsupportedFeatureError::class);

        $charge->qrMerchantToken();
    }
}
