<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Hostile;

use OutOfRangeException;

/**
 * "Unknown enum values in non-enum-typed fields" (plan task metadata): a field the NEW spec
 * documents as a free/open string -- because the real-world value set is large and growing (card
 * brands, in this case) -- but which the ported OLD parser hydrates through a FIXED, old-SDK-era
 * `Enums\TypedEnum` case list via `FormatterUtils::getTypedEnum()`/`TypedEnum::fromValue()`.
 *
 * `card.brand` is exactly this: the spec does not constrain it to an OpenAPI `enum:` (any string
 * a card network reports is valid), but `Resources\PaymentData\Card`'s constructor routes it
 * through `Enums\CardBrand::fromValue()`, a fixed ~10-case list. A brand outside that list (here,
 * `elo` -- a real, common Brazilian card brand genuinely absent from the old SDK's case list, not
 * a fabricated string) throws `OutOfRangeException`.
 *
 * This is PARITY, not a compat-introduced bug: `Enums\TypedEnum::fromGetter()`'s
 * `throw new OutOfRangeException(...)` is a verbatim port, and the old SDK's own `CardBrand` had
 * the exact same fixed case list -- an integrator on the OLD SDK hitting a card brand outside its
 * list got the identical exception. Documented here as expected, reproducible behavior.
 */
class UnknownEnumValueTest extends HostileTestCase
{
    private function baseTokenBody(): array
    {
        return [
            'id' => '11f11e85-e9e9-b198-b990-c3a715943241',
            'store_id' => self::STORE_ID,
            'email' => 'test@test.com',
            'payment_type' => 'card',
            'active' => true,
            'mode' => 'live',
            'type' => 'recurring',
            'usage_limit' => null,
            'confirmed' => null,
            'metadata' => ['order_id' => '12345'],
            'created_on' => '2026-03-13T02:39:52.908468Z',
            'last_used_on' => null,
            'data' => [
                'card' => [
                    'cardholder' => 'TEST TEST',
                    'exp_month' => 9,
                    'exp_year' => 2026,
                    'last_four' => '424242',
                    'brand' => 'visa',
                    'card_type' => 'credit',
                    'country' => 'JP',
                    'category' => 'standard',
                    'issuer' => 'issuer',
                    'sub_brand' => 'none',
                ],
                'billing' => null,
                'cvv_authorize' => [
                    'enabled' => false,
                    'status' => null,
                    'charge_id' => null,
                    'credentials_id' => null,
                    'currency' => null,
                ],
                'three_ds' => null,
            ],
        ];
    }

    public function testUnrecognizedCardBrandThrowsOutOfRangeException(): void
    {
        $body = $this->baseTokenBody();
        $body['data']['card']['brand'] = 'elo';

        $this->server()->queueResponse(200, json_encode($body));

        $this->expectException(OutOfRangeException::class);
        $this->expectExceptionMessageMatches('/elo/');

        $this->storeClient()->getTransactionToken('11f11e85-e9e9-b198-b990-c3a715943241');
    }

    /** Sanity check: the SAME body, unmutated, hydrates cleanly (proves the mutation is the cause). */
    public function testRecognizedCardBrandHydratesCleanly(): void
    {
        $body = $this->baseTokenBody();

        $this->server()->queueResponse(200, json_encode($body));

        $token = $this->storeClient()->getTransactionToken('11f11e85-e9e9-b198-b990-c3a715943241');

        $this->assertSame(\Univapay\Compat\Enums\CardBrand::VISA(), $token->data->card->brand);
    }
}
