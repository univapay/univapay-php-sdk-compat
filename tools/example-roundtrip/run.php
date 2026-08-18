<?php

/**
 * Example round-trip harness CLI.
 *
 * Proves every new operation example added to the paired docs/spec repo hydrates cleanly through
 * compat's ported old-SDK parsers -- the same parsers exercised by Prism-backed SDK contract
 * tests -- before the expensive all-language SDK regen.
 *
 * ONE-ARG RE-RUN: this entire tool takes exactly one input, the spec, as JSON. Re-running against
 * a newer docs-repo commit (e.g. once new transaction-history endpoints land) is:
 *
 *   git -C /path/to/univapay_docs show <newer-sha>:src/spec/openapi.yaml > /tmp/openapi-next.yaml
 *   node tools/example-roundtrip/yaml2json.js /tmp/openapi-next.yaml /tmp/openapi-next.json
 *   php tools/example-roundtrip/run.php /tmp/openapi-next.json
 *
 * No PHP here ever parses YAML directly (composer.json intentionally adds no YAML dependency for
 * this one-off tool) -- the docs repo already has `js-yaml` in its own devDependencies and Node
 * available; the conversion step is a disposable pre-step done with a small local npm install in
 * this directory (see package.json), NOT by running anything inside the docs repo's working tree.
 *
 * Usage:
 *   php run.php <spec.json> [--json]
 *
 *   <spec.json>  Output of yaml2json.js -- the OpenAPI spec as plain JSON.
 *   --json       Emit the full machine-readable report instead of the human-readable summary.
 *
 * Exit codes:
 *   0  no MAPPING-TABLE-GAP/STALE/invariant violations (parse failures/spot mismatches are
 *      EXPECTED findings, not tool bugs -- see the printed Findings section; they don't fail
 *      the exit code, they're the reason this tool exists)
 *   1  the mapping table itself has drifted from the spec (a gap, staleness, or the
 *      inline-response-example invariant tripped) -- fix Mapping.php
 *   2  usage error
 */

require __DIR__ . '/../../vendor/autoload.php';

use Univapay\Compat\Tools\ExampleRoundTrip\Harness;
use Univapay\Compat\Tools\ExampleRoundTrip\Mapping;

$args = array_slice($argv, 1);
$jsonOutput = false;
$specPath = null;
foreach ($args as $arg) {
    if ($arg === '--json') {
        $jsonOutput = true;
    } elseif ($specPath === null) {
        $specPath = $arg;
    }
}

if ($specPath === null) {
    fwrite(STDERR, "Usage: php run.php <spec.json> [--json]\n");
    exit(2);
}

$report = Harness::run($specPath);

if ($jsonOutput) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    $hasTableDrift = $report['mappingGaps'] || $report['mappingStale'];
    exit($hasTableDrift ? 1 : 0);
}

$c = $report['counts'];

echo "== example round-trip harness report ==\n";
echo "spec: $specPath\n\n";

echo "-- Mapping table coverage --\n";
echo "  total examples in spec : {$c['total']}\n";
echo "  MAPPED                 : {$c['mapped']}\n";
echo "  OUT_OF_SCOPE            : {$c['outOfScope']}\n";
echo "  UNMAPPED                : {$c['unmapped']}\n";
echo "\n";

echo "-- Parse attempts (MAPPED rows, one attempt per list item) --\n";
echo "  attempts : {$c['parseAttempts']}\n";
echo "  pass     : {$c['parseOk']}\n";
echo "  fail     : {$c['parseFail']}\n";
echo "  spot-check type mismatches (on passes) : {$c['spotMismatches']}\n";
echo "\n";

if ($report['mappingGaps']) {
    echo "!! MAPPING-TABLE-GAP -- spec has examples with NO row in Mapping::table():\n";
    foreach ($report['mappingGaps'] as $name) {
        echo "   - $name\n";
    }
    echo "\n";
}
if ($report['mappingStale']) {
    echo "!! MAPPING-TABLE-STALE -- Mapping::table() rows whose example no longer exists in spec:\n";
    foreach ($report['mappingStale'] as $name) {
        echo "   - $name\n";
    }
    echo "\n";
}
if ($report['webhooksWithoutExample']) {
    echo "-- Note: webhooks with a schema but NO example in the spec (nothing to round-trip) --\n";
    foreach ($report['webhooksWithoutExample'] as $opName) {
        echo "   - $opName\n";
    }
    echo "\n";
}

echo "-- UNMAPPED (genuine gaps -- response-shaped example, no compat parser) --\n";
foreach ($report['accounted'] as $name => $row) {
    if ($row['category'] === Mapping::UNMAPPED) {
        echo "   - $name\n";
        if ($row['reason']) {
            echo "       " . $row['reason'] . "\n";
        }
    }
}
echo "\n";

echo "-- Findings: parse failures --\n";
$anyFail = false;
foreach ($report['attempts'] as $a) {
    if ($a['ok']) {
        continue;
    }
    $anyFail = true;
    $label = $a['item'] === null ? $a['case'] : "{$a['case']}[items.{$a['item']}]";
    echo "   FAIL $label via {$a['parser']}\n";
    echo "       " . ($a['exceptionClass'] ? $a['exceptionClass'] . ': ' : '') . $a['exceptionMessage'] . "\n";
}
if (!$anyFail) {
    echo "   (none)\n";
}
echo "\n";

echo "-- Findings: spot-check type mismatches (parse succeeded, hydrated type is wrong) --\n";
$anyMismatch = false;
foreach ($report['attempts'] as $a) {
    foreach ($a['spot'] as $s) {
        if ($s['ok']) {
            continue;
        }
        $anyMismatch = true;
        $label = $a['item'] === null ? $a['case'] : "{$a['case']}[items.{$a['item']}]";
        echo "   MISMATCH $label via {$a['parser']}: property \${$s['property']} expected "
            . "{$s['expectedType']}, got {$s['actualType']}\n";
    }
}
if (!$anyMismatch) {
    echo "   (none)\n";
}
echo "\n";

$hasTableDrift = $report['mappingGaps'] || $report['mappingStale'];
exit($hasTableDrift ? 1 : 0);
