<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Differential;

use PHPUnit\Framework\TestCase;
use UnivaPay\Models\Charge as GeneratedCharge;
use Univapay\Compat\Support\FallbackRegistry;
use Univapay\Compat\Tests\Support\DifferentialHydration;

/**
 * Differential hydration harness (see tests/Support/DifferentialHydration.php) for `Charge`, the
 * "hard gate" docs/ARCHITECTURE.md's confinement-allowlist update and this brief both require
 * before a resource may flip to typed-primary: every fixture below is hydrated BOTH through the
 * raw `JsonSchema` path and through `Charge::hydrateFromTyped()` fed a REAL jsonmapper-deserialized
 * `UnivaPay\Models\Charge`, and asserted equal.
 */
class ChargeDifferentialTest extends TestCase
{
    use DifferentialHydration;

    private function minimalChargeJson(): array
    {
        return [
            'id' => '11ef0000-0000-4000-8000-000000000001',
            'store_id' => 'store-1',
            'transaction_token_id' => '11ef0000-0000-4000-8000-000000000002',
            'transaction_token_type' => 'one_time',
            'subscription_id' => null,
            'requested_amount' => 1000,
            'requested_currency' => 'JPY',
            'requested_amount_formatted' => 1000,
            'charged_amount' => null,
            'charged_currency' => null,
            'charged_amount_formatted' => null,
            'only_direct_currency' => false,
            'capture_at' => null,
            'status' => 'pending',
            'error' => null,
            'metadata' => (object) [],
            'mode' => 'test',
            'created_on' => '2024-06-25T07:12:15.164520Z',
            'redirect' => null,
            'three_ds' => null,
        ];
    }

    private function fullChargeJson(): array
    {
        return [
            'id' => '11ed0cce-59e5-795a-b95c-fb1234567890',
            'store_id' => '11e99edf-6075-c71c-b6d5-ef1237890',
            'transaction_token_id' => '11ed0cce-589a-5584-b959-631234567890',
            'transaction_token_type' => 'recurring',
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
                'message' => 'The card number is not valid',
            ],
            'metadata' => ['order_id' => '12345'],
            'mode' => 'live',
            'redirect' => [
                'endpoint' => 'https://test.int/endpoint?foo=bar',
                'redirect_id' => '11ed0cce-59e5-795a-b95c-rd1234567890',
            ],
            'three_ds' => [
                'redirect_endpoint' => 'https://ec-site.example.com/3ds/complete',
                'mode' => 'normal',
            ],
            'created_on' => '2022-07-26T10:33:12.934225Z',
        ];
    }

    public function testMinimalPendingChargeMatches(): void
    {
        $this->assertTypedMatchesRaw(
            \Univapay\Compat\Resources\Charge::class,
            GeneratedCharge::class,
            $this->minimalChargeJson()
        );
    }

    public function testFullChargeWithErrorRedirectAndThreeDSMatches(): void
    {
        $this->assertTypedMatchesRaw(
            \Univapay\Compat\Resources\Charge::class,
            GeneratedCharge::class,
            $this->fullChargeJson()
        );
    }

    public function testSubscriptionTokenTypeMatches(): void
    {
        $json = $this->minimalChargeJson();
        $json['transaction_token_type'] = 'subscription';
        $json['status'] = 'successful';
        $json['charged_amount'] = 1000;
        $json['charged_currency'] = 'JPY';
        $json['charged_amount_formatted'] = 1000;

        $this->assertTypedMatchesRaw(\Univapay\Compat\Resources\Charge::class, GeneratedCharge::class, $json);
    }

    /**
     * `three_ds` present but three_ds.mode absent (schema-optional): the generated `ChargeThreeDs`
     * model doesn't need mode either -- proves the raw-body patch for this field doesn't assume
     * mode's presence.
     */
    public function testThreeDSWithoutModeMatches(): void
    {
        $json = $this->minimalChargeJson();
        $json['three_ds'] = ['redirect_endpoint' => 'https://ec-site.example.com/3ds/complete'];

        $this->assertTypedMatchesRaw(\Univapay\Compat\Resources\Charge::class, GeneratedCharge::class, $json);
    }

    /**
     * Genuine spec gap regression: the generated `ChargeThreeDs` response model has no MPI fields
     * at all. This fixture proves that even though the typed model can't carry them, hydrateFromTyped()
     * still recovers redirect_id (also absent from the typed model) via its raw-body patch --
     * nothing the raw path reads is silently dropped by the typed path.
     */
    public function testThreeDSRedirectIdSurvivesDespiteBeingAbsentFromTheTypedModel(): void
    {
        $json = $this->minimalChargeJson();
        $json['three_ds'] = [
            'redirect_endpoint' => 'https://ec-site.example.com/3ds/complete',
            'redirect_id' => '11efbdb4-6820-12dc-8246-6f01ed1243a9',
            'mode' => 'normal',
        ];

        $this->assertTypedMatchesRaw(\Univapay\Compat\Resources\Charge::class, GeneratedCharge::class, $json);

        // Belt-and-suspenders: prove redirect_id genuinely made it onto the typed-hydrated object,
        // not just that both sides happened to agree it was null.
        $wireJson = (string) json_encode($json);
        $rawDecoded = json_decode($wireJson, true);
        $typedModel = \UnivaPay\ApiHelper::getJsonHelper()->mapClass(json_decode($wireJson), GeneratedCharge::class);
        $rawObject = \Univapay\Compat\Resources\Charge::hydrateFromTyped($typedModel, $rawDecoded, null);
        $this->assertSame('11efbdb4-6820-12dc-8246-6f01ed1243a9', $rawObject->threeDS->redirectId);
    }

    // --- fallback regression: a required field genuinely missing --------------------------------

    /**
     * A genuinely missing required field: `hydrateFromTyped()` declines (its own null-guard), so
     * `TypedHydrator` falls back to `getSchema()->parse()` -- which, for a truly missing required
     * field, throws `NoSuchPathException` exactly as it always has. Proves the fallback is faithful
     * even in the "both paths ultimately fail the same way" case, not just the happy path.
     */
    public function testMissingRequiredStatusDeclinesAndFallsBackToTheSameRawException(): void
    {
        FallbackRegistry::reset();
        $json = $this->minimalChargeJson();
        unset($json['status']);
        $context = $this->differentialContext();

        $wireJson = (string) json_encode($json);
        $rawDecoded = json_decode($wireJson, true);
        $typed = \UnivaPay\ApiHelper::getJsonHelper()->mapClass(json_decode($wireJson), GeneratedCharge::class);
        $result = new \Univapay\Compat\Support\TypedResult($rawDecoded, $typed, false);

        $threw = null;
        try {
            $chargeClass = \Univapay\Compat\Resources\Charge::class;
            \Univapay\Compat\Support\TypedHydrator::resolve($chargeClass, $result, $context);
        } catch (\Univapay\Compat\Utility\Json\NoSuchPathException $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw, 'Expected the raw fallback to throw NoSuchPathException, same as the raw path.');
        $this->assertNotEmpty(FallbackRegistry::occurrences());
        $this->assertSame(
            FallbackRegistry::REASON_HYDRATION_DECLINED,
            FallbackRegistry::occurrences()[0]['reason']
        );

        // Confirm the raw path alone throws the identical exception -- i.e. this isn't a
        // typed-path-only quirk, the fallback is landing on genuinely equivalent behavior.
        $this->expectException(\Univapay\Compat\Utility\Json\NoSuchPathException::class);
        \Univapay\Compat\Resources\Charge::getSchema()->parse($rawDecoded, [$context]);
    }
}
