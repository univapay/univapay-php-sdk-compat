<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Hostile;

use Univapay\Compat\Resources\Charge;

/**
 * "Missing optional objects" (plan task metadata): every documented example this repo's own
 * fixtures use sets optional fields to an explicit `null`, but a real (or hand-rolled hostile)
 * response body may OMIT the key entirely instead -- a meaningfully different JSON shape
 * (`array_key_exists() === false` vs `=== true && value === null`) that a naively-written parser
 * could handle differently. Confirms `Utility\Json\JsonSchema`'s optional (`upsert(..., false,
 * ...)`) properties tolerate a genuinely ABSENT key, not just an explicit null one, across every
 * optional field on `Charge` at once.
 */
class MissingOptionalObjectsTest extends HostileTestCase
{
    public function testChargeHydratesWithEveryOptionalKeyEntirelyAbsent(): void
    {
        // Only the REQUIRED Charge properties (per Charge::initSchema()'s upsert(true, ...) /
        // constructor non-nullable params): id, store_id, transaction_token_id,
        // transaction_token_type, requested_currency, requested_amount, status, mode, created_on.
        // charged_currency/charged_amount/capture_at/redirect/three_ds/error/metadata/
        // subscription_id/only_direct_currency are all optional and OMITTED here entirely (not
        // present as keys at all), unlike every fixture elsewhere in this suite which sets them
        // to explicit null.
        $body = [
            'id' => self::CHARGE_ID,
            'store_id' => self::STORE_ID,
            'transaction_token_id' => '11ef32a7-3a71-8662-803f-1bc27702eeec',
            'transaction_token_type' => 'recurring',
            'requested_amount' => 1000,
            'requested_currency' => 'JPY',
            'status' => 'pending',
            'mode' => 'test',
            'created_on' => '2024-06-25T07:12:15.16452Z',
        ];

        $this->server()->queueResponse(200, json_encode($body));

        $charge = $this->storeClient()->getCharge(self::STORE_ID, self::CHARGE_ID);

        $this->assertInstanceOf(Charge::class, $charge);
        $this->assertNull($charge->subscriptionId);
        $this->assertNull($charge->chargedCurrency);
        $this->assertNull($charge->chargedAmount);
        $this->assertNull($charge->captureAt);
        $this->assertNull($charge->error);
        $this->assertNull($charge->metadata);
        $this->assertNull($charge->redirect);
        $this->assertNull($charge->threeDS);
        $this->assertNull($charge->onlyDirectCurrency);
    }
}
