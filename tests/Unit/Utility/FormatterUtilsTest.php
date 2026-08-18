<?php

namespace Univapay\Compat\Tests\Unit\Utility;

use DateInterval;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\ChargeStatus;
use Univapay\Compat\Utility\FormatterUtils;

class FormatterUtilsTest extends TestCase
{
    public function testGetDateTimeParsesAStringIntoADateTime()
    {
        $dt = FormatterUtils::getDateTime('2026-08-14T07:35:50.000000Z');
        $this->assertInstanceOf(\DateTime::class, $dt);
        $this->assertSame('2026-08-14', $dt->format('Y-m-d'));
    }

    public function testGetCurrencyWrapsMoneyPhpCurrency()
    {
        $currency = FormatterUtils::getCurrency('JPY');
        $this->assertInstanceOf(Currency::class, $currency);
        $this->assertTrue($currency->equals(new Currency('JPY')));
    }

    public function testGetMoneyReadsAmountAndSiblingCurrencyKey()
    {
        $parser = FormatterUtils::getMoney('currency');
        $money = $parser(1000, ['amount' => 1000, 'currency' => 'JPY'], null);

        $this->assertInstanceOf(Money::class, $money);
        $this->assertTrue($money->equals(new Money(1000, new Currency('JPY'))));
    }

    public function testGetMoneyReadsCurrencyFromParentWhenAtRoot()
    {
        $parser = FormatterUtils::getMoney('currency', true);
        $money = $parser(500, ['amount' => 500], ['currency' => 'USD']);

        $this->assertTrue($money->equals(new Money(500, new Currency('USD'))));
    }

    public function testGetTypedEnumResolvesThroughFromValue()
    {
        $parser = FormatterUtils::getTypedEnum(ChargeStatus::class);
        $this->assertSame(ChargeStatus::SUCCESSFUL(), $parser('successful'));
    }

    public function testGetListOfMapsEachRawValueThroughTheGivenParser()
    {
        $parser = FormatterUtils::getListOf(function ($value) {
            return $value * 2;
        });

        $this->assertSame([2, 4, 6], $parser([1, 2, 3]));
    }

    public function testFormatDateIntervalIsoTrimsZeroSegments()
    {
        $interval = new DateInterval('P1Y0M3D');
        $this->assertSame('P1Y3D', FormatterUtils::formatDateIntervalISO($interval));
    }

    public function testFormatDateIntervalIsoOnZeroIntervalReturnsPT0S()
    {
        $interval = new DateInterval('P0Y0M0D');
        $this->assertSame('PT0S', FormatterUtils::formatDateIntervalISO($interval));
    }
}
