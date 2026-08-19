<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Differential;

use PHPUnit\Framework\TestCase;
use UnivaPay\Models\Subscription as GeneratedSubscription;
use Univapay\Compat\Resources\Subscription;
use Univapay\Compat\Support\FallbackRegistry;
use Univapay\Compat\Tests\Support\DifferentialHydration;

/**
 * Differential hydration harness (see tests/Support/DifferentialHydration.php) for `Subscription`,
 * flipped to typed-primary once `univapay/client-sdk` 1.2.0 closed the spec gap (see
 * docs/ARCHITECTURE.md). Base fixture reused from `tests/Unit/Resources/SubscriptionTest.php`.
 */
class SubscriptionDifferentialTest extends TestCase
{
    use DifferentialHydration;

    private function subscriptionJson(array $overrides = []): array
    {
        $json = [
            'id' => '11111111-1111-1111-1111-111111111111',
            'store_id' => '22222222-2222-2222-2222-222222222222',
            'transaction_token_id' => '33333333-3333-3333-3333-333333333333',
            'amount' => 1000,
            'currency' => 'JPY',
            'amount_formatted' => 1000,
            'period' => 'monthly',
            'initial_amount' => 100,
            'initial_amount_formatted' => 100,
            'schedule_settings' => [
                'start_on' => '2017-07-31',
                'zone_id' => 'Asia/Tokyo',
                'preserve_end_of_month' => true,
                'retry_interval' => 'P5D',
            ],
            'next_payment' => [
                'id' => '11e893e1-2842-3cea-b0a8-47819043c1eb',
                'due_date' => '2018-08-30',
                'zone_id' => 'Asia/Tokyo',
                'amount' => 1000,
                'currency' => 'JPY',
                'amount_formatted' => 1000,
                'is_paid' => false,
                'is_last_payment' => false,
                'created_on' => '2018-07-31T10:13:08.715295Z',
            ],
            'first_charge_authorization_only' => true,
            'first_charge_capture_after' => 'PT5H',
            'status' => 'current',
            'subscription_plan' => [
                'plan_type' => 'fixed_cycle_amount',
                'fixed_cycle_amount' => 1000,
            ],
            'amount_left' => 5000,
            'amount_left_formatted' => 5000,
            'metadata' => (object) [],
            'mode' => 'test',
            'created_on' => '2017-07-04T06:06:05.580391Z',
        ];
        return array_replace($json, $overrides);
    }

    public function testFullSubscriptionWithPeriodMatches(): void
    {
        $this->assertTypedMatchesRaw(Subscription::class, GeneratedSubscription::class, $this->subscriptionJson());
    }

    public function testCyclicalPeriodVariantMatches(): void
    {
        $json = $this->subscriptionJson(['cyclical_period' => 'P15D']);
        unset($json['period']);
        $this->assertTypedMatchesRaw(Subscription::class, GeneratedSubscription::class, $json);
    }

    /**
     * `installment_plan`'s amount key is `fixed_cycles_amount` (plural) on the generated model --
     * compat's `InstallmentPlan` has no property to receive it at all (only `planType`/
     * `fixedCycles`), so it must be silently ignored on BOTH paths, same as any other extra
     * unused getter.
     */
    public function testInstallmentPlanWithFixedCyclesAmountMatches(): void
    {
        $json = $this->subscriptionJson([
            'subscription_plan' => null,
            'installment_plan' => [
                'plan_type' => 'fixed_cycles',
                'fixed_cycles' => 6,
                'fixed_cycles_amount' => 3000,
            ],
        ]);
        $this->assertTypedMatchesRaw(Subscription::class, GeneratedSubscription::class, $json);

        $context = $this->differentialContext();
        $rawObject = Subscription::getSchema()->parse($json, [$context]);
        $this->assertSame(6, $rawObject->installmentPlan->fixedCycles);
    }

    public function testAmountLeftAbsentMatches(): void
    {
        $json = $this->subscriptionJson();
        unset($json['amount_left'], $json['amount_left_formatted']);
        $this->assertTypedMatchesRaw(Subscription::class, GeneratedSubscription::class, $json);
    }

    /**
     * `three_ds`'s typed source (`SubscriptionThreeDs`) has no MPI fields, unlike `redirect_id`
     * which IS present here (unlike Charge's equivalent gap) -- threeDSMPI is hardcoded null since
     * MPI data is request-only and never appears in a response.
     */
    public function testThreeDsPresentMatches(): void
    {
        $json = $this->subscriptionJson([
            'three_ds' => [
                'mode' => 'normal',
                'redirect_endpoint' => 'https://ec-site.example.com/3ds/complete',
                'redirect_id' => '11efbdb4-6820-12dc-8246-6f01ed1243a9',
            ],
        ]);
        $this->assertTypedMatchesRaw(Subscription::class, GeneratedSubscription::class, $json);

        $context = $this->differentialContext();
        $rawObject = Subscription::getSchema()->parse($json, [$context]);
        $this->assertSame('11efbdb4-6820-12dc-8246-6f01ed1243a9', $rawObject->threeDS->redirectId);
        $this->assertNull($rawObject->threeDS->threeDSMPI);
    }

    public function testNoNextPaymentNoPlansMatches(): void
    {
        $json = $this->subscriptionJson([
            'next_payment' => null,
            'subscription_plan' => null,
            'initial_amount' => null,
            'initial_amount_formatted' => null,
        ]);
        $this->assertTypedMatchesRaw(Subscription::class, GeneratedSubscription::class, $json);
    }

    /**
     * `payments_left` is read from the raw body on both paths (this class's own auto-derived
     * schema has always read that key, not the generated model's differently-named `cycles_left`
     * field), so a fixture that genuinely carries `payments_left` still flows through correctly --
     * patching from raw is not the same as always-null, just independent of the typed model.
     */
    public function testPaymentsLeftPresentFlowsThroughOnBothPaths(): void
    {
        $json = $this->subscriptionJson(['payments_left' => 9, 'cycles_left' => 3]);
        $this->assertTypedMatchesRaw(Subscription::class, GeneratedSubscription::class, $json);

        $context = $this->differentialContext();
        $rawObject = Subscription::getSchema()->parse($json, [$context]);
        $this->assertSame(9, $rawObject->paymentsLeft);
    }

    /**
     * Pre-existing, unrelated field-name mismatch (not fixed by 1.2.0): if the wire only carries
     * the generated model's `cycles_left` (never `payments_left`), this class's own schema finds
     * nothing at its own key and `paymentsLeft` resolves to null on both paths -- typed hydration
     * does not backfill it from `cycles_left`, since compat has no property to represent that
     * field distinctly from `paymentsLeft` in the first place.
     */
    public function testPaymentsLeftIsNullWhenOnlyCyclesLeftIsPresent(): void
    {
        $json = $this->subscriptionJson(['cycles_left' => 3]);
        $this->assertTypedMatchesRaw(Subscription::class, GeneratedSubscription::class, $json);

        $context = $this->differentialContext();
        $rawObject = Subscription::getSchema()->parse($json, [$context]);
        $this->assertNull($rawObject->paymentsLeft);
    }

    // --- fallback regression: a required field genuinely missing --------------------------------

    public function testMissingRequiredScheduleSettingsDeclinesAndFallsBackToTheSameRawException(): void
    {
        FallbackRegistry::reset();
        $json = $this->subscriptionJson();
        unset($json['schedule_settings']);
        $context = $this->differentialContext();

        $wireJson = (string) json_encode($json);
        $rawDecoded = json_decode($wireJson, true);
        $typed = \UnivaPay\ApiHelper::getJsonHelper()->mapClass(json_decode($wireJson), GeneratedSubscription::class);
        $result = new \Univapay\Compat\Support\TypedResult($rawDecoded, $typed, false);

        $threw = null;
        try {
            \Univapay\Compat\Support\TypedHydrator::resolve(Subscription::class, $result, $context);
        } catch (\Univapay\Compat\Utility\Json\NoSuchPathException $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw);
        $this->assertSame(FallbackRegistry::REASON_HYDRATION_DECLINED, FallbackRegistry::occurrences()[0]['reason']);
    }

    public function testMissingRequiredZoneIdInScheduleSettingsDeclinesAndFallsBackToTheSameRawException(): void
    {
        FallbackRegistry::reset();
        $json = $this->subscriptionJson();
        unset($json['schedule_settings']['zone_id']);
        $context = $this->differentialContext();

        $wireJson = (string) json_encode($json);
        $rawDecoded = json_decode($wireJson, true);
        $typed = \UnivaPay\ApiHelper::getJsonHelper()->mapClass(json_decode($wireJson), GeneratedSubscription::class);
        $result = new \Univapay\Compat\Support\TypedResult($rawDecoded, $typed, false);

        $threw = null;
        try {
            \Univapay\Compat\Support\TypedHydrator::resolve(Subscription::class, $result, $context);
        } catch (\Univapay\Compat\Utility\Json\NoSuchPathException $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw);
        $this->assertSame(FallbackRegistry::REASON_HYDRATION_DECLINED, FallbackRegistry::occurrences()[0]['reason']);
    }
}
