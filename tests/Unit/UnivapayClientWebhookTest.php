<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\WebhookEvent;
use Univapay\Compat\Errors\UnivapayInvalidWebhookData;
use Univapay\Compat\Errors\UnivapayUnknownWebhookEvent;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Resources\Cancel;
use Univapay\Compat\Resources\Charge;
use Univapay\Compat\Resources\Refund;
use Univapay\Compat\Resources\Subscription;
use Univapay\Compat\Resources\TransactionToken;
use Univapay\Compat\Resources\Transfer;
use Univapay\Compat\Resources\WebhookPayload;
use Univapay\Compat\UnivapayClient;

/**
 * Covers `UnivapayClient::parseWebhookData()` -- every `WebhookEvent` case's dispatch
 * plus the three documented corner semantics (see that method's own class doc for the exact
 * old-SDK behavior each fixture below reproduces):
 *
 * 1. Merchant-JWT TOKEN-prefixed / REFUND_FINISHED / CANCEL_FINISHED events -> `UnivapayInvalidWebhookData`
 *    (the store-context guard throws INSIDE parser selection, old code's catch swallows it).
 * 2. `CUSTOMS_DECLARATION_FINISHED` (a real `WebhookEvent` case with no switch arm, hence no
 *    parser) -> `UnivapayInvalidWebhookData` (the null-parser call becomes `\Error`, caught by
 *    this method's `Throwable` catch -- see method doc for why `Exception` alone would not
 *    actually catch this in real PHP).
 * 3. A garbage `event` string unknown to `WebhookEvent` itself -> `UnivapayUnknownWebhookEvent`.
 * 4. Transfer events hydrate a real `Transfer` regardless of unsupported-API status.
 */
class UnivapayClientWebhookTest extends TestCase
{
    private function token(array $payload): string
    {
        $header = base64_encode((string) json_encode(['alg' => 'none']));
        $body = base64_encode((string) json_encode($payload));
        return "$header.$body.sig";
    }

    private function storeClient(): UnivapayClient
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
        return new UnivapayClient(AppJWT::createToken($jwt, 'secret-1'));
    }

    private function merchantClient(): UnivapayClient
    {
        $jwt = $this->token([
            'sub' => 'app_token',
            'iat' => 1,
            'merchant_id' => 'merchant-1',
            'creator_id' => 'creator-1',
            'version' => 1,
            'jti' => 'jti-1'
        ]);
        return new UnivapayClient(AppJWT::createToken($jwt, 'secret-1'));
    }

    private static function tokenPayload(): array
    {
        return [
            'id' => 'token-1', 'store_id' => 'store-1', 'email' => 'test@test.com', 'active' => true,
            'payment_type' => 'card', 'mode' => 'test', 'type' => 'one_time', 'confirmed' => null,
            'created_on' => '2030-01-01T00:00:00.000000Z', 'metadata' => null,
            'data' => [
                'card' => [
                    'cardholder' => 'PHP TEST', 'exp_month' => 2, 'exp_year' => 2030,
                    'last_four' => '1831', 'brand' => 'mastercard', 'country' => 'JP',
                    'card_type' => 'credit', 'category' => 'signature', 'issuer' => 'BANCO',
                    'sub_brand' => 'none'
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
        ];
    }

    private function chargePayload(): array
    {
        return [
            'id' => 'charge-1', 'store_id' => 'store-1', 'transaction_token_id' => 'token-1',
            'transaction_token_type' => 'one_time', 'subscription_id' => null,
            'requested_amount' => 1000, 'requested_currency' => 'JPY',
            'requested_amount_formatted' => 1000, 'status' => 'successful', 'mode' => 'test',
            'created_on' => '2022-07-26T10:33:12.934225Z', 'metadata' => [],
        ];
    }

    private function subscriptionPayload(): array
    {
        return [
            'id' => 'sub-1', 'store_id' => 'store-1', 'transaction_token_id' => 'token-1',
            'amount' => 1000, 'currency' => 'JPY', 'amount_formatted' => 1000, 'period' => 'monthly',
            'schedule_settings' => ['zone_id' => 'Asia/Tokyo'], 'payments_left' => 12,
            'status' => 'unverified', 'metadata' => [], 'mode' => 'test', 'amount_left' => 12000,
            'amount_left_formatted' => 12000, 'created_on' => '2022-07-26T10:33:12.934225Z',
        ];
    }

    private static function refundPayload(): array
    {
        return [
            'id' => 'refund-1', 'store_id' => 'store-1', 'charge_id' => 'charge-1', 'amount' => 500,
            'currency' => 'JPY', 'amount_formatted' => 500, 'status' => 'successful',
            'mode' => 'test', 'created_on' => '2022-07-26T10:33:12.934225Z', 'metadata' => [],
        ];
    }

    private static function cancelPayload(): array
    {
        return [
            'id' => 'cancel-1', 'charge_id' => 'charge-1', 'status' => 'successful', 'mode' => 'test',
            'created_on' => '2022-07-26T10:33:12.934225Z', 'metadata' => [],
        ];
    }

    private function transferPayload(): array
    {
        return [
            'id' => 'transfer-1', 'bank_account_id' => 'bank-account-1', 'currency' => 'JPY',
            'amount' => 10000, 'amount_formatted' => 10000, 'status' => 'paid',
            'error_code' => null, 'error_text' => null, 'metadata' => [], 'note' => null,
            'from' => '2022-07-01T00:00:00.000000Z', 'to' => '2022-07-31T00:00:00.000000Z',
            'created_on' => '2022-08-01T00:00:00.000000Z',
        ];
    }

    // --- successful hydration per event group, store-scoped client -------------------------------

    /**
     * @dataProvider tokenEvents
     */
    #[DataProvider('tokenEvents')]
    public function testTokenEventsHydrateATransactionTokenOnAStoreScopedClient($eventValue, $eventCase)
    {
        $client = $this->storeClient();
        $payload = $client->parseWebhookData(['event' => $eventValue, 'data' => $this->tokenPayload()]);

        $this->assertInstanceOf(WebhookPayload::class, $payload);
        $this->assertEquals($eventCase, $payload->event);
        $this->assertInstanceOf(TransactionToken::class, $payload->data);
        $this->assertSame('token-1', $payload->data->id);
    }

    public static function tokenEvents(): array
    {
        return [
            'token_created' => ['token_created', WebhookEvent::TOKEN_CREATED()],
            'token_updated' => ['token_updated', WebhookEvent::TOKEN_UPDATED()],
            'token_cvv_auth_updated' => ['token_cvv_auth_updated', WebhookEvent::TOKEN_CVV_AUTH_UPDATED()],
            'recurring_token_deleted' => ['recurring_token_deleted', WebhookEvent::RECURRING_TOKEN_DELETED()],
        ];
    }

    /**
     * @dataProvider chargeEvents
     */
    #[DataProvider('chargeEvents')]
    public function testChargeEventsHydrateAChargeRegardlessOfJwtType($eventValue, $eventCase)
    {
        foreach ([$this->storeClient(), $this->merchantClient()] as $client) {
            $payload = $client->parseWebhookData(['event' => $eventValue, 'data' => $this->chargePayload()]);

            $this->assertEquals($eventCase, $payload->event);
            $this->assertInstanceOf(Charge::class, $payload->data);
            $this->assertSame('charge-1', $payload->data->id);
        }
    }

    public static function chargeEvents(): array
    {
        return [
            'charge_updated' => ['charge_updated', WebhookEvent::CHARGE_UPDATED()],
            'charge_finished' => ['charge_finished', WebhookEvent::CHARGE_FINISHED()],
        ];
    }

    /**
     * @dataProvider subscriptionEvents
     */
    #[DataProvider('subscriptionEvents')]
    public function testSubscriptionEventsHydrateASubscriptionRegardlessOfJwtType($eventValue, $eventCase)
    {
        foreach ([$this->storeClient(), $this->merchantClient()] as $client) {
            $payload = $client->parseWebhookData(['event' => $eventValue, 'data' => $this->subscriptionPayload()]);

            $this->assertEquals($eventCase, $payload->event);
            $this->assertInstanceOf(Subscription::class, $payload->data);
            $this->assertSame('sub-1', $payload->data->id);
        }
    }

    public static function subscriptionEvents(): array
    {
        return [
            'subscription_payment' => ['subscription_payment', WebhookEvent::SUBSCRIPTION_PAYMENT()],
            'subscription_completed' => ['subscription_completed', WebhookEvent::SUBSCRIPTION_COMPLETED()],
            'subscription_failure' => ['subscription_failure', WebhookEvent::SUBSCRIPTION_FAILURE()],
            'subscription_canceled' => ['subscription_canceled', WebhookEvent::SUBSCRIPTION_CANCELED()],
            'subscription_suspended' => ['subscription_suspended', WebhookEvent::SUBSCRIPTION_SUSPENDED()],
        ];
    }

    public function testRefundFinishedHydratesARefundOnAStoreScopedClient()
    {
        $client = $this->storeClient();
        $payload = $client->parseWebhookData(['event' => 'refund_finished', 'data' => $this->refundPayload()]);

        $this->assertEquals(WebhookEvent::REFUND_FINISHED(), $payload->event);
        $this->assertInstanceOf(Refund::class, $payload->data);
    }

    public function testCancelFinishedHydratesACancelOnAStoreScopedClient()
    {
        $client = $this->storeClient();
        $payload = $client->parseWebhookData(['event' => 'cancel_finished', 'data' => $this->cancelPayload()]);

        $this->assertEquals(WebhookEvent::CANCEL_FINISHED(), $payload->event);
        $this->assertInstanceOf(Cancel::class, $payload->data);
    }

    /**
     * @dataProvider transferEvents
     */
    #[DataProvider('transferEvents')]
    public function testTransferEventsHydrateATransferRegardlessOfJwtTypeOrUnsupportedApiStatus($eventValue, $eventCase)
    {
        foreach ([$this->storeClient(), $this->merchantClient()] as $client) {
            $payload = $client->parseWebhookData(['event' => $eventValue, 'data' => $this->transferPayload()]);

            $this->assertEquals($eventCase, $payload->event);
            $this->assertInstanceOf(Transfer::class, $payload->data);
            $this->assertSame('transfer-1', $payload->data->id);
        }
    }

    public static function transferEvents(): array
    {
        return [
            'transfer_created' => ['transfer_created', WebhookEvent::TRANSFER_CREATED()],
            'transfer_updated' => ['transfer_updated', WebhookEvent::TRANSFER_UPDATED()],
            'transfer_finalized' => ['transfer_finalized', WebhookEvent::TRANSFER_FINALIZED()],
        ];
    }

    // --- corner 1: merchant-JWT store-scoped events swallowed into UnivapayInvalidWebhookData ----

    /**
     * @dataProvider storeScopedEventsForMerchantJwtCorner
     */
    #[DataProvider('storeScopedEventsForMerchantJwtCorner')]
    public function testMerchantJwtOnAStoreScopedEventIsSwallowedIntoInvalidWebhookData($eventValue, $payload)
    {
        $client = $this->merchantClient();

        $this->expectException(UnivapayInvalidWebhookData::class);

        $client->parseWebhookData(['event' => $eventValue, 'data' => $payload]);
    }

    public static function storeScopedEventsForMerchantJwtCorner(): array
    {
        return [
            'token_created' => ['token_created', self::tokenPayload()],
            'refund_finished' => ['refund_finished', self::refundPayload()],
            'cancel_finished' => ['cancel_finished', self::cancelPayload()],
        ];
    }

    // --- corner 2: CUSTOMS_DECLARATION_FINISHED (valid case, no switch arm, null-parser \Error) ---

    public function testCustomsDeclarationFinishedHasNoParserAndIsSwallowedIntoInvalidWebhookData()
    {
        $client = $this->storeClient();

        // Confirms the event string itself IS a real WebhookEvent case (not the unknown-string
        // corner below) -- fromValue() must not throw OutOfRangeException for it.
        $this->assertInstanceOf(WebhookEvent::class, WebhookEvent::fromValue('customs_declaration_finished'));

        $this->expectException(UnivapayInvalidWebhookData::class);

        $client->parseWebhookData(['event' => 'customs_declaration_finished', 'data' => ['id' => 'customs-1']]);
    }

    public function testCustomsCornerReproducesTheSameOutcomeOnAMerchantJwtClientToo()
    {
        $client = $this->merchantClient();

        $this->expectException(UnivapayInvalidWebhookData::class);

        $client->parseWebhookData(['event' => 'customs_declaration_finished', 'data' => ['id' => 'customs-1']]);
    }

    // --- corner 3: a garbage event string unknown to WebhookEvent itself -------------------------

    public function testUnrecognizedEventStringThrowsUnivapayUnknownWebhookEvent()
    {
        $client = $this->storeClient();

        try {
            $client->parseWebhookData(['event' => 'totally_bogus_event', 'data' => []]);
            $this->fail('Expected a UnivapayUnknownWebhookEvent');
        } catch (UnivapayUnknownWebhookEvent $e) {
            $this->assertStringContainsString('totally_bogus_event', $e->getMessage());
        }
    }
}
