<?php

declare(strict_types=1);

namespace Univapay\Compat\Tools\ExampleRoundTrip;

use Throwable;

/**
 * Orchestrates the example round-trip harness: decode the already-YAML->JSON-converted spec,
 * extract every example, cross-check the extraction against `Mapping::table()` (the deliverable's
 * contract -- every example must have a row, every row's example must still exist), then for every
 * MAPPED row actually run the payload through its compat parser (`X::getSchema()->parse()`) and a
 * `SpotChecker` type spot-check.
 *
 * Deliberately never constructs a real `Support\CompatContext`/`Support\Bridge` -- every compat
 * resource's `$context` constructor parameter defaults to `null` and nothing this harness exercises
 * (schema hydration only, never `fetch()`/`update()`/list dispatch) needs a live one. `SpotChecker`
 * knows to skip the `context` parameter for exactly this reason.
 */
class Harness
{
    /**
     * @return array{
     *   mappingGaps: string[],
     *   mappingStale: string[],
     *   inlineResponseInvariantViolations: string[],
     *   webhooksWithoutExample: string[],
     *   accounted: array<string, array{category: string, reason: ?string}>,
     *   attempts: array<int, array>,
     *   counts: array<string, int>
     * }
     */
    public static function run(string $specJsonPath): array
    {
        $raw = file_get_contents($specJsonPath);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read spec JSON at $specJsonPath");
        }
        $spec = json_decode($raw, true);
        if (!is_array($spec)) {
            throw new \RuntimeException("Failed to json_decode $specJsonPath: " . json_last_error_msg());
        }

        $extractor = new SpecExtractor($spec);
        $named = $extractor->namedExamples();
        list($webhookEx, $webhooksNoExample) = $extractor->webhookExamples();
        $inlinePathResponses = $extractor->inlinePathResponseExamples();
        $inlineRequestLocations = $extractor->inlineRequestExampleLocations();

        // string keys, no collision by construction (distinct prefixes: bare name /
        // 'webhooks.' / 'inline.')
        $allSpecExamples = $named + $webhookEx + $inlinePathResponses;
        $table = Mapping::table();

        $mappingGaps = array_values(array_diff(array_keys($allSpecExamples), array_keys($table)));
        $mappingStale = array_values(array_diff(array_keys($table), array_keys($allSpecExamples)));

        $accounted = [];
        $attempts = [];

        foreach ($table as $name => $row) {
            if (!isset($allSpecExamples[$name])) {
                // Stale row -- already reported via $mappingStale; nothing to run.
                continue;
            }
            $accounted[$name] = ['category' => $row['category'], 'reason' => $row['reason'] ?? null];

            if ($row['category'] !== Mapping::MAPPED) {
                continue;
            }

            $value = $allSpecExamples[$name]['value'];
            $parserClass = $row['parser'];
            $kind = $row['kind'];

            switch ($kind) {
                case 'single':
                    $attempts[] = self::attemptParse($name, null, $parserClass, $value);
                    break;

                case 'webhook-envelope':
                    $payload = is_array($value) ? ($value['data'] ?? null) : null;
                    if ($payload === null) {
                        $attempts[] = [
                            'case' => $name, 'item' => null, 'parser' => $parserClass,
                            'ok' => false, 'exceptionClass' => null,
                            'exceptionMessage' => 'webhook example had no `data` key to extract',
                            'spot' => [],
                        ];
                        break;
                    }
                    $attempts[] = self::attemptParse($name, null, $parserClass, $payload);
                    break;

                case 'list-items':
                    $items = is_array($value) ? ($value['items'] ?? []) : [];
                    if (!is_array($items) || count($items) === 0) {
                        $attempts[] = [
                            'case' => $name, 'item' => null, 'parser' => $parserClass,
                            'ok' => false, 'exceptionClass' => null,
                            'exceptionMessage' => 'expected a non-empty `items` array, found none',
                            'spot' => [],
                        ];
                        break;
                    }
                    foreach ($items as $idx => $item) {
                        $attempts[] = self::attemptParse($name, $idx, $parserClass, $item);
                    }
                    break;

                case 'raw-array-items':
                    if (!is_array($value) || count($value) === 0) {
                        $attempts[] = [
                            'case' => $name, 'item' => null, 'parser' => $parserClass,
                            'ok' => false, 'exceptionClass' => null,
                            'exceptionMessage' => 'expected a non-empty raw array, found none',
                            'spot' => [],
                        ];
                        break;
                    }
                    foreach ($value as $idx => $item) {
                        $attempts[] = self::attemptParse($name, $idx, $parserClass, $item);
                    }
                    break;

                default:
                    $attempts[] = [
                        'case' => $name, 'item' => null, 'parser' => $parserClass,
                        'ok' => false, 'exceptionClass' => null,
                        'exceptionMessage' => "unknown Mapping 'kind': $kind",
                        'spot' => [],
                    ];
            }
        }

        $counts = self::tallyCounts($table, $allSpecExamples, $attempts);

        return [
            'mappingGaps' => $mappingGaps,
            'mappingStale' => $mappingStale,
            'inlineRequestExampleLocations' => $inlineRequestLocations,
            'webhooksWithoutExample' => $webhooksNoExample,
            'accounted' => $accounted,
            'attempts' => $attempts,
            'counts' => $counts,
        ];
    }

    private static function attemptParse(string $case, $item, string $parserClass, $payload): array
    {
        $spot = [];
        try {
            /** @var \Univapay\Compat\Utility\Json\JsonSchema $schema */
            $schema = call_user_func([$parserClass, 'getSchema']);
            $instance = $schema->parse($payload, []);
            $spot = SpotChecker::check($parserClass, $instance);
            return [
                'case' => $case, 'item' => $item, 'parser' => $parserClass,
                'ok' => true, 'exceptionClass' => null, 'exceptionMessage' => null,
                'spot' => $spot,
            ];
        } catch (Throwable $e) {
            return [
                'case' => $case, 'item' => $item, 'parser' => $parserClass,
                'ok' => false, 'exceptionClass' => get_class($e), 'exceptionMessage' => $e->getMessage(),
                'spot' => $spot,
            ];
        }
    }

    private static function tallyCounts(array $table, array $allSpecExamples, array $attempts): array
    {
        $byCategory = [Mapping::MAPPED => 0, Mapping::OUT_OF_SCOPE => 0, Mapping::UNMAPPED => 0];
        foreach ($table as $name => $row) {
            if (!isset($allSpecExamples[$name])) {
                continue;
            }
            $byCategory[$row['category']]++;
        }

        $parseOk = 0;
        $parseFail = 0;
        $spotMismatches = 0;
        foreach ($attempts as $a) {
            if ($a['ok']) {
                $parseOk++;
            } else {
                $parseFail++;
            }
            foreach ($a['spot'] as $s) {
                if (!$s['ok']) {
                    $spotMismatches++;
                }
            }
        }

        return [
            'mapped' => $byCategory[Mapping::MAPPED],
            'outOfScope' => $byCategory[Mapping::OUT_OF_SCOPE],
            'unmapped' => $byCategory[Mapping::UNMAPPED],
            'total' => count($allSpecExamples),
            'parseAttempts' => count($attempts),
            'parseOk' => $parseOk,
            'parseFail' => $parseFail,
            'spotMismatches' => $spotMismatches,
        ];
    }
}
