<?php

namespace Univapay\Compat\Tests\Unit\Support;

use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Univapay\Compat\Support\MoneyHelper;

class MoneyHelperTest extends TestCase
{
    public function testAmountReturnsAnInt()
    {
        $money = new Money(1000, new Currency('JPY'));

        $amount = MoneyHelper::amount($money);

        $this->assertSame(1000, $amount);
        $this->assertIsInt($amount);
    }

    public function testCurrencyReturnsTheIsoCode()
    {
        $money = new Money(1000, new Currency('JPY'));

        $this->assertSame('JPY', MoneyHelper::currency($money));
    }

    public function testFromBuildsAMoneyValueFromAmountAndCurrency()
    {
        $money = MoneyHelper::from(500, 'USD');

        $this->assertInstanceOf(Money::class, $money);
        $this->assertSame(500, MoneyHelper::amount($money));
        $this->assertSame('USD', MoneyHelper::currency($money));
    }

    public function testFromAcceptsAStringAmountLikeMoneyphpV3GetAmount()
    {
        $money = MoneyHelper::from('500', 'USD');

        $this->assertSame(500, MoneyHelper::amount($money));
    }

    public function testFromReturnsNullWhenAmountIsNull()
    {
        $this->assertNull(MoneyHelper::from(null, 'USD'));
    }

    public function testFromReturnsNullWhenCurrencyIsNull()
    {
        $this->assertNull(MoneyHelper::from(500, null));
    }

    public function testAmountRoundTripsThroughFrom()
    {
        $original = new Money(12345, new Currency('JPY'));

        $rebuilt = MoneyHelper::from(MoneyHelper::amount($original), MoneyHelper::currency($original));

        $this->assertTrue($original->equals($rebuilt));
    }
}
