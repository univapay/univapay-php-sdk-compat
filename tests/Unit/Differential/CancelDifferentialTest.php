<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Differential;

use PHPUnit\Framework\TestCase;
use UnivaPay\Models\Cancel as GeneratedCancel;
use Univapay\Compat\Resources\Cancel;
use Univapay\Compat\Support\FallbackRegistry;
use Univapay\Compat\Tests\Support\DifferentialHydration;

/**
 * Differential hydration harness (see tests/Support/DifferentialHydration.php) for `Cancel`.
 */
class CancelDifferentialTest extends TestCase
{
    use DifferentialHydration;

    private function minimalCancelJson(): array
    {
        return [
            'id' => 'cancel-1',
            'charge_id' => 'charge-1',
            'store_id' => 'store-1',
            'status' => 'pending',
            'error' => null,
            'metadata' => (object) [],
            'mode' => 'test',
            'created_on' => '2022-07-26T10:33:12.934225Z',
        ];
    }

    public function testMinimalPendingCancelMatches(): void
    {
        $this->assertTypedMatchesRaw(Cancel::class, GeneratedCancel::class, $this->minimalCancelJson());
    }

    public function testSuccessfulCancelWithMetadataMatches(): void
    {
        $json = $this->minimalCancelJson();
        $json['status'] = 'successful';
        $json['metadata'] = ['something' => 'anything'];

        $this->assertTypedMatchesRaw(Cancel::class, GeneratedCancel::class, $json);
    }

    public function testFailedCancelWithErrorMatches(): void
    {
        $json = $this->minimalCancelJson();
        $json['status'] = 'failed';
        $json['error'] = ['code' => 500, 'message' => 'cancel rejected'];

        $this->assertTypedMatchesRaw(Cancel::class, GeneratedCancel::class, $json);
    }

    /**
     * A genuinely missing required field (`mode`): `hydrateFromTyped()` declines, and the raw
     * fallback throws `NoSuchPathException` exactly as the raw path always has.
     */
    public function testMissingRequiredModeDeclinesAndFallsBackToTheSameRawException(): void
    {
        FallbackRegistry::reset();
        $json = $this->minimalCancelJson();
        unset($json['mode']);
        $context = $this->differentialContext();

        $wireJson = (string) json_encode($json);
        $rawDecoded = json_decode($wireJson, true);
        $typed = \UnivaPay\ApiHelper::getJsonHelper()->mapClass(json_decode($wireJson), GeneratedCancel::class);
        $result = new \Univapay\Compat\Support\TypedResult($rawDecoded, $typed, false);

        $threw = null;
        try {
            \Univapay\Compat\Support\TypedHydrator::resolve(Cancel::class, $result, $context);
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
