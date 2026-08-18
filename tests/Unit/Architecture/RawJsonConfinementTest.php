<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * @group confinement
 *
 * Confinement audit: the compat layer's core design bet is "typed-out (request path via
 * Support\RequestModelFactory), raw-in (response path via ported JsonSchema parsers)" -- see
 * docs/ARCHITECTURE.md for the full boundary writeup. This test is the grep-based enforcement of
 * the RAW-IN half of that bet: it asserts that touching a decoded HTTP response body as an untyped
 * array/string (`json_decode(`, or indexing into a variable conventionally named
 * `$body`/`$json`/`$raw`/`$decoded`/`$response`/`$payload`) happens ONLY in the files this test
 * explicitly names below, never anywhere else in `src/`.
 *
 * Two allowlists, two directions of drift both caught:
 *   - A NEW file touching raw JSON that isn't listed here fails the "confined" assertion --
 *     someone added a raw-hydration path outside the reviewed boundary.
 *   - A listed file that no longer matches its pattern fails the "not stale" assertion -- the
 *     reason it was allowlisted no longer applies and the entry should be removed (mirrors
 *     tools/example-roundtrip/src/Mapping.php's own "mapping table must not go stale" design).
 *
 * ## Why `$data[` is deliberately NOT one of the grepped variable names
 *
 * This codebase's own naming convention reserves `$data` for REQUEST-side payment-method payload
 * arrays (`Support\RequestModelFactory`'s `buildTokenCreateData()` family builds outbound typed
 * request models FROM a `$data` array the compat `PaymentData\*`/`PaymentMethod\*` classes carry
 * internally) and for `Resources\TransactionToken::$data` (the hydrated payment-data union,
 * itself parsed through `initSchema()`, not raw-indexed elsewhere). Grepping `$data[` would flood
 * this audit with `RequestModelFactory`'s dozens of legitimate request-building accesses, which
 * are the OUTBOUND, typed-model-building half of the architecture this test is not about. The
 * one place a literal HTTP response-shaped payload is genuinely named `$data` --
 * `UnivapayClient::parseWebhookData(array $data)`, the public webhook-parsing entry point that
 * takes a raw, consumer-supplied array (not something ApiCaller ever decoded) -- simply isn't
 * matched by this grep at all (no allowlist entry needed for it below): it's permanent, since it's
 * that method's own documented contract, not a decoded API response.
 *
 * ## Allowlist rationale (each entry is a real, verified touchpoint)
 *
 * - `Support/ApiCaller.php` / `Utility/Json/*` -- the architecture's own reviewed core: raw-body
 *   capture + decode (ApiCaller) and the ported `JsonSchema` hydration machinery it feeds.
 * - `Resources/Authentication/AppJWT.php` -- decodes the JWT's own base64 payload segment, not an
 *   API response body; `json_decode()` here predates any HTTP call this library makes.
 * - `Utility/FormatterUtils.php` -- `getMoney()`'s currency-lookup closure receives the same
 *   `($value, $json, $parent)` triple every `initSchema()` upsert formatter does; it is called
 *   FROM inside `initSchema()` bodies, just defined in a shared helper instead of inline.
 * - `Errors/UnivapayRequestError.php` + its 401/403/409 subclasses -- `fromJson()`/constructor
 *   read the array `ExceptionMapper::mapResponse()`/`map()` build for them. These read
 *   `$json['status']`/`['code']`/`['errors']` guarded with `??` fallbacks (value-neutral: null
 *   before and after a missing key, this only silences a PHP-engine-level warning -- see
 *   tests/Hostile/MalformedErrorBodyTest), matching the old SDK's own
 *   `UnivapayRequestError::fromJson()` behavior. `Support/ExceptionMapper.php` itself is NOT in
 *   this allowlist -- its `bodyAsArray()`/`fromStatus()` build these arrays from typed accessors
 *   (`$e->getStatus()` etc.) or hand a pre-decoded value straight through, with no literal
 *   `$json[...]`-shaped bracket access of its own to match this grep's pattern.
 * - `Support/ListDispatcher.php` -- `wrapPage()` (`$decoded['items']`/`['has_more']`) and
 *   `resolveMerchantId()` (`$decoded['id']` from a raw `GET /me`) both hydrate list envelopes and
 *   a single scalar directly rather than through a resource's `initSchema()`. Candidate for
 *   routing through a typed envelope if a typed-first hydration path is added later.
 * - `Resources/Store.php` -- `getCustomerId()` returns `$body['customer_id']` directly, with no
 *   `Jsonable` hydration step at all for this one response.
 * - `Resources/TransactionToken.php` -- the ONE resource file whose raw-array touch
 *   (`$json['payment_type']` in `initSchema()`'s `data` upsert closure) is inside `initSchema()`
 *   itself, same as every other resource's formatter closures -- listed explicitly (rather than
 *   relying on a generic "inside initSchema()" carve-out) because this audit's grep is a flat
 *   per-file check, not a method-body-aware parser; verified by direct reading that no OTHER
 *   method in this file touches raw JSON.
 *
 * Every other resource class's raw-JSON handling happens through `Utility\Json\JsonSchema`'s
 * reflection-driven `parse()` (property-by-property, via each class's declared `$schema`), which
 * never literally writes `$body[`/`$json[`-shaped code in the resource file itself -- so those
 * files need no allowlist entry at all.
 */
class RawJsonConfinementTest extends TestCase
{
    private const SRC_DIR = __DIR__ . '/../../../src';

    /** Files allowed to call json_decode(...) directly, relative to src/. */
    private const ALLOWED_JSON_DECODE = [
        'Support/ApiCaller.php',
        'Support/ExceptionMapper.php',
        'Resources/Authentication/AppJWT.php',
    ];

    /**
     * Files (or whole directories, trailing slash) allowed to index into a variable
     * conventionally named body/json/raw/decoded/response/payload -- i.e. treat a decoded HTTP
     * response as an untyped array. Relative to src/.
     */
    private const ALLOWED_RAW_ARRAY_ACCESS = [
        'Utility/Json/', // the ported JsonSchema hydration machinery itself
        'Utility/FormatterUtils.php',
        'Support/ListDispatcher.php',
        'Resources/Store.php',
        'Resources/TransactionToken.php',
        'Errors/UnivapayRequestError.php',
        'Errors/UnivapayForbiddenError.php',
        'Errors/UnivapayUnauthorizedError.php',
        'Errors/UnivapayResourceConflictError.php',
    ];

    /** @return string[] relative (to src/) paths of every .php file in src/ */
    private static function allSourceFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::SRC_DIR, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() === 'php') {
                $relative = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen(self::SRC_DIR) + 1));
                $files[] = $relative;
            }
        }
        sort($files);
        return $files;
    }

    private static function isAllowed(string $relativeFile, array $allowlist): bool
    {
        foreach ($allowlist as $entry) {
            if (substr($entry, -1) === '/') {
                if (strpos($relativeFile, $entry) === 0) {
                    return true;
                }
            } elseif ($relativeFile === $entry) {
                return true;
            }
        }
        return false;
    }

    public function testJsonDecodeIsConfinedToTheAllowlist(): void
    {
        $violations = [];
        foreach (self::allSourceFiles() as $relative) {
            if (self::isAllowed($relative, self::ALLOWED_JSON_DECODE)) {
                continue;
            }
            $contents = file_get_contents(self::SRC_DIR . '/' . $relative);
            if (preg_match('/\bjson_decode\s*\(/', $contents)) {
                $violations[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Found json_decode() outside the confinement allowlist: " . implode(', ', $violations)
            . '. Either this is a genuine new raw-hydration touchpoint (add it to '
            . 'RawJsonConfinementTest::ALLOWED_JSON_DECODE with a documented reason, and to '
            . "docs/ARCHITECTURE.md's boundary table), or it should be routed through "
            . 'Support\\ApiCaller / a resource\'s initSchema() instead.'
        );
    }

    public function testRawArrayAccessOnDecodedBodiesIsConfinedToTheAllowlist(): void
    {
        // Matches $body[, $json[, $raw[, $decoded[, $response[, $payload[ -- the variable-naming
        // convention this codebase uses for "a decoded HTTP response body treated as an array"
        // (see class doc for why $data[ is deliberately excluded).
        $pattern = '/\$(?:body|json|raw|decoded|response|payload)\s*\[/';

        $violations = [];
        foreach (self::allSourceFiles() as $relative) {
            if (self::isAllowed($relative, self::ALLOWED_RAW_ARRAY_ACCESS)) {
                continue;
            }
            $contents = file_get_contents(self::SRC_DIR . '/' . $relative);
            $stripped = self::stripComments($contents);
            if (preg_match($pattern, $stripped)) {
                $violations[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Found raw array access on a decoded-body-shaped variable outside the confinement '
            . 'allowlist: ' . implode(', ', $violations) . '. Either this is a genuine new '
            . 'raw-hydration touchpoint (add it to '
            . 'RawJsonConfinementTest::ALLOWED_RAW_ARRAY_ACCESS with a documented reason, and to '
            . "docs/ARCHITECTURE.md's boundary table), or it should be routed through a "
            . "resource's initSchema()/getSchema() instead."
        );
    }

    /**
     * Every allowlisted (non-directory) entry must still actually match -- otherwise it is a
     * stale exception (the reason it was added no longer applies) and should be removed, exactly
     * like tools/example-roundtrip/src/Mapping.php's "mapping table must not go stale" contract.
     */
    public function testAllowlistEntriesAreNotStale(): void
    {
        $stale = [];

        foreach (self::ALLOWED_JSON_DECODE as $entry) {
            $contents = file_get_contents(self::SRC_DIR . '/' . $entry);
            if (!preg_match('/\bjson_decode\s*\(/', $contents)) {
                $stale[] = "ALLOWED_JSON_DECODE: $entry";
            }
        }

        foreach (self::ALLOWED_RAW_ARRAY_ACCESS as $entry) {
            if (substr($entry, -1) === '/') {
                continue; // directory carve-outs (Utility/Json/) aren't checked for staleness
            }
            $contents = self::stripComments(file_get_contents(self::SRC_DIR . '/' . $entry));
            if (!preg_match('/\$(?:body|json|raw|decoded|response|payload)\s*\[/', $contents)) {
                $stale[] = "ALLOWED_RAW_ARRAY_ACCESS: $entry";
            }
        }

        $this->assertSame(
            [],
            $stale,
            'Allowlist entries no longer match anything in their file -- remove them: '
            . implode(', ', $stale)
        );
    }

    /**
     * Strips /* ... *\/ and // line comments so doc-comment PROSE that happens to mention
     * `$json['...']` (there is plenty of it in this codebase, describing exactly this audit's
     * subject matter) is never mistaken for real code by the regex above.
     */
    private static function stripComments(string $contents): string
    {
        $withoutBlockComments = preg_replace('#/\*.*?\*/#s', '', $contents);
        return preg_replace('#//[^\n]*#', '', $withoutBlockComments);
    }
}
