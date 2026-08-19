<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Univapay\Compat\Support\FallbackRegistry;
use Univapay\Compat\Support\TypedHydrator;
use Univapay\Compat\Support\TypedResult;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Fixture with no `hydrateFromTyped()` -- stands in for the ~20 resources that haven't flipped to
 * typed-primary yet. `TypedHydrator::resolve()` must behave IDENTICALLY to a plain
 * `getSchema()->parse()` call for this class, regardless of what's in the `TypedResult`.
 */
class UnflippedFixtureForTypedHydratorTest
{
    public $name;

    public function __construct($name)
    {
        $this->name = $name;
    }

    public static function getSchema()
    {
        return (new JsonSchema(self::class))->with('name', true);
    }
}

/**
 * Fixture with `hydrateFromTyped()` -- stands in for a flipped resource (e.g. `Charge`).
 * Controllable per-test via static properties so one class covers every dispatch branch.
 */
class FlippedFixtureForTypedHydratorTest
{
    public $name;

    /** @var string|null */
    public static $behavior = 'hydrate';

    public function __construct($name)
    {
        $this->name = $name;
    }

    public static function getSchema()
    {
        return (new JsonSchema(self::class))->with('name', true);
    }

    public static function hydrateFromTyped($typed, array $body, $context)
    {
        switch (self::$behavior) {
            case 'decline':
                return null;
            case 'throw':
                throw new RuntimeException('hydrateFromTyped blew up');
            case 'echoBodyType':
                return new self(is_array($body) && empty($body) ? 'empty-array' : 'unexpected');
            default:
                return new self('typed:' . $typed);
        }
    }
}

class TypedHydratorTest extends TestCase
{
    protected function setUp(): void
    {
        FallbackRegistry::reset();
        FlippedFixtureForTypedHydratorTest::$behavior = 'hydrate';
    }

    protected function tearDown(): void
    {
        FallbackRegistry::reset();
    }

    public function testUnflippedClassAlwaysResolvesViaRawParseRegardlessOfTypedPresence()
    {
        $result = new TypedResult(['name' => 'raw-value'], 'some-typed-value', false);

        $resolved = TypedHydrator::resolve(UnflippedFixtureForTypedHydratorTest::class, $result, null);

        $this->assertSame('raw-value', $resolved->name);
        $this->assertEmpty(FallbackRegistry::occurrences());
    }

    public function testUnflippedClassRecordsNothingEvenWhenMapperFailed()
    {
        $result = new TypedResult(['name' => 'raw-value'], null, true);

        $resolved = TypedHydrator::resolve(UnflippedFixtureForTypedHydratorTest::class, $result, null);

        $this->assertSame('raw-value', $resolved->name);
        $this->assertEmpty(FallbackRegistry::occurrences());
    }

    public function testFlippedClassPrefersTypedHydrationWhenTypedIsPresent()
    {
        $result = new TypedResult(['name' => 'raw-value'], 'typed-value', false);

        $resolved = TypedHydrator::resolve(FlippedFixtureForTypedHydratorTest::class, $result, null);

        $this->assertSame('typed:typed-value', $resolved->name);
        $this->assertEmpty(FallbackRegistry::occurrences());
    }

    public function testFlippedClassFallsBackAndRecordsWhenHydrateFromTypedDeclines()
    {
        FlippedFixtureForTypedHydratorTest::$behavior = 'decline';
        $result = new TypedResult(['name' => 'raw-value'], 'typed-value', false);

        $resolved = TypedHydrator::resolve(FlippedFixtureForTypedHydratorTest::class, $result, null);

        $this->assertSame('raw-value', $resolved->name);
        $this->assertCount(1, FallbackRegistry::occurrences());
        $this->assertSame(FallbackRegistry::REASON_HYDRATION_DECLINED, FallbackRegistry::occurrences()[0]['reason']);
    }

    public function testFlippedClassFallsBackAndRecordsWhenHydrateFromTypedThrows()
    {
        FlippedFixtureForTypedHydratorTest::$behavior = 'throw';
        $result = new TypedResult(['name' => 'raw-value'], 'typed-value', false);

        $resolved = TypedHydrator::resolve(FlippedFixtureForTypedHydratorTest::class, $result, null);

        $this->assertSame('raw-value', $resolved->name);
        $this->assertCount(1, FallbackRegistry::occurrences());
        $this->assertSame(FallbackRegistry::REASON_HYDRATION_EXCEPTION, FallbackRegistry::occurrences()[0]['reason']);
        $this->assertSame('hydrateFromTyped blew up', FallbackRegistry::occurrences()[0]['detail']);
    }

    public function testFlippedClassFallsBackAndRecordsWhenTypedIsNullAndMapperFailed()
    {
        $result = new TypedResult(['name' => 'raw-value'], null, true);

        $resolved = TypedHydrator::resolve(FlippedFixtureForTypedHydratorTest::class, $result, null);

        $this->assertSame('raw-value', $resolved->name);
        $this->assertCount(1, FallbackRegistry::occurrences());
        $this->assertSame(FallbackRegistry::REASON_JSONMAPPER_EXCEPTION, FallbackRegistry::occurrences()[0]['reason']);
    }

    public function testFlippedClassFallsBackWithoutRecordingWhenTypedIsSimplyAbsent()
    {
        // typed === null but mapperFailed === false: no jsonmapper was ever attempted at all
        // (e.g. the generated controller returned something other than an ApiResponse). Nothing
        // to blame the typed path for, so nothing is recorded.
        $result = new TypedResult(['name' => 'raw-value'], null, false);

        $resolved = TypedHydrator::resolve(FlippedFixtureForTypedHydratorTest::class, $result, null);

        $this->assertSame('raw-value', $resolved->name);
        $this->assertEmpty(FallbackRegistry::occurrences());
    }

    public function testNonArrayRawBodyIsNormalizedToEmptyArrayBeforeReachingHydrateFromTyped()
    {
        // TypedResult::$rawBody can legitimately be `true` (an empty response body) --
        // hydrateFromTyped()'s `array $body` parameter must never receive that raw `true` directly.
        FlippedFixtureForTypedHydratorTest::$behavior = 'echoBodyType';
        $result = new TypedResult(true, 'typed-value', false);

        $resolved = TypedHydrator::resolve(FlippedFixtureForTypedHydratorTest::class, $result, null);

        $this->assertSame('empty-array', $resolved->name);
    }
}
