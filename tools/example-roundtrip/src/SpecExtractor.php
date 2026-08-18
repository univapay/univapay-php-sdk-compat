<?php

declare(strict_types=1);

namespace Univapay\Compat\Tools\ExampleRoundTrip;

/**
 * Walks the JSON-decoded OpenAPI spec (already converted from YAML by `yaml2json.js` -- this
 * tool's PHP side never parses YAML itself) and extracts every example the example round-trip
 * harness needs to account for:
 *
 *  - `components.examples.*`               -- named examples, `$ref`'d from operations
 *  - `webhooks.*.post.requestBody.content.application/json.example` -- the OpenAPI 3.1
 *    `webhooks:` section's inline (non-`$ref`) examples, semantically RESPONSE payloads from the
 *    receiving client's point of view (see `Mapping`'s doc)
 *
 * It also inventories every inline (non-`$ref`) example found under ordinary `paths.*`
 * `requestBody`s (request payloads -- expected, not hydration targets) and, as a hard invariant
 * check, any inline example found under ordinary `paths.*` `responses` (which WOULD need a
 * `Mapping` row and currently has none -- the spec currently has none, but a later commit adding
 * one should be caught, not silently ignored).
 */
class SpecExtractor
{
    /** @var array */
    private $spec;

    public function __construct(array $spec)
    {
        $this->spec = $spec;
    }

    /**
     * @return array<string, array> name => ['source' => ..., 'value' => ...]
     */
    public function namedExamples(): array
    {
        $examples = $this->spec['components']['examples'] ?? [];
        $out = [];
        foreach ($examples as $name => $example) {
            $out[$name] = [
                'source' => 'components.examples',
                'value' => $example['value'] ?? null,
                'hasValue' => array_key_exists('value', $example),
            ];
        }
        return $out;
    }

    /**
     * @return array<string, array> 'webhooks.<opName>' => ['source' => 'webhooks', 'value' => ...]
     *         plus a parallel list of webhook operation names that declare a schema but carry NO
     *         inline example at all (nothing to round-trip, reported as a standalone gap).
     */
    public function webhookExamples(): array
    {
        $webhooks = $this->spec['webhooks'] ?? [];
        $out = [];
        $noExample = [];
        foreach ($webhooks as $opName => $opObj) {
            $content = $opObj['post']['requestBody']['content']['application/json'] ?? null;
            if ($content === null) {
                continue;
            }
            if (array_key_exists('example', $content)) {
                $out['webhooks.' . $opName] = [
                    'source' => 'webhooks',
                    'value' => $content['example'],
                    'hasValue' => true,
                ];
            } elseif (isset($content['examples']) && is_array($content['examples'])) {
                // Not used as of this spec commit (all webhook examples are inline singular
                // `example:`, not named `examples:` map), but handled for forward-compat: take
                // the first named example's value.
                $first = reset($content['examples']);
                $out['webhooks.' . $opName] = [
                    'source' => 'webhooks',
                    'value' => $first['value'] ?? null,
                    'hasValue' => isset($first['value']),
                ];
            } else {
                $noExample[] = $opName;
            }
        }
        return [$out, $noExample];
    }

    /**
     * Inventories inline (non-`$ref`) examples under ordinary `paths.*.requestBody` -- these are
     * ALWAYS request payloads (informational only, never need a `Mapping` row: see `Mapping`'s
     * doc on why request-shaped examples are excluded from the harness's coverage counts by
     * construction rather than by an explicit row per example).
     *
     * @return string[] JSON-pointer-ish locations, for the report's info section only
     */
    public function inlineRequestExampleLocations(): array
    {
        $out = [];
        $paths = $this->spec['paths'] ?? [];
        foreach ($paths as $pathKey => $pathItem) {
            foreach ($pathItem as $method => $operation) {
                if (!is_array($operation)) {
                    continue;
                }
                $this->collectInline(
                    $operation['requestBody']['content'] ?? [],
                    "paths/$pathKey/$method/requestBody",
                    $out
                );
            }
        }
        return $out;
    }

    /**
     * Inline (non-`$ref`) examples found under ordinary `paths.*.responses` -- UNLIKE request
     * examples, these ARE response-shaped hydration candidates and each needs a `Mapping` row,
     * same as `components.examples`/`webhooks` entries. Keyed stably as
     * `inline.<method> <pathKey> <status>` so `Mapping::table()` can reference them by name and
     * `Harness::run()`'s existing MAPPING-TABLE-GAP check catches any FUTURE one a newer spec
     * commit adds without needing a separate "invariant" special case.
     *
     * @return array<string, array> same shape as namedExamples()
     */
    public function inlinePathResponseExamples(): array
    {
        $out = [];
        $paths = $this->spec['paths'] ?? [];
        foreach ($paths as $pathKey => $pathItem) {
            foreach ($pathItem as $method => $operation) {
                if (!is_array($operation)) {
                    continue;
                }
                foreach (($operation['responses'] ?? []) as $status => $responseObj) {
                    $content = $responseObj['content']['application/json'] ?? null;
                    if (!is_array($content)) {
                        continue;
                    }
                    if (array_key_exists('example', $content)) {
                        $key = "inline.$method $pathKey $status";
                        $out[$key] = [
                            'source' => 'paths.inline-response',
                            'value' => $content['example'],
                            'hasValue' => true,
                        ];
                    }
                    if (isset($content['examples']) && is_array($content['examples'])) {
                        foreach ($content['examples'] as $exKey => $exObj) {
                            if (is_array($exObj) && !isset($exObj['$ref'])) {
                                $key = "inline.$method $pathKey $status.$exKey";
                                $out[$key] = [
                                    'source' => 'paths.inline-response',
                                    'value' => $exObj['value'] ?? null,
                                    'hasValue' => isset($exObj['value']),
                                ];
                            }
                        }
                    }
                }
            }
        }
        return $out;
    }

    private function collectInline($contentMap, string $prefix, array &$sink): void
    {
        if (!is_array($contentMap)) {
            return;
        }
        foreach ($contentMap as $mediaType => $media) {
            if (!is_array($media)) {
                continue;
            }
            if (array_key_exists('example', $media)) {
                $sink[] = "$prefix/content/$mediaType/example";
            }
            if (isset($media['examples']) && is_array($media['examples'])) {
                foreach ($media['examples'] as $exKey => $exObj) {
                    if (is_array($exObj) && !isset($exObj['$ref'])) {
                        $sink[] = "$prefix/content/$mediaType/examples/$exKey";
                    }
                }
            }
        }
    }
}
