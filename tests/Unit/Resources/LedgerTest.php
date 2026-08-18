<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources;

use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\LedgerOrigin;
use Univapay\Compat\Resources\Ledger;

/**
 * Covers `Ledger` -- pure data class, exactly as upstream (no `Resource` inheritance,
 * no `fetch()`/`update()`): hydration is the only surface to test, since the throw for this
 * feature's unsupported status lives entirely on `Mixins\GetLedgers` and
 * `Transfer::fetchCall()`/`updateCall()` (covered in `TransferTest`).
 */
class LedgerTest extends TestCase
{
    public function testHydratesALedger()
    {
        $ledger = Ledger::getSchema()->parse([
            'id' => 'ledger-1',
            'store_id' => 'store-1',
            'currency' => 'JPY',
            'amount' => 1000,
            'amount_formatted' => 1000,
            'percent_fee' => 3.5,
            'flat_fee_currency' => 'JPY',
            'flat_fee_amount' => 30,
            'flat_fee_formatted' => 30,
            'exchange_rate' => 1.0,
            'origin' => 'charge',
            'note' => null,
            'created_on' => '2022-08-01T00:00:00.000000Z',
        ]);

        $this->assertSame('ledger-1', $ledger->id);
        $this->assertSame('store-1', $ledger->storeId);
        $this->assertEquals(new Money(1000, new Currency('JPY')), $ledger->amount);
        $this->assertEquals(new Money(30, new Currency('JPY')), $ledger->flatFeeAmount);
        $this->assertEquals(LedgerOrigin::CHARGE(), $ledger->origin);
    }
}
