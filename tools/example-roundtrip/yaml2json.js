#!/usr/bin/env node
/**
 * One-shot YAML -> JSON converter for the RT-harness (round-trip harness).
 *
 * The RT harness's PHP CLI (run.php) needs to walk the OpenAPI spec's `components/examples` and
 * inline operation examples, but this repo has no YAML parser in its PHP dependency tree (and the
 * plan explicitly says not to add one just for this harness). Node + js-yaml already exists on any
 * machine that can run the docs repo's own tooling, so this script does the ONE YAML->JSON hop as
 * a separate, disposable pre-step: `node yaml2json.js <spec.yaml> <out.json>`. Everything past
 * this point (mapping table, parser invocation, findings) is pure PHP.
 *
 * Usage: node yaml2json.js <input-openapi.yaml> <output.json>
 */
'use strict';

const fs = require('fs');
const path = require('path');
const yaml = require('js-yaml');

const [, , inputPath, outputPath] = process.argv;

if (!inputPath || !outputPath) {
  console.error('Usage: node yaml2json.js <input-openapi.yaml> <output.json>');
  process.exit(2);
}

const raw = fs.readFileSync(path.resolve(inputPath), 'utf8');
const doc = yaml.load(raw);

fs.writeFileSync(path.resolve(outputPath), JSON.stringify(doc));
console.log(`Wrote ${outputPath} (${fs.statSync(path.resolve(outputPath)).size} bytes)`);
