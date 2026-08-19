<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Differential;

use PHPUnit\Framework\TestCase;
use UnivaPay\Models\Refund as GeneratedRefund;
use Univapay\Compat\Resources\Refund;
use Univapay\Compat\Support\FallbackRegistry;
use Univapay\Compat\Tests\Support\DifferentialHydration;

/**
 * Differential hydration harness (see tests/Support/DifferentialHydration.php) for `Refund`.
 */
class RefundDifferentialTest extends TestCase
{
    use DifferentialHydration;

    private function minimalRefundJson(): array
    {
        return [
            'id' => 'refund-1',
            'store_id' => 'store-1',
            'charge_id' => 'charge-1',
            'status' => 'pending',
            'currency' => 'JPY',
            'amount' => 100,
            'amount_formatted' => 100,
            'reason' => null,
            'message' => null,
            'error' => null,
            'metadata' => (object) [],
            'mode' => 'test',
            'created_on' => '2022-07-26T10:33:12.934225Z',
        ];
    }

    public function testMinimalPendingRefundMatches(): void
    {
        $this->assertTypedMatchesRaw(Refund::class, GeneratedRefund::class, $this->minimalRefundJson());
    }

    public function testSuccessfulRefundWithReasonAndMetadataMatches(): void
    {
        $json = $this->minimalRefundJson();
        $json['status'] = 'successful';
        $json['reason'] = 'customer_request';
        $json['message'] = 'requested by customer';
        $json['metadata'] = ['order_id' => '12345'];

        $this->assertTypedMatchesRaw(Refund::class, GeneratedRefund::class, $json);
    }

    public function testFailedRefundWithErrorMatches(): void
    {
        $json = $this->minimalRefundJson();
        $json['status'] = 'failed';
        $json['error'] = ['code' => 401, 'message' => 'refund rejected'];

        $this->assertTypedMatchesRaw(Refund::class, GeneratedRefund::class, $json);
    }

    /**
     * A genuinely missing required field (`currency`): `hydrateFromTyped()` declines, and the raw
     * fallback throws `NoSuchPathException` exactly as the raw path always has.
     */
    public function testMissingRequiredCurrencyDeclinesAndFallsBackToTheSameRawException(): void
    {
        FallbackRegistry::reset();
        $json = $this->minimalRefundJson();
        unset($json['currency']);
        $context = $this->differentialContext();

        $wireJson = (string) json_encode($json);
        $rawDecoded = json_decode($wireJson, true);
        $typed = \UnivaPay\ApiHelper::getJsonHelper()->mapClass(json_decode($wireJson), GeneratedRefund::class);
        $result = new \Univapay\Compat\Support\TypedResult($rawDecoded, $typed, false);

        $threw = null;
        try {
            \Univapay\Compat\Support\TypedHydrator::resolve(Refund::class, $result, $context);
        } catch (\Univapay\Compat\Utility\Json\NoSuchPathException $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw);
        $this->assertSame(
            FallbackRegistry::REASON_HYDRATION_DECLINED,
            FallbackRegistry::occurrences()[0]['reason']
        );
    }
}
