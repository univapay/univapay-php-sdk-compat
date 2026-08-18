<?php

namespace Univapay\Compat\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\ChargeStatus;
use Univapay\Compat\Enums\Field;

/**
 * TypedEnum::fromGetter() (private, backing fromValue()/fromName()) starts with:
 *
 *     if ($value == null || $value == "") { return null; }
 *
 * That is a loose (`==`) comparison, so an int 0 or an empty string short-circuits to `null`
 * BEFORE the reflection-based search even runs -- regardless of whether any case in the enum
 * actually has that value. This is a preexisting quirk in the ported (unmodified) old SDK code:
 * these tests assert the CURRENT behavior for regression-safety, they do not "fix" it.
 */
class TypedEnumFromGetterQuirkTest extends TestCase
{
    public function testFromValueZeroReturnsNullInsteadOfThrowing()
    {
        // No ChargeStatus case has value 0 (or even an int value at all -- all values are the
        // lowercased method name strings) -- an unrelated unknown value would normally throw
        // OutOfRangeException, but `0 == null` is true, so the null short-circuit wins first.
        $this->assertNull(ChargeStatus::fromValue(0));
    }

    public function testFromValueEmptyStringReturnsNullInsteadOfThrowingOrMatching()
    {
        // Field has THREE cases whose getValue() === '' (PERIOD, PHONE_NUMBER, ZIP) -- an
        // ambiguous match if the search ran at all. Because `'' == ''` is caught by the
        // fromGetter guard before the reflection loop, fromValue('') returns null rather than
        // resolving to (or erroring on) any of the three empty-valued cases.
        $this->assertNull(Field::fromValue(''));
    }

    public function testFromNameEmptyStringAlsoShortCircuitsToNull()
    {
        $this->assertNull(ChargeStatus::fromName(''));
    }
}
