<?php

namespace Univapay\Compat\Tests\Unit\Utility;

use DateInterval;
use PHPUnit\Framework\TestCase;
use Univapay\Compat\Utility\DateUtils;

/**
 * DateUtils::asPeriodString() is ported verbatim, including a preexisting bug in the seconds
 * segment: it reads `$date->y` (years) instead of `$date->s` (seconds) when building the "S"
 * token. These tests assert the CURRENT (buggy) behavior for regression-safety -- fixing it is
 * out of scope for a verbatim port (see plan discipline for FormatterUtils/DateUtils: preserve
 * as-is, don't "fix").
 */
class DateUtilsTest extends TestCase
{
    public function testDateOnlyIntervalOmitsTimeSegment()
    {
        $interval = new DateInterval('P1Y2M3D');
        $this->assertSame('P1Y2M3D', DateUtils::asPeriodString($interval));
    }

    public function testZeroYearsMonthsDaysOmitsDateSegmentPieces()
    {
        $interval = new DateInterval('P0Y0M0D');
        $this->assertSame('P', DateUtils::asPeriodString($interval));
    }

    public function testSecondsSegmentActuallyUsesYearsValueNotSeconds()
    {
        // Constructed with 0 years but 45 seconds: a correct implementation would emit "PT45S".
        // The ported bug instead emits "PT0S" here because it reads $date->y (0), not $date->s
        // (45), for the "S" token.
        $interval = new DateInterval('PT45S');
        $this->assertSame('PT0S', DateUtils::asPeriodString($interval));
    }

    public function testSecondsSegmentReflectsYearsWhenYearsIsNonZero()
    {
        // With both years and seconds present, the "S" token is (bug-for-bug) the YEARS count,
        // not the seconds count -- demonstrating the substitution concretely.
        $interval = new DateInterval('P2YT45S');
        $this->assertSame('P2YT2S', DateUtils::asPeriodString($interval));
    }
}
