<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Hostile;

use Money\Money;
use Univapay\Compat\Resources\Charge;

/**
 * Exercises `Support\ApiCaller`'s JsonMapperException-bypass path (see docs/ARCHITECTURE.md) end
 * to end, through a REAL HTTP response the generated SDK's own strict jsonmapper cannot
 * deserialize -- proving the raw-body fallback (captured by the `HttpCallBack`
 * BEFORE the strict mapper runs, then hydrated through the SAME ported `JsonSchema` parser every
 * clean response goes through) actually recovers, not just that `tests/Unit/Support/
 * ApiCallerTest.php`'s synthetic closures believe it would.
 *
 * Two deliberately spec-invalid 2xx bodies, each chosen because the generated `UnivaPay\Models\
 * Charge` model WILL choke on it while the ported (old-SDK) `Charge::initSchema()`/`FormatterUtils`
 * path tolerates it by construction:
 *
 * 1. **Nested metadata object.** The spec's own `GenericMetadataValue` schema explicitly documents
 *    "not a nested object; the server rejects metadata whose direct property values are JSON
 *    objects" (verified against src/spec/openapi.yaml) -- i.e. this is a shape the SERVER itself
 *    would never send today, but it's exactly the "old parser accepts, new spec doesn't describe"
 *    category (nested legacy metadata was real before `GenericMetadataValue`'s `anyOf` widened to
 *    scalars/arrays; a stray nested object is still outside that widened union). The generated
 *    model's metadata dictionary only accepts string/number/boolean/null/array-of-those per
 *    property; a bare object fails every anyOf branch. Compat's own metadata handling has no type
 *    validation at all (plain array pass-through) -- old-SDK parity, not a new relaxation.
 * 2. **`requested_amount` as a JSON string.** The OLD SDK's wire emitted Money amounts as
 *    STRINGS; the new spec's `requested_amount` is a strict integer, which the generated model
 *    maps as a native PHP `int` -- if the generated mapper enforces that strictly (plausible but
 *    not independently re-verified here against apimatic/jsonmapper's own source), this is a
 *    second, independent trigger for the same bypass. Regardless of whether the strict mapper
 *    actually throws on this specific delta, `Support\ApiCaller::call()` ALWAYS answers from the
 *    raw captured body rather than the generated client's typed result either way (see its own
 *    class doc) -- so this test's real assertion is the architectural guarantee itself:
 *    `Utility\FormatterUtils::getMoney()` builds `new Money($value, ...)`, and `moneyphp\Money`'s
 *    constructor (verified: vendor/moneyphp/money/src/Money.php) accepts a numeric STRING exactly
 *    as readily as an int, so the old wire format's own quirk round-trips correctly through the
 *    ported path either way.
 */
class JsonMapperBypassTest extends HostileTestCase
{
    private function baseChargeBody(): array
    {
        return [
            'id' => self::CHARGE_ID,
            'store_id' => self::STORE_ID,
            'transaction_token_id' => '11ef32a7-3a71-8662-803f-1bc27702eeec',
            'transaction_token_type' => 'recurring',
            'subscription_id' => null,
            'requested_amount' => 1000,
            'requested_currency' => 'JPY',
            'requested_amount_formatted' => 1000,
            'charged_amount' => null,
            'charged_currency' => null,
            'only_direct_currency' => false,
            'capture_at' => null,
            'status' => 'pending',
            'error' => null,
            'metadata' => ['order_id' => '12345'],
            'mode' => 'test',
            'created_on' => '2024-06-25T07:12:15.16452Z',
            'redirect' => null,
        ];
    }

    public function testNestedLegacyMetadataObjectBypassesToTheRawParser(): void
    {
        $body = $this->baseChargeBody();
        // A JSON OBJECT as a direct metadata property value -- the spec's GenericMetadataValue
        // anyOf has no object branch, so the generated Charge model's strict mapper throws
        // JsonMapperException on this field.
        $body['metadata'] = [
            'order_id' => '12345',
            'legacy_info' => ['nested' => true, 'source' => 'old-integration'],
        ];

        $this->server()->queueResponse(200, json_encode($body));

        $charge = $this->storeClient()->getCharge(self::STORE_ID, self::CHARGE_ID);

        $this->assertInstanceOf(Charge::class, $charge);
        $this->assertSame(
            ['order_id' => '12345', 'legacy_info' => ['nested' => true, 'source' => 'old-integration']],
            $charge->metadata
        );
    }

    public function testRequestedAmountAsAJsonStringBypassesToTheRawParser(): void
    {
        $body = $this->baseChargeBody();
        // Old-wire-format quirk (plan "wire-parity oracle" note): a JSON STRING where the spec
        // declares an integer.
        $body['requested_amount'] = '1000';

        $this->server()->queueResponse(200, json_encode($body));

        $charge = $this->storeClient()->getCharge(self::STORE_ID, self::CHARGE_ID);

        $this->assertInstanceOf(Charge::class, $charge);
        $this->assertInstanceOf(Money::class, $charge->requestedAmount);
        $this->assertSame('1000', $charge->requestedAmount->getAmount());
    }
}
