<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Differential;

use PHPUnit\Framework\TestCase;
use UnivaPay\ApiHelper;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Resources\TransactionToken;
use Univapay\Compat\Support\FallbackRegistry;
use Univapay\Compat\Support\TypedHydrator;
use Univapay\Compat\Support\TypedResult;
use Univapay\Compat\Tests\Support\DifferentialHydration;

/**
 * Differential hydration harness (see tests/Support/DifferentialHydration.php) for
 * `TransactionToken`'s 7-way discriminated union. Fixtures are the same JSON shapes
 * `tests/Unit/Resources/TransactionTokenTest.php` already uses for the raw path, so a divergence
 * here would mean typed and raw hydration disagree on a payload that resource's own unit tests
 * already treat as a realistic fixture.
 *
 * Unlike the single-model differential tests (Charge/Refund/Cancel/tokens), the typed side here
 * goes through `ApiHelper::getJsonHelper()->mapTypes()` with the SAME `oneOf{payment_type}(...)`
 * type group string the generated `TransactionTokensApi` methods use (verified against
 * `TransactionTokensApi::getTransactionToken()`) -- exercising the real discriminator-selection
 * logic, not just a pre-picked concrete class.
 */
class TransactionTokenDifferentialTest extends TestCase
{
    use DifferentialHydration;

    private const TYPE_GROUP = 'oneOf{paymentType}(CardTransactionToken{card},KonbiniTransactionToken{konbini},'
        . 'OnlineTransactionToken{online},BankTransferTransactionToken{bankTransfer},'
        . 'PaidyTransactionToken{paidy},QrScanTransactionToken{qrScan},QrMerchantTransactionToken{qrMerchant})';

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
                    'sub_brand' => 'none',
                ],
                'billing' => [
                    'line1' => 'test line 1',
                    'line2' => 'test line 2',
                    'state' => 'tokyo',
                    'city' => 'test city',
                    'country' => 'JP',
                    'zip' => '101-1111',
                    'phone_number' => ['country_code' => 81, 'local_number' => '12910298309128'],
                ],
                'cvv_authorize' => [
                    'enabled' => false,
                    'status' => null,
                    'charge_id' => null,
                    'credentials_id' => null,
                    'currency' => null,
                ],
                'three_ds' => [
                    'enabled' => false,
                    'redirect_endpoint' => null,
                    'status' => null,
                    'redirect_id' => null,
                    'error' => null,
                ],
            ],
        ], $overrides);
    }

    /**
     * Hydrates $rawBody through both paths -- the raw `JsonSchema` path, and the typed union
     * discriminator (`mapTypes()`) fed through `TypedHydrator::resolve()` -- and asserts equal.
     */
    private function assertTokenMatches(array $rawBody): void
    {
        $context = $this->differentialContext();
        $wireJson = (string) json_encode($rawBody);
        $rawDecoded = json_decode($wireJson, true);
        $objectTree = json_decode($wireJson);

        $rawObject = TransactionToken::getSchema()->parse($rawDecoded, [$context]);

        $typed = ApiHelper::getJsonHelper()->mapTypes($objectTree, self::TYPE_GROUP);
        $result = new TypedResult($rawDecoded, $typed, false);
        $typedObject = TypedHydrator::resolve(TransactionToken::class, $result, $context);

        $this->assertEquals($rawObject, $typedObject);
    }

    // --- one differential test per payment type, mirroring TransactionTokenTest's fixtures ------

    public function testCardTokenMatches(): void
    {
        $this->assertTokenMatches($this->cardTokenJson());
    }

    /**
     * `apple_pay` is NOT one of the 7 wire discriminator values the generated union recognizes
     * (`TransactionTokenPaymentType`'s spec enum has no such value at all -- Apple Pay tokens are
     * always reported as `payment_type: "card"` on the wire, distinguished only by `card.brand`;
     * compat's own `PaymentType::APPLE_PAY()` exists for the REQUEST side, creating a token, not a
     * response shape). So `mapTypes()` itself throws `OneOfValidationException` for this fixture --
     * this is exactly "a payload that fails union mapping" the real `ApiCaller` would hit too
     * (`$result->typed` null, `mapperFailed` true), and `TypedHydrator` must fall back to the raw
     * path, which still hydrates PaymentType::APPLE_PAY() fine (no typed union involved there).
     */
    public function testApplePayTokenFallsBackToRawSinceTheTypedUnionHasNoSuchDiscriminatorValue(): void
    {
        FallbackRegistry::reset();
        $json = $this->cardTokenJson(['payment_type' => 'apple_pay']);
        $context = $this->differentialContext();

        $threw = null;
        try {
            ApiHelper::getJsonHelper()->mapTypes(json_decode((string) json_encode($json)), self::TYPE_GROUP);
        } catch (\apimatic\jsonmapper\OneOfValidationException $e) {
            $threw = $e;
        }
        $this->assertNotNull($threw, 'Expected mapTypes() to reject "apple_pay" as an unrecognized discriminator.');

        // Simulate ApiCaller::callTyped()'s own catch of exactly this failure (see its class doc):
        // typed null, mapperFailed true.
        $result = new TypedResult($json, null, true);
        $token = TypedHydrator::resolve(TransactionToken::class, $result, $context);

        $this->assertEquals(PaymentType::APPLE_PAY(), $token->paymentType);
        $this->assertSame('PHP TEST', $token->data->card->cardholder);
        $this->assertSame(
            FallbackRegistry::REASON_JSONMAPPER_EXCEPTION,
            FallbackRegistry::occurrences()[0]['reason']
        );
    }

    public function testRecurringCardTokenWithCvvAuthorizeAndThreeDsMatches(): void
    {
        $json = $this->cardTokenJson(['type' => 'recurring']);
        $json['data']['cvv_authorize'] = [
            'enabled' => true,
            'status' => 'pending',
            'charge_id' => null,
            'credentials_id' => null,
            'currency' => 'USD',
        ];
        $json['data']['three_ds'] = [
            'enabled' => true,
            'redirect_endpoint' => 'https://test.int/endpoint?foo=bar',
            'status' => 'pending',
            'redirect_id' => '11efbdb4-6820-12dc-8246-6f01ed1243a9',
            'error' => ['code' => 100, 'message' => 'failed'],
        ];
        $this->assertTokenMatches($json);
    }

    public function testKonbiniTokenMatches(): void
    {
        $this->assertTokenMatches($this->cardTokenJson([
            'payment_type' => 'konbini',
            'data' => [
                'customer_name' => 'PHP test',
                'phone_number' => ['country_code' => 81, 'local_number' => '12910298309128'],
                'convenience_store' => 'seven_eleven',
                'expiration_period' => 'PT168H',
            ],
        ]));
    }

    public function testQrScanTokenMatches(): void
    {
        $this->assertTokenMatches($this->cardTokenJson([
            'payment_type' => 'qr_scan',
            'data' => ['brand' => 'pay_pay'],
        ]));
    }

    public function testQrMerchantTokenMatches(): void
    {
        $this->assertTokenMatches($this->cardTokenJson([
            'payment_type' => 'qr_merchant',
            'data' => ['brand' => 'alipay_merchant_qr'],
        ]));
    }

    public function testPaidyTokenMatches(): void
    {
        $this->assertTokenMatches($this->cardTokenJson([
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
                    'zip' => '1001000',
                ],
                'phone_number' => '08012345678',
            ],
        ]));
    }

    public function testOnlineTokenMatches(): void
    {
        $this->assertTokenMatches($this->cardTokenJson([
            'payment_type' => 'online',
            'ip_address' => '127.0.0.1',
            'data' => [
                'brand' => 'we_chat_online',
                'call_method' => 'web',
                'user_identifier' => 'PHP TEST',
                'os_type' => 'android',
            ],
        ]));
    }

    /**
     * bank_transfer has no compat `PaymentData\*` class at all -- a PRE-EXISTING raw-path gap (see
     * TransactionToken::dataFromTyped()'s doc). Both paths must agree `data` is null.
     */
    public function testBankTransferTokenHasNullDataOnBothPaths(): void
    {
        $json = $this->cardTokenJson([
            'payment_type' => 'bank_transfer',
            'data' => [
                'brand' => 'some_bank',
                'bank_code' => '0001',
                'bank_name' => 'Test Bank',
                'branch_code' => '001',
                'branch_name' => 'Main',
                'account_number' => '1234567',
                'account_holder_name' => 'Taro Yamada',
            ],
        ]);

        $this->assertTokenMatches($json);

        $context = $this->differentialContext();
        $rawObject = TransactionToken::getSchema()->parse($json, [$context]);
        $this->assertNull($rawObject->data);
    }

    /**
     * `ip_address` is a genuine spec gap (no generated variant carries it) -- both paths must
     * still agree it survives via the raw-body patch.
     */
    public function testIpAddressSurvivesDespiteBeingAbsentFromEveryTypedVariant(): void
    {
        $json = $this->cardTokenJson(['ip_address' => '203.0.113.5']);
        $this->assertTokenMatches($json);

        $context = $this->differentialContext();
        $wireJson = (string) json_encode($json);
        $typed = ApiHelper::getJsonHelper()->mapTypes(json_decode($wireJson), self::TYPE_GROUP);
        $hydrated = TransactionToken::hydrateFromTyped($typed, json_decode($wireJson, true), $context);
        $this->assertSame('203.0.113.5', $hydrated->ipAddress);
    }

    // --- fallback regression: a required field genuinely missing --------------------------------

    public function testMissingRequiredModeDeclinesAndFallsBackToTheSameRawException(): void
    {
        FallbackRegistry::reset();
        $json = $this->cardTokenJson();
        unset($json['mode']);
        $context = $this->differentialContext();

        $wireJson = (string) json_encode($json);
        $rawDecoded = json_decode($wireJson, true);
        $typed = ApiHelper::getJsonHelper()->mapTypes(json_decode($wireJson), self::TYPE_GROUP);
        $result = new TypedResult($rawDecoded, $typed, false);

        $threw = null;
        try {
            TypedHydrator::resolve(TransactionToken::class, $result, $context);
        } catch (\Univapay\Compat\Utility\Json\NoSuchPathException $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw);
        $this->assertSame(FallbackRegistry::REASON_HYDRATION_DECLINED, FallbackRegistry::occurrences()[0]['reason']);
    }

    /**
     * A malformed CARD `data.card` missing its required `cvv_authorize` sibling: `CardData::
     * hydrateFromTyped()` declines, and `TransactionToken::hydrateFromTyped()` propagates that
     * decline (its own `DATA_UNMAPPABLE` sentinel) rather than silently hydrating a token with a
     * dropped `data`. The raw fallback throws the identical exception the raw path always has.
     */
    public function testCardDataMissingCvvAuthorizeDeclinesAndFallsBackToTheSameRawException(): void
    {
        FallbackRegistry::reset();
        $json = $this->cardTokenJson();
        unset($json['data']['cvv_authorize']);
        $context = $this->differentialContext();

        $wireJson = (string) json_encode($json);
        $rawDecoded = json_decode($wireJson, true);
        $typed = ApiHelper::getJsonHelper()->mapTypes(json_decode($wireJson), self::TYPE_GROUP);
        $result = new TypedResult($rawDecoded, $typed, false);

        $threw = null;
        try {
            TypedHydrator::resolve(TransactionToken::class, $result, $context);
        } catch (\Univapay\Compat\Utility\Json\NoSuchPathException $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw);
        $this->assertSame(FallbackRegistry::REASON_HYDRATION_DECLINED, FallbackRegistry::occurrences()[0]['reason']);
    }
}
