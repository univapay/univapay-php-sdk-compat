<?php

namespace Univapay\Compat\Tests\Unit\Utility\Json;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Utility\Json\JsonSchema;
use Univapay\Compat\Utility\Json\NoSuchPathException;
use Univapay\Compat\Utility\Json\RequiredValueNotFoundException;

class JsonSchemaFixtureTarget
{
    public $id;
    public $name;

    public function __construct($id, $name)
    {
        $this->id = $id;
        $this->name = $name;
    }
}

/**
 * JsonSchema is the parser at the heart of the response-hydration strategy this compat package
 * relies on throughout (see plan "raw-body hydration" decision): responses are hydrated via
 * json_decode() + these ported schema parsers rather than the generated SDK's strict typed
 * deserializer, specifically so legacy/edge-case wire shapes the new spec doesn't describe don't
 * blow up compat call sites. These are basic correctness tests for the parser itself (no
 * dedicated test existed for it upstream).
 */
class JsonSchemaTest extends TestCase
{
    public function testParseBuildsTargetFromRequiredAndOptionalPaths()
    {
        $schema = (new JsonSchema(JsonSchemaFixtureTarget::class))
            ->with('id', true)
            ->with('name', false);

        $result = $schema->parse(['id' => 'abc123', 'name' => 'Widget']);

        $this->assertInstanceOf(JsonSchemaFixtureTarget::class, $result);
        $this->assertSame('abc123', $result->id);
        $this->assertSame('Widget', $result->name);
    }

    public function testMissingOptionalValueParsesAsNull()
    {
        $schema = (new JsonSchema(JsonSchemaFixtureTarget::class))
            ->with('id', true)
            ->with('name', false);

        $result = $schema->parse(['id' => 'abc123']);

        $this->assertNull($result->name);
    }

    public function testMissingRequiredValueThrows()
    {
        // NOTE: RequiredValueNotFoundException is thrown by getValues() when the field
        // resolves to a present-but-null value; an entirely absent key is a different failure
        // (getField() throws NoSuchPathException instead, before a null value ever surfaces) --
        // see testGetFieldThrowsNoSuchPathExceptionWithFullPath() below for that path.
        $schema = (new JsonSchema(JsonSchemaFixtureTarget::class))
            ->with('id', true);

        $this->expectException(RequiredValueNotFoundException::class);
        $schema->parse(['id' => null]);
    }

    public function testFormatterReceivesValueJsonAndParent()
    {
        $seen = [];
        $schema = (new JsonSchema(JsonSchemaFixtureTarget::class))
            ->with('id', true)
            ->with('name', false, function ($value, $json, $parent) use (&$seen) {
                $seen[] = [$value, $json, $parent];
                return strtoupper($value);
            });

        $result = $schema->parse(['id' => 'abc123', 'name' => 'widget']);

        $this->assertSame('WIDGET', $result->name);
        $this->assertSame('widget', $seen[0][0]);
    }

    public function testUpsertReplacesExistingComponentAtSamePath()
    {
        $schema = (new JsonSchema(JsonSchemaFixtureTarget::class))
            ->with('id', true)
            ->with('name', false)
            ->upsert('id', true, function ($value) {
                return "prefixed-$value";
            });

        $result = $schema->parse(['id' => 'abc123', 'name' => null]);

        $this->assertSame('prefixed-abc123', $result->id);
    }

    public function testGetFieldNestedPathTraversal()
    {
        $value = JsonSchema::getField(['a' => ['b' => ['c' => 'deep']]], true, ['a', 'b', 'c']);
        $this->assertSame('deep', $value);
    }

    public function testGetFieldThrowsNoSuchPathExceptionWithFullPath()
    {
        $this->expectException(NoSuchPathException::class);
        JsonSchema::getField(['a' => ['b' => []]], true, ['a', 'b', 'c']);
    }

    public function testGetFieldReturnsNullForMissingOptionalPath()
    {
        $this->assertNull(JsonSchema::getField(['a' => []], false, ['a', 'missing']));
    }
}
