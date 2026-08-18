<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\RoundTrip;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Tools\ExampleRoundTrip\Harness;
use Univapay\Compat\Tools\ExampleRoundTrip\Mapping;

/**
 * @group roundtrip
 *
 * PHPUnit wrapper around the example round-trip harness (tools/example-roundtrip) so CI
 * can rerun it as part of the normal test suite instead of only via its standalone CLI
 * (tools/example-roundtrip/run.php).
 *
 * SKIPS (does not fail) when the extracted spec JSON isn't present -- that file is a
 * gitignored build artifact (see .gitignore's "tools/example-roundtrip" block), not something
 * this repo commits, so a fresh checkout or an environment that never ran the extraction step
 * simply skips rather than erroring. To populate it:
 *
 *   git -C /path/to/univapay_docs show <sha>:src/spec/openapi.yaml \
 *       > tools/example-roundtrip/openapi-<sha>.yaml
 *   (cd tools/example-roundtrip && npm install && \
 *       node yaml2json.js openapi-<sha>.yaml spec-<sha>.json)
 *
 * then point self::SPEC_JSON_PATH (or the UNIVAPAY_RT_SPEC_JSON env var) at the resulting file.
 *
 * This test does NOT hardcode an expected failure count -- instead it asserts the OBSERVED
 * failing case set is EXACTLY the set Mapping::table() currently tags with an 'ANTICIPATED
 * FAILURE' reason (KNOWN, already-triaged docs-repo-backlog gaps, not compat bugs). As of the
 * spec commit pinned in openapi-7c9f9f85.yaml (7c9f9f85, docs-repo HEAD of
 * feat/spec-v1.1-sdk-migration -- content-identical to 0ac94680, the spec-editing commit in that
 * same range; the two commits after it only touch generated SDK trees, not
 * src/spec/openapi.yaml), that set is empty: every MAPPED example round-trips cleanly. Diffing
 * f2a4094d..7c9f9f85 directly, that range: (1) removed the merchant-payout Bank Accounts feature
 * entirely (`GET /bank_accounts(/{id})`, the `BankAccount*` schemas, and both
 * `BankAccountListResponseExample`/`BankAccountResponseExample` -- both rows REMOVED from
 * Mapping::table() at this re-pin, since the examples no longer exist), (2) marked
 * `card_brand`/`brands` (the `TransactionHistoryCardBrandQuery`/`TransactionHistoryBrandsQuery`
 * params) `deprecated: true` in favor of `brand`, (3) renamed every array query param added in
 * that range (`card_brand`/`brand`/`brands`/`service_providers`/`bank_transfer_payment_statuses`)
 * to its `[]`-suffixed wire form (e.g. `card_brand[]`) -- a request-serialization detail with no
 * effect on any `components.examples`/webhook RESPONSE example this harness maps, and (4)
 * retyped `TokenResponseQrMerchantData.qr_image_url` (dropped `format: uri`) and changed its
 * example value from a URL to an opaque numeric payload -- a description/example change only, no
 * schema shape change (`type: string` either way), so no Mapping::table() row needed updating for
 * it either. Net Mapping::table() diff at this re-pin: the two BankAccount rows removed, nothing
 * else.
 * Asserting zero unconditionally would work today but silently stop meaning anything the next
 * time a real gap needs allowlisting; keying off Mapping::table() itself instead means:
 *   - a NEW failure (a regression, or a newly-added example with a real mismatch) fails the test
 *   - a previously-failing case that now unexpectedly PASSES also fails the test (a prompt to
 *     shrink Mapping::table()'s allowlist -- the docs repo fixed it, stop expecting the failure)
 * Spot-check type mismatches are asserted at zero unconditionally -- there are none as of this
 * commit, so any new one is worth looking at immediately rather than pre-allowlisting.
 *
 * ## Re-pinning again later (one-arg procedure, per run.php's own doc)
 *
 *   git -C /path/to/univapay_docs show <newer-sha>:src/spec/openapi.yaml \
 *       > tools/example-roundtrip/openapi-<newer-sha>.yaml
 *   (cd tools/example-roundtrip && node yaml2json.js openapi-<newer-sha>.yaml spec-<newer-sha>.json)
 *   php tools/example-roundtrip/run.php tools/example-roundtrip/spec-<newer-sha>.json
 *
 * then update self::SPEC_JSON_PATH below, `git rm` the old openapi-<sha>.yaml, `git add` the new
 * one, and diff the two spec YAMLs directly for example name/content changes Mapping::table()
 * needs to follow (as this doc's own paragraph above did for the 7c9f9f85 re-pin).
 */
class ExampleRoundTripTest extends TestCase
{
    /** Default location `run.php`'s own usage instructions tell a maintainer to populate. */
    public const SPEC_JSON_PATH = __DIR__ . '/../../tools/example-roundtrip/spec-7c9f9f85.json';

    private function specJsonPath(): ?string
    {
        $envPath = getenv('UNIVAPAY_RT_SPEC_JSON');
        if ($envPath !== false && is_string($envPath) && $envPath !== '') {
            return $envPath;
        }
        return self::SPEC_JSON_PATH;
    }

    public function testMappingTableHasNoDriftAndKnownFailuresAreUnchanged(): void
    {
        $path = $this->specJsonPath();
        if (!is_string($path) || !is_file($path)) {
            $this->markTestSkipped(
                "Extracted spec JSON not found at $path -- see this test's class doc for how to "
                . 'produce it (or set the UNIVAPAY_RT_SPEC_JSON env var to an existing one). '
                . 'Not a failure: this is expected on a fresh checkout / CI without docs-repo access.'
            );
        }

        $report = Harness::run($path);

        $this->assertSame(
            [],
            $report['mappingGaps'],
            'Mapping::table() is missing a row for example(s) the spec now has -- see '
            . 'tools/example-roundtrip/src/Mapping.php\'s class doc ("the mapping table is the '
            . "deliverable's contract\") and add a row for each name listed above."
        );
        $this->assertSame(
            [],
            $report['mappingStale'],
            'Mapping::table() has row(s) for example(s) the spec no longer has -- remove the '
            . 'stale row(s) listed above from tools/example-roundtrip/src/Mapping.php.'
        );

        $expectedFailingCases = self::anticipatedFailureCaseNames();
        $actualFailingCases = self::failingCaseNames($report['attempts']);

        sort($expectedFailingCases);
        sort($actualFailingCases);

        $this->assertSame(
            $expectedFailingCases,
            $actualFailingCases,
            "The set of examples failing to round-trip through compat's parsers no longer "
            . "matches Mapping::table()'s documented 'ANTICIPATED FAILURE' rows. If this is a "
            . 'NEW failure: it is a regression (or a genuinely new example/parser mismatch) -- '
            . 'investigate before adding it to the allowlist. If a previously-failing case is no '
            . 'longer listed as actually failing: the docs repo (or a compat fix) already '
            . "resolved it -- update that row's category/reason in Mapping.php (drop the "
            . "'ANTICIPATED FAILURE' framing) so this allowlist stays honest."
        );

        $this->assertSame(
            0,
            $report['counts']['spotMismatches'],
            'A spot-check type mismatch appeared (hydration succeeded but produced the wrong '
            . 'type for a Money/DateTime/TypedEnum/nested-resource field) -- see the failing '
            . "attempt's 'spot' entries. There are none as of this harness's baseline run, so "
            . 'this is new and worth investigating directly rather than allowlisting.'
        );
    }

    /**
     * @return string[] case names (bare `Mapping::table()` keys, not per-list-item) whose row is
     *         still tagged as an anticipated, not-yet-resolved failure.
     */
    private static function anticipatedFailureCaseNames(): array
    {
        $names = [];
        foreach (Mapping::table() as $name => $row) {
            $reason = $row['reason'] ?? '';
            if (strpos($reason, 'ANTICIPATED FAILURE') === 0 && strpos($reason, 'now FIXED') === false) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * @param array $attempts Harness::run()'s 'attempts' list
     * @return string[] unique case names with at least one failing parse attempt (list-item
     *         attempts collapse to their parent case name -- Mapping's rows are keyed per case,
     *         not per item).
     */
    private static function failingCaseNames(array $attempts): array
    {
        $names = [];
        foreach ($attempts as $attempt) {
            if (!$attempt['ok']) {
                $names[$attempt['case']] = true;
            }
        }
        return array_keys($names);
    }
}
