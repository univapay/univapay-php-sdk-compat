<?php

namespace Univapay\Compat\Tests\Unit\Utility;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Utility\FunctionalUtils;

class FunctionalUtilsTest extends TestCase
{
    public function testGetOrElseReturnsValueWhenKeyPresent()
    {
        $this->assertSame('bar', FunctionalUtils::getOrElse(['foo' => 'bar'], 'foo', 'fallback'));
    }

    public function testGetOrElseReturnsFallbackWhenKeyMissing()
    {
        $this->assertSame('fallback', FunctionalUtils::getOrElse([], 'foo', 'fallback'));
    }

    public function testGetOrNullReturnsNullWhenKeyMissing()
    {
        $this->assertNull(FunctionalUtils::getOrNull([], 'foo'));
    }

    public function testCopyProducesAnIndependentArray()
    {
        $original = ['a' => 1];
        $copy = FunctionalUtils::copy($original);
        $this->assertEquals($original, $copy);
    }

    public function testIdentityReturnsInputUnchanged()
    {
        $value = new \stdClass();
        $this->assertSame($value, FunctionalUtils::identity($value));
    }

    public function testArrayFindIndexReturnsMatchingIndex()
    {
        $index = FunctionalUtils::arrayFindIndex(['a', 'b', 'c'], function ($x) {
            return $x === 'b';
        });
        $this->assertSame(1, $index);
    }

    public function testArrayFindIndexReturnsNullWhenNoMatch()
    {
        $index = FunctionalUtils::arrayFindIndex(['a', 'b', 'c'], function ($x) {
            return $x === 'z';
        });
        $this->assertNull($index);
    }

    public function testStripNullsRemovesOnlyNullValues()
    {
        $stripped = FunctionalUtils::stripNulls(['a' => null, 'b' => false, 'c' => 0, 'd' => 'x']);
        $this->assertSame(['b' => false, 'c' => 0, 'd' => 'x'], $stripped);
    }

    public function testGetClassVarsAssocIncludesParentVarsWhenRequested()
    {
        $vars = FunctionalUtils::getClassVarsAssoc(ChildFixture::class, true);
        sort($vars);
        $this->assertSame(['childProp', 'parentProp'], $vars);
    }

    public function testGetClassVarsAssocExcludesParentVarsWhenNotRequested()
    {
        $vars = FunctionalUtils::getClassVarsAssoc(ChildFixture::class, false);
        $this->assertSame(['childProp'], $vars);
    }
}

class ParentFixture
{
    public $parentProp;
}

class ChildFixture extends ParentFixture
{
    public $childProp;
}
