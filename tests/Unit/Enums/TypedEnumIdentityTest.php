<?php

namespace Univapay\Compat\Tests\Unit\Enums;

use OutOfRangeException;
use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\ChargeStatus;
use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Enums\SubscriptionStatus;

/**
 * Verifies the TypedEnum contract (ported verbatim from the old SDK, namespace line only) is
 * preserved bit-for-bit: memoized singleton identity, switch-safety, fromValue/fromName,
 * findValues, getValue/getName, and that an unrecognized value throws OutOfRangeException.
 */
class TypedEnumIdentityTest extends TestCase
{
    public function testSingletonIdentity()
    {
        $this->assertSame(ChargeStatus::PENDING(), ChargeStatus::PENDING());
        $this->assertNotSame(ChargeStatus::PENDING(), ChargeStatus::SUCCESSFUL());
    }

    public function testSwitchSafe()
    {
        $status = ChargeStatus::SUCCESSFUL();
        $matched = null;

        switch ($status) {
            case ChargeStatus::PENDING():
                $matched = 'pending';
                break;
            case ChargeStatus::SUCCESSFUL():
                $matched = 'successful';
                break;
            default:
                $matched = 'other';
        }

        $this->assertSame('successful', $matched);
    }

    public function testGetValueAndGetName()
    {
        $this->assertSame('pending', ChargeStatus::PENDING()->getValue());
        $this->assertSame('PENDING', ChargeStatus::PENDING()->getName());
    }

    public function testFromValueReturnsSameSingleton()
    {
        $this->assertSame(ChargeStatus::AWAITING(), ChargeStatus::fromValue('awaiting'));
    }

    public function testFromNameReturnsSameSingleton()
    {
        $this->assertSame(ChargeStatus::AWAITING(), ChargeStatus::fromName('AWAITING'));
    }

    public function testFindValuesReturnsEveryCase()
    {
        $values = CursorDirection::findValues();
        $this->assertCount(2, $values);

        $asStrings = array_map(function ($v) {
            return $v->getValue();
        }, $values);
        sort($asStrings);
        $this->assertSame(['asc', 'desc'], $asStrings);
    }

    public function testUnknownValueThrowsOutOfRangeException()
    {
        $this->expectException(OutOfRangeException::class);
        SubscriptionStatus::fromValue('not-a-real-status');
    }

    public function testUnknownNameThrowsOutOfRangeException()
    {
        $this->expectException(OutOfRangeException::class);
        SubscriptionStatus::fromName('NOT_A_REAL_STATUS');
    }

    public function testToStringIncludesClassAndCaseName()
    {
        $this->assertSame(
            'Univapay\Compat\Enums\ChargeStatus::PENDING',
            (string) ChargeStatus::PENDING()
        );
    }
}
