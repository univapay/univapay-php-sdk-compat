<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Tests\Support\FakeJwtBuilder;
use Univapay\Compat\UnivapayClient;
use Univapay\Compat\UnivapayClientOptions;

/**
 * @group integration
 *
 * Shared base for the Prism-backed integration suite. Every test in tests/Integration/
 * SKIPS (does not fail) unless the UNIVAPAY_PRISM_URL environment variable is set -- exactly the
 * pattern tests/RoundTrip/ExampleRoundTripTest.php already established for its own external
 * dependency (the extracted spec JSON). Start Prism from a checkout of the docs repo before
 * running this suite:
 *
 *   cd /path/to/univapay_docs && npm ci && npm run mock
 *   UNIVAPAY_PRISM_URL=http://127.0.0.1:4010 vendor/bin/phpunit tests/Integration
 *
 * ## Fixture IDs
 *
 * The path-param IDs below are copied verbatim from the docs repo's OWN contract-test fixture
 * map (tests/helpers.js's `IDS`, not tests/contract/helpers.js -- the file has since moved) --
 * they are the exact UUIDs/ids the spec's own operation examples were authored
 * against. Reusing them is not required for Prism to match a path template (Prism's static mock
 * only pattern-matches the URL template, it does not inspect path-param VALUES against the
 * response body), but several path params are declared `format: uuid` in the spec and Prism DOES
 * validate parameter format against the declared schema -- reusing real, spec-shaped ids avoids
 * a 422 from Prism's own request validation and keeps failures in this suite meaningful (a real
 * hydration/behavior problem, not a self-inflicted malformed fixture id).
 *
 * ## Auth
 *
 * `AppJWT::createToken()` never verifies its input's signature (matching the old SDK exactly --
 * see AppJWT's own class doc), so a hand-built, unsigned three-segment JWT-shaped string is
 * sufficient here, same technique tests/Unit/Resources/Authentication/AppJWTTest.php already
 * uses offline. Prism's own security-scheme check (`JWT_TOKEN: {type: http, scheme: bearer}` in
 * the spec, no format regex -- verified against src/spec/openapi.yaml) only requires an
 * `Authorization: Bearer <anything>` header to be present, so the resulting compound
 * `Bearer {secret}.{header.payload.sig}` wire value (Support\Bridge::__construct()'s
 * `BearerAuthCredentialsBuilder::init($jwt->secret, $jwt->token)`) is accepted without issue.
 */
abstract class IntegrationTestCase extends TestCase
{
    use FakeJwtBuilder;

    // --- Fixture ids (docs repo tests/helpers.js's IDS map, spec-example-authored) -------------
    public const STORE_ID = '11edf541-c42d-653c-8c3d-dfe0a55f95c0';
    public const CHARGE_ID = '11ef0000-0000-4000-8000-000000000001';
    public const TOKEN_ID = '11ef32a7-3a71-8662-803f-1bc27702eeec';
    public const SUBSCRIPTION_ID = '11ef335e-9aa5-c54a-8313-7f9847da313a';
    public const PAYMENT_ID = '11ef335e-9ae2-8322-8e79-e7ba4b56234e';
    public const REFUND_ID = '11ef0000-0000-4000-8000-000000000010';
    public const CANCEL_ID = '11ef0000-0000-4000-8000-000000000011';
    public const MERCHANT_ID = '01234567-89ab-cdef-0123-456789abcdef';

    protected function setUp(): void
    {
        if (self::prismUrl() === null) {
            $this->markTestSkipped(
                'UNIVAPAY_PRISM_URL is not set -- start Prism from a docs-repo checkout '
                . '(npm run mock, port 4010 by default) and set UNIVAPAY_PRISM_URL to run this '
                . 'suite. Not a failure: expected on a fresh checkout / CI without docs-repo '
                . 'access (see this class\'s doc comment).'
            );
        }
    }

    private static function prismUrl(): ?string
    {
        $url = getenv('UNIVAPAY_PRISM_URL');
        return ($url !== false && $url !== '') ? $url : null;
    }

    /**
     * A store-scoped client (StoreAppJWT) -- every store-gated method
     * (createToken/getCheckoutInfo/getTransactionToken/simulation/...) needs this, not a
     * merchant-level token.
     */
    protected function storeClient(): UnivapayClient
    {
        return new UnivapayClient(
            $this->buildStoreAppToken(self::STORE_ID, self::MERCHANT_ID),
            $this->clientOptions()
        );
    }

    /**
     * A merchant-level client (no store_id claim) -- for getMe()/getTransfer() and for asserting
     * Support\Bridge::requireStoreId()'s guard actually fires.
     */
    protected function merchantClient(): UnivapayClient
    {
        return new UnivapayClient($this->buildMerchantAppToken(self::MERCHANT_ID), $this->clientOptions());
    }

    protected function clientOptions(): UnivapayClientOptions
    {
        // UnivapayClientOptions's real shape (verified against src/UnivapayClientOptions.php) is
        // a single positional $endpoint string, NOT a keyed options array -- unlike the generated
        // SDK's own builder-style construction, this constructor is a verbatim port of the old
        // SDK's own (equally single-positional-arg) UnivapayClientOptions.
        return new UnivapayClientOptions((string) self::prismUrl());
    }
}
