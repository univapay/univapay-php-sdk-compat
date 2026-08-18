<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources;

use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\ChargeStatus;
use Univapay\Compat\Enums\TransactionType;
use Univapay\Compat\Resources\Transaction;

/**
 * Covers `Transaction` -- pure data class, exactly as upstream (old `Transaction`
 * never extended `Resource`, no `fetch()`/`update()` -- transaction history items were never
 * individually fetchable by id in either SDK), distinct from `Transfer`'s permanent-unsupported
 * status -- see class doc. `Mixins\GetTransactions`'s real `GET /transaction_history` dispatch
 * is covered in `StoreTest`/`UnivapayClientTest`; this covers the data shape's hydration.
 */
class TransactionTest extends TestCase
{
    public function testHydratesATransaction()
    {
        $transaction = Transaction::getSchema()->parse([
            'id' => 'transaction-1',
            'store_id' => 'store-1',
            'resource_id' => 'charge-1',
            'charge_id' => null,
            'currency' => 'JPY',
            'amount' => 1000,
            'amount_formatted' => 1000,
            'type' => 'charge',
            'status' => 'successful',
            'metadata' => [],
            'mode' => 'test',
            'user_data' => ['cardholder_email_address' => 'test@example.com'],
            'created_on' => '2022-08-01T00:00:00.000000Z',
        ]);

        $this->assertSame('transaction-1', $transaction->id);
        $this->assertSame('store-1', $transaction->storeId);
        $this->assertSame('charge-1', $transaction->resourceId);
        $this->assertNull($transaction->chargeId);
        $this->assertEquals(new Money(1000, new Currency('JPY')), $transaction->amount);
        $this->assertEquals(TransactionType::CHARGE(), $transaction->type);
        $this->assertEquals(ChargeStatus::SUCCESSFUL(), $transaction->status);
        $this->assertEquals(AppTokenMode::TEST(), $transaction->mode);
        $this->assertSame('test@example.com', $transaction->userData['cardholder_email_address']);
    }

    public function testHasNoFetchOrUpdateMethodMatchingOldSdkExactly()
    {
        $transaction = Transaction::getSchema()->parse([
            'id' => 'transaction-1', 'store_id' => 'store-1', 'resource_id' => 'refund-1',
            'charge_id' => 'charge-1', 'currency' => 'JPY', 'amount' => 500,
            'amount_formatted' => 500, 'type' => 'refund', 'status' => 'successful',
            'metadata' => [], 'mode' => 'test', 'user_data' => [],
            'created_on' => '2022-08-01T00:00:00.000000Z',
        ]);

        $this->assertFalse(method_exists($transaction, 'fetch'));
        $this->assertFalse(method_exists($transaction, 'update'));
    }
}
