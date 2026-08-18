<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Integration;

use DateTime;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\CallMethod;
use Univapay\Compat\Enums\ConvenienceStore;
use Univapay\Compat\Enums\OnlineBrand;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Enums\QrBrandMerchant;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Resources\PaymentData\CardData;
use Univapay\Compat\Resources\PaymentData\ConvenienceStoreData;
use Univapay\Compat\Resources\PaymentData\PaidyData;
use Univapay\Compat\Resources\PaymentData\Address;
use Univapay\Compat\Resources\PaymentData\PhoneNumber;
use Univapay\Compat\Resources\PaymentMethod\CardPayment;
use Univapay\Compat\Resources\PaymentMethod\ConvenienceStorePayment;
use Univapay\Compat\Resources\PaymentMethod\OnlinePayment;
use Univapay\Compat\Resources\PaymentMethod\PaidyPayment;
use Univapay\Compat\Resources\PaymentMethod\PaymentMethodPatch;
use Univapay\Compat\Resources\PaymentMethod\QrMerchantPayment;
use Univapay\Compat\Resources\PaymentMethod\QrScanPayment;
use Univapay\Compat\Resources\PaymentToken\ThreeDSIssuerToken;
use Univapay\Compat\Resources\TransactionToken;

/**
 * @group integration
 *
 * Round-trips: createToken() for each of the 6 create-able payment types (card/konbini/online/
 * qr_scan/qr_merchant/paidy), getTransactionToken(), patch(), deactivate(), enableThreeDS(),
 * threeDSIssuerToken().
 *
 * ## Prism example-selection limitation (documented, not fought)
 *
 * `POST /tokens` and `GET /stores/{storeId}/tokens/{id}` both have NINE named response examples
 * (one per payment-type variant), but Prism -- with no `Prefer: example=<name>` header, which
 * this compat layer's public surface has no way to send (the generated controllers accept no
 * extra-header parameter for it, and `Support\ApiCaller`'s `HttpCallBack` is wired for
 * afterResponse capture only) -- always serves the FIRST-DEFINED example key, verified to be the
 * CARD variant (`cardResponse`, `type: "recurring"`) regardless of the request body's own
 * payment type. So every `createToken()` call below gets back a real 201 with a real, fully
 * hydrated `TransactionToken` -- proving the REQUEST side (each payment type's own
 * `Support\RequestModelFactory` builder, wire-validated by Prism's own JSON-Schema request
 * validation) round-trips correctly end-to-end -- but the RESPONSE `data` will always hydrate as
 * `PaymentData\CardData`, never the payment type actually submitted. The other 8 payment types'
 * RESPONSE hydration (konbini/online/qr_scan/qr_merchant/paidy/bank_transfer/...) is exercised
 * separately, offline, by tests/RoundTrip/ExampleRoundTripTest.php, which feeds every named
 * example directly through the same parsers without going through Prism at all.
 */
class TokenTest extends IntegrationTestCase
{
    private function assertCommonTokenShape(TransactionToken $token): void
    {
        $this->assertNotNull($token->id);
        $this->assertInstanceOf(PaymentType::class, $token->paymentType);
        $this->assertInstanceOf(AppTokenMode::class, $token->mode);
        $this->assertInstanceOf(TokenType::class, $token->type);
        $this->assertInstanceOf(DateTime::class, $token->createdOn);
        $this->assertIsBool($token->active);
    }

    public function testCreateTokenWithCardPayment(): void
    {
        $payment = new CardPayment('test@example.com', 'TARO YAMADA', '4242424242424242', '01', '2030', '123');

        $token = $this->storeClient()->createToken($payment);

        $this->assertCommonTokenShape($token);
        $this->assertInstanceOf(CardData::class, $token->data);
    }

    public function testCreateTokenWithConvenienceStorePayment(): void
    {
        $payment = new ConvenienceStorePayment(
            'test@example.com',
            new ConvenienceStoreData(
                'TARO YAMADA',
                new PhoneNumber(PhoneNumber::JP, '08012341234'),
                ConvenienceStore::FAMILY_MART()
            )
        );

        $token = $this->storeClient()->createToken($payment);

        $this->assertCommonTokenShape($token);
    }

    public function testCreateTokenWithOnlinePayment(): void
    {
        // Only 5 of ~24 old OnlineBrand cases are covered by the generated
        // BaseOnlineDataBrand enum today (Support\RequestModelFactory::buildOnlineData()'s own
        // documented coverage gap) -- PAY_PAY_ONLINE is one of the 5, and a non-null CallMethod
        // is required (TokenCreateOnlineData has no old-SDK-equivalent nullable default).
        $payment = new OnlinePayment('test@example.com', OnlineBrand::PAY_PAY_ONLINE(), null, null, CallMethod::WEB());

        $token = $this->storeClient()->createToken($payment);

        $this->assertCommonTokenShape($token);
    }

    public function testCreateTokenWithQrScanPayment(): void
    {
        $payment = new QrScanPayment('test@example.com', 'raw-scanned-qr-payload');

        $token = $this->storeClient()->createToken($payment);

        $this->assertCommonTokenShape($token);
    }

    public function testCreateTokenWithQrMerchantPayment(): void
    {
        // PAY_PAY_MERCHANT avoids the two documented wire-parity gap cases (TOUCH_N_GO/
        // PUBLICBANK -- RequestModelFactory::buildQrMerchantData()'s class doc).
        $payment = new QrMerchantPayment('test@example.com', QrBrandMerchant::PAY_PAY_MERCHANT());

        $token = $this->storeClient()->createToken($payment);

        $this->assertCommonTokenShape($token);
    }

    public function testCreateTokenWithPaidyPayment(): void
    {
        $payment = new PaidyPayment(new PaidyData(
            'paidy-token-abc',
            new Address('1-1-1', 'Shibakoen', 'Tokyo', 'Minato', 'JP', '105-0011')
        ), 'test@example.com');

        $token = $this->storeClient()->createToken($payment);

        $this->assertCommonTokenShape($token);
    }

    public function testGetTransactionTokenReturnsATypedToken(): void
    {
        $token = $this->storeClient()->getTransactionToken(self::TOKEN_ID);

        $this->assertInstanceOf(TransactionToken::class, $token);
        // NOT asserted against self::TOKEN_ID: Prism serves its own example body verbatim
        // regardless of the requested path param's value (see ChargeTest's identical note).
        $this->assertNotEmpty($token->id);
        $this->assertCommonTokenShape($token);
        $this->assertInstanceOf(CardData::class, $token->data);
    }

    public function testPatchReturnsANewInstanceViaUpdateThenFetch(): void
    {
        $token = $this->storeClient()->getTransactionToken(self::TOKEN_ID);

        $patched = $token->patch(new PaymentMethodPatch('patched@example.com'));

        // TransactionToken::patch() is documented to update() then fetch() (PATCH's own response
        // doesn't always carry the full `data` back) -- either way this must be a NEW instance,
        // never $this (old-SDK "fetch/update/patch never mutate" contract).
        $this->assertInstanceOf(TransactionToken::class, $patched);
        $this->assertNotSame($token, $patched);
    }

    public function testDeactivateReturnsTrue(): void
    {
        $token = $this->storeClient()->getTransactionToken(self::TOKEN_ID);

        $result = $token->deactivate();

        $this->assertTrue($result);
    }

    public function testEnableThreeDSHydratesANewTokenAndDisableReturnsTrue(): void
    {
        // The default (only reachable, per this class's own doc) response example is
        // `type: "recurring"` -- enableThreeDS()'s RECURRING-only guard passes for free.
        $token = $this->storeClient()->getTransactionToken(self::TOKEN_ID);
        $this->assertSame(TokenType::RECURRING(), $token->type);

        $enabled = $token->enableThreeDS(true, 'https://example.com/3ds-redirect');
        $this->assertInstanceOf(TransactionToken::class, $enabled);

        $disabled = $token->enableThreeDS(false);
        $this->assertTrue($disabled);
    }

    public function testThreeDSIssuerTokenReturnsATypedIssuerToken(): void
    {
        $token = $this->storeClient()->getTransactionToken(self::TOKEN_ID);

        $issuerToken = $token->threeDSIssuerToken();

        $this->assertInstanceOf(ThreeDSIssuerToken::class, $issuerToken);
    }
}
