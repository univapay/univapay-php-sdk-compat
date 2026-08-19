<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Differential;

use PHPUnit\Framework\TestCase;
use UnivaPay\Models\IssuerToken as GeneratedIssuerToken;
use UnivaPay\Models\ThreeDsIssuerToken as GeneratedThreeDsIssuerToken;
use Univapay\Compat\Resources\PaymentToken\OnlineToken;
use Univapay\Compat\Resources\PaymentToken\ThreeDSIssuerToken;
use Univapay\Compat\Tests\Support\DifferentialHydration;

/**
 * Differential hydration harness (see tests/Support/DifferentialHydration.php) for
 * `PaymentToken\OnlineToken` (typed model: `UnivaPay\Models\IssuerToken`) and
 * `PaymentToken\ThreeDSIssuerToken` (typed model: `UnivaPay\Models\ThreeDsIssuerToken`).
 */
class PaymentTokenDifferentialTest extends TestCase
{
    use DifferentialHydration;

    // --- OnlineToken / IssuerToken ---------------------------------------------------------------

    public function testOnlineVariantMatches(): void
    {
        $json = [
            'issuer_token' => 'https://issuer.example.com/redirect',
            'call_method' => 'web',
            'payload' => (object) [],
            'payment_type' => 'online',
        ];

        $this->assertTypedMatchesRaw(OnlineToken::class, GeneratedIssuerToken::class, $json);
    }

    /**
     * The bank_transfer variant this class's own docblock describes: no `call_method` at all
     * (relaxed to optional for exactly this reason), and issuer_token absent too -- the generated
     * `IssuerToken` model already flattens this into the same class with nullable fields, no
     * discriminated union to route.
     */
    public function testBankTransferVariantWithNoCallMethodMatches(): void
    {
        $json = [
            'payment_type' => 'bank_transfer',
            'account_id' => 'acct-1',
            'branch_code' => '001',
            'branch_name' => 'Main Branch',
            'account_holder_name' => 'Taro Yamada',
            'account_number' => '1234567',
        ];

        $this->assertTypedMatchesRaw(OnlineToken::class, GeneratedIssuerToken::class, $json);
    }

    // --- ThreeDSIssuerToken / ThreeDsIssuerToken -------------------------------------------------

    public function testCardThreeDSIssuerTokenMatches(): void
    {
        $json = [
            'call_method' => 'http_post',
            'content_type' => 'application/x-www-form-urlencoded',
            'issuer_token' => 'issuer-token-1',
            'payload' => (object) ['request_data' => 'PAReq=abc'],
            'payment_type' => 'card',
        ];

        $this->assertTypedMatchesRaw(ThreeDSIssuerToken::class, GeneratedThreeDsIssuerToken::class, $json);
    }

    public function testCardThreeDSIssuerTokenWithNullPayloadMatches(): void
    {
        $json = [
            'call_method' => 'http_get',
            'content_type' => 'text/plain',
            'issuer_token' => 'issuer-token-2',
            'payload' => null,
            'payment_type' => 'card',
        ];

        $this->assertTypedMatchesRaw(ThreeDSIssuerToken::class, GeneratedThreeDsIssuerToken::class, $json);
    }

    /**
     * A genuinely missing required field (`call_method`): the generated model's getter is
     * non-nullable (`: string`), so calling it throws a `TypeError` -- caught by
     * `TypedHydrator::resolve()`'s catch-all, same net result as `Charge`/`Refund`/`Cancel`'s
     * explicit null-guards, just via PHP's own return-type enforcement instead.
     */
    public function testMissingRequiredCallMethodFallsBackToRaw(): void
    {
        \Univapay\Compat\Support\FallbackRegistry::reset();
        $json = [
            'content_type' => 'application/x-www-form-urlencoded',
            'issuer_token' => 'issuer-token-1',
            'payload' => null,
            'payment_type' => 'card',
        ];
        $context = $this->differentialContext();

        $wireJson = (string) json_encode($json);
        $rawDecoded = json_decode($wireJson, true);

        $typedModel = \UnivaPay\ApiHelper::getJsonHelper()->mapClass(
            json_decode($wireJson),
            GeneratedThreeDsIssuerToken::class
        );

        $threw = null;
        try {
            \Univapay\Compat\Support\TypedHydrator::resolve(
                ThreeDSIssuerToken::class,
                new \Univapay\Compat\Support\TypedResult($rawDecoded, $typedModel, false),
                $context
            );
        } catch (\Univapay\Compat\Utility\Json\NoSuchPathException $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw, 'Expected the raw fallback to throw, same as the raw path.');
        $this->assertNotEmpty(\Univapay\Compat\Support\FallbackRegistry::occurrences());
        $this->assertSame(
            \Univapay\Compat\Support\FallbackRegistry::REASON_HYDRATION_EXCEPTION,
            \Univapay\Compat\Support\FallbackRegistry::occurrences()[0]['reason']
        );
    }
}
