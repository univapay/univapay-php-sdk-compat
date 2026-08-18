<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Hostile;

use Univapay\Compat\Errors\UnivapayRequestError;

/**
 * Malformed error bodies (fromJson without isset paths): a 400 response
 * body missing the `errors`/`code` keys the old wire shape normally guarantees.
 * `Errors\UnivapayRequestError::fromJson()` (and its 401/403/409 subclasses) read
 * `$json['status']`/`['code']`/`['errors']` -- verified byte-for-byte identical to the OLD SDK's
 * own `fromJson()`, i.e. this is PARITY, not a new compat bug. These four constructors are
 * hardened with `??` fallbacks anyway (see their own doc comments): value-neutral (a missing key
 * produced `null` before too), the only change is that a strict error-to-exception handler
 * (PHPUnit's own default among them) no longer turns the resulting PHP engine warning into a
 * spurious test failure.
 *
 * This test asserts the CURRENT (hardened) behavior: a malformed 400 body still maps to a real
 * `UnivapayRequestError` with the present fields populated and the missing ones `null`, with no
 * warning/notice escaping.
 */
class MalformedErrorBodyTest extends HostileTestCase
{
    public function test400BodyMissingErrorsAndCodeKeysStillMapsToARequestErrorWithNullFields(): void
    {
        // Deliberately missing 'code' and 'errors' -- only 'status' present, unlike every
        // documented ErrorResponse example in the spec (which always includes all three).
        $malformedBody = json_encode(['status' => 400]);

        $this->server()->queueResponse(400, $malformedBody);

        try {
            $this->storeClient()->getCharge(self::STORE_ID, 'missing-charge-id');
            $this->fail('Expected a UnivapayRequestError to be thrown');
        } catch (UnivapayRequestError $e) {
            $this->assertSame(400, $e->status);
            $this->assertNull($e->code);
            $this->assertNull($e->errors);
        }
    }

    public function testCompletelyEmptyErrorBodyStillMapsCleanly(): void
    {
        $this->server()->queueResponse(400, '{}');

        try {
            $this->storeClient()->getCharge(self::STORE_ID, 'missing-charge-id');
            $this->fail('Expected a UnivapayRequestError to be thrown');
        } catch (UnivapayRequestError $e) {
            $this->assertNull($e->status);
            $this->assertNull($e->code);
            $this->assertNull($e->errors);
        }
    }
}
